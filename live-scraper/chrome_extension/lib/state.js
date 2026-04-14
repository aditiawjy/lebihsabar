let isLiveRunning = false;
let isLiveCycleRunning = false;
let currentTabId = null;
let lastAutoSentSignature = null;
let notifiedMatchKeys = new Set();
let wasAboveThresholdByMatchKey = new Map();
let oddHistoryByMatchKey = new Map();
let oddInsightByMatchKey = new Map();
let lastScoreByMatchKey = new Map();
let registeredMatchKeys = new Set();
let kickoffTimeByMatchKey = new Map();
let shScoreByMatchKey = new Map();
let sentMilestones = new Set();
let sentNG1Signals = new Set();
let sentHT22Signals = new Set();
let sentP14Signals = new Set();
let sentP19Signals = new Set();
let last1HGoalMinByMatchKey = new Map();
let first1HGoalMinByMatchKey = new Map();
let all1HGoalMinsByMatchKey = new Map();
let all1HScorersByMatchKey = new Map();
let has2HGoalByMatchKey = new Map();
let all2HGoalMinsByMatchKey = new Map();


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
        chrome.storage.local.get(['liveRuntimeState', 'sentNG1Keys', 'sentP14Keys', 'sentP19Keys']),
        chrome.storage.session.get(['kickoffTimes', 'registeredKeys', 'sentMilestoneKeys', 'last1HGoalMins', 'all1HScorers', 'has2HGoals'])
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
    if (Array.isArray(localData.sentNG1Keys)) {
        sentNG1Signals = new Set(localData.sentNG1Keys);
    }
    if (Array.isArray(localData.sentP14Keys)) {
        sentP14Signals = new Set(localData.sentP14Keys);
    }
    if (Array.isArray(localData.sentP19Keys)) {
        sentP19Signals = new Set(localData.sentP19Keys);
    }
    if (sessionData.last1HGoalMins) {
        last1HGoalMinByMatchKey = new Map(Object.entries(sessionData.last1HGoalMins).map(([k, v]) => [k, Number(v)]));
    }
    if (sessionData.all1HScorers) {
        all1HScorersByMatchKey = new Map(Object.entries(sessionData.all1HScorers));
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
    await Promise.all([
        chrome.storage.session.set({
            kickoffTimes: Object.fromEntries(kickoffTimeByMatchKey),
            registeredKeys: [...registeredMatchKeys],
            sentMilestoneKeys: [...sentMilestones],
            last1HGoalMins: Object.fromEntries(last1HGoalMinByMatchKey),
            all1HScorers: Object.fromEntries(all1HScorersByMatchKey),
            has2HGoals: Object.fromEntries(has2HGoalByMatchKey),
        }),
        chrome.storage.local.set({
            sentNG1Keys: [...sentNG1Signals],
            sentP14Keys: [...sentP14Signals],
            sentP19Keys: [...sentP19Signals],
        }),
    ]);
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
