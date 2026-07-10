// streak-notify.js
// Market percobaan: "Kalah 2x beruntun -> Over 1.5".
// 1) Refresh watch-list dari streak_kl2_api.php (tim sedang kalah >=2x, Over1.5 historis tinggi).
// 2) Saat match tim watch sedang main DAN odd Over 1.75 >= target -> kirim Telegram (sekali/hari).

const STREAK_WATCH_KEY = 'streakWatchToday';
const STREAK_STATUS_KEY = 'streakNotifyStatus';
const STREAK_LIVE_ALERT_KEY = 'streakLiveAlertState';

async function setStreakStatus(patch) {
    const data = await chrome.storage.local.get([STREAK_STATUS_KEY]);
    const cur = data[STREAK_STATUS_KEY] || {};
    await chrome.storage.local.set({ [STREAK_STATUS_KEY]: { ...cur, ...patch } });
}

// Ambil watch-list terbaru dari endpoint PHP.
async function checkStreakWatchRefresh() {
    // Dua market: kalah 2x, dan under 1.5 3x beruntun. Keduanya -> Over 1.5.
    const modes = [
        { mode: 'kl2',   out: 'o15', pct: STREAK_MIN_PCT,        min: STREAK_MIN_SAMPLE,        label: 'Kalah 2x → Over 1.5' },
        { mode: 'u15x3', out: 'o15', pct: STREAK_U15_MIN_PCT,    min: STREAK_U15_MIN_SAMPLE,    label: 'Under 1.5 3x → Over 1.5' },
        { mode: 'dr2',   out: 'o15', pct: STREAK_DR_MIN_PCT,     min: STREAK_DR_MIN_SAMPLE,     label: 'Draw 2x → Over 1.5' },
        { mode: 'dr2',   out: 'o05', pct: STREAK_DR_O05_MIN_PCT,  min: STREAK_DR_O05_MIN_SAMPLE,  label: 'Draw 2x → Over 0.5' },
        { mode: 'dr3',   out: 'o05', pct: STREAK_DR3_O05_MIN_PCT, min: STREAK_DR3_O05_MIN_SAMPLE, label: 'Draw 3x → Over 0.5' },
        { mode: 'u05x2', out: 'o05', pct: STREAK_U05_MIN_PCT,     min: STREAK_U05_MIN_SAMPLE,     label: 'Under 0.5 2x → Over 0.5' },
    ];
    let combined = [];
    let date = new Date().toISOString().slice(0, 10);
    for (const m of modes) {
        let resp;
        try {
            const url = `${STREAK_API_URL}?mode=${m.mode}&out=${m.out}&pct=${m.pct}&min=${m.min}`;
            const res = await fetch(url, { cache: 'no-store' });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            resp = await res.json();
        } catch (e) {
            await setStreakStatus({ lastCheck: h2hNowStr(), ok: false, error: `Endpoint(${m.mode}/${m.out}): ${e.message || 'fetch failed'}`, count: '-' });
            return { ok: false, error: e.message || 'fetch failed' };
        }
        if (!resp || !resp.ok || !Array.isArray(resp.teams)) {
            await setStreakStatus({ lastCheck: h2hNowStr(), ok: false, error: `Respons ${m.mode}/${m.out} tidak valid`, count: '-' });
            return { ok: false, error: 'bad response' };
        }
        date = resp.date || date;
        for (const t of resp.teams) combined.push({ ...t, market: m.mode, out: m.out, marketLabel: m.label });
    }
    await chrome.storage.local.set({ [STREAK_WATCH_KEY]: { date, teams: combined } });
    streakWatchCache = null; // paksa reload cache
    await setStreakStatus({ lastCheck: h2hNowStr(), ok: true, error: '', count: combined.length });
    return { ok: true, count: combined.length };
}

// === Alert LIVE berbasis odd ===============================================

let streakWatchCache = null; // { date, teamMap: Map(normalizedTeam -> meta) }

async function loadStreakWatchMap() {
    const today = new Date().toISOString().slice(0, 10);
    if (streakWatchCache && streakWatchCache.date === today) return streakWatchCache.teamMap;
    const data = await chrome.storage.local.get([STREAK_WATCH_KEY]);
    const wl = data[STREAK_WATCH_KEY];
    const teamMap = new Map(); // normalizedTeam -> [meta, ...] (satu tim bisa di banyak market)
    if (wl && Array.isArray(wl.teams)) {
        for (const t of wl.teams) {
            const k = normalizeTeamName(t.team);
            if (!teamMap.has(k)) teamMap.set(k, []);
            teamMap.get(k).push(t);
        }
    }
    streakWatchCache = { date: wl?.date || today, teamMap };
    return teamMap;
}

async function getStreakLiveAlertState(today) {
    const data = await chrome.storage.local.get([STREAK_LIVE_ALERT_KEY]);
    const st = data[STREAK_LIVE_ALERT_KEY];
    if (!st || st.date !== today) return { date: today, sent: [] };
    return { date: st.date, sent: Array.isArray(st.sent) ? st.sent : [] };
}

// Cari odd Over 1.75 (coba market live 'o/u' lalu 'ft.o/u').
function getStreakOverOdd(match) {
    let odd = getTargetOverOdd(match, STREAK_ODD_SELECTION, TARGET_ODD_MARKET);
    if (!odd) odd = getTargetOverOdd(match, STREAK_ODD_SELECTION, TARGET_FT_ODD_MARKET);
    return odd; // {marketName,label,oddValue} | null
}

function buildStreakAlertMessage(meta, match, odd) {
    const home = escapeHtml(match?.homeTeam || '?');
    const away = escapeHtml(match?.awayTeam || '?');
    const label = escapeHtml(meta.marketLabel || 'Streak → Over 1.5');
    const reason = meta.market === 'u15x3' ? 'Under 1.5 3x beruntun'
        : (meta.market === 'dr2' ? 'Draw 2x beruntun' : 'Kalah 2x beruntun');
    const oddLine = (odd && isFinite(odd.oddValue))
        ? `💱 ${escapeHtml(odd.label || 'Over')} @ <b>${odd.oddValue.toFixed(2)}</b>\n`
        : `⏳ Over 1.5 masih hidup (skor < 2)\n`;
    return (
        `🟠 <b>${label} (LIVE)</b>\n\n` +
        `⚽ ${home} vs ${away}\n` +
        `⏱️ ${escapeHtml(match?.status || '-')} · skor ${escapeHtml(match?.score || '0-0')}\n` +
        `🎯 Tim ${escapeHtml(reason)}: <b>${escapeHtml(meta.team)}</b>\n` +
        `📊 Over 1.5 setelah pola ini: <b>${meta.pct}%</b> (${meta.over}/${meta.total})\n` +
        oddLine +
        `${escapeHtml(meta.league || '')}`
    );
}

// Pesan untuk market Over 0.5 (tanpa odd; trigger 0-0 di babak 2).
function buildStreakAlertMessageO05(meta, match) {
    const home = escapeHtml(match?.homeTeam || '?');
    const away = escapeHtml(match?.awayTeam || '?');
    const label = escapeHtml(meta.marketLabel || 'Streak → Over 0.5');
    const reason = meta.market === 'u05x2' ? 'Under 0.5 2x beruntun'
        : (meta.market === 'dr3' ? 'Draw 3x beruntun' : 'Draw 2x beruntun');
    return (
        `🔵 <b>${label} (LIVE)</b>\n\n` +
        `⚽ ${home} vs ${away}\n` +
        `⏱️ ${escapeHtml(match?.status || '-')} · skor masih <b>0-0</b>\n` +
        `🎯 Tim ${escapeHtml(reason)}: <b>${escapeHtml(meta.team)}</b>\n` +
        `📊 Over 0.5 setelah pola ini: <b>${meta.pct}%</b> (${meta.over}/${meta.total})\n` +
        `${escapeHtml(meta.league || '')}`
    );
}

// Dipanggil tiap siklus live (handleFreshData). Tangani dua jenis market:
//  - out 'o15' (Over 1.5): saat live & odd Over 1.75 >= target.
//  - out 'o05' (Over 0.5): saat live babak 2 sekitar 2H 4' & skor masih 0-0.
async function trackStreakKl2LiveOdds(matches) {
    if (!Array.isArray(matches) || matches.length === 0) return;
    const teamMap = await loadStreakWatchMap();
    if (teamMap.size === 0) return;

    const today = new Date().toISOString().slice(0, 10);
    const st = await getStreakLiveAlertState(today);
    const sentSet = new Set(st.sent);
    let changed = false;

    for (const m of matches) {
        const { half, min } = parseMatchMinute(m?.status);
        if (half !== '1H' && half !== '2H') continue; // harus sedang main

        // Kumpulkan semua meta untuk tim home & away (tim watch bisa di banyak market).
        const metas = [
            ...(teamMap.get(normalizeTeamName(m?.homeTeam)) || []),
            ...(teamMap.get(normalizeTeamName(m?.awayTeam)) || []),
        ];
        if (metas.length === 0) continue;

        const pairKey = h2hPairKey(m?.homeTeam, m?.awayTeam);
        const sc = parseScoreTuple(m?.score || '0-0');

        for (const meta of metas) {
            const dedupKey = `${pairKey}|${meta.market}|${meta.out || 'o15'}`;
            if (sentSet.has(dedupKey)) continue;

            if ((meta.out || 'o15') === 'o05') {
                // Over 0.5: babak 2 sekitar 2H 4' & masih 0-0
                if (half !== '2H') continue;
                if (min < STREAK_O05_2H_FROM || min > STREAK_O05_2H_TO) continue;
                if (sc.total !== 0) continue;
                const r = await sendTelegramText(buildStreakAlertMessageO05(meta, m));
                if (r && r.ok) {
                    sentSet.add(dedupKey); changed = true;
                    await setStreakStatus({ lastAlert: `${meta.team} (O0.5) @ ${h2hNowStr()}` });
                }
            } else {
                // Over 1.5: live babak 2 (menit window) & total gol masih < 2 (Over 1.5 belum kena).
                // (Situs tak punya garis o1.75; pakai kondisi skor seperti market Over 0.5.)
                if (half !== '2H') continue;
                if (min < STREAK_O15_2H_FROM || min > STREAK_O15_2H_TO) continue;
                if (sc.total >= 2) continue;
                const odd = getStreakOverOdd(m); // opsional, hanya untuk info bila ada
                const r = await sendTelegramText(buildStreakAlertMessage(meta, m, odd));
                if (r && r.ok) {
                    sentSet.add(dedupKey); changed = true;
                    await setStreakStatus({ lastAlert: `${meta.team} (O1.5) @ ${h2hNowStr()}` });
                }
            }
        }
    }

    if (changed) {
        await chrome.storage.local.set({ [STREAK_LIVE_ALERT_KEY]: { date: today, sent: Array.from(sentSet) } });
    }
}
