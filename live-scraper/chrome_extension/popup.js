let currentTab = null;
let currentViewFilter = 'all';
let popupDataCache = {
    groupedMatches: null,
    oddInsights: null,
    goalMinutes: null,
    allGoalMinutes: null,
    all2HGoalMinutes: null,
    htScores: null,
    count: null
};
const DEFAULT_CUSTOM_WATCH_THRESHOLD = 1.65;
const DEFAULT_CUSTOM_WATCH_MARKET = '0.5';
let customWatchConfig = {
    teamRules: [],
    matchRules: [],
    customOddThreshold: DEFAULT_CUSTOM_WATCH_THRESHOLD,
    customOddSelection: `o${DEFAULT_CUSTOM_WATCH_MARKET}`
};

const WATCH_CONFIG_ACTIONS = {
    get: 'getCustomWatchConfig',
    set: 'setCustomWatchConfig'
};

function isExtensionContextValid() {
    try {
        chrome.runtime.getManifest();
        return true;
    } catch (error) {
        return false;
    }
}

async function refreshCurrentTab() {
    const tabs = await chrome.tabs.query({ active: true, currentWindow: true });
    currentTab = tabs[0] || null;
    return currentTab;
}

async function requestBackground(action) {
    return chrome.runtime.sendMessage({ action });
}

async function requestBackgroundWithPayload(action, payload = {}) {
    return chrome.runtime.sendMessage({ action, payload });
}

function isHtModeOn() {
    return Boolean(document.getElementById('htModeToggle')?.checked);
}

// Aktif/nonaktifkan input market & odd sesuai mode HT.
function applyHtModeUI() {
    const htOn = isHtModeOn();
    const marketInput = document.getElementById('customMarketInput');
    const oddInput = document.getElementById('customOddInput');
    [marketInput, oddInput].forEach((el) => {
        if (!el) return;
        el.disabled = htOn;
        el.style.opacity = htOn ? '0.5' : '';
        if (htOn) el.value = '';
    });
}

function getTeamRuleInputs() {
    const htOnly = isHtModeOn();

    // Skor HT (selalu dibaca; wajib bila mode HT).
    const htInput = document.getElementById('customHtScoreInput');
    const rawHt = String(htInput?.value || '').trim();
    const halftimeScores = [];
    if (rawHt) {
        for (const part of rawHt.split(',')) {
            const token = part.replace(/\s+/g, '');
            if (!token) continue;
            const m = token.match(/^(\d+)-(\d+)$/);
            if (!m) {
                showError('Skor HT harus format angka-angka seperti 0-0, 1-0');
                return null;
            }
            const norm = `${parseInt(m[1], 10)}-${parseInt(m[2], 10)}`;
            if (!halftimeScores.includes(norm)) halftimeScores.push(norm);
        }
    }

    if (htOnly) {
        if (!halftimeScores.length) {
            showError('Mode Market HT: isi minimal satu skor HT (mis 0-0).');
            return null;
        }
        // Odd dimatikan: simpan default agar struktur konsisten, tapi tandai htOnly.
        return {
            customOddSelection: `o${DEFAULT_CUSTOM_WATCH_MARKET}`,
            customOddThreshold: customWatchConfig.customOddThreshold,
            halftimeScores,
            htOnly: true
        };
    }

    const marketInput = document.getElementById('customMarketInput');
    const oddInput = document.getElementById('customOddInput');

    const rawMarket = String(marketInput?.value || '').trim();
    const marketValue = rawMarket.replace(/\s+/g, '').toLowerCase();

    if (rawMarket && !/^o?\d+(?:[.,]\d+)?$/.test(marketValue)) {
        showError('Market should be a positive number like 0.5, 0.75, 1.0');
        return null;
    }

    const normalizedMarket = marketValue
        ? (marketValue.startsWith('o') ? marketValue : `o${marketValue}`)
        : `o${DEFAULT_CUSTOM_WATCH_MARKET}`;

    const nextThreshold = toThresholdNumber(oddInput?.value, customWatchConfig.customOddThreshold);

    return {
        customOddSelection: normalizedMarket,
        customOddThreshold: nextThreshold,
        halftimeScores,
        htOnly: false
    };
}

function getMatchWatchRuleInPopup(match) {
    const teams = [
        match?.homeTeam,
        match?.awayTeam
    ].map(normalizeTeamName);

    const rules = Array.isArray(customWatchConfig.teamRules) ? customWatchConfig.teamRules : [];
    for (const rule of rules) {
        const watchTeam = normalizeTeamName(rule?.team);
        if (!watchTeam) {
            continue;
        }

        const matched = teams.some((teamName) => teamName.includes(watchTeam) || watchTeam.includes(teamName));
        if (matched) {
            return {
                team: watchTeam,
                customOddThreshold: Number.isFinite(rule?.customOddThreshold)
                    ? rule.customOddThreshold
                    : customWatchConfig.customOddThreshold,
                customOddSelection: rule?.customOddSelection || customWatchConfig.customOddSelection
            };
        }
    }

    return null;
}

function getMatchWatchContextInPopup(match) {
    const rule = getMatchWatchRuleInPopup(match);

    return {
        isWatchedTeam: Boolean(rule),
        appliedBy: rule?.team || null,
        appliedThreshold: Number.isFinite(rule?.customOddThreshold)
            ? rule.customOddThreshold
            : customWatchConfig.customOddThreshold,
        appliedSelection: rule?.customOddSelection || customWatchConfig.customOddSelection
    };
}



function formatDelta(value) {
    if (!Number.isFinite(value) || value === 0) {
        return '-';
    }

    const sign = value > 0 ? '+' : '';
    return `${sign}${value.toFixed(2)}`;
}

function formatOdd(value) {
    return Number.isFinite(value) ? value.toFixed(2) : '-';
}

function formatDuration(ms) {
    if (!Number.isFinite(ms) || ms <= 0) {
        return '-';
    }

    const seconds = Math.floor(ms / 1000);
    if (seconds < 60) {
        return `${seconds}s`;
    }

    const minutes = Math.floor(seconds / 60);
    const remainSeconds = seconds % 60;
    return remainSeconds > 0 ? `${minutes}m ${remainSeconds}s` : `${minutes}m`;
}

function formatComparisonOdds(comparisonOdds = {}) {
    const entries = Object.values(comparisonOdds || {}).filter((entry) => Number.isFinite(entry?.oddValue));
    if (!entries.length) {
        return '-';
    }

    return entries.map((entry) => `${entry.label} ${formatOdd(entry.oddValue)}`).join(' | ');
}

function getPatternBadgeClass(insight) {
    switch (insight?.severity) {
        case 'danger':
            return 'pattern-badge pattern-danger';
        case 'warning':
            return 'pattern-badge pattern-warning';
        default:
            return 'pattern-badge pattern-neutral';
    }
}

function getPatternIcon(insight) {
    if ((insight?.pattern || '').startsWith('Cross >')) {
        const match = /Cross\s*>\s*([0-9]+(?:[.,][0-9]+)?)/.exec(String(insight.pattern));
        return match?.[1] ? String(match[1]).replace(',', '.') : DEFAULT_CUSTOM_WATCH_THRESHOLD.toFixed(2);
    }

    switch (insight?.pattern) {
        case 'Breakout':
            return '!!';
        case 'Fake Breakout':
            return 'x!';
        case 'Spike Up':
            return 'UP';
        case 'Spike Down':
            return 'DN';
        case 'Volatile':
            return '~~';
        default:
            return '--';
    }
}

function getWatchRuleText(insight = {}) {
    const market = insight?.marketDisplay || `O/U ${DEFAULT_CUSTOM_WATCH_MARKET}`;
    const threshold = Number.isFinite(insight?.threshold) ? insight.threshold : DEFAULT_CUSTOM_WATCH_THRESHOLD;
    return `${market} > ${threshold.toFixed(2)}`;
}

function rankInsight(insight) {
    const severityScore = insight?.severity === 'danger'
        ? 3
        : insight?.severity === 'warning'
            ? 2
            : 1;
    const durationScore = Number.isFinite(insight?.aboveThresholdDurationMs) ? insight.aboveThresholdDurationMs / 1000 : 0;
    const deltaScore = Number.isFinite(insight?.deltaFromWindow) ? Math.abs(insight.deltaFromWindow) * 10 : 0;
    const crossScore = Number.isFinite(insight?.thresholdCrosses) ? insight.thresholdCrosses : 0;

    return (severityScore * 1000) + durationScore + deltaScore + crossScore;
}

function isMeaningfulInsight(insight) {
    if (!insight || !insight.isSecondHalf) {
        return false;
    }

    if (insight.severity === 'danger' || insight.severity === 'warning') {
        return true;
    }

    return insight.isAboveThreshold === true || Math.abs(insight.deltaFromWindow || 0) >= 0.12;
}

function isSecondHalfMatch(match, insight) {
    return Boolean(insight?.isSecondHalf || /^2H\s+\d+'$/i.test(String(match?.status || '').trim()));
}

function shouldIncludeMatch(match, insight) {
    if (currentViewFilter === '2h') {
        return isSecondHalfMatch(match, insight);
    }

    if (currentViewFilter === 'odd') {
        return isMeaningfulInsight(insight);
    }

    return true;
}

function updateViewToggleButtons() {
    const buttonMap = {
        all: 'viewAllBtn',
        '2h': 'view2HBtn',
        odd: 'viewOddBtn'
    };

    Object.entries(buttonMap).forEach(([key, id]) => {
        const element = document.getElementById(id);
        if (!element) {
            return;
        }

        element.classList.toggle('active', currentViewFilter === key);
    });
}

function renderOddInsights(oddInsights = {}) {
    const panel = document.getElementById('oddInsightsPanel');
    const countEl = document.getElementById('oddInsightCount');
    if (!panel) return; // panel "Top Odd Aneh" dihapus dari popup
    const insightList = Object.values(oddInsights)
        .filter((insight) => isMeaningfulInsight(insight))
        .sort((a, b) => rankInsight(b) - rankInsight(a))
        .slice(0, 5);

    countEl.textContent = `${insightList.length} aktif`;

    if (!insightList.length) {
        panel.innerHTML = '<div class="insight-empty">Belum ada breakout, spike, atau pola odd 2H yang cukup kuat.</div>';
        return;
    }

    panel.innerHTML = `<div class="insight-list">${insightList.map((insight) => `
        <div class="insight-card">
            <div class="insight-card__top">
                <div class="insight-card__teams">${JSON.parse(insight.matchKey).teams}</div>
                <span class="${getPatternBadgeClass(insight)}"><span class="pattern-icon">${getPatternIcon(insight)}</span>${insight.pattern}</span>
            </div>
            <div class="insight-card__meta">
                <span>${insight.status || '-'}</span>
                <span>Score ${insight.score || '-'}</span>
                <span class="insight-card__odd">${getWatchRuleText(insight)}</span>
                <span class="insight-card__odd">Odd ${formatOdd(insight.currentOdd)} (${formatDelta(insight.deltaFromPrevious)})</span>
                <span>Compare ${formatComparisonOdds(insight.comparisonOdds)}</span>
                <span>Above threshold ${formatDuration(insight.aboveThresholdDurationMs)}</span>
                <span>Max ${formatOdd(insight.maxOdd)}</span>
            </div>
        </div>
    `).join('')}</div>`;
}

// Format opsi sebuah market string "FT. 1X2: Home:1.45 | Draw:3.50" menjadi HTML.
function formatBetTypeOptions(betTypeStr) {
    const parts = String(betTypeStr || '').split(': ');
    if (parts.length < 2) {
        return `<span class="odds-normal">${betTypeStr}</span>`;
    }
    const betType = parts[0];
    const options = parts.slice(1).join(': ');
    const is1X2Type = betType.includes('1X2');

    const formattedOptions = options.split(' | ').map((opt) => {
        if (opt === '[LOCKED]') {
            return '<span style="color:#999;font-style:italic;">LOCKED</span>';
        }
        const optParts = opt.split(':');
        if (optParts.length === 2) {
            const goal = optParts[0];
            const oddsVal = optParts[1].trim();
            if (is1X2Type) {
                const isMinus = oddsVal.startsWith('-');
                return `<span class="${isMinus ? 'odds-minus' : 'odds-normal'}">${goal}: ${oddsVal}</span>`;
            }
            const oddsNum = parseFloat(oddsVal);
            return `<span class="${oddsNum < 2.0 ? 'odds-favorite' : 'odds-normal'}">${goal} @ ${oddsVal}</span>`;
        }
        return opt;
    }).join(' | ');

    return formattedOptions;
}

// Klasifikasikan market hanya untuk FULL-TIME ke salah satu: '1x2' | 'hdp' | 'ou'.
// Mengembalikan null untuk market babak (1H/HT/2H) atau market lain (mis. Next Goal).
function classifyFtMarket(betTypeStr) {
    const title = String(betTypeStr || '').split(':')[0].toLowerCase().replace(/\s+/g, '');
    // Hanya full-time: prefiks "ft" atau tanpa penanda babak.
    const isFt = title.startsWith('ft') || (!title.includes('1h') && !title.includes('ht') && !title.includes('2h'));
    if (!isFt) return null;
    if (title.includes('1x2')) return '1x2';
    if (title.includes('o/u') || title.includes('over') || title.includes('under')) return 'ou';
    if (title.includes('handicap') || title.includes('hdp') || title.includes('ah')) return 'hdp';
    return null;
}

function renderFtMarketColumn(odds, kind) {
    const matched = (Array.isArray(odds) ? odds : []).filter((s) => classifyFtMarket(s) === kind);
    if (!matched.length) return '-';
    return `<div class="odds-row">${matched.map((s) => `<span class="odds-group">${formatBetTypeOptions(s)}</span>`).join('')}</div>`;
}

function renderTable(matches, oddInsights = {}, allGoalMinutes = {}, htScores = {}, all2HGoalMinutes = {}) {
    if (!isExtensionContextValid()) {
        return;
    }

    const container = document.getElementById('matchesTable');
    if (!matches?.length) {
        container.innerHTML = '<div style="text-align:center;padding:40px;background:white;">No matches found</div>';
        return;
    }

    const normalizedGroups = Array.isArray(matches) && matches[0]?.matches
        ? matches
        : [{
            league: 'Unknown League',
            matches
        }];

    const prioritizedGroups = normalizedGroups
        .map((group) => ({
            ...group,
            matches: (group.matches || [])
                .filter((match) => shouldIncludeMatch(match, oddInsights[createMatchKey(match)] || null))
                .slice()
                .sort((a, b) => {
                const aInsight = oddInsights[createMatchKey(a)] || null;
                const bInsight = oddInsights[createMatchKey(b)] || null;
                const aSecondHalf = isSecondHalfMatch(a, aInsight);
                const bSecondHalf = isSecondHalfMatch(b, bInsight);

                if (aSecondHalf !== bSecondHalf) {
                    return aSecondHalf ? -1 : 1;
                }

                return rankInsight(bInsight) - rankInsight(aInsight);
            })
        }))
        .filter((group) => (group.matches || []).length > 0);

    if (!prioritizedGroups.length) {
        const emptyText = currentViewFilter === '2h'
            ? 'Belum ada match babak kedua.'
            : currentViewFilter === 'odd'
                ? 'Belum ada odd aneh yang terdeteksi.'
                : 'No matches found';
        container.innerHTML = `<div style="text-align:center;padding:40px;background:white;">${emptyText}</div>`;
        return;
    }

    container.innerHTML = prioritizedGroups.map(({ league, matches: leagueMatches }) => {
        const rowsHtml = leagueMatches.map((m) => {
            const timeClass = m.status?.includes('H.Time') ? 'time-ht' : 'time-live';
            const matchKey = createMatchKey(m);
            const insight = oddInsights[matchKey] || null;
            const allGoalMins = Array.isArray(allGoalMinutes[matchKey]) ? allGoalMinutes[matchKey] : null;
            const all2HGoalMins = Array.isArray(all2HGoalMinutes[matchKey]) ? all2HGoalMinutes[matchKey] : null;
            const htScore = insight?.htScore || htScores[matchKey] || null;
            const watchContext = getMatchWatchContextInPopup(m);
            const watchBadge = watchContext.isWatchedTeam
                ? ' <span style="background:#fff3cd;color:#856404;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;">WATCH TEAM</span>'
                : '';
            const odd1x2Html = renderFtMarketColumn(m.odds, '1x2');
            const oddHdpHtml = renderFtMarketColumn(m.odds, 'hdp');
            const oddOuHtml = renderFtMarketColumn(m.odds, 'ou');

            const goalBadge = allGoalMins?.length
                ? `<div style="margin-top:3px;font-size:9px;color:#856404;background:#fff3cd;border-radius:10px;padding:1px 6px;display:inline-block;">Goal 1H: ${allGoalMins.map((min) => `${min}'`).join(', ')}</div>`
                : '';

            return `<tr>
                <td class="team-name">${m.homeTeam || '-'}</td>
                <td class="team-name">${m.awayTeam || '-'}${watchBadge}</td>
                <td><span class="${timeClass}">${m.status || '-'}</span></td>
                <td class="score">${m.score || '-'}${goalBadge}</td>
                <td class="score">${htScore || '-'}${all2HGoalMins?.length ? `<div style="margin-top:3px;font-size:9px;color:#155724;background:#d4edda;border-radius:10px;padding:1px 6px;display:inline-block;">Goal 2H: ${all2HGoalMins.map((min) => `${min}'`).join(', ')}</div>` : ''}</td>
                <td class="odds-text">${odd1x2Html}</td>
                <td class="odds-text">${oddHdpHtml}</td>
                <td class="odds-text">${oddOuHtml}</td>
            </tr>`;
        }).join('');

        return `<div class="league-group">
            <div class="league-title">${league}</div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 13%;">Home</th>
                        <th style="width: 13%;">Away</th>
                        <th style="width: 7%;">Time</th>
                        <th style="width: 7%;">Score</th>
                        <th style="width: 6%;">HT</th>
                        <th style="width: 18%;">Odd 1X2 FT</th>
                        <th style="width: 18%;">Odd Handicap FT</th>
                        <th style="width: 18%;">Odd Over Under FT</th>
                    </tr>
                </thead>
                <tbody>
                    ${rowsHtml}
                </tbody>
            </table>
        </div>`;
    }).join('');
}

function showError(message) {
    document.getElementById('errorBox').innerHTML = `<div class="error-box">${message}</div>`;
}

function clearError() {
    document.getElementById('errorBox').innerHTML = '';
}

function updateLiveUI(isRunning) {
    const stopBtn = document.getElementById('stopLiveBtn');
    const indicator = document.getElementById('liveStatus');

    if (isRunning) {
        stopBtn.disabled = false;
        indicator.className = 'live-indicator live-on';
        indicator.textContent = 'AUTO LIVE: ON (BACKGROUND)';
    } else {
        stopBtn.disabled = true;
        indicator.className = 'live-indicator live-off';
        indicator.textContent = 'AUTO LIVE: OFF';
    }
}

// Akumulasi nama team yang pernah terlihat (disimpan di localStorage) untuk
// autocomplete input team target. Menerima struktur grup-liga maupun array match.
const TEAM_SUGGESTION_STORE_KEY = 'bpvmSeenTeamNames';
const TEAM_SUGGESTION_MAX = 2000;
const CLUB_NAMES_URL = 'http://localhost/lebihsabar/club-names.php';

// Ambil daftar LENGKAP klub dari server (bukan hanya yang sedang live), gabung ke
// simpanan lokal, lalu render datalist. Gagal fetch -> tetap pakai daftar lokal.
async function fetchAllClubNames() {
    try {
        const resp = await fetch(CLUB_NAMES_URL, { signal: AbortSignal.timeout(5000) });
        if (!resp.ok) return;
        const data = await resp.json();
        const teams = Array.isArray(data?.teams) ? data.teams : [];
        if (!teams.length) return;

        let stored = [];
        try {
            stored = JSON.parse(localStorage.getItem(TEAM_SUGGESTION_STORE_KEY) || '[]');
        } catch (_) {
            stored = [];
        }
        const merged = Array.from(new Set([...stored, ...teams].filter(Boolean))).slice(-TEAM_SUGGESTION_MAX);
        try {
            localStorage.setItem(TEAM_SUGGESTION_STORE_KEY, JSON.stringify(merged));
        } catch (_) {}
        updateTeamSuggestions([]);
    } catch (_) {
        // diam: pakai daftar lokal yang sudah ada
    }
}
function updateTeamSuggestions(matchesOrGroups) {
    const names = new Set();
    const collect = (m) => {
        if (m?.homeTeam) names.add(String(m.homeTeam).trim());
        if (m?.awayTeam) names.add(String(m.awayTeam).trim());
    };
    (Array.isArray(matchesOrGroups) ? matchesOrGroups : []).forEach((item) => {
        if (item && Array.isArray(item.matches)) {
            item.matches.forEach(collect);
        } else {
            collect(item);
        }
    });

    let stored = [];
    try {
        stored = JSON.parse(localStorage.getItem(TEAM_SUGGESTION_STORE_KEY) || '[]');
    } catch (_) {
        stored = [];
    }
    const merged = Array.from(new Set([...stored, ...names].filter(Boolean))).slice(-TEAM_SUGGESTION_MAX);
    try {
        localStorage.setItem(TEAM_SUGGESTION_STORE_KEY, JSON.stringify(merged));
    } catch (_) {}

    const datalist = document.getElementById('teamSuggestions');
    if (datalist) {
        datalist.innerHTML = merged
            .sort((a, b) => a.localeCompare(b))
            .map((name) => `<option value="${escapeHtml(name)}"></option>`)
            .join('');
    }
}

function applyPopupState(state) {
    const data = state?.data || {};
    const runtimeState = data.runtimeState || { isLiveRunning: false };
    const liveStatus = data.liveStatus || {};
    const groupedMatches = data.groupedMatches?.length ? data.groupedMatches : data.matches;
    const oddInsights = data.oddInsights || {};
    const goalMinutes = data.goalMinutes || {};
    const allGoalMinutes = data.allGoalMinutes || {};
    const all2HGoalMinutes = data.all2HGoalMinutes || {};
    const htScores = data.htScores || {};
    const hasMatchData = Array.isArray(groupedMatches) && groupedMatches.length > 0;
    const hasOddData = oddInsights && Object.keys(oddInsights).length > 0;

    if (hasMatchData) {
        popupDataCache.groupedMatches = groupedMatches;
    }

    if (hasOddData) {
        popupDataCache.oddInsights = oddInsights;
    }

    if (Object.keys(allGoalMinutes).length > 0) {
        popupDataCache.goalMinutes = goalMinutes;
        popupDataCache.allGoalMinutes = allGoalMinutes;
    }

    if (Object.keys(all2HGoalMinutes).length > 0) {
        popupDataCache.all2HGoalMinutes = all2HGoalMinutes;
    }

    if (Object.keys(htScores).length > 0) {
        popupDataCache.htScores = htScores;
    }

    const matchesToRender = hasMatchData
        ? groupedMatches
        : (popupDataCache.groupedMatches || []);
    const insightsToRender = hasOddData
        ? oddInsights
        : (popupDataCache.oddInsights || {});
    const allGoalMinutesToRender = Object.keys(allGoalMinutes).length > 0
        ? allGoalMinutes
        : (popupDataCache.allGoalMinutes || {});
    const all2HGoalMinutesToRender = Object.keys(all2HGoalMinutes).length > 0
        ? all2HGoalMinutes
        : (popupDataCache.all2HGoalMinutes || {});
    const htScoresToRender = Object.keys(htScores).length > 0
        ? htScores
        : (popupDataCache.htScores || {});
    const countFromState = Number.isFinite(data.count) ? data.count : 0;
    if (hasMatchData) {
        popupDataCache.count = countFromState;
    }

    const matchCountToShow = hasMatchData
        ? countFromState
        : (popupDataCache.count !== null ? popupDataCache.count : countFromState);

    updateViewToggleButtons();
    renderOddInsights(insightsToRender);

    if (matchesToRender?.length) {
        renderTable(matchesToRender, insightsToRender, allGoalMinutesToRender, htScoresToRender, all2HGoalMinutesToRender);
        updateTeamSuggestions(matchesToRender);
    }

    document.getElementById('matchCount').textContent = `${matchCountToShow} matches`;
    document.getElementById('lastUpdate').textContent = `Last update: ${liveStatus.lastUpdate || data.time || '-'}`;
    document.getElementById('lastSent').textContent = `Last sent: ${liveStatus.lastSent || '-'}`;
    document.getElementById('lastRetry').textContent = `Last retry: ${liveStatus.lastRetry || '0'}`;
    document.getElementById('serverStatus').textContent = liveStatus.serverStatus || 'Telegram: -';
    document.getElementById('pageStatus').textContent = liveStatus.pageStatus || 'Checking page...';
    document.getElementById('lastCycle').textContent = `Cycle: ${liveStatus.lastCycle || '-'}`;
    document.getElementById('lastRefresh').textContent = `Refresh: ${liveStatus.lastRefresh || '-'}`;
    document.getElementById('lastExtractStatus').textContent = `Extract: ${liveStatus.lastExtractStatus || '-'}`;

    renderH2HStatus(data.h2hNotifyStatus || null);
    renderH2HWatch(data.h2hWatchToday || null);
    renderStreakStatus(data.streakNotifyStatus || null);
    renderStreakWatch(data.streakWatchToday || null);

    document.getElementById('pageStatus').style.color = (liveStatus.pageStatus || '').includes('X') ? '#dc3545' : '#28a745';
    document.getElementById('serverStatus').style.color = (liveStatus.serverStatus || '').includes('failed') || (liveStatus.serverStatus || '').includes('Failed')
        ? '#dc3545'
        : (liveStatus.serverStatus || '').includes('Retry')
            ? '#856404'
            : '#28a745';

    if (liveStatus.error) {
        showError(liveStatus.error);
    } else {
        clearError();
    }

    updateLiveUI(Boolean(runtimeState.isLiveRunning));
}

function renderTeamWatchPanel() {
    const chips = document.getElementById('teamChips');
    const info = document.getElementById('customWatchInfo');
    const thresholdInput = document.getElementById('customOddInput');

    thresholdInput.value = customWatchConfig.customOddThreshold;
    const teamRules = Array.isArray(customWatchConfig.teamRules) ? customWatchConfig.teamRules : [];

    chips.innerHTML = teamRules
        .map((watchRule) => {
            const team = normalizeTeamName(watchRule?.team || '');
            if (!team) {
                return '';
            }

            const selection = String(watchRule?.customOddSelection || customWatchConfig.customOddSelection || DEFAULT_CUSTOM_WATCH_MARKET);
            const threshold = Number.isFinite(watchRule?.customOddThreshold)
                ? Number(watchRule.customOddThreshold)
                : customWatchConfig.customOddThreshold;
            const htScores = Array.isArray(watchRule?.halftimeScores) ? watchRule.halftimeScores : [];
            const htText = htScores.length ? ` | HT ${htScores.join('/')}` : '';
            const isHtOnly = Boolean(watchRule?.htOnly);
            const ruleText = isHtOnly
                ? `[HT only]${escapeHtml(htText)}`
                : `${escapeHtml(`O/U ${selection.replace(/^o/, '')}`)} > ${threshold.toFixed(2)}${escapeHtml(htText)}`;

            return `
                <span class="team-chip" data-rule-team="${escapeHtml(team)}" title="Edit rule">
                    <span class="team-chip__text">${escapeHtml(team)} (${ruleText})</span>
                    <button type="button" data-remove-team="${escapeHtml(team)}" title="Hapus">x</button>
                </span>
            `;
        })
        .join('');

    const customMarket = String(customWatchConfig.customOddSelection || DEFAULT_CUSTOM_WATCH_MARKET).replace(/^o/, '');
    const customThreshold = Number(customWatchConfig.customOddThreshold || DEFAULT_CUSTOM_WATCH_THRESHOLD);
    const hasRules = teamRules.length > 0;
    info.textContent = hasRules
        ? `Mode: all mode, custom rules: ${teamRules.length} teams (${`O/U ${customMarket} > ${Number.isFinite(customThreshold) ? customThreshold.toFixed(2) : DEFAULT_CUSTOM_WATCH_THRESHOLD}`} as default fallback)`
        : 'Mode: all mode, belum ada custom team';

    Array.from(chips.querySelectorAll('span[data-rule-team]')).forEach((chip) => {
        chip.addEventListener('click', (event) => {
            if (event.target?.dataset?.removeTeam) {
                return;
            }

            const teamName = chip.dataset.ruleTeam;
            const matched = teamRules.find((rule) => normalizeTeamName(rule.team) === teamName);
            if (!matched) {
                return;
            }

            const teamInput = document.getElementById('teamInput');
            const marketInput = document.getElementById('customMarketInput');
            const thresholdInput = document.getElementById('customOddInput');
            const htInput = document.getElementById('customHtScoreInput');

            if (teamInput) {
                teamInput.value = matched.team;
            }
            if (marketInput) {
                marketInput.value = String(matched.customOddSelection || customWatchConfig.customOddSelection || DEFAULT_CUSTOM_WATCH_MARKET).replace(/^o/, '');
            }
            if (thresholdInput) {
                thresholdInput.value = Number.isFinite(matched.customOddThreshold)
                    ? Number(matched.customOddThreshold).toFixed(2)
                    : Number(customWatchConfig.customOddThreshold).toFixed(2);
            }
            if (htInput) {
                htInput.value = Array.isArray(matched.halftimeScores) ? matched.halftimeScores.join(', ') : '';
            }
            const htToggle = document.getElementById('htModeToggle');
            if (htToggle) {
                htToggle.checked = Boolean(matched.htOnly);
            }
            applyHtModeUI();
        });
    });

    Array.from(chips.querySelectorAll('button[data-remove-team]')).forEach((button) => {
        button.addEventListener('click', async () => {
            const teamValue = button.dataset.removeTeam;
            const nextRules = (customWatchConfig.teamRules || []).filter((item) => normalizeTeamName(item?.team) !== teamValue);
            customWatchConfig.teamRules = nextRules;
            await requestBackgroundWithPayload(WATCH_CONFIG_ACTIONS.set, customWatchConfig);
            await syncPopupState();
            await loadCustomWatchConfig();
        });
    });
}

async function loadCustomWatchConfig() {
    const response = await requestBackgroundWithPayload(WATCH_CONFIG_ACTIONS.get);
    if (!response || response.ok === false) {
        return;
    }

    const teamRules = Array.isArray(response.teamRules) ? response.teamRules : [];
    customWatchConfig = {
        teamRules,
        matchRules: Array.isArray(response.matchRules) ? response.matchRules : [],
        customOddThreshold: toThresholdNumber(response.customOddThreshold, DEFAULT_CUSTOM_WATCH_THRESHOLD),
        customOddSelection: response.customOddSelection || `o${DEFAULT_CUSTOM_WATCH_MARKET}`
    };

    const marketInput = document.getElementById('customMarketInput');
    if (marketInput) {
        marketInput.value = customWatchConfig.customOddSelection.replace(/^o/, '');
    }

    const thresholdInput = document.getElementById('customOddInput');
    if (thresholdInput) {
        thresholdInput.value = Number.isFinite(customWatchConfig.customOddThreshold)
            ? Number(customWatchConfig.customOddThreshold).toFixed(2)
            : DEFAULT_CUSTOM_WATCH_THRESHOLD;
    }

    renderTeamWatchPanel();
    renderMatchWatchPanel();
}

// Tambah rule per-match (home + away + market + odd).
async function addMatchRule() {
    clearError();
    const homeEl = document.getElementById('matchHomeInput');
    const awayEl = document.getElementById('matchAwayInput');
    const mktEl = document.getElementById('matchMarketInput');
    const oddEl = document.getElementById('matchOddInput');
    const home = String(homeEl?.value || '').trim();
    const away = String(awayEl?.value || '').trim();
    if (!home || !away) { showError('Isi Home dan Away.'); return; }

    const rawMarket = String(mktEl?.value || '').trim().replace(/\s+/g, '').toLowerCase();
    if (rawMarket && !/^o?\d+(?:[.,]\d+)?$/.test(rawMarket)) {
        showError('Market harus angka seperti 0.5, 0.75, 1.0'); return;
    }
    const market = rawMarket ? (rawMarket.startsWith('o') ? rawMarket : `o${rawMarket}`) : `o${DEFAULT_CUSTOM_WATCH_MARKET}`;
    const odd = toThresholdNumber(oddEl?.value, customWatchConfig.customOddThreshold);

    const hN = normalizeTeamName(home), aN = normalizeTeamName(away);
    const rules = Array.isArray(customWatchConfig.matchRules) ? customWatchConfig.matchRules : [];
    const next = rules.filter((r) => !(normalizeTeamName(r.home) === hN && normalizeTeamName(r.away) === aN));
    next.push({ home, away, customOddSelection: market, customOddThreshold: odd });
    customWatchConfig.matchRules = next;
    await requestBackgroundWithPayload(WATCH_CONFIG_ACTIONS.set, customWatchConfig);
    await syncPopupState();
    if (homeEl) homeEl.value = ''; if (awayEl) awayEl.value = ''; if (mktEl) mktEl.value = ''; if (oddEl) oddEl.value = '';
    await loadCustomWatchConfig();
}

function renderMatchWatchPanel() {
    const chips = document.getElementById('matchChips');
    if (!chips) return;
    const rules = Array.isArray(customWatchConfig.matchRules) ? customWatchConfig.matchRules : [];
    chips.innerHTML = rules.map((r, i) => {
        const mkt = String(r.customOddSelection || 'o0.5').replace(/^o/, '');
        const odd = Number.isFinite(r.customOddThreshold) ? Number(r.customOddThreshold).toFixed(2) : '?';
        const label = `${escapeHtmlPopup(r.home)} vs ${escapeHtmlPopup(r.away)} · O/U ${escapeHtmlPopup(mkt)} ≥ ${odd}`;
        return `<span class="team-chip"><span class="team-chip__text">${label}</span><button type="button" data-remove-match="${i}" title="Hapus">x</button></span>`;
    }).join('') || '<span style="font-size:11px;color:#6c757d;">Belum ada match rule</span>';

    Array.from(chips.querySelectorAll('button[data-remove-match]')).forEach((btn) => {
        btn.addEventListener('click', async () => {
            const idx = parseInt(btn.dataset.removeMatch, 10);
            customWatchConfig.matchRules = (customWatchConfig.matchRules || []).filter((_, i) => i !== idx);
            await requestBackgroundWithPayload(WATCH_CONFIG_ACTIONS.set, customWatchConfig);
            await syncPopupState();
            await loadCustomWatchConfig();
        });
    });
}

function escapeHtmlPopup(v) {
    return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function renderH2HWatch(wl) {
    const panel = document.getElementById('h2hWatchPanel');
    const countEl = document.getElementById('h2hWatchCount');
    if (!panel) return;
    const matches = (wl && Array.isArray(wl.matches)) ? wl.matches : [];
    if (countEl) countEl.textContent = `${matches.length} match`;
    if (matches.length === 0) {
        panel.innerHTML = '<div class="insight-empty">Belum ada match lolos hari ini.</div>';
        return;
    }
    panel.innerHTML = matches.map((m) => {
        const pctColor = m.pct >= 95 ? '#1a7f37' : (m.pct >= 90 ? '#2da44e' : '#bf8700');
        return `<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:5px 6px;border-bottom:1px solid #eee;font-size:12px;">
            <span style="font-weight:600;color:#555;min-width:42px;">${escapeHtmlPopup(m.time)}</span>
            <span style="flex:1;">${escapeHtmlPopup(m.home)} <span style="color:#999;">vs</span> ${escapeHtmlPopup(m.away)}</span>
            <span style="font-weight:700;color:${pctColor};">${m.pct}%</span>
            <span style="color:#999;">${m.hits}/${m.total}</span>
        </div>`;
    }).join('');
}

function renderStreakWatch(wl) {
    const panel = document.getElementById('streakWatchPanel');
    const countEl = document.getElementById('streakWatchCount');
    if (!panel) return;
    const teams = (wl && Array.isArray(wl.teams)) ? wl.teams : [];
    if (countEl) countEl.textContent = `${teams.length} tim`;
    if (teams.length === 0) {
        panel.innerHTML = '<div class="insight-empty">Belum ada tim kalah 2x yang lolos ambang.</div>';
        return;
    }
    panel.innerHTML = teams.map((t) => {
        const pctColor = t.pct >= 90 ? '#1a7f37' : (t.pct >= 85 ? '#2da44e' : '#bf8700');
        var base = t.market === 'u15x3' ? 'U1.5×3' : (t.market === 'dr2' ? 'Draw×2' : (t.market === 'dr3' ? 'Draw×3' : (t.market === 'u05x2' ? 'U0.5×2' : 'Kalah×2')));
        var tag = base + (t.out === 'o05' ? ' O0.5' : ' O1.5');
        var tagColor = t.out === 'o05' ? '#0b8a8a' : (t.market === 'u15x3' ? '#0b62d6' : (t.market === 'dr2' ? '#7b3fe4' : '#b54708'));
        return `<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:5px 6px;border-bottom:1px solid #eee;font-size:12px;">
            <span style="font-size:10px;font-weight:700;color:${tagColor};min-width:72px;">${tag}</span>
            <span style="flex:1;"><b>${escapeHtmlPopup(t.team)}</b> <span style="color:#999;">vs</span> ${escapeHtmlPopup(t.opponent)}</span>
            <span style="color:#777;">${escapeHtmlPopup(t.next_time || '')}</span>
            <span style="font-weight:700;color:${pctColor};">${t.pct}%</span>
            <span style="color:#999;">${t.over}/${t.total}</span>
        </div>`;
    }).join('');
}

function renderStreakStatus(st) {
    const el = document.getElementById('streakStatus');
    if (!el) return;
    if (!st || !st.lastCheck) {
        el.textContent = 'Streak watch: belum ada cek';
        el.style.color = '#856404';
        return;
    }
    if (!st.ok) {
        el.textContent = `Streak watch: GAGAL — ${st.error || 'error'} (${st.lastCheck})`;
        el.style.color = '#dc3545';
        return;
    }
    const alert = st.lastAlert ? ` · alert: ${st.lastAlert}` : '';
    el.textContent = `Streak watch: OK · ${st.count} tim · cek ${st.lastCheck}${alert}`;
    el.style.color = '#28a745';
}

function renderH2HStatus(st) {
    const el = document.getElementById('h2hStatus');
    if (!el) return;
    if (!st || !st.lastCheck) {
        el.textContent = 'H2H Telegram: belum ada cek';
        el.style.color = '#856404';
        return;
    }
    if (!st.ok) {
        el.textContent = `H2H Telegram: GAGAL — ${st.error || 'error'} (${st.lastCheck})`;
        el.style.color = '#dc3545';
        return;
    }
    const note = st.sent > 0
        ? `terkirim ${st.sent} match`
        : (st.note || 'tidak ada kiriman baru');
    el.textContent = `H2H Telegram: OK · ${note} · cek ${st.lastCheck}`;
    el.style.color = '#28a745';
}

async function syncPopupState() {
    if (!isExtensionContextValid()) {
        return;
    }

    const state = await requestBackground('getPopupState');
    if (!state?.ok) {
        showError(state?.error || 'Failed to load popup state');
        return;
    }

    applyPopupState(state);
}

async function checkPageStatus() {
    if (!isExtensionContextValid()) {
        return;
    }

    await refreshCurrentTab();
    const isTarget = currentTab && currentTab.url && currentTab.url.includes('g943gp.bpvmr7u6.com');

    if (!isTarget) {
        document.getElementById('pageStatus').textContent = 'X Not on target page';
        document.getElementById('pageStatus').style.color = '#dc3545';
    }
}

async function extractData() {
    const response = await requestBackground('extractNow');
    if (!response?.ok) {
        showError(response?.error || 'Failed to extract data');
        return;
    }

    await syncPopupState();
}

async function startLive() {
    const response = await requestBackground('startLive');
    if (!response?.ok) {
        showError(response?.error || 'Failed to start live mode');
        await syncPopupState();
        return;
    }

    clearError();
    await syncPopupState();
}

async function addTeam() {
    const teamInput = document.getElementById('teamInput');
    const raw = String(teamInput?.value || '');
    const teamName = raw.trim();
    clearError();
    if (!teamName) {
        showError('Nama team harus diisi.');
        return;
    }

    const teamValue = teamName.toLowerCase().replace(/\s+/g, ' ').trim();
    if (!teamValue) {
        return;
    }

    const ruleInput = getTeamRuleInputs();
    if (!ruleInput) {
        return;
    }

    const normalizedRules = Array.isArray(customWatchConfig.teamRules) ? customWatchConfig.teamRules : [];
    const nextRules = normalizedRules.filter((rule) => normalizeTeamName(rule?.team) !== teamValue);
    nextRules.push({
        team: teamValue,
        customOddThreshold: ruleInput.customOddThreshold,
        customOddSelection: ruleInput.customOddSelection,
        halftimeScores: ruleInput.halftimeScores,
        htOnly: ruleInput.htOnly
    });

    customWatchConfig.teamRules = nextRules;
    await requestBackgroundWithPayload(WATCH_CONFIG_ACTIONS.set, customWatchConfig);
    await syncPopupState();

    teamInput.value = '';
    const marketInput = document.getElementById('customMarketInput');
    const oddInput = document.getElementById('customOddInput');
    const htInput = document.getElementById('customHtScoreInput');
    if (marketInput) {
        marketInput.value = '';
    }
    if (oddInput) {
        oddInput.value = '';
    }
    if (htInput) {
        htInput.value = '';
    }
    const htToggle = document.getElementById('htModeToggle');
    if (htToggle) {
        htToggle.checked = false;
    }
    applyHtModeUI();
    await loadCustomWatchConfig();
}

async function saveCustomThreshold() {
    const ruleInput = getTeamRuleInputs();
    if (!ruleInput) {
        return;
    }

    customWatchConfig.customOddThreshold = ruleInput.customOddThreshold;
    customWatchConfig.customOddSelection = ruleInput.customOddSelection;
    clearError();
    await requestBackgroundWithPayload(WATCH_CONFIG_ACTIONS.set, customWatchConfig);
    await syncPopupState();
    await loadCustomWatchConfig();
}

async function stopLive() {
    await requestBackground('stopLive');
    await syncPopupState();
}

async function sendToServer() {
    const response = await requestBackground('sendStoredData');
    if (!response?.ok) {
        showError(response?.error || 'Failed to send data');
    } else {
        clearError();
    }

    await syncPopupState();
}

document.addEventListener('DOMContentLoaded', async () => {
    try {
        updateTeamSuggestions([]);
        fetchAllClubNames();
        await refreshCurrentTab();
        await syncPopupState();
        await checkPageStatus();

        document.getElementById('refreshBtn').addEventListener('click', extractData);
        document.getElementById('stopLiveBtn').addEventListener('click', stopLive);
        document.getElementById('startLiveBtn').addEventListener('click', startLive);
        document.getElementById('addTeamBtn').addEventListener('click', addTeam);
        document.getElementById('saveWatchBtn').addEventListener('click', saveCustomThreshold);
        document.getElementById('addMatchBtn')?.addEventListener('click', addMatchRule);
        document.getElementById('btnCheckH2H')?.addEventListener('click', async () => {
            const btn = document.getElementById('btnCheckH2H');
            const el = document.getElementById('h2hStatus');
            if (btn) btn.disabled = true;
            if (el) { el.textContent = 'H2H Telegram: mengecek...'; el.style.color = '#856404'; }
            const r = await requestBackground('checkH2HNow');
            await syncPopupState();
            if (r && r.ok === false && el) {
                el.textContent = `H2H Telegram: GAGAL — ${r.error || 'error'}`;
                el.style.color = '#dc3545';
            }
            if (btn) btn.disabled = false;
        });
        document.getElementById('btnCheckStreak')?.addEventListener('click', async () => {
            const btn = document.getElementById('btnCheckStreak');
            const el = document.getElementById('streakStatus');
            if (btn) btn.disabled = true;
            if (el) { el.textContent = 'Streak watch: mengecek...'; el.style.color = '#856404'; }
            const r = await requestBackground('checkStreakNow');
            await syncPopupState();
            if (r && r.ok === false && el) {
                el.textContent = `Streak watch: GAGAL — ${r.error || 'error'}`;
                el.style.color = '#dc3545';
            }
            if (btn) btn.disabled = false;
        });
        document.getElementById('htModeToggle')?.addEventListener('change', applyHtModeUI);
        document.getElementById('teamInput')?.addEventListener('keydown', async (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                await addTeam();
            }
        });
        document.getElementById('customOddInput')?.addEventListener('keydown', async (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                await saveCustomThreshold();
            }
        });
        document.getElementById('customMarketInput')?.addEventListener('keydown', async (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                await saveCustomThreshold();
            }
        });
        document.getElementById('viewAllBtn').addEventListener('click', async () => {
            currentViewFilter = 'all';
            await syncPopupState();
        });
        document.getElementById('view2HBtn').addEventListener('click', async () => {
            currentViewFilter = '2h';
            await syncPopupState();
        });
        document.getElementById('viewOddBtn').addEventListener('click', async () => {
            currentViewFilter = 'odd';
            await syncPopupState();
        });

        await loadCustomWatchConfig();
    } catch (error) {
        showError(error.message || 'Popup init failed');
    }
});
