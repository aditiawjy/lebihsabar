let currentTab = null;

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

function renderTable(matches) {
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

    container.innerHTML = normalizedGroups.map(({ league, matches: leagueMatches }) => {
        const rowsHtml = leagueMatches.map((m) => {
            const timeClass = m.status?.includes('H.Time') ? 'time-ht' : 'time-live';

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
                        <th style="width: 44%;">Odd</th>
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

    if (groupedMatches?.length) {
        renderTable(groupedMatches);
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
    } catch (error) {
        showError(error.message || 'Popup init failed');
    }
});
