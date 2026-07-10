// V-Soccer goal tracker — runs continuously on *.1x2aaa.com (Asian view).
// Scans the live event list, detects score changes per match, and forwards
// kickoff / goal / milestone events to the background worker, which persists
// them into goal_log_vsoccer.csv (mirrors the main BPVM goal log pipeline).

(function () {
    const SCAN_INTERVAL_MS = 1500;

    // Per-round state keyed by "home|away".
    // { lastScore: "h-a", total, registered, lastHalf, milestones: Set }
    const state = new Map();

    const txt = (el) => (el ? el.textContent.trim() : '');

    function parseMinute(status) {
        const m = /^(1H|2H)\D*(\d+)/i.exec(String(status || '').trim());
        return m ? { half: m[1].toUpperCase(), min: parseInt(m[2], 10) } : { half: '', min: -1 };
    }

    function scanMatches() {
        const out = [];
        document.querySelectorAll('.eventlist_asia_fe_EventListLeague_container').forEach((leagueEl) => {
            const league = txt(leagueEl.querySelector('.eventlist_asia_fe_EventListLeague_leagueName span')) || 'V-Soccer';
            leagueEl.querySelectorAll('.eventlist_asia_fe_EventListLeague_singleEvent').forEach((ev) => {
                const names = Array.from(ev.querySelectorAll('.eventlist_asia_fe_EventCard_teamNameText'))
                    .map((n) => n.textContent.trim())
                    .filter((n) => n && n.toLowerCase() !== 'draw');
                if (names.length < 2) return;

                const scoreRaw = txt(ev.querySelector('.eventlist_asia_fe_EventTime_scoreLive'));
                const sm = /(\d+)\s*:\s*(\d+)/.exec(scoreRaw);
                if (!sm) return;

                const half = txt(ev.querySelector('.eventlist_asia_fe_EventTime_gamePart'));
                const minEl = ev.querySelector('.eventlist_asia_fe_EventTime_gameProgress span:last-child');
                const minute = minEl ? minEl.textContent.trim() : '';

                out.push({
                    league,
                    homeTeam: names[0],
                    awayTeam: names[1],
                    homeScore: parseInt(sm[1], 10),
                    awayScore: parseInt(sm[2], 10),
                    status: [half, minute].filter(Boolean).join(' ')
                });
            });
        });
        return out;
    }

    function tick() {
        const matches = scanMatches();
        if (!matches.length) return;

        const goals = [];
        const kickoffs = [];
        const milestones = [];
        const ts = new Date().toISOString();

        for (const m of matches) {
            const key = `${m.homeTeam}|${m.awayTeam}`;
            const scoreStr = `${m.homeScore}-${m.awayScore}`;
            const total = m.homeScore + m.awayScore;
            const pm = parseMinute(m.status);
            if (pm.half !== '1H' && pm.half !== '2H') continue;

            let s = state.get(key);

            // New round detection: fresh fixture, or score reset back down (new game reusing teams).
            const isReset = s && (total < s.total || (pm.half === '1H' && pm.min <= 1 && s.lastHalf === '2H'));
            if (!s || isReset) {
                s = { lastScore: scoreStr, total, registered: false, lastHalf: pm.half, milestones: new Set() };
                state.set(key, s);
            }

            // Register kickoff once per round.
            if (!s.registered) {
                s.registered = true;
                kickoffs.push({
                    timestamp: ts,
                    league: m.league,
                    home_team: m.homeTeam,
                    away_team: m.awayTeam,
                    home_score: String(m.homeScore),
                    away_score: String(m.awayScore)
                });
            }

            // Goal detection: total increased.
            if (total > s.total) {
                goals.push({
                    timestamp: ts,
                    league: m.league,
                    home_team: m.homeTeam,
                    away_team: m.awayTeam,
                    minute: m.status,
                    score_after: scoreStr,
                    home_score: String(m.homeScore),
                    away_score: String(m.awayScore)
                });
            }

            // Milestone flags (sent once each), so 0-0 games are tracked too.
            const fire = (id) => {
                if (s.milestones.has(id)) return;
                s.milestones.add(id);
                milestones.push({
                    timestamp: ts,
                    league: m.league,
                    home_team: m.homeTeam,
                    away_team: m.awayTeam,
                    milestone: id,
                    home_score: String(m.homeScore),
                    away_score: String(m.awayScore)
                });
            };
            if (pm.half === '1H' && pm.min >= 3) fire('1h3');
            if (pm.half === '2H') { fire('1h3'); if (pm.min >= 1) fire('2h1'); if (pm.min >= 7) fire('2h7'); }

            s.lastScore = scoreStr;
            s.total = total;
            s.lastHalf = pm.half;
        }

        if (goals.length || kickoffs.length || milestones.length) {
            try {
                chrome.runtime.sendMessage({
                    action: 'vsoccerGoalLog',
                    payload: { goals, matches: kickoffs, milestones }
                });
            } catch (_) { /* extension context invalidated on reload */ }
        }
    }

    // Drive ticks from a Web Worker. Chrome throttles (or freezes) main-thread
    // setInterval when the tab is backgrounded/minimized, which would make the
    // scanner miss the fast Virtual Soccer rounds. Worker timers are not
    // background-throttled, so the scan keeps firing at full speed regardless of
    // tab focus. Falls back to a plain interval if the page CSP blocks blob workers.
    function startWorkerTicker() {
        try {
            const src = 'setInterval(function(){postMessage(0);},' + SCAN_INTERVAL_MS + ');';
            const url = URL.createObjectURL(new Blob([src], { type: 'application/javascript' }));
            const worker = new Worker(url);
            worker.onmessage = () => tick();
            return true;
        } catch (_) {
            return false;
        }
    }

    if (!startWorkerTicker()) {
        setInterval(tick, SCAN_INTERVAL_MS); // foreground-only fallback
    }
    tick();
})();
