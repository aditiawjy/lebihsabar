<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pattern Accuracy Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #0f1117; color: #e1e4e8; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { text-align: center; margin-bottom: 8px; font-size: 1.8rem; color: #58a6ff; }
        .subtitle { text-align: center; color: #8b949e; margin-bottom: 24px; font-size: 0.9rem; }
        .stats-bar { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; margin-bottom: 24px; }
        .stat-card { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 12px 20px; text-align: center; min-width: 140px; }
        .stat-card .value { font-size: 1.5rem; font-weight: 700; color: #58a6ff; }
        .stat-card .label { font-size: 0.75rem; color: #8b949e; margin-top: 4px; }

        .section { margin-bottom: 32px; }
        .section h2 { font-size: 1.2rem; margin-bottom: 12px; color: #c9d1d9; border-bottom: 1px solid #30363d; padding-bottom: 8px; }

        table { width: 100%; border-collapse: collapse; background: #161b22; border-radius: 8px; overflow: hidden; }
        th { background: #1c2129; color: #8b949e; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 10px 12px; text-align: left; }
        td { padding: 10px 12px; border-top: 1px solid #21262d; font-size: 0.85rem; }
        tr:hover td { background: #1c2129; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
        .badge-green { background: #238636; color: #fff; }
        .badge-yellow { background: #9e6a03; color: #fff; }
        .badge-red { background: #da3633; color: #fff; }
        .pct { font-weight: 700; }
        .pct-high { color: #3fb950; }
        .pct-mid { color: #d29922; }
        .pct-low { color: #f85149; }

        .detail-table { font-size: 0.8rem; }
        .detail-table td { padding: 6px 10px; }
        .goal-seq { font-family: 'Cascadia Code', 'Fira Code', monospace; font-size: 0.75rem; color: #79c0ff; }
        .scorer-h { color: #3fb950; font-weight: 600; }
        .scorer-a { color: #f85149; font-weight: 600; }

        .filter-bar { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; align-items: center; }
        .filter-bar select, .filter-bar input { background: #0d1117; border: 1px solid #30363d; color: #e1e4e8; padding: 6px 10px; border-radius: 6px; font-size: 0.85rem; }
        .filter-bar label { color: #8b949e; font-size: 0.8rem; }

        .last-update { text-align: center; color: #484f58; font-size: 0.75rem; margin-top: 24px; }
        .expand-btn { background: none; border: 1px solid #30363d; color: #58a6ff; padding: 2px 8px; border-radius: 4px; cursor: pointer; font-size: 0.75rem; }
        .expand-btn:hover { background: #1c2129; }
        .detail-row { display: none; }
        .detail-row.open { display: table-row; }
        .detail-row td { background: #0d1117; padding: 12px; }

        /* Slide panel */
        #slide-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; display: none; }
        #slide-panel {
            position: fixed; top: 0; right: -100%; width: min(1400px, 98vw); height: 100vh;
            background: #161b22; border-left: 1px solid #30363d;
            z-index: 100; overflow-y: auto; transition: right 0.3s ease;
            padding: 0;
        }
        #slide-panel.open { right: 0; }
        #slide-header {
            position: sticky; top: 0; background: #1c2129; border-bottom: 1px solid #30363d;
            padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; z-index: 1;
        }
        #slide-header h3 { font-size: 1rem; color: #58a6ff; margin: 0; }
        #slide-close {
            background: none; border: 1px solid #30363d; color: #8b949e;
            width: 28px; height: 28px; border-radius: 6px; cursor: pointer; font-size: 1rem;
            display: flex; align-items: center; justify-content: center;
        }
        #slide-close:hover { background: #da3633; color: #fff; border-color: #da3633; }
        #slide-body { padding: 16px 20px; }
        #slide-body table { font-size: 0.78rem; }
        #slide-body td { padding: 6px 8px; }
        #slide-body th { padding: 8px; }

        /* Live signal section */
        #live-section { margin-bottom: 28px; }
        #live-section h2 { font-size: 1.2rem; margin-bottom: 12px; color: #c9d1d9; border-bottom: 1px solid #30363d; padding-bottom: 8px; display: flex; align-items: center; gap: 10px; }
        .live-dot { width: 8px; height: 8px; border-radius: 50%; background: #3fb950; display: inline-block; animation: pulse 1.4s infinite; }
        @keyframes pulse { 0%,100%{opacity:1;} 50%{opacity:0.3;} }
        #live-status-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; font-size: 0.8rem; color: #8b949e; }
        #live-api-badge { padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: 600; }
        .api-online { background: #1a3a22; color: #3fb950; border: 1px solid #238636; }
        .api-offline { background: #3a1a1a; color: #f85149; border: 1px solid #da3633; }
        #live-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 12px; }
        .live-card {
            background: #161b22; border: 1px solid #30363d; border-radius: 10px;
            padding: 14px 16px; position: relative; overflow: hidden;
        }
        .live-card.has-signal { border-color: #2ea043; box-shadow: 0 0 0 1px #238636 inset; }
        .live-card .match-name { font-weight: 600; font-size: 0.9rem; color: #e1e4e8; margin-bottom: 4px; }
        .live-card .match-meta { font-size: 0.75rem; color: #8b949e; margin-bottom: 8px; }
        .live-card .score-box { display: inline-block; background: #0d1117; border: 1px solid #30363d; border-radius: 6px; padding: 4px 12px; font-size: 1.1rem; font-weight: 700; color: #58a6ff; letter-spacing: 2px; margin-bottom: 10px; }
        .live-card .signals { display: flex; flex-direction: column; gap: 5px; }
        .signal-tag { display: inline-flex; align-items: center; gap: 6px; background: #12261e; border: 1px solid #238636; color: #3fb950; border-radius: 6px; padding: 3px 10px; font-size: 0.75rem; font-weight: 600; }
        .signal-tag .pid { color: #79c0ff; margin-right: 2px; }
        .live-empty { color: #484f58; font-size: 0.85rem; padding: 16px 0; text-align: center; }
        #live-last-update { font-size: 0.72rem; color: #484f58; }
    </style>
</head>
<body>
<div id="slide-overlay" onclick="closePanel()"></div>
<div id="slide-panel">
    <div id="slide-header">
        <h3 id="slide-title"></h3>
        <button id="slide-close" onclick="closePanel()">✕</button>
    </div>
    <div id="slide-body"></div>
</div>

<div class="container">
    <h1>Pattern Accuracy Dashboard</h1>
    <p class="subtitle">Analisis pola gol 1H berdasarkan data goal_log.csv</p>
    <div id="update-indicator" style="text-align:center; margin-bottom:16px;">
        <span class="badge badge-green" id="update-status">● LIVE</span>
        <span style="color:#8b949e; font-size:0.8rem; margin-left:8px;" id="update-time"></span>
        <button onclick="location.reload()" style="margin-left:12px; background:#21262d; border:1px solid #30363d; color:#58a6ff; padding:4px 12px; border-radius:6px; cursor:pointer; font-size:0.8rem;">↻ Refresh</button>
    </div>

<?php
date_default_timezone_set('Asia/Jakarta');
$csvFile = __DIR__ . '/goal_log.csv';
$csvTime = filemtime($csvFile);

$rows = [];
$fh = fopen($csvFile, 'r');
fgetcsv($fh); // skip header
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 7) continue;
    $rows[] = $row;
}
fclose($fh);

require_once __DIR__ . '/pattern_snapshot.php';

function parseGoals($gs) {
    $goals = [];
    $parts = explode('|', $gs);
    foreach ($parts as $part) {
        $part = trim($part);
        if (preg_match('/(1H|2H)\s+(\d+)\'\s*\((\d+)-(\d+)\)/', $part, $m)) {
            $goals[] = ['half' => $m[1], 'min' => (int)$m[2], 'home' => (int)$m[3], 'away' => (int)$m[4]];
        }
    }
    return $goals;
}

function getLeagueType($s) {
    if (strpos($s, '15 Mins') !== false) return '15min';
    if (strpos($s, '16 Mins') !== false) return '16min';
    if (strpos($s, '20 Mins') !== false) return '20min';
    return 'other';
}

function getH1Scorers($h1) {
    $scorers = [];
    $ph = 0; $pa = 0;
    foreach ($h1 as $g) {
        if ($g['home'] > $ph) $scorers[] = 'H';
        if ($g['away'] > $pa) $scorers[] = 'A';
        $ph = $g['home']; $pa = $g['away'];
    }
    return $scorers;
}

function countSwitches($scorers) {
    $sw = 0;
    for ($i = 1; $i < count($scorers); $i++) {
        if ($scorers[$i] !== $scorers[$i-1]) $sw++;
    }
    return $sw;
}

function maxGap($goals) {
    if (count($goals) < 2) return 0;
    $max = 0;
    for ($i = 1; $i < count($goals); $i++) {
        $gap = $goals[$i]['min'] - $goals[$i-1]['min'];
        if ($gap > $max) $max = $gap;
    }
    return $max;
}

function minGap($goals) {
    if (count($goals) < 2) return 99;
    $min = 99;
    for ($i = 1; $i < count($goals); $i++) {
        $gap = $goals[$i]['min'] - $goals[$i-1]['min'];
        if ($gap < $min) $min = $gap;
    }
    return $min;
}

function maxRun($scorers) {
    if (count($scorers) === 0) return 0;
    $max = 1; $cur = 1;
    for ($i = 1; $i < count($scorers); $i++) {
        $cur = ($scorers[$i] === $scorers[$i-1]) ? $cur + 1 : 1;
        if ($cur > $max) $max = $cur;
    }
    return $max;
}

function allGapsGe($goals, $min) {
    for ($i = 1; $i < count($goals); $i++) {
        if (($goals[$i]['min'] - $goals[$i-1]['min']) < $min) return false;
    }
    return true;
}

function scorerHtml($s) {
    return $s === 'H' ? '<span class="scorer-h">H</span>' : '<span class="scorer-a">A</span>';
}

// Parse all matches
$matches = [];
foreach ($rows as $row) {
    $goals = parseGoals($row[4]);
    try { $fh_score = (int)$row[5]; $fa_score = (int)$row[6]; } catch (Exception $e) { continue; }
    $h1 = array_values(array_filter($goals, fn($g) => $g['half'] === '1H'));
    $h2 = array_values(array_filter($goals, fn($g) => $g['half'] === '2H'));
    $sh = end($h1) ?: null;
    $league = getLeagueType($row[1]);
    $h1s = getH1Scorers($h1);

    $matches[] = [
        'home' => $row[2], 'away' => $row[3],
        'league' => $league,
        'h1' => $h1,
        'h1c' => count($h1), 'h2c' => count($h2),
        'sc_h' => $sh ? $sh['home'] : 0,
        'sc_a' => $sh ? $sh['away'] : 0,
        'h1_first' => $h1[0]['min'] ?? -1,
        'h1_last' => end($h1)['min'] ?? -1,
        'fh' => $fh_score, 'fa' => $fa_score,
        'h1s' => $h1s,
        'switches' => countSwitches($h1s),
        'max_gap' => maxGap($h1),
        'min_gap' => minGap($h1),
        'max_run' => maxRun($h1s),
        'all_gaps_ge3' => allGapsGe($h1, 3),
        'all_gaps_ge5' => allGapsGe($h1, 5),
        'h2' => $h2,
        'h2_first_min' => count($h2) ? $h2[0]['min'] : -1,
        'has_late' => count(array_filter($h2, fn($g) => $g['min'] >= 7)) > 0,
        'next_goal' => (function($h2, $sc_h, $sc_a) {
            if (!count($h2)) return null;
            if ($h2[0]['home'] > $sc_h) return 'H';
            if ($h2[0]['away'] > $sc_a) return 'A';
            return null;
        })($h2, $sh ? $sh['home'] : 0, $sh ? $sh['away'] : 0),
        'h2_eq_min' => (function($h2) {
            foreach ($h2 as $g) { if ($g['home'] == $g['away']) return $g['min']; }
            return -1;
        })($h2),
    ];
}

// P2: Diff 2+ & last 1H mnt 7' & all gaps >= 3
$p2 = array_filter($matches, fn($m) => $m['h1c'] >= 2 && abs($m['sc_h']-$m['sc_a']) >= 2 && $m['h1_last'] == 7 && $m['all_gaps_ge3'] && $m['max_run'] <= 2);
// P3: AH seri 1-1, gap >= 3, 15/16min
$p3 = array_filter($matches, fn($m) => in_array($m['league'],['15min','16min']) && $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['h1s']==['A','H'] && ($m['h1'][1]['min']-$m['h1'][0]['min']) >= 3);
// P6: Seri 1-1 + equalizer at mnt 7'
$p6 = array_filter($matches, fn($m) => $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['h1_last']==7);
// P7: Seri 1-1 + gap >= 5 + first goal != mnt 1 (100%)
$p7 = array_filter($matches, fn($m) => $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['max_gap']>=5 && $m['h1_first']!==1);
// P9: AH seri 1-1 + gap >= 5 + first goal != mnt 1 (100%)
$p9 = array_filter($matches, fn($m) => $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['h1s']==['A','H'] && $m['max_gap']>=5 && $m['h1_first']!==1);
// P10: 0-0 di 1H (skip)
$p10 = array_filter($matches, fn($m) => $m['h1c'] == 0);
// P29: Balas >=2x + last mnt >=6 + min_gap>=1 (semua league)
$p29 = array_filter($matches, fn($m) => $m['switches'] >= 2 && $m['h1_last'] >= 6 && $m['min_gap'] >= 1 && ($m['h1_last']-$m['h1_first']) >= 6);
// P12: Total 1H goals >= 4
$p12 = array_filter($matches, fn($m) => $m['h1c'] >= 4 && ($m['h1_last']-$m['h1_first']) >= 6 && $m['min_gap'] >= 1 && $m['h1_last'] <= 9 && $m['h1_first'] >= 1);
// P13: First 0-2' + last 7'+
$p13 = array_filter($matches, fn($m) => $m['h1c']>=2 && $m['h1_first']<=2 && $m['h1_last']===7 && abs($m['sc_h']-$m['sc_a'])<=2 && $m['min_gap']>=2);
// P14: Seri + gap >= 4 + span >= 5 + first goal != mnt 1 (100%)
$p14 = array_filter($matches, fn($m) => $m['h1c']>=2 && $m['sc_h']==$m['sc_a'] && $m['sc_h']>0 && $m['max_gap']>=4 && ($m['h1_last']-$m['h1_first'])>=5 && $m['h1_first']!==1);
// P15: HT 2-2
$p15 = array_filter($matches, fn($m) => $m['sc_h']==2 && $m['sc_a']==2 && $m['max_gap']<=4);
// P16: Last gol 1H mnt 6 atau 7, league 16min
$p16 = array_filter($matches, fn($m) => $m['league']==='16min' && ($m['h1_last']==6 || $m['h1_last']==7) && ($m['h1_last']-$m['h1_first'])>=3);
// P17: First 1H mnt 1-2 + last mnt 7
$p17 = array_filter($matches, fn($m) => $m['h1c']>=2 && $m['h1_first']>=1 && $m['h1_first']<=2 && $m['h1_last']==7 && $m['max_gap']>=2 && $m['min_gap']>=2);
// P18: Span 1H >= 6 mnt, gol 1H >= 3, selisih HT <= 2
$p18 = array_filter($matches, fn($m) => $m['h1c']>=3 && ($m['h1_last']-$m['h1_first'])>=6 && abs($m['sc_h']-$m['sc_a'])<=2 && $m['max_run']<=2 && $m['switches']>=2);
// P19: Last gol 1H mnt 3-4, last scorer HOME, 20min
$p19 = array_filter($matches, fn($m) => $m['league']==='20min' && in_array($m['h1_last'],[3,4]) && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H' && ($m['h1c']===1 || $m['max_gap']>=2));
// P20: Last gol 1H mnt 3, last scorer AWAY, 16min
$p20 = array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1_last']===3 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A');
// P21: Last gol 1H mnt 5, last scorer AWAY, 15min, max_gap>=2 + sw>=1 + (n1h>=3 atau AWAY unggul HT)
$p21 = array_filter($matches, fn($m) => $m['league']==='15min' && $m['h1_last']===5 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && $m['max_gap']>=2 && ($m['h1c']>=3 || $m['sc_a']>$m['sc_h']) && $m['switches']>=1);
// P22: Away menang HT, 16min, gol>=2 atau selisih>=2
$p22 = array_filter($matches, fn($m) => $m['league']==='16min' && $m['sc_a'] > $m['sc_h'] && ($m['h1c']>=2 || ($m['sc_a']-$m['sc_h'])>=2));
// P23: 1 gol di 1H, mnt pertama >= 3, 16min
$p23 = array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1c']===1 && $m['h1_first']>=3 && count($m['h1s'])>0 && $m['h1s'][0]==='H');
// P24: Team HOME tertentu di 15min (Arminia Bielefeld, CA Osasuna, FC Koln, etc)
$p24_teams = ['Arminia Bielefeld (V)','CA Osasuna (V)','FC Koln (V)','Leicester City (V)','Manchester United (V)','Borussia Dortmund (V)','Liverpool (V)'];
$p24 = array_filter($matches, fn($m) => $m['league']==='15min' && in_array(trim($m['home']), $p24_teams) && $m['h1c']>=1 && $m['h1_last']>=4 && abs($m['sc_h']-$m['sc_a'])<=1 && $m['sc_h']>=1 && $m['h1_first']>=1);
// P25: Team AWAY tertentu (Real Sociedad, France, Netherlands, Ukraine)
$p25_teams = ['Real Sociedad (V)','France (V)','Netherlands (V)','Ukraine (V)'];
$p25 = array_filter($matches, fn($m) => in_array(trim($m['away']), $p25_teams) && $m['h1_last']>=2 && abs($m['sc_h']-$m['sc_a'])<=1 && ($m['h1_last']-$m['h1_first'])!==2);
// P26: HT total ganjil, league 16min, last mnt >=6
$p26 = array_filter($matches, fn($m) => $m['league']==='16min' && ($m['sc_h']+$m['sc_a'])%2===1 && $m['h1_last']>=6);
// P27: Last scorer AWAY, league 16min, max_gap>=3
$p27 = array_filter($matches, fn($m) => $m['league']==='16min' && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A' && $m['max_gap']>=3);
// P28: Croatia atau France terlibat (home atau away)
$p28_teams = ['Croatia (V)','France (V)'];
$p28 = array_filter($matches, fn($m) => (in_array(trim($m['home']), $p28_teams) || in_array(trim($m['away']), $p28_teams)) && $m['h1_last']>=3);
// P30: Total gol ganjil + last mnt >=6, 16min
$p30 = array_filter($matches, fn($m) => $m['league']==='16min' && ($m['sc_h']+$m['sc_a'])%2===1 && $m['h1_last']>=6);
// P32: Span >=8 mnt + gol 1H >=2, 20min
$p32 = array_filter($matches, fn($m) => $m['league']==='20min' && $m['h1c']>=2 && ($m['h1_last']-$m['h1_first'])>=8);
// P33: Total gol 1H >=4, 15min
$p33 = array_filter($matches, fn($m) => $m['league']==='15min' && $m['h1c']>=4 && abs($m['sc_h']-$m['sc_a'])<=2 && $m['min_gap']>=1);
// P34: First AWAY + last HOME + span >=5, 15min
$p34 = array_filter($matches, fn($m) => $m['league']==='15min' && count($m['h1s'])>0 && $m['h1s'][0]==='A' && $m['h1s'][count($m['h1s'])-1]==='H' && ($m['h1_last']-$m['h1_first'])>=5);
// P35: Team AWAY: Mexico / Belgium / Germany / Man City
$p35_teams = ['Mexico (V)','Belgium (V)','Germany (V)','Manchester City (V)'];
$p35 = array_filter($matches, fn($m) => in_array(trim($m['away']), $p35_teams) && $m['h1_last']>=3 && abs($m['sc_h']-$m['sc_a'])<=1);
// P36: Team HOME: PSG / Germany / Mexico / Belgium + gol>=2 + span>=1
$p36_teams = ['Paris Saint Germain (V)','Germany (V)','Mexico (V)','Belgium (V)'];
$p36 = array_filter($matches, fn($m) => in_array(trim($m['home']), $p36_teams) && $m['h1c']>=2 && ($m['h1_last']-$m['h1_first'])>=1);
// P37: First AWAY + last AWAY + span >= 6 mnt + (n1h>=3 or lm<=7) (100%)
$p37 = array_filter($matches, fn($m) => $m['h1c']>=2 && count($m['h1s'])>0 && $m['h1s'][0]==='A' && $m['h1s'][count($m['h1s'])-1]==='A' && ($m['h1_last']-$m['h1_first'])>=6 && ($m['h1c']>=3 || $m['h1_last']<=7));
// P39: Gol 1H >= 3 + span >= 7 mnt, 20min
$p39 = array_filter($matches, fn($m) => $m['league']==='20min' && $m['h1c']>=3 && ($m['h1_last']-$m['h1_first'])>=7);

// ===== NEXT GOAL PATTERNS =====
// NG1: HT 1-0 + gol terakhir mnt 3 → next HOME 87%
$ng1 = array_filter($matches, fn($m) => $m['h1c']>=1 && $m['sc_h']==1 && $m['sc_a']==0 && $m['h1_last']==3 && $m['h2c']>0);
// NG2: HOME unggul HT + gol terakhir mnt 3 → next HOME 82%
$ng2 = array_filter($matches, fn($m) => $m['h1c']>=1 && $m['sc_h']>$m['sc_a'] && $m['h1_last']==3 && $m['h2c']>0);
// NG3: HT 1-0, league 16min → next HOME 74%
$ng3 = array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1c']>=1 && $m['sc_h']==1 && $m['sc_a']==0 && $m['h2c']>0);
// NG4: HT 1-0 + gol mnt 3 + league 16min → next HOME 100%
$ng4 = array_filter($matches, fn($m) => $m['league']==='16min' && $m['sc_h']==1 && $m['sc_a']==0 && $m['h1_last']==3 && $m['h2c']>0);
// NG5: Seri 1-1 + scorer H dulu A terakhir (sc=HA) + lm=7 → next AWAY 100%
$ng5 = array_filter($matches, fn($m) => $m['sc_h']==1 && $m['sc_a']==1 && $m['h1c']==2 && count($m['h1s'])>=2 && $m['h1s'][0]==='H' && $m['h1s'][count($m['h1s'])-1]==='A' && $m['h1_last']==7 && $m['h2c']>0);
// NG6: Seri 1-1 + lm=7 + league 20min → next AWAY 90%
$ng6 = array_filter($matches, fn($m) => $m['league']==='20min' && $m['sc_h']==1 && $m['sc_a']==1 && $m['h1_last']==7 && $m['h2c']>0);

$next_patterns = [
    ['id'=>'NG1','label'=>'HT 1-0 + gol terakhir mnt 3','next'=>'HOME','data'=>$ng1],
    ['id'=>'NG2','label'=>'HOME unggul HT + gol terakhir mnt 3','next'=>'HOME','data'=>$ng2],
    ['id'=>'NG3','label'=>'HT 1-0, league 16min','next'=>'HOME','data'=>$ng3],
    ['id'=>'NG4','label'=>'HT 1-0 + gol mnt 3, league 16min','next'=>'HOME','data'=>$ng4],
    ['id'=>'NG5','label'=>'Seri 1-1 + scorer HA + gol terakhir mnt 7','next'=>'AWAY','data'=>$ng5],
    ['id'=>'NG6','label'=>'Seri 1-1 + gol terakhir mnt 7, league 20min','next'=>'AWAY','data'=>$ng6],
];


$patterns = [
    ['id'=>'P2','label'=>'Selisih 2+ & last mnt 7\' & gap >=3 & max_run<=2','data'=>$p2],
    ['id'=>'P3','label'=>'AH gap >=3 mnt, 15min/16min','data'=>$p3],
    ['id'=>'P6','label'=>'Seri 1-1 + gol penyama mnt 7\'','data'=>$p6],
    ['id'=>'P7','label'=>'Seri 1-1 + gap >= 5 mnt + first goal != mnt 1','data'=>$p7],
    ['id'=>'P9','label'=>'AH seri 1-1 + gap >= 5 mnt + first goal != mnt 1','data'=>$p9],
    ['id'=>'P12','label'=>'Total gol 1H >= 4 + span >= 6 mnt + min_gap>=1 + lm<=9 + fm>=1','data'=>$p12],
    ['id'=>'P13','label'=>'First 0-2\' + last 7\' + selisih <=2 + min_gap>=2','data'=>$p13],
    ['id'=>'P14','label'=>'Seri + gap >= 4 mnt + span >= 5 mnt + first goal != mnt 1','data'=>$p14],
    ['id'=>'P15','label'=>'HT 2-2 + max_gap<=4','data'=>$p15],
    ['id'=>'P16','label'=>'Last gol 1H mnt 6-7 + span >=3, 16min','data'=>$p16],
    ['id'=>'P17','label'=>'First 1H mnt 1-2 + last mnt 7 + min_gap>=2','data'=>$p17],
    ['id'=>'P18','label'=>'Span 1H >= 6 mnt + gol 1H >= 3 + selisih <=2 + max_run <=2 + sw>=2','data'=>$p18],
    ['id'=>'P19','label'=>'Last gol 1H mnt 3-4, last HOME, 20min, gap>=2','data'=>$p19],
    ['id'=>'P20','label'=>'Last gol 1H mnt 3, last AWAY, 16min','data'=>$p20],
    ['id'=>'P21','label'=>'Last gol 1H mnt 5, last AWAY, 15min, max_gap>=2 + sw>=1 (n1h>=3 atau AWAY unggul)','data'=>$p21],
    ['id'=>'P22','label'=>'Away menang HT, 16min, gol>=2 atau unggul>=2','data'=>$p22],
    ['id'=>'P23','label'=>'1 gol 1H HOME, mnt pertama >=3, 16min','data'=>$p23],
    ['id'=>'P24','label'=>'HOME 15min: Arminia Bielefeld / CA Osasuna / FC Koln / Leicester City / Man United / Dortmund / Liverpool (lm>=4, selisih <=1, HOME cetak >=1, fm>=1)','data'=>$p24],
    ['id'=>'P25','label'=>'AWAY: Real Sociedad / France / Netherlands / Ukraine (lm>=2, selisih<=1)','data'=>$p25],
    ['id'=>'P26','label'=>'HT total ganjil (1,3,5...), 16min, last mnt >=6','data'=>$p26],
    ['id'=>'P27','label'=>'Gol terakhir 1H dicetak AWAY, 16min, max_gap>=3','data'=>$p27],
    ['id'=>'P28','label'=>'Croatia atau France main (home atau away) + last mnt >=3','data'=>$p28],
    ['id'=>'P29','label'=>'Balas >=2x + last mnt >=6 + min_gap>=1 + span>=6 (semua league)','data'=>$p29],
    ['id'=>'P30','label'=>'Total gol ganjil + last mnt >=6, 16min','data'=>$p30],
    ['id'=>'P32','label'=>'Span 1H >=8 mnt + gol >=2, 20min','data'=>$p32],
    ['id'=>'P33','label'=>'Total gol 1H >=4 + selisih HT <=2 + min_gap>=1, 15min','data'=>$p33],
    ['id'=>'P34','label'=>'First AWAY + last HOME + span >=5, 15min','data'=>$p34],
    ['id'=>'P35','label'=>'AWAY: Mexico / Belgium / Germany / Man City (last mnt >=3 + selisih HT <=1)','data'=>$p35],
    ['id'=>'P36','label'=>'HOME: PSG / Germany / Mexico / Belgium (gol>=2, span>=1)','data'=>$p36],
    ['id'=>'P37','label'=>'First & last scorer AWAY + span >=6 mnt (n1h>=3 atau lm<=7)','data'=>$p37],
    ['id'=>'P39','label'=>'Gol 1H >=3 + span >=7 mnt, 20min','data'=>$p39],
];

$totalMatches = count($matches);
$with2h = count(array_filter($matches, fn($m) => $m['h2c'] > 0));

// Build current snapshot data & compare with 1h ago
$currentSnap = [];
foreach ($patterns as $p) {
    $t = count($p['data']);
    $h = count(array_filter($p['data'], fn($m) => $m['h2c'] > 0));
    $currentSnap[$p['id']] = ['t' => $t, 'h' => $h];
}
foreach ($next_patterns as $ng) {
    $tgt = $ng['next'];
    $t = count($ng['data']);
    $h = count(array_filter($ng['data'], fn($m) => ($tgt==='HOME' ? $m['next_goal']==='H' : $m['next_goal']==='A')));
    $currentSnap[$ng['id']] = ['t' => $t, 'h' => $h];
}
$oldSnap = getSnapshotHourAgo();
$oldSnapData = $oldSnap ? $oldSnap['data'] : [];
$oldSnapTime = $oldSnap ? $oldSnap['time'] : null;
saveSnapshot($currentSnap);

echo '<div class="stats-bar">';
echo '<div class="stat-card"><div class="value">'.$totalMatches.'</div><div class="label">Total Matches</div></div>';
echo '<div class="stat-card"><div class="value">'.count($patterns).'</div><div class="label">Patterns</div></div>';
echo '<div class="stat-card"><div class="value">'.date('d/m H:i', $csvTime).'</div><div class="label">Last Update</div></div>';
echo '</div>';

// Live match signal section (populated by JS)
echo '<div id="live-section">
  <h2><span class="live-dot"></span> Live Match Signal</h2>
  <div id="live-status-bar">
    <span id="live-api-badge" class="api-offline">API Offline</span>
    <button id="btn-start-api" onclick="startApiServer()" style="background:#1a3a22;border:1px solid #238636;color:#3fb950;padding:3px 12px;border-radius:6px;cursor:pointer;font-size:0.78rem;display:none;">▶ Jalankan API</button>
    <button id="btn-stop-api" onclick="stopApiServer()" style="background:#3a1a1a;border:1px solid #da3633;color:#f85149;padding:3px 12px;border-radius:6px;cursor:pointer;font-size:0.78rem;display:none;">■ Stop API</button>
    <span id="live-last-update"></span>
  </div>
  <div id="live-cards"><div class="live-empty">Menunggu data dari extension...</div></div>
</div>';

// Sort patterns by accuracy desc, then by sample size desc
usort($patterns, function($a, $b) {
    $ta = count($a['data']); $tb = count($b['data']);
    $pa = $ta > 0 ? count(array_filter($a['data'], fn($m) => $m['h2c'] > 0)) / $ta : 0;
    $pb = $tb > 0 ? count(array_filter($b['data'], fn($m) => $m['h2c'] > 0)) / $tb : 0;
    if ($pb != $pa) return $pb <=> $pa;
    return $tb - $ta;
});

// Summary table
$snapMinsAgo = $oldSnapTime ? round((time() - $oldSnapTime) / 60) : null;
$snapLabel = $oldSnapTime ? date('H:i', $oldSnapTime) . '→' . date('H:i') . " ({$snapMinsAgo} mnt lalu)" : null;
echo '<div class="section"><h2>Summary Akurasi</h2>';
echo '<table><tr><th>#</th><th>Pattern</th><th>Record</th><th>Akurasi</th><th>Status</th>';
echo '<th style="color:#8b949e;white-space:nowrap;">' . ($snapLabel ? "+Sample ($snapLabel)" : '+Sample') . '</th>';
echo '<th></th></tr>';
foreach ($patterns as $p) {
    $total = count($p['data']);
    $has2h = count(array_filter($p['data'], fn($m) => $m['h2c'] > 0));
    $pct = $total > 0 ? round($has2h/$total*100) : 0;
    $cls = $pct >= 95 ? 'pct-high' : ($pct >= 85 ? 'pct-mid' : 'pct-low');
    $badge = $pct >= 95 ? 'badge-green' : ($pct >= 85 ? 'badge-yellow' : 'badge-red');
    $status = $pct >= 95 ? 'EXCELLENT' : ($pct >= 85 ? 'GOOD' : 'WARNING');

    // Delta vs 1h ago
    $deltaHtml = '<span style="color:#484f58">—</span>';
    if ($oldSnapData && isset($oldSnapData[$p['id']])) {
        $old = $oldSnapData[$p['id']];
        $deltaT = $total - $old['t'];
        $deltaH = $has2h - $old['h'];
        if ($deltaT > 0) {
            $sign = $deltaH >= 0 ? '+' : '';
            $col = $deltaH > 0 ? '#3fb950' : ($deltaH < 0 ? '#f85149' : '#8b949e');
            $deltaHtml = "<span style=\"color:{$col};font-weight:600;\">+{$deltaT} sample ({$sign}{$deltaH} hit)</span>";
        } elseif ($deltaT == 0) {
            $deltaHtml = '<span style="color:#484f58">tidak berubah</span>';
        }
    }

    echo "<tr>";
    echo "<td><strong>{$p['id']}</strong></td>";
    echo "<td>{$p['label']}</td>";
    echo "<td>{$has2h}/{$total}</td>";
    echo "<td class=\"pct {$cls}\">{$pct}%</td>";
    echo "<td><span class=\"badge {$badge}\">{$status}</span></td>";
    echo "<td style=\"font-size:0.8rem;\">{$deltaHtml}</td>";
    echo "<td><button class=\"expand-btn\" onclick=\"toggle('{$p['id']}')\">Detail</button></td>";
    echo "</tr>";
}
echo '</table></div>';

// ===== NEXT GOAL TABLE =====
echo '<div class="section"><h2>Next Goal Pattern (Gol Pertama Babak 2)</h2>';
echo '<table><tr><th>#</th><th>Pattern</th><th>Prediksi Next Goal</th><th>Record</th><th>Akurasi</th><th>Status</th>';
echo '<th style="color:#8b949e;white-space:nowrap;">' . ($snapLabel ? "+Sample ($snapLabel)" : '+Sample') . '</th>';
echo '<th></th></tr>';
foreach ($next_patterns as $ng) {
    $total = count($ng['data']);
    $nh = count(array_filter($ng['data'], fn($m) => $m['next_goal']==='H'));
    $na = count(array_filter($ng['data'], fn($m) => $m['next_goal']==='A'));
    $tgt = $ng['next'];
    $hits = $tgt === 'HOME' ? $nh : $na;
    $pct = $total > 0 ? round($hits/$total*100) : 0;
    $cls = $pct >= 85 ? 'pct-high' : ($pct >= 75 ? 'pct-mid' : 'pct-low');
    $badge = $pct >= 85 ? 'badge-green' : ($pct >= 75 ? 'badge-yellow' : 'badge-red');
    $status = $pct >= 85 ? 'STRONG' : ($pct >= 75 ? 'GOOD' : 'WEAK');
    $nextBadge = $tgt === 'HOME'
        ? '<span class="scorer-h" style="padding:2px 10px;border-radius:4px;font-weight:700;">HOME</span>'
        : '<span class="scorer-a" style="padding:2px 10px;border-radius:4px;font-weight:700;">AWAY</span>';

    $deltaHtml = '<span style="color:#484f58">—</span>';
    if ($oldSnapData && isset($oldSnapData[$ng['id']])) {
        $old = $oldSnapData[$ng['id']];
        $deltaT = $total - $old['t'];
        $deltaH = $hits - $old['h'];
        if ($deltaT > 0) {
            $sign = $deltaH >= 0 ? '+' : '';
            $col = $deltaH > 0 ? '#3fb950' : ($deltaH < 0 ? '#f85149' : '#8b949e');
            $deltaHtml = "<span style=\"color:{$col};font-weight:600;\">+{$deltaT} sample ({$sign}{$deltaH} hit)</span>";
        } elseif ($deltaT == 0) {
            $deltaHtml = '<span style="color:#484f58">tidak berubah</span>';
        }
    }

    echo "<tr>";
    echo "<td><strong>{$ng['id']}</strong></td>";
    echo "<td>{$ng['label']}</td>";
    echo "<td>{$nextBadge}</td>";
    echo "<td>{$hits}/{$total} <span style='color:#484f58;font-size:0.75rem;'>(H:{$nh} A:{$na})</span></td>";
    echo "<td class=\"pct {$cls}\">{$pct}%</td>";
    echo "<td><span class=\"badge {$badge}\">{$status}</span></td>";
    echo "<td style=\"font-size:0.8rem;\">{$deltaHtml}</td>";
    echo "<td><button class=\"expand-btn\" onclick=\"toggle('{$ng['id']}')\">Detail</button></td>";
    echo "</tr>";
}
echo '</table></div>';

// Detail tables per pattern — stored as JS data for slide panel
echo '<script>const PATTERN_DATA = {};';
foreach ($patterns as $p) {
    $total = count($p['data']);
    $has2h = count(array_filter($p['data'], fn($m) => $m['h2c'] > 0));
    $pct = $total > 0 ? round($has2h/$total*100) : 0;
    $rows_html = '';
    foreach ($p['data'] as $m) {
        $seq = implode(' → ', array_map('scorerHtml', $m['h1s']));
        $timeline1h = '';
        foreach ($m['h1'] as $g) { $timeline1h .= "{$g['min']}' ({$g['home']}-{$g['away']})&nbsp;&nbsp;"; }
        $timeline2h = '';
        foreach ($m['h2'] as $g) { $timeline2h .= "{$g['min']}' ({$g['home']}-{$g['away']})&nbsp;&nbsp;"; }
        $has2hBadge = $m['h2c'] > 0 ? '<span class="badge badge-green">✓ 2H</span>' : '<span class="badge badge-red">✗ No 2H</span>';
        $home = htmlspecialchars($m['home']); $away = htmlspecialchars($m['away']);
        $rows_html .= "<tr><td>{$home} vs {$away}</td><td>{$m['league']}</td><td>{$m['sc_h']}-{$m['sc_a']}</td><td><strong>{$m['fh']}-{$m['fa']}</strong></td><td class=\"goal-seq\">{$timeline1h}</td><td class=\"goal-seq\">".($timeline2h ?: '<span style="color:#484f58">-</span>')."</td><td class=\"goal-seq\">{$seq}</td><td>{$has2hBadge}</td></tr>";
    }
    $id = $p['id']; $label = addslashes($p['label']);
    $table = addslashes('<table class="detail-table"><tr><th>Match</th><th>League</th><th>HT</th><th>FT</th><th>Timeline 1H</th><th>Timeline 2H</th><th>Sequence</th><th>2H?</th></tr>'.$rows_html.'</table>');
    echo "PATTERN_DATA['{$id}'] = { label: '{$label}', record: '{$has2h}/{$total}', pct: '{$pct}%', html: '{$table}' };";
}

// Next goal patterns detail
foreach ($next_patterns as $ng) {
    $total = count($ng['data']);
    $nh = count(array_filter($ng['data'], fn($m) => $m['next_goal']==='H'));
    $na = count(array_filter($ng['data'], fn($m) => $m['next_goal']==='A'));
    $tgt = $ng['next'];
    $hits = $tgt === 'HOME' ? $nh : $na;
    $pct = $total > 0 ? round($hits/$total*100) : 0;
    $rows_html = '';
    foreach ($ng['data'] as $m) {
        $seq = implode(' → ', array_map('scorerHtml', $m['h1s']));
        $timeline1h = '';
        foreach ($m['h1'] as $g) { $timeline1h .= "{$g['min']}' ({$g['home']}-{$g['away']})&nbsp;&nbsp;"; }
        $timeline2h = '';
        foreach ($m['h2'] as $g) { $timeline2h .= "{$g['min']}' ({$g['home']}-{$g['away']})&nbsp;&nbsp;"; }
        $ng_val = $m['next_goal'];
        if ($ng_val === 'H') $nextBadge = '<span class="scorer-h" style="padding:1px 8px;border-radius:3px;font-weight:700;">HOME</span>';
        elseif ($ng_val === 'A') $nextBadge = '<span class="scorer-a" style="padding:1px 8px;border-radius:3px;font-weight:700;">AWAY</span>';
        else $nextBadge = '<span style="color:#484f58">-</span>';
        $isHit = ($tgt === 'HOME' && $ng_val === 'H') || ($tgt === 'AWAY' && $ng_val === 'A');
        $rowStyle = $isHit ? '' : ' style="opacity:0.5"';
        $home = htmlspecialchars($m['home']); $away = htmlspecialchars($m['away']);
        $rows_html .= "<tr{$rowStyle}><td>{$home} vs {$away}</td><td>{$m['league']}</td><td>{$m['sc_h']}-{$m['sc_a']}</td><td>{$seq}</td><td class=\"goal-seq\">{$timeline1h}</td><td class=\"goal-seq\">".($timeline2h ?: '<span style="color:#484f58">-</span>')."</td><td>{$nextBadge}</td></tr>";
    }
    $id = $ng['id']; $label = addslashes($ng['label']);
    $table = addslashes('<table class="detail-table"><tr><th>Match</th><th>League</th><th>HT</th><th>Sequence 1H</th><th>Timeline 1H</th><th>Timeline 2H</th><th>Next Goal</th></tr>'.$rows_html.'</table>');
    echo "PATTERN_DATA['{$id}'] = { label: '{$label}', record: '{$hits}/{$total}', pct: '{$pct}%', html: '{$table}' };";
}
echo '</script>';

echo '<p class="last-update">CSV last modified: '.date('d/m/Y H:i:s', $csvTime).' | Total '.$totalMatches.' matches | Auto-refresh: 30s</p>';
echo '<script>document.getElementById("update-time").textContent = "Last: '.date('H:i:s', $csvTime).'";</script>';
?>

</div>
<script>
let activePanel = null;

function toggle(id) {
    if (activePanel === id) { closePanel(); return; }
    const d = PATTERN_DATA[id];
    if (!d) return;
    document.getElementById('slide-title').innerHTML = '<strong>' + id + '</strong>: ' + d.label + ' &nbsp;<span style="color:#8b949e;font-size:0.85rem;">' + d.record + ' = ' + d.pct + '</span>';
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

document.addEventListener('keydown', e => { if (e.key === 'Escape') closePanel(); });

// Restore panel after auto-refresh
const saved = sessionStorage.getItem('openPanel');
if (saved) toggle(saved);

// Auto-refresh every 30s, preserve open panel
let refreshCountdown = 30;
setInterval(() => {
    refreshCountdown--;
    if (refreshCountdown <= 0) location.reload();
}, 1000);

// =============================================
// LIVE MATCH SIGNAL
// =============================================

function getLeagueType(league) {
    if (!league) return null;
    const l = league.toLowerCase();
    if (l.includes('20 min') || l.includes('20min')) return '20min';
    if (l.includes('16 min') || l.includes('16min')) return '16min';
    if (l.includes('15 min') || l.includes('15min')) return '15min';
    return null;
}

function parseStatus(status) {
    // toleran: 1H/2H + spasi + angka + karakter apapun (', ′, ', mnt, dll)
    const m = String(status || '').trim().match(/^(1H|2H)\s+(\d+)/i);
    if (!m) return { half: null, min: -1 };
    return { half: m[1].toUpperCase(), min: parseInt(m[2], 10) };
}

// Track HT score: saat transisi ke 2H, simpan score saat itu sebagai HT
const htMemory = {}; // matchKey => {h, a}
const prevStateMemory = {}; // matchKey => {half, h, a}

function matchKey(m) {
    return (m.homeTeam || '') + '|' + (m.awayTeam || '') + '|' + (m.league || '');
}

function updateHtMemory(matches) {
    for (const m of matches) {
        const key = matchKey(m);
        const { half } = parseStatus(m.status);
        const h = parseInt(m.homeScore) || 0;
        const a = parseInt(m.awayScore) || 0;
        const prev = prevStateMemory[key];

        // Saat pertama kali lihat 2H, simpan score sebelumnya (= HT score)
        if (half === '2H' && prev && prev.half === '1H') {
            htMemory[key] = { h: prev.h, a: prev.a };
        }
        // Reset saat balik ke 1H (match baru)
        if (half === '1H' && prev && prev.half === '2H') {
            delete htMemory[key];
        }
        prevStateMemory[key] = { half, h, a };
    }
}

// Evaluate pattern signals dari skor HT + info 1H
function evaluateSignals(lg, htH, htA, curMin1H) {
    const signals = [];
    const total = htH + htA;
    const diff = Math.abs(htH - htA);

    // P10: 0-0 di HT
    if (htH === 0 && htA === 0) signals.push({ id: 'P10', label: '0-0 di 1H' });

    // P12: total gol 1H >= 4
    if (total >= 4) signals.push({ id: 'P12', label: 'Total gol >= 4' });

    // P15: HT 2-2
    if (htH === 2 && htA === 2) signals.push({ id: 'P15', label: 'HT 2-2' });


    // P7/P14: Seri 1-1 (jika seri, kemungkinan P7/P14/P6 — tidak bisa verif gap tanpa history)
    if (htH === htA && total >= 2) signals.push({ id: 'P7', label: 'HT Seri ' + htH + '-' + htA });

    // P22: Away menang HT, 16min
    if (lg === '16min' && htA > htH) signals.push({ id: 'P22', label: 'Away unggul HT, 16min' });

    // P2: selisih 2+
    if (diff >= 2) signals.push({ id: 'P2', label: 'HT selisih ' + diff + '+' });

    // P16: 16min league (ada gol di 1H)
    if (lg === '16min' && total > 0 && curMin1H !== null && curMin1H >= 6)
        signals.push({ id: 'P16', label: 'Last mnt 6+, 16min' });

    // P23: 1 gol 1H, 16min (info dari skor HT)
    if (lg === '16min' && total === 1) signals.push({ id: 'P23', label: '1 gol HT, 16min' });

    // P19: Home unggul, 20min
    if (lg === '20min' && htH > htA) signals.push({ id: 'P19', label: 'Home unggul HT, 20min' });

    // P21: Away score, 15min
    if (lg === '15min' && htA > htH) signals.push({ id: 'P21', label: 'Away unggul HT, 15min' });
    if (lg === '15min' && htH === htA && htA > 0) signals.push({ id: 'P21', label: 'Seri HT, 15min' });


    const seen = new Set();
    return signals.filter(s => { if (seen.has(s.id)) return false; seen.add(s.id); return true; });
}

// Evaluate signals saat masih 1H (real-time, skor berubah tiap gol)
function evaluateSignals1H(lg, h, a, curMin) {
    const signals = [];
    const total = h + a;
    const diff = Math.abs(h - a);

    if (h === 0 && a === 0) signals.push({ id: 'P10', label: '0-0 sedang berjalan' });
    if (total >= 4) signals.push({ id: 'P12', label: 'Sudah ' + total + ' gol!' });
    if (h === 2 && a === 2) signals.push({ id: 'P15', label: 'Seri 2-2' });
    if (h === 1 && a === 1 && curMin >= 7) signals.push({ id: 'P6', label: 'Seri 1-1, mnt 7+' });
    if (h === 1 && a === 1 && curMin >= 5) signals.push({ id: 'P7', label: 'Seri 1-1, mnt 5+' });
    if (lg === '16min' && a > h) signals.push({ id: 'P22', label: 'Away unggul, 16min' });
    if (lg === '16min' && curMin >= 6 && total > 0) signals.push({ id: 'P16', label: 'Mnt 6+, 16min' });
    if (lg === '16min' && total === 1 && curMin >= 3) signals.push({ id: 'P23', label: '1 gol mnt ' + curMin + ', 16min' });
    if (lg === '20min' && h > a) signals.push({ id: 'P19', label: 'Home unggul, 20min' });
    if (lg === '15min' && a >= h && total > 0 && curMin >= 4) signals.push({ id: 'P21', label: 'Away score mnt ' + curMin + ', 15min' });
    if (diff >= 2 && curMin >= 7) signals.push({ id: 'P2', label: 'Selisih ' + diff + '+, mnt 7+' });

    const seen = new Set();
    return signals.filter(s => { if (seen.has(s.id)) return false; seen.add(s.id); return true; });
}

function renderLiveCards(matches) {
    const container = document.getElementById('live-cards');
    if (!matches || !matches.length) {
        container.innerHTML = '<div class="live-empty">Tidak ada match live saat ini.</div>';
        return;
    }

    // Show 1H and 2H matches
    const liveMatches = matches.filter(m => {
        const { half } = parseStatus(m.status);
        return half === '1H' || half === '2H';
    });

    if (!liveMatches.length) {
        container.innerHTML = '<div class="live-empty">Tidak ada match aktif (1H/2H).</div>';
        return;
    }

    let html = '';
    for (const m of liveMatches) {
        const lg = getLeagueType(m.league);
        const { half, min: curMin } = parseStatus(m.status);
        const h = parseInt(m.homeScore) || 0;
        const a = parseInt(m.awayScore) || 0;
        const key = matchKey(m);

        let signals = [];
        let htLabel = '';
        let phase2H = false;

        if (half === '2H') {
            phase2H = true;
            const ht = htMemory[key];
            const htH = ht ? ht.h : h; // fallback ke current jika belum ada history
            const htA = ht ? ht.a : a;
            htLabel = `HT: ${htH}-${htA}`;
            signals = lg ? evaluateSignals(lg, htH, htA, null) : [];
        } else {
            signals = lg ? evaluateSignals1H(lg, h, a, curMin) : [];
        }

        const hasSignal = signals.length > 0;
        const signalHtml = signals.map(s =>
            `<div class="signal-tag"><span class="pid">${s.id}</span>${s.label}</div>`
        ).join('');

        const halfBadge = phase2H
            ? `<span style="color:#d29922;font-weight:700;">2H ${curMin}'</span>`
            : `<span style="color:#3fb950;font-weight:700;animation:pulse 1.4s infinite;display:inline-block;">● 1H ${curMin}'</span>`;

        const lgLabel = lg ? `<span style="color:#58a6ff;font-size:0.72rem;">[${lg}]</span>` : `<span style="color:#484f58;font-size:0.72rem;">[league?]</span>`;

        html += `<div class="live-card ${hasSignal ? 'has-signal' : ''}">
            <div class="match-name">${esc(m.homeTeam)} vs ${esc(m.awayTeam)}</div>
            <div class="match-meta">${lgLabel} ${halfBadge}${phase2H && htLabel ? ' &nbsp;|&nbsp; <span style="color:#8b949e;font-size:0.72rem;">' + htLabel + '</span>' : ''}</div>
            <div class="score-box">${h} - ${a}</div>
            <div class="signals">
                ${signalHtml || '<span style="color:#484f58;font-size:0.75rem;">Tidak ada signal pattern</span>'}
            </div>
        </div>`;
    }
    container.innerHTML = html;
}

function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

async function fetchLiveData() {
    try {
        const resp = await fetch('http://127.0.0.1:5000/api/live-data', { signal: AbortSignal.timeout(3000) });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();
        document.getElementById('live-api-badge').textContent = 'API Online';
        document.getElementById('live-api-badge').className = 'api-online';
        document.getElementById('btn-start-api').style.display = 'none';
        document.getElementById('btn-stop-api').style.display = 'inline-block';
        const now = new Date();
        document.getElementById('live-last-update').textContent = 'Update: ' + now.toLocaleTimeString();
        updateHtMemory(data.matches || []);
        renderLiveCards(data.matches || []);
    } catch(e) {
        document.getElementById('live-api-badge').textContent = 'API Offline';
        document.getElementById('live-api-badge').className = 'api-offline';
        document.getElementById('btn-start-api').style.display = 'inline-block';
        document.getElementById('btn-stop-api').style.display = 'none';
        document.getElementById('live-last-update').textContent = '';
        document.getElementById('live-cards').innerHTML = '<div class="live-empty">API tidak aktif — klik ▶ Jalankan API</div>';
    }
}

async function stopApiServer() {
    const btn = document.getElementById('btn-stop-api');
    btn.textContent = '⏳ Menghentikan...';
    btn.disabled = true;
    try {
        const resp = await fetch('stop_api_server.php');
        const data = await resp.json();
        document.getElementById('live-last-update').textContent = data.message || 'API dihentikan';
        setTimeout(fetchLiveData, 2000);
    } catch(e) {
        document.getElementById('live-last-update').textContent = 'Gagal stop: ' + e.message;
    }
    btn.textContent = '■ Stop API';
    btn.disabled = false;
}

async function startApiServer() {
    const btn = document.getElementById('btn-start-api');
    btn.textContent = '⏳ Memulai...';
    btn.disabled = true;
    try {
        const resp = await fetch('start_api_server.php');
        const data = await resp.json();
        document.getElementById('live-last-update').textContent = data.message || 'Menunggu API...';
        setTimeout(fetchLiveData, 3000);
    } catch(e) {
        document.getElementById('live-last-update').textContent = 'Gagal: ' + e.message;
    }
    btn.textContent = '▶ Jalankan API';
    btn.disabled = false;
}

// Fetch live data every 5 seconds
fetchLiveData();
setInterval(fetchLiveData, 5000);
</script>
</body>
</html>
