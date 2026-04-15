importScripts(
    'lib/utils.js',
    'lib/constants.js',
    'lib/telegram.js',
    'lib/state.js',
    'lib/odd-tracker.js',
    'lib/signals.js'
);

const DASHBOARD_API_URL = 'http://127.0.0.1:5000/api/dashboard-live-data';


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

async function keepTargetTabAlive(tab) {
    if (!tab?.id) {
        return null;
    }

    try {
        return await chrome.tabs.update(tab.id, { autoDiscardable: false });
    } catch (_) {
        return tab;
    }
}

async function ensureTargetTabReady(tab) {
    const protectedTab = await keepTargetTabAlive(tab);
    if (!protectedTab?.id) {
        return null;
    }

    if (!protectedTab.discarded) {
        return protectedTab;
    }

    await chrome.tabs.reload(protectedTab.id);
    await delay(REFRESH_SETTLE_MS + 1000);
    return chrome.tabs.get(protectedTab.id);
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

function createDataSignature(data) {
    if (!data?.matches) {
        return null;
    }

    return JSON.stringify({
        count: data.count,
        matches: data.matches
    });
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

async function sendDashboardLiveData(data) {
    const matches = Array.isArray(data?.matches) ? data.matches : [];
    if (!matches.length) return false;

    try {
        await fetch(DASHBOARD_API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                matches,
                allGoalMinutes: Object.fromEntries(all1HGoalMinsByMatchKey),
                allGoalScorers: Object.fromEntries(all1HScorersByMatchKey),
                all2HGoalMinutes: Object.fromEntries(all2HGoalMinsByMatchKey),
                all2HScorers: Object.fromEntries(all2HScorersByMatchKey),
                htScores: Object.fromEntries(shScoreByMatchKey),
                timestamp: new Date().toISOString()
            })
        });
        return true;
    } catch (_) {
        return false;
    }
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
    await trackGoalEvents(Array.isArray(data?.matches) ? data.matches : []);
    await trackNG1Signal(Array.isArray(data?.matches) ? data.matches : []);
    await trackHT22Signal(Array.isArray(data?.matches) ? data.matches : []);
    await trackP7Signal(Array.isArray(data?.matches) ? data.matches : []);
    await trackP14Signal(Array.isArray(data?.matches) ? data.matches : []);
    await trackP19Signal(Array.isArray(data?.matches) ? data.matches : []);
    await persistMatchState();
    await sendDashboardLiveData(data);
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
        const foundTab = await getTargetTab();
        const targetTab = await ensureTargetTabReady(foundTab);
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
            error: foundTab?.discarded ? 'Target tab was discarded and has been reloaded.' : ''
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
    const foundTab = await getTargetTab();
    const targetTab = await ensureTargetTabReady(foundTab);
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
        'liveHtScores',
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
            htScores: data.liveHtScores || {},
            liveStatus: data.liveStatus || null,
            customWatchConfig: data[CUSTOM_WATCH_CONFIG_KEY] || getDefaultCustomWatchConfig(),
            runtimeState: data.liveRuntimeState || {
                isLiveRunning: false,
                currentTabId: null
            },
            goalMinutes: Object.fromEntries(first1HGoalMinByMatchKey),
            allGoalMinutes: Object.fromEntries(all1HGoalMinsByMatchKey),
            all2HGoalMinutes: Object.fromEntries(all2HGoalMinsByMatchKey)
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
