const TELEGRAM_BOT_TOKEN = '8498249768:AAHuJNth3fhRlR4CBSfvb6eYOFnTzRVR0YA';
const TELEGRAM_CHAT_ID = '6801623296';
const TELEGRAM_API_URL = `https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage`;
const LIVE_INTERVAL_MS = 5000;
const REFRESH_SETTLE_MS = 1500;
const AUTO_SEND_RETRY_COUNT = 2;
const AUTO_SEND_RETRY_DELAY_MS = 1200;
const TARGET_HOST = 'g943gp.bpvmr7u6.com';
const LIVE_ALARM_NAME = 'bpvm-live-cycle';


let isLiveRunning = false;
let isLiveCycleRunning = false;
let currentTabId = null;
let lastAutoSentSignature = null;
let sentAlertKeys = new Set();

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

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function getMatchTeams(match) {
    return match?.teams || `${match?.homeTeam || 'Unknown'} vs ${match?.awayTeam || 'Unknown'}`;
}

function isSecondHalfOneZeroZero(match) {
    const status = String(match?.status || '').trim();
    const score = String(match?.score || '').trim();
    return /^2H\s+1'$/.test(status) && ['0-0', '0 - 0'].includes(score);
}

function createAlertKey(match) {
    return JSON.stringify({
        league: match?.league || 'N/A',
        teams: getMatchTeams(match)
    });
}

function formatMatchMessage(match) {
    const odds = Array.isArray(match?.odds) ? match.odds.slice(0, 3) : [];
    const lines = [
        '⚠️ <b>2H 1\' 0-0 ALERT</b>',
        '',
        `⚽ <b>${escapeHtml(getMatchTeams(match))}</b>`,
        `📊 Score: <b>${escapeHtml(match?.score || '0-0')}</b>`,
        `🏆 League: ${escapeHtml(match?.league || 'N/A')}`,
        `⏰ Status: ${escapeHtml(match?.status || 'Live')}`,
        `📅 Time: ${new Date().toLocaleTimeString()}`,
        '',
        '🔥 <i>Masuk 2H 1\' dan skor masih 0-0.</i>'
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
    const data = await chrome.storage.local.get(['liveRuntimeState']);
    const runtimeState = data.liveRuntimeState || {};

    isLiveRunning = Boolean(runtimeState.isLiveRunning);
    currentTabId = Number.isInteger(runtimeState.currentTabId) ? runtimeState.currentTabId : null;

    if (isLiveRunning) {
        await chrome.alarms.create(LIVE_ALARM_NAME, {
            periodInMinutes: 0.1
        });
    }
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

        const alertMatches = matches.filter((match) => isSecondHalfOneZeroZero(match) && !sentAlertKeys.has(createAlertKey(match)));
        if (!alertMatches.length) {
            await setStatus({
                serverStatus: 'Telegram: No 2H 1\' 0-0 alert',
                error: ''
            });
            return false;
        }

        let sentCount = 0;
        for (const match of alertMatches) {
            const res = await fetch(TELEGRAM_API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    chat_id: TELEGRAM_CHAT_ID,
                    text: formatMatchMessage(match),
                    parse_mode: 'HTML'
                })
            });

            if (!res.ok) {
                throw new Error(`Telegram API error ${res.status}`);
            }

            sentAlertKeys.add(createAlertKey(match));
            sentCount += 1;
        }

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

async function handleFreshData(data) {
    await setSavedMatchData(data);
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
            await updateLiveState(false);
            await setStatus({
                pageStatus: '✗ Not on target page',
                error: 'Target tab not found. Open the BPVM page to keep auto live running.'
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
        'liveStatus',
        'liveRuntimeState'
    ]);

    return {
        ok: true,
        data: {
            matches: data.lastMatches || [],
            groupedMatches: data.lastGroupedMatches || [],
            count: data.lastCount || 0,
            time: data.lastUpdate || '-',
            liveStatus: data.liveStatus || null,
            runtimeState: data.liveRuntimeState || {
                isLiveRunning: false,
                currentTabId: null
            }
        }
    };
}

chrome.runtime.onInstalled.addListener(() => {
    chrome.storage.local.set({
        liveRuntimeState: {
            isLiveRunning: false,
            currentTabId: null
        },
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
            default:
                sendResponse({ ok: false, error: 'Unknown action' });
        }
    })().catch((error) => {
        sendResponse({ ok: false, error: error.message || 'Unhandled background error' });
    });

    return true;
});
