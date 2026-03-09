// Popup script - Simple version without background
let currentTab = null;
let liveInterval = null;
let isPopupOpen = true;
let isLiveRunning = false;
let isLiveCycleRunning = false;
let lastAutoSentSignature = null;

const SERVER_URL = 'http://127.0.0.1:5000';
const LIVE_INTERVAL_MS = 5000;
const REFRESH_SETTLE_MS = 1500;
const AUTO_SEND_RETRY_COUNT = 2;
const AUTO_SEND_RETRY_DELAY_MS = 1200;

function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
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

async function refreshCurrentTab() {
    const tabs = await chrome.tabs.query({ active: true, currentWindow: true });
    currentTab = tabs[0] || null;
    return currentTab;
}

function isExtensionContextValid() {
    try {
        chrome.runtime.getManifest();
        return true;
    } catch (e) {
        return false;
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    try {
        await refreshCurrentTab();
        
        // Load saved data silently
        try {
            await loadSavedData();
        } catch (e) {
            // Silent fail for storage errors
        }
        
        checkPageStatus();
        
        document.getElementById('refreshBtn').addEventListener('click', extractData);
        document.getElementById('stopLiveBtn').addEventListener('click', stopLive);
        document.getElementById('sendBtn').addEventListener('click', sendToServer);
    } catch (e) {
        console.log('Init warning:', e.message);
    }
});

window.addEventListener('beforeunload', () => {
    isPopupOpen = false;
    if (liveInterval) {
        clearInterval(liveInterval);
        liveInterval = null;
    }
});

async function loadSavedData() {
    try {
        if (!isExtensionContextValid()) return;
        const data = await chrome.storage.local.get(['lastMatches', 'lastUpdate', 'lastCount']);
        if (data.lastMatches && data.lastMatches.length > 0) {
            renderTable(data.lastMatches);
            document.getElementById('matchCount').textContent = `${data.lastCount || data.lastMatches.length} matches`;
            document.getElementById('lastUpdate').textContent = `Last update: ${data.lastUpdate || '-'}`;
            document.getElementById('pageStatus').textContent = '✓ Loaded saved data';
            document.getElementById('pageStatus').style.color = '#28a745';
            window.lastData = {
                matches: data.lastMatches,
                count: data.lastCount || data.lastMatches.length,
                time: data.lastUpdate
            };
        }
    } catch (e) {
        // Silent fail
    }
}

function checkPageStatus() {
    if (!isExtensionContextValid()) return;
    
    const isTarget = currentTab && currentTab.url && currentTab.url.includes('g943gp.bpvmr7u6.com');
    const statusEl = document.getElementById('pageStatus');
    
    if (isTarget) {
        statusEl.textContent = '✓ Target page detected';
        statusEl.style.color = '#28a745';

        setTimeout(() => {
            if (isExtensionContextValid()) {
                startLive();
            }
        }, 1000);
    } else {
        stopLive();
        statusEl.textContent = '✗ Not on target page';
        statusEl.style.color = '#dc3545';
    }
}

async function extractData() {
    if (!isExtensionContextValid() || !isPopupOpen) return;

    await refreshCurrentTab();
    
    if (!currentTab || !currentTab.url.includes('g943gp.bpvmr7u6.com')) {
        return;
    }
    
    try {
        const results = await chrome.scripting.executeScript({
            target: { tabId: currentTab.id },
            func: () => {
                const matches = [];
                const matchGroups = document.querySelectorAll('.match-group');
                
                matchGroups.forEach(group => {
                    const table = group.closest('.odds-table-card');
                    const leagueHeader = table?.querySelector('.league-header__name');
                    const league = leagueHeader?.innerText?.trim() || '';
                    
                    const matchElements = group.querySelectorAll('.match');
                    const briefElements = group.querySelectorAll('.match-brief');
                    
                    matchElements.forEach((matchEl, idx) => {
                        const match = { league };
                        
                        const brief = briefElements[idx];
                        if (brief) {
                            const teams = brief.querySelectorAll('.match-brief__team');
                            if (teams.length >= 2) {
                                match.homeTeam = teams[0].innerText.trim();
                                match.awayTeam = teams[1].innerText.trim();
                            }
                        }

                        const hasPenaltyTeam =
                            match.homeTeam?.includes('(PEN)') ||
                            match.awayTeam?.includes('(PEN)');

                        if (hasPenaltyTeam) {
                            return;
                        }
                         
                        const timeEl = matchEl.querySelector('.match-time-live');
                        if (timeEl) match.status = timeEl.innerText.trim();
                        
                        const homeScoreEl = matchEl.querySelector('.match-team:first-child .match-team__score');
                        const awayScoreEl = matchEl.querySelector('.match-team:last-child .match-team__score');
                        if (homeScoreEl) match.homeScore = homeScoreEl.innerText.trim();
                        if (awayScoreEl) match.awayScore = awayScoreEl.innerText.trim();
                        
                        match.odds = [];
                        const betTypes = matchEl.querySelectorAll('.match__bettype');
                        
                        betTypes.forEach(betType => {
                            const betTypeTitle = betType.querySelector('.match__bettype-title')?.innerText?.trim();
                            if (!betTypeTitle) return;
                            
                            const oddsButtons = betType.querySelectorAll('.odds-button');
                            const betOptions = [];
                            
                            oddsButtons.forEach(btn => {
                                const isLocked = btn.getAttribute('data-odds-status') === 'close-price';
                                const goal = btn.querySelector('.odds-button__goal')?.innerText?.trim();
                                const oddsValue = btn.querySelector('.odds-button__odds')?.innerText?.trim();
                                const isMinus = btn.querySelector('.odds-button__odds')?.getAttribute('data-minus') === 'true';
                                
                                if (isLocked) {
                                    betOptions.push('[LOCKED]');
                                } else if (goal && oddsValue) {
                                    const is1X2 = betTypeTitle.includes('1X2');
                                    
                                    if (is1X2) {
                                        betOptions.push(`${goal}:${oddsValue}`);
                                    } else {
                                        let decimalOdds;
                                        const numericOdds = parseFloat(oddsValue);
                                        
                                        if (isMinus || oddsValue.startsWith('-')) {
                                            const absOdds = Math.abs(numericOdds);
                                            decimalOdds = (1 + (1 / absOdds)).toFixed(2);
                                        } else {
                                            decimalOdds = (1 + numericOdds).toFixed(2);
                                        }
                                        
                                        betOptions.push(`${goal}:${decimalOdds}`);
                                    }
                                }
                            });
                            
                            if (betOptions.length > 0) {
                                match.odds.push(`${betTypeTitle}: ${betOptions.join(' | ')}`);
                            }
                        });
                        
                        if (match.homeTeam && match.awayTeam) {
                            match.score = `${match.homeScore || '0'} - ${match.awayScore || '0'}`;
                            matches.push(match);
                        }
                    });
                });
                
                return {
                    matches,
                    count: matches.length,
                    time: new Date().toLocaleTimeString()
                };
            }
        });
        
        if (!isExtensionContextValid() || !isPopupOpen) return;
        
        if (results?.[0]?.result) {
            const data = results[0].result;
            renderTable(data.matches);
            document.getElementById('matchCount').textContent = `${data.count} matches`;
            document.getElementById('lastUpdate').textContent = `Last update: ${data.time}`;
            clearError();
            window.lastData = data;
            
            // Save to storage
            await chrome.storage.local.set({
                lastMatches: data.matches,
                lastCount: data.count,
                lastUpdate: data.time
            });

            if (isLiveRunning) {
                const currentSignature = createDataSignature(data);

                if (currentSignature && currentSignature !== lastAutoSentSignature) {
                    const sent = await sendToServerWithRetry();
                    if (sent) {
                        lastAutoSentSignature = currentSignature;
                    }
                } else {
                    document.getElementById('serverStatus').textContent = 'Server: No change';
                    document.getElementById('serverStatus').style.color = '#6c757d';
                }
            }
        }
    } catch (err) {
        if (!isExtensionContextValid()) return;
        console.error('Extract error:', err.message);
    }
}

function renderTable(matches) {
    if (!isExtensionContextValid()) return;
    
    const tbody = document.getElementById('matchesTable');
    
    if (!matches?.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;">No matches found</td></tr>';
        return;
    }
    
    tbody.innerHTML = matches.map(m => {
        const timeClass = m.status?.includes('H.Time') ? 'time-ht' : 'time-live';
        
        let oddsHtml = '-';
        if (m.odds?.length) {
            oddsHtml = m.odds.slice(0, 3).map(betTypeStr => {
                const parts = betTypeStr.split(': ');
                if (parts.length >= 2) {
                    const betType = parts[0];
                    const options = parts.slice(1).join(': ');
                    const is1X2Type = betType.includes('1X2');
                    
                    const formattedOptions = options.split(' | ').map(opt => {
                        if (opt === '[LOCKED]') {
                            return '<span style="color:#999;font-style:italic;">🔒 LOCKED</span>';
                        }
                        const optParts = opt.split(':');
                        if (optParts.length === 2) {
                            const goal = optParts[0];
                            const oddsVal = optParts[1].trim();
                            
                            if (is1X2Type) {
                                const isMinus = oddsVal.startsWith('-');
                                const oddClass = isMinus ? 'odds-minus' : 'odds-normal';
                                return `<span class="${oddClass}">${goal}: ${oddsVal}</span>`;
                            } else {
                                const oddsNum = parseFloat(oddsVal);
                                const oddClass = oddsNum < 2.0 ? 'odds-favorite' : 'odds-normal';
                                return `<span class="${oddClass}">${goal} @ ${oddsVal}</span>`;
                            }
                        }
                        return opt;
                    }).join(' | ');
                    
                    return `<div style="margin-bottom:3px;"><strong style="font-size:9px;color:#666;">${betType}:</strong> ${formattedOptions}</div>`;
                }
                return `<span class="odds-normal">${betTypeStr}</span>`;
            }).join('');
        }
        
        return `<tr>
            <td style="font-size:10px;color:#666;">${m.league || '-'}</td>
            <td class="team-name">${m.homeTeam || '-'}</td>
            <td class="team-name">${m.awayTeam || '-'}</td>
            <td><span class="${timeClass}">${m.status || '-'}</span></td>
            <td class="score">${m.score || '-'}</td>
            <td class="odds-text">${oddsHtml}</td>
        </tr>`;
    }).join('');
}

async function startLive() {
    if (!isExtensionContextValid()) return;
    await refreshCurrentTab();

    if (!currentTab || !currentTab.url.includes('g943gp.bpvmr7u6.com')) {
        showError('Not on target page!');
        return;
    }

    if (isLiveRunning) {
        return;
    }

    isLiveRunning = true;
    lastAutoSentSignature = null;
    
    updateLiveUI(true);

    runLiveCycle();
}

function stopLive() {
    isLiveRunning = false;

    if (liveInterval) {
        clearInterval(liveInterval);
        liveInterval = null;
    }
    updateLiveUI(false);
}

async function runLiveCycle() {
    if (!isLiveRunning || isLiveCycleRunning) {
        return;
    }

    isLiveCycleRunning = true;

    try {
        await refreshCurrentTab();

        if (!isExtensionContextValid() || !isPopupOpen || !currentTab || !currentTab.url.includes('g943gp.bpvmr7u6.com')) {
            stopLive();
            return;
        }

        await clickPageRefresh();
        await delay(REFRESH_SETTLE_MS);

        if (!isLiveRunning) {
            return;
        }

        await extractData();
    } finally {
        isLiveCycleRunning = false;
    }

    if (!isLiveRunning || !isPopupOpen || !isExtensionContextValid()) {
        stopLive();
        return;
    }

    liveInterval = setTimeout(() => {
        runLiveCycle();
    }, LIVE_INTERVAL_MS);
}

async function clickPageRefresh() {
    if (!isExtensionContextValid() || !isPopupOpen) return;
    
    try {
        await chrome.scripting.executeScript({
            target: { tabId: currentTab.id },
            func: () => {
                const btn = document.querySelector('button.btn--icon svg.icon--refresh')?.closest('button');
                if (btn) {
                    btn.click();
                    return true;
                }
                return false;
            }
        });
    } catch (e) {
        console.log('Refresh click failed:', e.message);
    }
}

function updateLiveUI(isRunning) {
    try {
        if (!isExtensionContextValid()) return;
        const stopBtn = document.getElementById('stopLiveBtn');
        const indicator = document.getElementById('liveStatus');
        
        if (isRunning) {
            stopBtn.disabled = false;
            indicator.className = 'live-indicator live-on';
            indicator.textContent = 'AUTO LIVE: ON (5s)';
        } else {
            stopBtn.disabled = true;
            indicator.className = 'live-indicator live-off';
            indicator.textContent = 'AUTO LIVE: OFF';
        }
    } catch (e) {
        // Silent fail
    }
}

async function sendToServer(isAutoSend = false) {
    if (!isExtensionContextValid()) return;
    if (!window.lastData) {
        if (!isAutoSend) {
            showError('No data to send! Extract first.');
        }
        return false;
    }
    
    try {
        const res = await fetch(`${SERVER_URL}/api/live-data`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(window.lastData)
        });
        
        if (res.ok) {
            document.getElementById('serverStatus').textContent = isAutoSend ? 'Server: Auto-sent ✓' : 'Server: Connected ✓';
            document.getElementById('serverStatus').style.color = '#28a745';
            document.getElementById('lastSent').textContent = `Last sent: ${new Date().toLocaleTimeString()}`;
            document.getElementById('lastRetry').textContent = isAutoSend ? document.getElementById('lastRetry').textContent : 'Last retry: manual';
            return true;
        }
    } catch (e) {
        document.getElementById('serverStatus').textContent = isAutoSend ? 'Server: Auto-send failed ✗' : 'Server: Failed ✗';
        document.getElementById('serverStatus').style.color = '#dc3545';
    }

    return false;
}

async function sendToServerWithRetry() {
    let attempt = 0;

    document.getElementById('lastRetry').textContent = 'Last retry: 0';

    while (attempt <= AUTO_SEND_RETRY_COUNT) {
        const sent = await sendToServer(true);
        if (sent) {
            document.getElementById('lastRetry').textContent = `Last retry: ${attempt}`;
            return true;
        }

        attempt += 1;

        if (attempt <= AUTO_SEND_RETRY_COUNT) {
            document.getElementById('serverStatus').textContent = `Server: Retry ${attempt}/${AUTO_SEND_RETRY_COUNT}`;
            document.getElementById('serverStatus').style.color = '#856404';
            document.getElementById('lastRetry').textContent = `Last retry: ${attempt}`;
            await delay(AUTO_SEND_RETRY_DELAY_MS);
        }
    }

    document.getElementById('serverStatus').textContent = 'Server: Auto-send failed after retry ✗';
    document.getElementById('serverStatus').style.color = '#dc3545';
    document.getElementById('lastRetry').textContent = `Last retry: ${AUTO_SEND_RETRY_COUNT}`;
    return false;
}

function showError(msg) {
    if (!isExtensionContextValid()) return;
    document.getElementById('errorBox').innerHTML = `<div class="error-box">${msg}</div>`;
}

function clearError() {
    if (!isExtensionContextValid()) return;
    document.getElementById('errorBox').innerHTML = '';
}
