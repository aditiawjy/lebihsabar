async function sendTelegramText(text) {
    try {
        const res = await fetch(TELEGRAM_API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ chat_id: TELEGRAM_CHAT_ID, text, parse_mode: 'HTML' })
        });
        const body = await res.json().catch(() => ({}));
        if (res.ok && body && body.ok) {
            return { ok: true };
        }
        return { ok: false, error: (body && body.description) || `HTTP ${res.status}` };
    } catch (e) {
        return { ok: false, error: e.message || 'network error' };
    }
}
