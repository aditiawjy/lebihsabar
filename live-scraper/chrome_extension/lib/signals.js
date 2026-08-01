const MATCH_STATE_STALE_MS = 45 * 60 * 1000;

function clearThresholdAlertState(matchKey) {
    for (const stateKey of Array.from(notifiedMatchKeys)) {
        if (String(stateKey).startsWith(`${matchKey}::`)) {
            notifiedMatchKeys.delete(stateKey);
        }
    }

    for (const stateKey of Array.from(wasAboveThresholdByMatchKey.keys())) {
        if (String(stateKey).startsWith(`${matchKey}::`)) {
            wasAboveThresholdByMatchKey.delete(stateKey);
        }
    }
}

function resetMatchTrackingState(key) {
    registeredMatchKeys.delete(key);
    kickoffTimeByMatchKey.delete(key);
    oddsSnapshotSent.delete(key);
    lastScoreByMatchKey.delete(key);
    shScoreByMatchKey.delete(key);
    sentNG1Signals.delete(key);
    sentHT22Signals.delete(key);
    sentOneGoal2HSignals.delete(key);
    sentTeamHtSignals.delete(key);
    sentP7Signals.delete(key);
    sentP14Signals.delete(key);
    sentP19Signals.delete(key);
    for (const signalKey of Array.from(sentP727374Signals)) {
        if (String(signalKey).startsWith(`${key}|`)) {
            sentP727374Signals.delete(signalKey);
        }
    }
    p727374SignalsByMatchKey.delete(key);
    last1HGoalMinByMatchKey.delete(key);
    first1HGoalMinByMatchKey.delete(key);
    all1HGoalMinsByMatchKey.delete(key);
    all1HScorersByMatchKey.delete(key);
    has2HGoalByMatchKey.delete(key);
    all2HGoalMinsByMatchKey.delete(key);
    all2HScorersByMatchKey.delete(key);
    lastSeenAtByMatchKey.delete(key);
    lastStatusByMatchKey.delete(key);
    oddHistoryByMatchKey.delete(key);
    oddInsightByMatchKey.delete(key);
    lastLoggedOddsByMatchKey.delete(key);
    clearThresholdAlertState(key);
    for (const ms of MILESTONES) {
        sentMilestones.delete(key + '|' + ms.id);
    }
}

function parseScoreTuple(scoreStr) {
    const parts = String(scoreStr || '0-0').split('-').map((value) => parseInt(value, 10) || 0);
    return { home: parts[0] || 0, away: parts[1] || 0, total: (parts[0] || 0) + (parts[1] || 0) };
}

function shouldResetFixtureState(key, minute, scoreStr, nowMs) {
    if (!registeredMatchKeys.has(key)) return false;

    const lastSeenAt = lastSeenAtByMatchKey.get(key) || 0;
    const lastStatus = String(lastStatusByMatchKey.get(key) || '').trim();
    const prevStatus = parseMatchMinute(lastStatus);
    const curStatus = parseMatchMinute(minute);
    const prevScore = parseScoreTuple(lastScoreByMatchKey.get(key) || '0-0');
    const curScore = parseScoreTuple(scoreStr);
    const wasHalftime = /^H\.?Time$/i.test(lastStatus);
    const isHalftime = /^H\.?Time$/i.test(String(minute || '').trim());

    if (lastSeenAt && (nowMs - lastSeenAt) > MATCH_STATE_STALE_MS) {
        return true;
    }

    if (prevScore.total > curScore.total && isKickoffMinute(minute)) {
        return true;
    }

    if ((prevStatus.half === '2H' || wasHalftime) && curStatus.half === '1H') {
        return true;
    }

    if (prevStatus.half === curStatus.half && prevStatus.min >= 0 && curStatus.min >= 0 && curStatus.min + 2 < prevStatus.min) {
        return true;
    }

    if ((prevStatus.half === '2H' || wasHalftime) && isKickoffMinute(minute)) {
        return true;
    }

    if ((last1HGoalMinByMatchKey.get(key) || -1) >= 7 && curStatus.half === '1H' && curStatus.min >= 0 && curStatus.min <= 4) {
        return true;
    }

    if (has2HGoalByMatchKey.get(key) && (isHalftime || (curStatus.half === '2H' && curStatus.min <= 2))) {
        return true;
    }

    return false;
}

function registerMatchIfNeeded(key, match, timestamp, newMatches) {
    if (registeredMatchKeys.has(key)) return false;

    registeredMatchKeys.add(key);
    kickoffTimeByMatchKey.set(key, timestamp);
    newMatches.push({
        timestamp,
        league: match?.league || '',
        home_team: match?.homeTeam || '',
        away_team: match?.awayTeam || '',
    });
    return true;
}

function appendGoalState(key, prevScore, homeScore, awayScore, half, minuteValue) {
    const prevParts = String(prevScore).split('-').map((value) => parseInt(value, 10) || 0);
    const prevHome = prevParts[0] || 0;
    const prevAway = prevParts[1] || 0;
    const curHome = parseInt(homeScore, 10) || 0;
    const curAway = parseInt(awayScore, 10) || 0;
    const homeDelta = Math.max(0, curHome - prevHome);
    const awayDelta = Math.max(0, curAway - prevAway);
    const goalCount = homeDelta + awayDelta;

    if (half === '1H') {
        const curLast = last1HGoalMinByMatchKey.get(key) ?? -1;
        if (minuteValue > curLast) last1HGoalMinByMatchKey.set(key, minuteValue);
        if (!first1HGoalMinByMatchKey.has(key)) first1HGoalMinByMatchKey.set(key, minuteValue);

        const allMins = all1HGoalMinsByMatchKey.get(key) || [];
        for (let i = 0; i < goalCount; i += 1) allMins.push(minuteValue);
        all1HGoalMinsByMatchKey.set(key, allMins);
        const allScorers = all1HScorersByMatchKey.get(key) || [];
        for (let i = 0; i < homeDelta; i += 1) allScorers.push('H');
        for (let i = 0; i < awayDelta; i += 1) allScorers.push('A');
        all1HScorersByMatchKey.set(key, allScorers);
    }

    if (half === '2H') {
        has2HGoalByMatchKey.set(key, true);
        const all2HMins = all2HGoalMinsByMatchKey.get(key) || [];
        for (let i = 0; i < goalCount; i += 1) all2HMins.push(minuteValue);
        all2HGoalMinsByMatchKey.set(key, all2HMins);
        const all2HScorers = all2HScorersByMatchKey.get(key) || [];
        for (let i = 0; i < homeDelta; i += 1) all2HScorers.push('H');
        for (let i = 0; i < awayDelta; i += 1) all2HScorers.push('A');
        all2HScorersByMatchKey.set(key, all2HScorers);
    }
}

function buildTrackedGoalEventsForMatch(key, match, timestamp) {
    const events = [];
    let home = 0;
    let away = 0;
    const league = match?.league || '';
    const homeTeam = match?.homeTeam || '';
    const awayTeam = match?.awayTeam || '';
    const scorePairs = [
        {
            half: '1H',
            mins: all1HGoalMinsByMatchKey.get(key) || [],
            scorers: all1HScorersByMatchKey.get(key) || []
        },
        {
            half: '2H',
            mins: all2HGoalMinsByMatchKey.get(key) || [],
            scorers: all2HScorersByMatchKey.get(key) || []
        }
    ];

    for (const part of scorePairs) {
        const mins = Array.isArray(part.mins) ? part.mins : [];
        const scorers = Array.isArray(part.scorers) ? part.scorers : [];
        const count = Math.min(mins.length, scorers.length);
        for (let i = 0; i < count; i += 1) {
            const scorer = scorers[i];
            if (scorer === 'H') home += 1;
            else if (scorer === 'A') away += 1;
            else continue;

            events.push({
                timestamp,
                league,
                home_team: homeTeam,
                away_team: awayTeam,
                minute: `${part.half} ${mins[i]}'`,
                score_before: '0-0',
                score_after: `${home}-${away}`,
                home_score: String(home),
                away_score: String(away),
            });
        }
    }

    return events;
}

function buildScoreSnapshot(match, timestamp) {
    return {
        timestamp,
        league: match?.league || '',
        home_team: match?.homeTeam || '',
        away_team: match?.awayTeam || '',
        home_score: String(match?.homeScore ?? '0'),
        away_score: String(match?.awayScore ?? '0'),
    };
}

// -- Snapshot market O/U di dua momen: kickoff dan turun minum -------------------
// Yang disimpan hanya market FT. O/U karena itu yang benar-benar bisa ditaruhi
// untuk total gol. Odds sudah desimal: formatOddsValue() di content.js mengubah
// format Hong Kong (0.94 -> 1.94) dan Malay/Indonesia (-0.97 -> 2.03).
const oddsSnapshotSent = new Map();   // key -> Set berisi 'ko' / 'ht'

// Ambil "FT. O/U: o 5.25:1.94 | u 5.25:1.90" -> {line:'5.25', over:'1.94', under:'1.90'}
//
// Situs memakai swiper: hanya slide market yang sedang aktif yang ada di DOM,
// jadi "FT. O/U" sering belum tampil saat dibaca. Karena itu O/U mana pun
// diterima dengan FT diutamakan, dan nama marketnya ikut disimpan supaya
// nanti jelas angka itu berasal dari market yang mana.
function parseOverUnder(oddsList) {
    if (!Array.isArray(oddsList)) return null;

    const ouRows = oddsList.filter((s) => typeof s === 'string' && /O\/U\s*:/i.test(s));
    if (!ouRows.length) return null;
    const row = ouRows.find((s) => /^FT\./i.test(s)) || ouRows[0];

    const title = (row.split(':')[0] || '').trim();
    const over = row.match(/\bo\s*([\d.]+)\s*:\s*([\d.]+)/i);
    const under = row.match(/\bu\s*([\d.]+)\s*:\s*([\d.]+)/i);
    if (!over && !under) return null;

    return {
        market: title,
        line: (over && over[1]) || (under && under[1]) || '',
        over: over ? over[2] : '',
        under: under ? under[2] : '',
    };
}

function isHalfTimeStatus(status) {
    return /^h\.?\s*time$/i.test(String(status || '').trim());
}

// Kembalikan snapshot yang belum pernah dikirim untuk match ini.
// 'ko' diambil sekali saat match pertama terdaftar; 'ht' saat status H.Time.
function buildOddsSnapshot(key, match, timestamp, phase) {
    const sent = oddsSnapshotSent.get(key) || new Set();
    if (sent.has(phase)) return null;

    const ou = parseOverUnder(match?.odds);
    if (!ou || (!ou.over && !ou.under)) return null;   // market belum tampil, coba siklus berikutnya

    sent.add(phase);
    oddsSnapshotSent.set(key, sent);

    return {
        timestamp,
        phase,                                   // 'ko' atau 'ht'
        league: match?.league || '',
        home_team: match?.homeTeam || '',
        away_team: match?.awayTeam || '',
        status: String(match?.status || ''),
        score: `${match?.homeScore ?? '0'}-${match?.awayScore ?? '0'}`,
        market: ou.market,
        line: ou.line,
        over_odd: ou.over,
        under_odd: ou.under,
    };
}

function buildVisibleGoalBackfill(key, match, minute, scoreStr, timestamp) {
    const parsed = parseMatchMinute(minute);
    if (parsed.half !== '1H') return null;
    if (all1HGoalMinsByMatchKey.has(key) || all2HGoalMinsByMatchKey.has(key)) return null;

    const homeScore = parseInt(match?.homeScore ?? '0', 10) || 0;
    const awayScore = parseInt(match?.awayScore ?? '0', 10) || 0;
    if (homeScore + awayScore !== 1) return null;

    appendGoalState(key, '0-0', String(homeScore), String(awayScore), parsed.half, parsed.min);

    return {
        timestamp,
        league: match?.league || '',
        home_team: match?.homeTeam || '',
        away_team: match?.awayTeam || '',
        minute,
        score_before: '0-0',
        score_after: scoreStr,
        home_score: String(match?.homeScore ?? '0'),
        away_score: String(match?.awayScore ?? '0'),
    };
}

// -- Pengiriman goal-log dengan antrean offline ----------------------------------
// goal-log-save.php idempotent (row di-key per tanggal+jam+tim, goal di-dedup),
// jadi payload yang gagal terkirim aman dikirim ulang di siklus berikutnya.
const GOAL_LOG_SAVE_URL = 'http://localhost/lebihsabar/goal-log-save.php';
const GOAL_LOG_QUEUE_KEY = 'goalLogSendQueue';
const GOAL_LOG_QUEUE_MAX = 300;

async function postGoalLogPayload(body) {
    const resp = await fetch(GOAL_LOG_SAVE_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
    if (!resp.ok) {
        throw new Error('goal-log-save HTTP ' + resp.status);
    }
}

async function sendGoalLogPayload(body) {
    try {
        await postGoalLogPayload(body);
        return true;
    } catch (error) {
        try {
            const stored = await chrome.storage.local.get([GOAL_LOG_QUEUE_KEY]);
            const queue = Array.isArray(stored[GOAL_LOG_QUEUE_KEY]) ? stored[GOAL_LOG_QUEUE_KEY] : [];
            queue.push({ body, queuedAt: Date.now() });
            await chrome.storage.local.set({ [GOAL_LOG_QUEUE_KEY]: queue.slice(-GOAL_LOG_QUEUE_MAX) });
            await setStatus({ error: 'goal-log offline, ' + queue.length + ' payload diantrekan' });
        } catch (_) {}
        return false;
    }
}

async function flushGoalLogQueue() {
    try {
        const stored = await chrome.storage.local.get([GOAL_LOG_QUEUE_KEY]);
        const queue = Array.isArray(stored[GOAL_LOG_QUEUE_KEY]) ? stored[GOAL_LOG_QUEUE_KEY] : [];
        if (!queue.length) return;

        const remaining = [];
        for (const item of queue) {
            try {
                await postGoalLogPayload(item.body);
            } catch (_) {
                remaining.push(item);
            }
        }
        await chrome.storage.local.set({ [GOAL_LOG_QUEUE_KEY]: remaining });
        if (!remaining.length) {
            await setStatus({ error: '' });
        }
    } catch (_) {}
}

// -- Logging perubahan odds ke odds_log.csv ---------------------------------------
// Hanya target O/U (o0.75 + pembanding o1.0/o1.25) dan Next Goal; satu event per
// perubahan nilai. Endpoint append-only sehingga kiriman ulang dari antrean aman.
const ODD_LOG_SAVE_URL = 'http://localhost/lebihsabar/odd-log-save.php';
const ODD_LOG_QUEUE_KEY = 'oddLogSendQueue';
const ODD_LOG_QUEUE_MAX = 300;

async function postOddLogPayload(body) {
    const resp = await fetch(ODD_LOG_SAVE_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
    if (!resp.ok) {
        throw new Error('odd-log-save HTTP ' + resp.status);
    }
}

async function sendOddLogPayload(body) {
    try {
        await postOddLogPayload(body);
        return true;
    } catch (error) {
        try {
            const stored = await chrome.storage.local.get([ODD_LOG_QUEUE_KEY]);
            const queue = Array.isArray(stored[ODD_LOG_QUEUE_KEY]) ? stored[ODD_LOG_QUEUE_KEY] : [];
            queue.push({ body, queuedAt: Date.now() });
            await chrome.storage.local.set({ [ODD_LOG_QUEUE_KEY]: queue.slice(-ODD_LOG_QUEUE_MAX) });
        } catch (_) {}
        return false;
    }
}

async function flushOddLogQueue() {
    try {
        const stored = await chrome.storage.local.get([ODD_LOG_QUEUE_KEY]);
        const queue = Array.isArray(stored[ODD_LOG_QUEUE_KEY]) ? stored[ODD_LOG_QUEUE_KEY] : [];
        if (!queue.length) return;

        const remaining = [];
        for (const item of queue) {
            try {
                await postOddLogPayload(item.body);
            } catch (_) {
                remaining.push(item);
            }
        }
        await chrome.storage.local.set({ [ODD_LOG_QUEUE_KEY]: remaining });
    } catch (_) {}
}

// Kumpulkan nilai odds yang dipantau dari satu match: { 'ft:o0.75': 2.1, '1h:o1.0': 1.5, 'ng:home': 1.65, ... }
// Market FT O/U dan 1H O/U HARUS dipisah — filter generik 'o/u' mencampur keduanya
// sehingga deteksi perubahan membandingkan nilai lintas market (data jadi ngaco).
function collectTrackedOddValues(match) {
    const values = {};

    const ouSelections = [TARGET_ODD_SELECTION, ...COMPARISON_ODD_SELECTIONS];
    const marketPrefixes = [
        ['ft:', TARGET_FT_ODD_MARKET],   // "FT. O/U"
        ['1h:', '1h.o/u'],               // "1H. O/U"
    ];
    for (const [prefix, marketFilter] of marketPrefixes) {
        const ouMap = getOverOddsBySelection(match, ouSelections, marketFilter);
        for (const [selection, info] of Object.entries(ouMap)) {
            if (Number.isFinite(info?.oddValue)) {
                values[prefix + selection] = { value: info.oddValue, market: info.marketName || 'O/U' };
            }
        }
    }

    const ng = match?.nextGoalOdds;
    if (ng && typeof ng === 'object') {
        for (const side of ['home', 'away', 'none']) {
            const val = parseFloat(String(ng[side] ?? '').replace(',', '.'));
            if (Number.isFinite(val)) {
                values['ng:' + side] = { value: val, market: 'Next Goal' };
            }
        }
    }

    return values;
}

async function trackOddChanges(matches) {
    await flushOddLogQueue();

    const events = [];
    const nowIso = new Date().toISOString();

    for (const match of Array.isArray(matches) ? matches : []) {
        const key = createMatchKey(match);
        const current = collectTrackedOddValues(match);
        if (!Object.keys(current).length) continue;

        const previous = lastLoggedOddsByMatchKey.get(key) || {};
        for (const [selection, info] of Object.entries(current)) {
            const prevValue = previous[selection]?.value;
            if (prevValue === info.value) continue;
            events.push({
                timestamp: nowIso,
                league: match?.league || '',
                home_team: match?.homeTeam || '',
                away_team: match?.awayTeam || '',
                status: match?.status || '',
                score: match?.score || '',
                market: info.market,
                selection,
                odd_value: info.value,
                prev_value: Number.isFinite(prevValue) ? prevValue : ''
            });
        }
        lastLoggedOddsByMatchKey.set(key, current);
    }

    if (events.length) {
        await sendOddLogPayload({ odds: events });
    }
}

async function trackGoalEvents(matches) {
    await flushGoalLogQueue();
    if (!Array.isArray(matches) || !matches.length) return;

    const newGoals = [];
    const newMatches = [];
    const now = new Date();
    const timestamp = now.toISOString();
    const nowMs = now.getTime();

    for (const match of matches) {
        const key = createMatchKey(match);
        const homeScore = String(match?.homeScore ?? '0').trim();
        const awayScore = String(match?.awayScore ?? '0').trim();
        const scoreStr = `${homeScore}-${awayScore}`;
        const minute = String(match?.status || '').trim();
        const parsedMinute = parseMatchMinute(minute);
        const isTrackableLiveState = parsedMinute.half === '1H' || parsedMinute.half === '2H' || /^H\.?Time$/i.test(minute);

        if (shouldResetFixtureState(key, minute, scoreStr, nowMs)) {
            resetMatchTrackingState(key);
        }

        const isNewRound = isKickoffMinute(minute) && scoreStr === '0-0' &&
            registeredMatchKeys.has(key) &&
            (lastScoreByMatchKey.get(key) || '0-0') !== '0-0';
        if (isKickoffMinute(minute) && (!registeredMatchKeys.has(key) || isNewRound)) {
            if (isNewRound) {
                resetMatchTrackingState(key);
            }
            registerMatchIfNeeded(key, match, timestamp, newMatches);

            const kickoffBackfill = buildVisibleGoalBackfill(key, match, minute, scoreStr, kickoffTimeByMatchKey.get(key) || timestamp);
            if (kickoffBackfill) {
                newGoals.push(kickoffBackfill);
            }

            lastScoreByMatchKey.set(key, scoreStr);
            lastSeenAtByMatchKey.set(key, nowMs);
            lastStatusByMatchKey.set(key, minute);
            continue;
        }

        if (!registeredMatchKeys.has(key) && isTrackableLiveState) {
            registerMatchIfNeeded(key, match, timestamp, newMatches);
        }

        const isHalftime = /^H\.?Time$/i.test(minute);
        if ((isHalftime || isSecondHalfStart(minute)) && !shScoreByMatchKey.has(key)) {
            shScoreByMatchKey.set(key, scoreStr);
        }
        if (isKickoffMinute(minute) && shScoreByMatchKey.has(key)) {
            shScoreByMatchKey.delete(key);
        }

        if (!registeredMatchKeys.has(key)) continue;

        const prev = lastScoreByMatchKey.get(key);
        if (prev === undefined) {
            const inferredGoal = buildVisibleGoalBackfill(key, match, minute, scoreStr, kickoffTimeByMatchKey.get(key) || timestamp);
            if (inferredGoal) {
                newGoals.push(inferredGoal);
            }
            lastScoreByMatchKey.set(key, scoreStr);
            lastSeenAtByMatchKey.set(key, nowMs);
            lastStatusByMatchKey.set(key, minute);
            continue;
        }

        if (prev !== scoreStr) {
            const { half: gHalf, min: gMin } = parseMatchMinute(minute);
            appendGoalState(key, prev, homeScore, awayScore, gHalf, gMin);

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

        lastSeenAtByMatchKey.set(key, nowMs);
        lastStatusByMatchKey.set(key, minute);
    }

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
                        home_score: String(match?.homeScore ?? '0'),
                        away_score: String(match?.awayScore ?? '0'),
                    });
                }
            }
        }
    }

    const scoreSnapshots = [];
    const oddsSnapshots = [];
    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        const ts = kickoffTimeByMatchKey.get(key) || timestamp;
        scoreSnapshots.push(buildScoreSnapshot(match, ts));

        // Market kickoff: dicoba tiap siklus sampai berhasil, karena pada
        // pembacaan pertama market kadang belum ter-render.
        const ko = buildOddsSnapshot(key, match, ts, 'ko');
        if (ko) oddsSnapshots.push(ko);

        if (isHalfTimeStatus(match?.status)) {
            const ht = buildOddsSnapshot(key, match, ts, 'ht');
            if (ht) oddsSnapshots.push(ht);
        }
    }

    const matchPayload = [...newMatches, ...scoreSnapshots];
    if (matchPayload.length) {
        await sendGoalLogPayload({ matches: matchPayload });
    }

    if (oddsSnapshots.length) {
        await sendGoalLogPayload({ oddsSnapshots });
    }

    if (newMilestones.length) {
        await sendGoalLogPayload({ milestones: newMilestones });
    }

    await chrome.storage.local.set({
        liveHtScores: Object.fromEntries(shScoreByMatchKey)
    });

    if (newGoals.length) {
        const stored = await chrome.storage.local.get(['goalLog']);
        const existing = Array.isArray(stored.goalLog) ? stored.goalLog : [];
        const updated = [...existing, ...newGoals].slice(-500);
        await chrome.storage.local.set({ goalLog: updated });
    }

    const syncGoals = [];
    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        const trackedEvents = buildTrackedGoalEventsForMatch(key, match, kickoffTimeByMatchKey.get(key) || timestamp);
        const trackedFinal = trackedEvents.length ? trackedEvents[trackedEvents.length - 1].score_after : null;
        if (trackedEvents.length && trackedFinal === `${String(match?.homeScore ?? '0').trim()}-${String(match?.awayScore ?? '0').trim()}`) {
            syncGoals.push(...trackedEvents);
        }
    }

    const goalsToPersist = syncGoals.length ? syncGoals : newGoals;
    if (!goalsToPersist.length) return;

    await sendGoalLogPayload({ goals: goalsToPersist });
}

function getPatternTelegramOdd(match) {
    return getQualifiedOddsAlert(match, TARGET_ODD_SELECTION, TARGET_ODD_MIN);
}

function isPatternNotificationWindow(status) {
    if (/^H\.?Time$/i.test(String(status || '').trim())) {
        return true;
    }

    const parsed = parseMatchMinute(status);
    return parsed.half === '2H' && parsed.min >= 0;
}

let p727374SignatureCache = null;
let p727374SignatureFetchedAt = 0;

function getLivePatternLeague(match) {
    const text = String(match?.league || '');
    if (/15/.test(text)) return '15min';
    if (/16/.test(text)) return '16min';
    if (/20/.test(text)) return '20min';
    return text.trim();
}

function getMinGapFromMins(mins) {
    if (!Array.isArray(mins) || mins.length < 2) return 0;
    let minGap = Number.POSITIVE_INFINITY;
    for (let i = 1; i < mins.length; i += 1) {
        minGap = Math.min(minGap, mins[i] - mins[i - 1]);
    }
    return Number.isFinite(minGap) ? minGap : 0;
}

function getMaxGapFromMins(mins) {
    if (!Array.isArray(mins) || mins.length < 2) return 0;
    let maxGap = 0;
    for (let i = 1; i < mins.length; i += 1) {
        maxGap = Math.max(maxGap, mins[i] - mins[i - 1]);
    }
    return maxGap;
}

function countSwitchesFromScorers(scorers) {
    if (!Array.isArray(scorers) || scorers.length < 2) return 0;
    let switches = 0;
    for (let i = 1; i < scorers.length; i += 1) {
        if (scorers[i] !== scorers[i - 1]) switches += 1;
    }
    return switches;
}

function maxRunFromScorers(scorers) {
    if (!Array.isArray(scorers) || !scorers.length) return 0;
    let longest = 1;
    let current = 1;
    for (let i = 1; i < scorers.length; i += 1) {
        current = scorers[i] === scorers[i - 1] ? current + 1 : 1;
        longest = Math.max(longest, current);
    }
    return longest;
}

function buildP727374State(match) {
    const key = createMatchKey(match);
    const status = String(match?.status || '').trim();
    if (!isPatternNotificationWindow(status)) return null;
    if (has2HGoalByMatchKey.get(key)) return null;

    const mins = all1HGoalMinsByMatchKey.get(key) || [];
    const scorers = all1HScorersByMatchKey.get(key) || [];
    if (!mins.length || mins.length !== scorers.length) return null;

    const currentScore = parseScoreTuple(match?.score || '0-0');
    const htScoreText = shScoreByMatchKey.get(key) || '';
    const score = parseScoreTuple(htScoreText || match?.score || '0-0');
    const parsedStatus = parseMatchMinute(status);
    if (parsedStatus.half === '2H' && htScoreText && (currentScore.home !== score.home || currentScore.away !== score.away)) {
        return null;
    }

    const league = getLivePatternLeague(match);
    const first = mins[0];
    const last = mins[mins.length - 1];
    const sequence = scorers.join('');
    const switches = countSwitchesFromScorers(scorers);
    const minGap = getMinGapFromMins(mins);
    const maxGap = getMaxGapFromMins(mins);
    const kickoffRaw = kickoffTimeByMatchKey.get(key) || '';
    const kickoffDate = kickoffRaw ? new Date(kickoffRaw) : null;
    const hasKickoffDate = kickoffDate && !Number.isNaN(kickoffDate.getTime());
    const signature = `${league}|${first}|${last}|${sequence}|${score.home}-${score.away}`;
    const shape = `${league}|${mins.length}|${first}|${last}|${score.home}-${score.away}|${switches}|${minGap}|${maxGap}`;
    const state = {
        home: match?.homeTeam || '',
        away: match?.awayTeam || '',
        datetime: kickoffRaw,
        league,
        h1c: mins.length,
        sc_h: score.home,
        sc_a: score.away,
        h1_first: first,
        h1_last: last,
        h1s: scorers.slice(),
        switches,
        max_gap: maxGap,
        min_gap: minGap,
        max_run: maxRunFromScorers(scorers),
        all_gaps_ge3: mins.length < 2 || mins.every((min, idx) => idx === 0 || min - mins[idx - 1] >= 3),
        kickoff_hour: hasKickoffDate ? kickoffDate.getHours() : -1,
        kickoff_minute: hasKickoffDate ? kickoffDate.getMinutes() : -1,
        kickoff_dow: hasKickoffDate ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][kickoffDate.getDay()] : '',
        kickoff_dow_num: hasKickoffDate ? kickoffDate.getDay() : -1,
        goal_mins: mins.slice()
    };

    return { key, status, score, league, mins, sequence, signature, shape, state };
}

async function getP727374SignatureConfig() {
    const now = Date.now();
    if (p727374SignatureCache && now - p727374SignatureFetchedAt < 60000) {
        return p727374SignatureCache;
    }

    const response = await fetch('http://localhost/lebihsabar/pattern-live-signatures.php', { cache: 'no-store' });
    if (!response.ok) throw new Error(`pattern signatures HTTP ${response.status}`);
    const payload = await response.json();
    const patterns = payload?.patterns || {};
    p727374SignatureCache = {
        P72: new Set(Array.isArray(patterns.P72) ? patterns.P72 : []),
    };
    p727374SignatureFetchedAt = now;
    return p727374SignatureCache;
}

async function trackP727374GoalSignal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    let config;
    try {
        config = await getP727374SignatureConfig();
    } catch (error) {
        await setStatus({ error: `P72 signatures failed: ${error.message || error}` });
        return;
    }

    for (const match of matches) {
        const state = buildP727374State(match);
        if (!state) continue;

        const matched = [];
        if (config.P72.has(state.signature)) matched.push('P72');
        if (!matched.length) continue;

        const signalKey = `${state.key}|${matched.join('+')}|${state.signature}|${state.shape}`;
        p727374SignalsByMatchKey.set(state.key, {
            ids: matched.slice(),
            signature: state.signature,
            shape: state.shape,
            status: state.status,
            score: `${state.score.home}-${state.score.away}`,
            mins: state.mins.slice(),
            sequence: state.sequence,
            state: state.state,
            seenAt: Date.now()
        });
        if (sentP727374Signals.has(signalKey)) continue;

        sentP727374Signals.add(signalKey);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const minuteText = state.mins.map((min) => `${min}'`).join(', ');
        const msg =
            `[ALERT] <b>${escapeHtml(matched.join(' + '))} SIGNAL - AKAN ADA GOAL 2H</b>\n` +
            `Goal <b>${home} vs ${away}</b>\n` +
            `[LEAGUE] League: ${escapeHtml(match?.league || state.league || '?')}\n` +
            `[SCORE] HT/Current: <b>${state.score.home}-${state.score.away}</b>\n` +
            `[TIME] Status: <b>${escapeHtml(state.status)}</b>\n` +
            `[NOTE] Final 1H signature cocok sebelum gol 2H. Target: <b>akan ada goal babak kedua</b>.\n` +
            `Signature: <code>${escapeHtml(state.signature)}</code>\n` +
            `Gol 1H: <b>${escapeHtml(minuteText)}</b> | Sequence: <b>${escapeHtml(state.sequence)}</b>`;

        await sendTelegramText(msg);
    }
}

// -- Signal: skor masih 1-0 / 0-1 di menit >= 4 babak kedua --------------------
// Satu notif Telegram per match. Isi ONE_GOAL_2H_TEAMS untuk membatasi ke tim
// tertentu saja (cocokkan sebagian nama, case-insensitive); kosong = semua match.
const ONE_GOAL_2H_MIN_MINUTE = 4;
const ONE_GOAL_2H_TEAMS = [
    'Algeria (V)',
    'England (V)',
    'Norway (V)',
    'Portugal (V)',
    'Liverpool (V)',
    'Korea Republic (V)',
    'Brazil (V)',
    'Argentina (V)',
    'Australia (V)',
    'Indonesia (V)',
];

function matchesOneGoal2HTeamFilter(match) {
    if (!ONE_GOAL_2H_TEAMS.length) return true;
    const home = String(match?.homeTeam || '').toLowerCase();
    const away = String(match?.awayTeam || '').toLowerCase();
    return ONE_GOAL_2H_TEAMS.some((team) => {
        const t = String(team).toLowerCase();
        return home.includes(t) || away.includes(t);
    });
}

async function trackOneGoal2HSignal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        if (sentOneGoal2HSignals.has(key)) continue;
        if (!matchesOneGoal2HTeamFilter(match)) continue;

        const { half, min } = parseMatchMinute(String(match?.status || ''));
        if (half !== '2H' || min < ONE_GOAL_2H_MIN_MINUTE) continue;

        const homeScore = parseInt(match?.homeScore ?? '0', 10);
        const awayScore = parseInt(match?.awayScore ?? '0', 10);
        if (homeScore + awayScore !== 1) continue;

        // Syarat: gol tunggal HARUS tercipta di babak pertama (skor HT = 1-0 / 0-1)
        // dan belum berubah sampai 2H. Tanpa skor HT tersimpan, lewati (tidak bisa
        // dipastikan golnya dari babak pertama, mis. extension mulai di tengah laga).
        const htScoreText = shScoreByMatchKey.get(key);
        if (!htScoreText) continue;
        const ht = parseScoreTuple(htScoreText);
        if (ht.home + ht.away !== 1) continue;
        if (homeScore !== ht.home || awayScore !== ht.away) continue;

        sentOneGoal2HSignals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const msg =
            `[ALERT] <b>MASIH ${homeScore}-${awayScore} DI 2H ${min}'</b>\n` +
            `<b>${home} vs ${away}</b>\n` +
            `[LEAGUE] ${escapeHtml(match?.league || '?')}\n` +
            `[SCORE] <b>${homeScore}-${awayScore}</b> (gol di babak 1, HT ${ht.home}-${ht.away}) | [TIME] <b>2H ${min}'</b>\n` +
            `[NOTE] Sinyal melemah seiring menit berjalan (2H 3': ~50% peluang gol tersisa, ` +
            `2H 5': ~32%) - bertindak cepat atau abaikan notif yang datang terlambat.`;

        await sendTelegramText(msg);
    }
}

// -- Signal: skor halftime cocok untuk team di daftar target --------------------
// Per-team: rule.halftimeScores (mis ['0-0','1-0']). Saat match team itu mencapai
// HT dengan skor yang cocok -> notif Telegram, satu per match.
async function trackTeamHalftimeSignal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    const watchConfig = await getCustomWatchConfig();
    const rules = Array.isArray(watchConfig.teamRules) ? watchConfig.teamRules : [];
    if (!rules.length) return;

    for (const match of matches) {
        const key = createMatchKey(match);
        if (sentTeamHtSignals.has(key)) continue;

        const rule = getWatchRuleByMatch(match, rules);
        if (!rule) continue;
        const wantScores = Array.isArray(rule.halftimeScores) ? rule.halftimeScores : [];
        if (!wantScores.length) continue;

        // Tentukan skor HT: pakai yang tersimpan, atau skor saat ini bila status H.Time.
        const status = String(match?.status || '').trim();
        const isHt = /^H\.?Time$/i.test(status);
        const parsed = parseMatchMinute(status);
        let htScoreText = shScoreByMatchKey.get(key) || '';
        if (!htScoreText && isHt) {
            htScoreText = `${parseInt(match?.homeScore ?? '0', 10)}-${parseInt(match?.awayScore ?? '0', 10)}`;
        }
        if (!htScoreText) continue;
        // Hanya picu di HT atau awal babak kedua (HT baru saja lewat).
        if (!isHt && !(parsed.half === '2H' && parsed.min <= 1)) continue;

        const htNorm = htScoreText.replace(/\s+/g, '');
        const m = htNorm.match(/^(\d+)-(\d+)$/);
        const canonical = m ? `${parseInt(m[1], 10)}-${parseInt(m[2], 10)}` : htNorm;
        if (!wantScores.includes(canonical)) continue;

        sentTeamHtSignals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const msg =
            `[ALERT] <b>HALFTIME ${escapeHtml(canonical)}</b>\n` +
            `<b>${home} vs ${away}</b>\n` +
            `[LEAGUE] ${escapeHtml(match?.league || '?')}\n` +
            `[SCORE HT] <b>${escapeHtml(canonical)}</b> | [TARGET] <b>${escapeHtml(rule.team)}</b>\n` +
            `[NOTE] Skor halftime cocok dengan rule team target Anda.`;

        await sendTelegramText(msg);
    }
}

async function trackNG1Signal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        if (sentNG1Signals.has(key)) continue;

        const status = String(match?.status || '').trim();
        if (!isPatternNotificationWindow(status)) continue;
        if (has2HGoalByMatchKey.get(key)) continue;

        const homeScore = parseInt(match?.homeScore ?? '0', 10);
        const awayScore = parseInt(match?.awayScore ?? '0', 10);

        if (homeScore !== 1 || awayScore !== 0) continue;

        const last1HMin = last1HGoalMinByMatchKey.get(key) ?? -1;
        if (last1HMin !== 3) continue;

        const targetOdd = getPatternTelegramOdd(match);
        if (!targetOdd) continue;

        sentNG1Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');

        const msg =
            `[TARGET] <b>NG1 SIGNAL - BABAK 2 MULAI!</b>\n` +
            `Goal <b>${home} vs ${away}</b>\n` +
            `[SCORE] Skor HT: <b>1-0</b> | Gol terakhir 1H: menit <b>3'</b>\n` +
            `[TIME] Status: <b>${escapeHtml(status)}</b>\n\n` +
            `[TARGET] Market: <b>${escapeHtml(targetOdd.marketName)}: ${escapeHtml(targetOdd.label)} @ ${escapeHtml(targetOdd.oddValue.toFixed(2))}</b>\n\n` +
            `[TREND] Pola NG1: HT 1-0 + gol mnt 3 -> <b>HOME 83%</b>\n` +
            `[HOT] <i>Notif dikirim saat FT O/U O0.5 sudah > 1.65.</i>`;

        await sendTelegramText(msg);
    }
}

async function trackHT22Signal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        if (sentHT22Signals.has(key)) continue;

        const status = String(match?.status || '').trim();
        if (!isPatternNotificationWindow(status)) continue;
        if (has2HGoalByMatchKey.get(key)) continue;

        const homeScore = parseInt(match?.homeScore ?? '0', 10);
        const awayScore = parseInt(match?.awayScore ?? '0', 10);

        const htScore = shScoreByMatchKey.get(key);
        if (htScore !== '2-2') continue;

        const goalMins = all1HGoalMinsByMatchKey.get(key) || [];
        if (goalMins.length < 2) continue;

        let maxGap = 0;
        for (let i = 1; i < goalMins.length; i += 1) {
            maxGap = Math.max(maxGap, goalMins[i] - goalMins[i - 1]);
        }
        if (maxGap > 2) continue;

        const targetOdd = getPatternTelegramOdd(match);
        if (!targetOdd) continue;

        sentHT22Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const league = escapeHtml(match?.league || '?');
        const currentScore = `${homeScore}-${awayScore}`;
        const minuteText = goalMins.map((min) => `${min}'`).join(', ');

        const msg =
            `[ALERT] <b>P15 SIGNAL - HT 2-2 + MAX GAP <= 2</b>\n` +
            `Goal <b>${home} vs ${away}</b>\n` +
            `[LEAGUE] League: ${league}\n` +
            `[SCORE] Skor HT: <b>2-2</b> | Skor sekarang: <b>${currentScore}</b>\n` +
            `[TIME] Status: <b>${escapeHtml(status)}</b>\n` +
            `[TARGET] Market: <b>${escapeHtml(targetOdd.marketName)}: ${escapeHtml(targetOdd.label)} @ ${escapeHtml(targetOdd.oddValue.toFixed(2))}</b>\n` +
            `[NOTE] Gol 1H: <b>${escapeHtml(minuteText)}</b> | Max gap: <b>${maxGap}</b> menit\n\n` +
            `[HOT] <i>P15 lolos dan FT O/U O0.5 sudah > 1.65.</i>`;

        await sendTelegramText(msg);
    }
}

async function trackP14Signal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        if (sentP14Signals.has(key)) continue;

        const status = String(match?.status || '').trim();
        if (!isPatternNotificationWindow(status)) continue;
        if (has2HGoalByMatchKey.get(key)) continue;

        const homeScore = parseInt(match?.homeScore ?? '0', 10);
        const awayScore = parseInt(match?.awayScore ?? '0', 10);
        if (homeScore !== awayScore || homeScore === 0) continue;

        const goalMins = all1HGoalMinsByMatchKey.get(key) || [];
        if (goalMins.length < 2) continue;

        const firstGoalMin = goalMins[0];
        const lastGoalMin = goalMins[goalMins.length - 1];
        const span = lastGoalMin - firstGoalMin;
        let maxGap = 0;
        let minGap = Number.POSITIVE_INFINITY;
        for (let i = 1; i < goalMins.length; i += 1) {
            const gap = goalMins[i] - goalMins[i - 1];
            maxGap = Math.max(maxGap, gap);
            minGap = Math.min(minGap, gap);
        }

        if (!Number.isFinite(minGap)) minGap = 0;

        if (firstGoalMin === 1 || span < 5 || maxGap < 4 || minGap < 2) continue;

        const targetOdd = getPatternTelegramOdd(match);
        if (!targetOdd) continue;

        sentP14Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const league = escapeHtml(match?.league || '?');
        const score = `${homeScore}-${awayScore}`;
        const minuteText = goalMins.map((min) => `${min}'`).join(', ');

        const msg =
            `[ALERT] <b>P14 SIGNAL - POTENSI GOL BABAK 2</b>\n` +
            `Goal <b>${home} vs ${away}</b>\n` +
            `[LEAGUE] League: ${league}\n` +
            `[SCORE] Skor: <b>${score}</b>\n` +
            `[TIME] Status: <b>${escapeHtml(status)}</b>\n\n` +
            `[TARGET] Market: <b>${escapeHtml(targetOdd.marketName)}: ${escapeHtml(targetOdd.label)} @ ${escapeHtml(targetOdd.oddValue.toFixed(2))}</b>\n\n` +
            `[NOTE] Pola P14 terpenuhi:\n` +
            `- Seri di babak pertama\n` +
            `- Gap gol max <b>${maxGap}</b> menit\n` +
            `- Gap gol min <b>${minGap}</b> menit\n` +
            `- Span gol <b>${span}</b> menit\n` +
            `- First goal menit <b>${firstGoalMin}'</b>\n` +
            `- Urutan menit gol: <b>${escapeHtml(minuteText)}</b>\n\n` +
            `[HOT] <i>P14 lolos dan FT O/U O0.5 sudah > 1.65.</i>`;

        await sendTelegramText(msg);
    }
}

async function trackP7Signal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        if (sentP7Signals.has(key)) continue;

        const status = String(match?.status || '').trim();
        if (!isPatternNotificationWindow(status)) continue;
        if (has2HGoalByMatchKey.get(key)) continue;

        const homeScore = parseInt(match?.homeScore ?? '0', 10);
        const awayScore = parseInt(match?.awayScore ?? '0', 10);
        if (homeScore !== 1 || awayScore !== 1) continue;

        const goalMins = all1HGoalMinsByMatchKey.get(key) || [];
        if (goalMins.length !== 2) continue;

        const firstGoalMin = goalMins[0];
        const secondGoalMin = goalMins[1];
        const maxGap = secondGoalMin - firstGoalMin;
        if (firstGoalMin === 1 || maxGap < 5) continue;

        const targetOdd = getPatternTelegramOdd(match);
        if (!targetOdd) continue;

        sentP7Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const league = escapeHtml(match?.league || '?');
        const minuteText = goalMins.map((min) => `${min}'`).join(', ');

        const msg =
            `[ALERT] <b>P7 SIGNAL - POTENSI GOL BABAK 2</b>\n` +
            `Goal <b>${home} vs ${away}</b>\n` +
            `[LEAGUE] League: ${league}\n` +
            `[SCORE] Skor: <b>1-1</b>\n` +
            `[TIME] Status: <b>${escapeHtml(status)}</b>\n\n` +
            `[TARGET] Market: <b>${escapeHtml(targetOdd.marketName)}: ${escapeHtml(targetOdd.label)} @ ${escapeHtml(targetOdd.oddValue.toFixed(2))}</b>\n\n` +
            `[NOTE] Pola P7 terpenuhi:\n` +
            `- Seri <b>1-1</b>\n` +
            `- Gap antar gol >= <b>5</b> menit (aktual <b>${maxGap}</b>)\n` +
            `- First goal != menit <b>1</b> (aktual <b>${firstGoalMin}'</b>)\n` +
            `- Urutan menit gol 1H: <b>${escapeHtml(minuteText)}</b>\n\n` +
            `[HOT] <i>P7 lolos dan FT O/U O0.5 sudah > 1.65.</i>`;

        await sendTelegramText(msg);
    }
}

async function trackP19Signal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        if (sentP19Signals.has(key)) continue;

        const status = String(match?.status || '').trim();
        if (!isPatternNotificationWindow(status)) continue;
        if (has2HGoalByMatchKey.get(key)) continue;
        if (!/20\s+Mins/i.test(String(match?.league || ''))) continue;

        const homeScore = parseInt(match?.homeScore ?? '0', 10);
        const awayScore = parseInt(match?.awayScore ?? '0', 10);
        const goalMins = all1HGoalMinsByMatchKey.get(key) || [];
        const scorers = all1HScorersByMatchKey.get(key) || [];

        if (!goalMins.length || !scorers.length) continue;

        const lastGoalMin = goalMins[goalMins.length - 1];
        const lastScorer = scorers[scorers.length - 1];
        if (![3, 4].includes(lastGoalMin) || lastScorer !== 'H') continue;

        if (goalMins.length < 2) continue;

        let maxGap = 0;
        for (let i = 1; i < goalMins.length; i += 1) {
            maxGap = Math.max(maxGap, goalMins[i] - goalMins[i - 1]);
        }
        if (maxGap < 2) continue;

        const targetOdd = getPatternTelegramOdd(match);
        if (!targetOdd) continue;

        sentP19Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const league = escapeHtml(match?.league || '?');
        const score = `${homeScore}-${awayScore}`;
        const minuteText = goalMins.map((min) => `${min}'`).join(', ');
        const gapText = `Gap max <b>${maxGap}</b> menit`;

        const msg =
            `[ALERT] <b>P19 SIGNAL - POTENSI GOL BABAK 2</b>\n` +
            `Goal <b>${home} vs ${away}</b>\n` +
            `[LEAGUE] League: ${league}\n` +
            `[SCORE] Skor: <b>${score}</b>\n` +
            `[TIME] Status: <b>${escapeHtml(status)}</b>\n\n` +
            `[TARGET] Market: <b>${escapeHtml(targetOdd.marketName)}: ${escapeHtml(targetOdd.label)} @ ${escapeHtml(targetOdd.oddValue.toFixed(2))}</b>\n\n` +
            `[NOTE] Pola P19 terpenuhi:\n` +
            `- Last goal 1H menit <b>${lastGoalMin}'</b>\n` +
            `- Last scorer <b>HOME</b>\n` +
            `- ${gapText}\n` +
            `- Urutan menit gol 1H: <b>${escapeHtml(minuteText)}</b>\n\n` +
            `[HOT] <i>P19 lolos dan FT O/U O0.5 sudah > 1.65.</i>`;

        await sendTelegramText(msg);
    }
}

async function trackP28Signal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        if (sentP28Signals.has(key)) continue;

        const status = String(match?.status || '').trim();
        if (!isPatternNotificationWindow(status)) continue;
        if (has2HGoalByMatchKey.get(key)) continue;

        const homeTeam = normalizeTeamName(match?.homeTeam || '');
        const awayTeam = normalizeTeamName(match?.awayTeam || '');
        const isTargetTeam = ['croatia', 'france'].some((team) => homeTeam.includes(team) || awayTeam.includes(team));
        if (!isTargetTeam) continue;

        const homeScore = parseInt(match?.homeScore ?? '0', 10);
        const awayScore = parseInt(match?.awayScore ?? '0', 10);
        const goalMins = all1HGoalMinsByMatchKey.get(key) || [];
        const scorers = all1HScorersByMatchKey.get(key) || [];
        if (!goalMins.length || !scorers.length) continue;

        const firstGoalMin = goalMins[0];
        const lastGoalMin = goalMins[goalMins.length - 1];
        const span = lastGoalMin - firstGoalMin;
        let switches = 0;
        for (let i = 1; i < scorers.length; i += 1) {
            if (scorers[i] !== scorers[i - 1]) switches += 1;
        }

        if (lastGoalMin < 3 || span < 3 || switches < 1) continue;

        const targetOdd = getPatternTelegramOdd(match);
        if (!targetOdd) continue;

        sentP28Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const league = escapeHtml(match?.league || '?');
        const score = `${homeScore}-${awayScore}`;
        const minuteText = goalMins.map((min) => `${min}'`).join(', ');

        const msg =
            `[ALERT] <b>P28 SIGNAL - POTENSI GOL BABAK 2</b>\n` +
            `Goal <b>${home} vs ${away}</b>\n` +
            `[LEAGUE] League: ${league}\n` +
            `[SCORE] Skor: <b>${score}</b>\n` +
            `[TIME] Status: <b>${escapeHtml(status)}</b>\n\n` +
            `[TARGET] Market: <b>${escapeHtml(targetOdd.marketName)}: ${escapeHtml(targetOdd.label)} @ ${escapeHtml(targetOdd.oddValue.toFixed(2))}</b>\n\n` +
            `[NOTE] Pola P28 terpenuhi:\n` +
            `- Croatia atau France bermain\n` +
            `- Last goal 1H menit <b>${lastGoalMin}'</b>\n` +
            `- Span gol <b>${span}</b> menit\n` +
            `- Ada balas gol 1H <b>${switches}x</b>\n` +
            `- Urutan menit gol 1H: <b>${escapeHtml(minuteText)}</b>\n\n` +
            `[HOT] <i>P28 lolos dan FT O/U O0.5 sudah > 1.65.</i>`;

        await sendTelegramText(msg);
    }
}
