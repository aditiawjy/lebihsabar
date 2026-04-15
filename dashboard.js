(function() {
    'use strict';

    var INITIAL_DATA = JSON.parse(document.getElementById('initial-data').textContent);
    var PATTERN_DATA = {};
    var PATTERN_DEFS = INITIAL_DATA.patternDefs || [];
    var TEAM_CONFIG = INITIAL_DATA.teamConfig || {};

    var activePanel = null;
    var htMemory = {};
    var prevStateMemory = {};
    var refreshCountdown = 30;
    var countdownEl = document.getElementById('countdown');

    var summarySortState = { col: null, dir: 'desc' };
    var nextSortState = { col: null, dir: 'desc' };
    var currentSummaryData = [];
    var currentNextData = [];
    var currentLateData = [];
    var liveCandidateCounts = {};

    var SIGNAL_DEFS = [
        { id: 'P10', fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return htH===0&&htA===0; }, labelFn: function() { return '0-0 di 1H'; }, phase: 'ht' },
        { id: 'P12', fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return (htH+htA)>=4; }, labelFn: function(lg,htH,htA) { return 'Total gol >= 4'; }, phase: 'ht' },
        { id: 'P15', fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return htH===2&&htA===2; }, labelFn: function() { return 'HT 2-2'; }, phase: 'ht' },
        { id: 'P2',  fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return Math.abs(htH-htA)>=2; }, labelFn: function(lg,htH,htA) { return 'HT selisih ' + Math.abs(htH-htA) + '+'; }, phase: 'ht' },
        { id: 'P7',  fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return htH===htA&&(htH+htA)>=2; }, labelFn: function(lg,htH,htA) { return 'HT Seri ' + htH + '-' + htA; }, phase: 'ht' },
        { id: 'P22', fn: function(lg,htH,htA) { return lg==='16min'&&htA>htH; }, labelFn: function() { return 'Away unggul HT, 16min'; }, phase: 'ht' },
        { id: 'P16', fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return lg==='16min'&&(htH+htA)>0&&curMin1H!==null&&curMin1H>=6; }, labelFn: function() { return 'Last mnt 6+, 16min'; }, phase: 'ht' },
        { id: 'P23', fn: function(lg,htH,htA) { return lg==='16min'&&(htH+htA)===1; }, labelFn: function() { return '1 gol HT, 16min'; }, phase: 'ht' },
        { id: 'P19', fn: function(lg,htH,htA) { return lg==='20min'&&htH>htA; }, labelFn: function() { return 'Home unggul HT, 20min'; }, phase: 'ht' },
        { id: 'P21', fn: function(lg,htH,htA) { return lg==='15min'&&htA>htH; }, labelFn: function() { return 'Away unggul HT, 15min'; }, phase: 'ht' },
        { id: 'P21b', fn: function(lg,htH,htA) { return lg==='15min'&&htH===htA&&htA>0; }, labelFn: function() { return 'Seri HT, 15min'; }, phase: 'ht' },
        { id: 'P10_1h', fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return h===0&&a===0; }, labelFn: function() { return '0-0 sedang berjalan'; }, phase: '1h' },
        { id: 'P12_1h', fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return (h+a)>=4; }, labelFn: function(lg,htH,htA,curMin1H,h,a,curMin) { return 'Sudah ' + (h+a) + ' gol!'; }, phase: '1h' },
        { id: 'P15_1h', fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return h===2&&a===2; }, labelFn: function() { return 'Seri 2-2'; }, phase: '1h' },
        { id: 'P6_1h', fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return h===1&&a===1&&curMin>=7; }, labelFn: function() { return 'Seri 1-1, mnt 7+'; }, phase: '1h' },
        { id: 'P7_1h', fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return h===1&&a===1&&curMin>=5; }, labelFn: function() { return 'Seri 1-1, mnt 5+'; }, phase: '1h' },
        { id: 'P22_1h', fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return lg==='16min'&&a>h; }, labelFn: function() { return 'Away unggul, 16min'; }, phase: '1h' },
        { id: 'P16_1h', fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return lg==='16min'&&curMin>=6&&(h+a)>0; }, labelFn: function(lg,htH,htA,curMin1H,h,a,curMin) { return 'Mnt ' + curMin + '+, 16min'; }, phase: '1h' },
        { id: 'P23_1h', fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return lg==='16min'&&(h+a)===1&&curMin>=3; }, labelFn: function(lg,htH,htA,curMin1H,h,a,curMin) { return '1 gol mnt ' + curMin + ', 16min'; }, phase: '1h' },
        { id: 'P19_1h', fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return lg==='20min'&&h>a; }, labelFn: function() { return 'Home unggul, 20min'; }, phase: '1h' },
        { id: 'P21_1h', fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return lg==='15min'&&a>=h&&(h+a)>0&&curMin>=4; }, labelFn: function(lg,htH,htA,curMin1H,h,a,curMin) { return 'Away score mnt ' + curMin + ', 15min'; }, phase: '1h' },
        { id: 'P2_1h', fn: function(lg,htH,htA,curMin1H,h,a,curMin) { return Math.abs(h-a)>=2&&curMin>=7; }, labelFn: function(lg,htH,htA,curMin1H,h,a,curMin) { return 'Selisih ' + Math.abs(h-a) + '+, mnt 7+'; }, phase: '1h' },
    ];

    function escHtml(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function buildPatternData() {
        INITIAL_DATA.patterns.forEach(function(p) {
            var total = p.data.length;
            var has2h = p.data.filter(function(m) { return m.h2c > 0; }).length;
            var pct = total > 0 ? Math.round(has2h / total * 100) : 0;
            var rowsHtml = '';
            p.data.forEach(function(m) {
                var seq = m.h1s.map(function(s) {
                    return s === 'H' ? '<span class="scorer-h">H</span>' : '<span class="scorer-a">A</span>';
                }).join(' \u2192 ');
                var tl1h = m.h1.map(function(g) { return g.min + "\u2019 (" + g.home + "-" + g.away + ") "; }).join('  ');
                var tl2h = m.h2.map(function(g) { return g.min + "\u2019 (" + g.home + "-" + g.away + ") "; }).join('  ');
                var has2hBadge = m.h2c > 0
                    ? '<span class="badge badge-green">\u2713 2H</span>'
                    : '<span class="badge badge-red">\u2717 No 2H</span>';
                rowsHtml += '<tr>'
                    + '<td class="record-sub">' + escHtml(m.datetime || '') + '</td>'
                    + '<td>' + escHtml(m.home) + ' vs ' + escHtml(m.away) + '</td>'
                    + '<td>' + escHtml(m.league) + '</td>'
                    + '<td>' + m.sc_h + '-' + m.sc_a + '</td>'
                    + '<td><strong>' + m.fh + '-' + m.fa + '</strong></td>'
                    + '<td class="goal-seq">' + tl1h + '</td>'
                    + '<td class="goal-seq">' + (tl2h || '<span class="delta-zero">-</span>') + '</td>'
                    + '<td class="goal-seq">' + seq + '</td>'
                    + '<td>' + has2hBadge + '</td></tr>';
            });
            var table = '<table class="detail-table"><thead><tr><th>Tanggal</th><th>Match</th><th>League</th><th>HT</th><th>FT</th><th>Timeline 1H</th><th>Timeline 2H</th><th>Sequence</th><th>2H?</th></tr></thead><tbody>' + rowsHtml + '</tbody></table>';
            PATTERN_DATA[p.id] = { label: p.label, record: has2h + '/' + total, pct: pct + '%', html: table };
        });

        INITIAL_DATA.nextPatterns.forEach(function(ng) {
            var total = ng.data.length;
            var nh = ng.data.filter(function(m) { return m.next_goal === 'H'; }).length;
            var na = ng.data.filter(function(m) { return m.next_goal === 'A'; }).length;
            var tgt = ng.next;
            var hits = tgt === 'HOME' ? nh : na;
            var pct = total > 0 ? Math.round(hits / total * 100) : 0;
            var rowsHtml = '';
            ng.data.forEach(function(m) {
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
                rowsHtml += '<tr' + rowAttr + '>'
                    + '<td class="record-sub">' + escHtml(m.datetime || '') + '</td>'
                    + '<td>' + escHtml(m.home) + ' vs ' + escHtml(m.away) + '</td>'
                    + '<td>' + escHtml(m.league) + '</td>'
                    + '<td>' + m.sc_h + '-' + m.sc_a + '</td>'
                    + '<td>' + seq + '</td>'
                    + '<td class="goal-seq">' + tl1h + '</td>'
                    + '<td class="goal-seq">' + (tl2h || '<span class="delta-zero">-</span>') + '</td>'
                    + '<td>' + nextBadge + '</td></tr>';
            });
            var table = '<table class="detail-table"><thead><tr><th>Tanggal</th><th>Match</th><th>League</th><th>HT</th><th>Sequence 1H</th><th>Timeline 1H</th><th>Timeline 2H</th><th>Next Goal</th></tr></thead><tbody>' + rowsHtml + '</tbody></table>';
            PATTERN_DATA[ng.id] = { label: ng.label, record: hits + '/' + total, pct: pct + '%', html: table };
        });

        (INITIAL_DATA.latePatterns || []).forEach(function(lp) {
            var total = lp.data.length;
            var lateHits = lp.data.filter(function(m) { return m.has_late; }).length;
            var pct = total > 0 ? Math.round(lateHits / total * 100) : 0;
            var rowsHtml = '';
            lp.data.forEach(function(m) {
                var seq = m.h1s.map(function(s) {
                    return s === 'H' ? '<span class="scorer-h">H</span>' : '<span class="scorer-a">A</span>';
                }).join(' \u2192 ');
                var tl1h = m.h1.map(function(g) { return g.min + "\u2019 (" + g.home + "-" + g.away + ") "; }).join('  ');
                var tl2h = m.h2.map(function(g) { return g.min + "\u2019 (" + g.home + "-" + g.away + ") "; }).join('  ');
                var lateGoals = (m.h2 || []).filter(function(g) { return g.min >= 7; });
                var lateTimeline = lateGoals.map(function(g) { return g.min + "\u2019 (" + g.home + "-" + g.away + ") "; }).join('  ');
                var lateBadge = m.has_late
                    ? '<span class="badge badge-green">\u2713 Late Goal</span>'
                    : '<span class="badge badge-red">\u2717 No Late Goal</span>';
                rowsHtml += '<tr>'
                    + '<td class="record-sub">' + escHtml(m.datetime || '') + '</td>'
                    + '<td>' + escHtml(m.home) + ' vs ' + escHtml(m.away) + '</td>'
                    + '<td>' + escHtml(m.league) + '</td>'
                    + '<td>' + m.sc_h + '-' + m.sc_a + '</td>'
                    + '<td><strong>' + m.fh + '-' + m.fa + '</strong></td>'
                    + '<td class="goal-seq">' + tl1h + '</td>'
                    + '<td class="goal-seq">' + (tl2h || '<span class="delta-zero">-</span>') + '</td>'
                    + '<td class="goal-seq">' + seq + '</td>'
                    + '<td class="goal-seq">' + (lateTimeline || '<span class="delta-zero">-</span>') + '</td>'
                    + '<td>' + lateBadge + '</td></tr>';
            });
            var table = '<table class="detail-table"><thead><tr><th>Tanggal</th><th>Match</th><th>League</th><th>HT</th><th>FT</th><th>Timeline 1H</th><th>Timeline 2H</th><th>Sequence</th><th>Late Timeline</th><th>Late?</th></tr></thead><tbody>' + rowsHtml + '</tbody></table>';
            PATTERN_DATA[lp.id] = { label: lp.label, record: lateHits + '/' + total, pct: pct + '%', html: table };
        });
    }

    function toggle(id) {
        if (activePanel === id) { closePanel(); return; }
        var d = PATTERN_DATA[id];
        if (!d) return;
        document.getElementById('slide-title').innerHTML = '<strong>' + id + '</strong>: ' + d.label + ' <span style="color:var(--text-secondary);font-size:0.85rem;">' + d.record + ' = ' + d.pct + '</span>';
        document.getElementById('slide-body').innerHTML = d.html;
        document.getElementById('slide-overlay').style.display = 'block';
        document.getElementById('slide-panel').classList.add('open');
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
            refreshCountdown = 30;
        }
    }

    async function refreshDashboard() {
        try {
            var resp = await fetch('dashboard_api.php', { signal: AbortSignal.timeout(5000) });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            var apiData = await resp.json();

            document.getElementById('stat-total').textContent = apiData.total_matches;
            document.getElementById('stat-patterns').textContent = apiData.pattern_count;
            if (apiData.csv_time_str) {
                document.getElementById('stat-updated').textContent = apiData.csv_time_str;
            }

            renderSummaryTable(apiData.patterns);
            renderNextTable(apiData.next_patterns);
            renderLateTable(apiData.late_patterns || []);

            document.getElementById('update-time').textContent = 'Last: ' + new Date().toLocaleTimeString();
            document.getElementById('last-update').textContent =
                'CSV last modified: ' + (apiData.csv_time ? new Date(apiData.csv_time * 1000).toLocaleString() : '-')
                + ' | Total ' + apiData.total_matches + ' matches | Auto-refresh: 30s via AJAX';
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
        currentSummaryData = patterns;
        var sorted = applySummarySort(patterns);
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
        currentNextData = nextPatterns;
        var sorted = applyNextSort(nextPatterns);
        var tbody = document.getElementById('next-body');
        tbody.innerHTML = sorted.map(function(ng) {
            var nextBadge = ng.next === 'HOME'
                ? '<span class="scorer-h next-badge-home">HOME</span>'
                : '<span class="scorer-a next-badge-away">AWAY</span>';
            return '<tr data-total="' + ng.total + '" data-hits="' + ng.hits + '" data-nh="' + ng.nh + '" data-na="' + ng.na + '" data-pct="' + ng.pct + '">'
                + '<td><strong>' + ng.id + '</strong></td>'
                + '<td>' + escHtml(ng.label) + '</td>'
                + '<td>' + nextBadge + '</td>'
                + '<td>' + ng.hits + '/' + ng.total + ' <span class="record-sub">(H:' + ng.nh + ' A:' + ng.na + ')</span></td>'
                + '<td class="pct ' + ng.cls + '">' + ng.pct + '%</td>'
                + '<td><span class="badge ' + ng.badge + '">' + ng.status + '</span></td>'
                + '<td style="font-size:0.8rem;">' + (ng.delta && ng.delta.html ? ng.delta.html : '<span class="delta-zero">\u2014</span>') + '</td>'
                + '<td><button class="expand-btn" data-pid="' + ng.id + '">Detail</button></td>'
                + '</tr>';
        }).join('');
        bindExpandButtons(tbody);
    }

    function renderLateTable(latePatterns) {
        currentLateData = latePatterns;
        var tbody = document.getElementById('late-body');
        if (!tbody) return;
        tbody.innerHTML = latePatterns.map(function(lp) {
            return '<tr data-total="' + lp.total + '" data-hits="' + lp.late_hits + '" data-pct="' + lp.pct + '">'
                + '<td><strong>' + lp.id + '</strong></td>'
                + '<td>' + escHtml(lp.label) + '</td>'
                + '<td>' + lp.late_hits + '/' + lp.total + '</td>'
                + '<td class="pct ' + lp.cls + '">' + lp.pct + '%</td>'
                + '<td><span class="badge ' + lp.badge + '">' + lp.status + '</span></td>'
                + '<td style="font-size:0.8rem;">' + (lp.delta && lp.delta.html ? lp.delta.html : '<span class="delta-zero">\u2014</span>') + '</td>'
                + '<td><button class="expand-btn" data-pid="' + lp.id + '">Detail</button></td>'
                + '</tr>';
        }).join('');
        bindExpandButtons(tbody);
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
                all2HGoalMinutes: apiData.all2HGoalMinutes || {}
            };
        }

        var data = apiData && apiData.data ? apiData.data : {};
        return {
            matches: Array.isArray(data.live_matches) ? data.live_matches : (Array.isArray(data.matches) ? data.matches : []),
            allGoalMinutes: data.allGoalMinutes || apiData.allGoalMinutes || {},
            allGoalScorers: data.allGoalScorers || apiData.allGoalScorers || {},
            all2HGoalMinutes: data.all2HGoalMinutes || apiData.all2HGoalMinutes || {}
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

    function buildLivePatternState(match, livePayload) {
        var key = matchKey(match);
        var goalMins = getLiveGoalMinutes(livePayload, key);
        var scorers = getLiveGoalScorers(livePayload, key);
        if (!scorers.length && goalMins.length) {
            scorers = deriveFallbackScorers(goalMins, match);
        }
        if (goalMins.length !== scorers.length) {
            return null;
        }

        var counts = scorerCountsJS(scorers);
        return {
            home: getMatchHomeTeam(match),
            away: getMatchAwayTeam(match),
            league: getLeagueTypeJS(getMatchLeague(match)),
            h1c: goalMins.length,
            sc_h: counts.home,
            sc_a: counts.away,
            h1_first: goalMins.length ? goalMins[0] : -1,
            h1_last: goalMins.length ? goalMins[goalMins.length - 1] : -1,
            h1s: scorers,
            switches: countSwitchesJS(scorers),
            max_gap: getMaxGap(goalMins),
            min_gap: minGapJS(goalMins),
            max_run: maxRunJS(scorers),
            all_gaps_ge3: allGapsGeJS(goalMins, 3)
        };
    }

    function inTeamConfig(listName, team) {
        return Array.isArray(TEAM_CONFIG[listName]) && TEAM_CONFIG[listName].indexOf(team) !== -1;
    }

    function matchesSummaryPatternLive(pid, s) {
        if (!s) return false;
        var span = s.h1c >= 2 ? (s.h1_last - s.h1_first) : 0;
        var diff = Math.abs(s.sc_h - s.sc_a);
        var lastScorer = s.h1s.length ? s.h1s[s.h1s.length - 1] : null;
        var firstScorer = s.h1s.length ? s.h1s[0] : null;

        switch (pid) {
            case 'P2': return s.league === '16min' && s.h1c >= 2 && diff >= 2 && s.h1_last === 7 && s.all_gaps_ge3 && s.max_run <= 2 && s.h1_first <= 1;
            case 'P3': return ['15min', '16min'].indexOf(s.league) !== -1 && s.h1c === 2 && s.sc_h === 1 && s.sc_a === 1 && arrayEqualsJS(s.h1s, ['A', 'H']) && s.max_gap >= 4 && s.h1_last >= 4 && s.h1_first >= 2;
            case 'P6': return s.h1c === 2 && s.sc_h === 1 && s.sc_a === 1 && s.h1_last === 7 && span >= 4 && s.h1_first !== 1;
            case 'P7': return s.h1c === 2 && s.sc_h === 1 && s.sc_a === 1 && s.max_gap >= 5 && s.h1_first !== 1;
            case 'P9': return s.h1c === 2 && s.sc_h === 1 && s.sc_a === 1 && arrayEqualsJS(s.h1s, ['A', 'H']) && s.max_gap >= 5 && s.h1_first !== 1;
            case 'P12': return s.h1c >= 4 && span >= 6 && s.min_gap >= 1 && s.h1_last <= 9 && s.h1_first >= 1 && s.h1_first !== 1;
            case 'P13': return s.h1c >= 2 && s.h1_first <= 2 && s.h1_last === 7 && diff <= 2 && s.min_gap >= 3 && s.switches >= 1;
            case 'P14': return s.h1c >= 2 && s.sc_h === s.sc_a && s.sc_h > 0 && s.max_gap >= 4 && span >= 5 && s.h1_first !== 1 && s.min_gap >= 2;
            case 'P15': return s.sc_h === 2 && s.sc_a === 2 && s.max_gap <= 2;
            case 'P16': return s.league === '16min' && s.h1_last === 7 && span >= 3 && s.switches >= 1 && s.h1_first !== 1;
            case 'P17': return s.h1c >= 2 && s.h1_first >= 1 && s.h1_first <= 2 && s.h1_last === 7 && s.max_gap >= 2 && s.min_gap >= 2 && s.switches >= 1 && firstScorer === 'A';
            case 'P18': return s.h1c >= 3 && span >= 6 && diff <= 2 && s.max_run <= 2 && s.switches >= 3 && s.min_gap >= 1 && s.h1_first >= 1;
            case 'P19': return s.league === '20min' && [3, 4].indexOf(s.h1_last) !== -1 && lastScorer === 'H' && (s.h1c === 1 || s.max_gap >= 2);
            case 'P20': return s.league === '16min' && s.h1_last === 3 && lastScorer === 'A';
            case 'P21': return s.league === '15min' && s.h1_last === 5 && lastScorer === 'A' && s.max_gap >= 2 && s.min_gap >= 1 && (s.h1c >= 3 || s.sc_a > s.sc_h) && s.switches >= 1 && s.max_run <= 2;
            case 'P22': return s.league === '16min' && s.sc_a > s.sc_h && s.h1c >= 2 && span >= 3 && s.switches >= 1;
            case 'P24': return s.league === '15min' && inTeamConfig('p24_teams', s.home) && s.h1c >= 1 && s.h1_last >= 4 && diff <= 1 && s.sc_h >= 1 && s.h1_first >= 4 && firstScorer === 'H';
            case 'P25': return inTeamConfig('p25_teams', s.away) && s.h1_last >= 2 && diff <= 1 && span >= 3 && s.min_gap >= 2;
            case 'P26': return s.league === '16min' && ((s.sc_h + s.sc_a) % 2 === 1) && s.h1_last >= 6 && s.h1c >= 2 && s.sc_a > s.sc_h;
            case 'P27': return s.league === '16min' && lastScorer === 'A' && s.max_gap >= 3 && s.h1_first !== 1 && span >= 6;
            case 'P28': return (inTeamConfig('p28_teams', s.home) || inTeamConfig('p28_teams', s.away)) && s.h1_last >= 3 && span >= 3 && diff <= 1;
            case 'P32': return s.league === '20min' && s.h1c >= 2 && span >= 9 && s.sc_h === s.sc_a && s.min_gap >= 3 && s.switches >= 1 && s.h1_first !== 1;
            case 'P33': return s.league === '15min' && s.h1c >= 4 && diff <= 1 && s.min_gap >= 1;
            case 'P34': return s.league === '15min' && firstScorer === 'A' && lastScorer === 'H' && span >= 6 && s.h1c >= 4;
            case 'P35': return inTeamConfig('p35_teams', s.away) && s.h1_last >= 4 && diff <= 1 && s.h1_first >= 3 && s.switches >= 1;
            case 'P36': return inTeamConfig('p36_teams', s.home) && s.h1c >= 2 && span >= 1 && diff <= 1 && s.min_gap >= 2;
            case 'P37': return s.league === '16min' && s.h1c >= 2 && s.h1_first <= 1 && firstScorer === 'A' && lastScorer === 'A' && s.switches === 0;
            case 'P39': return s.league === '20min' && s.h1c >= 3 && span >= 7 && diff <= 3 && s.min_gap >= 3 && s.h1_first >= 1;
            case 'P40': return s.league === '16min' && diff >= 2 && s.h1_first <= 1;
            case 'P41': return diff >= 2 && s.h1_first >= 2 && span >= 6;
            case 'P42': return s.h1_first >= 2 && span >= 6 && s.min_gap >= 3;
            case 'P43': return s.sc_a > s.sc_h && span >= 6 && lastScorer === 'H';
            case 'P44': return diff >= 2 && s.h1_first >= 2 && s.switches >= 1;
            case 'P45': return s.league === '16min' && s.h1_first !== 1 && span >= 6;
            case 'P46': return s.league === '16min' && span >= 6 && s.min_gap >= 2;
            case 'P47': return s.sc_h === s.sc_a && s.h1_first !== 1 && s.switches >= 2;
            case 'P48': return s.sc_h === s.sc_a && span >= 7 && s.switches >= 2;
            case 'P49': return s.league === '16min' && diff >= 2 && span >= 6;
            case 'P50': return s.league === '16min' && s.sc_a > s.sc_h && span >= 6;
            case 'P51': return s.league === '16min' && s.switches >= 2;
            case 'P52': return s.league === '16min' && span >= 6 && s.min_gap >= 3;
            default: return false;
        }
    }

    function matchKey(m) {
        return getMatchHomeTeam(m) + '|' + getMatchAwayTeam(m) + '|' + getMatchLeague(m);
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
            }
            prevStateMemory[key] = { half: s.half, h: h, a: a };
        }
    }

    function getLiveGoalMinutes(livePayload, key) {
        var allGoalMinutes = livePayload && livePayload.allGoalMinutes ? livePayload.allGoalMinutes : {};
        return Array.isArray(allGoalMinutes[key])
            ? allGoalMinutes[key].map(function(min) { return parseInt(min, 10); }).filter(function(min) { return !Number.isNaN(min); })
            : [];
    }

    function getLiveGoalScorers(livePayload, key) {
        var allGoalScorers = livePayload && livePayload.allGoalScorers ? livePayload.allGoalScorers : {};
        return Array.isArray(allGoalScorers[key]) ? allGoalScorers[key] : [];
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

        var goalMins = getLiveGoalMinutes(livePayload, key);
        if (!goalMins.length) return false;

        var lastGoalMin = goalMins[goalMins.length - 1];
        if (lastGoalMin !== 3 && lastGoalMin !== 4) return false;

        if (goalMins.length > 1 && getMaxGap(goalMins) < 2) return false;

        return isLastScorerHome(goalMins, getLiveGoalScorers(livePayload, key), htH, htA);
    }

    function buildLiveSignals(match, livePayload, lg, htH, htA, h, a, curMin, phase) {
        var signals = evaluateSignalsForMatch(lg, htH, htA, phase === '1h' ? curMin : null, h, a, curMin, phase)
            .filter(function(sig) { return sig.id !== 'P19'; });

        if (isHistoricalP19LiveCandidate(match, livePayload)) {
            signals.push({ id: 'P19', label: 'Last gol 1H mnt 3-4, last HOME, 20min, gap>=2' });
        }

        return signals;
    }

    function applyLiveCandidateIndicators() {
        document.querySelectorAll('#summary-body tr').forEach(function(row) {
            var pid = row.getAttribute('data-pid') || (row.cells[0] ? row.cells[0].textContent.trim() : '');
            var deltaCell = row.querySelector('.delta-cell') || row.cells[5];
            if (!pid || !deltaCell) return;

            if (!deltaCell.dataset.baseHtml) {
                deltaCell.dataset.baseHtml = deltaCell.innerHTML;
            }

            var baseHtml = deltaCell.dataset.baseHtml;
            var liveCount = liveCandidateCounts[pid] || 0;
            if (liveCount > 0) {
                deltaCell.innerHTML = baseHtml + '<br><span style="color:#58a6ff;font-weight:600;">live ' + liveCount + ' candidate' + (liveCount > 1 ? 's' : '') + '</span>';
            } else {
                deltaCell.innerHTML = baseHtml;
            }
        });
    }

    function collectLiveCandidateCounts(matches, livePayload) {
        var counts = {};
        (matches || []).forEach(function(match) {
            var statusText = getMatchStatusText(match);
            var s = parseStatus(statusText);
            if (s.half !== '1H' && s.half !== '2H' && !/^H\.?Time$/i.test(statusText)) return;

            var state = buildLivePatternState(match, livePayload);
            if (!state) return;

            PATTERN_DEFS.forEach(function(pattern) {
                if (matchesSummaryPatternLive(pattern.id, state)) {
                    counts[pattern.id] = (counts[pattern.id] || 0) + 1;
                }
            });
        });

        liveCandidateCounts = counts;
        applyLiveCandidateIndicators();
    }

    function evaluateSignalsForMatch(lg, htH, htA, curMin1H, h, a, curMin, phase) {
        var signals = [];
        var seen = {};
        for (var i = 0; i < SIGNAL_DEFS.length; i++) {
            var def = SIGNAL_DEFS[i];
            if (def.phase !== phase) continue;
            var args = [lg, htH, htA, curMin1H, h, a, curMin];
            try {
                if (def.fn.apply(null, args)) {
                    var label = typeof def.labelFn === 'function' ? def.labelFn.apply(null, args) : def.labelFn;
                    var cleanId = def.id.replace('_1h','').replace('_b','');
                    if (!seen[cleanId]) {
                        seen[cleanId] = true;
                        signals.push({ id: cleanId, label: label });
                    }
                }
            } catch(e) {}
        }
        return signals;
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
            var s = parseStatus(getMatchStatusText(m));
            return s.half === '1H' || s.half === '2H';
        });
        if (!liveMatches.length) {
            container.innerHTML = '<div class="live-empty">Tidak ada match aktif (1H/2H).</div>';
            renderLiveAlerts([]);
            return;
        }
        var html = '';
        for (var i = 0; i < liveMatches.length; i++) {
            var m = liveMatches[i];
            var lg = getLeagueTypeJS(getMatchLeague(m));
            var s = parseStatus(getMatchStatusText(m));
            var score = getMatchScores(m);
            var h = score.home;
            var a = score.away;
            var key = matchKey(m);
            var signals = [];
            var htLabel = '';
            var phase2H = false;
            if (s.half === '2H') {
                phase2H = true;
                var ht = htMemory[key];
                var htH = ht ? ht.h : h;
                var htA = ht ? ht.a : a;
                htLabel = 'HT: ' + htH + '-' + htA;
                signals = lg ? buildLiveSignals(m, livePayload, lg, htH, htA, h, a, s.min, 'ht') : [];
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
                    status: (phase2H ? '2H ' : '1H ') + s.min + "\u2019",
                    signals: signals
                });
            }
            var signalHtml = signals.map(function(sig) {
                return '<div class="signal-tag"><span class="pid">' + sig.id + '</span>' + sig.label + '</div>';
            }).join('');
            var halfBadge = phase2H
                ? '<span class="half-badge-2h">2H ' + s.min + "\u2019</span>"
                : '<span class="half-badge-1h">\u25CF 1H ' + s.min + "\u2019</span>";
            var lgLabel = lg ? '<span class="league-tag">[' + lg + ']</span>' : '<span class="league-unknown">[league?]</span>';
            html += '<div class="live-card ' + (hasSignal ? 'has-signal' : '') + '">' 
                + '<div class="match-name">' + escHtml(getMatchHomeTeam(m)) + ' vs ' + escHtml(getMatchAwayTeam(m)) + '</div>'
                + '<div class="match-meta">' + lgLabel + ' ' + halfBadge + (phase2H && htLabel ? ' &nbsp;|&nbsp; <span class="ht-meta">' + htLabel + '</span>' : '') + '</div>'
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

        if (!signalMatches || !signalMatches.length) {
            alertBox.className = 'live-alerts-empty';
            alertBox.textContent = 'Belum ada alert pattern live.';
            updateStatus.textContent = '\u25CF LIVE';
            updateStatus.className = 'badge badge-green';
            document.title = 'Pattern Accuracy Dashboard';
            return;
        }

        var totalSignals = signalMatches.reduce(function(sum, item) {
            return sum + item.signals.length;
        }, 0);

        var itemsHtml = signalMatches.map(function(item) {
            var patternsHtml = item.signals.map(function(sig) {
                return '<span class="live-alert-pattern"><span class="pid">' + sig.id + '</span>' + escHtml(sig.label) + '</span>';
            }).join('');

            return '<div class="live-alert-item">'
                + '<div class="live-alert-match">' + escHtml(item.home) + ' vs ' + escHtml(item.away) + '</div>'
                + '<div class="live-alert-meta">[' + escHtml(item.league || 'league?') + '] ' + escHtml(item.status) + ' | Skor ' + escHtml(item.score) + '</div>'
                + '<div class="live-alert-patterns">' + patternsHtml + '</div>'
                + '</div>';
        }).join('');

        alertBox.className = 'live-alerts-active';
        alertBox.innerHTML = '<div class="live-alerts-head">'
            + '<span class="live-alert-badge">ALERT ' + signalMatches.length + ' MATCH</span>'
            + '<span class="live-alerts-title">Ada indikasi pattern live</span>'
            + '<span class="live-alert-sub">' + totalSignals + ' signal aktif terdeteksi dari live scraper.</span>'
            + '</div>'
            + '<div class="live-alert-list">' + itemsHtml + '</div>';

        updateStatus.textContent = '\u25CF ALERT ' + signalMatches.length;
        updateStatus.className = 'badge badge-red';
        document.title = '(' + signalMatches.length + ') Pattern Alert';
    }

    async function fetchLiveData() {
        try {
            var resp = await fetch('http://127.0.0.1:5000/api/live-data', { signal: AbortSignal.timeout(3000) });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            var data = await resp.json();
            var livePayload = normalizeLivePayload(data);
            document.getElementById('live-api-badge').textContent = 'API Online';
            document.getElementById('live-api-badge').className = 'api-online';
            document.getElementById('btn-start-api').style.display = 'none';
            document.getElementById('btn-stop-api').style.display = 'inline-block';
            document.getElementById('live-last-update').textContent = 'Update: ' + new Date().toLocaleTimeString();
            updateHtMemory(livePayload.matches || []);
            renderLiveCards(livePayload.matches || [], livePayload);
            collectLiveCandidateCounts(livePayload.matches || [], livePayload);
        } catch(e) {
            document.getElementById('live-api-badge').textContent = 'API Offline';
            document.getElementById('live-api-badge').className = 'api-offline';
            document.getElementById('btn-start-api').style.display = 'inline-block';
            document.getElementById('btn-stop-api').style.display = 'none';
            document.getElementById('live-last-update').textContent = '';
            document.getElementById('live-cards').innerHTML = '<div class="live-empty">API tidak aktif \u2014 klik \u25B6 Jalankan API</div>';
            liveCandidateCounts = {};
            applyLiveCandidateIndicators();
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
            setTimeout(fetchLiveData, 2000);
        } catch(e) {
            document.getElementById('live-last-update').textContent = 'Gagal stop: ' + e.message;
        }
        btn.textContent = '\u25A0 Stop API';
        btn.disabled = false;
    }

    async function startApiServer() {
        var btn = document.getElementById('btn-start-api');
        btn.textContent = '\u23F3 Memulai...';
        btn.disabled = true;
        try {
            var resp = await fetch('start_api_server.php');
            var result = await resp.json();
            document.getElementById('live-last-update').textContent = result.message || 'Menunggu API...';
            setTimeout(fetchLiveData, 3000);
        } catch(e) {
            document.getElementById('live-last-update').textContent = 'Gagal: ' + e.message;
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

    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closePanel(); });
    bindExpandButtons(document);

    var saved = sessionStorage.getItem('openPanel');
    if (saved) toggle(saved);

    setInterval(updateCountdown, 1000);
    fetchLiveData();
    setInterval(fetchLiveData, 5000);
})();
