function registerMatchIfNeeded(key, match, timestamp, newMatches) {
    if (registeredMatchKeys.has(key)) return false;

    registeredMatchKeys.add(key);
    kickoffTimeByMatchKey.set(key, timestamp);
    newMatches.push({
        timestamp,
        league: match?.league || '',
        home_team: match?.homeTeam || '',
        away_team: match?.awayTeam || '',
    });
    return true;
}

function appendGoalState(key, prevScore, homeScore, awayScore, half, minuteValue) {
    const prevParts = String(prevScore).split('-').map((value) => parseInt(value, 10) || 0);
    const prevHome = prevParts[0] || 0;
    const prevAway = prevParts[1] || 0;
    const curHome = parseInt(homeScore, 10) || 0;
    const curAway = parseInt(awayScore, 10) || 0;
    const homeDelta = Math.max(0, curHome - prevHome);
    const awayDelta = Math.max(0, curAway - prevAway);
    const goalCount = homeDelta + awayDelta;

    if (half === '1H') {
        const curLast = last1HGoalMinByMatchKey.get(key) ?? -1;
        if (minuteValue > curLast) last1HGoalMinByMatchKey.set(key, minuteValue);
        if (!first1HGoalMinByMatchKey.has(key)) first1HGoalMinByMatchKey.set(key, minuteValue);

        const allMins = all1HGoalMinsByMatchKey.get(key) || [];
        for (let i = 0; i < goalCount; i += 1) allMins.push(minuteValue);
        all1HGoalMinsByMatchKey.set(key, allMins);
        const allScorers = all1HScorersByMatchKey.get(key) || [];
        for (let i = 0; i < homeDelta; i += 1) allScorers.push('H');
        for (let i = 0; i < awayDelta; i += 1) allScorers.push('A');
        all1HScorersByMatchKey.set(key, allScorers);
    }

    if (half === '2H') {
        has2HGoalByMatchKey.set(key, true);
        const all2HMins = all2HGoalMinsByMatchKey.get(key) || [];
        for (let i = 0; i < goalCount; i += 1) all2HMins.push(minuteValue);
        all2HGoalMinsByMatchKey.set(key, all2HMins);
        const all2HScorers = all2HScorersByMatchKey.get(key) || [];
        for (let i = 0; i < homeDelta; i += 1) all2HScorers.push('H');
        for (let i = 0; i < awayDelta; i += 1) all2HScorers.push('A');
        all2HScorersByMatchKey.set(key, all2HScorers);
    }
}

function buildTrackedGoalEventsForMatch(key, match, timestamp) {
    const events = [];
    let home = 0;
    let away = 0;
    const league = match?.league || '';
    const homeTeam = match?.homeTeam || '';
    const awayTeam = match?.awayTeam || '';
    const scorePairs = [
        {
            half: '1H',
            mins: all1HGoalMinsByMatchKey.get(key) || [],
            scorers: all1HScorersByMatchKey.get(key) || []
        },
        {
            half: '2H',
            mins: all2HGoalMinsByMatchKey.get(key) || [],
            scorers: all2HScorersByMatchKey.get(key) || []
        }
    ];

    for (const part of scorePairs) {
        const mins = Array.isArray(part.mins) ? part.mins : [];
        const scorers = Array.isArray(part.scorers) ? part.scorers : [];
        const count = Math.min(mins.length, scorers.length);
        for (let i = 0; i < count; i += 1) {
            const scorer = scorers[i];
            if (scorer === 'H') home += 1;
            else if (scorer === 'A') away += 1;
            else continue;

            events.push({
                timestamp,
                league,
                home_team: homeTeam,
                away_team: awayTeam,
                minute: `${part.half} ${mins[i]}'`,
                score_before: '0-0',
                score_after: `${home}-${away}`,
                home_score: String(home),
                away_score: String(away),
            });
        }
    }

    return events;
}

function buildScoreSnapshot(match, timestamp) {
    return {
        timestamp,
        league: match?.league || '',
        home_team: match?.homeTeam || '',
        away_team: match?.awayTeam || '',
        home_score: String(match?.homeScore ?? '0'),
        away_score: String(match?.awayScore ?? '0'),
    };
}

function buildVisibleGoalBackfill(key, match, minute, scoreStr, timestamp) {
    const parsed = parseMatchMinute(minute);
    if (parsed.half !== '1H') return null;
    if (all1HGoalMinsByMatchKey.has(key) || all2HGoalMinsByMatchKey.has(key)) return null;

    const homeScore = parseInt(match?.homeScore ?? '0', 10) || 0;
    const awayScore = parseInt(match?.awayScore ?? '0', 10) || 0;
    if (homeScore + awayScore !== 1) return null;

    appendGoalState(key, '0-0', String(homeScore), String(awayScore), parsed.half, parsed.min);

    return {
        timestamp,
        league: match?.league || '',
        home_team: match?.homeTeam || '',
        away_team: match?.awayTeam || '',
        minute,
        score_before: '0-0',
        score_after: scoreStr,
        home_score: String(match?.homeScore ?? '0'),
        away_score: String(match?.awayScore ?? '0'),
    };
}

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
        const parsedMinute = parseMatchMinute(minute);
        const isTrackableLiveState = parsedMinute.half === '1H' || parsedMinute.half === '2H' || /^H\.?Time$/i.test(minute);

        const isNewRound = isKickoffMinute(minute) && scoreStr === '0-0' &&
            registeredMatchKeys.has(key) &&
            (lastScoreByMatchKey.get(key) || '0-0') !== '0-0';
        if (isKickoffMinute(minute) && (!registeredMatchKeys.has(key) || isNewRound)) {
            if (isNewRound) {
                registeredMatchKeys.delete(key);
            }
            registerMatchIfNeeded(key, match, timestamp, newMatches);
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
            all2HScorersByMatchKey.delete(key);
            for (const ms of MILESTONES) {
                sentMilestones.delete(key + '|' + ms.id);
            }

            const kickoffBackfill = buildVisibleGoalBackfill(key, match, minute, scoreStr, kickoffTimeByMatchKey.get(key) || timestamp);
            if (kickoffBackfill) {
                newGoals.push(kickoffBackfill);
            }

            lastScoreByMatchKey.set(key, scoreStr);
            continue;
        }

        if (!registeredMatchKeys.has(key) && isTrackableLiveState) {
            registerMatchIfNeeded(key, match, timestamp, newMatches);
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
            const inferredGoal = buildVisibleGoalBackfill(key, match, minute, scoreStr, kickoffTimeByMatchKey.get(key) || timestamp);
            if (inferredGoal) {
                newGoals.push(inferredGoal);
            }
            lastScoreByMatchKey.set(key, scoreStr);
            continue;
        }

        if (prev !== scoreStr) {
            const { half: gHalf, min: gMin } = parseMatchMinute(minute);
            appendGoalState(key, prev, homeScore, awayScore, gHalf, gMin);

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

    const scoreSnapshots = [];
    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        scoreSnapshots.push(buildScoreSnapshot(match, kickoffTimeByMatchKey.get(key) || timestamp));
    }

    const matchPayload = [...newMatches, ...scoreSnapshots];
    if (matchPayload.length) {
        try {
            await fetch('http://localhost/lebihsabar/goal-log-save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ matches: matchPayload })
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

    await chrome.storage.local.set({
        liveHtScores: Object.fromEntries(shScoreByMatchKey)
    });

    if (newGoals.length) {
        const stored = await chrome.storage.local.get(['goalLog']);
        const existing = Array.isArray(stored.goalLog) ? stored.goalLog : [];
        const updated = [...existing, ...newGoals].slice(-500);
        await chrome.storage.local.set({ goalLog: updated });
    }

    const syncGoals = [];
    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        const trackedEvents = buildTrackedGoalEventsForMatch(key, match, kickoffTimeByMatchKey.get(key) || timestamp);
        const trackedFinal = trackedEvents.length ? trackedEvents[trackedEvents.length - 1].score_after : null;
        if (trackedEvents.length && trackedFinal === `${String(match?.homeScore ?? '0').trim()}-${String(match?.awayScore ?? '0').trim()}`) {
            syncGoals.push(...trackedEvents);
        }
    }

    const goalsToPersist = syncGoals.length ? syncGoals : newGoals;
    if (!goalsToPersist.length) return;

    try {
        await fetch('http://localhost/lebihsabar/goal-log-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ goals: goalsToPersist })
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
