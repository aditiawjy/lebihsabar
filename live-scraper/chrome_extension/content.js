function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function isVisibleElement(element) {
    if (!element) return false;
    const rect = element.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
}

function getMatchBriefKey(brief) {
    if (!brief) return '';
    const teams = Array.from(brief.querySelectorAll('.match-brief__team'))
        .map((team) => team.innerText.trim())
        .filter(Boolean);
    return teams.join(' vs ') || brief.innerText.trim().slice(0, 120);
}

function hasLoadedMatchDetail(brief) {
    const next = brief?.nextElementSibling;
    if (!next || !next.classList.contains('match')) return false;
    return Boolean(
        next.querySelector('.match__bettype, .odds-button, .match-team__score, .match-time-live')
        && isVisibleElement(next)
    );
}

function findDetailClickTarget(brief) {
    if (!brief) return null;
    return brief.querySelector('button, [role="button"], .match-brief__main, .match-brief__teams') || brief;
}

function isLiveDetailPage() {
    return /\/live\/\d+\/\d+\/\d+(?:[/?#]|$)/i.test(window.location.pathname + window.location.search + window.location.hash);
}

function normalizeDetailUrl(url) {
    if (!url) return null;
    try {
        const parsed = new URL(url, window.location.href);
        return /\/live\/\d+\/\d+\/\d+(?:[/?#]|$)/i.test(parsed.pathname + parsed.search + parsed.hash)
            ? parsed.href
            : null;
    } catch (_) {
        return null;
    }
}

function findDetailUrlForBrief(brief) {
    if (!brief) return null;

    const candidates = [];
    if (brief.matches('a[href]')) {
        candidates.push(brief.getAttribute('href'));
    }

    brief.querySelectorAll('a[href], [href], [data-href], [data-url], [data-link], [to]').forEach((el) => {
        candidates.push(
            el.getAttribute('href'),
            el.getAttribute('data-href'),
            el.getAttribute('data-url'),
            el.getAttribute('data-link'),
            el.getAttribute('to')
        );
    });

    [brief, ...Array.from(brief.querySelectorAll('*')).slice(0, 80)].forEach((el) => {
        Array.from(el.attributes || []).forEach((attr) => {
            if (/live\/\d+\/\d+\/\d+/i.test(attr.value || '')) {
                candidates.push(attr.value);
            }
        });
    });

    for (const candidate of candidates) {
        const detailUrl = normalizeDetailUrl(candidate);
        if (detailUrl) {
            return detailUrl;
        }
    }

    return null;
}

function findDetailUrlForMatch(matchEl, brief) {
    return findDetailUrlForBrief(brief) || findDetailUrlForBrief(matchEl);
}

async function ensureVisibleMatchDetails() {
    if (typeof AUTO_DETAIL_ENABLED === 'boolean' && !AUTO_DETAIL_ENABLED) {
        return { opened: 0, checked: 0 };
    }

    if (isLiveDetailPage()) {
        return { opened: 0, checked: 0, mode: 'detail-page' };
    }

    const maxOpen = typeof AUTO_DETAIL_MAX_MATCHES_PER_CYCLE === 'number' ? AUTO_DETAIL_MAX_MATCHES_PER_CYCLE : 20;
    const settleMs = typeof AUTO_DETAIL_SETTLE_MS === 'number' ? AUTO_DETAIL_SETTLE_MS : 250;
    const briefs = Array.from(document.querySelectorAll('.match-brief')).filter(isVisibleElement);
    let opened = 0;
    let checked = 0;

    for (const brief of briefs) {
        if (opened >= maxOpen) break;
        checked += 1;
        if (hasLoadedMatchDetail(brief)) continue;

        const beforeKey = getMatchBriefKey(brief);
        const target = findDetailClickTarget(brief);
        if (!target) continue;

        target.click();
        opened += 1;
        await sleep(settleMs);

        if (beforeKey && !hasLoadedMatchDetail(brief)) {
            const afterKey = getMatchBriefKey(brief);
            if (afterKey && afterKey !== beforeKey) {
                break;
            }
        }
    }

    return { opened, checked };
}

function extractLiveData() {

    function parseLocaleFloat(value) {
        return parseFloat(String(value || '').replace(',', '.'));
    }

    function formatOddsValue(oddsValue, isMinus, shouldConvert) {
        if (!shouldConvert) {
            return oddsValue;
        }

        const numericOdds = parseLocaleFloat(oddsValue);
        if (!Number.isFinite(numericOdds)) {
            return oddsValue;
        }

        if (isMinus || oddsValue.startsWith('-')) {
            const absOdds = Math.abs(numericOdds);
            return absOdds > 0 ? (1 + (1 / absOdds)).toFixed(2) : oddsValue;
        }

        return (1 + numericOdds).toFixed(2);
    }

    function parseBetTypeElement(betType) {
        const betTypeTitle = (
            betType.querySelector('.match__bettype-title')?.innerText ||
            betType.querySelector('.bettype__header-text')?.innerText ||
            ''
        ).trim();
        if (!betTypeTitle) {
            return null;
        }

        const items = Array.from(betType.querySelectorAll('.bettype__item'));
        if (items.length) {
            const options = [];
            items.slice(0, typeof MAX_ODDS_BUTTONS_PER_MARKET === 'number' ? MAX_ODDS_BUTTONS_PER_MARKET : 12)
                .forEach((item) => {
                    const label = item.querySelector('.bettype__title, .bettype__text')?.innerText?.trim();
                    const oddsEl = item.querySelector('.odds-button__odds');
                    const oddsButton = item.querySelector('.odds-button');
                    const isLocked = oddsButton?.getAttribute('data-odds-status') === 'close-price';
                    const oddsValue = oddsEl?.innerText?.trim();

                    if (isLocked && label) {
                        options.push(`${label}:[LOCKED]`);
                    } else if (label && oddsValue) {
                        options.push(`${label}:${oddsValue}`);
                    }
                });

            return options.length ? `${betTypeTitle}: ${options.join(' | ')}` : null;
        }

        const oddsButtons = Array.from(betType.querySelectorAll('.odds-button'))
            .slice(0, typeof MAX_ODDS_BUTTONS_PER_MARKET === 'number' ? MAX_ODDS_BUTTONS_PER_MARKET : 12);
        const betOptions = [];

        oddsButtons.forEach((btn) => {
            const isLocked = btn.getAttribute('data-odds-status') === 'close-price';
            const goal = btn.querySelector('.odds-button__goal')?.innerText?.trim();
            const oddsValue = btn.querySelector('.odds-button__odds')?.innerText?.trim();
            const isMinus = btn.querySelector('.odds-button__odds')?.getAttribute('data-minus') === 'true';

            if (isLocked) {
                betOptions.push('[LOCKED]');
            } else if (goal && oddsValue) {
                const is1X2 = betTypeTitle.includes('1X2');
                betOptions.push(`${goal}:${formatOddsValue(oddsValue, isMinus, !is1X2)}`);
            }
        });

        return betOptions.length > 0 ? `${betTypeTitle}: ${betOptions.join(' | ')}` : null;
    }

    function extractNextGoalOdds(matchEl) {
        const betTypes = Array.from(matchEl.querySelectorAll('.match__bettype, .bettype'));

        for (const betType of betTypes) {
            const betTypeTitle = (
                betType.querySelector('.match__bettype-title')?.innerText ||
                betType.querySelector('.bettype__header-text')?.innerText ||
                ''
            ).trim();

            if (betTypeTitle.toLowerCase() !== 'next goal') {
                continue;
            }

            const result = {};
            Array.from(betType.querySelectorAll('.bettype__item')).forEach((item) => {
                const label = item.querySelector('.bettype__title, .bettype__text')?.innerText?.trim().toLowerCase();
                const oddsEl = item.querySelector('.odds-button__odds');
                const oddsButton = item.querySelector('.odds-button');
                const isLocked = oddsButton?.getAttribute('data-odds-status') === 'close-price';
                const oddsValue = oddsEl?.innerText?.trim();

                if (!label || isLocked || !oddsValue) {
                    return;
                }

                if (label === 'home') result.home = oddsValue;
                if (label === 'away') result.away = oddsValue;
                if (label === 'none') result.none = oddsValue;
            });

            if (Object.keys(result).length) {
                return result;
            }
        }

        return null;
    }

    function extractTextList(selectors) {
        const values = [];
        selectors.forEach((selector) => {
            document.querySelectorAll(selector).forEach((el) => {
                const text = el.innerText?.trim();
                if (text && text.length <= 80 && !values.includes(text)) {
                    values.push(text);
                }
            });
        });
        return values;
    }

    function extractMetaContent(patterns) {
        const candidates = [
            document.querySelector('h1')?.innerText,
            document.querySelector('[class*="event-header"]')?.innerText,
            document.querySelector('[class*="match-header"]')?.innerText,
            document.querySelector('[class*="breadcrumb"]')?.innerText,
            document.title
        ].filter(Boolean);

        for (const value of candidates) {
            const text = String(value || '').replace(/\s+/g, ' ').trim();
            for (const pattern of patterns) {
                const match = text.match(pattern);
                if (match) {
                    return match;
                }
            }
        }

        return null;
    }

    function extractDetailTeamsFromText() {
        const teamEls = extractTextList([
            '.match-brief__team',
            '.match-team__name',
            '.event-team__name',
            '.competitor-name',
            '[class*="team-name"]',
            '[class*="participant-name"]',
            '[class*="competitor"] [class*="name"]'
        ]);

        if (teamEls.length >= 2) {
            return [teamEls[0], teamEls[1]];
        }

        const match = extractMetaContent([
            /(.+?)\s+(?:vs|v)\s+(.+?)(?:\s+\d+\s*[-:]\s*\d+|\s*$)/i,
            /(.+?)\s+[-–]\s+(.+?)(?:\s+\d+\s*[-:]\s*\d+|\s*$)/i
        ]);

        if (match) {
            return [match[1].trim(), match[2].trim()];
        }

        const titleParts = document.title.split(/[-|]/).map((part) => part.trim()).filter(Boolean);
        return [titleParts[0] || 'Detail Home', titleParts[1] || 'Detail Away'];
    }

    function extractDetailScore() {
        const explicitScores = extractTextList([
            '.match-team__score',
            '[class*="team__score"]',
            '[class*="score"]'
        ]).filter((value) => /^\d+$/.test(value));

        if (explicitScores.length >= 2) {
            return [explicitScores[0], explicitScores[1]];
        }

        const scoreMatch = extractMetaContent([/(\d+)\s*[-:]\s*(\d+)/]);
        return scoreMatch ? [scoreMatch[1], scoreMatch[2]] : ['0', '0'];
    }

    function extractStandaloneDetailMatch() {
        const root = document;
        const betTypes = Array.from(root.querySelectorAll('.match__bettype, .bettype'));
        if (!betTypes.length) {
            return null;
        }

        const odds = betTypes
            .slice(0, typeof MAX_ODDS_MARKETS_PER_MATCH === 'number' ? MAX_ODDS_MARKETS_PER_MATCH : 8)
            .map((betType) => parseBetTypeElement(betType))
            .filter(Boolean);
        const nextGoalOdds = extractNextGoalOdds(root);

        if (!odds.length && !nextGoalOdds) {
            return null;
        }

        const teams = extractDetailTeamsFromText();
        const scores = extractDetailScore();
        const status =
            document.querySelector('.match-time-live')?.innerText?.trim() ||
            extractTextList(['[class*="time-live"]', '[class*="match-time"]', '[class*="status"]'])
                .find((value) => /^(1H|2H)\s+\d+'$|^H\.?Time$/i.test(value)) ||
            '-';

        const homeTeam = teams[0] || 'Detail Home';
        const awayTeam = teams[1] || 'Detail Away';
        const homeScore = scores[0] || '0';
        const awayScore = scores[1] || '0';

        return {
            league: document.querySelector('.league-header__name, [class*="league"]')?.innerText?.trim() || 'Detail Page',
            homeTeam,
            awayTeam,
            homeScore,
            awayScore,
            score: `${homeScore} - ${awayScore}`,
            status,
            odds,
            ...(nextGoalOdds ? { nextGoalOdds } : {})
        };
    }

    const matches = [];
    const groupedMatches = [];
    const leagueHeaders = document.querySelectorAll('.league-header');

    leagueHeaders.forEach((header) => {
        const league = header.querySelector('.league-header__name')?.innerText?.trim() || '';
        const group = header.nextElementSibling;

        if (!group || !group.classList.contains('match-group')) {
            return;
        }

        const leagueMatches = [];
        const matchElements = group.querySelectorAll('.match');
        const briefElements = group.querySelectorAll('.match-brief');

        matchElements.forEach((matchEl, idx) => {
            const match = { league };
            const brief = briefElements[idx];
            match.listIndex = matches.length;

            if (brief) {
                const teams = brief.querySelectorAll('.match-brief__team');
                if (teams.length >= 2) {
                    match.homeTeam = teams[0].innerText.trim();
                    match.awayTeam = teams[1].innerText.trim();
                }

                const detailUrl = findDetailUrlForMatch(matchEl, brief);
                if (detailUrl) {
                    match.detailUrl = detailUrl;
                }
            }

            const hasPenaltyTeam =
                match.homeTeam?.includes('(PEN)') ||
                match.awayTeam?.includes('(PEN)');

            if (hasPenaltyTeam) {
                return;
            }

            const timeEl = matchEl.querySelector('.match-time-live');
            if (timeEl) {
                match.status = timeEl.innerText.trim();
            }

            const homeScoreEl = matchEl.querySelector('.match-team:first-child .match-team__score');
            const awayScoreEl = matchEl.querySelector('.match-team:last-child .match-team__score');
            if (homeScoreEl) {
                match.homeScore = homeScoreEl.innerText.trim();
            }
            if (awayScoreEl) {
                match.awayScore = awayScoreEl.innerText.trim();
            }

            match.odds = [];
            const betTypes = Array.from(matchEl.querySelectorAll('.match__bettype, .bettype'))
                .slice(0, typeof MAX_ODDS_MARKETS_PER_MATCH === 'number' ? MAX_ODDS_MARKETS_PER_MATCH : 8);

            betTypes.forEach((betType) => {
                const parsed = parseBetTypeElement(betType);
                if (parsed) {
                    match.odds.push(parsed);
                }
            });

            const nextGoalOdds = extractNextGoalOdds(matchEl);
            if (nextGoalOdds) {
                match.nextGoalOdds = nextGoalOdds;
            }

            if (match.homeTeam && match.awayTeam) {
                match.score = `${match.homeScore || '0'} - ${match.awayScore || '0'}`;
                matches.push(match);
                leagueMatches.push(match);
            }
        });

        if (leagueMatches.length > 0) {
            groupedMatches.push({
                league: league || 'Unknown League',
                matches: leagueMatches
            });
        }
    });

    if (!matches.length) {
        const detailMatch = extractStandaloneDetailMatch();
        if (detailMatch) {
            matches.push(detailMatch);
            groupedMatches.push({
                league: detailMatch.league || 'Detail Page',
                matches: [detailMatch]
            });
        }
    }

    return {
        matches,
        groupedMatches,
        count: matches.length,
        time: new Date().toLocaleTimeString()
    };
}

async function extractLiveDataWithDetails() {
    const detailState = await ensureVisibleMatchDetails();
    const data = extractLiveData();
    data.detailState = detailState;
    return data;
}

function clickRefreshButton() {
    const selectors = [
        'button.btn--icon svg.icon--refresh',
        'button svg.icon--refresh',
        'button[class*="refresh"]',
        'button[aria-label*="Refresh" i]',
        'button[title*="Refresh" i]',
        'button[aria-label*="Reload" i]',
        'button[title*="Reload" i]'
    ];

    let btn = null;
    for (const selector of selectors) {
        const element = document.querySelector(selector);
        if (element) {
            btn = element.closest('button') || element;
            break;
        }
    }

    if (!btn) {
        const buttons = Array.from(document.querySelectorAll('button'));
        btn = buttons.find((candidate) => {
            const text = `${candidate.innerText || ''} ${candidate.getAttribute('aria-label') || ''} ${candidate.getAttribute('title') || ''}`.toLowerCase();
            return text.includes('refresh') || text.includes('reload') || text.includes('update');
        }) || null;
    }

    if (btn) {
        btn.click();
        return {
            clicked: true,
            selector: btn.outerHTML?.slice(0, 160) || 'button'
        };
    }

    return {
        clicked: false,
        selector: null
    };
}

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    (async () => {
        switch (message?.action) {
            case 'ping':
                sendResponse({ ok: true });
                break;
            case 'extractData':
                sendResponse({ ok: true, data: await extractLiveDataWithDetails() });
                break;
            case 'clickRefresh':
                sendResponse({ ok: true, data: clickRefreshButton() });
                break;
            case 'openMatchDetailByIndex':
                sendResponse({ ok: true, data: await openMatchDetailByIndex(message.index) });
                break;
            default:
                sendResponse({ ok: false, error: 'Unknown content action' });
        }
    })().catch((error) => {
        sendResponse({ ok: false, error: error.message || 'Content script failed' });
    });

    return true;
});

async function openMatchDetailByIndex(index) {
    const wantedIndex = Number(index);
    if (!Number.isInteger(wantedIndex) || wantedIndex < 0) {
        return { opened: false, error: 'Invalid match index' };
    }

    const matchPairs = [];
    document.querySelectorAll('.league-header').forEach((header) => {
        const group = header.nextElementSibling;
        if (!group || !group.classList.contains('match-group')) return;

        const briefs = Array.from(group.querySelectorAll('.match-brief'));
        const matches = Array.from(group.querySelectorAll('.match'));
        matches.forEach((matchEl, idx) => {
            matchPairs.push({
                matchEl,
                brief: briefs[idx] || null
            });
        });
    });

    const pair = matchPairs[wantedIndex];
    if (!pair) {
        return { opened: false, error: 'Match index not found' };
    }

    const detailUrl = findDetailUrlForMatch(pair.matchEl, pair.brief);
    if (detailUrl) {
        window.location.href = detailUrl;
        return { opened: true, detailUrl };
    }

    const target = findDetailClickTarget(pair.brief) || findDetailClickTarget(pair.matchEl) || pair.matchEl;
    if (!target) {
        return { opened: false, error: 'Detail click target not found' };
    }

    target.click();
    return { opened: true, clicked: true };
}
