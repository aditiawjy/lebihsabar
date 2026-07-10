// h2h-notify.js
// Cek endpoint h2h_today_api.php (match hari ini dengan rekor H2H Over 0.5 kuat)
// lalu kirim notifikasi Telegram. Dedup per match per hari via chrome.storage.

const H2H_NOTIFY_STATE_KEY = 'h2hNotifyState';
const H2H_NOTIFY_STATUS_KEY = 'h2hNotifyStatus';
const H2H_WATCH_KEY = 'h2hWatchToday';
const H2H_LIVE_ALERT_KEY = 'h2hLiveAlertState';

// Kunci pasangan tim yang tahan beda urutan & variasi nama (mis. "(V)").
function h2hPairKey(a, b) {
    const x = normalizeTeamName(a);
    const y = normalizeTeamName(b);
    return [x, y].sort().join(' | ');
}

function h2hNowStr() {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${p(d.getDate())}/${p(d.getMonth() + 1)} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}

// Simpan status terakhir agar bisa dilihat di popup (bukti terkirim / error).
async function setH2HStatus(patch) {
    const data = await chrome.storage.local.get([H2H_NOTIFY_STATUS_KEY]);
    const cur = data[H2H_NOTIFY_STATUS_KEY] || {};
    await chrome.storage.local.set({ [H2H_NOTIFY_STATUS_KEY]: { ...cur, ...patch } });
}

function h2hEscapeHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

async function getH2HNotifyState(today) {
    const data = await chrome.storage.local.get([H2H_NOTIFY_STATE_KEY]);
    const st = data[H2H_NOTIFY_STATE_KEY];
    // Reset bila beda hari
    if (!st || st.date !== today) {
        return { date: today, sent: [] };
    }
    return { date: st.date, sent: Array.isArray(st.sent) ? st.sent : [] };
}

async function saveH2HNotifyState(state) {
    await chrome.storage.local.set({ [H2H_NOTIFY_STATE_KEY]: state });
}

function buildH2HMessage(matches) {
    const lines = [`⚽ <b>H2H Over 0.5 — Match Hari Ini</b>`];
    for (const m of matches) {
        lines.push(
            `\n🕒 <b>${h2hEscapeHtml(m.time)}</b> · ${h2hEscapeHtml(m.home)} vs ${h2hEscapeHtml(m.away)}` +
            `\n   📊 ${m.pct}% (${m.hits}/${m.total}) · ${h2hEscapeHtml(m.league)}`
        );
    }
    return lines.join('\n');
}

// Dipanggil dari alarm / startup. Mengirim hanya match yang belum dinotif hari ini.
async function checkH2HTodayAndNotify() {
    let resp;
    try {
        const url = `${H2H_API_URL}?min=${H2H_MIN_MATCHES}&pct=${H2H_MIN_PCT}&mkt=${H2H_MARKET}`;
        const res = await fetch(url, { cache: 'no-store' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        resp = await res.json();
    } catch (e) {
        // Endpoint offline (XAMPP mati) — catat error, coba lagi siklus berikutnya.
        const err = e.message || 'fetch failed';
        await setH2HStatus({ lastCheck: h2hNowStr(), ok: false, error: `Endpoint: ${err}`, found: '-', sent: 0 });
        return { ok: false, error: err };
    }

    if (!resp || !resp.ok || !Array.isArray(resp.matches)) {
        await setH2HStatus({ lastCheck: h2hNowStr(), ok: false, error: 'Respons endpoint tidak valid', found: '-', sent: 0 });
        return { ok: false, error: 'bad response' };
    }

    const today = resp.date;

    // Simpan watch-list hari ini (untuk panel popup + alert live 0-0 babak 2).
    await chrome.storage.local.set({
        [H2H_WATCH_KEY]: { date: today, matches: resp.matches },
    });

    const state = await getH2HNotifyState(today);
    const sentSet = new Set(state.sent);

    // Match yang lolos ambang & belum dikirim hari ini
    const fresh = resp.matches.filter((m) => !sentSet.has(m.key));
    if (fresh.length === 0) {
        await setH2HStatus({
            lastCheck: h2hNowStr(), ok: true, error: '',
            found: resp.matches.length, sent: 0,
            note: resp.matches.length > 0 ? 'Semua sudah dinotif hari ini' : 'Belum ada match lolos',
        });
        return { ok: true, sent: 0 };
    }

    // Pecah jadi batch agar tidak melebihi batas 4096 karakter Telegram.
    const BATCH = 12;
    let sentOk = 0;
    let lastErr = '';
    for (let i = 0; i < fresh.length; i += BATCH) {
        const slice = fresh.slice(i, i + BATCH);
        const r = await sendTelegramText(buildH2HMessage(slice));
        if (r && r.ok) {
            sentOk += slice.length;
            for (const m of slice) sentSet.add(m.key); // tandai terkirim hanya jika sukses
        } else {
            lastErr = (r && r.error) || 'gagal kirim';
        }
    }

    await saveH2HNotifyState({ date: today, sent: Array.from(sentSet) });
    await setH2HStatus({
        lastCheck: h2hNowStr(),
        ok: lastErr === '',
        error: lastErr ? `Telegram: ${lastErr}` : '',
        found: resp.matches.length,
        sent: sentOk,
        lastSentTime: sentOk > 0 ? h2hNowStr() : undefined,
    });

    return { ok: lastErr === '', sent: sentOk, error: lastErr || undefined };
}

// === Alert LIVE: match watch sedang main, masih 0-0, di awal babak 2 ========

let h2hWatchCache = null; // { date, pairMap: Map(pairKey -> meta) }

async function loadH2HWatchMap() {
    const today = new Date().toISOString().slice(0, 10); // tanggal lokal cukup utk dedup
    if (h2hWatchCache && h2hWatchCache.date === today) return h2hWatchCache.pairMap;

    const data = await chrome.storage.local.get([H2H_WATCH_KEY]);
    const wl = data[H2H_WATCH_KEY];
    const pairMap = new Map();
    if (wl && Array.isArray(wl.matches)) {
        for (const m of wl.matches) {
            pairMap.set(h2hPairKey(m.home, m.away), m);
        }
    }
    h2hWatchCache = { date: wl?.date || today, pairMap };
    return pairMap;
}

async function getLiveAlertState(today) {
    const data = await chrome.storage.local.get([H2H_LIVE_ALERT_KEY]);
    const st = data[H2H_LIVE_ALERT_KEY];
    if (!st || st.date !== today) return { date: today, sent: [] };
    return { date: st.date, sent: Array.isArray(st.sent) ? st.sent : [] };
}

function buildLiveAlertMessage(meta, match) {
    const home = escapeHtml(match?.homeTeam || meta.home);
    const away = escapeHtml(match?.awayTeam || meta.away);
    return (
        `🔥 <b>LIVE 0-0 babak 2 — H2H Over 0.5</b>\n\n` +
        `⚽ ${home} vs ${away}\n` +
        `⏱️ ${escapeHtml(match?.status || '2H')} · skor masih <b>0-0</b>\n` +
        `📊 Rekor H2H Over 0.5: <b>${meta.pct}%</b> (${meta.hits}/${meta.total})\n` +
        `${escapeHtml(meta.league || '')}`
    );
}

// Dipanggil tiap siklus live (handleFreshData). Kirim sekali per match per hari.
async function trackH2HLive0to0SecondHalf(matches) {
    if (!Array.isArray(matches) || matches.length === 0) return;
    const pairMap = await loadH2HWatchMap();
    if (pairMap.size === 0) return;

    const today = new Date().toISOString().slice(0, 10);
    const st = await getLiveAlertState(today);
    const sentSet = new Set(st.sent);
    let changed = false;

    for (const m of matches) {
        const { half, min } = parseMatchMinute(m?.status);
        if (half !== '2H') continue;
        if (min < H2H_LIVE_2H_MIN_FROM || min > H2H_LIVE_2H_MIN_TO) continue;
        const sc = parseScoreTuple(m?.score || '0-0');
        if (sc.total !== 0) continue; // harus masih 0-0

        const pk = h2hPairKey(m?.homeTeam, m?.awayTeam);
        const meta = pairMap.get(pk);
        if (!meta) continue;             // bukan match watch >= ambang
        if (sentSet.has(pk)) continue;   // sudah dialert hari ini

        const r = await sendTelegramText(buildLiveAlertMessage(meta, m));
        if (r && r.ok) {
            sentSet.add(pk);
            changed = true;
            await setH2HStatus({ lastLiveAlert: `${meta.home} vs ${meta.away} @ ${h2hNowStr()}` });
        }
    }

    if (changed) {
        await chrome.storage.local.set({ [H2H_LIVE_ALERT_KEY]: { date: today, sent: Array.from(sentSet) } });
    }
}

// Pastikan alarm periodik terdaftar.
async function ensureH2HAlarm() {
    const existing = await chrome.alarms.get(H2H_ALARM_NAME);
    if (!existing) {
        chrome.alarms.create(H2H_ALARM_NAME, {
            delayInMinutes: 1,
            periodInMinutes: H2H_POLL_MINUTES,
        });
    }
}
