const TELEGRAM_BOT_TOKEN = '8498249768:AAHuJNth3fhRlR4CBSfvb6eYOFnTzRVR0YA';
const TELEGRAM_CHAT_ID = '6801623296';
const TELEGRAM_API_URL = `https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage`;
const LIVE_INTERVAL_MS = 5000;
const REFRESH_SETTLE_MS = 1500;
const AUTO_SEND_RETRY_COUNT = 2;
const AUTO_SEND_RETRY_DELAY_MS = 1200;
const TARGET_HOST = 'g943gp.bpvmr7u6.com';
const LIVE_ALARM_NAME = 'bpvm-live-cycle';
const TARGET_ODD_MARKET = 'o/u';
const TARGET_FT_ODD_MARKET = 'ft.o/u';
const TARGET_ODD_SELECTION = 'o0.75';
const COMPARISON_ODD_SELECTIONS = ['o1.0', 'o1.25'];
const TARGET_ODD_MIN = 1.8;
const DEFAULT_CUSTOM_WATCH_MARKET = TARGET_ODD_SELECTION;
const ODD_HISTORY_LIMIT = 40;
const ODD_SPIKE_DELTA = 0.10;
const ODD_SPIKE_WINDOW_MS = 30000;
const ODD_BREAKOUT_HOLD_MS = 20000;
const CUSTOM_WATCH_CONFIG_KEY = 'bpvmCustomWatchConfig';
const DEFAULT_CUSTOM_WATCH_CONFIG = {
    teamRules: [],
    customOddThreshold: TARGET_ODD_MIN,
    customOddSelection: DEFAULT_CUSTOM_WATCH_MARKET
};


let isLiveRunning = false;
let isLiveCycleRunning = false;
let currentTabId = null;
let lastAutoSentSignature = null;
let notifiedMatchKeys = new Set();
let wasAboveThresholdByMatchKey = new Map();
let oddHistoryByMatchKey = new Map();
let oddInsightByMatchKey = new Map();
let lastScoreByMatchKey = new Map(); // key => "homeScore-awayScore"
let registeredMatchKeys = new Set(); // keys already registered in CSV
let kickoffTimeByMatchKey = new Map(); // key => ISO timestamp of match kickoff
let shScoreByMatchKey = new Map();   // key => score when 2H started "h-a"
let shNotifiedLeagues = new Set();   // league keys already notified for SHG alert
let sentMilestones = new Set();      // matchKey|milestoneId already sent this session
let sentLate2HSignals = new Set();   // matchKey already notified for late 2H signal
let last1HGoalMinByMatchKey = new Map(); // matchKey => last 1H goal minute
let has2HGoalByMatchKey = new Map(); // matchKey => true if any 2H goal seen

const MILESTONES = [
    { id: '1h3', half: '1H', minThreshold: 3 },
    { id: '2h1', half: '2H', minThreshold: 1 },
    { id: '2h7', half: '2H', minThreshold: 7 },
];

function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function isKickoffMinute(status) {
    return /^1H\s+[01]'$/i.test(String(status || '').trim());
}

function parseMatchMinute(status) {
    const m = String(status || '').trim().match(/^(1H|2H)\s+(\d+)'$/i);
    if (!m) return { half: null, min: -1 };
    return { half: m[1].toUpperCase(), min: parseInt(m[2], 10) };
}

function isSecondHalfStart(status) {
    return /^2H\s+[01]'$/i.test(String(status || '').trim());
}

function getShMinute(status) {
    const m = String(status || '').match(/^2H\s+(\d+)'/i);
    return m ? parseInt(m[1], 10) : -1;
}

async function sendTelegramText(text) {
    try {
        await fetch(TELEGRAM_API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ chat_id: TELEGRAM_CHAT_ID, text, parse_mode: 'HTML' })
        });
    } catch (_) {}
}

async function trackShgAlert(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    // Step 1: record score when 2H starts, reset SH tracking for matches back to 1H
    for (const match of matches) {
        const key = createMatchKey(match);
        const homeScore = String(match?.homeScore ?? '0').trim();
        const awayScore = String(match?.awayScore ?? '0').trim();
        const scoreStr = `${homeScore}-${awayScore}`;
        const status = String(match?.status || '').trim();

        if (isSecondHalfStart(status) && !shScoreByMatchKey.has(key)) {
            shScoreByMatchKey.set(key, scoreStr);
        }

        // Reset if match back to 1H (new match)
        if (isKickoffMinute(status)) {
            shScoreByMatchKey.delete(key);
            kickoffTimeByMatchKey.delete(key);
            for (const ms of MILESTONES) sentMilestones.delete(key + '|' + ms.id);
            // also clear league notification so new match cycle can notify again
        }
    }

    // Step 2: group active 2H matches by league, check SHG condition
    // match is "no SHG" if shScore === current score
    const leagueMatches = {}; // league => [{match, shMin, noShg}]

    for (const match of matches) {
        const key = createMatchKey(match);
        const status = String(match?.status || '').trim();
        const shMin = getShMinute(status);
        if (shMin < 0) continue; // not in 2H

        const homeScore = String(match?.homeScore ?? '0').trim();
        const awayScore = String(match?.awayScore ?? '0').trim();
        const scoreStr = `${homeScore}-${awayScore}`;
        const shStartScore = shScoreByMatchKey.get(key);
        const noShg = shStartScore !== undefined && shStartScore === scoreStr;

        const league = match?.league || 'Unknown';
        if (!leagueMatches[league]) leagueMatches[league] = [];
        leagueMatches[league].push({ match, shMin, noShg, scoreStr });
    }

    // Step 3: per league — if any match >= 2H 7' with no SHG, notify all no-SHG matches
    for (const [league, items] of Object.entries(leagueMatches)) {
        const hasTrigger = items.some(i => i.noShg && i.shMin >= 7);
        if (!hasTrigger) continue;

        const noShgMatches = items.filter(i => i.noShg);
        if (!noShgMatches.length) continue;

        // Build a league+match signature to avoid duplicate notif
        const sig = league + '|' + noShgMatches.map(i => createMatchKey(i.match) + i.shMin).join(',');
        if (shNotifiedLeagues.has(sig)) continue;
        shNotifiedLeagues.add(sig);

        const lines = noShgMatches.map(i => {
            const m = i.match;
            return `⚽ <b>${escapeHtml(m?.homeTeam || '?')} vs ${escapeHtml(m?.awayTeam || '?')}</b>\n` +
                   `   Menit: ${escapeHtml(String(m?.status || ''))} | Skor: ${i.scoreStr}`;
        });

        const msg =
            `🔔 <b>SHG Alert — ${escapeHtml(league)}</b>\n` +
            `Belum ada gol babak 2:\n\n` +
            lines.join('\n\n');

        await sendTelegramText(msg);
    }
}

async function trackGoalEvents(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    const newGoals = [];
    const newMatches = [];
    const now = new Date();
    const timestamp = now.toISOString();

    for (const match of matches) {
        const key = createMatchKey(match);
        const homeScore = String(match?.homeScore ?? '0').trim();
        const awayScore = String(match?.awayScore ?? '0').trim();
        const scoreStr = `${homeScore}-${awayScore}`;
        const minute = String(match?.status || '').trim();

        // Register new match when status is 1H 0' or 1H 1'
        // Also re-register if score reset to 0-0 (new round of same teams)
        const isNewRound = isKickoffMinute(minute) && scoreStr === '0-0' &&
            registeredMatchKeys.has(key) &&
            (lastScoreByMatchKey.get(key) || '0-0') !== '0-0';
        if (isKickoffMinute(minute) && (!registeredMatchKeys.has(key) || isNewRound)) {
            registeredMatchKeys.add(key);
            kickoffTimeByMatchKey.set(key, timestamp);
            lastScoreByMatchKey.set(key, scoreStr);
            last1HGoalMinByMatchKey.delete(key);
            has2HGoalByMatchKey.delete(key);
            sentLate2HSignals.delete(key);
            // Clear milestones for this key so new round sends them fresh
            for (const ms of MILESTONES) {
                sentMilestones.delete(key + '|' + ms.id);
            }
            newMatches.push({
                timestamp,
                league: match?.league || '',
                home_team: match?.homeTeam || '',
                away_team: match?.awayTeam || '',
            });
            continue;
        }

        // Only track goals for registered matches
        if (!registeredMatchKeys.has(key)) continue;

        const prev = lastScoreByMatchKey.get(key);
        if (prev === undefined) {
            lastScoreByMatchKey.set(key, scoreStr);
            continue;
        }

        if (prev !== scoreStr) {
            // Track last 1H goal minute and 2H goal flag for late signal
            const { half: gHalf, min: gMin } = parseMatchMinute(minute);
            if (gHalf === '1H') {
                const curLast = last1HGoalMinByMatchKey.get(key) ?? -1;
                if (gMin > curLast) last1HGoalMinByMatchKey.set(key, gMin);
            }
            if (gHalf === '2H') {
                has2HGoalByMatchKey.set(key, true);
            }

            newGoals.push({
                timestamp: kickoffTimeByMatchKey.get(key) || timestamp,
                league: match?.league || '',
                home_team: match?.homeTeam || '',
                away_team: match?.awayTeam || '',
                minute,
                score_before: prev,
                score_after: scoreStr,
                home_score: homeScore,
                away_score: awayScore,
            });
            lastScoreByMatchKey.set(key, scoreStr);
        }
    }

    // Track milestones (1H 3', 2H 1', 2H 7') for registered matches
    const newMilestones = [];
    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        const { half, min } = parseMatchMinute(String(match?.status || ''));
        if (!half) continue;
        for (const ms of MILESTONES) {
            if (ms.half === half && min >= ms.minThreshold) {
                const msKey = key + '|' + ms.id;
                if (!sentMilestones.has(msKey)) {
                    sentMilestones.add(msKey);
                    newMilestones.push({
                        timestamp: kickoffTimeByMatchKey.get(key) || timestamp,
                        league: match?.league || '',
                        home_team: match?.homeTeam || '',
                        away_team: match?.awayTeam || '',
                        milestone: ms.id,
                    });
                }
            }
        }
    }

    // Send new match registrations
    if (newMatches.length) {
        try {
            await fetch('http://localhost/sabaraja/goal-log-save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ matches: newMatches })
            });
        } catch (_) {}
    }

    // Send milestones
    if (newMilestones.length) {
        try {
            await fetch('http://localhost/sabaraja/goal-log-save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ milestones: newMilestones })
            });
        } catch (_) {}
    }

    if (!newGoals.length) return;

    // append to chrome.storage goalLog
    const stored = await chrome.storage.local.get(['goalLog']);
    const existing = Array.isArray(stored.goalLog) ? stored.goalLog : [];
    const updated = [...existing, ...newGoals].slice(-500);
    await chrome.storage.local.set({ goalLog: updated });

    // send goals to PHP server
    try {
        await fetch('http://localhost/sabaraja/goal-log-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ goals: newGoals })
        });
    } catch (_) {}
}

function createDataSignature(data) {
    if (!data?.matches) {
        return null;
    }

    return JSON.stringify({
        count: data.count,
        matches: data.matches
    });
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function getMatchTeams(match) {
    return match?.teams || `${match?.homeTeam || 'Unknown'} vs ${match?.awayTeam || 'Unknown'}`;
}

function createMatchKey(match) {
    return JSON.stringify({
        league: match?.league || 'N/A',
        teams: getMatchTeams(match)
    });
}

function isSecondHalfStatus(match) {
    const status = String(match?.status || '').trim();
    return /^2H\s+\d+'$/i.test(status);
}

function normalizeTeamName(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/\([^)]*\)/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function toThresholdNumber(value, fallback = TARGET_ODD_MIN) {
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

function normalizeWatchMarketSelection(value, fallback = DEFAULT_CUSTOM_WATCH_MARKET) {
    const rawValue = String(value || '').trim().replace(',', '.').toLowerCase().replace(/\s+/g, '');
    if (!rawValue) {
        return fallback;
    }

    const normalized = rawValue.startsWith('o') ? rawValue : `o${rawValue}`;
    const match = normalized.match(/\d+(?:\.\d+)?/);
    if (!match) {
        return fallback;
    }

    const numeric = Number(match[0]);
    if (!Number.isFinite(numeric) || numeric <= 0) {
        return fallback;
    }

    return `o${Number(numeric).toString()}`;
}

function formatWatchMarketForDisplay(value) {
    const normalizedSelection = normalizeWatchMarketSelection(value, TARGET_ODD_SELECTION);
    return `O/U ${normalizedSelection.replace(/^o/, '')}`;
}

function getDefaultCustomWatchConfig() {
    return {
        teamRules: [],
        customOddThreshold: DEFAULT_CUSTOM_WATCH_CONFIG.customOddThreshold,
        customOddSelection: DEFAULT_CUSTOM_WATCH_CONFIG.customOddSelection
    };
}

function normalizeWatchTeamRule(rule = {}, fallback = {}) {
    const fallbackThreshold = toThresholdNumber(fallback.customOddThreshold, DEFAULT_CUSTOM_WATCH_CONFIG.customOddThreshold);
    const fallbackSelection = normalizeWatchMarketSelection(fallback.customOddSelection, DEFAULT_CUSTOM_WATCH_CONFIG.customOddSelection);

    const normalizedTeam = normalizeTeamName(rule.team || rule.teamName || rule.name || rule.team_name);
    if (!normalizedTeam) {
        return null;
    }

    return {
        team: normalizedTeam,
        customOddThreshold: toThresholdNumber(rule.customOddThreshold ?? rule.threshold, fallbackThreshold),
        customOddSelection: normalizeWatchMarketSelection(rule.customOddSelection ?? rule.market ?? rule.selection, fallbackSelection)
    };
}

function normalizeWatchTeamRules(rawRules = [], fallback = {}) {
    if (!Array.isArray(rawRules) || !rawRules.length) {
        return [];
    }

    const seen = new Set();
    const nextRules = [];

    rawRules.forEach((rule) => {
        const normalizedRule = normalizeWatchTeamRule(rule, fallback);
        if (!normalizedRule || seen.has(normalizedRule.team)) {
            return;
        }

        seen.add(normalizedRule.team);
        nextRules.push(normalizedRule);
    });

    return nextRules;
}

async function getCustomWatchConfig() {
    const data = await chrome.storage.local.get([CUSTOM_WATCH_CONFIG_KEY]);
    const stored = data?.[CUSTOM_WATCH_CONFIG_KEY] || {};

    const fallbackThreshold = toThresholdNumber(stored.customOddThreshold, DEFAULT_CUSTOM_WATCH_CONFIG.customOddThreshold);
    const fallbackSelection = normalizeWatchMarketSelection(stored.customOddSelection, DEFAULT_CUSTOM_WATCH_CONFIG.customOddSelection);
    const legacyTeamList = Array.isArray(stored.teamList)
        ? stored.teamList
            .map((team) => normalizeTeamName(team))
            .filter(Boolean)
        : [];
    const normalizedLegacyRules = legacyTeamList.map((team) => ({
        team,
        customOddThreshold: fallbackThreshold,
        customOddSelection: fallbackSelection
    }));

    const normalizedRules = normalizeWatchTeamRules(stored.teamRules, {
        customOddThreshold: fallbackThreshold,
        customOddSelection: fallbackSelection
    });

    return {
        ...getDefaultCustomWatchConfig(),
        ...stored,
        teamRules: normalizedRules.length ? normalizedRules : normalizedLegacyRules,
        customOddThreshold: fallbackThreshold,
        customOddSelection: fallbackSelection
    };
}

function getWatchRuleByMatch(match, customTeamRules = []) {
    if (!Array.isArray(customTeamRules) || !customTeamRules.length) {
        return false;
    }

    const teams = [
        match?.homeTeam,
        match?.awayTeam
    ].map(normalizeTeamName).filter(Boolean);

    for (const rule of customTeamRules) {
        const teamName = normalizeTeamName(rule?.team);
        if (!teamName) {
            continue;
        }

        const matched = teams.some((team) => team.includes(teamName) || teamName.includes(team));
        if (matched) {
            return {
                team: teamName,
                customOddThreshold: toThresholdNumber(rule.customOddThreshold, DEFAULT_CUSTOM_WATCH_CONFIG.customOddThreshold),
                customOddSelection: normalizeWatchMarketSelection(rule.customOddSelection, DEFAULT_CUSTOM_WATCH_CONFIG.customOddSelection)
            };
        }
    }

    return null;
}

function isCustomWatchTeam(match, customTeamList = []) {
    return Boolean(getWatchRuleByMatch(match, customTeamList));
}

function getMatchWatchContext(match, customConfig = {}) {
    const normalizedTeamRules = Array.isArray(customConfig.teamRules) ? customConfig.teamRules : [];
    const matchTeamRule = getWatchRuleByMatch(match, normalizedTeamRules);

    const isWatched = Boolean(matchTeamRule);
    const baseThreshold = toThresholdNumber(customConfig.customOddThreshold, DEFAULT_CUSTOM_WATCH_CONFIG.customOddThreshold);
    const baseSelection = normalizeWatchMarketSelection(customConfig.customOddSelection, DEFAULT_CUSTOM_WATCH_CONFIG.customOddSelection);
    const appliedThreshold = isWatched
        ? toThresholdNumber(matchTeamRule.customOddThreshold, baseThreshold)
        : TARGET_ODD_MIN;
    const appliedSelection = isWatched
        ? normalizeWatchMarketSelection(matchTeamRule.customOddSelection, baseSelection)
        : TARGET_ODD_SELECTION;

    return {
        isWatched,
        watchTeamRule: matchTeamRule,
        appliedThreshold,
        appliedSelection,
        appliedBy: isWatched ? matchTeamRule?.team : null
    };
}

async function setCustomWatchConfig(payload = {}) {
    const fallbackThreshold = toThresholdNumber(payload.customOddThreshold, DEFAULT_CUSTOM_WATCH_CONFIG.customOddThreshold);
    const fallbackSelection = normalizeWatchMarketSelection(payload.customOddSelection, DEFAULT_CUSTOM_WATCH_CONFIG.customOddSelection);
    const legacyTeamList = Array.isArray(payload.teamList)
        ? payload.teamList
            .map((team) => normalizeTeamName(team))
            .filter(Boolean)
        : [];

    const normalizedRulesFromPayload = normalizeWatchTeamRules(payload.teamRules, {
        customOddThreshold: fallbackThreshold,
        customOddSelection: fallbackSelection
    });

    const fallbackRules = legacyTeamList.map((team) => ({
        team,
        customOddThreshold: fallbackThreshold,
        customOddSelection: fallbackSelection
    }));

    const normalized = {
        teamRules: normalizedRulesFromPayload.length ? normalizedRulesFromPayload : fallbackRules,
        customOddThreshold: toThresholdNumber(payload.customOddThreshold, DEFAULT_CUSTOM_WATCH_CONFIG.customOddThreshold),
        customOddSelection: normalizeWatchMarketSelection(payload.customOddSelection, DEFAULT_CUSTOM_WATCH_CONFIG.customOddSelection)
    };

    await chrome.storage.local.set({ [CUSTOM_WATCH_CONFIG_KEY]: normalized });
    return normalized;
}

function normalizeMarketValue(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/\s+/g, '');
}

function parseLocaleFloat(value) {
    return parseFloat(String(value || '').replace(',', '.'));
}

function parseSelectionValue(value) {
    const normalized = String(value || '').replace(/,/g, '.').toLowerCase().trim();
    const match = normalized.match(/\d+(?:\.\d+)?/);
    if (!match) {
        return null;
    }

    const numeric = Number(match[0]);
    return Number.isFinite(numeric) ? numeric : null;
}

function isSelectionMatch(label, marketName, wantedSelection) {
    const normalizedLabel = normalizeMarketValue(label);
    const normalizedWanted = normalizeMarketValue(wantedSelection);

    if (!normalizedWanted) {
        return false;
    }

    if (
        normalizedLabel === normalizedWanted
        || normalizedLabel.includes(normalizedWanted)
        || normalizedWanted.includes(normalizedLabel)
    ) {
        return true;
    }

    const wantedValue = parseSelectionValue(normalizedWanted);
    if (wantedValue === null) {
        return false;
    }

    const labelValue = parseSelectionValue(normalizedLabel);
    if (labelValue !== null && labelValue === wantedValue) {
        return true;
    }

    const marketValue = parseSelectionValue(marketName);
    return marketValue !== null && marketValue === wantedValue;
}

function getOverOddsBySelection(match, selections = [TARGET_ODD_SELECTION], marketFilter = TARGET_ODD_MARKET) {
    const odds = Array.isArray(match?.odds) ? match.odds : [];
    const wantedSelections = new Set(selections.map((selection) => normalizeMarketValue(selection)));
    const foundSelections = {};

    for (const marketLine of odds) {
        const parts = String(marketLine || '').split(': ');
        if (parts.length < 2) {
            continue;
        }

        const marketName = parts[0].trim();
        if (!normalizeMarketValue(marketName).includes(marketFilter)) {
            continue;
        }

        const optionsText = parts.slice(1).join(': ');
        const options = optionsText.split(' | ');
        for (const option of options) {
            if (option === '[LOCKED]') {
                continue;
            }

            const separatorIndex = option.indexOf(':');
            if (separatorIndex === -1) {
                continue;
            }

            const label = option.slice(0, separatorIndex).trim();
            const oddText = option.slice(separatorIndex + 1).trim();
            const oddValue = parseLocaleFloat(oddText);
            if (!Number.isFinite(oddValue)) {
                continue;
            }

            const matchedSelection = Array.from(wantedSelections).find((wantedSelection) => isSelectionMatch(label, marketName, wantedSelection));
            if (!matchedSelection) {
                continue;
            }

            foundSelections[matchedSelection] = {
                marketName,
                label,
                oddValue
            };

            if (Object.keys(foundSelections).length === wantedSelections.size) {
                return foundSelections;
            }
        }
    }

    return foundSelections;
}

function getTargetOverOdd(match, marketSelection = TARGET_ODD_SELECTION, marketFilter = TARGET_ODD_MARKET) {
    const oddsMap = getOverOddsBySelection(match, [marketSelection], marketFilter);
    const normalizedSelection = normalizeMarketValue(marketSelection);
    return oddsMap[normalizedSelection] || null;
}

function getQualifiedOddsAlert(match, marketSelection = TARGET_ODD_SELECTION, threshold = TARGET_ODD_MIN, isWatched = false) {
    if (!isWatched && !isSecondHalfStatus(match)) {
        return null;
    }

    const normalizedSelection = normalizeWatchMarketSelection(marketSelection, TARGET_ODD_SELECTION);
    const marketFilter = isWatched ? TARGET_FT_ODD_MARKET : TARGET_ODD_MARKET;
    const targetOdd = getTargetOverOdd(match, normalizedSelection, marketFilter);
    const numericThreshold = toThresholdNumber(threshold, TARGET_ODD_MIN);

    if (!targetOdd || !(targetOdd.oddValue >= numericThreshold)) {
        return null;
    }

    return targetOdd;
}

function pruneMatchAlertState(matches) {
    const activeMatchKeys = new Set(matches.map((match) => createMatchKey(match)));

    const isStateMatchForActiveMatch = (stateKey) => activeMatchKeys.has(splitStateKey(stateKey).matchKey);

    const notifiedMatchKeysNormalized = Array.from(notifiedMatchKeys)
        .filter((stateKey) => isStateMatchForActiveMatch(stateKey));
    const oddHistoryKeysNormalized = Array.from(oddHistoryByMatchKey.keys())
        .filter((matchKey) => activeMatchKeys.has(matchKey));
    const oddInsightKeysNormalized = Array.from(oddInsightByMatchKey.keys())
        .filter((matchKey) => activeMatchKeys.has(matchKey));

    notifiedMatchKeys = new Set(notifiedMatchKeysNormalized);

    wasAboveThresholdByMatchKey = new Map(
        Array.from(wasAboveThresholdByMatchKey.entries())
            .filter(([stateKey]) => isStateMatchForActiveMatch(stateKey))
    );

    oddHistoryByMatchKey = new Map(
        Array.from(oddHistoryByMatchKey.entries())
            .filter(([matchKey]) => oddHistoryKeysNormalized.includes(matchKey))
    );

    oddInsightByMatchKey = new Map(
        Array.from(oddInsightByMatchKey.entries())
            .filter(([matchKey]) => oddInsightKeysNormalized.includes(matchKey))
    );
}

function splitStateKey(stateKey) {
    const keyText = String(stateKey || '');
    const delimiter = '::';
    const lastIndex = keyText.lastIndexOf(delimiter);
    if (lastIndex === -1) {
        return {
            matchKey: keyText,
            threshold: TARGET_ODD_MIN,
            selection: TARGET_ODD_SELECTION
        };
    }

    const prevIndex = keyText.lastIndexOf(delimiter, lastIndex - 1);

    if (prevIndex === -1) {
        return {
            matchKey: keyText.slice(0, lastIndex),
            threshold: Number(keyText.slice(lastIndex + delimiter.length)) || TARGET_ODD_MIN,
            selection: TARGET_ODD_SELECTION
        };
    }

    return {
        matchKey: keyText.slice(0, prevIndex),
        threshold: Number(keyText.slice(prevIndex + delimiter.length, lastIndex)) || TARGET_ODD_MIN,
        selection: String(keyText.slice(lastIndex + delimiter.length)) || TARGET_ODD_SELECTION
    };
}

function getThresholdStateKey(matchKey, threshold, marketSelection = TARGET_ODD_SELECTION) {
    const normalizedThreshold = toThresholdNumber(threshold, TARGET_ODD_MIN).toFixed(2);
    const normalizedSelection = normalizeWatchMarketSelection(marketSelection, TARGET_ODD_SELECTION);
    return `${matchKey}::${normalizedThreshold}::${normalizedSelection}`;
}

function getRecentHistoryPoint(history, timeWindowMs) {
    if (!Array.isArray(history) || history.length === 0) {
        return null;
    }

    const latest = history[history.length - 1];
    for (let index = history.length - 1; index >= 0; index -= 1) {
        const point = history[index];
        if ((latest.timestamp - point.timestamp) >= timeWindowMs) {
            return point;
        }
    }

    return history[0] || null;
}

function computeAboveThresholdDurationMs(history, threshold = TARGET_ODD_MIN) {
    if (!Array.isArray(history) || history.length === 0) {
        return 0;
    }

    const latest = history[history.length - 1];
    if (!(latest.oddValue > threshold)) {
        return 0;
    }

    let startTimestamp = latest.timestamp;
    for (let index = history.length - 2; index >= 0; index -= 1) {
        const point = history[index];
        if (!(point.oddValue > threshold)) {
            break;
        }
        startTimestamp = point.timestamp;
    }

    return latest.timestamp - startTimestamp;
}

function countThresholdCrosses(history, threshold = TARGET_ODD_MIN) {
    if (!Array.isArray(history) || history.length <= 1) {
        return 0;
    }

    let crosses = 0;
    for (let index = 1; index < history.length; index += 1) {
        const prevAbove = history[index - 1].oddValue > threshold;
        const currAbove = history[index].oddValue > threshold;
        if (prevAbove !== currAbove) {
            crosses += 1;
        }
    }

    return crosses;
}

function createOddInsight(match, history, targetOdd, watchContext = {}, htScore = null) {
    const latest = history[history.length - 1] || null;
    const previous = history.length > 1 ? history[history.length - 2] : null;
    const windowPoint = getRecentHistoryPoint(history, ODD_SPIKE_WINDOW_MS);
    const threshold = toThresholdNumber(watchContext.appliedThreshold, TARGET_ODD_MIN);
    const selection = normalizeWatchMarketSelection(watchContext.appliedSelection, TARGET_ODD_SELECTION);
    const deltaFromPrevious = latest && previous ? latest.oddValue - previous.oddValue : 0;
    const deltaFromWindow = latest && windowPoint ? latest.oddValue - windowPoint.oddValue : 0;
    const aboveThresholdDurationMs = computeAboveThresholdDurationMs(history, threshold);
    const thresholdCrosses = countThresholdCrosses(history, threshold);
    const maxOdd = history.reduce((maxValue, point) => Math.max(maxValue, point.oddValue), latest ? latest.oddValue : 0);
    const crossedUp = latest && previous ? previous.oddValue <= threshold && latest.oddValue > threshold : false;
    const crossedDown = latest && previous ? previous.oddValue > threshold && latest.oddValue <= threshold : false;

    let pattern = 'Tracking';
    let severity = 'neutral';
    const thresholdText = threshold.toFixed(2);

    if (crossedDown && maxOdd > threshold) {
        pattern = 'Fake Breakout';
        severity = 'danger';
    } else if (latest && latest.oddValue > threshold && aboveThresholdDurationMs >= ODD_BREAKOUT_HOLD_MS) {
        pattern = 'Breakout';
        severity = 'danger';
    } else if (Math.abs(deltaFromWindow) >= ODD_SPIKE_DELTA) {
        pattern = deltaFromWindow > 0 ? 'Spike Up' : 'Spike Down';
        severity = deltaFromWindow > 0 ? 'warning' : 'neutral';
    } else if (crossedUp) {
        pattern = `Cross > ${thresholdText}`;
        severity = 'warning';
    } else if (thresholdCrosses >= 3) {
        pattern = 'Volatile';
        severity = 'warning';
    }

    const comparisonOdds = getOverOddsBySelection(match, COMPARISON_ODD_SELECTIONS);

    return {
        matchKey: createMatchKey(match),
        marketSelection: selection,
        threshold,
        marketDisplay: formatWatchMarketForDisplay(selection),
        marketName: targetOdd.marketName,
        label: targetOdd.label,
        currentOdd: latest ? latest.oddValue : null,
        previousOdd: previous ? previous.oddValue : null,
        deltaFromPrevious,
        deltaFromWindow,
        aboveThresholdDurationMs,
        thresholdCrosses,
        maxOdd,
        pattern,
        severity,
        status: String(match?.status || '').trim(),
        score: String(match?.score || '').trim(),
        updatedAt: latest ? latest.timestamp : Date.now(),
        isSecondHalf: isSecondHalfStatus(match),
        isAboveThreshold: latest ? latest.oddValue > threshold : false,
        htScore,
        comparisonOdds: Object.fromEntries(Object.entries(comparisonOdds).map(([key, value]) => [key, {
            label: value.label,
            oddValue: value.oddValue
        }]))
    };
}

async function updateOddTracking(matches) {
    const timestamp = Date.now();
    const watchConfig = await getCustomWatchConfig();

    for (const match of matches) {
        const matchKey = createMatchKey(match);
        const watchContext = getMatchWatchContext(match, watchConfig);
        const marketFilter = watchContext.isWatched ? TARGET_FT_ODD_MARKET : TARGET_ODD_MARKET;
        const targetOdd = getTargetOverOdd(match, watchContext.appliedSelection, marketFilter);

        if (!isSecondHalfStatus(match) || !targetOdd) {
            oddInsightByMatchKey.delete(matchKey);
            continue;
        }

        const history = oddHistoryByMatchKey.get(matchKey) || [];
        const lastPoint = history[history.length - 1] || null;
        if (!lastPoint || lastPoint.oddValue !== targetOdd.oddValue || lastPoint.status !== String(match?.status || '').trim() || lastPoint.score !== String(match?.score || '').trim()) {
            history.push({
                timestamp,
                oddValue: targetOdd.oddValue,
                status: String(match?.status || '').trim(),
                score: String(match?.score || '').trim()
            });
        }

        oddHistoryByMatchKey.set(matchKey, history.slice(-ODD_HISTORY_LIMIT));
        oddInsightByMatchKey.set(matchKey, createOddInsight(match, oddHistoryByMatchKey.get(matchKey), targetOdd, watchContext, shScoreByMatchKey.get(matchKey) || null));
    }

    await chrome.storage.local.set({
        liveOddInsights: Object.fromEntries(Array.from(oddInsightByMatchKey.entries()))
    });
}

function formatMatchMessage(match, targetOdd, watchContext = null) {
    const isWatchedTeam = Boolean(watchContext?.isWatched);
    const appliedThreshold = toThresholdNumber(watchContext?.appliedThreshold, TARGET_ODD_MIN);
    const appliedSelection = watchContext?.appliedSelection || TARGET_ODD_SELECTION;
    const normalizedSelection = normalizeWatchMarketSelection(appliedSelection, TARGET_ODD_SELECTION);
    const odds = Array.isArray(match?.odds) ? match.odds.slice(0, 3) : [];
    const lines = [
        '🚨 <b>LIVE O/U ALERT</b>',
        '',
        `⚽ <b>${escapeHtml(getMatchTeams(match))}</b>`,
        `📊 Score: <b>${escapeHtml(match?.score || '0-0')}</b>`,
        `🏆 League: ${escapeHtml(match?.league || 'N/A')}`,
        `⏰ Status: ${escapeHtml(match?.status || 'Live')}`,
        `🎯 Market: <b>${escapeHtml(targetOdd?.marketName || 'O/U')}: ${escapeHtml(targetOdd?.label || normalizedSelection)} @ ${escapeHtml((targetOdd?.oddValue || 0).toFixed(2))}</b>`,
        `👀 Watch team: ${isWatchedTeam ? 'Ya' : 'Tidak'}`,
        `📐 Rule: >${appliedThreshold.toFixed(2)}`,
        `📅 Time: ${new Date().toLocaleTimeString()}`,
        '',
        `🔥 <i>Alert dikirim saat market ${escapeHtml(targetOdd?.label || normalizedSelection)} sudah di atas ${appliedThreshold.toFixed(2)}.</i>`
    ];

    if (odds.length) {
        lines.push('', '📈 <b>Odds:</b>');
        odds.forEach((odd) => lines.push(`• ${escapeHtml(odd)}`));
    }

    return lines.join('\n');
}


function isTargetUrl(url) {
    return typeof url === 'string' && url.includes(TARGET_HOST);
}

async function getTargetTab() {
    if (currentTabId !== null) {
        try {
            const tab = await chrome.tabs.get(currentTabId);
            if (tab?.id && isTargetUrl(tab.url)) {
                return tab;
            }
        } catch (error) {
            currentTabId = null;
        }
    }

    const tabs = await chrome.tabs.query({});
    const targetTab = tabs.find((tab) => isTargetUrl(tab.url)) || null;
    currentTabId = targetTab?.id ?? null;
    return targetTab;
}

async function saveRuntimeState(partialState = {}) {
    const state = {
        isLiveRunning,
        currentTabId,
        ...partialState
    };

    await chrome.storage.local.set({ liveRuntimeState: state });
    return state;
}

async function updateLiveState(isRunning, extraState = {}) {
    isLiveRunning = isRunning;
    if (!isRunning) {
        await chrome.alarms.clear(LIVE_ALARM_NAME);
    } else {
        await chrome.alarms.create(LIVE_ALARM_NAME, {
            periodInMinutes: 0.1
        });
    }

    return saveRuntimeState(extraState);
}

async function restoreRuntimeState() {
    const [localData, sessionData] = await Promise.all([
        chrome.storage.local.get(['liveRuntimeState']),
        chrome.storage.session.get(['kickoffTimes', 'registeredKeys', 'sentMilestoneKeys', 'sentLate2HKeys', 'last1HGoalMins', 'has2HGoals'])
    ]);
    const runtimeState = localData.liveRuntimeState || {};

    isLiveRunning = Boolean(runtimeState.isLiveRunning);
    currentTabId = Number.isInteger(runtimeState.currentTabId) ? runtimeState.currentTabId : null;

    if (sessionData.kickoffTimes) {
        kickoffTimeByMatchKey = new Map(Object.entries(sessionData.kickoffTimes));
    }
    if (Array.isArray(sessionData.registeredKeys)) {
        registeredMatchKeys = new Set(sessionData.registeredKeys);
    }
    if (Array.isArray(sessionData.sentMilestoneKeys)) {
        sentMilestones = new Set(sessionData.sentMilestoneKeys);
    }
    if (Array.isArray(sessionData.sentLate2HKeys)) {
        sentLate2HSignals = new Set(sessionData.sentLate2HKeys);
    }
    if (sessionData.last1HGoalMins) {
        last1HGoalMinByMatchKey = new Map(Object.entries(sessionData.last1HGoalMins).map(([k, v]) => [k, Number(v)]));
    }
    if (sessionData.has2HGoals) {
        has2HGoalByMatchKey = new Map(Object.entries(sessionData.has2HGoals));
    }

    if (isLiveRunning) {
        await chrome.alarms.create(LIVE_ALARM_NAME, {
            periodInMinutes: 0.1
        });
    }
}

async function persistMatchState() {
    await chrome.storage.session.set({
        kickoffTimes: Object.fromEntries(kickoffTimeByMatchKey),
        registeredKeys: [...registeredMatchKeys],
        sentMilestoneKeys: [...sentMilestones],
        sentLate2HKeys: [...sentLate2HSignals],
        last1HGoalMins: Object.fromEntries(last1HGoalMinByMatchKey),
        has2HGoals: Object.fromEntries(has2HGoalByMatchKey),
    });
}

async function setStatus(patch = {}) {
    const data = await chrome.storage.local.get(['liveStatus']);
    const nextStatus = {
        lastUpdate: '-',
        lastSent: '-',
        lastRetry: '0',
        serverStatus: 'Telegram: -',
        pageStatus: 'Checking page...',
        lastCycle: '-',
        lastRefresh: '-',
        lastExtractStatus: '-',
        error: '',
        ...data.liveStatus,
        ...patch
    };

    await chrome.storage.local.set({ liveStatus: nextStatus });
    return nextStatus;
}

async function setSavedMatchData(data) {
    await chrome.storage.local.set({
        lastMatches: data.matches,
        lastGroupedMatches: data.groupedMatches || [],
        lastCount: data.count,
        lastUpdate: data.time
    });
}

async function requestContentAction(tabId, action) {
    await ensureContentScript(tabId);
    return chrome.tabs.sendMessage(tabId, { action });
}

async function ensureContentScript(tabId) {
    try {
        await chrome.tabs.sendMessage(tabId, { action: 'ping' });
        return;
    } catch (error) {
        if (!error?.message?.includes('Receiving end does not exist')) {
            throw error;
        }
    }

    await chrome.scripting.executeScript({
        target: { tabId },
        files: ['content.js']
    });
}

async function clickPageRefresh(tabId) {
    try {
        const response = await requestContentAction(tabId, 'clickRefresh');
        if (response?.ok) {
            return response.data || { clicked: false, selector: null };
        }
        return { clicked: false, selector: null };
    } catch (error) {
        return {
            clicked: false,
            selector: null,
            error: error.message || 'Refresh message failed'
        };
    }
}

async function extractDataFromTab(tabId) {
    const response = await requestContentAction(tabId, 'extractData');
    if (!response?.ok || !response.data) {
        throw new Error(response?.error || 'Failed to extract data from page');
    }

    return response.data;
}

async function sendToServer(data, isAutoSend = false) {
    try {
        const matches = Array.isArray(data?.matches) ? data.matches : [];
        if (!matches.length) {
            await setStatus({
                serverStatus: 'Telegram: No data',
                error: 'No match data to send.'
            });
            return false;
        }

        const watchConfig = await getCustomWatchConfig();
        pruneMatchAlertState(matches);

        const evaluatedMatches = matches
            .map((match) => {
                const matchKey = createMatchKey(match);
                const watchContext = getMatchWatchContext(match, watchConfig);
                const threshold = watchContext.appliedThreshold;
                const marketSelection = watchContext.appliedSelection;

                return {
                    match,
                    matchKey,
                    stateKey: getThresholdStateKey(matchKey, threshold, marketSelection),
                    targetOdd: getQualifiedOddsAlert(match, marketSelection, threshold, watchContext.isWatched),
                    watchContext
                };
            });

        const alertMatches = evaluatedMatches.filter(({ stateKey, targetOdd }) => {
            if (!targetOdd || notifiedMatchKeys.has(stateKey)) {
                return false;
            }

            return wasAboveThresholdByMatchKey.get(stateKey) !== true;
        });

        if (!alertMatches.length) {
            evaluatedMatches.forEach(({ stateKey, targetOdd }) => {
                wasAboveThresholdByMatchKey.set(stateKey, Boolean(targetOdd));
            });

            const defaultThresholdText = TARGET_ODD_MIN.toFixed(2);
            const defaultSelectionText = formatWatchMarketForDisplay(TARGET_ODD_SELECTION);
            const watchTeamsCount = Array.isArray(watchConfig.teamRules) ? watchConfig.teamRules.length : 0;
            const hasWatchTeams = watchTeamsCount > 0;
            const customThresholdText = toThresholdNumber(watchConfig.customOddThreshold, TARGET_ODD_MIN).toFixed(2);
            const customSelectionText = formatWatchMarketForDisplay(watchConfig.customOddSelection);
            const statusText = hasWatchTeams
                ? `Telegram: No alert on ${defaultSelectionText} > ${defaultThresholdText} (team watch uses per-team rules; fallback ${customSelectionText} > ${customThresholdText})`
                : `Telegram: No alert on ${defaultSelectionText} > ${defaultThresholdText}`;

            await setStatus({
                serverStatus: statusText,
                error: ''
            });
            return false;
        }

        let sentCount = 0;
        for (const { match, stateKey, targetOdd, watchContext } of alertMatches) {
            const res = await fetch(TELEGRAM_API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    chat_id: TELEGRAM_CHAT_ID,
                    text: formatMatchMessage(match, targetOdd, watchContext),
                    parse_mode: 'HTML'
                })
            });

            if (!res.ok) {
                throw new Error(`Telegram API error ${res.status}`);
            }

            notifiedMatchKeys.add(stateKey);
            wasAboveThresholdByMatchKey.set(stateKey, true);
            sentCount += 1;
        }

        evaluatedMatches.forEach(({ stateKey, targetOdd }) => {
            if (!notifiedMatchKeys.has(stateKey)) {
                wasAboveThresholdByMatchKey.set(stateKey, Boolean(targetOdd));
            }
        });

        const sentAt = new Date().toLocaleTimeString();
        await setStatus({
            serverStatus: isAutoSend ? `Telegram: Auto-sent ${sentCount} alert ✓` : `Telegram: Sent ${sentCount} alert ✓`,
            lastSent: sentAt,
            lastRetry: isAutoSend ? undefined : 'manual',
            error: ''
        });
        return true;
    } catch (error) {
        await setStatus({
            serverStatus: isAutoSend ? 'Telegram: Auto-send failed ✗' : 'Telegram: Failed ✗',
            error: error.message || 'Telegram send failed'
        });
    }

    return false;
}


async function sendToServerWithRetry(data) {
    let attempt = 0;
    await setStatus({ lastRetry: '0' });

    while (attempt <= AUTO_SEND_RETRY_COUNT) {
        const sent = await sendToServer(data, true);
        if (sent) {
            await setStatus({ lastRetry: String(attempt) });
            return true;
        }

        attempt += 1;
        if (attempt <= AUTO_SEND_RETRY_COUNT) {
                await setStatus({
                    serverStatus: `Telegram: Retry ${attempt}/${AUTO_SEND_RETRY_COUNT}`,
                    lastRetry: String(attempt)
                });
            await delay(AUTO_SEND_RETRY_DELAY_MS);
        }
    }

    await setStatus({
        serverStatus: 'Telegram: Auto-send failed after retry ✗',
        lastRetry: String(AUTO_SEND_RETRY_COUNT)
    });
    return false;
}

async function trackLate2HSignal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    for (const match of matches) {
        const league = String(match?.league || '');
        if (!league.includes('INTERNATIONAL FRIENDLY Virtual PES 21 - 20 Mins')) continue;

        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        if (sentLate2HSignals.has(key)) continue;

        const status = String(match?.status || '').trim();
        const shMin = getShMinute(status);
        if (shMin !== 6) continue; // only trigger exactly at 2H 6'

        const homeScore = parseInt(match?.homeScore ?? '0', 10);
        const awayScore = parseInt(match?.awayScore ?? '0', 10);
        const diff = Math.abs(homeScore - awayScore);
        const has2HGoal = has2HGoalByMatchKey.get(key) === true;
        const last1HMin = last1HGoalMinByMatchKey.get(key) ?? -1;

        // SYARAT 1: selisih ≤ 1 + sudah ada goal 2H
        const signal1 = diff <= 1 && has2HGoal;
        // SYARAT 2: last 1H goal menit 7-8 + selisih ≤ 2
        const signal2 = last1HMin >= 7 && diff <= 2;

        if (!signal1 && !signal2) continue;

        sentLate2HSignals.add(key);

        const reason = signal1
            ? `✅ Selisih ${diff} gol + sudah ada goal 2H`
            : `✅ Last 1H goal menit ${last1HMin}' + selisih ${diff} gol`;

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const score = `${homeScore}-${awayScore}`;

        const msg =
            `🚨 <b>SIGNAL LATE 2H GOAL!</b>\n` +
            `⚽ <b>${home} vs ${away}</b>\n` +
            `📊 Skor: <b>${score}</b> | Menit: <b>${escapeHtml(status)}</b>\n` +
            `📋 ${reason}\n` +
            `🎯 <b>MASUK sekarang — over next goal / 2H 7'+</b>`;

        await sendTelegramText(msg);
    }
}

async function handleFreshData(data) {
    await setSavedMatchData(data);
    await trackGoalEvents(Array.isArray(data?.matches) ? data.matches : []);
    await persistMatchState();
    await trackLate2HSignal(Array.isArray(data?.matches) ? data.matches : []);
    await trackShgAlert(Array.isArray(data?.matches) ? data.matches : []);
    await updateOddTracking(Array.isArray(data?.matches) ? data.matches : []);
    await setStatus({
        pageStatus: '✓ Target page detected',
        lastUpdate: data.time,
        error: ''
    });

    if (!isLiveRunning) {
        return { sent: false, changed: false };
    }

    const currentSignature = createDataSignature(data);
    if (currentSignature && currentSignature !== lastAutoSentSignature) {
        const sent = await sendToServerWithRetry(data);
        if (sent) {
            lastAutoSentSignature = currentSignature;
        }
        return { sent, changed: true };
    }

    await setStatus({ serverStatus: 'Server: No change' });
    return { sent: false, changed: false };
}

async function runLiveCycle() {
    if (!isLiveRunning || isLiveCycleRunning) {
        return;
    }

    isLiveCycleRunning = true;

    try {
        const targetTab = await getTargetTab();
        if (!targetTab?.id || !isTargetUrl(targetTab.url)) {
            await setStatus({
                pageStatus: '✗ Not on target page',
                error: 'Target tab not found. Will retry next cycle.'
            });
            return;
        }

        currentTabId = targetTab.id;
        await saveRuntimeState();
        await setStatus({
            pageStatus: '✓ Target page detected',
            lastCycle: new Date().toLocaleTimeString(),
            error: ''
        });

        const refreshResult = await clickPageRefresh(targetTab.id);
        await setStatus({
            lastRefresh: refreshResult.clicked
                ? `Clicked ${new Date().toLocaleTimeString()}`
                : `Not found ${new Date().toLocaleTimeString()}`,
            error: refreshResult.error || ''
        });

        await delay(REFRESH_SETTLE_MS);

        if (!isLiveRunning) {
            return;
        }

        const data = await extractDataFromTab(targetTab.id);
        const firstMatchStatus = data.matches?.[0]?.status || '-';
        await setStatus({
            lastExtractStatus: `${firstMatchStatus} @ ${new Date().toLocaleTimeString()}`
        });
        await handleFreshData(data);
    } catch (error) {
        await setStatus({
            error: error.message || 'Live cycle failed',
            serverStatus: 'Server: Failed ✗'
        });
    } finally {
        isLiveCycleRunning = false;
    }

}

async function startLive() {
    const targetTab = await getTargetTab();
    if (!targetTab?.id || !isTargetUrl(targetTab.url)) {
        await updateLiveState(false);
        await setStatus({
            pageStatus: '✗ Not on target page',
            error: 'Open the BPVM target page first.'
        });
        return { ok: false, error: 'Not on target page' };
    }

    currentTabId = targetTab.id;
    lastAutoSentSignature = null;
    await updateLiveState(true);
    await setStatus({
        pageStatus: '✓ Target page detected',
        error: ''
    });

    runLiveCycle();
    return { ok: true };
}

async function stopLive() {
    await updateLiveState(false);
    await setStatus({ error: '' });
    return { ok: true };
}

async function sendStoredDataToServer() {
    const data = await chrome.storage.local.get(['lastMatches', 'lastGroupedMatches', 'lastCount', 'lastUpdate']);
    if (!data.lastMatches?.length) {
        await setStatus({ error: 'No data to send! Extract first.' });
        return { ok: false, error: 'No data to send' };
    }

    const payload = {
        matches: data.lastMatches,
        groupedMatches: data.lastGroupedMatches || [],
        count: data.lastCount || data.lastMatches.length,
        time: data.lastUpdate || new Date().toLocaleTimeString()
    };

    const sent = await sendToServer(payload, false);
    return sent ? { ok: true } : { ok: false, error: 'Send failed' };
}

async function getPopupState() {
    const data = await chrome.storage.local.get([
        'lastMatches',
        'lastGroupedMatches',
        'lastCount',
        'lastUpdate',
        'liveOddInsights',
        'liveStatus',
        'liveRuntimeState',
        CUSTOM_WATCH_CONFIG_KEY
    ]);

    return {
        ok: true,
        data: {
            matches: data.lastMatches || [],
            groupedMatches: data.lastGroupedMatches || [],
            count: data.lastCount || 0,
            time: data.lastUpdate || '-',
            oddInsights: data.liveOddInsights || {},
            liveStatus: data.liveStatus || null,
            customWatchConfig: data[CUSTOM_WATCH_CONFIG_KEY] || getDefaultCustomWatchConfig(),
            runtimeState: data.liveRuntimeState || {
                isLiveRunning: false,
                currentTabId: null
            }
        }
    };
}

chrome.runtime.onInstalled.addListener(() => {
    chrome.storage.local.set({
        liveOddInsights: {},
        liveRuntimeState: {
            isLiveRunning: false,
            currentTabId: null
        },
        [CUSTOM_WATCH_CONFIG_KEY]: getDefaultCustomWatchConfig(),
        liveStatus: {
            lastUpdate: '-',
            lastSent: '-',
            lastRetry: '0',
            serverStatus: 'Telegram: -',
            pageStatus: 'Checking page...',
            lastCycle: '-',
            lastRefresh: '-',
            lastExtractStatus: '-',
            error: ''
        }
    });
});

chrome.runtime.onStartup.addListener(() => {
    restoreRuntimeState().catch(() => {});
});

restoreRuntimeState().catch(() => {});

chrome.tabs.onRemoved.addListener((tabId) => {
    if (tabId === currentTabId) {
        currentTabId = null;
        saveRuntimeState().catch(() => {});
    }
});

chrome.alarms.onAlarm.addListener((alarm) => {
    if (alarm.name !== LIVE_ALARM_NAME) {
        return;
    }

    restoreRuntimeState()
        .then(() => {
            if (isLiveRunning) {
                return runLiveCycle();
            }
            return null;
        })
        .catch(() => {});
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    (async () => {
        switch (message?.action) {
            case 'startLive':
                sendResponse(await startLive());
                break;
            case 'stopLive':
                sendResponse(await stopLive());
                break;
            case 'extractNow': {
                const targetTab = await getTargetTab();
                if (!targetTab?.id) {
                    sendResponse({ ok: false, error: 'Target tab not found' });
                    break;
                }

                const data = await extractDataFromTab(targetTab.id);
                await handleFreshData(data);
                sendResponse({ ok: true, data });
                break;
            }
            case 'sendStoredData':
                sendResponse(await sendStoredDataToServer());
                break;
    case 'getPopupState':
                sendResponse(await getPopupState());
                break;
            case 'getCustomWatchConfig':
                sendResponse(await getCustomWatchConfig());
                break;
            case 'setCustomWatchConfig':
                sendResponse(await setCustomWatchConfig(message.payload || {}));
                break;
            default:
                sendResponse({ ok: false, error: 'Unknown action' });
        }
    })().catch((error) => {
        sendResponse({ ok: false, error: error.message || 'Unhandled background error' });
    });

    return true;
});
