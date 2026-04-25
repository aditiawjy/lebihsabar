const MATCH_STATE_STALE_MS = 45 * 60 * 1000;

function clearThresholdAlertState(matchKey) {
    for (const stateKey of Array.from(notifiedMatchKeys)) {
        if (String(stateKey).startsWith(`${matchKey}::`)) {
            notifiedMatchKeys.delete(stateKey);
        }
    }

    for (const stateKey of Array.from(wasAboveThresholdByMatchKey.keys())) {
        if (String(stateKey).startsWith(`${matchKey}::`)) {
            wasAboveThresholdByMatchKey.delete(stateKey);
        }
    }
}

function resetMatchTrackingState(key) {
    registeredMatchKeys.delete(key);
    kickoffTimeByMatchKey.delete(key);
    lastScoreByMatchKey.delete(key);
    shScoreByMatchKey.delete(key);
    sentNG1Signals.delete(key);
    sentHT22Signals.delete(key);
    sentP7Signals.delete(key);
    sentP14Signals.delete(key);
    sentP19Signals.delete(key);
    last1HGoalMinByMatchKey.delete(key);
    first1HGoalMinByMatchKey.delete(key);
    all1HGoalMinsByMatchKey.delete(key);
    all1HScorersByMatchKey.delete(key);
    has2HGoalByMatchKey.delete(key);
    all2HGoalMinsByMatchKey.delete(key);
    all2HScorersByMatchKey.delete(key);
    lastSeenAtByMatchKey.delete(key);
    lastStatusByMatchKey.delete(key);
    oddHistoryByMatchKey.delete(key);
    oddInsightByMatchKey.delete(key);
    clearThresholdAlertState(key);
    for (const ms of MILESTONES) {
        sentMilestones.delete(key + '|' + ms.id);
    }
}

function parseScoreTuple(scoreStr) {
    const parts = String(scoreStr || '0-0').split('-').map((value) => parseInt(value, 10) || 0);
    return { home: parts[0] || 0, away: parts[1] || 0, total: (parts[0] || 0) + (parts[1] || 0) };
}

function shouldResetFixtureState(key, minute, scoreStr, nowMs) {
    if (!registeredMatchKeys.has(key)) return false;

    const lastSeenAt = lastSeenAtByMatchKey.get(key) || 0;
    const lastStatus = String(lastStatusByMatchKey.get(key) || '').trim();
    const prevStatus = parseMatchMinute(lastStatus);
    const curStatus = parseMatchMinute(minute);
    const prevScore = parseScoreTuple(lastScoreByMatchKey.get(key) || '0-0');
    const curScore = parseScoreTuple(scoreStr);
    const wasHalftime = /^H\.?Time$/i.test(lastStatus);
    const isHalftime = /^H\.?Time$/i.test(String(minute || '').trim());

    if (lastSeenAt && (nowMs - lastSeenAt) > MATCH_STATE_STALE_MS) {
        return true;
    }

    if (prevScore.total > curScore.total && isKickoffMinute(minute)) {
        return true;
    }

    if ((prevStatus.half === '2H' || wasHalftime) && curStatus.half === '1H') {
        return true;
    }

    if (prevStatus.half === curStatus.half && prevStatus.min >= 0 && curStatus.min >= 0 && curStatus.min + 2 < prevStatus.min) {
        return true;
    }

    if ((prevStatus.half === '2H' || wasHalftime) && isKickoffMinute(minute)) {
        return true;
    }

    if ((last1HGoalMinByMatchKey.get(key) || -1) >= 7 && curStatus.half === '1H' && curStatus.min >= 0 && curStatus.min <= 4) {
        return true;
    }

    if (has2HGoalByMatchKey.get(key) && (isHalftime || (curStatus.half === '2H' && curStatus.min <= 2))) {
        return true;
    }

    return false;
}

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
    const nowMs = now.getTime();

    for (const match of matches) {
        const key = createMatchKey(match);
        const homeScore = String(match?.homeScore ?? '0').trim();
        const awayScore = String(match?.awayScore ?? '0').trim();
        const scoreStr = `${homeScore}-${awayScore}`;
        const minute = String(match?.status || '').trim();
        const parsedMinute = parseMatchMinute(minute);
        const isTrackableLiveState = parsedMinute.half === '1H' || parsedMinute.half === '2H' || /^H\.?Time$/i.test(minute);

        if (shouldResetFixtureState(key, minute, scoreStr, nowMs)) {
            resetMatchTrackingState(key);
        }

        const isNewRound = isKickoffMinute(minute) && scoreStr === '0-0' &&
            registeredMatchKeys.has(key) &&
            (lastScoreByMatchKey.get(key) || '0-0') !== '0-0';
        if (isKickoffMinute(minute) && (!registeredMatchKeys.has(key) || isNewRound)) {
            if (isNewRound) {
                resetMatchTrackingState(key);
            }
            registerMatchIfNeeded(key, match, timestamp, newMatches);

            const kickoffBackfill = buildVisibleGoalBackfill(key, match, minute, scoreStr, kickoffTimeByMatchKey.get(key) || timestamp);
            if (kickoffBackfill) {
                newGoals.push(kickoffBackfill);
            }

            lastScoreByMatchKey.set(key, scoreStr);
            lastSeenAtByMatchKey.set(key, nowMs);
            lastStatusByMatchKey.set(key, minute);
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
            lastSeenAtByMatchKey.set(key, nowMs);
            lastStatusByMatchKey.set(key, minute);
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

        lastSeenAtByMatchKey.set(key, nowMs);
        lastStatusByMatchKey.set(key, minute);
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

function getPatternTelegramOdd(match) {
    return getQualifiedOddsAlert(match, TARGET_ODD_SELECTION, TARGET_ODD_MIN);
}

function isPatternNotificationWindow(status) {
    if (/^H\.?Time$/i.test(String(status || '').trim())) {
        return true;
    }

    return getShMinute(status) >= 0;
}

async function trackNG1Signal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        if (sentNG1Signals.has(key)) continue;

        const status = String(match?.status || '').trim();
        if (!isPatternNotificationWindow(status)) continue;
        if (has2HGoalByMatchKey.get(key)) continue;

        const homeScore = parseInt(match?.homeScore ?? '0', 10);
        const awayScore = parseInt(match?.awayScore ?? '0', 10);

        if (homeScore !== 1 || awayScore !== 0) continue;

        const last1HMin = last1HGoalMinByMatchKey.get(key) ?? -1;
        if (last1HMin !== 3) continue;

        const targetOdd = getPatternTelegramOdd(match);
        if (!targetOdd) continue;

        sentNG1Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');

        const msg =
            `[TARGET] <b>NG1 SIGNAL - BABAK 2 MULAI!</b>\n` +
            `Goal <b>${home} vs ${away}</b>\n` +
            `[SCORE] Skor HT: <b>1-0</b> | Gol terakhir 1H: menit <b>3'</b>\n` +
            `[TIME] Status: <b>${escapeHtml(status)}</b>\n\n` +
            `[TARGET] Market: <b>${escapeHtml(targetOdd.marketName)}: ${escapeHtml(targetOdd.label)} @ ${escapeHtml(targetOdd.oddValue.toFixed(2))}</b>\n\n` +
            `[TREND] Pola NG1: HT 1-0 + gol mnt 3 -> <b>HOME 83%</b>\n` +
            `[HOT] <i>Notif dikirim saat FT O/U O0.75 sudah > 1.95.</i>`;

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
        if (!isPatternNotificationWindow(status)) continue;
        if (has2HGoalByMatchKey.get(key)) continue;

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

        const targetOdd = getPatternTelegramOdd(match);
        if (!targetOdd) continue;

        sentHT22Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const league = escapeHtml(match?.league || '?');
        const currentScore = `${homeScore}-${awayScore}`;
        const minuteText = goalMins.map((min) => `${min}'`).join(', ');

        const msg =
            `[ALERT] <b>P15 SIGNAL - HT 2-2 + MAX GAP <= 2</b>\n` +
            `Goal <b>${home} vs ${away}</b>\n` +
            `[LEAGUE] League: ${league}\n` +
            `[SCORE] Skor HT: <b>2-2</b> | Skor sekarang: <b>${currentScore}</b>\n` +
            `[TIME] Status: <b>${escapeHtml(status)}</b>\n` +
            `[TARGET] Market: <b>${escapeHtml(targetOdd.marketName)}: ${escapeHtml(targetOdd.label)} @ ${escapeHtml(targetOdd.oddValue.toFixed(2))}</b>\n` +
            `[NOTE] Gol 1H: <b>${escapeHtml(minuteText)}</b> | Max gap: <b>${maxGap}</b> menit\n\n` +
            `[HOT] <i>P15 lolos dan FT O/U O0.75 sudah > 1.95.</i>`;

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
        if (!isPatternNotificationWindow(status)) continue;
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

        const targetOdd = getPatternTelegramOdd(match);
        if (!targetOdd) continue;

        sentP14Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const league = escapeHtml(match?.league || '?');
        const score = `${homeScore}-${awayScore}`;
        const minuteText = goalMins.map((min) => `${min}'`).join(', ');

        const msg =
            `[ALERT] <b>P14 SIGNAL - POTENSI GOL BABAK 2</b>\n` +
            `Goal <b>${home} vs ${away}</b>\n` +
            `[LEAGUE] League: ${league}\n` +
            `[SCORE] Skor: <b>${score}</b>\n` +
            `[TIME] Status: <b>${escapeHtml(status)}</b>\n\n` +
            `[TARGET] Market: <b>${escapeHtml(targetOdd.marketName)}: ${escapeHtml(targetOdd.label)} @ ${escapeHtml(targetOdd.oddValue.toFixed(2))}</b>\n\n` +
            `[NOTE] Pola P14 terpenuhi:\n` +
            `- Seri di babak pertama\n` +
            `- Gap gol max <b>${maxGap}</b> menit\n` +
            `- Gap gol min <b>${minGap}</b> menit\n` +
            `- Span gol <b>${span}</b> menit\n` +
            `- First goal menit <b>${firstGoalMin}'</b>\n` +
            `- Urutan menit gol: <b>${escapeHtml(minuteText)}</b>\n\n` +
            `[HOT] <i>P14 lolos dan FT O/U O0.75 sudah > 1.95.</i>`;

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
        if (!isPatternNotificationWindow(status)) continue;
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

        const targetOdd = getPatternTelegramOdd(match);
        if (!targetOdd) continue;

        sentP7Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const league = escapeHtml(match?.league || '?');
        const minuteText = goalMins.map((min) => `${min}'`).join(', ');

        const msg =
            `[ALERT] <b>P7 SIGNAL - POTENSI GOL BABAK 2</b>\n` +
            `Goal <b>${home} vs ${away}</b>\n` +
            `[LEAGUE] League: ${league}\n` +
            `[SCORE] Skor: <b>1-1</b>\n` +
            `[TIME] Status: <b>${escapeHtml(status)}</b>\n\n` +
            `[TARGET] Market: <b>${escapeHtml(targetOdd.marketName)}: ${escapeHtml(targetOdd.label)} @ ${escapeHtml(targetOdd.oddValue.toFixed(2))}</b>\n\n` +
            `[NOTE] Pola P7 terpenuhi:\n` +
            `- Seri <b>1-1</b>\n` +
            `- Gap antar gol >= <b>5</b> menit (aktual <b>${maxGap}</b>)\n` +
            `- First goal != menit <b>1</b> (aktual <b>${firstGoalMin}'</b>)\n` +
            `- Urutan menit gol 1H: <b>${escapeHtml(minuteText)}</b>\n\n` +
            `[HOT] <i>P7 lolos dan FT O/U O0.75 sudah > 1.95.</i>`;

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
        if (!isPatternNotificationWindow(status)) continue;
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

        if (goalMins.length < 2) continue;

        let maxGap = 0;
        for (let i = 1; i < goalMins.length; i += 1) {
            maxGap = Math.max(maxGap, goalMins[i] - goalMins[i - 1]);
        }
        if (maxGap < 2) continue;

        const targetOdd = getPatternTelegramOdd(match);
        if (!targetOdd) continue;

        sentP19Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const league = escapeHtml(match?.league || '?');
        const score = `${homeScore}-${awayScore}`;
        const minuteText = goalMins.map((min) => `${min}'`).join(', ');
        const gapText = `Gap max <b>${maxGap}</b> menit`;

        const msg =
            `[ALERT] <b>P19 SIGNAL - POTENSI GOL BABAK 2</b>\n` +
            `Goal <b>${home} vs ${away}</b>\n` +
            `[LEAGUE] League: ${league}\n` +
            `[SCORE] Skor: <b>${score}</b>\n` +
            `[TIME] Status: <b>${escapeHtml(status)}</b>\n\n` +
            `[TARGET] Market: <b>${escapeHtml(targetOdd.marketName)}: ${escapeHtml(targetOdd.label)} @ ${escapeHtml(targetOdd.oddValue.toFixed(2))}</b>\n\n` +
            `[NOTE] Pola P19 terpenuhi:\n` +
            `- Last goal 1H menit <b>${lastGoalMin}'</b>\n` +
            `- Last scorer <b>HOME</b>\n` +
            `- ${gapText}\n` +
            `- Urutan menit gol 1H: <b>${escapeHtml(minuteText)}</b>\n\n` +
            `[HOT] <i>P19 lolos dan FT O/U O0.75 sudah > 1.95.</i>`;

        await sendTelegramText(msg);
    }
}

async function trackP28Signal(matches) {
    if (!Array.isArray(matches) || !matches.length) return;

    for (const match of matches) {
        const key = createMatchKey(match);
        if (!registeredMatchKeys.has(key)) continue;
        if (sentP28Signals.has(key)) continue;

        const status = String(match?.status || '').trim();
        if (!isPatternNotificationWindow(status)) continue;
        if (has2HGoalByMatchKey.get(key)) continue;

        const homeTeam = normalizeTeamName(match?.homeTeam || '');
        const awayTeam = normalizeTeamName(match?.awayTeam || '');
        const isTargetTeam = ['croatia', 'france'].some((team) => homeTeam.includes(team) || awayTeam.includes(team));
        if (!isTargetTeam) continue;

        const homeScore = parseInt(match?.homeScore ?? '0', 10);
        const awayScore = parseInt(match?.awayScore ?? '0', 10);
        const goalMins = all1HGoalMinsByMatchKey.get(key) || [];
        const scorers = all1HScorersByMatchKey.get(key) || [];
        if (!goalMins.length || !scorers.length) continue;

        const firstGoalMin = goalMins[0];
        const lastGoalMin = goalMins[goalMins.length - 1];
        const span = lastGoalMin - firstGoalMin;
        let switches = 0;
        for (let i = 1; i < scorers.length; i += 1) {
            if (scorers[i] !== scorers[i - 1]) switches += 1;
        }

        if (lastGoalMin < 3 || span < 3 || switches < 1) continue;

        const targetOdd = getPatternTelegramOdd(match);
        if (!targetOdd) continue;

        sentP28Signals.add(key);

        const home = escapeHtml(match?.homeTeam || '?');
        const away = escapeHtml(match?.awayTeam || '?');
        const league = escapeHtml(match?.league || '?');
        const score = `${homeScore}-${awayScore}`;
        const minuteText = goalMins.map((min) => `${min}'`).join(', ');

        const msg =
            `[ALERT] <b>P28 SIGNAL - POTENSI GOL BABAK 2</b>\n` +
            `Goal <b>${home} vs ${away}</b>\n` +
            `[LEAGUE] League: ${league}\n` +
            `[SCORE] Skor: <b>${score}</b>\n` +
            `[TIME] Status: <b>${escapeHtml(status)}</b>\n\n` +
            `[TARGET] Market: <b>${escapeHtml(targetOdd.marketName)}: ${escapeHtml(targetOdd.label)} @ ${escapeHtml(targetOdd.oddValue.toFixed(2))}</b>\n\n` +
            `[NOTE] Pola P28 terpenuhi:\n` +
            `- Croatia atau France bermain\n` +
            `- Last goal 1H menit <b>${lastGoalMin}'</b>\n` +
            `- Span gol <b>${span}</b> menit\n` +
            `- Ada balas gol 1H <b>${switches}x</b>\n` +
            `- Urutan menit gol 1H: <b>${escapeHtml(minuteText)}</b>\n\n` +
            `[HOT] <i>P28 lolos dan FT O/U O0.75 sudah > 1.95.</i>`;

        await sendTelegramText(msg);
    }
}
