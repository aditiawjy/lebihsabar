(function() {
    'use strict';

    var INITIAL_DATA = JSON.parse(document.getElementById('initial-data').textContent);
    var PATTERN_DATA = {};
    var PATTERN_DEFS = INITIAL_DATA.patternDefs || [];

    var activePanel = null;
    var htMemory = {};
    var prevStateMemory = {};
    var refreshCountdown = 30;
    var countdownEl = document.getElementById('countdown');

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

            document.getElementById('update-time').textContent = 'Last: ' + new Date().toLocaleTimeString();
            document.getElementById('last-update').textContent =
                'CSV last modified: ' + (apiData.csv_time ? new Date(apiData.csv_time * 1000).toLocaleString() : '-')
                + ' | Total ' + apiData.total_matches + ' matches | Auto-refresh: 30s via AJAX';
        } catch(e) {
            // silent — keep current data
        }
    }

    function renderSummaryTable(patterns) {
        var tbody = document.getElementById('summary-body');
        tbody.innerHTML = patterns.map(function(p) {
            return '<tr>'
                + '<td><strong>' + p.id + '</strong></td>'
                + '<td>' + escHtml(p.label) + '</td>'
                + '<td>' + p.has2h + '/' + p.total + '</td>'
                + '<td class="pct ' + p.cls + '">' + p.pct + '%</td>'
                + '<td><span class="badge ' + p.badge + '">' + p.status + '</span></td>'
                + '<td style="font-size:0.8rem;">' + (p.delta && p.delta.html ? p.delta.html : '<span class="delta-zero">\u2014</span>') + '</td>'
                + '<td><button class="expand-btn" data-pid="' + p.id + '">Detail</button></td>'
                + '</tr>';
        }).join('');
        bindExpandButtons(tbody);
    }

    function renderNextTable(nextPatterns) {
        var tbody = document.getElementById('next-body');
        tbody.innerHTML = nextPatterns.map(function(ng) {
            var nextBadge = ng.next === 'HOME'
                ? '<span class="scorer-h next-badge-home">HOME</span>'
                : '<span class="scorer-a next-badge-away">AWAY</span>';
            return '<tr>'
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

    function bindExpandButtons(root) {
        root.querySelectorAll('.expand-btn[data-pid]').forEach(function(btn) {
            btn.addEventListener('click', function() { toggle(this.dataset.pid); });
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

    function matchKey(m) {
        return (m.homeTeam || '') + '|' + (m.awayTeam || '') + '|' + (m.league || '');
    }

    function updateHtMemory(matches) {
        for (var i = 0; i < matches.length; i++) {
            var m = matches[i];
            var key = matchKey(m);
            var s = parseStatus(m.status);
            var h = parseInt(m.homeScore) || 0;
            var a = parseInt(m.awayScore) || 0;
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

    function renderLiveCards(matches) {
        var container = document.getElementById('live-cards');
        if (!matches || !matches.length) {
            container.innerHTML = '<div class="live-empty">Tidak ada match live saat ini.</div>';
            return;
        }
        var liveMatches = matches.filter(function(m) {
            var s = parseStatus(m.status);
            return s.half === '1H' || s.half === '2H';
        });
        if (!liveMatches.length) {
            container.innerHTML = '<div class="live-empty">Tidak ada match aktif (1H/2H).</div>';
            return;
        }
        var html = '';
        for (var i = 0; i < liveMatches.length; i++) {
            var m = liveMatches[i];
            var lg = getLeagueTypeJS(m.league);
            var s = parseStatus(m.status);
            var h = parseInt(m.homeScore) || 0;
            var a = parseInt(m.awayScore) || 0;
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
                signals = lg ? evaluateSignalsForMatch(lg, htH, htA, null, h, a, s.min, 'ht') : [];
            } else {
                signals = lg ? evaluateSignalsForMatch(lg, h, a, s.min, h, a, s.min, '1h') : [];
            }
            var hasSignal = signals.length > 0;
            var signalHtml = signals.map(function(sig) {
                return '<div class="signal-tag"><span class="pid">' + sig.id + '</span>' + sig.label + '</div>';
            }).join('');
            var halfBadge = phase2H
                ? '<span class="half-badge-2h">2H ' + s.min + "\u2019</span>"
                : '<span class="half-badge-1h">\u25CF 1H ' + s.min + "\u2019</span>";
            var lgLabel = lg ? '<span class="league-tag">[' + lg + ']</span>' : '<span class="league-unknown">[league?]</span>';
            html += '<div class="live-card ' + (hasSignal ? 'has-signal' : '') + '">'
                + '<div class="match-name">' + escHtml(m.homeTeam) + ' vs ' + escHtml(m.awayTeam) + '</div>'
                + '<div class="match-meta">' + lgLabel + ' ' + halfBadge + (phase2H && htLabel ? ' &nbsp;|&nbsp; <span class="ht-meta">' + htLabel + '</span>' : '') + '</div>'
                + '<div class="score-box">' + h + ' - ' + a + '</div>'
                + '<div class="signals">'
                + (signalHtml || '<span style="color:var(--text-muted);font-size:0.75rem;">Tidak ada signal pattern</span>')
                + '</div></div>';
        }
        container.innerHTML = html;
    }

    async function fetchLiveData() {
        try {
            var resp = await fetch('http://127.0.0.1:5000/api/live-data', { signal: AbortSignal.timeout(3000) });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            var data = await resp.json();
            document.getElementById('live-api-badge').textContent = 'API Online';
            document.getElementById('live-api-badge').className = 'api-online';
            document.getElementById('btn-start-api').style.display = 'none';
            document.getElementById('btn-stop-api').style.display = 'inline-block';
            document.getElementById('live-last-update').textContent = 'Update: ' + new Date().toLocaleTimeString();
            updateHtMemory(data.matches || []);
            renderLiveCards(data.matches || []);
        } catch(e) {
            document.getElementById('live-api-badge').textContent = 'API Offline';
            document.getElementById('live-api-badge').className = 'api-offline';
            document.getElementById('btn-start-api').style.display = 'inline-block';
            document.getElementById('btn-stop-api').style.display = 'none';
            document.getElementById('live-last-update').textContent = '';
            document.getElementById('live-cards').innerHTML = '<div class="live-empty">API tidak aktif \u2014 klik \u25B6 Jalankan API</div>';
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

    buildPatternData();

    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closePanel(); });
    bindExpandButtons(document);

    var saved = sessionStorage.getItem('openPanel');
    if (saved) toggle(saved);

    setInterval(updateCountdown, 1000);
    fetchLiveData();
    setInterval(fetchLiveData, 5000);
})();
