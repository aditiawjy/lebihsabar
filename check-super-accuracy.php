<?php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
date_default_timezone_set('Asia/Jakarta');

const FINISH_MINUTES = 15;
const SUPER_FIRST_GOAL_MAX = 8;
const SUPER_MIN_LINE = 5.75;
const SUPER_SECOND_GOAL_MAX = 25;
const SUPER_LAST_1H_MIN = 35;
const SUPER_MAX_HT_DIFF = 1;
const SUPER1_TOTAL_HT = 3;
const SUPER1_MIN_LINE = 6.75;
const SUPER2_FIRST_GOAL_MAX = 8;
const SUPER2_MIN_LINE = 7.25;
const P11_TOTAL_HT = 3;
const P11_FIRST_GOAL_MAX = 12;
const P1_FIRST_GOAL_MAX = 12;
const P1_MIN_LINE = 5.75;
const P2_FIRST_GOAL_MAX = 15;
const P2_MIN_LINE = 5.5;
const P3_FIRST_GOAL_MIN = 5;
const P3_FIRST_GOAL_MAX = 9;
const P4_FIRST_GOAL_MIN = 15;
const P4_MIN_LINE = 5.5;
const P5_FIRST_GOAL_MAX = 18;
const P6_FIRST_GOAL_MAX = 8;
const P6_MAX_LINE = 6.25;
const P7_FIRST_GOAL_MAX = 8;
const P8_MIN_LINE = 6.0;
const P10_MIN_LINE = 5.75;

$file = __DIR__ . '/goal_log_vsoccer.csv';
$now = time();
$allowedPatterns = ['super', 'super1', 'super2', 'hah', 'p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p8', 'p9', 'p10', 'p11'];
$patternKey = isset($_GET['pattern']) && in_array($_GET['pattern'], $allowedPatterns, true)
    ? $_GET['pattern']
    : 'super';
$patternCode = strtoupper($patternKey);
$isOver15Pattern = $patternKey === 'hah' || preg_match('/^p(?:[1-9]|10|11)$/', $patternKey) === 1;
$targetGoals = $isOver15Pattern ? 2 : 3;
$targetLabel = $isOver15Pattern
    ? 'Over 1.5 gol babak kedua'
    : 'Over 2.5 gol babak kedua';
$lineRequirements = [
    'super' => '≥ 5.75', 'super1' => '≥ 6.75', 'super2' => '≥ 7.25',
    'hah' => 'Tanpa syarat', 'p1' => '≥ 5.75', 'p2' => '≥ 5.5',
    'p3' => 'Tanpa syarat', 'p4' => '≥ 5.5', 'p5' => 'Tanpa syarat',
    'p6' => '≤ 6.25', 'p7' => 'Tanpa syarat', 'p8' => '≥ 6',
    'p9' => 'Tanpa syarat', 'p10' => '≥ 5.75', 'p11' => 'Tanpa syarat',
];
$lineRequirement = $lineRequirements[$patternKey];

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function midline($value): ?float
{
    $parts = array_values(array_filter(
        array_map('trim', explode('/', (string)$value)),
        static fn($part) => $part !== '' && is_numeric($part)
    ));
    if (!$parts) {
        return null;
    }
    return array_sum(array_map('floatval', $parts)) / count($parts);
}

function goalSnapshots($value): array
{
    preg_match_all("/(1H|2H)\\s+(\\d+)'\\s*\\((\\d+)-(\\d+)\\)/", (string)$value, $matches, PREG_SET_ORDER);
    return $matches;
}

function percent(int $hits, int $total): ?float
{
    return $total > 0 ? ($hits / $total) * 100 : null;
}

function pct(?float $value): string
{
    return $value === null ? '–' : number_format($value, 1, ',', '.') . '%';
}

function wilson95(int $hits, int $total): array
{
    if ($total === 0) {
        return [null, null];
    }
    $z = 1.96;
    $p = $hits / $total;
    $denominator = 1 + ($z * $z / $total);
    $centre = ($p + ($z * $z / (2 * $total))) / $denominator;
    $margin = ($z * sqrt(($p * (1 - $p) / $total) + ($z * $z / (4 * $total * $total)))) / $denominator;
    return [max(0, $centre - $margin) * 100, min(1, $centre + $margin) * 100];
}

function summarize(array $rows): array
{
    $hits = count(array_filter($rows, static fn($row) => $row['hit']));
    $total = count($rows);
    return ['hits' => $hits, 'total' => $total, 'rate' => percent($hits, $total)];
}

$allFinished = 0;
$qualified = [];
$error = null;
// Baseline = hasil kalau SEMUA match ditaruhi tanpa pattern apa pun. Tanpa
// pembanding ini, akurasi pattern 90% tidak bisa dinilai: bisa jadi tebak-buta
// pun sudah dapat 66%.
$baseline = ['hits' => 0, 'total' => 0];
$baselineByDay = [];

if (!is_file($file)) {
    $error = 'goal_log_vsoccer.csv tidak ditemukan.';
} elseif (($handle = fopen($file, 'r')) === false) {
    $error = 'goal_log_vsoccer.csv tidak dapat dibuka.';
} else {
    $header = fgetcsv($handle);
    $index = array_flip($header ?: []);
    $required = ['datetime', 'league', 'home_team', 'away_team', 'goals', 'final_home', 'final_away', 'ko_line'];
    foreach ($required as $column) {
        if (!isset($index[$column])) {
            $error = "Kolom CSV tidak ditemukan: {$column}.";
            break;
        }
    }

    while ($error === null && ($row = fgetcsv($handle)) !== false) {
        $finalHomeRaw = trim((string)($row[$index['final_home']] ?? ''));
        $finalAwayRaw = trim((string)($row[$index['final_away']] ?? ''));
        if (!is_numeric($finalHomeRaw) || !is_numeric($finalAwayRaw)) {
            continue;
        }

        $dateText = trim((string)($row[$index['datetime']] ?? ''));
        $date = DateTime::createFromFormat('d/m/Y H:i', $dateText);
        if ($date && ($now - $date->getTimestamp()) < FINISH_MINUTES * 60) {
            continue;
        }

        $snapshots = goalSnapshots($row[$index['goals']] ?? '');
        if (!$snapshots) {
            $allFinished++;
            continue;
        }

        $koLineText = trim((string)($row[$index['ko_line']] ?? ''));
        $ko = midline($koLineText);
        if ($ko === null) {
            continue;
        }

        $allFinished++;
        $minutes1H = [];
        $goalSides1H = [];
        $htHome = 0;
        $htAway = 0;
        $goals2H = 0;

        foreach ($snapshots as $snapshot) {
            $half = $snapshot[1];
            $minute = (int)$snapshot[2];
            if ($half === '1H') {
                $nextHome = (int)$snapshot[3];
                $nextAway = (int)$snapshot[4];
                $minutes1H[] = $minute;
                if ($nextHome > $htHome) {
                    $goalSides1H[] = 'home';
                } elseif ($nextAway > $htAway) {
                    $goalSides1H[] = 'away';
                }
                $htHome = $nextHome;
                $htAway = $nextAway;
            } else {
                $goals2H++;
            }
        }

        // Baseline dihitung sebelum filter pattern, jadi mencakup semua match
        // selesai — termasuk yang tidak memenuhi pattern.
        $day = substr($dateText, 0, 10);
        $baselineHit = $goals2H >= $targetGoals;
        $baseline['total']++;
        $baseline['hits'] += $baselineHit ? 1 : 0;
        $baselineByDay[$day]['total'] = ($baselineByDay[$day]['total'] ?? 0) + 1;
        $baselineByDay[$day]['hits'] = ($baselineByDay[$day]['hits'] ?? 0) + ($baselineHit ? 1 : 0);

        if (!$minutes1H) {
            continue;
        }

        $firstGoal = $minutes1H[0];
        $secondGoal = $minutes1H[1] ?? null;
        $lastGoal1H = $minutes1H[count($minutes1H) - 1];
        $isDraw = $htHome === $htAway;
        $drawException = $isDraw
            && $secondGoal !== null
            && $secondGoal <= SUPER_SECOND_GOAL_MAX
            && $lastGoal1H >= SUPER_LAST_1H_MIN;

        if ($patternKey === 'p1') {
            $matchesPattern = abs($htHome - $htAway) === 1
                && $firstGoal <= P1_FIRST_GOAL_MAX
                && $ko >= P1_MIN_LINE;
            $branch = 'Selisih HT tepat 1';
        } elseif ($patternKey === 'p2') {
            $matchesPattern = (($htHome === 2 && $htAway === 1) || ($htHome === 1 && $htAway === 2))
                && $firstGoal <= P2_FIRST_GOAL_MAX
                && $ko >= P2_MIN_LINE;
            $branch = 'HT 2-1 / 1-2';
        } elseif ($patternKey === 'p3') {
            $matchesPattern = ($htHome + $htAway) === 3
                && $firstGoal >= P3_FIRST_GOAL_MIN
                && $firstGoal <= P3_FIRST_GOAL_MAX;
            $branch = 'Total HT 3 · gol-1 5–9';
        } elseif ($patternKey === 'p4') {
            $matchesPattern = $htHome === 1
                && $htAway === 1
                && $firstGoal >= P4_FIRST_GOAL_MIN
                && $ko >= P4_MIN_LINE;
            $branch = 'HT tepat 1-1';
        } elseif ($patternKey === 'p5') {
            $matchesPattern = $htHome === 3
                && $htAway === 0
                && $firstGoal <= P5_FIRST_GOAL_MAX;
            $branch = 'HT tepat 3-0';
        } elseif ($patternKey === 'p6') {
            $matchesPattern = $htHome === 2
                && $htAway === 2
                && $firstGoal <= P6_FIRST_GOAL_MAX
                && $ko <= P6_MAX_LINE;
            $branch = 'HT tepat 2-2';
        } elseif ($patternKey === 'p7') {
            $matchesPattern = $htHome === 3
                && $htAway === 2
                && $firstGoal <= P7_FIRST_GOAL_MAX;
            $branch = 'HT tepat 3-2';
        } elseif ($patternKey === 'p8') {
            $matchesPattern = $htHome === 1
                && $htAway === 3
                && $ko >= P8_MIN_LINE;
            $branch = 'HT tepat 1-3';
        } elseif ($patternKey === 'p9') {
            $matchesPattern = $htHome === 3 && $htAway === 3;
            $branch = 'HT tepat 3-3';
        } elseif ($patternKey === 'p10') {
            $matchesPattern = $htHome === 2
                && $htAway === 3
                && $ko >= P10_MIN_LINE;
            $branch = 'HT tepat 2-3';
        } elseif ($patternKey === 'p11') {
            $matchesPattern = ($htHome + $htAway) === P11_TOTAL_HT
                && $firstGoal <= P11_FIRST_GOAL_MAX;
            $branch = 'Total HT 3 · gol-1 ≤ 12';
        } elseif ($patternKey === 'hah') {
            $matchesPattern = $htHome === 2
                && $htAway === 1
                && $goalSides1H === ['home', 'away', 'home'];
            $branch = 'Home–Away–Home';
        } elseif ($patternKey === 'super1') {
            $matchesPattern = ($htHome + $htAway) === SUPER1_TOTAL_HT
                && $ko >= SUPER1_MIN_LINE;
            $branch = 'Total HT tepat 3';
        } elseif ($patternKey === 'super2') {
            $matchesPattern = abs($htHome - $htAway) <= 1
                && $firstGoal <= SUPER2_FIRST_GOAL_MAX
                && $ko >= SUPER2_MIN_LINE;
            $branch = $isDraw ? 'HT seri' : 'Selisih HT 1';
        } else {
            $matchesPattern = abs($htHome - $htAway) <= SUPER_MAX_HT_DIFF
                && $firstGoal <= SUPER_FIRST_GOAL_MAX
                && $ko >= SUPER_MIN_LINE
                && (!$isDraw || $drawException);
            $branch = $isDraw ? 'Seri + syarat khusus' : 'Selisih HT 1';
        }

        if (!$matchesPattern) {
            continue;
        }

        $qualified[] = [
            'timestamp' => $date ? $date->getTimestamp() : 0,
            'datetime' => $dateText,
            'day' => $day,
            'league' => trim((string)($row[$index['league']] ?? '')),
            'home' => trim((string)($row[$index['home_team']] ?? '')),
            'away' => trim((string)($row[$index['away_team']] ?? '')),
            'ht' => "{$htHome}-{$htAway}",
            'ht_diff' => abs($htHome - $htAway),
            'final' => $finalHomeRaw . '-' . $finalAwayRaw,
            'first_goal' => $firstGoal,
            'second_goal' => $secondGoal,
            'last_goal_1h' => $lastGoal1H,
            'ko_text' => $koLineText,
            'ko' => $ko,
            'goals_2h' => $goals2H,
            'is_draw' => $isDraw,
            'branch' => $branch,
            'hit' => $goals2H >= $targetGoals,
        ];
    }
    fclose($handle);
}

usort($qualified, static fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

$nonDrawRows = array_values(array_filter($qualified, static fn($row) => !$row['is_draw']));
$drawRows = array_values(array_filter($qualified, static fn($row) => $row['is_draw']));

$allStats = summarize($qualified);
$nonDrawStats = summarize($nonDrawRows);
$drawStats = summarize($drawRows);
$failures = array_values(array_filter($qualified, static fn($row) => !$row['hit']));
$ci = wilson95($allStats['hits'], $allStats['total']);

$baselineRate = percent($baseline['hits'], $baseline['total']);
$edge = ($allStats['rate'] === null || $baselineRate === null) ? null : $allStats['rate'] - $baselineRate;

// Rincian per hari: pattern vs baseline hari yang sama. Hari pertama biasanya
// in-sample (aturan diturunkan dari situ); hari-hari sesudahnya yang menentukan.
$byDay = [];
foreach ($qualified as $row) {
    $byDay[$row['day']]['total'] = ($byDay[$row['day']]['total'] ?? 0) + 1;
    $byDay[$row['day']]['hits'] = ($byDay[$row['day']]['hits'] ?? 0) + ($row['hit'] ? 1 : 0);
}
$dayRows = [];
foreach ($byDay as $day => $agg) {
    $pRate = percent($agg['hits'], $agg['total']);
    $bHits = $baselineByDay[$day]['hits'] ?? 0;
    $bTotal = $baselineByDay[$day]['total'] ?? 0;
    $bRate = percent($bHits, $bTotal);
    $dayRows[] = [
        'day' => $day,
        'hits' => $agg['hits'],
        'total' => $agg['total'],
        'rate' => $pRate,
        'b_hits' => $bHits,
        'b_total' => $bTotal,
        'b_rate' => $bRate,
        'edge' => ($pRate === null || $bRate === null) ? null : $pRate - $bRate,
    ];
}
usort($dayRows, static function ($a, $b) {
    $ka = DateTime::createFromFormat('d/m/Y', $a['day']);
    $kb = DateTime::createFromFormat('d/m/Y', $b['day']);
    return ($ka ? $ka->getTimestamp() : 0) <=> ($kb ? $kb->getTimestamp() : 0);
});

function signed(?float $value): string
{
    if ($value === null) {
        return '–';
    }
    return ($value >= 0 ? '+' : '−') . number_format(abs($value), 1, ',', '.') . ' pp';
}

if ($allStats['total'] === 0) {
    $verdict = 'BELUM ADA SAMPEL';
    $verdictClass = 'neutral';
    $verdictText = "Belum ada match selesai yang memenuhi pola {$patternCode}.";
} elseif ($edge !== null && $ci[0] !== null && $ci[0] <= $baselineRate) {
    // Batas bawah CI menyentuh baseline: belum ada bukti pattern lebih baik
    // daripada menaruhi semua match tanpa filter.
    $verdict = 'EDGE BELUM TERBUKTI';
    $verdictClass = 'down';
    $verdictText = "Akurasi {$patternCode} " . pct($allStats['rate']) . ' vs baseline '
        . pct($baselineRate) . ' (' . signed($edge) . '), tapi batas bawah CI 95% ('
        . pct($ci[0]) . ') masih di bawah/menyentuh baseline. Sampel belum cukup untuk menyimpulkan pattern ini unggul.';
} elseif ($edge !== null && $edge <= 0) {
    $verdict = 'TIDAK ADA EDGE';
    $verdictClass = 'down';
    $verdictText = "{$patternCode} (" . pct($allStats['rate']) . ') tidak lebih baik daripada menaruhi semua match tanpa filter ('
        . pct($baselineRate) . ').';
} else {
    $verdict = 'EDGE POSITIF';
    $verdictClass = 'same';
    $verdictText = "{$patternCode} " . pct($allStats['rate']) . ' vs baseline ' . pct($baselineRate)
        . ' (' . signed($edge) . '), batas bawah CI 95% ' . pct($ci[0]) . ' masih di atas baseline.';
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="60">
<title>Cek Akurasi SUPER V-Soccer</title>
<style>
:root{--bg:#0c1016;--card:#151b24;--border:#2b3543;--text:#e7edf5;--muted:#92a0b3;--green:#59db8b;--red:#ff7185;--yellow:#f3c969;--blue:#78b7ff}
*{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
.wrap{max-width:1180px;margin:auto;padding:24px 16px 48px}.top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}
h1{font-size:24px;margin:0}.sub{color:var(--muted);margin:5px 0 0}.actions{display:flex;gap:8px}.btn{color:var(--text);text-decoration:none;border:1px solid var(--border);background:#111720;padding:7px 11px;border-radius:7px}
.controls{display:flex;align-items:center;gap:10px;margin:18px 0 10px;padding:12px 14px;background:#111720;border:1px solid var(--border);border-radius:10px}.controls label{color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}.controls select{min-width:260px;background:#0c1118;color:var(--text);border:1px solid #415064;border-radius:7px;padding:8px 34px 8px 10px;font:600 14px/1.2 system-ui,-apple-system,"Segoe UI",sans-serif;cursor:pointer}.controls select:focus{outline:2px solid var(--blue);outline-offset:2px}
.rule,.card{background:var(--card);border:1px solid var(--border);border-radius:12px}.rule{margin:18px 0;padding:14px 16px;border-left:4px solid var(--yellow)}
.rule b{color:var(--yellow)}.verdict{padding:18px;margin-bottom:16px}.verdict.same{border-color:#29794a}.verdict.down{border-color:#9c3545}.verdict.neutral{border-color:#756329}
.verdict .tag{font-size:12px;font-weight:800;letter-spacing:.08em}.same .tag{color:var(--green)}.down .tag{color:var(--red)}.neutral .tag{color:var(--yellow)}
.verdict .big{font-size:28px;font-weight:800;margin:3px 0}.muted{color:var(--muted)}
.grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:16px}@media(max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}}
.stat{padding:14px}.stat .label{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em}.stat .value{font:700 22px/1.25 ui-monospace,Consolas,monospace;margin-top:4px}
.section{margin-top:22px}.section h2{font-size:15px;margin:0 0 8px}.tablebox{overflow:auto;border:1px solid var(--border);border-radius:10px}
table{width:100%;border-collapse:collapse;white-space:nowrap;background:#10161e}th,td{padding:8px 10px;border-bottom:1px solid #222c38;text-align:left}th{color:var(--muted);font-size:11px;text-transform:uppercase}.num{text-align:right;font-variant-numeric:tabular-nums}.hit{color:var(--green);font-weight:700}.miss{color:var(--red);font-weight:700}.empty{padding:18px;color:var(--muted)}
.error{background:#421923;border:1px solid #8d3041;color:#ffbac5;padding:12px;border-radius:8px;margin:15px 0}.foot{color:var(--muted);font-size:12px;margin-top:16px}
</style>
</head>
<body>
<main class="wrap">
  <div class="top">
    <div><h1>Cek Akurasi Pattern V-Soccer</h1><p class="sub">Target mengikuti pola yang dipilih. Statistik langsung dari goal_log_vsoccer.csv.</p></div>
    <div class="actions"><a class="btn" href="vsoccer-live.php">← Live</a><a class="btn" href="javascript:location.reload()">↻ Refresh</a></div>
  </div>

  <form class="controls" method="get" action="">
    <label for="pattern">Pilih pola</label>
    <select id="pattern" name="pattern" onchange="this.form.submit()">
      <option value="super" <?= $patternKey === 'super' ? 'selected' : '' ?>>SUPER — Over 2.5 babak kedua</option>
      <option value="super1" <?= $patternKey === 'super1' ? 'selected' : '' ?>>SUPER1 — Over 2.5 babak kedua</option>
      <option value="super2" <?= $patternKey === 'super2' ? 'selected' : '' ?>>SUPER2 — Over 2.5 babak kedua</option>
      <option value="hah" <?= $patternKey === 'hah' ? 'selected' : '' ?>>HAH — Over 1.5 babak kedua</option>
      <option value="p1" <?= $patternKey === 'p1' ? 'selected' : '' ?>>P1 — Over 1.5 babak kedua</option>
      <option value="p2" <?= $patternKey === 'p2' ? 'selected' : '' ?>>P2 — Over 1.5 babak kedua</option>
      <option value="p3" <?= $patternKey === 'p3' ? 'selected' : '' ?>>P3 — Over 1.5 babak kedua</option>
      <option value="p4" <?= $patternKey === 'p4' ? 'selected' : '' ?>>P4 — Over 1.5 babak kedua</option>
      <option value="p5" <?= $patternKey === 'p5' ? 'selected' : '' ?>>P5 — Over 1.5 babak kedua</option>
      <option value="p6" <?= $patternKey === 'p6' ? 'selected' : '' ?>>P6 — Over 1.5 babak kedua</option>
      <option value="p7" <?= $patternKey === 'p7' ? 'selected' : '' ?>>P7 — Over 1.5 babak kedua</option>
      <option value="p8" <?= $patternKey === 'p8' ? 'selected' : '' ?>>P8 — Over 1.5 babak kedua</option>
      <option value="p9" <?= $patternKey === 'p9' ? 'selected' : '' ?>>P9 — Over 1.5 babak kedua</option>
      <option value="p10" <?= $patternKey === 'p10' ? 'selected' : '' ?>>P10 — Over 1.5 babak kedua</option>
      <option value="p11" <?= $patternKey === 'p11' ? 'selected' : '' ?>>P11 — Over 1.5 babak kedua</option>
    </select>
    <noscript><button class="btn" type="submit">Tampilkan</button></noscript>
  </form>

  <?php if ($patternKey === 'super1'): ?>
    <div class="rule"><b>SUPER1</b> — total gol HT tepat 3 (tanpa syarat menit) · line awal ≥ 6.75.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 3).</span></div>
  <?php elseif ($patternKey === 'super2'): ?>
    <div class="rule"><b>SUPER2</b> — selisih HT ≤ 1 (termasuk seri) · gol pertama ≤ 8' · line awal ≥ 7.25.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 3).</span></div>
  <?php elseif ($patternKey === 'hah'): ?>
    <div class="rule"><b>HAH</b> — urutan gol 1H Home–Away–Home, skor HT 2-1, tanpa syarat menit atau line.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
  <?php elseif ($patternKey === 'p1'): ?>
    <div class="rule"><b>P1</b> — selisih HT tepat 1 · gol pertama ≤ 12' · line awal ≥ 5.75.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
  <?php elseif ($patternKey === 'p2'): ?>
    <div class="rule"><b>P2</b> — HT 2-1 / 1-2 · gol pertama ≤ 15' · line awal ≥ 5.5.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
  <?php elseif ($patternKey === 'p3'): ?>
    <div class="rule"><b>P3</b> — total gol HT tepat 3 · gol pertama 5'–9'.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
  <?php elseif ($patternKey === 'p4'): ?>
    <div class="rule"><b>P4</b> — HT 1-1 · gol pertama ≥ 15' · line awal ≥ 5.5.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
  <?php elseif ($patternKey === 'p5'): ?>
    <div class="rule"><b>P5</b> — HT 3-0 · gol pertama ≤ 18'.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
  <?php elseif ($patternKey === 'p6'): ?>
    <div class="rule"><b>P6</b> — HT 2-2 · gol pertama ≤ 8' · line awal ≤ 6.25.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
  <?php elseif ($patternKey === 'p7'): ?>
    <div class="rule"><b>P7</b> — HT 3-2 · gol pertama ≤ 8'.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
  <?php elseif ($patternKey === 'p8'): ?>
    <div class="rule"><b>P8</b> — HT 1-3 · line awal ≥ 6.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
  <?php elseif ($patternKey === 'p9'): ?>
    <div class="rule"><b>P9</b> — skor HT tepat 3-3, tanpa syarat tambahan.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
  <?php elseif ($patternKey === 'p10'): ?>
    <div class="rule"><b>P10</b> — skor HT tepat 2-3 · line awal ≥ 5.75.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
  <?php elseif ($patternKey === 'p11'): ?>
    <div class="rule"><b>P11</b> — total gol HT tepat 3 (low) · gol pertama ≤ 12' · tanpa syarat line.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
  <?php else: ?>
    <div class="rule"><b>SUPER</b> — <b>selisih HT ≤ 1</b>; jika HT seri, gol ke-2 ≤ 25' dan gol terakhir 1H ≥ 35' · gol pertama ≤ 8' · line awal ≥ 5.75.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 3).<br><b>Revisi:</b> syarat selisih HT ≤ 1 (dari rumus historis O25-4) dikembalikan — tanpa syarat itu akurasi log cuma 78% (64/82).</span></div>
  <?php endif; ?>
  <?php if ($error !== null): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

  <section class="card verdict <?= e($verdictClass) ?>">
    <div class="tag"><?= e($verdict) ?></div>
    <div class="big"><?= pct($allStats['rate']) ?> <span class="muted" style="font-size:16px">(<?= $allStats['hits'] ?>/<?= $allStats['total'] ?>)</span></div>
    <div class="muted"><?= e($verdictText) ?></div>
  </section>

  <section class="grid">
    <div class="card stat"><div class="label"><?= e($patternCode) ?></div><div class="value"><?= $allStats['hits'] ?>/<?= $allStats['total'] ?></div><div class="muted"><?= pct($allStats['rate']) ?></div></div>
    <div class="card stat"><div class="label">Baseline (semua match)</div><div class="value"><?= pct($baselineRate) ?></div><div class="muted"><?= $baseline['hits'] ?>/<?= $baseline['total'] ?> tanpa filter apa pun</div></div>
    <div class="card stat"><div class="label">Edge vs baseline</div><div class="value" style="color:<?= $edge !== null && $edge > 0 ? 'var(--green)' : 'var(--red)' ?>"><?= signed($edge) ?></div><div class="muted">selisih akurasi</div></div>
    <div class="card stat"><div class="label">Target aktif</div><div class="value">O<?= $targetGoals === 2 ? '1.5' : '2.5' ?></div><div class="muted">HIT jika ≥ <?= $targetGoals ?> gol 2H</div></div>
    <div class="card stat"><div class="label">Syarat line</div><div class="value"><?= e($lineRequirement) ?></div><div class="muted">line KO</div></div>
    <div class="card stat"><div class="label">HT tidak seri (selisih 1)</div><div class="value"><?= $nonDrawStats['hits'] ?>/<?= $nonDrawStats['total'] ?></div><div class="muted"><?= pct($nonDrawStats['rate']) ?></div></div>
    <div class="card stat"><div class="label">HT seri + pengecualian</div><div class="value"><?= $drawStats['hits'] ?>/<?= $drawStats['total'] ?></div><div class="muted"><?= pct($drawStats['rate']) ?></div></div>
  </section>

  <section class="card stat">
    <div class="label">Interval keyakinan Wilson 95%</div>
    <div class="value" style="font-size:18px"><?= pct($ci[0]) ?> – <?= pct($ci[1]) ?></div>
    <div class="muted">Akurasi 100% pada sampel kecil belum berarti peluang sebenarnya pasti 100%. Bandingkan batas bawah dengan baseline <?= pct($baselineRate) ?> — kalau masih di bawahnya, edge belum terbukti.</div>
  </section>

  <section class="section">
    <h2>Per hari — pattern vs baseline hari yang sama</h2>
    <?php if (!$dayRows): ?>
      <div class="card empty">Belum ada data.</div>
    <?php else: ?>
      <div class="tablebox"><table><thead><tr><th>Hari</th><th class="num"><?= e($patternCode) ?></th><th class="num">Akurasi</th><th class="num">Baseline</th><th class="num">Edge</th></tr></thead><tbody>
      <?php foreach ($dayRows as $d): ?><tr>
        <td><?= e($d['day']) ?></td>
        <td class="num"><?= $d['hits'] ?>/<?= $d['total'] ?></td>
        <td class="num"><?= pct($d['rate']) ?></td>
        <td class="num"><?= pct($d['b_rate']) ?> <span class="muted">(<?= $d['b_hits'] ?>/<?= $d['b_total'] ?>)</span></td>
        <td class="num <?= $d['edge'] !== null && $d['edge'] > 0 ? 'hit' : 'miss' ?>"><?= signed($d['edge']) ?></td>
      </tr><?php endforeach; ?>
      </tbody></table></div>
      <p class="foot">Hari-hari awal cenderung <b>in-sample</b> (aturan diturunkan dari data itu), jadi edge besar di situ wajar dan bukan bukti. Yang menentukan adalah hari-hari sesudah aturan dikunci.</p>
    <?php endif; ?>
  </section>

  <section class="section">
    <h2>Match gagal (penyebab akurasi berkurang)</h2>
    <?php if (!$failures): ?>
      <div class="card empty">Tidak ada kegagalan pada data yang memenuhi <?= e($patternCode) ?>.</div>
    <?php else: ?>
      <div class="tablebox"><table><thead><tr><th>Waktu</th><th>Match</th><th>HT</th><th>Cabang</th><th class="num">Gol-1</th><th class="num">Gol-2</th><th class="num">Gol terakhir 1H</th><th class="num">Line KO</th><th class="num">Gol 2H</th><th>Hasil</th></tr></thead><tbody>
      <?php foreach ($failures as $row): ?><tr>
        <td><?= e($row['datetime']) ?></td><td><?= e($row['home']) ?> vs <?= e($row['away']) ?></td><td><?= e($row['ht']) ?></td><td><?= e($row['branch']) ?></td>
        <td class="num"><?= $row['first_goal'] ?>'</td><td class="num"><?= $row['second_goal'] === null ? '–' : $row['second_goal'] . "'" ?></td><td class="num"><?= $row['last_goal_1h'] ?>'</td>
        <td class="num"><?= e($row['ko_text']) ?></td><td class="num"><?= $row['goals_2h'] ?></td><td class="miss">MISS</td>
      </tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>

  <section class="section">
    <h2>Semua sampel <?= e($patternCode) ?> (terbaru di atas)</h2>
    <?php if (!$qualified): ?>
      <div class="card empty">Belum ada data yang memenuhi pola.</div>
    <?php else: ?>
      <div class="tablebox"><table><thead><tr><th>#</th><th>Waktu</th><th>Match</th><th>HT</th><th>Cabang</th><th class="num">Gol-1</th><th class="num">Gol-2</th><th class="num">Gol terakhir 1H</th><th class="num">Line KO</th><th class="num">Gol 2H</th><th>Hasil</th></tr></thead><tbody>
      <?php foreach (array_reverse($qualified, true) as $i => $row): ?><tr>
        <td><?= $i + 1 ?></td><td><?= e($row['datetime']) ?></td><td><?= e($row['home']) ?> vs <?= e($row['away']) ?></td><td><?= e($row['ht']) ?></td><td><?= e($row['branch']) ?></td>
        <td class="num"><?= $row['first_goal'] ?>'</td><td class="num"><?= $row['second_goal'] === null ? '–' : $row['second_goal'] . "'" ?></td><td class="num"><?= $row['last_goal_1h'] ?>'</td>
        <td class="num"><?= e($row['ko_text']) ?></td><td class="num"><?= $row['goals_2h'] ?></td><td class="<?= $row['hit'] ? 'hit' : 'miss' ?>"><?= $row['hit'] ? 'HIT' : 'MISS' ?></td>
      </tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>

  <p class="foot">Membaca <?= e(basename($file)) ?> secara read-only · <?= $allFinished ?> match selesai terbaca · halaman refresh otomatis tiap 60 detik.</p>
</main>
</body>
</html>
