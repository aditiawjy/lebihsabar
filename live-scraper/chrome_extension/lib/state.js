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
let sentP7Signals = new Set();
let sentP14Signals = new Set();
let sentP19Signals = new Set();
let last1HGoalMinByMatchKey = new Map();
let first1HGoalMinByMatchKey = new Map();
let all1HGoalMinsByMatchKey = new Map();
let all1HScorersByMatchKey = new Map();
let has2HGoalByMatchKey = new Map();
let all2HGoalMinsByMatchKey = new Map();
let all2HScorersByMatchKey = new Map();


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
        chrome.storage.local.get(['liveRuntimeState', 'sentNG1Keys', 'sentP7Keys', 'sentP14Keys', 'sentP19Keys']),
        chrome.storage.session.get(['kickoffTimes', 'registeredKeys', 'sentMilestoneKeys', 'lastScores', 'shScores', 'last1HGoalMins', 'first1HGoalMins', 'all1HGoalMins', 'all1HScorers', 'has2HGoals', 'all2HGoalMins', 'all2HScorers'])
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
    if (Array.isArray(localData.sentP7Keys)) {
        sentP7Signals = new Set(localData.sentP7Keys);
    }
    if (Array.isArray(localData.sentP14Keys)) {
        sentP14Signals = new Set(localData.sentP14Keys);
    }
    if (Array.isArray(localData.sentP19Keys)) {
        sentP19Signals = new Set(localData.sentP19Keys);
    }
    if (sessionData.lastScores) {
        lastScoreByMatchKey = new Map(Object.entries(sessionData.lastScores));
    }
    if (sessionData.shScores) {
        shScoreByMatchKey = new Map(Object.entries(sessionData.shScores));
    }
    if (sessionData.last1HGoalMins) {
        last1HGoalMinByMatchKey = new Map(Object.entries(sessionData.last1HGoalMins).map(([k, v]) => [k, Number(v)]));
    }
    if (sessionData.first1HGoalMins) {
        first1HGoalMinByMatchKey = new Map(Object.entries(sessionData.first1HGoalMins).map(([k, v]) => [k, Number(v)]));
    }
    if (sessionData.all1HGoalMins) {
        all1HGoalMinsByMatchKey = new Map(Object.entries(sessionData.all1HGoalMins).map(([k, arr]) => [k, Array.isArray(arr) ? arr.map((v) => Number(v)) : []]));
    }
    if (sessionData.all1HScorers) {
        all1HScorersByMatchKey = new Map(Object.entries(sessionData.all1HScorers));
    }
    if (sessionData.has2HGoals) {
        has2HGoalByMatchKey = new Map(Object.entries(sessionData.has2HGoals));
    }
    if (sessionData.all2HGoalMins) {
        all2HGoalMinsByMatchKey = new Map(Object.entries(sessionData.all2HGoalMins).map(([k, arr]) => [k, Array.isArray(arr) ? arr.map((v) => Number(v)) : []]));
    }
    if (sessionData.all2HScorers) {
        all2HScorersByMatchKey = new Map(Object.entries(sessionData.all2HScorers));
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
            lastScores: Object.fromEntries(lastScoreByMatchKey),
            shScores: Object.fromEntries(shScoreByMatchKey),
            last1HGoalMins: Object.fromEntries(last1HGoalMinByMatchKey),
            first1HGoalMins: Object.fromEntries(first1HGoalMinByMatchKey),
            all1HGoalMins: Object.fromEntries(all1HGoalMinsByMatchKey),
            all1HScorers: Object.fromEntries(all1HScorersByMatchKey),
            has2HGoals: Object.fromEntries(has2HGoalByMatchKey),
            all2HGoalMins: Object.fromEntries(all2HGoalMinsByMatchKey),
            all2HScorers: Object.fromEntries(all2HScorersByMatchKey),
        }),
        chrome.storage.local.set({
            sentNG1Keys: [...sentNG1Signals],
            sentP7Keys: [...sentP7Signals],
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
