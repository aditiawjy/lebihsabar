function extractLiveData() {
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

            if (brief) {
                const teams = brief.querySelectorAll('.match-brief__team');
                if (teams.length >= 2) {
                    match.homeTeam = teams[0].innerText.trim();
                    match.awayTeam = teams[1].innerText.trim();
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
            const betTypes = matchEl.querySelectorAll('.match__bettype');

            betTypes.forEach((betType) => {
                const betTypeTitle = betType.querySelector('.match__bettype-title')?.innerText?.trim();
                if (!betTypeTitle) {
                    return;
                }

                const oddsButtons = betType.querySelectorAll('.odds-button');
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

                        if (is1X2) {
                            betOptions.push(`${goal}:${oddsValue}`);
                        } else {
                            const numericOdds = parseFloat(oddsValue);
                            let decimalOdds;

                            if (isMinus || oddsValue.startsWith('-')) {
                                const absOdds = Math.abs(numericOdds);
                                decimalOdds = (1 + (1 / absOdds)).toFixed(2);
                            } else {
                                decimalOdds = (1 + numericOdds).toFixed(2);
                            }

                            betOptions.push(`${goal}:${decimalOdds}`);
                        }
                    }
                });

                if (betOptions.length > 0) {
                    match.odds.push(`${betTypeTitle}: ${betOptions.join(' | ')}`);
                }
            });

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

    return {
        matches,
        groupedMatches,
        count: matches.length,
        time: new Date().toLocaleTimeString()
    };
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
    try {
        switch (message?.action) {
            case 'ping':
                sendResponse({ ok: true });
                break;
            case 'extractData':
                sendResponse({ ok: true, data: extractLiveData() });
                break;
            case 'clickRefresh':
                sendResponse({ ok: true, data: clickRefreshButton() });
                break;
            default:
                sendResponse({ ok: false, error: 'Unknown content action' });
        }
    } catch (error) {
        sendResponse({ ok: false, error: error.message || 'Content script failed' });
    }

    return false;
});
