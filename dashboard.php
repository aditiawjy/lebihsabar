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
    </style>
</head>
<body>
<div class="container">
    <h1>Pattern Accuracy Dashboard</h1>
    <p class="subtitle">Analisis pola gol 1H berdasarkan data goal_log.csv</p>
    <div id="update-indicator" style="text-align:center; margin-bottom:16px;">
        <span class="badge badge-green" id="update-status">● LIVE</span>
        <span style="color:#8b949e; font-size:0.8rem; margin-left:8px;" id="update-time"></span>
        <button onclick="location.reload()" style="margin-left:12px; background:#21262d; border:1px solid #30363d; color:#58a6ff; padding:4px 12px; border-radius:6px; cursor:pointer; font-size:0.8rem;">↻ Refresh</button>
    </div>

<?php
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
    if (empty($goals)) continue;
    try { $fh_score = (int)$row[5]; $fa_score = (int)$row[6]; } catch (Exception $e) { continue; }
    $h1 = array_filter($goals, fn($g) => $g['half'] === '1H');
    $h2 = array_filter($goals, fn($g) => $g['half'] === '2H');
    $h1 = array_values($h1);
    $h2 = array_values($h2);
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
        'all_gaps_ge3' => allGapsGe($h1, 3),
        'all_gaps_ge5' => allGapsGe($h1, 5),
        'h2' => $h2,
        'h2_first_min' => count($h2) ? $h2[0]['min'] : -1,
        'has_late' => count(array_filter($h2, fn($g) => $g['min'] >= 7)) > 0,
        'h2_eq_min' => (function($h2) {
            foreach ($h2 as $g) { if ($g['home'] == $g['away']) return $g['min']; }
            return -1;
        })($h2),
    ];
}

// P2: Diff 2+ & last 1H mnt 7' & all gaps >= 3
$p2 = array_filter($matches, fn($m) => $m['h1c'] >= 2 && abs($m['sc_h']-$m['sc_a']) >= 2 && $m['h1_last'] == 7 && $m['all_gaps_ge3']);
// P3: AH seri 1-1, gap >= 3, 15/16min
$p3 = array_filter($matches, fn($m) => in_array($m['league'],['15min','16min']) && $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['h1s']==['A','H'] && ($m['h1'][1]['min']-$m['h1'][0]['min']) >= 3);
// P4: 1 goal 8'+, AWAY, 16/20min
$p4 = array_filter($matches, fn($m) => in_array($m['league'],['16min','20min']) && $m['h1c']==1 && $m['h1_first']>=8 && $m['h1s']==['A']);
// P5: First 0-2' + last 7'+ + diff<=1 + all gaps>=3
$p5 = array_filter($matches, fn($m) => $m['h1c']>=2 && $m['h1_first']<=2 && $m['h1_last']>=7 && abs($m['sc_h']-$m['sc_a'])<=1 && $m['all_gaps_ge3']);
// P6: Seri 1-1 + equalizer at mnt 7'
$p6 = array_filter($matches, fn($m) => $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['h1_last']==7);
// P7: Seri 1-1 + gap >= 5
$p7 = array_filter($matches, fn($m) => $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['max_gap']>=5);
// P8: Away comeback 1H (HAA)
$p8 = array_filter($matches, fn($m) => $m['h1s']==['H','A','A'] && $m['sc_h']==1 && $m['sc_a']==2);
// P9: AH seri 1-1 + gap >= 5
$p9 = array_filter($matches, fn($m) => $m['h1c']==2 && $m['sc_h']==1 && $m['sc_a']==1 && $m['h1s']==['A','H'] && $m['max_gap']>=5);
// P10: 0-0 di 1H (skip)
$p10 = array_filter($matches, fn($m) => $m['h1c'] == 0);
// P11: Switches 2+
$p11 = array_filter($matches, fn($m) => $m['switches'] >= 2);
// P12: Total 1H goals >= 4
$p12 = array_filter($matches, fn($m) => $m['h1c'] >= 4);
// P13: First 0-2' + last 7'+
$p13 = array_filter($matches, fn($m) => $m['h1c']>=2 && $m['h1_first']<=2 && $m['h1_last']>=7);
// P14: Seri + gap >= 4
$p14 = array_filter($matches, fn($m) => $m['h1c']>=2 && $m['sc_h']==$m['sc_a'] && $m['sc_h']>0 && $m['max_gap']>=4);
// P15: HT 2-2
$p15 = array_filter($matches, fn($m) => $m['sc_h']==2 && $m['sc_a']==2);
// P16: Last gol 1H mnt 6 atau 7, league 16min
$p16 = array_filter($matches, fn($m) => $m['league']==='16min' && ($m['h1_last']==6 || $m['h1_last']==7));
// P17: First 1H mnt 1-2 + last mnt 7
$p17 = array_filter($matches, fn($m) => $m['h1c']>=2 && $m['h1_first']>=1 && $m['h1_first']<=2 && $m['h1_last']==7);
// P18: Span 1H >= 6 mnt (last - first >= 6, 2+ gol)
$p18 = array_filter($matches, fn($m) => $m['h1c']>=2 && ($m['h1_last']-$m['h1_first'])>=6);
// P19: Last gol 1H mnt 3-4, last scorer HOME, 20min
$p19 = array_filter($matches, fn($m) => $m['league']==='20min' && in_array($m['h1_last'],[3,4]) && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='H');
// P20: Last gol 1H mnt 3, last scorer AWAY, 16min
$p20 = array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1_last']===3 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A');
// P21: Last gol 1H mnt 5, last scorer AWAY, 15min
$p21 = array_filter($matches, fn($m) => $m['league']==='15min' && $m['h1_last']===5 && count($m['h1s'])>0 && $m['h1s'][count($m['h1s'])-1]==='A');
// P22: Away menang HT, league 16min
$p22 = array_filter($matches, fn($m) => $m['league']==='16min' && $m['sc_a'] > $m['sc_h']);
// P23: 1 gol di 1H, mnt pertama >= 3, 16min
$p23 = array_filter($matches, fn($m) => $m['league']==='16min' && $m['h1c']===1 && $m['h1_first']>=3);


$patterns = [
    ['id'=>'P2','label'=>'Selisih 2+ & last mnt 7\' & gap >=3','data'=>$p2],
    ['id'=>'P3','label'=>'AH gap >=3 mnt, 15min/16min','data'=>$p3],
    ['id'=>'P4','label'=>'1 gol mnt 8\'+, AWAY, 16/20min','data'=>$p4],
    ['id'=>'P5','label'=>'First 0-2\' + last 7\' + selisih<=1 + gap>=3','data'=>$p5],
    ['id'=>'P6','label'=>'Seri 1-1 + gol penyama mnt 7\'','data'=>$p6],
    ['id'=>'P7','label'=>'Seri 1-1 + gap >= 5 mnt','data'=>$p7],
    ['id'=>'P8','label'=>'Away comeback 1H (HAA)','data'=>$p8],
    ['id'=>'P9','label'=>'AH seri 1-1 + gap >= 5 mnt','data'=>$p9],
    ['id'=>'P11','label'=>'Switches 2+ (balas >=2x)','data'=>$p11],
    ['id'=>'P12','label'=>'Total gol 1H >= 4','data'=>$p12],
    ['id'=>'P13','label'=>'First 0-2\' + last 7\'','data'=>$p13],
    ['id'=>'P14','label'=>'Seri + gap >= 4 mnt','data'=>$p14],
    ['id'=>'P15','label'=>'HT 2-2','data'=>$p15],
    ['id'=>'P16','label'=>'Last gol 1H mnt 6-7, league 16min','data'=>$p16],
    ['id'=>'P17','label'=>'First 1H mnt 1-2 + last mnt 7','data'=>$p17],
    ['id'=>'P18','label'=>'Span 1H >= 6 mnt (2+ gol)','data'=>$p18],
    ['id'=>'P19','label'=>'Last gol 1H mnt 3-4, last HOME, 20min','data'=>$p19],
    ['id'=>'P20','label'=>'Last gol 1H mnt 3, last AWAY, 16min','data'=>$p20],
    ['id'=>'P21','label'=>'Last gol 1H mnt 5, last AWAY, 15min','data'=>$p21],
    ['id'=>'P22','label'=>'Away menang HT, 16min','data'=>$p22],
    ['id'=>'P23','label'=>'1 gol 1H, mnt pertama >=3, 16min','data'=>$p23],
];

$totalMatches = count($matches);
$with2h = count(array_filter($matches, fn($m) => $m['h2c'] > 0));

echo '<div class="stats-bar">';
echo '<div class="stat-card"><div class="value">'.$totalMatches.'</div><div class="label">Total Matches</div></div>';
echo '<div class="stat-card"><div class="value">'.count($patterns).'</div><div class="label">Patterns</div></div>';
echo '<div class="stat-card"><div class="value">'.date('d/m H:i', $csvTime).'</div><div class="label">Last Update</div></div>';
echo '</div>';

// Summary table
echo '<div class="section"><h2>Summary Akurasi</h2>';
echo '<table><tr><th>#</th><th>Pattern</th><th>Record</th><th>Akurasi</th><th>Status</th><th></th></tr>';
foreach ($patterns as $p) {
    $total = count($p['data']);
    $has2h = count(array_filter($p['data'], fn($m) => $m['h2c'] > 0));
    $pct = $total > 0 ? round($has2h/$total*100) : 0;
    $cls = $pct >= 95 ? 'pct-high' : ($pct >= 85 ? 'pct-mid' : 'pct-low');
    $badge = $pct >= 95 ? 'badge-green' : ($pct >= 85 ? 'badge-yellow' : 'badge-red');
    $status = $pct >= 95 ? 'EXCELLENT' : ($pct >= 85 ? 'GOOD' : 'WARNING');
    echo "<tr>";
    echo "<td><strong>{$p['id']}</strong></td>";
    echo "<td>{$p['label']}</td>";
    echo "<td>{$has2h}/{$total}</td>";
    echo "<td class=\"pct {$cls}\">{$pct}%</td>";
    echo "<td><span class=\"badge {$badge}\">{$status}</span></td>";
    echo "<td><button class=\"expand-btn\" onclick=\"toggle('{$p['id']}')\">Detail</button></td>";
    echo "</tr>";
}
echo '</table></div>';

// Detail tables per pattern
echo '<div class="section"><h2>Detail per Pattern</h2>';
foreach ($patterns as $p) {
    $total = count($p['data']);
    $has2h = count(array_filter($p['data'], fn($m) => $m['h2c'] > 0));
    $pct = $total > 0 ? round($has2h/$total*100) : 0;
    echo "<div id=\"detail-{$p['id']}\" style=\"display:none; margin-bottom:24px;\">";
    echo "<h3>{$p['id']}: {$p['label']} — {$has2h}/{$total} ({$pct}%)</h3>";
    echo '<table class="detail-table"><tr><th>Match</th><th>League</th><th>HT</th><th>FT</th><th>Timeline 1H</th><th>Timeline 2H</th><th>Sequence</th><th>2H?</th></tr>';
    foreach ($p['data'] as $m) {
        $seq = implode(' → ', array_map('scorerHtml', $m['h1s']));
        $timeline1h = '';
        foreach ($m['h1'] as $g) {
            $timeline1h .= "{$g['min']}' ({$g['home']}-{$g['away']})  ";
        }
        $timeline2h = '';
        foreach ($m['h2'] as $g) {
            $timeline2h .= "{$g['min']}' ({$g['home']}-{$g['away']})  ";
        }
        $has2hBadge = $m['h2c'] > 0 ? '<span class="badge badge-green">✓ 2H</span>' : '<span class="badge badge-red">✗ No 2H</span>';
        echo "<tr>";
        echo "<td>{$m['home']} vs {$m['away']}</td>";
        echo "<td>{$m['league']}</td>";
        echo "<td>{$m['sc_h']}-{$m['sc_a']}</td>";
        echo "<td><strong>{$m['fh']}-{$m['fa']}</strong></td>";
        echo "<td class=\"goal-seq\">{$timeline1h}</td>";
        echo "<td class=\"goal-seq\">".($timeline2h ?: '<span style="color:#484f58">-</span>')."</td>";
        echo "<td class=\"goal-seq\">{$seq}</td>";
        echo "<td>{$has2hBadge}</td>";
        echo "</tr>";
    }
    echo '</table></div>';
}
echo '</div>';

echo '<p class="last-update">CSV last modified: '.date('d/m/Y H:i:s', $csvTime).' | Total '.$totalMatches.' matches | Auto-refresh: 30s</p>';
echo '<script>document.getElementById("update-time").textContent = "Last: '.date('H:i:s', $csvTime).'";</script>';
?>

</div>
<script>
function toggle(id) {
    const el = document.getElementById('detail-' + id);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

// Auto-refresh every 30s
let refreshCountdown = 30;
setInterval(() => {
    refreshCountdown--;
    if (refreshCountdown <= 0) {
        location.reload();
    }
}, 1000);
</script>
</body>
</html>
