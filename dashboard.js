(function() {
    'use strict';

    var INITIAL_DATA = JSON.parse(document.getElementById('initial-data').textContent);
    var PATTERN_DATA = {};
    var PATTERN_STATS = {};
    var PATTERN_DEFS = INITIAL_DATA.patternDefs || [];
    var TEAM_CONFIG = INITIAL_DATA.teamConfig || {};

    var activePanel = null;
    var htMemory = {};
    var prevStateMemory = {};
    var liveGoalMemory = {};
    var liveScorerMemory = {};
    var livePatternStateMemory = {};
    var SUMMARY_MIN_SAMPLE = 10;
    var NEXT_MIN_SAMPLE = 0;
    var LATE_MIN_SAMPLE = 9;
    var SUMMARY_REFRESH_SECONDS = 5;
    var LIVE_FETCH_INTERVAL_MS = 2000;
    var refreshCountdown = SUMMARY_REFRESH_SECONDS;
    var countdownEl = document.getElementById('countdown');
    var summarySyncStateEl = document.getElementById('summary-sync-state');
    var latestCsvTime = INITIAL_DATA.csvTime || null;
    var latestGeneratedAt = INITIAL_DATA.generatedAt || null;

    var summarySortState = { col: null, dir: 'desc' };
    var nextSortState = { col: null, dir: 'desc' };
    var currentSummaryData = [];
    var currentNextData = [];
    var currentLateData = [];
    var exactPatternSignatureCache = {};
    var liveCandidateCounts = {};
    var nextLiveCandidateCounts = {};
    var lateLiveCandidateCounts = {};
    var liveCandidateDetails = {};
    var nextLiveCandidateDetails = {};
    var lateLiveCandidateDetails = {};
    var settledSummaryResults = [];
    var settledLateResults = [];
    var LIVE_CANDIDATE_STORAGE_KEY = 'liveCandidateState';
    var LIVE_PATTERN_STATE_STORAGE_KEY = 'livePatternStateMemory';
    var LIVE_SETTLED_STORAGE_KEY = 'liveSettledState';
    var LIVE_LATE_SETTLED_STORAGE_KEY = 'liveLateSettledState';
    var LIVE_STATE_SCHEMA_VERSION = '2026-05-02-rules-v150';
    var LIVE_CANDIDATE_CACHE_TTL_MS = 30000;
    var LIVE_CANDIDATE_GRACE_MS = 15000;
    var LIVE_SUMMARY_PRE_GOAL_CARRY_MS = 12 * 60 * 1000;
    var LIVE_PATTERN_STATE_TTL_MS = 15 * 60 * 1000;
    var LIVE_SETTLED_CACHE_TTL_MS = 12 * 60 * 60 * 1000;
    var DETAIL_ROWS_PER_PAGE = 10;
    var detailPageState = {};
    var restoredLiveCandidateState = false;
    var liveCandidateExpiryTimer = null;

    function getPatternLabelById(id) {
        for (var i = 0; i < PATTERN_DEFS.length; i++) {
            if (PATTERN_DEFS[i] && PATTERN_DEFS[i].id === id) {
                return PATTERN_DEFS[i].label || id;
            }
        }
        var lateDefs = INITIAL_DATA.latePatterns || [];
        for (var j = 0; j < lateDefs.length; j++) {
            if (lateDefs[j] && lateDefs[j].id === id) {
                return lateDefs[j].label || id;
            }
        }
        var nextDefs = INITIAL_DATA.nextPatterns || [];
        for (var k = 0; k < nextDefs.length; k++) {
            if (nextDefs[k] && nextDefs[k].id === id) {
                return nextDefs[k].label || id;
            }
        }
        return id;
    }

    function getStateSignature(s) {
        if (!s) return '';
        return s.league + '|' + s.h1_first + '|' + s.h1_last + '|' + (s.h1s || []).join('') + '|' + s.sc_h + '-' + s.sc_a;
    }

    function getMatchSignature(m) {
        if (!m) return '';
        return m.league + '|' + m.h1_first + '|' + m.h1_last + '|' + (m.h1s || []).join('') + '|' + m.sc_h + '-' + m.sc_a;
    }

    function getStateNoLeagueSignature(s) {
        if (!s) return '';
        return s.h1_first + '|' + s.h1_last + '|' + (s.h1s || []).join('') + '|' + s.sc_h + '-' + s.sc_a;
    }

    function getMatchNoLeagueSignature(m) {
        if (!m) return '';
        return m.h1_first + '|' + m.h1_last + '|' + (m.h1s || []).join('') + '|' + m.sc_h + '-' + m.sc_a;
    }

    function getStateShapeStatsSignature(s) {
        if (!s) return '';
        return s.league + '|' + s.h1c + '|' + s.h1_first + '|' + s.h1_last + '|' + s.sc_h + '-' + s.sc_a + '|' + s.switches + '|' + s.min_gap + '|' + s.max_gap;
    }

    function getMatchShapeStatsSignature(m) {
        if (!m) return '';
        return m.league + '|' + m.h1c + '|' + m.h1_first + '|' + m.h1_last + '|' + m.sc_h + '-' + m.sc_a + '|' + m.switches + '|' + m.min_gap + '|' + m.max_gap;
    }

    function getStateFullMinsSignature(s) {
        if (!s) return '';
        return s.league + '|' + (s.goal_mins || []).join(',') + '|' + (s.h1s || []).join('') + '|' + s.sc_h + '-' + s.sc_a;
    }

    function getMatchFullMinsSignature(m) {
        if (!m) return '';
        var mins = Array.isArray(m.h1) ? m.h1.map(function(g) { return g.min; }).join(',') : '';
        return m.league + '|' + mins + '|' + (m.h1s || []).join('') + '|' + m.sc_h + '-' + m.sc_a;
    }

    function matchesExactPatternFromData(pid, s) {
        if (!s) return false;
        if (!exactPatternSignatureCache[pid]) {
            var pattern = (INITIAL_DATA.patterns || []).find(function(item) { return item && item.id === pid; });
            var signatures = {};
            if (pattern && Array.isArray(pattern.data)) {
                pattern.data.forEach(function(match) {
                    var signature = getMatchSignature(match);
                    if (signature) signatures[signature] = true;
                });
            }
            exactPatternSignatureCache[pid] = signatures;
        }
        return !!exactPatternSignatureCache[pid][getStateSignature(s)];
    }

    function matchesNoLeagueExactPatternFromData(pid, s) {
        if (!s) return false;
        var cacheKey = pid + ':noLeague';
        if (!exactPatternSignatureCache[cacheKey]) {
            var pattern = (INITIAL_DATA.patterns || []).find(function(item) { return item && item.id === pid; });
            var signatures = {};
            if (pattern && Array.isArray(pattern.data)) {
                pattern.data.forEach(function(match) {
                    var signature = getMatchNoLeagueSignature(match);
                    if (signature) signatures[signature] = true;
                });
            }
            exactPatternSignatureCache[cacheKey] = signatures;
        }
        return !!exactPatternSignatureCache[cacheKey][getStateNoLeagueSignature(s)];
    }

    function matchesShapeStatsPatternFromData(pid, s) {
        if (!s) return false;
        var cacheKey = pid + ':shapeStats';
        if (!exactPatternSignatureCache[cacheKey]) {
            var pattern = (INITIAL_DATA.patterns || []).find(function(item) { return item && item.id === pid; });
            var signatures = {};
            if (pattern && Array.isArray(pattern.data)) {
                pattern.data.forEach(function(match) {
                    var signature = getMatchShapeStatsSignature(match);
                    if (signature) signatures[signature] = true;
                });
            }
            exactPatternSignatureCache[cacheKey] = signatures;
        }
        return !!exactPatternSignatureCache[cacheKey][getStateShapeStatsSignature(s)];
    }

    function matchesNextShapeStatsPatternFromData(pid, s) {
        if (!s) return false;
        var cacheKey = pid + ':nextShapeStats';
        if (!exactPatternSignatureCache[cacheKey]) {
            var pattern = (INITIAL_DATA.nextPatterns || []).find(function(item) { return item && item.id === pid; });
            var signatures = {};
            if (pattern && Array.isArray(pattern.data)) {
                pattern.data.forEach(function(match) {
                    var signature = getMatchShapeStatsSignature(match);
                    if (signature) signatures[signature] = true;
                });
            }
            exactPatternSignatureCache[cacheKey] = signatures;
        }
        return !!exactPatternSignatureCache[cacheKey][getStateShapeStatsSignature(s)];
    }

    function matchesFullMinsPatternFromData(pid, s) {
        if (!s) return false;
        var cacheKey = pid + ':fullMins';
        if (!exactPatternSignatureCache[cacheKey]) {
            var pattern = (INITIAL_DATA.nextPatterns || []).find(function(item) { return item && item.id === pid; });
            var signatures = {};
            if (pattern && Array.isArray(pattern.data)) {
                pattern.data.forEach(function(match) {
                    var signature = getMatchFullMinsSignature(match);
                    if (signature) signatures[signature] = true;
                });
            }
            exactPatternSignatureCache[cacheKey] = signatures;
        }
        return !!exactPatternSignatureCache[cacheKey][getStateFullMinsSignature(s)];
    }

    function getHistoricalLeagueType(value) {
        return getLeagueTypeJS(value) || String(value || '').trim();
    }

    function matchesHistoricalStateSignature(match, state) {
        if (!match || !state) return false;
        var matchSeq = Array.isArray(match.h1s) ? match.h1s : [];
        var stateSeq = Array.isArray(state.h1s) ? state.h1s : [];
        return match.h1c === state.h1c
            && match.h1_first === state.h1_first
            && match.h1_last === state.h1_last
            && match.sc_h === state.sc_h
            && match.sc_a === state.sc_a
            && arrayEqualsJS(matchSeq, stateSeq);
    }

    function findHistoricalMatch(home, away, league, state) {
        var leagueType = getHistoricalLeagueType(league);
        var allMatches = Array.isArray(INITIAL_DATA.all_matches) ? INITIAL_DATA.all_matches : [];
        var candidates = [];
        for (var i = 0; i < allMatches.length; i++) {
            var match = allMatches[i] || {};
            if (String(match.home || '').trim() !== String(home || '').trim()) continue;
            if (String(match.away || '').trim() !== String(away || '').trim()) continue;
            if (getHistoricalLeagueType(match.league) !== leagueType) continue;
            candidates.push(match);
        }

        if (!candidates.length) {
            return null;
        }

        if (state) {
            var exact = candidates.filter(function(match) {
                return matchesHistoricalStateSignature(match, state);
            });
            if (exact.length) {
                return exact[exact.length - 1];
            }

            return null;
        }

        return candidates[candidates.length - 1];
    }

    function escHtml(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function parseMatchDateTime(value) {
        var m = String(value || '').trim().match(/^(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2})$/);
        if (!m) return 0;
        return new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1]), Number(m[4]), Number(m[5]), 0).getTime();
    }

    function sortMatchesByDateDesc(matches) {
        return (matches || []).slice().sort(function(a, b) {
            return parseMatchDateTime(b && b.datetime) - parseMatchDateTime(a && a.datetime);
        });
    }

    function buildPatternData() {
        INITIAL_DATA.patterns.forEach(function(p) {
            var total = p.data.length;
            var has2h = p.data.filter(function(m) { return m.h2c > 0; }).length;
            var pct = total > 0 ? Math.round(has2h / total * 100) : 0;
            var rows = [];
            sortMatchesByDateDesc(p.data).forEach(function(m) {
                var seq = m.h1s.map(function(s) {
                    return s === 'H' ? '<span class="scorer-h">H</span>' : '<span class="scorer-a">A</span>';
                }).join(' \u2192 ');
                var tl1h = m.h1.map(function(g) { return g.min + "\u2019 (" + g.home + "-" + g.away + ") "; }).join('  ');
                var tl2h = m.h2.map(function(g) { return g.min + "\u2019 (" + g.home + "-" + g.away + ") "; }).join('  ');
                var has2hBadge = m.h2c > 0
                    ? '<span class="badge badge-green">\u2713 2H</span>'
                    : '<span class="badge badge-red">\u2717 No 2H</span>';
                rows.push('<tr>'
                    + '<td class="record-sub">' + escHtml(m.datetime || '') + '</td>'
                    + '<td>' + escHtml(m.home) + ' vs ' + escHtml(m.away) + '</td>'
                    + '<td>' + escHtml(m.league) + '</td>'
                    + '<td>' + m.sc_h + '-' + m.sc_a + '</td>'
                    + '<td><strong>' + m.fh + '-' + m.fa + '</strong></td>'
                    + '<td class="goal-seq">' + tl1h + '</td>'
                    + '<td class="goal-seq">' + (tl2h || '<span class="delta-zero">-</span>') + '</td>'
                    + '<td class="goal-seq">' + seq + '</td>'
                    + '<td>' + has2hBadge + '</td></tr>');
            });
            var tableHead = '<tr><th>Tanggal</th><th>Match</th><th>League</th><th>HT</th><th>FT</th><th>Timeline 1H</th><th>Timeline 2H</th><th>Sequence</th><th>2H?</th></tr>';
            var table = '<table class="detail-table"><thead>' + tableHead + '</thead><tbody>' + rows.join('') + '</tbody></table>';
            PATTERN_STATS[p.id] = { total: total, hits: has2h };
            PATTERN_DATA[p.id] = { label: p.label, record: has2h + '/' + total, pct: pct + '%', baseHtml: table, html: table, tableHead: tableHead, rows: rows };
        });

        INITIAL_DATA.nextPatterns.forEach(function(ng) {
            var total = ng.data.length;
            var nh = ng.data.filter(function(m) { return m.next_goal === 'H'; }).length;
            var na = ng.data.filter(function(m) { return m.next_goal === 'A'; }).length;
            var tgt = ng.next;
            var hits = tgt === 'HOME' ? nh : na;
            var pct = total > 0 ? Math.round(hits / total * 100) : 0;
            var rows = [];
            sortMatchesByDateDesc(ng.data).forEach(function(m) {
                var seq = m.h1s.map(function(s) {
                    return s === 'H' ? '<span class="scorer-h">H</span>' : '<span class="scorer-a">A</span>';
                }).join(' \u2192 ');
                var tl1h = m.h1.map(function(g) { return g.min + "\u2019 (" + g.home + "-" + g.away + ") "; }).join('  ');
                var tl2h = m.h2.map(function(g) { return g.min + "\u2019 (" + g.home + "-" + g.away + ") "; }).join('  ');
                var ngVal = m.next_goal;
                var nextBadge;
                if (ngVal === 'H') nextBadge = '<span class="scorer-h next-badge-home">HOME</span>';
                else if (ngVal === 'A') nextBadge = '<span class="scorer-a next-badge-away">AWAY</span>';
                else nextBadge = '<span class="delta-zero">-</span>';
                var isHit = (tgt === 'HOME' && ngVal === 'H') || (tgt === 'AWAY' && ngVal === 'A');
                var rowAttr = isHit ? '' : ' style="opacity:0.5"';
                rows.push('<tr' + rowAttr + '>'
                    + '<td class="record-sub">' + escHtml(m.datetime || '') + '</td>'
                    + '<td>' + escHtml(m.home) + ' vs ' + escHtml(m.away) + '</td>'
                    + '<td>' + escHtml(m.league) + '</td>'
                    + '<td>' + m.sc_h + '-' + m.sc_a + '</td>'
                    + '<td>' + seq + '</td>'
                    + '<td class="goal-seq">' + tl1h + '</td>'
                    + '<td class="goal-seq">' + (tl2h || '<span class="delta-zero">-</span>') + '</td>'
                    + '<td>' + nextBadge + '</td></tr>');
            });
            var nextTableHead = '<tr><th>Tanggal</th><th>Match</th><th>League</th><th>HT</th><th>Sequence 1H</th><th>Timeline 1H</th><th>Timeline 2H</th><th>Next Goal</th></tr>';
            var table = '<table class="detail-table"><thead>' + nextTableHead + '</thead><tbody>' + rows.join('') + '</tbody></table>';
            PATTERN_STATS[ng.id] = { total: total, hits: hits };
            PATTERN_DATA[ng.id] = { label: ng.label, record: hits + '/' + total, pct: pct + '%', baseHtml: table, html: table, tableHead: nextTableHead, rows: rows };
        });

        (INITIAL_DATA.latePatterns || []).forEach(function(lp) {
            var total = lp.data.length;
            var target = lp.target || 'has_late';
            var targetMin = target === 'has_after_2h4' ? 4 : 6;
            var targetLabel = target === 'has_after_2h4' ? '2H &gt;4' : 'Late Goal';
            var noTargetLabel = target === 'has_after_2h4' ? 'No 2H &gt;4' : 'No Late Goal';
            var lateHits = lp.data.filter(function(m) { return !!m[target]; }).length;
            var pct = total > 0 ? Math.round(lateHits / total * 100) : 0;
            var rows = [];
            sortMatchesByDateDesc(lp.data).forEach(function(m) {
                var seq = m.h1s.map(function(s) {
                    return s === 'H' ? '<span class="scorer-h">H</span>' : '<span class="scorer-a">A</span>';
                }).join(' \u2192 ');
                var tl1h = m.h1.map(function(g) { return g.min + "\u2019 (" + g.home + "-" + g.away + ") "; }).join('  ');
                var tl2h = m.h2.map(function(g) { return g.min + "\u2019 (" + g.home + "-" + g.away + ") "; }).join('  ');
                var lateGoals = (m.h2 || []).filter(function(g) { return g.min > targetMin; });
                var lateTimeline = lateGoals.map(function(g) { return g.min + "\u2019 (" + g.home + "-" + g.away + ") "; }).join('  ');
                var lateBadge = m[target]
                    ? '<span class="badge badge-green">\u2713 ' + targetLabel + '</span>'
                    : '<span class="badge badge-red">\u2717 ' + noTargetLabel + '</span>';
                rows.push('<tr>'
                    + '<td class="record-sub">' + escHtml(m.datetime || '') + '</td>'
                    + '<td>' + escHtml(m.home) + ' vs ' + escHtml(m.away) + '</td>'
                    + '<td>' + escHtml(m.league) + '</td>'
                    + '<td>' + m.sc_h + '-' + m.sc_a + '</td>'
                    + '<td><strong>' + m.fh + '-' + m.fa + '</strong></td>'
                    + '<td class="goal-seq">' + tl1h + '</td>'
                    + '<td class="goal-seq">' + (tl2h || '<span class="delta-zero">-</span>') + '</td>'
                    + '<td class="goal-seq">' + seq + '</td>'
                    + '<td class="goal-seq">' + (lateTimeline || '<span class="delta-zero">-</span>') + '</td>'
                    + '<td>' + lateBadge + '</td></tr>');
            });
            var lateTableHead = '<tr><th>Tanggal</th><th>Match</th><th>League</th><th>HT</th><th>FT</th><th>Timeline 1H</th><th>Timeline 2H</th><th>Sequence</th><th>' + targetLabel + ' Timeline</th><th>' + targetLabel + '?</th></tr>';
            var table = '<table class="detail-table"><thead>' + lateTableHead + '</thead><tbody>' + rows.join('') + '</tbody></table>';
            PATTERN_STATS[lp.id] = { total: total, hits: lateHits };
            PATTERN_DATA[lp.id] = { label: lp.label, record: lateHits + '/' + total, pct: pct + '%', baseHtml: table, html: table, tableHead: lateTableHead, rows: rows };
        });
    }

    function buildLiveTimeline(state) {
        var goalMins = Array.isArray(state && state.goal_mins) ? state.goal_mins : [];
        var scorers = Array.isArray(state && state.h1s) ? state.h1s : [];
        if (!goalMins.length || goalMins.length !== scorers.length) return '<span class="delta-zero">-</span>';

        var home = 0;
        var away = 0;
        return goalMins.map(function(min, idx) {
            if (scorers[idx] === 'H') home += 1;
            if (scorers[idx] === 'A') away += 1;
            return min + '’ (' + home + '-' + away + ')';
        }).join('  ');
    }

    function buildSummaryLiveCandidateRows(candidates) {
        return candidates.map(function(entry) {
            var match = entry.match || {};
            var state = entry.state || {};
            var score = getMatchScores(match);
            var seq = (state.h1s || []).map(function(s) {
                return s === 'H' ? '<span class="scorer-h">H</span>' : '<span class="scorer-a">A</span>';
            }).join(' → ');

            return '<tr>'
                + '<td class="record-sub"><span class="badge badge-yellow">LIVE</span> ' + escHtml(getMatchStatusText(match) || '') + '</td>'
                + '<td>' + escHtml(getMatchHomeTeam(match)) + ' vs ' + escHtml(getMatchAwayTeam(match)) + '</td>'
                + '<td>' + escHtml(getMatchLeague(match)) + '</td>'
                + '<td>' + score.home + '-' + score.away + '</td>'
                + '<td><span class="delta-zero">-</span></td>'
                + '<td class="goal-seq">' + buildLiveTimeline(state) + '</td>'
                + '<td class="goal-seq"><span class="delta-zero">-</span></td>'
                + '<td class="goal-seq">' + (seq || '<span class="delta-zero">-</span>') + '</td>'
                + '<td><span class="badge badge-yellow">' + getLiveCandidateBadgeText(entry) + '</span></td>'
                + '</tr>';
        }).join('');
    }

    function buildSummarySettledSampleRows(id) {
        var results = getSettledSummaryResultsByPid(id);
        if (!results.length) return '';

        return results.map(function(item) {
            var state = item.state || {};
            var seq = Array.isArray(state.h1s) ? state.h1s.map(function(s) {
                return s === 'H' ? '<span class="scorer-h">H</span>' : '<span class="scorer-a">A</span>';
            }).join(' → ') : '';
            var score = item.scoreObj || { home: state.sc_h || 0, away: state.sc_a || 0 };
            var outcomeBadge = item.outcome === 'win'
                ? '<span class="badge badge-green">LIVE WIN</span>'
                : '<span class="badge badge-red">LIVE LOSE</span>';
            var settledTime = new Date(item.settledAt || Date.now()).toLocaleTimeString();

            var rowClass = item.outcome === 'win' ? 'live-settled-row live-settled-win' : 'live-settled-row live-settled-lose';

            return '<tr class="' + rowClass + '">'
                + '<td class="record-sub">' + outcomeBadge + ' ' + escHtml(settledTime) + '</td>'
                + '<td>' + escHtml(item.home || '-') + ' vs ' + escHtml(item.away || '-') + '</td>'
                + '<td>' + escHtml(item.league || '-') + '</td>'
                + '<td>' + escHtml(score.home + '-' + score.away) + '</td>'
                + '<td><span class="delta-zero">-</span></td>'
                + '<td class="goal-seq">' + buildLiveTimeline(state) + '</td>'
                + '<td class="goal-seq"><span class="delta-zero">live result</span></td>'
                + '<td class="goal-seq">' + (seq || '<span class="delta-zero">-</span>') + '</td>'
                + '<td>' + outcomeBadge + '</td>'
                + '</tr>';
        }).join('');
    }

    function buildNextLiveCandidateRows(candidates) {
        return candidates.map(function(entry) {
            var match = entry.match || {};
            var state = entry.state || {};
            var score = getMatchScores(match);
            var seq = (state.h1s || []).map(function(s) {
                return s === 'H' ? '<span class="scorer-h">H</span>' : '<span class="scorer-a">A</span>';
            }).join(' → ');

            return '<tr>'
                + '<td class="record-sub"><span class="badge badge-yellow">LIVE</span> ' + escHtml(getMatchStatusText(match) || '') + '</td>'
                + '<td>' + escHtml(getMatchHomeTeam(match)) + ' vs ' + escHtml(getMatchAwayTeam(match)) + '</td>'
                + '<td>' + escHtml(getMatchLeague(match)) + '</td>'
                + '<td>' + score.home + '-' + score.away + '</td>'
                + '<td class="goal-seq">' + (seq || '<span class="delta-zero">-</span>') + '</td>'
                + '<td class="goal-seq">' + buildLiveTimeline(state) + '</td>'
                + '<td class="goal-seq"><span class="delta-zero">-</span></td>'
                + '<td><span class="badge badge-yellow">' + getLiveCandidateBadgeText() + '</span></td>'
                + '</tr>';
        }).join('');
    }

    function buildLateLiveCandidateRows(candidates) {
        return candidates.map(function(entry) {
            var match = entry.match || {};
            var state = entry.state || {};
            var score = getMatchScores(match);
            var seq = (state.h1s || []).map(function(s) {
                return s === 'H' ? '<span class="scorer-h">H</span>' : '<span class="scorer-a">A</span>';
            }).join(' → ');

            return '<tr>'
                + '<td class="record-sub"><span class="badge badge-yellow">LIVE</span> ' + escHtml(getMatchStatusText(match) || '') + '</td>'
                + '<td>' + escHtml(getMatchHomeTeam(match)) + ' vs ' + escHtml(getMatchAwayTeam(match)) + '</td>'
                + '<td>' + escHtml(getMatchLeague(match)) + '</td>'
                + '<td>' + score.home + '-' + score.away + '</td>'
                + '<td><span class="delta-zero">-</span></td>'
                + '<td class="goal-seq">' + buildLiveTimeline(state) + '</td>'
                + '<td class="goal-seq"><span class="delta-zero">-</span></td>'
                + '<td class="goal-seq">' + (seq || '<span class="delta-zero">-</span>') + '</td>'
                + '<td class="goal-seq"><span class="delta-zero">-</span></td>'
                + '<td><span class="badge badge-yellow">' + getLiveCandidateBadgeText() + '</span></td>'
                + '</tr>';
        }).join('');
    }

    function buildLiveCandidateSection(id) {
        var candidates = liveCandidateDetails[id] || nextLiveCandidateDetails[id] || lateLiveCandidateDetails[id] || [];
        if (!candidates.length) return '';
        if (candidates[0].kind === 'next') return buildNextLiveCandidateRows(candidates);
        if (candidates[0].kind === 'late') return buildLateLiveCandidateRows(candidates);
        return buildSummaryLiveCandidateRows(candidates);
    }

    function buildSummaryLiveSampleRows(id) {
        return buildSummarySettledSampleRows(id) + buildLiveCandidateSection(id);
    }

    function buildDetailPagination(id, page, totalPages, totalRows) {
        if (totalPages <= 1) return '';
        var prevDisabled = page <= 1 ? ' disabled' : '';
        var nextDisabled = page >= totalPages ? ' disabled' : '';
        var prevPage = Math.max(1, page - 1);
        var nextPage = Math.min(totalPages, page + 1);
        var startRow = ((page - 1) * DETAIL_ROWS_PER_PAGE) + 1;
        var endRow = Math.min(totalRows, page * DETAIL_ROWS_PER_PAGE);

        return '<div class="detail-pagination">'
            + '<button class="detail-page-btn" data-pid="' + escHtml(id) + '" data-page="' + prevPage + '"' + prevDisabled + '>Prev</button>'
            + '<span class="detail-page-info">Page ' + page + ' / ' + totalPages + ' • ' + startRow + '-' + endRow + ' dari ' + totalRows + '</span>'
            + '<button class="detail-page-btn" data-pid="' + escHtml(id) + '" data-page="' + nextPage + '"' + nextDisabled + '>Next</button>'
            + '</div>';
    }

    function buildPaginatedDetailTable(id, data) {
        if (!data || !Array.isArray(data.rows) || !data.tableHead) {
            return data && (data.baseHtml || data.html) ? (data.baseHtml || data.html) : '';
        }

        var rows = data.rows;
        var totalRows = rows.length;
        var totalPages = Math.max(1, Math.ceil(totalRows / DETAIL_ROWS_PER_PAGE));
        var page = detailPageState[id] || 1;
        if (page > totalPages) page = totalPages;
        if (page < 1) page = 1;
        detailPageState[id] = page;

        var start = (page - 1) * DETAIL_ROWS_PER_PAGE;
        var end = start + DETAIL_ROWS_PER_PAGE;
        var pageRows = rows.slice(start, end).join('');
        var liveRows = page === 1 ? buildSummaryLiveSampleRows(id) : '';
        var table = '<table class="detail-table"><thead>' + data.tableHead + '</thead><tbody>' + liveRows + pageRows + '</tbody></table>';
        return table + buildDetailPagination(id, page, totalPages, totalRows);
    }

    function changeDetailPage(id, page) {
        detailPageState[id] = page;
        if (activePanel === id) {
            refreshActivePanelContent();
        }
    }

    function bindDetailPaginationButtons(root) {
        if (!root) return;
        root.querySelectorAll('.detail-page-btn[data-pid][data-page]').forEach(function(btn) {
            btn.onclick = function(e) {
                if (e) e.preventDefault();
                if (this.disabled) return;
                changeDetailPage(this.dataset.pid, parseInt(this.dataset.page, 10) || 1);
            };
        });
    }

    function prependLiveCandidateRows(html, liveRows) {
        if (!liveRows || !html) return html;
        return html.replace('<tbody>', '<tbody>' + liveRows);
    }

    function hasAnyLiveCandidateState() {
        return Object.keys(liveCandidateCounts).length > 0
            || Object.keys(nextLiveCandidateCounts).length > 0
            || Object.keys(lateLiveCandidateCounts).length > 0;
    }

    function getLiveCandidateBadgeText(entry) {
        if (entry && entry.provisional) return restoredLiveCandidateState ? 'provisional (cached)' : 'provisional';
        return restoredLiveCandidateState ? 'candidate (cached)' : 'candidate';
    }

    function clearLiveCandidateState(clearStorage) {
        liveCandidateCounts = {};
        nextLiveCandidateCounts = {};
        lateLiveCandidateCounts = {};
        liveCandidateDetails = {};
        nextLiveCandidateDetails = {};
        lateLiveCandidateDetails = {};
        restoredLiveCandidateState = false;

        if (liveCandidateExpiryTimer) {
            clearTimeout(liveCandidateExpiryTimer);
            liveCandidateExpiryTimer = null;
        }

        if (clearStorage) {
            try {
                sessionStorage.removeItem(LIVE_CANDIDATE_STORAGE_KEY);
            } catch (e) {
                // ignore storage failures
            }
        }
    }

    function applyLiveCandidateStateToUi() {
        applyLiveCandidateIndicators();
        applyNextLiveCandidateIndicators();
        applyLateLiveCandidateIndicators();
        refreshActivePanelContent();
    }

    function scheduleLiveCandidateExpiry(delayMs) {
        if (liveCandidateExpiryTimer) {
            clearTimeout(liveCandidateExpiryTimer);
            liveCandidateExpiryTimer = null;
        }

        if (!restoredLiveCandidateState || !hasAnyLiveCandidateState()) return;

        liveCandidateExpiryTimer = setTimeout(function() {
            clearLiveCandidateState(true);
            applyLiveCandidateStateToUi();
        }, Math.max(0, delayMs || 0));
    }

    function saveLiveCandidateState() {
        try {
            sessionStorage.setItem(LIVE_CANDIDATE_STORAGE_KEY, JSON.stringify({
                schemaVersion: LIVE_STATE_SCHEMA_VERSION,
                savedAt: Date.now(),
                summaryCounts: liveCandidateCounts,
                nextCounts: nextLiveCandidateCounts,
                lateCounts: lateLiveCandidateCounts,
                summaryDetails: liveCandidateDetails,
                nextDetails: nextLiveCandidateDetails,
                lateDetails: lateLiveCandidateDetails,
                patternStateMemory: livePatternStateMemory
            }));
        } catch (e) {
            // ignore storage failures
        }
    }

    function pruneSettledSummaryResults() {
        var cutoff = Date.now() - LIVE_SETTLED_CACHE_TTL_MS;
        settledSummaryResults = settledSummaryResults.filter(function(result) {
            return result
                && Number.isFinite(result.settledAt)
                && result.settledAt >= cutoff
                && isSettledSummaryResultValid(result);
        });
    }

    function isFinalishSettledStatus(statusText) {
        var status = String(statusText || '').trim();
        if (!status) return false;
        if (/^H\.?Time$/i.test(status)) return false;
        if (/^(completed|complete|finished|finish|ft|full\s*time|ended|end)$/i.test(status)) return true;
        var parsed = parseStatus(status);
        return parsed.half === '2H';
    }

    function pruneSettledSummaryResultsForLiveMatches(matches) {
        return;
        var liveKeys = {};
        (matches || []).forEach(function(match) {
            var statusText = getMatchStatusText(match);
            var parsedStatus = parseStatus(statusText);
            var isHalftime = /^H\.?Time$/i.test(statusText);
            if (!(parsedStatus.half === '1H' || parsedStatus.half === '2H' || isHalftime)) {
                return;
            }
            var liveKey = [getMatchHomeTeam(match), getMatchAwayTeam(match), getHistoricalLeagueType(getMatchLeague(match))].join('|');
            liveKeys[liveKey] = true;
        });

        if (!Object.keys(liveKeys).length) {
            return;
        }

        settledSummaryResults = settledSummaryResults.filter(function(result) {
            var settledKey = [result.home || '', result.away || '', getHistoricalLeagueType(result.league)].join('|');
            return !liveKeys[settledKey];
        });
        saveSettledSummaryState();
    }

    function isSettledSummaryResultValid(result) {
        if (!result || !result.pid) return false;
        if (!result.state) {
            var historicalWithoutState = findHistoricalMatch(result.home, result.away, result.league, null);
            if (historicalWithoutState) {
                var historicalOutcomeWithoutState = historicalWithoutState.h2c > 0 ? 'win' : 'lose';
                return result.outcome === historicalOutcomeWithoutState;
            }
            return isFinalishSettledStatus(result.status);
        }
        var historical = findHistoricalMatch(result.home, result.away, result.league, result.state);
        if (historical) {
            var historicalOutcome = historical.h2c > 0 ? 'win' : 'lose';
            if (result.outcome !== historicalOutcome) return false;
            return true;
        } else {
            if (!isFinalishSettledStatus(result.status)) return false;
            if (result.scoreObj && (result.scoreObj.home !== result.state.sc_h || result.scoreObj.away !== result.state.sc_a) && !/^2H/i.test(String(result.status || '').trim())) {
                return false;
            }
        }
        return true;
    }

    function saveSettledSummaryState() {
        pruneSettledSummaryResults();
        try {
            sessionStorage.setItem(LIVE_SETTLED_STORAGE_KEY, JSON.stringify({
                schemaVersion: LIVE_STATE_SCHEMA_VERSION,
                savedAt: Date.now(),
                results: settledSummaryResults
            }));
        } catch (e) {
            // ignore storage failures
        }
    }

    function pruneSettledLateResults() {
        var cutoff = Date.now() - LIVE_SETTLED_CACHE_TTL_MS;
        settledLateResults = settledLateResults.filter(function(result) {
            var pattern = result && result.pid ? getLatePatternById(result.pid) : null;
            var historical = result && pattern ? findHistoricalMatch(result.home, result.away, result.league, result.state || null) : null;
            if (historical) return false;
            return result
                && Number.isFinite(result.settledAt)
                && result.settledAt >= cutoff
                && result.pid
                && result.key
                && result.outcome;
        });
    }

    function saveSettledLateState() {
        pruneSettledLateResults();
        try {
            sessionStorage.setItem(LIVE_LATE_SETTLED_STORAGE_KEY, JSON.stringify({
                schemaVersion: LIVE_STATE_SCHEMA_VERSION,
                savedAt: Date.now(),
                results: settledLateResults
            }));
        } catch (e) {
            // ignore storage failures
        }
    }

    function restoreLiveCandidateState() {
        try {
            var raw = sessionStorage.getItem(LIVE_CANDIDATE_STORAGE_KEY);
            if (!raw) return;
            var parsed = JSON.parse(raw);
            if (parsed.schemaVersion !== LIVE_STATE_SCHEMA_VERSION) {
                clearLiveCandidateState(true);
                return;
            }
            var savedAt = parseInt(parsed.savedAt, 10);
            if (!Number.isFinite(savedAt)) {
                clearLiveCandidateState(true);
                return;
            }
            var ageMs = Date.now() - savedAt;
            if (ageMs >= LIVE_CANDIDATE_CACHE_TTL_MS) {
                clearLiveCandidateState(true);
                return;
            }
            liveCandidateCounts = parsed.summaryCounts || {};
            nextLiveCandidateCounts = parsed.nextCounts || {};
            lateLiveCandidateCounts = parsed.lateCounts || {};
            liveCandidateDetails = parsed.summaryDetails || {};
            nextLiveCandidateDetails = parsed.nextDetails || {};
            lateLiveCandidateDetails = parsed.lateDetails || {};
            livePatternStateMemory = parsed.patternStateMemory || {};
            restoredLiveCandidateState = hasAnyLiveCandidateState();
            scheduleLiveCandidateExpiry(LIVE_CANDIDATE_CACHE_TTL_MS - ageMs);
        } catch (e) {
            clearLiveCandidateState(true);
        }
    }

    function restoreSettledSummaryState() {
        try {
            var raw = sessionStorage.getItem(LIVE_SETTLED_STORAGE_KEY);
            if (!raw) return;
            var parsed = JSON.parse(raw);
            if (parsed.schemaVersion !== LIVE_STATE_SCHEMA_VERSION) {
                settledSummaryResults = [];
                sessionStorage.removeItem(LIVE_SETTLED_STORAGE_KEY);
                return;
            }
            var savedAt = parseInt(parsed.savedAt, 10);
            if (!Number.isFinite(savedAt) || (Date.now() - savedAt) >= LIVE_SETTLED_CACHE_TTL_MS) {
                settledSummaryResults = [];
                sessionStorage.removeItem(LIVE_SETTLED_STORAGE_KEY);
                return;
            }
            settledSummaryResults = Array.isArray(parsed.results) ? parsed.results : [];
            pruneSettledSummaryResults();
        } catch (e) {
            settledSummaryResults = [];
            try {
                sessionStorage.removeItem(LIVE_SETTLED_STORAGE_KEY);
            } catch (storageError) {
                // ignore storage failures
            }
        }
    }

    function restoreSettledLateState() {
        try {
            var raw = sessionStorage.getItem(LIVE_LATE_SETTLED_STORAGE_KEY);
            if (!raw) return;
            var parsed = JSON.parse(raw);
            if (parsed.schemaVersion !== LIVE_STATE_SCHEMA_VERSION) {
                settledLateResults = [];
                sessionStorage.removeItem(LIVE_LATE_SETTLED_STORAGE_KEY);
                return;
            }
            var savedAt = parseInt(parsed.savedAt, 10);
            if (!Number.isFinite(savedAt) || (Date.now() - savedAt) >= LIVE_SETTLED_CACHE_TTL_MS) {
                settledLateResults = [];
                sessionStorage.removeItem(LIVE_LATE_SETTLED_STORAGE_KEY);
                return;
            }
            settledLateResults = Array.isArray(parsed.results) ? parsed.results : [];
            pruneSettledLateResults();
        } catch (e) {
            settledLateResults = [];
            try {
                sessionStorage.removeItem(LIVE_LATE_SETTLED_STORAGE_KEY);
            } catch (storageError) {
                // ignore storage failures
            }
        }
    }

    function upsertSettledSummaryResult(result) {
        if (!result || !result.pid || !result.key || !result.outcome) return;
        pruneSettledSummaryResults();
        var id = getSettledSummaryIdentity(result.pid, result.key, result.state);
        var nextResults = [result];
        settledSummaryResults.forEach(function(existing) {
            if (!existing) return;
            var existingId = getSettledSummaryIdentity(existing.pid, existing.key, existing.state);
            if (existingId !== id) nextResults.push(existing);
        });
        settledSummaryResults = nextResults.slice(0, 100);
        saveSettledSummaryState();
    }

    function getSettledSummaryIdentity(pid, key, state) {
        var signature = state ? getStateSignature(state) : '';
        return pid + '|' + key + (signature ? '|' + signature : '');
    }

    function hasSettledSummaryResult(pid, key, state) {
        if (!pid || !key) return false;
        pruneSettledSummaryResults();
        var identity = getSettledSummaryIdentity(pid, key, state);
        return settledSummaryResults.some(function(result) {
            return result && getSettledSummaryIdentity(result.pid, result.key, result.state) === identity;
        });
    }

    function hasPriorSummaryCandidate(pid, key, state, requireConfirmed) {
        if (!pid || !key) return false;
        var now = Date.now();
        var signature = state ? getStateSignature(state) : '';
        return (liveCandidateDetails[pid] || []).some(function(entry) {
            var entryKey = entry && (entry.key || (entry.match ? matchKey(entry.match) : ''));
            if (entryKey !== key) return false;
            if (signature && entry.state && getStateSignature(entry.state) !== signature) return false;
            if (requireConfirmed && entry.provisional) return false;
            var lastSeen = Number(entry.lastSeen || entry.seenAt || 0);
            return Number.isFinite(lastSeen) && now - lastSeen <= LIVE_SUMMARY_PRE_GOAL_CARRY_MS;
        });
    }

    function requiresConfirmedPriorCandidateForSettled(pid) {
        return false;
    }

    function requiresPriorCandidateForSettled(pid) {
        return false;
    }

    function supportsProvisionalSummaryCandidate(pid) {
        return false;
    }

    function isProvisionalSummaryCandidate(pid, statusText) {
        return supportsProvisionalSummaryCandidate(pid) && parseStatus(statusText).half === '1H';
    }

    function getSettledSummaryStats(pid) {
        pruneSettledSummaryResults();
        return settledSummaryResults.reduce(function(stats, result) {
            if (!result || result.pid !== pid) return stats;
            if (result.outcome === 'win') stats.win += 1;
            if (result.outcome === 'lose') stats.lose += 1;
            return stats;
        }, { win: 0, lose: 0 });
    }

    function getRecentSettledSummaryResults(limit) {
        pruneSettledSummaryResults();
        return settledSummaryResults.slice(0, limit || 10);
    }

    function upsertSettledLateResult(result) {
        if (!result || !result.pid || !result.key || !result.outcome) return;
        pruneSettledLateResults();
        var id = result.pid + '|' + result.key;
        var nextResults = [result];
        settledLateResults.forEach(function(existing) {
            if (!existing) return;
            var existingId = existing.pid + '|' + existing.key;
            if (existingId !== id) nextResults.push(existing);
        });
        settledLateResults = nextResults.slice(0, 100);
        saveSettledLateState();
    }

    function getSettledLateStats(pid) {
        pruneSettledLateResults();
        return settledLateResults.reduce(function(stats, result) {
            if (!result || result.pid !== pid) return stats;
            if (result.outcome === 'win') stats.win += 1;
            if (result.outcome === 'lose') stats.lose += 1;
            return stats;
        }, { win: 0, lose: 0 });
    }

    function getSettledLateResultsByPid(pid) {
        pruneSettledLateResults();
        return settledLateResults.filter(function(result) {
            return result && result.pid === pid;
        });
    }

    function buildSettledSummaryResult(pid, entry, currentMatch, livePayload) {
        var key = entry && entry.key ? entry.key : (entry && entry.match ? matchKey(entry.match) : '');
        if (!pid || !key) return null;

        var hasSecondHalfGoal = false;
        if (currentMatch) {
            hasSecondHalfGoal = hasAnySecondHalfGoal(currentMatch, livePayload, entry && entry.state ? entry.state : null);
        }

        var fallbackMatch = entry && entry.match ? entry.match : null;
        var historical = findHistoricalMatch(
            currentMatch ? getMatchHomeTeam(currentMatch) : getMatchHomeTeam(fallbackMatch),
            currentMatch ? getMatchAwayTeam(currentMatch) : getMatchAwayTeam(fallbackMatch),
            currentMatch ? getMatchLeague(currentMatch) : getMatchLeague(fallbackMatch),
            entry && entry.state ? entry.state : null
        );

        var outcome = null;
        var statusText = currentMatch ? getMatchStatusText(currentMatch) : String((entry && entry.status) || '').trim();
        if (hasSecondHalfGoal) {
            outcome = 'win';
        } else if (historical) {
            outcome = historical.h2c > 0 ? 'win' : 'lose';
        } else if (!currentMatch) {
            return null;
        } else {
            var parsedStatus = parseStatus(statusText);
            var isHalftime = /^H\.?Time$/i.test(statusText);
            var stillLiveWindow = parsedStatus.half === '1H' || isHalftime || (parsedStatus.half === '2H' && !hasSecondHalfGoal);
            if (!stillLiveWindow) {
                outcome = 'lose';
            }
        }

        if (!outcome) return null;

        var match = historical || currentMatch || (entry ? entry.match : null) || {};
        var score = historical
            ? { home: historical.fh || 0, away: historical.fa || 0 }
            : (currentMatch ? getMatchScores(currentMatch) : (entry && entry.score ? entry.score : { home: 0, away: 0 }));
        return {
            pid: pid,
            key: key,
            label: getPatternLabelById(pid),
            home: getMatchHomeTeam(match),
            away: getMatchAwayTeam(match),
            league: getLeagueTypeJS(getMatchLeague(match)) || getMatchLeague(match),
            score: score.home + ' - ' + score.away,
            scoreObj: { home: score.home, away: score.away },
            status: statusText || 'Finished',
            state: entry && entry.state ? JSON.parse(JSON.stringify(entry.state)) : null,
            outcome: outcome,
            settledAt: Date.now()
        };
    }

    function getSettledSummaryResultsByPid(pid) {
        pruneSettledSummaryResults();
        return settledSummaryResults.filter(function(result) {
            return result && result.pid === pid;
        });
    }

    function buildSettledSummarySection(pid) {
        var results = getSettledSummaryResultsByPid(pid);
        if (!results.length) return '';

        var rowsHtml = results.map(function(item) {
            var state = item.state || {};
            var seq = Array.isArray(state.h1s) ? state.h1s.map(function(s) {
                return s === 'H' ? '<span class="scorer-h">H</span>' : '<span class="scorer-a">A</span>';
            }).join(' → ') : '';
            var timeline = buildLiveTimeline(state);
            var outcomeBadge = item.outcome === 'win'
                ? '<span class="badge badge-green">WIN</span>'
                : '<span class="badge badge-red">LOSE</span>';
            var settledTime = new Date(item.settledAt || Date.now()).toLocaleTimeString();
            return '<tr>'
                + '<td class="record-sub">' + escHtml(settledTime) + '</td>'
                + '<td>' + escHtml(item.home) + ' vs ' + escHtml(item.away) + '</td>'
                + '<td>' + escHtml(item.league || '-') + '</td>'
                + '<td>' + escHtml(item.status || '-') + '</td>'
                + '<td>' + escHtml(item.score || '-') + '</td>'
                + '<td class="goal-seq">' + timeline + '</td>'
                + '<td class="goal-seq">' + (seq || '<span class="delta-zero">-</span>') + '</td>'
                + '<td>' + outcomeBadge + '</td>'
                + '</tr>';
        }).join('');

        return '<div style="margin-top:16px;">'
            + '<h4 style="margin:0 0 8px 0; font-size:0.95rem; color:var(--text-primary);">Settled Live Results</h4>'
            + '<table class="detail-table"><thead><tr><th>Jam</th><th>Match</th><th>League</th><th>Status Terakhir</th><th>Skor</th><th>Timeline 1H</th><th>Sequence</th><th>Hasil</th></tr></thead><tbody>' + rowsHtml + '</tbody></table>'
            + '</div>';
    }

    function buildSettledLateResult(pid, entry, currentMatch, livePayload) {
        var key = entry && entry.key ? entry.key : (entry && entry.match ? matchKey(entry.match) : '');
        if (!pid || !key) return null;

        var pattern = getLatePatternById(pid);
        if (!pattern) return null;

        var fallbackMatch = entry && entry.match ? entry.match : null;
        var match = currentMatch || fallbackMatch || {};
        var state = entry && entry.state ? entry.state : null;
        var statusText = currentMatch ? getMatchStatusText(currentMatch) : String((entry && entry.status) || getMatchStatusText(fallbackMatch) || '').trim();
        var targetReached = currentMatch && hasLateTargetGoal(pattern, currentMatch, livePayload, state);
        var outcome = null;

        if (targetReached) {
            outcome = 'win';
        } else if (!currentMatch) {
            var historical = findHistoricalMatch(getMatchHomeTeam(fallbackMatch), getMatchAwayTeam(fallbackMatch), getMatchLeague(fallbackMatch), state);
            if (historical) {
                outcome = (historical[getLatePatternTarget(pattern)] ? 'win' : 'lose');
            } else if (isFinalishSettledStatus(statusText)) {
                outcome = 'lose';
            }
        }

        if (!outcome) return null;

        var score = currentMatch ? getMatchScores(currentMatch) : (entry && entry.score ? entry.score : getMatchScores(fallbackMatch));
        return {
            pid: pid,
            key: key,
            label: getPatternLabelById(pid),
            home: getMatchHomeTeam(match),
            away: getMatchAwayTeam(match),
            league: getLeagueTypeJS(getMatchLeague(match)) || getMatchLeague(match),
            score: score.home + ' - ' + score.away,
            status: statusText || (outcome === 'win' ? 'Target reached' : 'Finished'),
            state: state ? JSON.parse(JSON.stringify(state)) : null,
            outcome: outcome,
            target: getLatePatternTarget(pattern),
            settledAt: Date.now()
        };
    }

    function buildSettledLateSection(pid) {
        var results = getSettledLateResultsByPid(pid);
        if (!results.length) return '';

        var rowsHtml = results.map(function(item) {
            var state = item.state || {};
            var seq = Array.isArray(state.h1s) ? state.h1s.map(function(s) {
                return s === 'H' ? '<span class="scorer-h">H</span>' : '<span class="scorer-a">A</span>';
            }).join(' → ') : '';
            var outcomeBadge = item.outcome === 'win'
                ? '<span class="badge badge-green">WIN</span>'
                : '<span class="badge badge-red">LOSE</span>';
            var targetLabel = item.target === 'has_after_2h4' ? '2H >4' : 'Late';
            var settledTime = new Date(item.settledAt || Date.now()).toLocaleTimeString();
            var rowClass = entry.provisional ? ' class="live-provisional-row"' : '';

            return '<tr' + rowClass + '>'
                + '<td class="record-sub">' + escHtml(settledTime) + '</td>'
                + '<td>' + escHtml(item.home) + ' vs ' + escHtml(item.away) + '</td>'
                + '<td>' + escHtml(item.league || '-') + '</td>'
                + '<td>' + escHtml(item.status || '-') + '</td>'
                + '<td>' + escHtml(item.score || '-') + '</td>'
                + '<td>' + escHtml(targetLabel) + '</td>'
                + '<td class="goal-seq">' + (seq || '<span class="delta-zero">-</span>') + '</td>'
                + '<td>' + outcomeBadge + '</td>'
                + '</tr>';
        }).join('');

        return '<div style="margin-top:16px;">'
            + '<h4 style="margin:0 0 8px 0; font-size:0.95rem; color:var(--text-primary);">Settled Live Late Results</h4>'
            + '<table class="detail-table"><thead><tr><th>Jam</th><th>Match</th><th>League</th><th>Status Terakhir</th><th>Skor</th><th>Target</th><th>Sequence</th><th>Hasil</th></tr></thead><tbody>' + rowsHtml + '</tbody></table>'
            + '</div>';
    }

    function settleRemovedSummaryCandidates(previousDetails, nextDetails, matches, livePayload) {
        var currentMatchesByKey = {};
        (matches || []).forEach(function(match) {
            currentMatchesByKey[matchKey(match)] = match;
        });

        var nextKeysByPattern = {};
        Object.keys(nextDetails || {}).forEach(function(pid) {
            nextKeysByPattern[pid] = {};
            (nextDetails[pid] || []).forEach(function(entry) {
                var key = entry.key || (entry.match ? matchKey(entry.match) : '');
                if (key) nextKeysByPattern[pid][key] = true;
            });
        });

        Object.keys(previousDetails || {}).forEach(function(pid) {
            (previousDetails[pid] || []).forEach(function(entry) {
                var key = entry.key || (entry.match ? matchKey(entry.match) : '');
                if (!key) return;
                if (nextKeysByPattern[pid] && nextKeysByPattern[pid][key]) return;
                if (requiresPriorCandidateForSettled(pid) && !hasPriorSummaryCandidate(pid, key, entry.state || null, requiresConfirmedPriorCandidateForSettled(pid))) return;
                var settled = buildSettledSummaryResult(pid, entry, currentMatchesByKey[key], livePayload);
                if (settled) upsertSettledSummaryResult(settled);
            });
        });
    }

    function settleRemovedLateCandidates(previousDetails, nextDetails, matches, livePayload) {
        var currentMatchesByKey = {};
        (matches || []).forEach(function(match) {
            currentMatchesByKey[matchKey(match)] = match;
        });

        var nextKeysByPattern = {};
        Object.keys(nextDetails || {}).forEach(function(pid) {
            nextKeysByPattern[pid] = {};
            (nextDetails[pid] || []).forEach(function(entry) {
                var key = entry.key || (entry.match ? matchKey(entry.match) : '');
                if (key) nextKeysByPattern[pid][key] = true;
            });
        });

        Object.keys(previousDetails || {}).forEach(function(pid) {
            (previousDetails[pid] || []).forEach(function(entry) {
                var key = entry.key || (entry.match ? matchKey(entry.match) : '');
                if (!key) return;
                if (nextKeysByPattern[pid] && nextKeysByPattern[pid][key]) return;
                var settled = buildSettledLateResult(pid, entry, currentMatchesByKey[key], livePayload);
                if (settled) upsertSettledLateResult(settled);
            });
        });
    }

    function buildPanelHtml(id, data) {
        if (!data) return '';
        var html = buildPaginatedDetailTable(id, data);
        return html + buildSettledSummarySection(id) + buildSettledLateSection(id);
    }

    function refreshActivePanelContent() {
        if (!activePanel || !PATTERN_DATA[activePanel]) return;
        var d = PATTERN_DATA[activePanel];
        document.getElementById('slide-title').innerHTML = '<strong>' + activePanel + '</strong>: ' + d.label + ' <span style="color:var(--text-secondary);font-size:0.85rem;">' + d.record + ' = ' + d.pct + '</span>';
        document.getElementById('slide-body').innerHTML = buildPanelHtml(activePanel, d);
        bindDetailPaginationButtons(document.getElementById('slide-body'));
        var panel = document.getElementById('slide-panel');
        if (panel) panel.scrollTop = 0;
    }

    function toggle(id) {
        if (activePanel === id) { closePanel(); return; }
        var d = PATTERN_DATA[id];
        if (!d) return;
        detailPageState[id] = 1;
        document.getElementById('slide-title').innerHTML = '<strong>' + id + '</strong>: ' + d.label + ' <span style="color:var(--text-secondary);font-size:0.85rem;">' + d.record + ' = ' + d.pct + '</span>';
        document.getElementById('slide-body').innerHTML = buildPanelHtml(id, d);
        bindDetailPaginationButtons(document.getElementById('slide-body'));
        document.getElementById('slide-overlay').style.display = 'block';
        document.getElementById('slide-panel').classList.add('open');
        document.getElementById('slide-panel').scrollTop = 0;
        activePanel = id;
        sessionStorage.setItem('openPanel', id);
    }

    function closePanel() {
        document.getElementById('slide-panel').classList.remove('open');
        document.getElementById('slide-overlay').style.display = 'none';
        activePanel = null;
        sessionStorage.removeItem('openPanel');
    }

    window.toggle = toggle;
    window.closePanel = closePanel;

    function updateCountdown() {
        refreshCountdown--;
        if (countdownEl) countdownEl.textContent = 'Refresh: ' + refreshCountdown + 's';
        if (refreshCountdown <= 0) {
            refreshDashboard();
            refreshCountdown = SUMMARY_REFRESH_SECONDS;
        }
    }

    function updateSummarySyncState(apiData) {
        if (!summarySyncStateEl) return;

        var nextCsvTime = apiData && apiData.csv_time ? apiData.csv_time : null;
        var nextGeneratedAt = apiData && apiData.generated_at ? apiData.generated_at : null;
        var csvChanged = nextCsvTime !== null && latestCsvTime !== null && nextCsvTime !== latestCsvTime;
        var rebuilt = apiData && apiData.from_cache === false;

        if (csvChanged && rebuilt) {
            summarySyncStateEl.textContent = 'CSV changed, summary rebuilt';
            summarySyncStateEl.style.color = '#3fb950';
        } else if (rebuilt && nextGeneratedAt !== latestGeneratedAt) {
            summarySyncStateEl.textContent = 'Summary recalculated';
            summarySyncStateEl.style.color = '#58a6ff';
        } else {
            summarySyncStateEl.textContent = 'Summary in sync';
            summarySyncStateEl.style.color = '#8b949e';
        }

        latestCsvTime = nextCsvTime;
        latestGeneratedAt = nextGeneratedAt;
    }

    async function refreshDashboard() {
        try {
            var resp = await fetch('dashboard_api.php', { signal: AbortSignal.timeout(5000) });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            var apiData = await resp.json();

            if (Array.isArray(apiData.pattern_details)) {
                INITIAL_DATA.patterns = apiData.pattern_details;
                exactPatternSignatureCache = {};
            }
            if (Array.isArray(apiData.next_pattern_details)) {
                INITIAL_DATA.nextPatterns = apiData.next_pattern_details;
                exactPatternSignatureCache = {};
            }
            if (Array.isArray(apiData.late_pattern_details)) {
                INITIAL_DATA.latePatterns = apiData.late_pattern_details;
            }
            if (Array.isArray(apiData.pattern_defs)) {
                PATTERN_DEFS = apiData.pattern_defs;
            }
            if (Array.isArray(apiData.all_matches)) {
                INITIAL_DATA.all_matches = apiData.all_matches;
            }
            PATTERN_DATA = {};
            buildPatternData();

            document.getElementById('stat-total').textContent = apiData.total_matches;
            document.getElementById('stat-patterns').textContent = apiData.pattern_count;
            if (apiData.csv_time_str) {
                document.getElementById('stat-updated').textContent = apiData.csv_time_str;
            }

            renderSummaryTable(apiData.patterns);
            renderNextTable(apiData.next_patterns);
            renderLateTable(apiData.late_patterns || []);

            document.getElementById('update-time').textContent = 'Last: ' + new Date().toLocaleTimeString();
            updateSummarySyncState(apiData);
            document.getElementById('last-update').textContent =
                'CSV last modified: ' + (apiData.csv_time ? new Date(apiData.csv_time * 1000).toLocaleString() : '-')
                + ' | Total ' + apiData.total_matches + ' matches | Auto-refresh: ' + SUMMARY_REFRESH_SECONDS + 's via AJAX';

            if (activePanel && PATTERN_DATA[activePanel]) {
                refreshActivePanelContent();
            }
        } catch(e) {
            // silent — keep current data
        }
    }

    function applySummarySort(patterns) {
        if (!summarySortState.col) return patterns;
        return patterns.slice().sort(function(a, b) {
            return sortSummary(patterns, a, b, summarySortState.col, summarySortState.dir);
        });
    }

    function applyNextSort(nextPatterns) {
        if (!nextSortState.col) return nextPatterns;
        return nextPatterns.slice().sort(function(a, b) {
            return sortNext(nextPatterns, a, b, nextSortState.col, nextSortState.dir);
        });
    }

    function renderSummaryTable(patterns) {
        var visiblePatterns = patterns.filter(function(p) {
            return (p.total || 0) >= SUMMARY_MIN_SAMPLE;
        });
        currentSummaryData = visiblePatterns;
        var sorted = applySummarySort(visiblePatterns);
        var tbody = document.getElementById('summary-body');
        tbody.innerHTML = sorted.map(function(p) {
            return '<tr data-pid="' + p.id + '" data-total="' + p.total + '" data-hits="' + p.has2h + '" data-pct="' + p.pct + '">'
                + '<td><strong>' + p.id + '</strong></td>'
                + '<td>' + escHtml(p.label) + '</td>'
                + '<td>' + p.has2h + '/' + p.total + '</td>'
                + '<td class="pct ' + p.cls + '">' + p.pct + '%</td>'
                + '<td><span class="badge ' + p.badge + '">' + p.status + '</span></td>'
                + '<td class="delta-cell" style="font-size:0.8rem;">' + (p.delta && p.delta.html ? p.delta.html : '<span class="delta-zero">\u2014</span>') + '</td>'
                + '<td><button class="expand-btn" data-pid="' + p.id + '">Detail</button></td>'
                + '</tr>';
        }).join('');
        bindExpandButtons(tbody);
        applyLiveCandidateIndicators();
    }

    function renderNextTable(nextPatterns) {
        var visibleNextPatterns = nextPatterns.filter(function(ng) {
            return (ng.total || 0) >= NEXT_MIN_SAMPLE;
        });
        currentNextData = visibleNextPatterns;
        var sorted = applyNextSort(visibleNextPatterns);
        var tbody = document.getElementById('next-body');
        tbody.innerHTML = sorted.map(function(ng) {
            var nextBadge = ng.next === 'HOME'
                ? '<span class="scorer-h next-badge-home">HOME</span>'
                : '<span class="scorer-a next-badge-away">AWAY</span>';
            return '<tr data-pid="' + ng.id + '" data-total="' + ng.total + '" data-hits="' + ng.hits + '" data-nh="' + ng.nh + '" data-na="' + ng.na + '" data-pct="' + ng.pct + '">'
                + '<td><strong>' + ng.id + '</strong></td>'
                + '<td>' + escHtml(ng.label) + '</td>'
                + '<td>' + nextBadge + '</td>'
                + '<td>' + ng.hits + '/' + ng.total + ' <span class="record-sub">(H:' + ng.nh + ' A:' + ng.na + ')</span></td>'
                + '<td class="pct ' + ng.cls + '">' + ng.pct + '%</td>'
                + '<td><span class="badge ' + ng.badge + '">' + ng.status + '</span></td>'
                + '<td class="delta-cell" style="font-size:0.8rem;">' + (ng.delta && ng.delta.html ? ng.delta.html : '<span class="delta-zero">\u2014</span>') + '</td>'
                + '<td><button class="expand-btn" data-pid="' + ng.id + '">Detail</button></td>'
                + '</tr>';
        }).join('');
        bindExpandButtons(tbody);
        applyNextLiveCandidateIndicators();
    }

    function renderLateTable(latePatterns) {
        var visibleLatePatterns = latePatterns.filter(function(lp) {
            return (lp.total || 0) >= LATE_MIN_SAMPLE;
        });
        currentLateData = visibleLatePatterns;
        var tbody = document.getElementById('late-body');
        if (!tbody) return;
        tbody.innerHTML = visibleLatePatterns.map(function(lp) {
            return '<tr data-pid="' + lp.id + '" data-total="' + lp.total + '" data-hits="' + lp.late_hits + '" data-pct="' + lp.pct + '">'
                + '<td><strong>' + lp.id + '</strong></td>'
                + '<td>' + escHtml(lp.label) + '</td>'
                + '<td>' + lp.late_hits + '/' + lp.total + '</td>'
                + '<td class="pct ' + lp.cls + '">' + lp.pct + '%</td>'
                + '<td><span class="badge ' + lp.badge + '">' + lp.status + '</span></td>'
                + '<td class="delta-cell" style="font-size:0.8rem;">' + (lp.delta && lp.delta.html ? lp.delta.html : '<span class="delta-zero">\u2014</span>') + '</td>'
                + '<td><button class="expand-btn" data-pid="' + lp.id + '">Detail</button></td>'
                + '</tr>';
        }).join('');
        bindExpandButtons(tbody);
        applyLateLiveCandidateIndicators();
    }

    function bindExpandButtons(root) {
        root.querySelectorAll('.expand-btn[data-pid]').forEach(function(btn) {
            btn.onclick = function(e) {
                if (e) e.preventDefault();
                toggle(this.dataset.pid);
            };
        });
    }

    function getLeagueTypeJS(league) {
        if (!league) return null;
        var l = league.toLowerCase();
        if (l.includes('20 min') || l.includes('20min')) return '20min';
        if (l.includes('16 min') || l.includes('16min')) return '16min';
        if (l.includes('15 min') || l.includes('15min')) return '15min';
        return null;
    }

    function parseStatus(status) {
        var m = String(status || '').trim().match(/^(1H|2H)\s+(\d+)/i);
        if (!m) return { half: null, min: -1 };
        return { half: m[1].toUpperCase(), min: parseInt(m[2], 10) };
    }

    function normalizeLivePayload(apiData) {
        if (apiData && Array.isArray(apiData.matches)) {
            return {
                matches: apiData.matches,
                allGoalMinutes: apiData.allGoalMinutes || {},
                allGoalScorers: apiData.allGoalScorers || {},
                all2HGoalMinutes: apiData.all2HGoalMinutes || {},
                htScores: apiData.htScores || {},
                patternSignals: apiData.patternSignals || {}
            };
        }

        var data = apiData && apiData.data ? apiData.data : {};
        return {
            matches: Array.isArray(data.live_matches) ? data.live_matches : (Array.isArray(data.matches) ? data.matches : []),
            allGoalMinutes: data.allGoalMinutes || apiData.allGoalMinutes || {},
            allGoalScorers: data.allGoalScorers || apiData.allGoalScorers || {},
            all2HGoalMinutes: data.all2HGoalMinutes || apiData.all2HGoalMinutes || {},
            htScores: data.htScores || apiData.htScores || {},
            patternSignals: data.patternSignals || apiData.patternSignals || {}
        };
    }

    function getMatchStatusText(match) {
        return String((match && (match.status || match.time)) || '').trim();
    }

    function getMatchLeague(match) {
        return String((match && match.league) || '').trim();
    }

    function getMatchHomeTeam(match) {
        return String((match && (match.homeTeam || match.home_team || match.home)) || '').trim();
    }

    function getMatchAwayTeam(match) {
        return String((match && (match.awayTeam || match.away_team || match.away)) || '').trim();
    }

    function getMatchScores(match) {
        var home = parseInt(match && (match.homeScore || match.home_score), 10);
        var away = parseInt(match && (match.awayScore || match.away_score), 10);

        if (Number.isNaN(home) || Number.isNaN(away)) {
            var scoreText = String((match && match.score) || '').trim();
            var scoreMatch = scoreText.match(/(\d+)\s*-\s*(\d+)/);
            if (scoreMatch) {
                home = parseInt(scoreMatch[1], 10);
                away = parseInt(scoreMatch[2], 10);
            }
        }

        return {
            home: Number.isNaN(home) ? 0 : home,
            away: Number.isNaN(away) ? 0 : away
        };
    }

    function countSwitchesJS(scorers) {
        var total = 0;
        for (var i = 1; i < scorers.length; i++) {
            if (scorers[i] !== scorers[i - 1]) total += 1;
        }
        return total;
    }

    function maxRunJS(scorers) {
        if (!scorers.length) return 0;
        var max = 1;
        var current = 1;
        for (var i = 1; i < scorers.length; i++) {
            current = scorers[i] === scorers[i - 1] ? current + 1 : 1;
            if (current > max) max = current;
        }
        return max;
    }

    function minGapJS(goalMins) {
        if (goalMins.length < 2) return 99;
        var min = 99;
        for (var i = 1; i < goalMins.length; i++) {
            min = Math.min(min, goalMins[i] - goalMins[i - 1]);
        }
        return min;
    }

    function allGapsGeJS(goalMins, minGap) {
        for (var i = 1; i < goalMins.length; i++) {
            if ((goalMins[i] - goalMins[i - 1]) < minGap) return false;
        }
        return true;
    }

    function arrayEqualsJS(a, b) {
        if (a.length !== b.length) return false;
        for (var i = 0; i < a.length; i++) {
            if (a[i] !== b[i]) return false;
        }
        return true;
    }

    function scorerCountsJS(scorers) {
        var home = 0;
        var away = 0;
        scorers.forEach(function(s) {
            if (s === 'H') home += 1;
            if (s === 'A') away += 1;
        });
        return { home: home, away: away };
    }

    function deriveFallbackScorers(goalMins, match) {
        if (goalMins.length !== 1) return [];
        var score = getMatchScores(match);
        if (score.home === 1 && score.away === 0) return ['H'];
        if (score.home === 0 && score.away === 1) return ['A'];
        return [];
    }

    function deriveFallbackGoalMinutes(match) {
        var status = parseStatus(getMatchStatusText(match));
        if (status.half !== '1H' || status.min < 0) return [];
        var score = getMatchScores(match);
        return (score.home + score.away) === 1 ? [status.min] : [];
    }

    function clearLiveGoalMemory(key) {
        delete liveGoalMemory[key];
        delete liveScorerMemory[key];
        delete livePatternStateMemory[key];
        savePatternStateMemory();
    }

    function savePatternStateMemory() {
        try {
            sessionStorage.setItem(LIVE_PATTERN_STATE_STORAGE_KEY, JSON.stringify({
                schemaVersion: LIVE_STATE_SCHEMA_VERSION,
                savedAt: Date.now(),
                memory: livePatternStateMemory
            }));
        } catch (e) {
            // ignore storage failures
        }
    }

    function restorePatternStateMemory() {
        try {
            var raw = sessionStorage.getItem(LIVE_PATTERN_STATE_STORAGE_KEY);
            if (!raw) return;
            var parsed = JSON.parse(raw);
            if (parsed.schemaVersion !== LIVE_STATE_SCHEMA_VERSION) {
                sessionStorage.removeItem(LIVE_PATTERN_STATE_STORAGE_KEY);
                return;
            }
            var savedAt = parseInt(parsed.savedAt, 10);
            if (!Number.isFinite(savedAt) || Date.now() - savedAt > LIVE_PATTERN_STATE_TTL_MS) {
                sessionStorage.removeItem(LIVE_PATTERN_STATE_STORAGE_KEY);
                return;
            }
            livePatternStateMemory = parsed.memory || {};
        } catch (e) {
            sessionStorage.removeItem(LIVE_PATTERN_STATE_STORAGE_KEY);
        }
    }

    function getStoredPatternState(key, match, livePayload) {
        var entry = livePatternStateMemory[key];
        if (!entry || !entry.state || !Number.isFinite(entry.savedAt)) return null;
        if (Date.now() - entry.savedAt > LIVE_PATTERN_STATE_TTL_MS) return null;
        if (getLiveSecondHalfGoalMinutes(livePayload, key, match).length > 0) return null;

        var score = getMatchScores(match);
        if (score.home !== entry.state.sc_h || score.away !== entry.state.sc_a) return null;

        return JSON.parse(JSON.stringify(entry.state));
    }

    function persistPatternState(key, state) {
        if (!key || !state || !Array.isArray(state.h1s) || !state.h1s.length) return;
        livePatternStateMemory[key] = {
            savedAt: Date.now(),
            state: JSON.parse(JSON.stringify(state))
        };
        savePatternStateMemory();
    }

    function getStoredGoalMinutes(key) {
        return Array.isArray(liveGoalMemory[key]) ? liveGoalMemory[key].slice() : [];
    }

    function getStoredGoalScorers(key) {
        return Array.isArray(liveScorerMemory[key]) ? liveScorerMemory[key].slice() : [];
    }

    function persistLiveGoalMemory(key, goalMins, scorers) {
        if (!goalMins.length || goalMins.length !== scorers.length) return;
        liveGoalMemory[key] = goalMins.slice();
        liveScorerMemory[key] = scorers.slice();
    }

    function inferUniformScorers(score) {
        var totalGoals = score.home + score.away;
        if (!totalGoals) return [];
        if (score.home > 0 && score.away === 0) {
            return Array(score.home).fill('H');
        }
        if (score.away > 0 && score.home === 0) {
            return Array(score.away).fill('A');
        }
        return [];
    }

    function distributeGoalMinutes(startMin, endMin, goalCount) {
        if (goalCount <= 0) return [];
        if (goalCount === 1) return [endMin];

        var boundedStart = Math.max(0, startMin);
        var boundedEnd = Math.max(boundedStart, endMin);
        var minutes = [];
        for (var i = 0; i < goalCount; i++) {
            var ratio = goalCount === 1 ? 1 : (i / (goalCount - 1));
            var raw = boundedStart + ((boundedEnd - boundedStart) * ratio);
            var minute = Math.round(raw);
            if (i === 0) {
                minute = boundedStart;
            } else if (i === goalCount - 1) {
                minute = boundedEnd;
            } else if (minute < minutes[i - 1]) {
                minute = minutes[i - 1];
            }
            minutes.push(minute);
        }
        return minutes;
    }

    function syncLiveGoalMemory(matches, livePayload) {
        for (var i = 0; i < matches.length; i++) {
            var match = matches[i];
            var key = matchKey(match);
            var status = parseStatus(getMatchStatusText(match));
            var score = getMatchScores(match);
            var totalGoals = score.home + score.away;
            var goalMins = getStoredGoalMinutes(key);
            var scorers = getStoredGoalScorers(key);

            if (status.half === '1H' && status.min >= 0 && status.min <= 1 && totalGoals === 0) {
                clearLiveGoalMemory(key);
                goalMins = [];
                scorers = [];
            }

            var payloadGoalMins = getLiveGoalMinutes(livePayload, key, match);
            var payloadScorers = getLiveGoalScorers(livePayload, key, match);
            if (payloadGoalMins.length && payloadGoalMins.length === payloadScorers.length) {
                var storedCount = goalMins.length;
                var payloadCount = payloadGoalMins.length;
                var shouldAcceptPayload = payloadCount >= storedCount;
                if (shouldAcceptPayload) {
                    persistLiveGoalMemory(key, payloadGoalMins, payloadScorers);
                    continue;
                }
            }

            if (goalMins.length !== scorers.length) {
                clearLiveGoalMemory(key);
                goalMins = [];
                scorers = [];
            }

            if (totalGoals < goalMins.length) {
                clearLiveGoalMemory(key);
                goalMins = [];
                scorers = [];
            }

            if (status.half !== '1H' || status.min < 0) {
                continue;
            }

            var seededFromCurrentScore = false;
            if (!goalMins.length && totalGoals === 1) {
                goalMins = [status.min];
                scorers = score.home > score.away ? ['H'] : ['A'];
                seededFromCurrentScore = true;
            }

            var prev = prevStateMemory[key];
            if (!goalMins.length && !prev && totalGoals > 1) {
                var uniformScorers = inferUniformScorers(score);
                if (uniformScorers.length === totalGoals) {
                    goalMins = distributeGoalMinutes(1, status.min, totalGoals);
                    scorers = uniformScorers;
                }
            }

            if (prev && prev.half === '1H' && !seededFromCurrentScore) {
                var homeDelta = Math.max(0, score.home - prev.h);
                var awayDelta = Math.max(0, score.away - prev.a);
                var goalCountDelta = homeDelta + awayDelta;
                var prevMin = Number.isFinite(prev.min) ? prev.min : status.min;

                if (goalCountDelta > 0) {
                    var inferredMinutes = goalCountDelta > 1
                        ? distributeGoalMinutes(Math.min(status.min, prevMin + 1), status.min, goalCountDelta)
                        : [status.min];
                    var inferredScorers = [];

                    for (var hIdx = 0; hIdx < homeDelta; hIdx++) {
                        inferredScorers.push('H');
                    }
                    for (var aIdx = 0; aIdx < awayDelta; aIdx++) {
                        inferredScorers.push('A');
                    }

                    for (var gIdx = 0; gIdx < inferredScorers.length; gIdx++) {
                        goalMins.push(inferredMinutes[gIdx] || status.min);
                        scorers.push(inferredScorers[gIdx]);
                    }
                }
            }

            persistLiveGoalMemory(key, goalMins, scorers);
        }
    }

    function buildLivePatternState(match, livePayload) {
        var key = matchKey(match);
        var payloadGoalMins = getLiveGoalMinutes(livePayload, key, match);
        var payloadScorers = getLiveGoalScorers(livePayload, key, match);
        var storedGoalMins = getStoredGoalMinutes(key);
        var storedScorers = getStoredGoalScorers(key);

        var useStored = storedGoalMins.length > payloadGoalMins.length;
        var goalMins = useStored ? storedGoalMins : payloadGoalMins;
        var scorers = useStored ? storedScorers : payloadScorers;

        if (!goalMins.length) {
            goalMins = storedGoalMins;
            scorers = storedScorers;
        }
        if (!goalMins.length) {
            var storedState = getStoredPatternState(key, match, livePayload);
            if (storedState) return storedState;
        }
        if (!goalMins.length) {
            goalMins = deriveFallbackGoalMinutes(match);
        }
        if (!scorers.length && goalMins.length) {
            scorers = storedScorers;
        }
        if (!scorers.length && goalMins.length) {
            scorers = payloadScorers;
        }
        if (!scorers.length && goalMins.length) {
            scorers = deriveFallbackScorers(goalMins, match);
        }
        if (goalMins.length !== scorers.length) {
            var fallbackState = getStoredPatternState(key, match, livePayload);
            if (fallbackState) return fallbackState;
            return null;
        }

        var counts = scorerCountsJS(scorers);
        var liveScore = getMatchScores(match);
        var state = {
            home: getMatchHomeTeam(match),
            away: getMatchAwayTeam(match),
            league: getLeagueTypeJS(getMatchLeague(match)),
            h1c: goalMins.length,
            sc_h: counts.home,
            sc_a: counts.away,
            fh: liveScore.home,
            fa: liveScore.away,
            h1_first: goalMins.length ? goalMins[0] : -1,
            h1_last: goalMins.length ? goalMins[goalMins.length - 1] : -1,
            h1s: scorers,
            switches: countSwitchesJS(scorers),
            max_gap: getMaxGap(goalMins),
            min_gap: minGapJS(goalMins),
            max_run: maxRunJS(scorers),
            all_gaps_ge3: allGapsGeJS(goalMins, 3),
            goal_mins: goalMins.slice()
        };
        persistPatternState(key, state);
        return state;
    }

    function inTeamConfig(listName, team) {
        return Array.isArray(TEAM_CONFIG[listName]) && TEAM_CONFIG[listName].indexOf(team) !== -1;
    }

    function matchesP58StrongGroup(s) {
        if (!s) return false;
        var key = s.league + '|' + s.h1_first + '|' + s.h1_last + '|' + s.h1s.join('') + '|' + s.sc_h + '-' + s.sc_a;
        return [
            '15min|5|6|HA|1-1', '15min|3|7|HH|2-0', '20min|1|4|HH|2-0',
            '15min|2|4|HH|2-0', '15min|6|7|AH|1-1', '15min|1|4|HAA|1-2',
            '15min|0|7|HAH|2-1', '20min|3|5|AA|0-2', '20min|7|9|AA|0-2',
            '15min|4|7|HA|1-1'
        ].indexOf(key) !== -1;
    }

    function matchesP26StrongGroup(s) {
        if (!s) return false;
        var key = s.league + '|' + s.h1_first + '|' + s.h1_last + '|' + s.h1s.join('') + '|' + s.sc_h + '-' + s.sc_a;
        return ['16min|0|6|HAA|1-2', '16min|3|7|AAA|0-3'].indexOf(key) !== -1;
    }

    function matchesLG7StrongGroup(s) {
        if (!s) return false;
        var key = s.league + '|' + s.h1_first + '|' + s.h1_last + '|' + s.h1s.join('') + '|' + s.sc_h + '-' + s.sc_a;
        return ['20min|0|8|HA|1-1', '15min|1|5|AHA|1-2', '20min|1|9|AAH|1-2'].indexOf(key) !== -1;
    }

    function matchesLG5StrongGroup(s) {
        return matchesLG7StrongGroup(s);
    }

    function matchesLG6StrongGroup(s) {
        return matchesLG7StrongGroup(s);
    }

    function matchesSummaryPatternLive(pid, s) {
        if (!s) return false;
        var span = s.h1c >= 2 ? (s.h1_last - s.h1_first) : 0;
        var diff = Math.abs(s.sc_h - s.sc_a);
        var lastScorer = s.h1s.length ? s.h1s[s.h1s.length - 1] : null;
        var firstScorer = s.h1s.length ? s.h1s[0] : null;

        switch (pid) {
            case 'P2': return s.league === '16min' && s.h1c >= 2 && diff >= 2 && s.h1_last === 7 && s.all_gaps_ge3 && s.max_run <= 2 && s.h1_first <= 1;
            case 'P6': return s.h1c === 2 && s.sc_h === 1 && s.sc_a === 1 && s.h1_last === 7 && span >= 5 && s.h1_first !== 1 && ['Manchester City (V)', 'Atletico de Madrid (V)', 'England (V)'].indexOf(s.home) === -1 && !(s.league === '15min' && s.h1_first === 0 && arrayEqualsJS(s.h1s, ['H', 'A'])) && !(s.h1_first === 0 && arrayEqualsJS(s.h1s, ['A', 'H']));
            case 'P7': return s.h1c === 2 && s.sc_h === 1 && s.sc_a === 1 && s.max_gap >= 5 && s.h1_first >= 3 && !(s.league === '16min' && s.h1_first === 3 && s.h1_last === 8 && arrayEqualsJS(s.h1s, ['A', 'H'])) && !(s.home === 'Netherlands (V)' && s.league === '20min' && s.h1_first === 4 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['A', 'H']) && s.sc_h === 1 && s.sc_a === 1);
            case 'P9': return s.h1c === 2 && s.sc_h === 1 && s.sc_a === 1 && arrayEqualsJS(s.h1s, ['A', 'H']) && s.max_gap >= 5 && s.h1_first >= 3 && !(s.league === '16min' && s.h1_first === 3 && s.h1_last === 8);
            case 'P12': return s.h1c >= 4 && span >= 6 && s.min_gap >= 1 && s.h1_last <= 9 && s.h1_first >= 1 && s.h1_first !== 1 && s.max_run <= 2;
            case 'P13': return s.h1c >= 2 && s.h1_first === 2 && s.h1_last === 7 && diff <= 2 && s.min_gap >= 3 && s.switches >= 1 && !(s.home === 'Manchester City (V)' && s.away === 'Liverpool (V)');
            case 'P14': return s.h1c >= 2 && s.sc_h === s.sc_a && s.sc_h > 0 && s.max_gap >= 4 && span >= 5 && s.h1_first >= 3 && s.min_gap >= 2 && !(s.league === '16min' && s.h1_first === 3 && s.h1_last === 8 && arrayEqualsJS(s.h1s, ['A', 'H'])) && !(s.home === 'Netherlands (V)' && s.league === '20min' && s.h1_first === 4 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['A', 'H']) && s.sc_h === 1 && s.sc_a === 1);
            case 'P15': return (((s.sc_h === 2 && s.sc_a === 2 && s.max_gap <= 2 && s.h1_last !== 8 && !(s.h1_first === 0 && s.h1_last === 5)) || (s.league === '20min' && s.sc_h === 2 && s.sc_a === 2 && s.max_gap === 3 && s.h1_first === 0 && s.h1_last <= 7) || (s.league === '16min' && s.sc_h === 2 && s.sc_a === 2 && s.max_gap === 3 && s.h1_first >= 2) || (s.league === '15min' && s.sc_h === 2 && s.sc_a === 2 && s.max_gap === 3 && s.h1_first === 1 && s.h1_last <= 6)) && !(s.league === '15min' && arrayEqualsJS(s.h1s, ['A', 'H', 'H', 'A']) && arrayEqualsJS(s.goal_mins, [1, 2, 4, 6])) && !(s.league === '20min' && s.h1_first === 0 && s.h1_last === 5 && arrayEqualsJS(s.h1s, ['H', 'A', 'A', 'H']) && s.sc_h === 2 && s.sc_a === 2));
            case 'P17': return s.h1c >= 2 && s.h1_first >= 1 && s.h1_first <= 2 && s.h1_last === 7 && s.max_gap >= 2 && s.min_gap >= 2 && s.switches >= 1 && firstScorer === 'A' && ((s.league === '15min' && s.h1_first === 1) || (s.league === '20min' && (s.h1_first === 2 || s.sc_a > s.sc_h)));
            case 'P19': return s.league === '20min' && s.h1c >= 2 && lastScorer === 'H' && s.h1_first <= 1 && s.switches >= 1 && (((s.h1_last === 3) && (s.h1_first === 0 || s.h1c >= 3)) || (s.h1_last === 4 && (s.sc_a > s.sc_h || s.switches >= 2))) && !(s.h1_first === 1 && s.h1_last === 3 && arrayEqualsJS(s.h1s, ['H', 'H', 'H', 'A', 'H', 'H']) && s.sc_h === 5 && s.sc_a === 1);
            case 'P20': return s.league === '16min' && s.h1_last === 3 && lastScorer === 'A' && (s.h1_first <= 1 || s.h1c >= 2);
            case 'P21': return s.league === '15min' && s.h1_last === 5 && lastScorer === 'A' && s.max_gap >= 2 && s.min_gap >= 1 && (s.h1c >= 3 || s.sc_a > s.sc_h) && s.switches >= 1 && s.max_run <= 2 && s.h1_first >= 1 && !(s.h1_first === 1 && s.h1_last === 5 && s.min_gap === 1 && s.max_run === 2 && s.sc_a > s.sc_h);
            case 'P24': return s.league === '15min' && inTeamConfig('p24_teams', s.home) && s.h1c >= 1 && s.h1_last >= 4 && diff <= 1 && s.sc_h >= 1 && s.h1_first >= 4 && firstScorer === 'H' && (s.home !== 'Everton (V)' || s.h1_last <= 5) && (s.home !== 'Arminia Bielefeld (V)' || !(s.h1c === 1 && s.h1_first >= 5 && s.h1_last <= 6)) && !(s.h1c === 1 && s.h1_first === 4 && s.h1_last === 4 && arrayEqualsJS(s.h1s, ['H']) && s.sc_h === 1 && s.sc_a === 0) && !(s.h1c === 1 && s.h1_last === 5) && !(s.home === 'Arsenal (V)' && s.h1_first === 6 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['H', 'A']) && s.sc_h === 1 && s.sc_a === 1) && !(s.home === 'Olympique de Marseille (V)' && s.away === 'Udinese (V)' && s.h1c === 1 && s.h1_first === 6 && arrayEqualsJS(s.h1s, ['H']) && s.sc_h === 1 && s.sc_a === 0) && !(s.h1_first === 5 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['H', 'A', 'A']) && s.sc_h === 1 && s.sc_a === 2) && !(s.home === 'Olympique de Marseille (V)' && s.away === 'Liverpool (V)');
            case 'P25': return inTeamConfig('p25_teams', s.away) && s.h1_last >= 2 && diff <= 1 && span >= 3 && s.min_gap >= 2 && lastScorer === 'A' && !(s.h1c === 2 && span === 3 && s.h1_first === 4) && !(s.league === '20min' && s.h1_first === 0 && s.h1_last === 5 && arrayEqualsJS(s.h1s, ['H', 'A']) && s.sc_h === 1 && s.sc_a === 1);
            case 'P26': return (s.league === '16min' && ((s.sc_h + s.sc_a) % 2 === 1) && s.h1_last >= 6 && s.h1c >= 2 && s.sc_a > s.sc_h && s.max_run <= 2 && (s.h1_first !== 1 || s.max_gap >= 4) && !(s.h1_last === 8 && [3, 5].indexOf(s.h1_first) !== -1 && arrayEqualsJS(s.h1s, ['H', 'A', 'A']) && s.sc_h === 1 && s.sc_a === 2)) || matchesP26StrongGroup(s);
            case 'P27': return s.league === '16min' && lastScorer === 'A' && s.max_gap >= 3 && s.h1_first !== 1 && span >= 6;
            case 'P28': return (inTeamConfig('p28_teams', s.home) || inTeamConfig('p28_teams', s.away)) && s.h1_last >= 3 && span >= 3 && s.switches >= 1 && (inTeamConfig('p28_teams', s.away) || s.h1_first >= 2) && !(s.league === '20min' && s.h1_first === 4 && s.h1_last === 8 && s.sc_h === 1 && s.sc_a === 2 && arrayEqualsJS(s.h1s, ['A', 'H', 'A'])) && !(s.league === '20min' && s.h1_first === 2 && s.h1_last >= 9 && s.sc_h === 2 && s.sc_a === 1 && arrayEqualsJS(s.h1s, ['H', 'A', 'H'])) && !(s.league === '16min' && s.h1_first === 5 && s.h1_last === 8 && arrayEqualsJS(s.h1s, ['H', 'A', 'A']) && s.sc_h === 1 && s.sc_a === 2);
            case 'P32': return s.league === '20min' && s.h1c >= 2 && span >= 9 && s.sc_h === s.sc_a && s.min_gap >= 3 && s.switches >= 1 && s.h1_first !== 1;
            case 'P33': return s.league === '15min' && s.h1c >= 4 && diff <= 1 && s.min_gap >= 1 && s.h1_last >= 6 && (s.switches >= 2 || s.h1_first >= 1) && !(arrayEqualsJS(s.h1s, ['A', 'H', 'H', 'A']) && s.max_gap === 2 && (s.h1_last >= 8 || s.h1_first <= 1)) && !(s.h1_first === 3 && s.h1_last === 8 && arrayEqualsJS(s.h1s, ['A', 'A', 'H', 'H']) && s.sc_h === 2 && s.sc_a === 2 && s.max_gap === 2);
            case 'P34': return s.league === '15min' && firstScorer === 'A' && lastScorer === 'H' && span >= 6 && s.h1c >= 4 && !(s.h1_first === 0 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['A', 'A', 'H', 'H']) && s.sc_h === 2 && s.sc_a === 2 && s.min_gap === 2) && !(s.h1_first === 0 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['A', 'H', 'A', 'H', 'H']) && s.sc_h === 3 && s.sc_a === 2);
            case 'P35': return (inTeamConfig('p35_teams', s.away) && s.h1c >= 2 && s.h1_first >= 3 && s.max_run <= 2 && s.h1_last >= 5 && s.min_gap >= 2 && !(s.h1s.length === 2 && s.h1s[0] === 'H' && s.h1s[1] === 'H' && s.h1_first === 3 && s.h1_last === 5) && !(s.league === '16min' && s.h1c === 2 && s.sc_h === 2 && s.sc_a === 0 && arrayEqualsJS(s.h1s, ['H', 'H']) && s.h1_first === 4 && s.h1_last === 6) && !(s.league === '16min' && s.h1c === 2 && s.sc_h === 2 && s.sc_a === 0 && arrayEqualsJS(s.h1s, ['H', 'H']) && s.h1_first >= 5 && s.h1_last === 7) && !(s.away === 'Poland (V)' && s.league === '16min' && s.h1_first === 5 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['A', 'A']) && s.sc_h === 0 && s.sc_a === 2) && !(s.league === '20min' && s.h1_first === 4 && s.h1_last === 9 && s.h1c === 2 && s.sc_h === 0 && s.sc_a === 2 && arrayEqualsJS(s.h1s, ['A', 'A'])) && !(s.h1_first === 3 && s.h1_last === 5 && s.h1c === 2 && s.sc_h === 1 && s.sc_a === 1 && arrayEqualsJS(s.h1s, ['H', 'A'])) && !(s.h1_first === 3 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['H', 'A', 'A'])) && !(s.league === '20min' && s.h1_first === 4 && s.h1_last === 6 && arrayEqualsJS(s.h1s, ['A', 'H']) && s.sc_h === 1 && s.sc_a === 1)) || (s.league === '16min' && inTeamConfig('p35_teams', s.away) && s.h1c >= 3 && s.h1_first === 1 && s.max_run <= 2 && s.h1_last >= 5 && s.min_gap >= 2) || (inTeamConfig('p35_teams', s.away) && s.h1c >= 3 && s.h1_first === 0 && s.max_run <= 2 && s.h1_last >= 6 && s.min_gap >= 2 && lastScorer === 'A' && !(s.league === '20min' && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['A', 'H', 'A']) && s.sc_h === 1 && s.sc_a === 2)) || (s.away === 'Portugal (V)' && s.h1c === 1 && arrayEqualsJS(s.h1s, ['A']) && !(s.h1_first === 0 && s.h1_last === 0 && s.sc_h === 0 && s.sc_a === 1)) || (inTeamConfig('p35_teams', s.away) && s.h1c === 3 && arrayEqualsJS(s.h1s, ['H', 'H', 'A']) && s.min_gap === 1);
            case 'P37': return s.league === '16min' && s.h1c >= 2 && s.h1_first <= 1 && s.h1_last <= 4 && firstScorer === 'A' && lastScorer === 'A' && s.switches === 0;
            case 'P39': return s.league === '20min' && s.h1c >= 3 && span >= 7 && diff <= 3 && s.min_gap >= 3 && s.h1_first >= 1;
            case 'P40': return s.league === '16min' && diff === 2 && s.h1_first <= 1 && span >= 5 && (s.min_gap >= 1 || s.max_gap >= 4) && !((s.h1_first === 0 && s.h1_last === 5 && arrayEqualsJS(s.h1s, ['H', 'H']) && s.sc_h === 2 && s.sc_a === 0) || (s.h1_first === 1 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['H', 'H']) && s.sc_h === 2 && s.sc_a === 0)) && !(lastScorer === 'A' && (s.sc_h - s.sc_a) >= 2 && s.max_run >= 3 && s.max_gap <= 3);
            case 'P41': return diff >= 2 && s.h1_first >= 2 && span >= 6 && s.max_gap >= 5 && !(s.h1c === 2 && s.max_gap >= 7 && s.sc_a > s.sc_h) && !(s.league === '20min' && s.h1c === 2 && s.sc_h === 2 && s.sc_a === 0 && s.h1_first === 2 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['H', 'H'])) && !(s.league === '20min' && s.h1_first >= 2 && s.h1_last >= 10 && arrayEqualsJS(s.h1s, ['H', 'H', 'H']));
            case 'P42': return s.league === '20min' && s.h1_first >= 2 && span >= 6 && s.min_gap >= 3 && !arrayEqualsJS(s.h1s, ['H', 'A']) && !(s.h1_first === 2 && s.h1_last === 9 && s.sc_h === 2 && s.sc_a === 1 && arrayEqualsJS(s.h1s, ['H', 'H', 'A'])) && !(s.max_gap >= 7 && s.h1c === 2 && s.sc_a > s.sc_h) && !(s.h1c === 2 && s.sc_h === 2 && s.sc_a === 0 && s.h1_first === 2 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['H', 'H']));
            case 'P43': return s.sc_a > s.sc_h && span >= 6 && lastScorer === 'H' && (s.sc_a - s.sc_h) === 1 && s.h1c <= 3 && !(s.league === '20min' && s.sc_h === 1 && s.sc_a === 2 && arrayEqualsJS(s.h1s, ['A', 'A', 'H']) && ((s.h1_first === 0 && s.h1_last === 9) || (s.h1_first === 1 && s.h1_last === 8) || (s.h1_first === 2 && s.h1_last === 9)));
            case 'P44': return diff >= 2 && s.h1_first >= 2 && s.switches >= 1 && s.max_run <= 2 && (s.league === '20min' || s.h1_last >= 8 || (s.sc_h === 3 && s.sc_a === 1)) && !(s.league === '20min' && s.h1_first === 2 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['A', 'H', 'A', 'A']) && s.sc_h === 1 && s.sc_a === 3);
            case 'P45': return s.league === '16min' && s.h1_first === 0 && span >= 6 && s.max_gap >= 6;
            case 'P46': return s.league === '16min' && span >= 6 && s.min_gap >= 2 && s.h1c === 2 && (s.h1_first === 0 || s.switches === 0) && !(s.h1_first === 1 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['H', 'H']) && s.sc_h === 2 && s.sc_a === 0);
            case 'P47': return s.sc_h === s.sc_a && s.h1_first !== 1 && s.switches >= 2 && s.h1_last >= 6 && s.min_gap >= 1 && s.max_gap >= 3 && !(s.league === '20min' && s.h1_first === 2 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['A', 'H', 'A', 'H']));
            case 'P48': return s.sc_h === s.sc_a && span >= 7 && s.switches >= 2 && (s.h1_first === 0 || span === 7 || lastScorer === 'H') && !(s.league === '20min' && s.h1_first === 0 && s.h1_last === 7 && s.h1c === 4 && s.sc_h === 2 && s.sc_a === 2 && s.min_gap === 0 && arrayEqualsJS(s.h1s, ['A', 'H', 'H', 'A'])) && !(s.league === '20min' && s.h1_first === 0 && s.h1_last === 9 && s.h1c === 4 && s.sc_h === 2 && s.sc_a === 2 && s.min_gap === 0 && arrayEqualsJS(s.h1s, ['H', 'A', 'H', 'A']));
            case 'P49': return s.league === '16min' && diff >= 2 && span >= 6 && s.h1_first >= 1 && !(s.h1_first === 1 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['H', 'H']) && s.sc_h === 2 && s.sc_a === 0);
            case 'P50': return s.league === '16min' && s.sc_a > s.sc_h && span >= 6 && s.max_run <= 2 && (s.h1_first === 0 || s.max_gap >= 6);
            case 'P51': return s.league === '16min' && s.switches >= 2 && s.h1_first !== 1 && !(s.h1_first === 0 && s.h1_last === 8 && arrayEqualsJS(s.h1s, ['A', 'H', 'H', 'A', 'H']) && s.sc_h === 3 && s.sc_a === 2 && s.min_gap === 0);
            case 'P52': return s.league === '16min' && span >= 6 && s.min_gap >= 3 && diff >= 2 && !(s.h1_first === 1 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['H', 'H']) && s.sc_h === 2 && s.sc_a === 0);
            case 'P53': return ((s.league === '20min' && s.h1_last === 3 && lastScorer === 'H' && s.min_gap >= 1 && s.h1c <= 2 && (s.h1_first === 0 || s.sc_a === 0)) || (s.league === '20min' && s.h1_first === 1 && s.h1_last === 3 && s.h1c === 2 && s.sc_h === 1 && s.sc_a === 1 && arrayEqualsJS(s.h1s, ['A', 'H']) && !(s.home === 'Paraguay (V)' && s.away === 'Bosnia-Herzegovina (V)'))) && !(s.home === 'Cyprus (V)' && s.h1_first === 0 && s.h1_last === 3 && arrayEqualsJS(s.h1s, ['H', 'H']) && s.sc_h === 2 && s.sc_a === 0);
            case 'P54': return s.league === '20min' && s.sc_a > s.sc_h && s.h1_last === 9 && span >= 4 && s.h1_first >= 2 && s.h1c <= 4 && !(s.h1_first === 2 && arrayEqualsJS(s.h1s, ['A', 'A', 'H'])) && !(s.h1_first === 2 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['A', 'A']) && s.sc_h === 0 && s.sc_a === 2) && !(s.h1_first === 4 && s.h1_last === 9 && s.h1c === 2 && arrayEqualsJS(s.h1s, ['A', 'A']) && s.sc_h === 0 && s.sc_a === 2) && !(s.home === 'China (V)' && s.h1_first === 5 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['A', 'A']) && s.sc_h === 0 && s.sc_a === 2) && !(s.h1_first === 5 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['H', 'A', 'A']) && s.sc_h === 1 && s.sc_a === 2);
            case 'P55': return s.league === '16min' && s.h1_last === 8 && s.sc_a > s.sc_h && !(s.h1_first === 3 && s.sc_h === 1 && s.sc_a === 2 && arrayEqualsJS(s.h1s, ['H', 'A', 'A'])) && !(s.h1_first === 5 && s.h1_last === 8 && s.sc_h === 1 && s.sc_a === 2 && arrayEqualsJS(s.h1s, ['H', 'A', 'A']) && s.min_gap === 0) && !(s.h1c === 1 && s.h1_first === 8 && arrayEqualsJS(s.h1s, ['A']) && s.sc_h === 0 && s.sc_a === 1);
            case 'P56': return s.league === '16min' && s.max_gap >= 6 && lastScorer === 'A';
            case 'P57': return s.h1_first === 0 && s.h1_last === 6 && firstScorer === 'A' && (s.league === '15min' || s.league === '16min' || s.h1c >= 3) && (s.switches >= 2 || s.max_gap >= 4) && !arrayEqualsJS(s.h1s, ['A', 'H', 'H']) && !arrayEqualsJS(s.h1s, ['A', 'A', 'A']) && !(s.league === '15min' && arrayEqualsJS(s.h1s, ['A', 'A']) && s.sc_h === 0 && s.sc_a === 2);
            case 'P58': return ((s.h1_first >= 3 && span >= 5 && s.min_gap >= 3 && !(s.h1_first === 4 && span === 5 && arrayEqualsJS(s.h1s, ['H', 'H']) && s.switches === 0)) || (s.league === '20min' && s.h1_first === 2 && span >= 5 && s.min_gap >= 3 && (lastScorer === 'H' || s.h1c >= 3)) || (s.league === '16min' && s.h1_first === 1 && span >= 5 && s.min_gap >= 3 && s.max_gap >= 4) || (s.league === '16min' && s.h1_first === 0 && span >= 5 && s.min_gap >= 3 && diff >= 2 && !(s.sc_h === 2 && s.sc_a === 0 && s.h1_last === 5 && arrayEqualsJS(s.h1s, ['H', 'H']))) || (s.league === '15min' && s.h1c === 3 && s.h1_first === 2 && span === 5 && s.min_gap === 1) || (s.league === '15min' && s.h1_first === 0 && s.h1_last === 6 && span === 6 && s.min_gap === 2)) && !(s.home === 'Netherlands (V)' && s.league === '20min' && s.h1_first === 4 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['A', 'H']) && s.sc_h === 1 && s.sc_a === 1) && !(s.league === '20min' && s.h1_first === 2 && s.h1_last === 7 && s.sc_h === 2 && s.sc_a === 0 && arrayEqualsJS(s.h1s, ['H', 'H'])) && !(s.league === '20min' && s.h1_first === 2 && s.h1_last === 9 && s.sc_h === 2 && s.sc_a === 1 && arrayEqualsJS(s.h1s, ['H', 'H', 'A'])) && !(s.league === '20min' && s.h1_first === 4 && s.h1_last === 9 && s.h1c === 2 && s.sc_h === 0 && s.sc_a === 2 && arrayEqualsJS(s.h1s, ['A', 'A'])) && !(s.league === '16min' && s.h1c === 1 && s.sc_h === 0 && s.sc_a === 1 && [1, 8].indexOf(s.h1_first) !== -1) && !(s.league === '16min' && s.h1_first === 1 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['H', 'H']) && s.sc_h === 2 && s.sc_a === 0) && !(s.league === '15min' && s.h1_first === 2 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['H', 'A', 'A']) && s.sc_h === 1 && s.sc_a === 2) && !(s.league === '16min' && s.h1_first === 3 && s.h1_last === 8 && arrayEqualsJS(s.h1s, ['A', 'H']) && s.sc_h === 1 && s.sc_a === 1);
            case 'P59': return s.h1_last === 9 && s.switches >= 2 && lastScorer === 'A' && (firstScorer === 'A' || s.max_gap >= 6) && !(s.h1_first === 1 && s.h1c === 3 && arrayEqualsJS(s.h1s, ['A', 'H', 'A'])) && !(s.h1c === 5 && s.h1_first === 2) && !(s.league === '20min' && s.h1_first === 0 && s.h1_last === 9 && s.h1c === 4 && s.sc_h === 2 && s.sc_a === 2 && s.min_gap === 0 && arrayEqualsJS(s.h1s, ['H', 'A', 'H', 'A']));
            case 'P60': return s.league === '20min' && s.h1_first === 3 && s.sc_h === s.sc_a && s.h1_last >= 5 && !(s.home === 'Denmark (V)' && s.h1_last === 5 && arrayEqualsJS(s.h1s, ['H', 'A']) && s.sc_h === 1 && s.sc_a === 1) && !(s.h1_last === 7 && arrayEqualsJS(s.h1s, ['H', 'A'])) && !(s.h1_last === 6 && arrayEqualsJS(s.h1s, ['A', 'H']) && s.sc_h === 1 && s.sc_a === 1);
            case 'P61': return (s.league === '15min' && inTeamConfig('p61_teams', s.away) && s.h1_last >= 5 && diff <= 1 && !(s.home === 'Girondins de Bordeaux (V)' && s.away === 'Lille OSC (V)') && !(s.away === 'AS Monaco (V)' && s.h1_first === 3 && s.h1_last === 5 && s.h1c === 2 && arrayEqualsJS(s.h1s, ['H', 'A'])) && !(s.h1c === 1 && s.h1_last === 5) && !(s.h1c === 1 && s.h1_first === 6 && s.h1_last === 6 && arrayEqualsJS(s.h1s, ['A']) && s.fh === 0 && s.fa === 1) && !(s.h1_first === 4 && s.h1_last === 6 && arrayEqualsJS(s.h1s, ['A', 'H']) && s.sc_h === 1 && s.sc_a === 1) && !(s.h1_first === 3 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['A', 'H']) && s.sc_h === 1 && s.sc_a === 1) && !(s.h1_last === 7 && s.min_gap === 0 && arrayEqualsJS(s.h1s, ['A', 'H', 'A']) && s.sc_h === 1 && s.sc_a === 2) && !(s.h1_first === 0 && s.h1_last === 5 && arrayEqualsJS(s.h1s, ['H', 'A', 'A']) && s.sc_h === 1 && s.sc_a === 2) && !(s.h1_first === 5 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['H', 'A', 'A']) && s.sc_h === 1 && s.sc_a === 2) && !(s.h1_first === 1 && s.h1_last === 5 && arrayEqualsJS(s.h1s, ['H', 'H', 'H']) && s.sc_h === 3 && s.sc_a === 0) && !(s.h1_first === 1 && s.h1_last === 6 && arrayEqualsJS(s.h1s, ['A', 'H', 'H']) && s.sc_h === 2 && s.sc_a === 1) && !(s.away === 'Lille OSC (V)' && s.h1_first === 0 && s.h1_last === 6 && s.h1c === 3 && arrayEqualsJS(s.h1s, ['H', 'A', 'A']) && s.sc_h === 1 && s.sc_a === 2) && !(s.h1_first === 2 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['H', 'A', 'A']) && s.sc_h === 1 && s.sc_a === 2)) || (s.league === '15min' && s.h1_first <= 1 && s.h1_last >= 6 && diff <= 1 && firstScorer === 'H' && lastScorer === 'H');
            case 'P62': return (((s.league === '15min' && inTeamConfig('p62_teams', s.home) && s.h1_first <= 1 && s.h1_last >= 4 && (s.h1_first === 0 || s.home !== 'FC Koln (V)') && (s.switches >= 1 || s.h1c <= 3 || s.h1_last >= 7) && !(s.h1_first === 1 && s.h1_last === 4 && arrayEqualsJS(s.h1s, ['A', 'A', 'H'])) && !(s.home === 'Leicester City (V)' && s.h1_first === 0 && s.h1_last === 5 && arrayEqualsJS(s.h1s, ['H', 'H'])) && !(s.home === 'Leicester City (V)' && s.h1_first === 1 && s.h1_last === 5 && s.sc_h === 0 && s.sc_a === 2 && arrayEqualsJS(s.h1s, ['A', 'A'])) && !(s.h1_first === 0 && s.h1_last === 5 && arrayEqualsJS(s.h1s, ['A', 'A']) && s.sc_h === 0 && s.sc_a === 2)) || (s.league === '15min' && s.h1_first <= 1 && s.h1_last >= 6 && s.switches >= 2 && diff <= 2 && lastScorer === 'H' && !arrayEqualsJS(s.h1s, ['H', 'A', 'H', 'H']) && !arrayEqualsJS(s.h1s, ['H', 'H', 'A', 'H'])) || (s.league === '15min' && s.h1_first === 3 && s.h1_last === 7 && diff === 2 && lastScorer === 'H') || (s.league === '15min' && s.h1_first === 2 && s.h1_last === 4 && s.sc_h === 2 && s.sc_a === 0 && arrayEqualsJS(s.h1s, ['H', 'H'])) || (s.league === '16min' && s.h1_first <= 1 && s.h1_last >= 7 && diff <= 2 && lastScorer === 'H') || (s.league === '20min' && s.h1_first <= 1 && s.h1_last >= 3 && s.switches >= 2 && s.sc_h === s.sc_a && lastScorer === 'H') || (s.league === '20min' && s.h1_first === 1 && s.h1_last === 4 && s.sc_h === 2 && s.sc_a === 0 && arrayEqualsJS(s.h1s, ['H', 'H']))) && !(s.away === 'Getafe CF (V)' && arrayEqualsJS(s.h1s, ['A', 'H', 'A'])) && !(s.home === 'Lazio (V)' && s.league === '15min' && s.h1_first === 2 && s.h1_last === 4 && arrayEqualsJS(s.h1s, ['H', 'H']) && s.sc_h === 2 && s.sc_a === 0) && !(s.league === '15min' && s.h1c === 5 && s.h1_first === 0 && s.h1_last >= 7 && s.min_gap === 0 && s.sc_h === 3 && s.sc_a === 2) && !(s.league === '16min' && s.h1_first === 1 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['H', 'H']) && s.sc_h === 2 && s.sc_a === 0) && !(s.league === '16min' && s.h1_first === 0 && s.h1_last === 8 && arrayEqualsJS(s.h1s, ['A', 'H', 'H', 'A', 'H']) && s.sc_h === 3 && s.sc_a === 2) && !(s.league === '20min' && s.h1_first === 0 && s.h1_last === 5 && arrayEqualsJS(s.h1s, ['H', 'A', 'A', 'H']) && s.sc_h === 2 && s.sc_a === 2));
            case 'P63': return s.league === '16min' && inTeamConfig('p63_teams', s.home) && s.h1_first <= 1 && s.h1_last >= 6 && !(s.h1_first === 1 && s.h1_last === 6 && arrayEqualsJS(s.h1s, ['H', 'H', 'A']) && s.sc_h === 2 && s.sc_a === 1) && !(s.h1_first === 0 && s.h1_last === 8 && arrayEqualsJS(s.h1s, ['A', 'H', 'H', 'A', 'H']) && s.sc_h === 3 && s.sc_a === 2) && !(lastScorer === 'A' && (s.sc_h - s.sc_a) >= 2 && s.max_run >= 3 && s.max_gap <= 3);
            case 'P64': return ((s.league === '15min' && inTeamConfig('p64_teams', s.away) && s.h1_first <= 1 && s.h1_last >= 4 && (s.away !== 'Napoli (V)' || s.max_run <= 2) && !(s.away === 'Napoli (V)' && arrayEqualsJS(s.h1s, ['A', 'A', 'H'])) && !(s.away === 'Lille OSC (V)' && s.h1_first === 1 && s.h1_last === 6 && arrayEqualsJS(s.h1s, ['A', 'A', 'A'])) && !(s.away === 'Lille OSC (V)' && s.h1_first === 0 && s.h1_last === 6 && s.h1c === 3 && arrayEqualsJS(s.h1s, ['H', 'A', 'A']) && s.sc_h === 1 && s.sc_a === 2) && !(s.h1_first === 1 && s.h1_last === 4 && s.h1c === 2 && arrayEqualsJS(s.h1s, ['A', 'H'])) && !(s.h1_first === 1 && s.h1_last === 5 && s.h1c === 2 && arrayEqualsJS(s.h1s, ['A', 'H']) && s.sc_h === 1 && s.sc_a === 1) && !(s.h1_first === 1 && s.h1_last === 7 && s.h1c === 2 && arrayEqualsJS(s.h1s, ['H', 'A']) && s.sc_h === 1 && s.sc_a === 1) && !(s.away === 'FC Koln (V)' && s.h1c === 2 && s.sc_h === 2 && s.sc_a === 0 && s.h1_first === 0 && s.h1_last === 4 && arrayEqualsJS(s.h1s, ['H', 'H']))) || (s.league === '15min' && s.h1_first === 1 && s.h1_last >= 7 && firstScorer === 'A' && lastScorer === 'H' && !(s.h1c === 2 && s.h1_last === 7 && s.h1_first === 1))) && !(s.league === '15min' && s.h1_first === 1 && s.h1_last === 7 && s.h1c === 2 && arrayEqualsJS(s.h1s, ['H', 'A']) && s.sc_h === 1 && s.sc_a === 1);
            case 'P65': return s.league === '15min' && inTeamConfig('p65_teams', s.home) && s.h1c >= 1 && s.h1_first <= 1 && !(s.home === 'Leicester City (V)' && s.h1_first === 0 && s.h1_last === 5 && arrayEqualsJS(s.h1s, ['H', 'H'])) && !(s.home === 'Leicester City (V)' && s.h1_first === 1 && s.h1_last === 5 && s.sc_h === 0 && s.sc_a === 2 && arrayEqualsJS(s.h1s, ['A', 'A'])) && !(s.home === 'Napoli (V)' && s.h1c === 1 && s.h1_first === 1 && s.sc_h === 0 && s.sc_a === 1 && arrayEqualsJS(s.h1s, ['A'])) && !(s.home === 'Olympique Lyonnais (V)' && s.h1c === 1 && s.h1_first === 1 && s.sc_h === 1 && s.sc_a === 0 && arrayEqualsJS(s.h1s, ['H'])) && !(s.h1_first === 1 && s.h1_last === 3 && arrayEqualsJS(s.h1s, ['H', 'H']) && s.sc_h === 2 && s.sc_a === 0) && !(s.h1_first === 1 && s.h1_last === 3 && arrayEqualsJS(s.h1s, ['A', 'A']) && s.sc_h === 0 && s.sc_a === 2);
            case 'P67': return s.league === '20min' && inTeamConfig('p67_teams', s.home) && s.h1_first <= 1 && s.h1_last >= 5 && !(s.h1_first === 1 && s.h1_last === 7 && s.h1c === 2 && arrayEqualsJS(s.h1s, ['H', 'H'])) && !(s.h1_first === 1 && s.h1_last === 6 && s.h1c === 2 && arrayEqualsJS(s.h1s, ['H', 'H']) && s.sc_h === 2 && s.sc_a === 0) && !(s.h1_first === 1 && s.h1_last === 10 && arrayEqualsJS(s.h1s, ['A', 'A', 'H', 'A', 'A'])) && !(s.h1_first === 1 && s.h1_last === 8 && arrayEqualsJS(s.h1s, ['A', 'A', 'A']) && s.sc_h === 0 && s.sc_a === 3) && !(s.h1_first === 0 && s.h1_last === 8 && s.h1c === 3 && arrayEqualsJS(s.h1s, ['A', 'H', 'A'])) && !(s.h1_first === 0 && s.h1_last === 5 && s.h1c === 3 && arrayEqualsJS(s.h1s, ['A', 'A', 'H']) && s.sc_h === 1 && s.sc_a === 2) && !(s.h1_first === 0 && s.h1_last === 7 && s.h1c === 3 && arrayEqualsJS(s.h1s, ['A', 'H', 'A']) && s.sc_h === 1 && s.sc_a === 2) && !(s.h1_first === 0 && s.h1_last === 6 && s.h1c === 2 && arrayEqualsJS(s.h1s, ['A', 'A']) && s.sc_h === 0 && s.sc_a === 2) && !(s.h1_first === 1 && s.h1_last === 5 && s.h1c === 2 && arrayEqualsJS(s.h1s, ['A', 'A']) && s.sc_h === 0 && s.sc_a === 2) && !(s.h1_first === 1 && s.h1_last === 9 && s.h1c === 2 && arrayEqualsJS(s.h1s, ['A', 'A']) && s.sc_h === 0 && s.sc_a === 2) && !(s.h1_first === 0 && s.h1_last === 5 && arrayEqualsJS(s.h1s, ['H', 'A', 'A', 'H']) && s.sc_h === 2 && s.sc_a === 2);
            case 'P68': return s.league === '15min' && s.home === 'Leicester City (V)' && s.h1c >= 1 && s.h1_first <= 1 && !(s.h1_first === 0 && s.h1_last === 5 && arrayEqualsJS(s.h1s, ['H', 'H']) && s.sc_h === 2 && s.sc_a === 0) && !(s.h1_first === 1 && s.h1_last === 5 && arrayEqualsJS(s.h1s, ['A', 'A']) && s.sc_h === 0 && s.sc_a === 2);
            case 'P69': return s.league === '20min' && s.home === 'Denmark (V)' && s.h1_first <= 1 && s.h1_last >= 5 && !(s.h1_first === 0 && s.h1_last === 5 && arrayEqualsJS(s.h1s, ['A', 'A', 'H']) && s.sc_h === 1 && s.sc_a === 2) && !(s.h1_first === 1 && s.h1_last === 8 && arrayEqualsJS(s.h1s, ['A', 'A', 'A']) && s.sc_h === 0 && s.sc_a === 3);
            case 'P70': return s.league === '15min' && s.away === 'Liverpool (V)' && s.h1_first <= 1 && s.h1_last >= 5;
            case 'P71': return s.league === '20min' && s.away === 'Germany (V)' && s.sc_a > s.sc_h && s.h1_last >= 6 && !(s.h1_first === 4 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['A', 'A']) && s.sc_h === 0 && s.sc_a === 2) && !(s.h1_first >= 8 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['A', 'A']) && s.sc_h === 0 && s.sc_a === 2);
            case 'P66': return (((s.league === '15min' && inTeamConfig('p66_teams', s.away) && s.h1_first <= 1 && s.h1_last >= 5 && (s.away !== 'Napoli (V)' || s.max_run <= 2) && (s.away !== 'Chelsea (V)' || firstScorer === 'A') && !(s.h1_first === 0 && s.h1_last === 7 && s.h1c === 2 && s.sc_h === 0 && s.sc_a === 2 && arrayEqualsJS(s.h1s, ['A', 'A'])) && !(s.h1c === 2 && arrayEqualsJS(s.h1s, ['A', 'H']) && span >= 6) && !(s.away === 'Lille OSC (V)' && s.h1_first === 1 && s.h1_last === 6 && arrayEqualsJS(s.h1s, ['A', 'A', 'A']))) || (s.league === '15min' && s.h1_first === 1 && s.h1_last === 4 && s.h1c === 3 && diff === 1 && lastScorer === 'A') || (s.league === '16min' && s.h1_first === 0 && s.h1_last >= 6 && diff <= 2 && lastScorer === 'A') || (s.league === '20min' && s.h1_first === 0 && s.h1_last >= 7 && s.switches >= 2 && diff <= 2 && lastScorer === 'A')) && !(s.away === 'Getafe CF (V)' && arrayEqualsJS(s.h1s, ['A', 'H', 'A'])) && !(s.league === '15min' && s.h1c === 4 && s.h1_first === 1 && s.h1_last === 5 && (s.sc_a - s.sc_h) >= 2) && !(s.league === '15min' && s.h1c === 5 && s.h1_first === 1 && s.h1_last === 7 && (s.sc_a - s.sc_h) >= 3 && s.max_run >= 3) && !(s.league === '20min' && s.h1_first === 0 && s.h1_last === 7 && s.h1c === 4 && s.sc_h === 2 && s.sc_a === 2 && s.min_gap === 0 && arrayEqualsJS(s.h1s, ['A', 'H', 'H', 'A'])) && !(s.league === '20min' && s.h1_first === 0 && s.h1_last === 8 && s.h1c === 3 && arrayEqualsJS(s.h1s, ['A', 'H', 'A'])) && !(s.league === '20min' && s.h1_first === 0 && s.h1_last === 9 && s.h1c === 4 && s.sc_h === 2 && s.sc_a === 2 && s.min_gap === 0 && arrayEqualsJS(s.h1s, ['H', 'A', 'H', 'A'])) && !(s.league === '20min' && s.h1_first === 0 && s.h1_last === 7 && s.h1c === 3 && arrayEqualsJS(s.h1s, ['A', 'H', 'A']) && s.sc_h === 1 && s.sc_a === 2) && !(s.league === '15min' && s.h1_first === 1 && s.h1_last === 5 && s.h1c === 2 && arrayEqualsJS(s.h1s, ['A', 'H']) && s.sc_h === 1 && s.sc_a === 1) && !(s.league === '15min' && s.h1_first === 1 && s.h1_last === 7 && s.h1c === 2 && arrayEqualsJS(s.h1s, ['H', 'A']) && s.sc_h === 1 && s.sc_a === 1) && !(s.league === '15min' && s.away === 'Lille OSC (V)' && s.h1_first === 0 && s.h1_last === 6 && s.h1c === 3 && arrayEqualsJS(s.h1s, ['H', 'A', 'A']) && s.sc_h === 1 && s.sc_a === 2) && !(s.league === '20min' && s.h1_first === 0 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['H', 'A', 'A', 'H', 'A']) && s.sc_h === 2 && s.sc_a === 3));
            default: return false;
        }
    }

    function matchesLatePatternLive(pid, s) {
        if (!s) return false;
        var span = s.h1c >= 2 ? (s.h1_last - s.h1_first) : 0;
        var firstScorer = s.h1s.length ? s.h1s[0] : null;

        switch (pid) {
            case 'LG1':
                return s.h1_last === 9 && s.h1_first <= 1 && (s.sc_a - s.sc_h) >= 2 && s.h1c >= 3;
            case 'LG2':
                return s.h1_last === 9 && span >= 7 && (s.sc_a - s.sc_h) >= 2 && s.h1_first <= 1 && s.h1c >= 3;
            case 'LG3':
                return s.league === '16min' && s.h1c >= 3 && firstScorer === 'A' && s.h1_last === 6 && s.max_gap !== 3;
            case 'LG4':
                return s.league === '20min' && inTeamConfig('lg4_teams', s.away) && s.sc_a > s.sc_h && s.h1_last === 9 && !(s.h1c === 3 && s.h1_first === 1) && !(s.h1c === 1 && s.h1_first === 9);
            case 'LG5':
                return inTeamConfig('lg5_teams', s.home) && (s.sc_a - s.sc_h) === 1 && s.h1_last >= 6 && s.h1_first >= 2 && !(s.h1_first === 4 && s.h1_last === 7 && arrayEqualsJS(s.h1s, ['A', 'A', 'H', 'H', 'A']) && s.sc_h === 2 && s.sc_a === 3);
            case 'LG6':
                return s.league === '20min' && ((inTeamConfig('lg6_teams', s.away) && s.away !== 'Argentina (V)' && s.h1_first <= 1 && s.h1_last >= 8 && (s.h1_first === 0 || s.sc_a > s.sc_h) && !(s.h1c === 2 && arrayEqualsJS(s.h1s, ['A', 'A'])) && !(s.h1c === 3 && s.h1_first === 0 && s.h1_last === 8 && arrayEqualsJS(s.h1s, ['A', 'H', 'A'])) && !(s.h1_first === 0 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['A', 'A', 'A', 'A']) && s.sc_h === 0 && s.sc_a === 4)) || (s.away === 'Argentina (V)' && s.h1_first === 0 && s.h1_last >= 7 && s.h1c >= 3 && Math.abs(s.sc_h - s.sc_a) <= 1));
            case 'LG7':
                return inTeamConfig('lg7_teams', s.away) && s.sc_a > s.sc_h && s.h1_last >= 6 && s.h1c <= 3 && (s.h1c >= 2 || s.h1_last >= 7) && !(s.h1c === 2 && s.h1_first === 0) && !(s.h1c === 1 && s.h1_first === 8 && s.h1_last === 8 && arrayEqualsJS(s.h1s, ['A']) && s.sc_h === 0 && s.sc_a === 1) && !(s.h1_first === 2 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['A', 'A']) && s.sc_h === 0 && s.sc_a === 2);
            case 'LG8':
                return s.league === '20min' && inTeamConfig('lg8_teams', s.away) && s.sc_a > s.sc_h && s.h1_last >= 6 && s.h1c >= 2 && !(s.h1c === 2 && s.h1_first === 0) && !(s.h1_first === 1 && s.h1_last === 10 && arrayEqualsJS(s.h1s, ['A', 'A', 'H', 'A', 'A'])) && !(s.h1_first === 0 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['A', 'A', 'A', 'A']) && s.sc_h === 0 && s.sc_a === 4) && !(s.h1_first === 4 && s.h1_last === 6 && arrayEqualsJS(s.h1s, ['A', 'A']) && s.sc_h === 0 && s.sc_a === 2) && !(s.switches === 0 && s.sc_h === 0 && s.sc_a >= 2 && s.h1_last <= 8 && s.max_gap <= 2);
            default:
                return false;
        }
    }

    function matchesNextPatternLive(pid, s) {
        if (!s) return false;
        var span = s.h1c >= 2 ? (s.h1_last - s.h1_first) : 0;

        switch (pid) {
            case 'NG6':
                return s.league === '20min' && s.sc_h === 1 && s.sc_a === 1 && arrayEqualsJS(s.h1s, ['A', 'H']) && ((s.h1_last === 7 && span >= 5 && s.h1_first !== 1) || (s.h1_first === 0 && s.h1_last >= 7)) && !(s.home === 'Colombia (V)' && s.away === 'Greece (V)');
            case 'NG7':
                return s.h1c >= 3 && s.max_gap >= 5 && Math.abs(s.sc_h - s.sc_a) === 2 && s.h1_last >= 8 && s.h1_last <= 9 && !(s.league === '20min' && s.h1_first === 1 && s.h1_last === 9 && s.h1c === 4 && arrayEqualsJS(s.h1s, ['H', 'A', 'H', 'H']) && s.sc_h === 3 && s.sc_a === 1);
            case 'NG8':
                return s.h1_first === 3 && span >= 6 && s.min_gap >= 3 && !(s.league === '20min' && s.h1_last === 10 && arrayEqualsJS(s.h1s, ['H', 'A']) && s.sc_h === 1 && s.sc_a === 1);
            case 'NG9':
                return s.league === '20min' && s.sc_a > s.sc_h && s.h1_last === 9 && Math.abs(s.sc_h - s.sc_a) <= 1 && s.switches >= 2 && !(s.home === 'Spain (V)' && s.away === 'Uruguay (V)') && !(s.h1_first === 2 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['A', 'A', 'H', 'A', 'H']) && s.sc_h === 2 && s.sc_a === 3) && !(s.h1_first === 0 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['H', 'A', 'A', 'H', 'A']) && s.sc_h === 2 && s.sc_a === 3);
            case 'NG10':
                return s.league === '20min' && s.h1_first === 4 && s.h1_last === 9 && arrayEqualsJS(s.h1s, ['A', 'H']);
            case 'NG11':
                return matchesNextShapeStatsPatternFromData('NG11', s);
            default:
                return false;
        }
    }

    function isNextPatternSignalWindow(match, livePayload) {
        var statusText = getMatchStatusText(match);
        var parsedStatus = parseStatus(statusText);
        var isHalftime = /^H\.?Time$/i.test(statusText);
        if (isHalftime) return true;

        if (hasAnySecondHalfGoal(match, livePayload)) return false;

        if (parsedStatus.half === '1H') return true;
        if (parsedStatus.half === '2H' && parsedStatus.min >= 0) return true;
        return false;
    }

    function isSummaryPatternSignalWindow(match, livePayload) {
        var statusText = getMatchStatusText(match);
        var parsedStatus = parseStatus(statusText);
        var isHalftime = /^H\.?Time$/i.test(statusText);
        if (isHalftime) return true;

        var hasSecondHalfGoal = hasAnySecondHalfGoal(match, livePayload);

        if (parsedStatus.half === '1H') return true;
        if (parsedStatus.half === '2H' && parsedStatus.min >= 0 && !hasSecondHalfGoal) return true;
        return false;
    }

    function hasAnySecondHalfGoal(match, livePayload, liveState) {
        var key = matchKey(match);
        if (getLiveSecondHalfGoalMinutes(livePayload, key, match).length > 0) {
            return true;
        }

        var score = getMatchScores(match);
        if (liveState && (score.home !== liveState.sc_h || score.away !== liveState.sc_a)) {
            return true;
        }

        var payloadHt = getLiveHtScore(livePayload, key, match);
        if (payloadHt) {
            return score.home !== payloadHt.home || score.away !== payloadHt.away;
        }

        var ht = htMemory[key];
        if (!ht) return false;

        return score.home !== ht.h || score.away !== ht.a;
    }

    function getLatePatternTarget(pattern) {
        return pattern && pattern.target ? pattern.target : 'has_late';
    }

    function hasLateTargetGoal(pattern, match, livePayload, liveState) {
        var key = matchKey(match);
        var target = getLatePatternTarget(pattern);
        var threshold = target === 'has_after_2h4' ? 4 : 6;
        var goalMins = getLiveSecondHalfGoalMinutes(livePayload, key, match);
        if (goalMins.some(function(min) { return min > threshold; })) {
            return true;
        }
        if (goalMins.length) return false;

        var ht = htMemory[key];
        if (!ht) return false;

        var status = parseStatus(getMatchStatusText(match));
        var score = getMatchScores(match);
        var hasScoreChanged = score.home !== ht.h || score.away !== ht.a || (liveState && (score.home !== liveState.sc_h || score.away !== liveState.sc_a));

        return hasScoreChanged && status.half === '2H' && status.min > threshold;
    }

    function isLatePatternSignalWindow(pattern, match, livePayload, liveState) {
        var statusText = getMatchStatusText(match);
        var parsedStatus = parseStatus(statusText);
        var isHalftime = /^H\.?Time$/i.test(statusText);
        if (isHalftime) return true;

        if (hasLateTargetGoal(pattern, match, livePayload, liveState)) return false;

        if (parsedStatus.half === '1H') return true;
        if (parsedStatus.half === '2H' && parsedStatus.min >= 0) return true;
        return false;
    }

    function matchKey(m) {
        return getMatchHomeTeam(m) + '|' + getMatchAwayTeam(m) + '|' + getMatchLeague(m);
    }

    function extensionMatchKey(m) {
        return JSON.stringify({
            league: getMatchLeague(m) || 'N/A',
            teams: getMatchHomeTeam(m) + ' vs ' + getMatchAwayTeam(m)
        });
    }

    function getPayloadKeyCandidates(match, key) {
        var keys = [];
        if (key) keys.push(key);
        if (match) {
            var extKey = extensionMatchKey(match);
            if (keys.indexOf(extKey) === -1) keys.push(extKey);
            var simpleKey = matchKey(match);
            if (keys.indexOf(simpleKey) === -1) keys.push(simpleKey);
        }
        return keys;
    }

    function updateHtMemory(matches) {
        for (var i = 0; i < matches.length; i++) {
            var m = matches[i];
            var key = matchKey(m);
            var s = parseStatus(getMatchStatusText(m));
            var score = getMatchScores(m);
            var h = score.home;
            var a = score.away;
            var prev = prevStateMemory[key];
            if (s.half === '2H' && prev && prev.half === '1H') {
                htMemory[key] = { h: prev.h, a: prev.a };
            }
            if (s.half === '1H' && prev && prev.half === '2H') {
                delete htMemory[key];
                clearLiveGoalMemory(key);
            }
            prevStateMemory[key] = { half: s.half, h: h, a: a, min: s.min };
        }
    }

    function getLiveGoalMinutes(livePayload, key, match) {
        var allGoalMinutes = livePayload && livePayload.allGoalMinutes ? livePayload.allGoalMinutes : {};
        var candidates = getPayloadKeyCandidates(match, key);
        for (var i = 0; i < candidates.length; i++) {
            var value = allGoalMinutes[candidates[i]];
            if (Array.isArray(value)) {
                return value.map(function(min) { return parseInt(min, 10); }).filter(function(min) { return !Number.isNaN(min); });
            }
        }
        return [];
    }

    function getLiveGoalScorers(livePayload, key, match) {
        var allGoalScorers = livePayload && livePayload.allGoalScorers ? livePayload.allGoalScorers : {};
        var candidates = getPayloadKeyCandidates(match, key);
        for (var i = 0; i < candidates.length; i++) {
            var value = allGoalScorers[candidates[i]];
            if (Array.isArray(value)) return value;
        }
        return [];
    }

    function getLiveSecondHalfGoalMinutes(livePayload, key, match) {
        var all2HGoalMinutes = livePayload && livePayload.all2HGoalMinutes ? livePayload.all2HGoalMinutes : {};
        var candidates = getPayloadKeyCandidates(match, key);
        for (var i = 0; i < candidates.length; i++) {
            var value = all2HGoalMinutes[candidates[i]];
            if (Array.isArray(value)) {
                return value.map(function(min) { return parseInt(min, 10); }).filter(function(min) { return !Number.isNaN(min); });
            }
        }
        return [];
    }

    function getLiveHtScore(livePayload, key, match) {
        var htScores = livePayload && livePayload.htScores ? livePayload.htScores : {};
        var candidates = getPayloadKeyCandidates(match, key);
        for (var i = 0; i < candidates.length; i++) {
            var value = htScores[candidates[i]];
            if (typeof value === 'string' && value.trim()) {
                var parts = value.match(/(\d+)\s*-\s*(\d+)/);
                if (parts) {
                    return { home: parseInt(parts[1], 10), away: parseInt(parts[2], 10) };
                }
            }
        }
        return null;
    }

    function getLivePatternSignals(livePayload, key, match) {
        var patternSignals = livePayload && livePayload.patternSignals ? livePayload.patternSignals : {};
        var candidates = getPayloadKeyCandidates(match, key);
        for (var i = 0; i < candidates.length; i++) {
            var value = patternSignals[candidates[i]];
            if (!value) continue;
            if (Array.isArray(value)) return value;
            return [value];
        }
        return [];
    }

    function getMaxGap(goalMins) {
        var maxGap = 0;
        for (var i = 1; i < goalMins.length; i++) {
            maxGap = Math.max(maxGap, goalMins[i] - goalMins[i - 1]);
        }
        return maxGap;
    }

    function isLastScorerHome(goalMins, scorers, htH, htA) {
        if (scorers.length === goalMins.length && scorers.length > 0) {
            return scorers[scorers.length - 1] === 'H';
        }

        if (htH > 0 && htA === 0) return true;
        if (goalMins.length === 1 && htH > htA) return true;

        return false;
    }

    function isHistoricalP19LiveCandidate(match, livePayload) {
        var statusText = getMatchStatusText(match);
        var parsedStatus = parseStatus(statusText);
        var isHalftime = /^H\.?Time$/i.test(statusText);
        if (!isHalftime && !(parsedStatus.half === '2H' && parsedStatus.min <= 2)) {
            return false;
        }

        if (getLeagueTypeJS(getMatchLeague(match)) !== '20min') return false;

        var key = matchKey(match);
        var score = getMatchScores(match);
        var ht = htMemory[key];
        var htH = ht ? ht.h : score.home;
        var htA = ht ? ht.a : score.away;
        if (htH <= htA) return false;

        if (parsedStatus.half === '2H' && (score.home !== htH || score.away !== htA)) {
            return false;
        }

        var goalMins = getLiveGoalMinutes(livePayload, key, match);
        if (!goalMins.length) return false;

        var lastGoalMin = goalMins[goalMins.length - 1];
        if (lastGoalMin !== 3 && lastGoalMin !== 4) return false;

        if (goalMins.length < 2 || getMaxGap(goalMins) < 2) return false;

        return isLastScorerHome(goalMins, getLiveGoalScorers(livePayload, key, match), htH, htA);
    }

    function hasMinHits(id) {
        var stats = PATTERN_STATS[id];
        return stats && stats.hits >= 10;
    }

    function getLatePatternById(id) {
        var patterns = INITIAL_DATA.latePatterns || [];
        for (var i = 0; i < patterns.length; i++) {
            if (patterns[i] && patterns[i].id === id) return patterns[i];
        }
        return null;
    }

    function mergeRecentLateCandidateDetails(details, currentMatchesByKey, livePayload) {
        var now = Date.now();
        Object.keys(lateLiveCandidateDetails || {}).forEach(function(pid) {
            var pattern = getLatePatternById(pid);
            if (!pattern) return;
            (lateLiveCandidateDetails[pid] || []).forEach(function(entry) {
                var key = entry && (entry.key || (entry.match ? matchKey(entry.match) : ''));
                if (!key) return;
                if (details[pid] && details[pid][key]) return;

                var lastSeen = Number(entry.lastSeen || entry.seenAt || 0);
                if (!Number.isFinite(lastSeen) || now - lastSeen > LIVE_SUMMARY_PRE_GOAL_CARRY_MS) return;

                var currentMatch = currentMatchesByKey[key];
                if (currentMatch && hasLateTargetGoal(pattern, currentMatch, livePayload, entry.state || null)) return;
                if (!currentMatch || !isLatePatternSignalWindow(pattern, currentMatch, livePayload, entry.state || null)) return;
                if (entry.state && !matchesLatePatternLive(pid, entry.state)) return;

                if (!details[pid]) details[pid] = {};
                details[pid][key] = Object.assign({}, entry, {
                    key: key,
                    kind: 'late',
                    lastSeen: lastSeen,
                    stale: true
                });
            });
        });
    }

    function mergeRecentSummaryCandidateDetails(details, currentMatchesByKey, livePayload) {
        var now = Date.now();
        Object.keys(liveCandidateDetails || {}).forEach(function(pid) {
            (liveCandidateDetails[pid] || []).forEach(function(entry) {
                var key = entry && (entry.key || (entry.match ? matchKey(entry.match) : ''));
                if (!key) return;
                if (details[pid] && details[pid][key]) return;

                var lastSeen = Number(entry.lastSeen || entry.seenAt || 0);
                if (!Number.isFinite(lastSeen) || now - lastSeen > LIVE_SUMMARY_PRE_GOAL_CARRY_MS) return;

                var currentMatch = currentMatchesByKey[key];
                if (!currentMatch) return;
                if (!isSummaryPatternSignalWindow(currentMatch, livePayload)) return;
                if (hasAnySecondHalfGoal(currentMatch, livePayload, entry.state || null)) return;
                if (entry.state && !matchesSummaryPatternLive(pid, entry.state)) return;

                if (!details[pid]) details[pid] = {};
                details[pid][key] = Object.assign({}, entry, {
                    match: currentMatch,
                    key: key,
                    kind: 'summary',
                    status: getMatchStatusText(currentMatch),
                    score: getMatchScores(currentMatch),
                    provisional: isProvisionalSummaryCandidate(pid, getMatchStatusText(currentMatch)),
                    lastSeen: lastSeen,
                    stale: true
                });
            });
        });
    }

    function buildLiveSignals(match, livePayload, lg, htH, htA, h, a, curMin, phase) {
        var liveState = buildLivePatternState(match, livePayload);
        var signals = [];
        var seen = {};
        var key = matchKey(match);
        var extensionSignals = getLivePatternSignals(livePayload, key, match);
        var extensionState = extensionSignals.length && extensionSignals[0] && extensionSignals[0].state
            ? extensionSignals[0].state
            : null;

        if ((liveState || extensionState) && hasAnySecondHalfGoal(match, livePayload, liveState || extensionState)) {
            return signals;
        }

        if (liveState && isSummaryPatternSignalWindow(match, livePayload)) {
            PATTERN_DEFS.forEach(function(pattern) {
                if (!pattern || !pattern.id) return;
                if (!hasMinHits(pattern.id)) return;
                if (matchesSummaryPatternLive(pattern.id, liveState)) {
                    seen[pattern.id] = true;
                    signals.push({ id: pattern.id, label: pattern.label || pattern.id });
                }
            });
        }

        if (isSummaryPatternSignalWindow(match, livePayload)) {
            extensionSignals.forEach(function(signal) {
                if (!signal || !Array.isArray(signal.ids)) return;
                signal.ids.forEach(function(pid) {
                    if (seen[pid]) return;
                    if (!hasMinHits(pid)) return;
                    if (!supportsProvisionalSummaryCandidate(pid)) return;
                    var signalState = signal.state || liveState;
                    if (signalState && !matchesSummaryPatternLive(pid, signalState)) return;
                    seen[pid] = true;
                    signals.push({ id: pid, label: getPatternLabelById(pid) || pid });
                });
            });
        }

        if (isSummaryPatternSignalWindow(match, livePayload)) {
            var now = Date.now();
            Object.keys(liveCandidateDetails || {}).forEach(function(pid) {
                if (seen[pid]) return;
                var entries = liveCandidateDetails[pid] || [];
                for (var i = 0; i < entries.length; i++) {
                    var entry = entries[i];
                    var entryKey = entry && (entry.key || (entry.match ? matchKey(entry.match) : ''));
                    if (entryKey !== key) continue;
                    var lastSeen = Number(entry.lastSeen || entry.seenAt || 0);
                    if (!Number.isFinite(lastSeen) || now - lastSeen > LIVE_SUMMARY_PRE_GOAL_CARRY_MS) continue;
                    if (hasAnySecondHalfGoal(match, livePayload, entry.state || null)) continue;
                    if (entry.state && !matchesSummaryPatternLive(pid, entry.state)) continue;
                    seen[pid] = true;
                    signals.push({ id: pid, label: getPatternLabelById(pid) || pid });
                    break;
                }
            });
        }

        if (liveState && isNextPatternSignalWindow(match, livePayload)) {
            (INITIAL_DATA.nextPatterns || []).forEach(function(pattern) {
                if (!pattern || !pattern.id || seen[pattern.id]) return;
                if (!hasMinHits(pattern.id)) return;
                if (matchesNextPatternLive(pattern.id, liveState)) {
                    seen[pattern.id] = true;
                    signals.push({ id: pattern.id, label: (pattern.label || pattern.id) + ' => ' + (pattern.next || '?') });
                }
            });
        }

        if (liveState) {
            (INITIAL_DATA.latePatterns || []).forEach(function(pattern) {
                if (!pattern || !pattern.id || seen[pattern.id]) return;
                if (!hasMinHits(pattern.id)) return;
                if (!isLatePatternSignalWindow(pattern, match, livePayload, liveState)) return;
                if (matchesLatePatternLive(pattern.id, liveState)) {
                    seen[pattern.id] = true;
                    signals.push({ id: pattern.id, label: pattern.label || pattern.id });
                }
            });
        }

        if (isHistoricalP19LiveCandidate(match, livePayload)) {
            if (!seen.P19 && hasMinHits('P19')) {
                signals.push({ id: 'P19', label: getPatternLabelById('P19') });
            }
        }

        return signals;
    }

    function applyLiveCandidateIndicators() {
        document.querySelectorAll('#summary-body tr').forEach(function(row) {
            var pid = row.getAttribute('data-pid') || (row.cells[0] ? row.cells[0].textContent.trim() : '');
            var deltaCell = row.querySelector('.delta-cell') || row.cells[5];
            if (!pid || !deltaCell) return;

            var recordCell = row.cells[2];
            if (recordCell && !recordCell.dataset.baseHtml) {
                recordCell.dataset.baseHtml = recordCell.innerHTML;
            }
            var pctCell = row.cells[3];
            if (pctCell && !pctCell.dataset.baseHtml) {
                pctCell.dataset.baseHtml = pctCell.innerHTML;
            }

            if (!deltaCell.dataset.baseHtml) {
                deltaCell.dataset.baseHtml = deltaCell.innerHTML;
            }

            var baseHtml = deltaCell.dataset.baseHtml;
            var liveCount = liveCandidateCounts[pid] || 0;
            var settledStats = getSettledSummaryStats(pid);
            var settledHtml = '';
            var settledInlineHtml = '';
            var settledPctHtml = '';
            if (settledStats.win || settledStats.lose) {
                var parts = [];
                if (settledStats.win) parts.push('W ' + settledStats.win);
                if (settledStats.lose) parts.push('L ' + settledStats.lose);
                var baseTotal = parseInt(row.getAttribute('data-total'), 10) || 0;
                var baseHits = parseInt(row.getAttribute('data-hits'), 10) || 0;
                var adjustedTotal = baseTotal + settledStats.win + settledStats.lose;
                var adjustedHits = baseHits + settledStats.win;
                var adjustedPct = adjustedTotal > 0 ? Math.round(adjustedHits / adjustedTotal * 100) : 0;
                settledHtml = '<br><span style="color:#8b949e;font-weight:600;">settled ' + parts.join(' / ') + '</span>';
                settledInlineHtml = '<br><span class="record-sub">settled ' + parts.join(' / ') + ' => live adj ' + adjustedHits + '/' + adjustedTotal + '</span>';
                settledPctHtml = '<br><span class="record-sub">live adj ' + adjustedPct + '%</span>';
            }
            if (recordCell) {
                recordCell.innerHTML = recordCell.dataset.baseHtml + settledInlineHtml;
            }
            if (pctCell) {
                pctCell.innerHTML = pctCell.dataset.baseHtml + settledPctHtml;
            }
            if (liveCount > 0) {
                deltaCell.innerHTML = baseHtml + '<br><span style="color:#58a6ff;font-weight:600;">live ' + liveCount + ' candidate' + (liveCount > 1 ? 's' : '') + (restoredLiveCandidateState ? ' (cached)' : '') + '</span>' + settledHtml;
            } else {
                deltaCell.innerHTML = baseHtml + settledHtml;
            }
        });
    }

    function applyNextLiveCandidateIndicators() {
        document.querySelectorAll('#next-body tr').forEach(function(row) {
            var pid = row.getAttribute('data-pid') || (row.cells[0] ? row.cells[0].textContent.trim() : '');
            var deltaCell = row.querySelector('.delta-cell') || row.cells[6];
            if (!pid || !deltaCell) return;

            if (!deltaCell.dataset.baseHtml) {
                deltaCell.dataset.baseHtml = deltaCell.innerHTML;
            }

            var baseHtml = deltaCell.dataset.baseHtml;
            var liveCount = nextLiveCandidateCounts[pid] || 0;
            if (liveCount > 0) {
                deltaCell.innerHTML = baseHtml + '<br><span style="color:#58a6ff;font-weight:600;">live ' + liveCount + ' candidate' + (liveCount > 1 ? 's' : '') + (restoredLiveCandidateState ? ' (cached)' : '') + '</span>';
            } else {
                deltaCell.innerHTML = baseHtml;
            }
        });
    }

    function applyLateLiveCandidateIndicators() {
        document.querySelectorAll('#late-body tr').forEach(function(row) {
            var pid = row.getAttribute('data-pid') || (row.cells[0] ? row.cells[0].textContent.trim() : '');
            var deltaCell = row.querySelector('.delta-cell') || row.cells[5];
            if (!pid || !deltaCell) return;

            var recordCell = row.cells[2];
            if (recordCell && !recordCell.dataset.baseHtml) {
                recordCell.dataset.baseHtml = recordCell.innerHTML;
            }

            if (!deltaCell.dataset.baseHtml) {
                deltaCell.dataset.baseHtml = deltaCell.innerHTML;
            }

            var baseHtml = deltaCell.dataset.baseHtml;
            var liveCount = lateLiveCandidateCounts[pid] || 0;
            var settledStats = getSettledLateStats(pid);
            var settledHtml = '';
            if (settledStats.win || settledStats.lose) {
                var parts = [];
                if (settledStats.win) parts.push('W ' + settledStats.win);
                if (settledStats.lose) parts.push('L ' + settledStats.lose);
                settledHtml = '<br><span style="color:#8b949e;font-weight:600;">settled ' + parts.join(' / ') + '</span>';
            }
            if (recordCell) {
                recordCell.innerHTML = recordCell.dataset.baseHtml + (settledStats.win ? ' <span class="record-sub">+ live W ' + settledStats.win + '</span>' : '');
            }
            if (liveCount > 0) {
                deltaCell.innerHTML = baseHtml + '<br><span style="color:#58a6ff;font-weight:600;">live ' + liveCount + ' candidate' + (liveCount > 1 ? 's' : '') + (restoredLiveCandidateState ? ' (cached)' : '') + '</span>' + settledHtml;
            } else {
                deltaCell.innerHTML = baseHtml + settledHtml;
            }
        });
    }

    function collectLiveCandidateCounts(matches, livePayload) {
        var counts = {};
        var details = {};
        var currentMatchesByKey = {};
        (matches || []).forEach(function(match) {
            var statusText = getMatchStatusText(match);
            var s = parseStatus(statusText);
            var isHalftime = /^H\.?Time$/i.test(statusText);
            var key = matchKey(match);
            currentMatchesByKey[key] = match;
            var state = buildLivePatternState(match, livePayload);
            var extensionSignals = getLivePatternSignals(livePayload, key, match);
            if (!state && !extensionSignals.length) return;
            var signalState = extensionSignals.length && extensionSignals[0] && extensionSignals[0].state
                ? extensionSignals[0].state
                : null;
            var hasSecondHalfGoal = hasAnySecondHalfGoal(match, livePayload, state || signalState);
            var isFirstHalf = s.half === '1H';
            var isPreSecondHalfGoalWindow = s.half === '2H' && !hasSecondHalfGoal;

            extensionSignals.forEach(function(signal) {
                if (!signal || !Array.isArray(signal.ids)) return;
                signal.ids.forEach(function(pid) {
                    if (!supportsProvisionalSummaryCandidate(pid)) return;
                    var candidateState = signal.state || state;
                    if (!candidateState || !matchesSummaryPatternLive(pid, candidateState)) return;

                    if (hasSecondHalfGoal) {
                        if (!hasSettledSummaryResult(pid, key, candidateState)) {
                            var settledFromSignal = buildSettledSummaryResult(pid, {
                                match: match,
                                state: candidateState,
                                kind: 'summary',
                                key: key,
                                status: signal.status || statusText,
                                score: getMatchScores(match),
                                lastSeen: signal.seenAt || Date.now()
                            }, match, livePayload);
                            if (settledFromSignal) upsertSettledSummaryResult(settledFromSignal);
                        }
                        return;
                    }

                    if (!isFirstHalf && !isHalftime && !isPreSecondHalfGoalWindow) return;

                    counts[pid] = (counts[pid] || 0) + 1;
                    if (!details[pid]) details[pid] = {};
                    details[pid][key] = {
                        match: match,
                        state: candidateState,
                        kind: 'summary',
                        key: key,
                        status: signal.status || getMatchStatusText(match),
                        score: getMatchScores(match),
                        provisional: false,
                        lastSeen: signal.seenAt || Date.now(),
                        source: 'extension'
                    };
                });
            });

            if (!state) return;

            PATTERN_DEFS.forEach(function(pattern) {
                if (matchesSummaryPatternLive(pattern.id, state)) {
                    if (hasSecondHalfGoal) {
                        if (requiresPriorCandidateForSettled(pattern.id) && !hasPriorSummaryCandidate(pattern.id, key, state, requiresConfirmedPriorCandidateForSettled(pattern.id))) {
                            return;
                        }
                        if (!hasSettledSummaryResult(pattern.id, key, state)) {
                            var settled = buildSettledSummaryResult(pattern.id, {
                                match: match,
                                state: state,
                                kind: 'summary',
                                key: key,
                                status: statusText,
                                score: getMatchScores(match)
                            }, match, livePayload);
                            if (settled) upsertSettledSummaryResult(settled);
                        }
                        return;
                    }

                    if (!isFirstHalf && !isHalftime && !isPreSecondHalfGoalWindow) return;

                    counts[pattern.id] = (counts[pattern.id] || 0) + 1;
                    if (!details[pattern.id]) details[pattern.id] = {};
                    details[pattern.id][key] = {
                        match: match,
                        state: state,
                        kind: 'summary',
                        key: key,
                        status: getMatchStatusText(match),
                        score: getMatchScores(match),
                        provisional: isProvisionalSummaryCandidate(pattern.id, statusText),
                        lastSeen: Date.now()
                    };
                }
            });
        });

        mergeRecentSummaryCandidateDetails(details, currentMatchesByKey, livePayload);

        counts = {};
        Object.keys(details).forEach(function(pid) {
            counts[pid] = Object.keys(details[pid]).length;
        });

        Object.keys(details).forEach(function(pid) {
            details[pid] = Object.values(details[pid]);
        });

        settleRemovedSummaryCandidates(liveCandidateDetails, details, matches, livePayload);

        liveCandidateCounts = counts;
        restoredLiveCandidateState = false;
        if (liveCandidateExpiryTimer) {
            clearTimeout(liveCandidateExpiryTimer);
            liveCandidateExpiryTimer = null;
        }
        liveCandidateDetails = details;
        saveLiveCandidateState();
        applyLiveCandidateIndicators();
    }

    function collectNextLiveCandidateCounts(matches, livePayload) {
        var counts = {};
        var details = {};
        (matches || []).forEach(function(match) {
            if (!isNextPatternSignalWindow(match, livePayload)) return;

            var state = buildLivePatternState(match, livePayload);
            if (!state) return;
            var key = matchKey(match);

            (INITIAL_DATA.nextPatterns || []).forEach(function(pattern) {
                if (!pattern || !pattern.id) return;
                if (matchesNextPatternLive(pattern.id, state)) {
                    counts[pattern.id] = (counts[pattern.id] || 0) + 1;
                    if (!details[pattern.id]) details[pattern.id] = {};
                    details[pattern.id][key] = { match: match, state: state, kind: 'next' };
                }
            });
        });

        nextLiveCandidateCounts = counts;
        restoredLiveCandidateState = false;
        if (liveCandidateExpiryTimer) {
            clearTimeout(liveCandidateExpiryTimer);
            liveCandidateExpiryTimer = null;
        }
        Object.keys(details).forEach(function(pid) {
            details[pid] = Object.values(details[pid]);
        });
        nextLiveCandidateDetails = details;
        saveLiveCandidateState();
        applyNextLiveCandidateIndicators();
    }

    function collectLateLiveCandidateCounts(matches, livePayload) {
        var counts = {};
        var details = {};
        var currentMatchesByKey = {};
        (matches || []).forEach(function(match) {
            currentMatchesByKey[matchKey(match)] = match;
            var state = buildLivePatternState(match, livePayload);
            if (!state) return;
            var key = matchKey(match);

            (INITIAL_DATA.latePatterns || []).forEach(function(pattern) {
                if (!pattern || !pattern.id) return;
                if (!isLatePatternSignalWindow(pattern, match, livePayload, state)) return;
                if (matchesLatePatternLive(pattern.id, state)) {
                    counts[pattern.id] = (counts[pattern.id] || 0) + 1;
                    if (!details[pattern.id]) details[pattern.id] = {};
                    details[pattern.id][key] = { match: match, state: state, kind: 'late', key: key, lastSeen: Date.now() };
                }
            });
        });

        restoredLiveCandidateState = false;
        if (liveCandidateExpiryTimer) {
            clearTimeout(liveCandidateExpiryTimer);
            liveCandidateExpiryTimer = null;
        }
        mergeRecentLateCandidateDetails(details, currentMatchesByKey, livePayload);

        counts = {};
        Object.keys(details).forEach(function(pid) {
            counts[pid] = Object.keys(details[pid]).length;
            details[pid] = Object.values(details[pid]);
        });
        settleRemovedLateCandidates(lateLiveCandidateDetails, details, matches, livePayload);
        lateLiveCandidateCounts = counts;
        lateLiveCandidateDetails = details;
        saveLiveCandidateState();
        applyLateLiveCandidateIndicators();
    }

    function renderLiveCards(matches, livePayload) {
        var container = document.getElementById('live-cards');
        var signalMatches = [];
        if (!matches || !matches.length) {
            container.innerHTML = '<div class="live-empty">Tidak ada match live saat ini.</div>';
            renderLiveAlerts([]);
            return;
        }
        var liveMatches = matches.filter(function(m) {
            var statusText = getMatchStatusText(m);
            var s = parseStatus(statusText);
            return s.half === '1H' || s.half === '2H' || /^H\.?Time$/i.test(statusText);
        });
        if (!liveMatches.length) {
            container.innerHTML = '<div class="live-empty">Tidak ada match aktif (1H/HT/2H).</div>';
            renderLiveAlerts([]);
            return;
        }
        var html = '';
        for (var i = 0; i < liveMatches.length; i++) {
            var m = liveMatches[i];
            var lg = getLeagueTypeJS(getMatchLeague(m));
            var statusText = getMatchStatusText(m);
            var s = parseStatus(statusText);
            var score = getMatchScores(m);
            var h = score.home;
            var a = score.away;
            var key = matchKey(m);
            var signals = [];
            var htLabel = '';
            var phase2H = false;
            var isHalftime = /^H\.?Time$/i.test(statusText);
            if (s.half === '2H') {
                phase2H = true;
                var ht = htMemory[key];
                var htH = ht ? ht.h : h;
                var htA = ht ? ht.a : a;
                htLabel = 'HT: ' + htH + '-' + htA;
                signals = lg ? buildLiveSignals(m, livePayload, lg, htH, htA, h, a, s.min, 'ht') : [];
            } else if (isHalftime) {
                htLabel = 'HT: ' + h + '-' + a;
                signals = lg ? buildLiveSignals(m, livePayload, lg, h, a, h, a, 0, 'ht') : [];
            } else {
                signals = lg ? buildLiveSignals(m, livePayload, lg, h, a, h, a, s.min, '1h') : [];
            }
            var hasSignal = signals.length > 0;
            if (hasSignal) {
                signalMatches.push({
                    home: getMatchHomeTeam(m),
                    away: getMatchAwayTeam(m),
                    league: lg,
                    score: h + ' - ' + a,
                    status: phase2H ? ('2H ' + s.min + "\u2019") : (isHalftime ? 'HT' : ('1H ' + s.min + "\u2019")),
                    signals: signals
                });
            }
            var signalHtml = signals.map(function(sig) {
                return '<div class="signal-tag"><span class="pid">' + sig.id + '</span>' + sig.label + '</div>';
            }).join('');
            var halfBadge = phase2H
                ? '<span class="half-badge-2h">2H ' + s.min + "\u2019</span>"
                : '<span class="half-badge-1h">\u25CF 1H ' + s.min + "\u2019</span>";
            if (isHalftime) {
                halfBadge = '<span class="half-badge-1h">\u25CF HT</span>';
            }
            var lgLabel = lg ? '<span class="league-tag">[' + lg + ']</span>' : '<span class="league-unknown">[league?]</span>';
            var metaSuffix = ((phase2H || isHalftime) && htLabel)
                ? ' &nbsp;|&nbsp; <span class="ht-meta">' + htLabel + '</span>'
                : '';
            html += '<div class="live-card ' + (hasSignal ? 'has-signal' : '') + '">' 
                + '<div class="match-name">' + escHtml(getMatchHomeTeam(m)) + ' vs ' + escHtml(getMatchAwayTeam(m)) + '</div>'
                + '<div class="match-meta">' + lgLabel + ' ' + halfBadge + metaSuffix + '</div>'
                + '<div class="score-box">' + h + ' - ' + a + '</div>'
                + '<div class="signals">'
                + (signalHtml || '<span style="color:var(--text-muted);font-size:0.75rem;">Tidak ada signal pattern</span>')
                + '</div></div>';
        }
        container.innerHTML = html;
        renderLiveAlerts(signalMatches);
    }

    function renderLiveAlerts(signalMatches) {
        var alertBox = document.getElementById('live-alerts');
        var updateStatus = document.getElementById('update-status');
        if (!alertBox || !updateStatus) return;

        var settledResults = getRecentSettledSummaryResults(10);

        if ((!signalMatches || !signalMatches.length) && !settledResults.length) {
            alertBox.className = 'live-alerts-empty';
            alertBox.textContent = 'Belum ada alert pattern live.';
            updateStatus.textContent = '\u25CF LIVE';
            updateStatus.className = 'badge badge-green';
            document.title = 'Pattern Accuracy Dashboard';
            return;
        }

        signalMatches = signalMatches || [];
        var totalSignals = signalMatches.reduce(function(sum, item) {
            return sum + item.signals.length;
        }, 0);

        var itemsHtml = signalMatches.map(function(item) {
            var patternsHtml = item.signals.map(function(sig) {
                return '<span class="live-alert-pattern"><span class="pid">' + sig.id + '</span>' + escHtml(sig.label) + '</span>';
            }).join(' ');

            return '<div class="live-alert-item">'
                + '<div class="live-alert-match">' + escHtml(item.home) + ' vs ' + escHtml(item.away) + '</div>'
                + '<div class="live-alert-meta">[' + escHtml(item.league || 'league?') + '] ' + escHtml(item.status) + ' | Skor ' + escHtml(item.score) + '</div>'
                + '<div class="live-alert-patterns">' + patternsHtml + '</div>'
                + '</div>';
        }).join('');

        var settledItemsHtml = settledResults.map(function(item) {
            var outcomeBadge = item.outcome === 'win'
                ? '<span class="badge badge-green">WIN</span>'
                : '<span class="badge badge-red">LOSE</span>';
            var settledTime = new Date(item.settledAt || Date.now()).toLocaleTimeString();
            var currentLabel = getPatternLabelById(item.pid);
            return '<div class="live-alert-item">'
                + '<div class="live-alert-match">' + escHtml(item.home) + ' vs ' + escHtml(item.away) + ' ' + outcomeBadge + '</div>'
                + '<div class="live-alert-meta">[' + escHtml(item.league || 'league?') + '] ' + escHtml(item.status || 'Finished') + ' | Skor ' + escHtml(item.score || '-') + ' | ' + escHtml(settledTime) + '</div>'
                + '<div class="live-alert-patterns"><span class="live-alert-pattern"><span class="pid">' + escHtml(item.pid) + '</span>' + escHtml(currentLabel || item.pid) + '</span></div>'
                + '</div>';
        }).join('');

        var activeSection = '';
        if (signalMatches.length) {
            activeSection = '<div class="live-alerts-head">'
                + '<span class="live-alert-badge">ALERT ' + signalMatches.length + ' MATCH</span>'
                + '<span class="live-alerts-title">Ada indikasi pattern live</span>'
                + '<span class="live-alert-sub">' + totalSignals + ' signal aktif terdeteksi dari live scraper.</span>'
                + '</div>'
                + '<div class="live-alert-list">' + itemsHtml + '</div>';
        }

        var settledSection = '';
        if (settledResults.length) {
            settledSection = '<div class="live-alerts-head" style="margin-top:' + (activeSection ? '12px' : '0') + ';">'
                + '<span class="live-alert-badge" style="background:#1f6feb;">RESULT ' + settledResults.length + '</span>'
                + '<span class="live-alerts-title">Candidate yang sudah selesai</span>'
                + '<span class="live-alert-sub">Yang menang maupun kalah tetap disimpan sementara.</span>'
                + '</div>'
                + '<div class="live-alert-list">' + settledItemsHtml + '</div>';
        }

        alertBox.className = 'live-alerts-active';
        alertBox.innerHTML = activeSection + settledSection;

        if (signalMatches.length) {
            updateStatus.textContent = '\u25CF ALERT ' + signalMatches.length;
            updateStatus.className = 'badge badge-red';
            document.title = '(' + signalMatches.length + ') Pattern Alert';
        } else {
            updateStatus.textContent = '\u25CF RESULT ' + settledResults.length;
            updateStatus.className = 'badge badge-yellow';
            document.title = '(' + settledResults.length + ') Pattern Results';
        }
    }

    async function fetchLiveData() {
        try {
            var resp = await fetch('live_api_proxy.php', { signal: AbortSignal.timeout(6000) });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            var data = await resp.json();
            if (data && data.online === false) throw new Error(data.error || 'API offline');
            document.getElementById('live-api-badge').textContent = 'API Online';
            document.getElementById('live-api-badge').className = 'api-online';
            document.getElementById('btn-start-api').style.display = 'none';
            document.getElementById('btn-stop-api').style.display = 'inline-block';
            document.getElementById('live-last-update').textContent = 'Update: ' + new Date().toLocaleTimeString();
            try {
                var livePayload = normalizeLivePayload(data);
                syncLiveGoalMemory(livePayload.matches || [], livePayload);
                pruneSettledSummaryResultsForLiveMatches(livePayload.matches || []);
                updateHtMemory(livePayload.matches || []);
                collectLiveCandidateCounts(livePayload.matches || [], livePayload);
                collectNextLiveCandidateCounts(livePayload.matches || [], livePayload);
                collectLateLiveCandidateCounts(livePayload.matches || [], livePayload);
                renderLiveCards(livePayload.matches || [], livePayload);
                refreshActivePanelContent();
            } catch (renderError) {
                console.error('Live payload render error:', renderError);
                document.getElementById('live-last-update').textContent = 'API Online, tetapi render live gagal: ' + renderError.message;
            }
        } catch(e) {
            document.getElementById('live-api-badge').textContent = 'API Offline';
            document.getElementById('live-api-badge').className = 'api-offline';
            document.getElementById('btn-start-api').style.display = 'inline-block';
            document.getElementById('btn-stop-api').style.display = 'none';
            document.getElementById('live-last-update').textContent = '';
            document.getElementById('live-cards').innerHTML = '<div class="live-empty">API tidak aktif \u2014 klik \u25B6 Jalankan API</div>';
            applyLiveCandidateIndicators();
            applyNextLiveCandidateIndicators();
            applyLateLiveCandidateIndicators();
            refreshActivePanelContent();
            renderLiveAlerts([]);
        }
    }

    async function stopApiServer() {
        var btn = document.getElementById('btn-stop-api');
        btn.textContent = '\u23F3 Menghentikan...';
        btn.disabled = true;
        try {
            var resp = await fetch('stop_api_server.php');
            var result = await resp.json();
            document.getElementById('live-last-update').textContent = result.message || 'API dihentikan';
            if (result && result.success) {
                document.getElementById('live-api-badge').textContent = 'API Offline';
                document.getElementById('live-api-badge').className = 'api-offline';
                document.getElementById('btn-start-api').style.display = 'inline-block';
                document.getElementById('btn-stop-api').style.display = 'none';
                document.getElementById('live-cards').innerHTML = '<div class="live-empty">API tidak aktif \u2014 klik \u25B6 Jalankan API</div>';
            }
            setTimeout(fetchLiveData, 2000);
        } catch(e) {
            document.getElementById('live-last-update').textContent = 'Gagal stop: ' + e.message;
        }
        btn.textContent = '\u25A0 Stop API';
        btn.disabled = false;
    }

    async function waitForApiOnline(attemptsLeft) {
        await fetchLiveData();
        if (document.getElementById('live-api-badge').textContent === 'API Online') {
            return;
        }
        if (attemptsLeft <= 1) {
            return;
        }
        setTimeout(function() {
            waitForApiOnline(attemptsLeft - 1);
        }, LIVE_FETCH_INTERVAL_MS);
    }

    async function startApiServer() {
        var btn = document.getElementById('btn-start-api');
        btn.textContent = '\u23F3 Memulai...';
        btn.disabled = true;
        try {
            var resp = await fetch('start_api_server.php');
            var result = await resp.json();
            document.getElementById('live-last-update').textContent = result.message || 'Menunggu API...';
            if (result && result.success) {
                document.getElementById('live-api-badge').textContent = result.already_running ? 'API Online' : 'Memulai API...';
                document.getElementById('live-api-badge').className = result.already_running ? 'api-online' : 'api-offline';
                document.getElementById('btn-start-api').style.display = 'none';
                document.getElementById('btn-stop-api').style.display = 'inline-block';
                if (!result.already_running) {
                    document.getElementById('live-cards').innerHTML = '<div class="live-empty">API sedang dijalankan, tunggu beberapa detik...</div>';
                }
                waitForApiOnline(4);
            }
        } catch(e) {
            document.getElementById('live-last-update').textContent = 'Gagal: ' + e.message;
            document.getElementById('live-api-badge').textContent = 'API Offline';
            document.getElementById('live-api-badge').className = 'api-offline';
            document.getElementById('btn-start-api').style.display = 'inline-block';
            document.getElementById('btn-stop-api').style.display = 'none';
        }
        btn.textContent = '\u25B6 Jalankan API';
        btn.disabled = false;
    }

    window.closePanel = closePanel;
    window.startApiServer = startApiServer;
    window.stopApiServer = stopApiServer;

    function sortSummary(data, a, b, col, dir) {
        var mult = dir === 'asc' ? 1 : -1;
        if (col === 'record') {
            var ta = a.total, tb = b.total;
            if (ta !== tb) return mult * (ta - tb);
            var ha = a.has2h, hb = b.has2h;
            return mult * (ha - hb);
        }
        if (col === 'pct') {
            var pa = a.total > 0 ? a.has2h / a.total : 0;
            var pb = b.total > 0 ? b.has2h / b.total : 0;
            if (pa !== pb) return mult * (pa - pb);
            return mult * (a.total - b.total);
        }
        return 0;
    }

    function sortNext(data, a, b, col, dir) {
        var mult = dir === 'asc' ? 1 : -1;
        if (col === 'record') {
            var ta = a.total, tb = b.total;
            if (ta !== tb) return mult * (ta - tb);
            var ha = a.hits, hb = b.hits;
            return mult * (ha - hb);
        }
        if (col === 'pct') {
            var pa = a.total > 0 ? a.hits / a.total : 0;
            var pb = b.total > 0 ? b.hits / b.total : 0;
            if (pa !== pb) return mult * (pa - pb);
            return mult * (a.total - b.total);
        }
        return 0;
    }

    function sortSummaryRows() {
        var tbody = document.getElementById('summary-body');
        var rows = Array.from(tbody.querySelectorAll('tr'));
        if (!rows.length) return;
        var st = summarySortState;
        if (!st.col) return;
        rows.sort(function(a, b) {
            var dir = st.dir === 'asc' ? 1 : -1;
            if (st.col === 'record') {
                var ta = parseInt(a.getAttribute('data-total')) || 0;
                var tb = parseInt(b.getAttribute('data-total')) || 0;
                if (ta !== tb) return dir * (ta - tb);
                var ha = parseInt(a.getAttribute('data-hits')) || 0;
                var hb = parseInt(b.getAttribute('data-hits')) || 0;
                return dir * (ha - hb);
            }
            if (st.col === 'pct') {
                var pa = parseInt(a.getAttribute('data-pct')) || 0;
                var pb = parseInt(b.getAttribute('data-pct')) || 0;
                if (pa !== pb) return dir * (pa - pb);
                var ta2 = parseInt(a.getAttribute('data-total')) || 0;
                var tb2 = parseInt(b.getAttribute('data-total')) || 0;
                return dir * (ta2 - tb2);
            }
            return 0;
        });
        rows.forEach(function(row) { tbody.appendChild(row); });
    }

    function sortNextRows() {
        var tbody = document.getElementById('next-body');
        var rows = Array.from(tbody.querySelectorAll('tr'));
        if (!rows.length) return;
        var st = nextSortState;
        if (!st.col) return;
        rows.sort(function(a, b) {
            var dir = st.dir === 'asc' ? 1 : -1;
            if (st.col === 'record') {
                var ta = parseInt(a.getAttribute('data-total')) || 0;
                var tb = parseInt(b.getAttribute('data-total')) || 0;
                if (ta !== tb) return dir * (ta - tb);
                var ha = parseInt(a.getAttribute('data-hits')) || 0;
                var hb = parseInt(b.getAttribute('data-hits')) || 0;
                return dir * (ha - hb);
            }
            if (st.col === 'pct') {
                var pa = parseInt(a.getAttribute('data-pct')) || 0;
                var pb = parseInt(b.getAttribute('data-pct')) || 0;
                if (pa !== pb) return dir * (pa - pb);
                var ta2 = parseInt(a.getAttribute('data-total')) || 0;
                var tb2 = parseInt(b.getAttribute('data-total')) || 0;
                return dir * (ta2 - tb2);
            }
            return 0;
        });
        rows.forEach(function(row) { tbody.appendChild(row); });
    }

    function updateSortArrows() {
        document.querySelectorAll('.sortable[data-table="summary"]').forEach(function(th) {
            var arrow = th.querySelector('.sort-arrow');
            if (th.getAttribute('data-sort') === summarySortState.col) {
                arrow.className = 'sort-arrow ' + (summarySortState.dir === 'asc' ? 'sort-asc' : 'sort-desc');
            } else {
                arrow.className = 'sort-arrow';
            }
        });
        document.querySelectorAll('.sortable[data-table="next"]').forEach(function(th) {
            var arrow = th.querySelector('.sort-arrow');
            if (th.getAttribute('data-sort') === nextSortState.col) {
                arrow.className = 'sort-arrow ' + (nextSortState.dir === 'asc' ? 'sort-asc' : 'sort-desc');
            } else {
                arrow.className = 'sort-arrow';
            }
        });
    }

    function handleSortClick(e) {
        var th = e.currentTarget;
        var table = th.getAttribute('data-table');
        var col = th.getAttribute('data-sort');
        if (table === 'summary') {
            if (summarySortState.col === col) {
                summarySortState.dir = summarySortState.dir === 'asc' ? 'desc' : 'asc';
            } else {
                summarySortState.col = col;
                summarySortState.dir = 'desc';
            }
            if (currentSummaryData.length) {
                renderSummaryTable(currentSummaryData);
            } else {
                sortSummaryRows();
            }
        } else {
            if (nextSortState.col === col) {
                nextSortState.dir = nextSortState.dir === 'asc' ? 'desc' : 'asc';
            } else {
                nextSortState.col = col;
                nextSortState.dir = 'desc';
            }
            if (currentNextData.length) {
                renderNextTable(currentNextData);
            } else {
                sortNextRows();
            }
        }
        updateSortArrows();
    }

    document.querySelectorAll('.sortable').forEach(function(th) {
        console.log('Binding sortable header:', th.getAttribute('data-sort'));
        th.addEventListener('click', handleSortClick);
    });

    function initSortFromDom() {
        var summaryRows = document.querySelectorAll('#summary-body tr');
        if (summaryRows.length && !currentSummaryData.length) {
            currentSummaryData = Array.from(summaryRows).map(function(tr) {
                return {
                    id: tr.cells[0].textContent.trim(),
                    label: tr.cells[1].textContent.trim(),
                    total: parseInt(tr.getAttribute('data-total')) || 0,
                    has2h: parseInt(tr.getAttribute('data-hits')) || 0,
                    pct: parseInt(tr.getAttribute('data-pct')) || 0,
                    cls: tr.cells[3].className.replace('pct ', '').trim(),
                    badge: tr.cells[4].querySelector('.badge').className.replace('badge ', '').trim(),
                    status: tr.cells[4].querySelector('.badge').textContent.trim(),
                    delta: null
                };
            });
        }
        var nextRows = document.querySelectorAll('#next-body tr');
        if (nextRows.length && !currentNextData.length) {
            currentNextData = Array.from(nextRows).map(function(tr) {
                return {
                    id: tr.cells[0].textContent.trim(),
                    label: tr.cells[1].textContent.trim(),
                    total: parseInt(tr.getAttribute('data-total')) || 0,
                    hits: parseInt(tr.getAttribute('data-hits')) || 0,
                    nh: parseInt(tr.getAttribute('data-nh')) || 0,
                    na: parseInt(tr.getAttribute('data-na')) || 0,
                    pct: parseInt(tr.getAttribute('data-pct')) || 0,
                    cls: tr.cells[4].className.replace('pct ', '').trim(),
                    badge: tr.cells[5].querySelector('.badge').className.replace('badge ', '').trim(),
                    status: tr.cells[5].querySelector('.badge').textContent.trim(),
                    delta: null
                };
            });
        }
    }
    initSortFromDom();

    buildPatternData();
    restorePatternStateMemory();
    restoreLiveCandidateState();
    restoreSettledSummaryState();
    restoreSettledLateState();
    applyLiveCandidateIndicators();
    applyNextLiveCandidateIndicators();
    applyLateLiveCandidateIndicators();

    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closePanel(); });
    bindExpandButtons(document);

    var saved = sessionStorage.getItem('openPanel');
    if (saved) toggle(saved);

    setInterval(updateCountdown, 1000);
    fetchLiveData();
    setInterval(fetchLiveData, LIVE_FETCH_INTERVAL_MS);
})();
