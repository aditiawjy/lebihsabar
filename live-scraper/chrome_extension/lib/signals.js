async function trackGoalEvents(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    const newGoals = [];
    const newMatches = [];
    const now = new Date();
    const timestamp = now.toISOString();

    for (const match of matches) {
        const key = createMatchKey(match);
        const homeScore = String(match?.homeScore ?? '0').trim();
        const awayScore = String(match?.awayScore ?? '0').trim();
        const scoreStr = `${homeScore}-${awayScore}`;
        const minute = String(match?.status || '').trim();

        const isNewRound = isKickoffMinute(minute) && scoreStr === '0-0' &&
            registeredMatchKeys.has(key) &&
            (lastScoreByMatchKey.get(key) || '0-0') !== '0-0';
        if (isKickoffMinute(minute) && (!registeredMatchKeys.has(key) || isNewRound)) {
            registeredMatchKeys.add(key);
            kickoffTimeByMatchKey.set(key, timestamp);
            lastScoreByMatchKey.set(key, scoreStr);
            last1HGoalMinByMatchKey.delete(key);
            has2HGoalByMatchKey.delete(key);
            sentNG1Signals.delete(key);
            sentHT22Signals.delete(key);
            sentP7Signals.delete(key);
            sentP14Signals.delete(key);
            sentP19Signals.delete(key);
            first1HGoalMinByMatchKey.delete(key);
            all1HGoalMinsByMatchKey.delete(key);
            all1HScorersByMatchKey.delete(key);
            all2HGoalMinsByMatchKey.delete(key);
            for (const ms of MILESTONES) {
                sentMilestones.delete(key + '|' + ms.id);
            }
            newMatches.push({
                timestamp,
                league: match?.league || '',
                home_team: match?.homeTeam || '',
                away_team: match?.awayTeam || '',
            });
            continue;
        }

        const isHalftime = /^H\.?Time$/i.test(minute);
        if ((isHalftime || isSecondHalfStart(minute)) && !shScoreByMatchKey.has(key)) {
            shScoreByMatchKey.set(key, scoreStr);
        }
        if (isKickoffMinute(minute) && shScoreByMatchKey.has(key)) {
            shScoreByMatchKey.delete(key);
        }

        if (!registeredMatchKeys.has(key)) continue;

        const prev = lastScoreByMatchKey.get(key);
        if (prev === undefined) {
            lastScoreByMatchKey.set(key, scoreStr);
            continue;
        }

        if (prev !== scoreStr) {
            const { half: gHalf, min: gMin } = parseMatchMinute(minute);
            if (gHalf === '1H') {
                const curLast = last1HGoalMinByMatchKey.get(key) ?? -1;
                if (gMin > curLast) last1HGoalMinByMatchKey.set(key, gMin);
                if (!first1HGoalMinByMatchKey.has(key)) first1HGoalMinByMatchKey.set(key, gMin);
                const allMins = all1HGoalMinsByMatchKey.get(key) || [];
                allMins.push(gMin);
                all1HGoalMinsByMatchKey.set(key, allMins);

                const prevParts = String(prev).split('-').map((value) => parseInt(value, 10) || 0);
                const prevHome = prevParts[0] || 0;
                const prevAway = prevParts[1] || 0;
                const curHome = parseInt(homeScore, 10) || 0;
                const curAway = parseInt(awayScore, 10) || 0;
                const homeDelta = Math.max(0, curHome - prevHome);
                const awayDelta = Math.max(0, curAway - prevAway);
                const allScorers = all1HScorersByMatchKey.get(key) || [];
                for (let i = 0; i < homeDelta; i += 1) allScorers.push('H');
                for (let i = 0; i < awayDelta; i += 1) allScorers.push('A');
                all1HScorersByMatchKey.set(key, allScorers);
            }
            if (gHalf === '2H') {
                has2HGoalByMatchKey.set(key, true);
                const all2HMins = all2HGoalMinsByMatchKey.get(key) || [];
                all2HMins.push(gMin);
                all2HGoalMinsByMatchKey.set(key, all2HMins);
            }

            newGoals.push({
                timestamp: kickoffTimeByMatchKey.get(key) || timestamp,
                league: match?.league || '',
                home_team: match?.homeTeam || '',
                away_team: match?.awayTeam || '',
                minute,
                score_before: prev,
                score_after: scoreStr,
                home_score: homeScore,
                away_score: awayScore,
            });
            lastScoreByMatchKey.set(key, scoreStr);
        }
    }

    const newMilestones = [];
    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        const { half, min } = parseMatchMinute(String(match?.status || ''));
        if (!half) continue;
        for (const ms of MILESTONES) {
            if (ms.half === half && min >= ms.minThreshold) {
                const msKey = key + '|' + ms.id;
                if (!sentMilestones.has(msKey)) {
                    sentMilestones.add(msKey);
                    newMilestones.push({
                        timestamp: kickoffTimeByMatchKey.get(key) || timestamp,
                        league: match?.league || '',
                        home_team: match?.homeTeam || '',
                        away_team: match?.awayTeam || '',
                        milestone: ms.id,
                        home_score: String(match?.homeScore ?? '0'),
                        away_score: String(match?.awayScore ?? '0'),
                    });
                }
            }
        }
    }

    if (newMatches.length) {
        try {
            await fetch('http://localhost/lebihsabar/goal-log-save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ matches: newMatches })
            });
        } catch (_) {}
    }

    if (newMilestones.length) {
        try {
            await fetch('http://localhost/lebihsabar/goal-log-save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ milestones: newMilestones })
            });
        } catch (_) {}
    }

    if (!newGoals.length) return;

    await chrome.storage.local.set({
        liveHtScores: Object.fromEntries(shScoreByMatchKey)
    });

    const stored = await chrome.storage.local.get(['goalLog']);
    const existing = Array.isArray(stored.goalLog) ? stored.goalLog : [];
    const updated = [...existing, ...newGoals].slice(-500);
    await chrome.storage.local.set({ goalLog: updated });

    try {
        await fetch('http://localhost/lebihsabar/goal-log-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ goals: newGoals })
        });
    } catch (_) {}
}

async function trackNG1Signal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        if (sentNG1Signals.has(key)) continue;

        const status = String(match?.status || '').trim();
        const shMin = getShMinute(status);
        if (shMin < 0 || shMin > 1) continue;

        const homeScore = parseInt(match?.homeScore ?? '0', 10);
        const awayScore = parseInt(match?.awayScore ?? '0', 10);

        if (homeScore !== 1 || awayScore !== 0) continue;

        const last1HMin = last1HGoalMinByMatchKey.get(key) ?? -1;
        if (last1HMin !== 3) continue;

        sentNG1Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');

        const msg =
            `🎯 <b>NG1 SIGNAL — BABAK 2 MULAI!</b>\n` +
            `⚽ <b>${home} vs ${away}</b>\n` +
            `📊 Skor HT: <b>1-0</b> | Gol terakhir 1H: menit <b>3'</b>\n` +
            `⏰ Status: <b>${escapeHtml(status)}</b>\n\n` +
            `📈 Pola NG1: HT 1-0 + gol mnt 3 → <b>HOME 83%</b>\n` +
            `🔥 <i>Pantau — prediksi gol HOME di babak kedua!</i>`;

        await sendTelegramText(msg);
    }
}

async function trackHT22Signal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        if (sentHT22Signals.has(key)) continue;

        const status = String(match?.status || '').trim();
        const shMin = getShMinute(status);
        if (shMin < 2 || shMin > 3) continue;

        const homeScore = parseInt(match?.homeScore ?? '0', 10);
        const awayScore = parseInt(match?.awayScore ?? '0', 10);

        const htScore = shScoreByMatchKey.get(key);
        if (htScore !== '2-2') continue;

        const goalMins = all1HGoalMinsByMatchKey.get(key) || [];
        if (goalMins.length < 2) continue;

        let maxGap = 0;
        for (let i = 1; i < goalMins.length; i += 1) {
            maxGap = Math.max(maxGap, goalMins[i] - goalMins[i - 1]);
        }
        if (maxGap > 2) continue;

        sentHT22Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const league = escapeHtml(match?.league || '?');
        const currentScore = `${homeScore}-${awayScore}`;
        const minuteText = goalMins.map((min) => `${min}'`).join(', ');

        const msg =
            `🚨 <b>P15 SIGNAL — HT 2-2 + MAX GAP <= 2</b>\n` +
            `⚽ <b>${home} vs ${away}</b>\n` +
            `🏆 League: ${league}\n` +
            `📊 Skor HT: <b>2-2</b> | Skor sekarang: <b>${currentScore}</b>\n` +
            `⏰ Status: <b>${escapeHtml(status)}</b>\n` +
            `📌 Gol 1H: <b>${escapeHtml(minuteText)}</b> | Max gap: <b>${maxGap}</b> menit\n\n` +
            `🔥 <i>P15 lolos: HT 2-2 dengan jeda gol rapat. Pantau peluang gol babak kedua!</i>`;

        await sendTelegramText(msg);
    }
}

async function trackP14Signal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        if (sentP14Signals.has(key)) continue;

        const status = String(match?.status || '').trim();
        const isHalftime = /^H\.?Time$/i.test(status);
        const shMin = getShMinute(status);
        const isEarlySecondHalf = shMin >= 0 && shMin <= 1;
        if (!isHalftime && !isEarlySecondHalf) continue;
        if (has2HGoalByMatchKey.get(key)) continue;

        const homeScore = parseInt(match?.homeScore ?? '0', 10);
        const awayScore = parseInt(match?.awayScore ?? '0', 10);
        if (homeScore !== awayScore || homeScore === 0) continue;

        const goalMins = all1HGoalMinsByMatchKey.get(key) || [];
        if (goalMins.length < 2) continue;

        const firstGoalMin = goalMins[0];
        const lastGoalMin = goalMins[goalMins.length - 1];
        const span = lastGoalMin - firstGoalMin;
        let maxGap = 0;
        let minGap = Number.POSITIVE_INFINITY;
        for (let i = 1; i < goalMins.length; i += 1) {
            const gap = goalMins[i] - goalMins[i - 1];
            maxGap = Math.max(maxGap, gap);
            minGap = Math.min(minGap, gap);
        }

        if (!Number.isFinite(minGap)) minGap = 0;

        if (firstGoalMin === 1 || span < 5 || maxGap < 4 || minGap < 2) continue;

        sentP14Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const league = escapeHtml(match?.league || '?');
        const score = `${homeScore}-${awayScore}`;
        const minuteText = goalMins.map((min) => `${min}'`).join(', ');

        const msg =
            `🚨 <b>P14 SIGNAL — POTENSI GOL BABAK 2</b>\n` +
            `⚽ <b>${home} vs ${away}</b>\n` +
            `🏆 League: ${league}\n` +
            `📊 Skor: <b>${score}</b>\n` +
            `⏰ Status: <b>${escapeHtml(status)}</b>\n\n` +
            `📌 Pola P14 terpenuhi:\n` +
            `• Seri di babak pertama\n` +
            `• Gap gol max <b>${maxGap}</b> menit\n` +
            `• Gap gol min <b>${minGap}</b> menit\n` +
            `• Span gol <b>${span}</b> menit\n` +
            `• First goal menit <b>${firstGoalMin}'</b>\n` +
            `• Urutan menit gol: <b>${escapeHtml(minuteText)}</b>\n\n` +
            `🔥 <i>P14 historis 95% — indikasi kuat ada gol di babak kedua.</i>`;

        await sendTelegramText(msg);
    }
}

async function trackP7Signal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        if (sentP7Signals.has(key)) continue;

        const status = String(match?.status || '').trim();
        const isHalftime = /^H\.?Time$/i.test(status);
        const shMin = getShMinute(status);
        const isEarlySecondHalf = shMin >= 0 && shMin <= 1;
        if (!isHalftime && !isEarlySecondHalf) continue;
        if (has2HGoalByMatchKey.get(key)) continue;

        const homeScore = parseInt(match?.homeScore ?? '0', 10);
        const awayScore = parseInt(match?.awayScore ?? '0', 10);
        if (homeScore !== 1 || awayScore !== 1) continue;

        const goalMins = all1HGoalMinsByMatchKey.get(key) || [];
        if (goalMins.length !== 2) continue;

        const firstGoalMin = goalMins[0];
        const secondGoalMin = goalMins[1];
        const maxGap = secondGoalMin - firstGoalMin;
        if (firstGoalMin === 1 || maxGap < 5) continue;

        sentP7Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const league = escapeHtml(match?.league || '?');
        const minuteText = goalMins.map((min) => `${min}'`).join(', ');

        const msg =
            `🚨 <b>P7 SIGNAL — POTENSI GOL BABAK 2</b>\n` +
            `⚽ <b>${home} vs ${away}</b>\n` +
            `🏆 League: ${league}\n` +
            `📊 Skor: <b>1-1</b>\n` +
            `⏰ Status: <b>${escapeHtml(status)}</b>\n\n` +
            `📌 Pola P7 terpenuhi:\n` +
            `• HT seri <b>1-1</b>\n` +
            `• Gap antar gol <b>${maxGap}</b> menit\n` +
            `• First goal menit <b>${firstGoalMin}'</b>\n` +
            `• Urutan menit gol 1H: <b>${escapeHtml(minuteText)}</b>\n\n` +
            `🔥 <i>P7 historis sangat kuat — indikasi ada gol di babak kedua.</i>`;

        await sendTelegramText(msg);
    }
}

async function trackP19Signal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        if (sentP19Signals.has(key)) continue;

        const status = String(match?.status || '').trim();
        const isHalftime = /^H\.?Time$/i.test(status);
        const shMin = getShMinute(status);
        const isEarlySecondHalf = shMin >= 0 && shMin <= 1;
        if (!isHalftime && !isEarlySecondHalf) continue;
        if (has2HGoalByMatchKey.get(key)) continue;
        if (!/20\s+Mins/i.test(String(match?.league || ''))) continue;

        const homeScore = parseInt(match?.homeScore ?? '0', 10);
        const awayScore = parseInt(match?.awayScore ?? '0', 10);
        const goalMins = all1HGoalMinsByMatchKey.get(key) || [];
        const scorers = all1HScorersByMatchKey.get(key) || [];

        if (!goalMins.length || !scorers.length) continue;

        const lastGoalMin = goalMins[goalMins.length - 1];
        const lastScorer = scorers[scorers.length - 1];
        if (![3, 4].includes(lastGoalMin) || lastScorer !== 'H') continue;

        let maxGap = 0;
        for (let i = 1; i < goalMins.length; i += 1) {
            maxGap = Math.max(maxGap, goalMins[i] - goalMins[i - 1]);
        }
        if (goalMins.length > 1 && maxGap < 2) continue;

        sentP19Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const league = escapeHtml(match?.league || '?');
        const score = `${homeScore}-${awayScore}`;
        const minuteText = goalMins.map((min) => `${min}'`).join(', ');

        const msg =
            `🚨 <b>P19 SIGNAL — POTENSI GOL BABAK 2</b>\n` +
            `⚽ <b>${home} vs ${away}</b>\n` +
            `🏆 League: ${league}\n` +
            `📊 Skor: <b>${score}</b>\n` +
            `⏰ Status: <b>${escapeHtml(status)}</b>\n\n` +
            `📌 Pola P19 terpenuhi:\n` +
            `• Last goal 1H menit <b>${lastGoalMin}'</b>\n` +
            `• Last scorer <b>HOME</b>\n` +
            `• Gap gol max <b>${maxGap}</b> menit\n` +
            `• Urutan menit gol 1H: <b>${escapeHtml(minuteText)}</b>\n\n` +
            `🔥 <i>P19 aktif — indikasi kuat ada gol di babak kedua.</i>`;

        await sendTelegramText(msg);
    }
}
