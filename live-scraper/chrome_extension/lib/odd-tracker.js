function getDefaultCustomWatchConfig() {
    return {
        teamRules: [],
        customOddThreshold: DEFAULT_CUSTOM_WATCH_CONFIG.customOddThreshold,
        customOddSelection: DEFAULT_CUSTOM_WATCH_CONFIG.customOddSelection
    };
}

function normalizeWatchTeamRule(rule = {}, fallback = {}) {
    const fallbackThreshold = toThresholdNumber(fallback.customOddThreshold, DEFAULT_CUSTOM_WATCH_CONFIG.customOddThreshold);
    const fallbackSelection = normalizeWatchMarketSelection(fallback.customOddSelection, DEFAULT_CUSTOM_WATCH_CONFIG.customOddSelection);

    const normalizedTeam = normalizeTeamName(rule.team || rule.teamName || rule.name || rule.team_name);
    if (!normalizedTeam) {
        return null;
    }

    return {
        team: normalizedTeam,
        customOddThreshold: toThresholdNumber(rule.customOddThreshold ?? rule.threshold, fallbackThreshold),
        customOddSelection: normalizeWatchMarketSelection(rule.customOddSelection ?? rule.market ?? rule.selection, fallbackSelection)
    };
}

function normalizeWatchTeamRules(rawRules = [], fallback = {}) {
    if (!Array.isArray(rawRules) || !rawRules.length) {
        return [];
    }

    const seen = new Set();
    const nextRules = [];

    rawRules.forEach((rule) => {
        const normalizedRule = normalizeWatchTeamRule(rule, fallback);
        if (!normalizedRule || seen.has(normalizedRule.team)) {
            return;
        }

        seen.add(normalizedRule.team);
        nextRules.push(normalizedRule);
    });

    return nextRules;
}

async function getCustomWatchConfig() {
    const data = await chrome.storage.local.get([CUSTOM_WATCH_CONFIG_KEY]);
    const stored = data?.[CUSTOM_WATCH_CONFIG_KEY] || {};

    const fallbackThreshold = toThresholdNumber(stored.customOddThreshold, DEFAULT_CUSTOM_WATCH_CONFIG.customOddThreshold);
    const fallbackSelection = normalizeWatchMarketSelection(stored.customOddSelection, DEFAULT_CUSTOM_WATCH_CONFIG.customOddSelection);
    const legacyTeamList = Array.isArray(stored.teamList)
        ? stored.teamList
            .map((team) => normalizeTeamName(team))
            .filter(Boolean)
        : [];
    const normalizedLegacyRules = legacyTeamList.map((team) => ({
        team,
        customOddThreshold: fallbackThreshold,
        customOddSelection: fallbackSelection
    }));

    const normalizedRules = normalizeWatchTeamRules(stored.teamRules, {
        customOddThreshold: fallbackThreshold,
        customOddSelection: fallbackSelection
    });

    return {
        ...getDefaultCustomWatchConfig(),
        ...stored,
        teamRules: normalizedRules.length ? normalizedRules : normalizedLegacyRules,
        customOddThreshold: fallbackThreshold,
        customOddSelection: fallbackSelection
    };
}

function getWatchRuleByMatch(match, customTeamRules = []) {
    if (!Array.isArray(customTeamRules) || !customTeamRules.length) {
        return false;
    }

    const teams = [
        match?.homeTeam,
        match?.awayTeam
    ].map(normalizeTeamName).filter(Boolean);

    for (const rule of customTeamRules) {
        const teamName = normalizeTeamName(rule?.team);
        if (!teamName) {
            continue;
        }

        const matched = teams.some((team) => team.includes(teamName) || teamName.includes(team));
        if (matched) {
            return {
                team: teamName,
                customOddThreshold: toThresholdNumber(rule.customOddThreshold, DEFAULT_CUSTOM_WATCH_CONFIG.customOddThreshold),
                customOddSelection: normalizeWatchMarketSelection(rule.customOddSelection, DEFAULT_CUSTOM_WATCH_CONFIG.customOddSelection)
            };
        }
    }

    return null;
}

function isCustomWatchTeam(match, customTeamList = []) {
    return Boolean(getWatchRuleByMatch(match, customTeamList));
}

function getMatchWatchContext(match, customConfig = {}) {
    const normalizedTeamRules = Array.isArray(customConfig.teamRules) ? customConfig.teamRules : [];
    const matchTeamRule = getWatchRuleByMatch(match, normalizedTeamRules);

    const isWatched = Boolean(matchTeamRule);
    const baseThreshold = toThresholdNumber(customConfig.customOddThreshold, DEFAULT_CUSTOM_WATCH_CONFIG.customOddThreshold);
    const baseSelection = normalizeWatchMarketSelection(customConfig.customOddSelection, DEFAULT_CUSTOM_WATCH_CONFIG.customOddSelection);
    const appliedThreshold = isWatched
        ? toThresholdNumber(matchTeamRule.customOddThreshold, baseThreshold)
        : TARGET_ODD_MIN;
    const appliedSelection = isWatched
        ? normalizeWatchMarketSelection(matchTeamRule.customOddSelection, baseSelection)
        : TARGET_ODD_SELECTION;

    return {
        isWatched,
        watchTeamRule: matchTeamRule,
        appliedThreshold,
        appliedSelection,
        appliedBy: isWatched ? matchTeamRule?.team : null
    };
}

async function setCustomWatchConfig(payload = {}) {
    const fallbackThreshold = toThresholdNumber(payload.customOddThreshold, DEFAULT_CUSTOM_WATCH_CONFIG.customOddThreshold);
    const fallbackSelection = normalizeWatchMarketSelection(payload.customOddSelection, DEFAULT_CUSTOM_WATCH_CONFIG.customOddSelection);
    const legacyTeamList = Array.isArray(payload.teamList)
        ? payload.teamList
            .map((team) => normalizeTeamName(team))
            .filter(Boolean)
        : [];

    const normalizedRulesFromPayload = normalizeWatchTeamRules(payload.teamRules, {
        customOddThreshold: fallbackThreshold,
        customOddSelection: fallbackSelection
    });

    const fallbackRules = legacyTeamList.map((team) => ({
        team,
        customOddThreshold: fallbackThreshold,
        customOddSelection: fallbackSelection
    }));

    const normalized = {
        teamRules: normalizedRulesFromPayload.length ? normalizedRulesFromPayload : fallbackRules,
        customOddThreshold: toThresholdNumber(payload.customOddThreshold, DEFAULT_CUSTOM_WATCH_CONFIG.customOddThreshold),
        customOddSelection: normalizeWatchMarketSelection(payload.customOddSelection, DEFAULT_CUSTOM_WATCH_CONFIG.customOddSelection)
    };

    await chrome.storage.local.set({ [CUSTOM_WATCH_CONFIG_KEY]: normalized });
    return normalized;
}

function getOverOddsBySelection(match, selections = [TARGET_ODD_SELECTION], marketFilter = TARGET_ODD_MARKET) {
    const odds = Array.isArray(match?.odds) ? match.odds : [];
    const wantedSelections = new Set(selections.map((selection) => normalizeMarketValue(selection)));
    const foundSelections = {};

    for (const marketLine of odds) {
        const parts = String(marketLine || '').split(': ');
        if (parts.length < 2) {
            continue;
        }

        const marketName = parts[0].trim();
        if (!normalizeMarketValue(marketName).includes(marketFilter)) {
            continue;
        }

        const optionsText = parts.slice(1).join(': ');
        const options = optionsText.split(' | ');
        for (const option of options) {
            if (option === '[LOCKED]') {
                continue;
            }

            const separatorIndex = option.indexOf(':');
            if (separatorIndex === -1) {
                continue;
            }

            const label = option.slice(0, separatorIndex).trim();
            const oddText = option.slice(separatorIndex + 1).trim();
            const oddValue = parseLocaleFloat(oddText);
            if (!Number.isFinite(oddValue)) {
                continue;
            }

            const matchedSelection = Array.from(wantedSelections).find((wantedSelection) => isSelectionMatch(label, marketName, wantedSelection));
            if (!matchedSelection) {
                continue;
            }

            foundSelections[matchedSelection] = {
                marketName,
                label,
                oddValue
            };

            if (Object.keys(foundSelections).length === wantedSelections.size) {
                return foundSelections;
            }
        }
    }

    return foundSelections;
}

function getTargetOverOdd(match, marketSelection = TARGET_ODD_SELECTION, marketFilter = TARGET_ODD_MARKET) {
    const oddsMap = getOverOddsBySelection(match, [marketSelection], marketFilter);
    const normalizedSelection = normalizeMarketValue(marketSelection);
    return oddsMap[normalizedSelection] || null;
}

function getQualifiedOddsAlert(match, marketSelection = TARGET_ODD_SELECTION, threshold = TARGET_ODD_MIN, isWatched = false) {
    if (!isWatched && !isSecondHalfStatus(match)) {
        return null;
    }

    const normalizedSelection = normalizeWatchMarketSelection(marketSelection, TARGET_ODD_SELECTION);
    const marketFilter = isWatched ? TARGET_FT_ODD_MARKET : TARGET_ODD_MARKET;
    const targetOdd = getTargetOverOdd(match, normalizedSelection, marketFilter);
    const numericThreshold = toThresholdNumber(threshold, TARGET_ODD_MIN);

    if (!targetOdd || !(targetOdd.oddValue >= numericThreshold)) {
        return null;
    }

    return targetOdd;
}

function pruneMatchAlertState(matches) {
    const activeMatchKeys = new Set(matches.map((match) => createMatchKey(match)));

    const isStateMatchForActiveMatch = (stateKey) => activeMatchKeys.has(splitStateKey(stateKey).matchKey);

    const notifiedMatchKeysNormalized = Array.from(notifiedMatchKeys)
        .filter((stateKey) => isStateMatchForActiveMatch(stateKey));
    const oddHistoryKeysNormalized = Array.from(oddHistoryByMatchKey.keys())
        .filter((matchKey) => activeMatchKeys.has(matchKey));
    const oddInsightKeysNormalized = Array.from(oddInsightByMatchKey.keys())
        .filter((matchKey) => activeMatchKeys.has(matchKey));

    notifiedMatchKeys = new Set(notifiedMatchKeysNormalized);

    wasAboveThresholdByMatchKey = new Map(
        Array.from(wasAboveThresholdByMatchKey.entries())
            .filter(([stateKey]) => isStateMatchForActiveMatch(stateKey))
    );

    oddHistoryByMatchKey = new Map(
        Array.from(oddHistoryByMatchKey.entries())
            .filter(([matchKey]) => oddHistoryKeysNormalized.includes(matchKey))
    );

    oddInsightByMatchKey = new Map(
        Array.from(oddInsightByMatchKey.entries())
            .filter(([matchKey]) => oddInsightKeysNormalized.includes(matchKey))
    );
}

function splitStateKey(stateKey) {
    const keyText = String(stateKey || '');
    const delimiter = '::';
    const lastIndex = keyText.lastIndexOf(delimiter);
    if (lastIndex === -1) {
        return {
            matchKey: keyText,
            threshold: TARGET_ODD_MIN,
            selection: TARGET_ODD_SELECTION
        };
    }

    const prevIndex = keyText.lastIndexOf(delimiter, lastIndex - 1);

    if (prevIndex === -1) {
        return {
            matchKey: keyText.slice(0, lastIndex),
            threshold: Number(keyText.slice(lastIndex + delimiter.length)) || TARGET_ODD_MIN,
            selection: TARGET_ODD_SELECTION
        };
    }

    return {
        matchKey: keyText.slice(0, prevIndex),
        threshold: Number(keyText.slice(prevIndex + delimiter.length, lastIndex)) || TARGET_ODD_MIN,
        selection: String(keyText.slice(lastIndex + delimiter.length)) || TARGET_ODD_SELECTION
    };
}

function getThresholdStateKey(matchKey, threshold, marketSelection = TARGET_ODD_SELECTION) {
    const normalizedThreshold = toThresholdNumber(threshold, TARGET_ODD_MIN).toFixed(2);
    const normalizedSelection = normalizeWatchMarketSelection(marketSelection, TARGET_ODD_SELECTION);
    return `${matchKey}::${normalizedThreshold}::${normalizedSelection}`;
}

function getRecentHistoryPoint(history, timeWindowMs) {
    if (!Array.isArray(history) || history.length === 0) {
        return null;
    }

    const latest = history[history.length - 1];
    for (let index = history.length - 1; index >= 0; index -= 1) {
        const point = history[index];
        if ((latest.timestamp - point.timestamp) >= timeWindowMs) {
            return point;
        }
    }

    return history[0] || null;
}

function computeAboveThresholdDurationMs(history, threshold = TARGET_ODD_MIN) {
    if (!Array.isArray(history) || history.length === 0) {
        return 0;
    }

    const latest = history[history.length - 1];
    if (!(latest.oddValue > threshold)) {
        return 0;
    }

    let startTimestamp = latest.timestamp;
    for (let index = history.length - 2; index >= 0; index -= 1) {
        const point = history[index];
        if (!(point.oddValue > threshold)) {
            break;
        }
        startTimestamp = point.timestamp;
    }

    return latest.timestamp - startTimestamp;
}

function countThresholdCrosses(history, threshold = TARGET_ODD_MIN) {
    if (!Array.isArray(history) || history.length <= 1) {
        return 0;
    }

    let crosses = 0;
    for (let index = 1; index < history.length; index += 1) {
        const prevAbove = history[index - 1].oddValue > threshold;
        const currAbove = history[index].oddValue > threshold;
        if (prevAbove !== currAbove) {
            crosses += 1;
        }
    }

    return crosses;
}

function createOddInsight(match, history, targetOdd, watchContext = {}, htScore = null) {
    const latest = history[history.length - 1] || null;
    const previous = history.length > 1 ? history[history.length - 2] : null;
    const windowPoint = getRecentHistoryPoint(history, ODD_SPIKE_WINDOW_MS);
    const threshold = toThresholdNumber(watchContext.appliedThreshold, TARGET_ODD_MIN);
    const selection = normalizeWatchMarketSelection(watchContext.appliedSelection, TARGET_ODD_SELECTION);
    const deltaFromPrevious = latest && previous ? latest.oddValue - previous.oddValue : 0;
    const deltaFromWindow = latest && windowPoint ? latest.oddValue - windowPoint.oddValue : 0;
    const aboveThresholdDurationMs = computeAboveThresholdDurationMs(history, threshold);
    const thresholdCrosses = countThresholdCrosses(history, threshold);
    const maxOdd = history.reduce((maxValue, point) => Math.max(maxValue, point.oddValue), latest ? latest.oddValue : 0);
    const crossedUp = latest && previous ? previous.oddValue <= threshold && latest.oddValue > threshold : false;
    const crossedDown = latest && previous ? previous.oddValue > threshold && latest.oddValue <= threshold : false;

    let pattern = 'Tracking';
    let severity = 'neutral';
    const thresholdText = threshold.toFixed(2);

    if (crossedDown && maxOdd > threshold) {
        pattern = 'Fake Breakout';
        severity = 'danger';
    } else if (latest && latest.oddValue > threshold && aboveThresholdDurationMs >= ODD_BREAKOUT_HOLD_MS) {
        pattern = 'Breakout';
        severity = 'danger';
    } else if (Math.abs(deltaFromWindow) >= ODD_SPIKE_DELTA) {
        pattern = deltaFromWindow > 0 ? 'Spike Up' : 'Spike Down';
        severity = deltaFromWindow > 0 ? 'warning' : 'neutral';
    } else if (crossedUp) {
        pattern = `Cross > ${thresholdText}`;
        severity = 'warning';
    } else if (thresholdCrosses >= 3) {
        pattern = 'Volatile';
        severity = 'warning';
    }

    const comparisonOdds = getOverOddsBySelection(match, COMPARISON_ODD_SELECTIONS);

    return {
        matchKey: createMatchKey(match),
        marketSelection: selection,
        threshold,
        marketDisplay: formatWatchMarketForDisplay(selection),
        marketName: targetOdd.marketName,
        label: targetOdd.label,
        currentOdd: latest ? latest.oddValue : null,
        previousOdd: previous ? previous.oddValue : null,
        deltaFromPrevious,
        deltaFromWindow,
        aboveThresholdDurationMs,
        thresholdCrosses,
        maxOdd,
        pattern,
        severity,
        status: String(match?.status || '').trim(),
        score: String(match?.score || '').trim(),
        updatedAt: latest ? latest.timestamp : Date.now(),
        isSecondHalf: isSecondHalfStatus(match),
        isAboveThreshold: latest ? latest.oddValue > threshold : false,
        htScore,
        comparisonOdds: Object.fromEntries(Object.entries(comparisonOdds).map(([key, value]) => [key, {
            label: value.label,
            oddValue: value.oddValue
        }]))
    };
}

async function updateOddTracking(matches) {
    const timestamp = Date.now();
    const watchConfig = await getCustomWatchConfig();

    for (const match of matches) {
        const matchKey = createMatchKey(match);
        const watchContext = getMatchWatchContext(match, watchConfig);
        const marketFilter = watchContext.isWatched ? TARGET_FT_ODD_MARKET : TARGET_ODD_MARKET;
        const targetOdd = getTargetOverOdd(match, watchContext.appliedSelection, marketFilter);

        if (!isSecondHalfStatus(match) || !targetOdd) {
            oddInsightByMatchKey.delete(matchKey);
            continue;
        }

        const history = oddHistoryByMatchKey.get(matchKey) || [];
        const lastPoint = history[history.length - 1] || null;
        if (!lastPoint || lastPoint.oddValue !== targetOdd.oddValue || lastPoint.status !== String(match?.status || '').trim() || lastPoint.score !== String(match?.score || '').trim()) {
            history.push({
                timestamp,
                oddValue: targetOdd.oddValue,
                status: String(match?.status || '').trim(),
                score: String(match?.score || '').trim()
            });
        }

        oddHistoryByMatchKey.set(matchKey, history.slice(-ODD_HISTORY_LIMIT));
        oddInsightByMatchKey.set(matchKey, createOddInsight(match, oddHistoryByMatchKey.get(matchKey), targetOdd, watchContext, shScoreByMatchKey.get(matchKey) || null));
    }

    await chrome.storage.local.set({
        liveOddInsights: Object.fromEntries(Array.from(oddInsightByMatchKey.entries())),
        liveHtScores: Object.fromEntries(Array.from(shScoreByMatchKey.entries()))
    });
}

function formatMatchMessage(match, targetOdd, watchContext = null) {
    const isWatchedTeam = Boolean(watchContext?.isWatched);
    const appliedThreshold = toThresholdNumber(watchContext?.appliedThreshold, TARGET_ODD_MIN);
    const appliedSelection = watchContext?.appliedSelection || TARGET_ODD_SELECTION;
    const normalizedSelection = normalizeWatchMarketSelection(appliedSelection, TARGET_ODD_SELECTION);
    const odds = Array.isArray(match?.odds) ? match.odds.slice(0, 3) : [];
    const lines = [
        '🚨 <b>LIVE O/U ALERT</b>',
        '',
        `⚽ <b>${escapeHtml(getMatchTeams(match))}</b>`,
        `📊 Score: <b>${escapeHtml(match?.score || '0-0')}</b>`,
        `🏆 League: ${escapeHtml(match?.league || 'N/A')}`,
        `⏰ Status: ${escapeHtml(match?.status || 'Live')}`,
        `🎯 Market: <b>${escapeHtml(targetOdd?.marketName || 'O/U')}: ${escapeHtml(targetOdd?.label || normalizedSelection)} @ ${escapeHtml((targetOdd?.oddValue || 0).toFixed(2))}</b>`,
        `👀 Watch team: ${isWatchedTeam ? 'Ya' : 'Tidak'}`,
        `📐 Rule: >${appliedThreshold.toFixed(2)}`,
        `📅 Time: ${new Date().toLocaleTimeString()}`,
        '',
        `🔥 <i>Alert dikirim saat market ${escapeHtml(targetOdd?.label || normalizedSelection)} sudah di atas ${appliedThreshold.toFixed(2)}.</i>`
    ];

    if (odds.length) {
        lines.push('', '📈 <b>Odds:</b>');
        odds.forEach((odd) => lines.push(`• ${escapeHtml(odd)}`));
    }

    return lines.join('\n');
}
