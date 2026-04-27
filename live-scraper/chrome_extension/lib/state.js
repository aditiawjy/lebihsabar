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
let sentP28Signals = new Set();
let sentP727374Signals = new Set();
let p727374SignalsByMatchKey = new Map();
let last1HGoalMinByMatchKey = new Map();
let first1HGoalMinByMatchKey = new Map();
let all1HGoalMinsByMatchKey = new Map();
let all1HScorersByMatchKey = new Map();
let has2HGoalByMatchKey = new Map();
let all2HGoalMinsByMatchKey = new Map();
let all2HScorersByMatchKey = new Map();
let lastSeenAtByMatchKey = new Map();
let lastStatusByMatchKey = new Map();
let lastTargetTabReloadAt = 0;

function getLiveStateRetentionMs() {
    return typeof MATCH_STATE_RETENTION_MS === 'number' ? MATCH_STATE_RETENTION_MS : 2 * 60 * 60 * 1000;
}

function getLiveStateMaxKeys() {
    return typeof MATCH_STATE_MAX_KEYS === 'number' ? MATCH_STATE_MAX_KEYS : 250;
}

function pruneSetByKnownMatches(sourceSet) {
    return new Set(Array.from(sourceSet || []).filter((key) => registeredMatchKeys.has(key)));
}

function pruneMilestoneSetByKnownMatches(sourceSet) {
    return new Set(Array.from(sourceSet || []).filter((key) => {
        const text = String(key);
        const matchKey = text.slice(0, text.lastIndexOf('|'));
        return matchKey && registeredMatchKeys.has(matchKey);
    }));
}

function prunePatternSignalSetByKnownMatches(sourceSet) {
    return new Set(Array.from(sourceSet || []).filter((key) => {
        const text = String(key);
        const matchKey = text.slice(0, text.indexOf('|'));
        return matchKey && registeredMatchKeys.has(matchKey);
    }));
}

function pruneLongRunningMatchState(activeMatches = [], nowMs = Date.now()) {
    const activeKeys = new Set((Array.isArray(activeMatches) ? activeMatches : []).map((match) => createMatchKey(match)));
    const retentionMs = getLiveStateRetentionMs();
    const staleKeys = [];

    for (const key of registeredMatchKeys) {
        const lastSeenAt = lastSeenAtByMatchKey.get(key) || 0;
        if (!activeKeys.has(key) && lastSeenAt && nowMs - lastSeenAt > retentionMs) {
            staleKeys.push(key);
        }
    }

    const maxKeys = getLiveStateMaxKeys();
    if (registeredMatchKeys.size - staleKeys.length > maxKeys) {
        const keep = new Set(staleKeys);
        Array.from(registeredMatchKeys)
            .filter((key) => !activeKeys.has(key) && !keep.has(key))
            .sort((a, b) => (lastSeenAtByMatchKey.get(a) || 0) - (lastSeenAtByMatchKey.get(b) || 0))
            .slice(0, registeredMatchKeys.size - staleKeys.length - maxKeys)
            .forEach((key) => staleKeys.push(key));
    }

    staleKeys.forEach((key) => resetMatchTrackingState(key));

    sentNG1Signals = pruneSetByKnownMatches(sentNG1Signals);
    sentHT22Signals = pruneSetByKnownMatches(sentHT22Signals);
    sentP7Signals = pruneSetByKnownMatches(sentP7Signals);
    sentP14Signals = pruneSetByKnownMatches(sentP14Signals);
    sentP19Signals = pruneSetByKnownMatches(sentP19Signals);
    sentP28Signals = pruneSetByKnownMatches(sentP28Signals);
    sentP727374Signals = prunePatternSignalSetByKnownMatches(sentP727374Signals);
    sentMilestones = pruneMilestoneSetByKnownMatches(sentMilestones);

    return staleKeys.length;
}

function objectFromMapForActiveMatches(map, activeKeys) {
    return Object.fromEntries(Array.from(map.entries()).filter(([key]) => activeKeys.has(key)));
}

function buildActiveMatchStatePayload(matches = []) {
    const activeKeys = new Set((Array.isArray(matches) ? matches : []).map((match) => createMatchKey(match)));

    return {
        activeKeys,
        allGoalMinutes: objectFromMapForActiveMatches(all1HGoalMinsByMatchKey, activeKeys),
        allGoalScorers: objectFromMapForActiveMatches(all1HScorersByMatchKey, activeKeys),
        all2HGoalMinutes: objectFromMapForActiveMatches(all2HGoalMinsByMatchKey, activeKeys),
        all2HScorers: objectFromMapForActiveMatches(all2HScorersByMatchKey, activeKeys),
        patternSignals: objectFromMapForActiveMatches(p727374SignalsByMatchKey, activeKeys),
        htScores: objectFromMapForActiveMatches(shScoreByMatchKey, activeKeys)
    };
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
    const [localData, sessionData] = await Promise.all([
        chrome.storage.local.get(['liveRuntimeState', 'sentNG1Keys', 'sentP7Keys', 'sentP14Keys', 'sentP19Keys', 'sentP28Keys', 'sentP727374Keys']),
        chrome.storage.session.get(['kickoffTimes', 'registeredKeys', 'sentMilestoneKeys', 'lastScores', 'shScores', 'last1HGoalMins', 'first1HGoalMins', 'all1HGoalMins', 'all1HScorers', 'has2HGoals', 'all2HGoalMins', 'all2HScorers', 'p727374Signals', 'lastSeenAt', 'lastStatuses'])
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
    if (Array.isArray(localData.sentP28Keys)) {
        sentP28Signals = new Set(localData.sentP28Keys);
    }
    if (Array.isArray(localData.sentP727374Keys)) {
        sentP727374Signals = new Set(localData.sentP727374Keys);
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
        all1HGoalMinsByMatchKey = new Map(Object.entries(sessionData.all1HGoalMins).map(([k, v]) => [k, Array.isArray(v) ? v.map((min) => Number(min)).filter((min) => Number.isFinite(min)) : []]));
    }
    if (sessionData.all1HScorers) {
        all1HScorersByMatchKey = new Map(Object.entries(sessionData.all1HScorers));
    }
    if (sessionData.has2HGoals) {
        has2HGoalByMatchKey = new Map(Object.entries(sessionData.has2HGoals));
    }
    if (sessionData.all2HGoalMins) {
        all2HGoalMinsByMatchKey = new Map(Object.entries(sessionData.all2HGoalMins).map(([k, v]) => [k, Array.isArray(v) ? v.map((min) => Number(min)).filter((min) => Number.isFinite(min)) : []]));
    }
    if (sessionData.all2HScorers) {
        all2HScorersByMatchKey = new Map(Object.entries(sessionData.all2HScorers));
    }
    if (sessionData.lastSeenAt) {
        lastSeenAtByMatchKey = new Map(Object.entries(sessionData.lastSeenAt).map(([k, v]) => [k, Number(v)]));
    }
    if (sessionData.lastStatuses) {
        lastStatusByMatchKey = new Map(Object.entries(sessionData.lastStatuses));
    }
    if (sessionData.p727374Signals) {
        p727374SignalsByMatchKey = new Map(Object.entries(sessionData.p727374Signals));
    }

    if (isLiveRunning) {
        await chrome.alarms.create(LIVE_ALARM_NAME, {
            periodInMinutes: 0.1
        });
    }
}

async function persistMatchState(activeMatches = []) {
    pruneLongRunningMatchState(activeMatches);

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
            p727374Signals: Object.fromEntries(p727374SignalsByMatchKey),
            lastSeenAt: Object.fromEntries(lastSeenAtByMatchKey),
            lastStatuses: Object.fromEntries(lastStatusByMatchKey),
        }),
        chrome.storage.local.set({
            sentNG1Keys: [...sentNG1Signals],
            sentP7Keys: [...sentP7Signals],
            sentP14Keys: [...sentP14Signals],
            sentP19Keys: [...sentP19Signals],
            sentP28Keys: [...sentP28Signals],
            sentP727374Keys: [...sentP727374Signals],
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
