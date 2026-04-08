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
const DEFAULT_CUSTOM_WATCH_THRESHOLD = 1.8;
const DEFAULT_CUSTOM_WATCH_MARKET = '0.75';
let customWatchConfig = {
    teamRules: [],
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

function getTeamRuleInputs() {
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
        customOddThreshold: nextThreshold
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

            let oddsHtml = '-';
            if (m.odds?.length) {
                oddsHtml = m.odds.slice(0, 3).map((betTypeStr) => {
                    const parts = betTypeStr.split(': ');
                    if (parts.length >= 2) {
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
                                    const oddClass = isMinus ? 'odds-minus' : 'odds-normal';
                                    return `<span class="${oddClass}">${goal}: ${oddsVal}</span>`;
                                }

                                const oddsNum = parseFloat(oddsVal);
                                const oddClass = oddsNum < 2.0 ? 'odds-favorite' : 'odds-normal';
                                return `<span class="${oddClass}">${goal} @ ${oddsVal}</span>`;
                            }

                            return opt;
                        }).join(' | ');

                        return `<div style="margin-bottom:3px;"><strong style="font-size:9px;color:#666;">${betType}:</strong> ${formattedOptions}</div>`;
                    }

                    return `<span class="odds-normal">${betTypeStr}</span>`;
                }).join('');
            }

            const goalBadge = allGoalMins?.length
                ? `<div style="margin-top:3px;font-size:9px;color:#856404;background:#fff3cd;border-radius:10px;padding:1px 6px;display:inline-block;">⚽ 1H: ${allGoalMins.map((min) => `${min}'`).join(', ')}</div>`
                : '';

            return `<tr>
                <td class="team-name">${m.homeTeam || '-'}</td>
                <td class="team-name">${m.awayTeam || '-'}${watchBadge}</td>
                <td><span class="${timeClass}">${m.status || '-'}</span></td>
                <td class="score">${m.score || '-'}${goalBadge}</td>
                <td class="score">${htScore || '-'}${all2HGoalMins?.length ? `<div style="margin-top:3px;font-size:9px;color:#155724;background:#d4edda;border-radius:10px;padding:1px 6px;display:inline-block;">⚽ 2H: ${all2HGoalMins.map((min) => `${min}'`).join(', ')}</div>` : ''}</td>
                <td class="odds-focus">${insight ? `${formatOdd(insight.currentOdd)} <span class="delta-text">(${formatDelta(insight.deltaFromPrevious)})</span><div class="pattern-meta">${formatComparisonOdds(insight.comparisonOdds)}</div>` : '-'}</td>
                <td>${insight ? `<span class="${getPatternBadgeClass(insight)}"><span class="pattern-icon">${getPatternIcon(insight)}</span>${insight.pattern}</span><div class="pattern-meta">${getWatchRuleText(insight)} • Above ${formatDuration(insight.aboveThresholdDurationMs)}</div>` : '<span class="pattern-badge pattern-muted"><span class="pattern-icon">--</span>No 2H data</span>'}</td>
                <td class="odds-text">${oddsHtml}</td>
            </tr>`;
        }).join('');

        return `<div class="league-group">
            <div class="league-title">${league}</div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 17%;">Home</th>
                        <th style="width: 17%;">Away</th>
                        <th style="width: 9%;">Time</th>
                        <th style="width: 9%;">Score</th>
                        <th style="width: 8%;">HT</th>
                        <th style="width: 11%;">Watch O</th>
                        <th style="width: 15%;">Pattern</th>
                        <th style="width: 14%;">Odd</th>
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

    document.getElementById('pageStatus').style.color = (liveStatus.pageStatus || '').includes('✗') ? '#dc3545' : '#28a745';
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
            const displaySelection = `O/U ${selection.replace(/^o/, '')}`;

            return `
                <span class="team-chip" data-rule-team="${escapeHtml(team)}" title="Edit rule">
                    <span class="team-chip__text">${escapeHtml(team)} (${escapeHtml(displaySelection)} > ${threshold.toFixed(2)})</span>
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
        document.getElementById('pageStatus').textContent = '✗ Not on target page';
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
        customOddSelection: ruleInput.customOddSelection
    });

    customWatchConfig.teamRules = nextRules;
    await requestBackgroundWithPayload(WATCH_CONFIG_ACTIONS.set, customWatchConfig);
    await syncPopupState();

    teamInput.value = '';
    const marketInput = document.getElementById('customMarketInput');
    const oddInput = document.getElementById('customOddInput');
    if (marketInput) {
        marketInput.value = '';
    }
    if (oddInput) {
        oddInput.value = '';
    }
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
        await refreshCurrentTab();
        await syncPopupState();
        await checkPageStatus();

        document.getElementById('refreshBtn').addEventListener('click', extractData);
        document.getElementById('stopLiveBtn').addEventListener('click', stopLive);
        document.getElementById('sendBtn').addEventListener('click', sendToServer);
        document.getElementById('startLiveBtn').addEventListener('click', startLive);
        document.getElementById('addTeamBtn').addEventListener('click', addTeam);
        document.getElementById('saveWatchBtn').addEventListener('click', saveCustomThreshold);
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
