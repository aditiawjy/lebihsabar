// Background worker: receives goal-log events from content.js and POSTs them to
// goal-log-save-vsoccer.php. Failed sends are queued in storage and retried.

const GOAL_LOG_SAVE_URL = 'http://localhost/lebihsabar/goal-log-save-vsoccer.php';
const QUEUE_KEY = 'vsoccerGoalQueue';
const QUEUE_MAX = 300;

async function postPayload(payload) {
    const resp = await fetch(GOAL_LOG_SAVE_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    return resp.json();
}

async function enqueue(payload) {
    const stored = await chrome.storage.local.get([QUEUE_KEY]);
    const queue = Array.isArray(stored[QUEUE_KEY]) ? stored[QUEUE_KEY] : [];
    queue.push(payload);
    await chrome.storage.local.set({ [QUEUE_KEY]: queue.slice(-QUEUE_MAX) });
}

async function flushQueue() {
    const stored = await chrome.storage.local.get([QUEUE_KEY]);
    const queue = Array.isArray(stored[QUEUE_KEY]) ? stored[QUEUE_KEY] : [];
    if (!queue.length) return;

    const remaining = [];
    for (const payload of queue) {
        try {
            await postPayload(payload);
        } catch (_) {
            remaining.push(payload);
        }
    }
    await chrome.storage.local.set({ [QUEUE_KEY]: remaining.slice(-QUEUE_MAX) });
}

async function handleGoalLog(payload) {
    try {
        await postPayload(payload);
        await flushQueue();
    } catch (_) {
        await enqueue(payload);
    }
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (message?.action === 'vsoccerGoalLog' && message.payload) {
        handleGoalLog(message.payload).then(() => sendResponse({ ok: true }));
        return true; // async response
    }
    return false;
});

// Periodically retry any queued sends.
chrome.alarms.create('vsoccerFlush', { periodInMinutes: 1 });
chrome.alarms.onAlarm.addListener((alarm) => {
    if (alarm.name === 'vsoccerFlush') flushQueue();
});
