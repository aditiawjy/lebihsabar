let currentTab = null;
let currentViewFilter = 'all';

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

function createMatchKey(match) {
    return JSON.stringify({
        league: match?.league || 'N/A',
        teams: match?.teams || `${match?.homeTeam || 'Unknown'} vs ${match?.awayTeam || 'Unknown'}`
    });
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
    switch (insight?.pattern) {
        case 'Breakout':
            return '!!';
        case 'Fake Breakout':
            return 'x!';
        case 'Spike Up':
            return 'UP';
        case 'Spike Down':
            return 'DN';
        case 'Cross > 1.80':
            return '1.8';
        case 'Volatile':
            return '~~';
        default:
            return '--';
    }
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
                <span class="insight-card__odd">O0.75 ${formatOdd(insight.currentOdd)} (${formatDelta(insight.deltaFromPrevious)})</span>
                <span>Compare ${formatComparisonOdds(insight.comparisonOdds)}</span>
                <span>Above 1.80 ${formatDuration(insight.aboveThresholdDurationMs)}</span>
                <span>Max ${formatOdd(insight.maxOdd)}</span>
            </div>
        </div>
    `).join('')}</div>`;
}

function renderTable(matches, oddInsights = {}) {
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
            const insight = oddInsights[createMatchKey(m)] || null;

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

            return `<tr>
                <td class="team-name">${m.homeTeam || '-'}</td>
                <td class="team-name">${m.awayTeam || '-'}</td>
                <td><span class="${timeClass}">${m.status || '-'}</span></td>
                <td class="score">${m.score || '-'}</td>
                <td class="odds-focus">${insight ? `${formatOdd(insight.currentOdd)} <span class="delta-text">(${formatDelta(insight.deltaFromPrevious)})</span><div class="pattern-meta">${formatComparisonOdds(insight.comparisonOdds)}</div>` : '-'}</td>
                <td>${insight ? `<span class="${getPatternBadgeClass(insight)}"><span class="pattern-icon">${getPatternIcon(insight)}</span>${insight.pattern}</span><div class="pattern-meta">Above 1.80: ${formatDuration(insight.aboveThresholdDurationMs)}</div>` : '<span class="pattern-badge pattern-muted"><span class="pattern-icon">--</span>No 2H data</span>'}</td>
                <td class="odds-text">${oddsHtml}</td>
            </tr>`;
        }).join('');

        return `<div class="league-group">
            <div class="league-title">${league}</div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 18%;">Home</th>
                        <th style="width: 18%;">Away</th>
                        <th style="width: 10%;">Time</th>
                        <th style="width: 10%;">Score</th>
                        <th style="width: 12%;">O0.75</th>
                        <th style="width: 16%;">Pattern</th>
                        <th style="width: 34%;">Odd</th>
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

    updateViewToggleButtons();
    renderOddInsights(oddInsights);

    if (groupedMatches?.length) {
        renderTable(groupedMatches, oddInsights);
    }

    document.getElementById('matchCount').textContent = `${data.count || 0} matches`;
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
    } catch (error) {
        showError(error.message || 'Popup init failed');
    }
});
