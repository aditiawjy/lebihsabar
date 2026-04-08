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
            first1HGoalMinByMatchKey.delete(key);
            all1HGoalMinsByMatchKey.delete(key);
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

        sentHT22Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const league = escapeHtml(match?.league || '?');
        const currentScore = `${homeScore}-${awayScore}`;

        const msg =
            `🚨 <b>SIGNAL HT 2-2 — BABAK 2 BERJALAN!</b>\n` +
            `⚽ <b>${home} vs ${away}</b>\n` +
            `🏆 League: ${league}\n` +
            `📊 Skor HT: <b>2-2</b> | Skor sekarang: <b>${currentScore}</b>\n` +
            `⏰ Status: <b>${escapeHtml(status)}</b>\n\n` +
            `🔥 <i>Match dengan HT 2-2 — pantau peluang gol babak kedua!</i>`;

        await sendTelegramText(msg);
    }
}
