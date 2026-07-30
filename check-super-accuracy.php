<?php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
date_default_timezone_set('Asia/Jakarta');

const FINISH_MINUTES = 15;
const SUPER_FIRST_GOAL_MAX = 6;
const SUPER_MIN_LINE = 5.75;
const SUPER_SECOND_GOAL_MAX = 25;
const SUPER_LAST_1H_MIN = 35;
const SUPER_MAX_HT_DIFF = 1;
const SUPER_NON_DRAW_SECOND_GOAL_MIN = 9;
const SUPER_NON_DRAW_SECOND_GOAL_MAX = 30;
const SUPER1_TOTAL_HT = 3;
const SUPER1_MIN_LINE = 6.75;
const SUPER1_FIRST_GOAL_MAX = 25;
const SUPER1_ONE_SIDED_MIN_LINE = 7.5;
const SUPER2_FIRST_GOAL_MAX = 8;
const SUPER2_MIN_LINE = 7.25;
const SUPER2_TOTAL5_SECOND_GOAL_MIN = 9;
const SUPER2_TOTAL5_SECOND_GOAL_MAX = 30;
const SUPER2_DRAW4_SECOND_GOAL_MIN = 14;
const SUPER2_DRAW4_SECOND_GOAL_MAX = 30;
const SLOW_FIRST_GOAL_MAX = 8;
const SLOW_MIN_LINE = 5.75;
const SLOW_TOTAL3_EARLY_FIRST_MAX = 4;
const SLOW_TOTAL3_EARLY_MIN_LINE = 7.25;
const SLOW_DRAW4_LATE_FIRST_MIN = 7;
const SLOW_DRAW4_EARLY_SECOND_MAX = 10;
const SLOW_DRAW4_MAX_LINE = 6.25;
const SLOW_TOTAL5_EARLY_FIRST_MAX = 5;
const SLOW_TOTAL5_LAST_GOAL_MIN = 30;
const SLOW_TOTAL6_FIRST_GOAL_MAX = 7;
const P11_TOTAL_HT = 3;
const P11_FIRST_GOAL_MAX = 12;
const P11_MIN_LINE = 5.75;
const P1_FIRST_GOAL_MAX = 12;
const P1_MIN_LINE = 5.75;
const P1_LOW_TOTAL_FIRST_GOAL_MAX = 6;
const P1_HIGH_TOTAL_SECOND_GOAL_MIN = 9;
const P1_HIGH_TOTAL_SECOND_GOAL_MAX = 30;
const P2_FIRST_GOAL_MAX = 15;
const P2_MIN_LINE = 5.75;
const P2_MAX_LINE = 7.5;
const P2_EARLY_FIRST_GOAL_MAX = 4;
const P2_EARLY_MIN_LINE = 7.25;
const P3_FIRST_GOAL_MIN = 5;
const P3_FIRST_GOAL_MAX = 9;
const P3_MIN_LINE = 5.5;
const P3_MAX_LINE = 7.5;
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
$allowedPatterns = ['super', 'super1', 'super2', 'slow', 'hah', 'p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p8', 'p9', 'p10', 'p11'];
$patternKey = isset($_GET['pattern']) && in_array($_GET['pattern'], $allowedPatterns, true)
    ? $_GET['pattern']
    : 'super';
$patternCode = $patternKey === 'slow' ? 'S-LOW' : strtoupper($patternKey);
$isOver15Pattern = $patternKey === 'hah' || preg_match('/^p(?:[1-9]|10|11)$/', $patternKey) === 1;
$targetGoals = $isOver15Pattern ? 2 : 3;
$targetLabel = $isOver15Pattern
    ? 'Over 1.5 gol babak kedua'
    : 'Over 2.5 gol babak kedua';
$lineRequirements = [
    'super' => '≥ 5.75', 'super1' => '≥ 6.75', 'super2' => '≥ 7.25', 'slow' => '≥ 5.75',
    'hah' => 'Tanpa syarat', 'p1' => '≥ 5.75', 'p2' => '5.75–7.5',
    'p3' => '5.5–7.5', 'p4' => '≥ 5.5', 'p5' => 'Tanpa syarat',
    'p6' => '≤ 6.25', 'p7' => 'Tanpa syarat', 'p8' => '≥ 6',
    'p9' => 'Tanpa syarat', 'p10' => '≥ 5.75', 'p11' => '≥ 5.75',
];
$lineRequirement = $lineRequirements[$patternKey];

// Aturan pattern diturunkan dari data 23/07 & 28/07, jadi hari-hari itu in-sample:
// akurasinya hampir selalu 100% dan bukan bukti apa pun. Verdict dihitung hanya
// dari match sejak tanggal ini. Bisa digeser lewat ?since=dd/mm/yyyy.
const RULES_LOCKED_FROM = '29/07/2026';
const MIN_EVAL_SAMPLE = 10;
$lockedText = RULES_LOCKED_FROM;
if (isset($_GET['since']) && preg_match('#^\d{2}/\d{2}/\d{4}$#', (string)$_GET['since']) === 1) {
    $parsed = DateTime::createFromFormat('d/m/Y H:i', $_GET['since'] . ' 00:00');
    if ($parsed) {
        $lockedText = (string)$_GET['since'];
    }
}
$lockedDate = DateTime::createFromFormat('d/m/Y H:i', $lockedText . ' 00:00');
$lockedTs = $lockedDate ? $lockedDate->getTimestamp() : 0;

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
$baselineEval = ['hits' => 0, 'total' => 0];   // baseline khusus data sejak aturan dikunci
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

        // Timeline scraper kadang terputus sebelum laga selesai. Jika skor akhir
        // mencatat lebih banyak gol, pakai selisih FT-HT agar hasil pattern tidak
        // keliru hanya karena event individual yang hilang.
        $scoreDerivedGoals2H = max(0, ($finalHomeRaw + $finalAwayRaw) - ($htHome + $htAway));
        $goals2H = max($goals2H, $scoreDerivedGoals2H);

        // Baseline dihitung sebelum filter pattern, jadi mencakup semua match
        // selesai — termasuk yang tidak memenuhi pattern.
        $day = substr($dateText, 0, 10);
        $rowTs = $date ? $date->getTimestamp() : 0;
        $isEval = $lockedTs > 0 && $rowTs >= $lockedTs;
        $baselineHit = $goals2H >= $targetGoals;
        $baseline['total']++;
        $baseline['hits'] += $baselineHit ? 1 : 0;
        if ($isEval) {
            $baselineEval['total']++;
            $baselineEval['hits'] += $baselineHit ? 1 : 0;
        }
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
        $nonDrawSecondGoalWindow = $isDraw
            || !in_array($htHome + $htAway, [3, 5], true)
            || ($secondGoal !== null
                && $secondGoal >= SUPER_NON_DRAW_SECOND_GOAL_MIN
                && $secondGoal <= SUPER_NON_DRAW_SECOND_GOAL_MAX);

        if ($patternKey === 'p1') {
            $totalHt = $htHome + $htAway;
            $matchesPattern = abs($htHome - $htAway) === 1
                && $firstGoal <= P1_FIRST_GOAL_MAX
                && $ko >= P1_MIN_LINE
                && ($totalHt !== 1 || $firstGoal <= P1_LOW_TOTAL_FIRST_GOAL_MAX)
                && ($totalHt !== 5 || ($secondGoal !== null
                    && $secondGoal >= P1_HIGH_TOTAL_SECOND_GOAL_MIN
                    && $secondGoal <= P1_HIGH_TOTAL_SECOND_GOAL_MAX));
            $branch = 'Selisih HT tepat 1';
        } elseif ($patternKey === 'p2') {
            $matchesPattern = (($htHome === 2 && $htAway === 1) || ($htHome === 1 && $htAway === 2))
                && $firstGoal <= P2_FIRST_GOAL_MAX
                && $ko >= P2_MIN_LINE
                && $ko <= P2_MAX_LINE
                && ($firstGoal > P2_EARLY_FIRST_GOAL_MAX || $ko >= P2_EARLY_MIN_LINE);
            $branch = 'HT 2-1 / 1-2';
        } elseif ($patternKey === 'p3') {
            $matchesPattern = ($htHome + $htAway) === 3
                && $firstGoal >= P3_FIRST_GOAL_MIN
                && $firstGoal <= P3_FIRST_GOAL_MAX
                && $ko >= P3_MIN_LINE
                && $ko <= P3_MAX_LINE;
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
                && $firstGoal <= P11_FIRST_GOAL_MAX
                && $ko >= P11_MIN_LINE;
            $branch = 'Total HT 3 · gol-1 ≤ 12';
        } elseif ($patternKey === 'hah') {
            $matchesPattern = $htHome === 2
                && $htAway === 1
                && $goalSides1H === ['home', 'away', 'home'];
            $branch = 'Home–Away–Home';
        } elseif ($patternKey === 'super1') {
            $matchesPattern = ($htHome + $htAway) === SUPER1_TOTAL_HT
                && $ko >= SUPER1_MIN_LINE
                && $firstGoal <= SUPER1_FIRST_GOAL_MAX
                && (abs($htHome - $htAway) !== 3 || $ko >= SUPER1_ONE_SIDED_MIN_LINE);
            $branch = 'Total HT tepat 3';
        } elseif ($patternKey === 'slow') {
            // Sama seperti SUPER2 tapi ambang line lebih rendah; tanpa syarat khusus seri.
            $matchesPattern = abs($htHome - $htAway) <= 1
                && $firstGoal <= SLOW_FIRST_GOAL_MAX
                && $ko >= SLOW_MIN_LINE
                && (!$isDraw || $drawException)
                && $nonDrawSecondGoalWindow
                && ($htHome + $htAway) !== 1
                && (($htHome + $htAway) !== 3
                    || $firstGoal > SLOW_TOTAL3_EARLY_FIRST_MAX
                    || $ko >= SLOW_TOTAL3_EARLY_MIN_LINE)
                && (!($isDraw && ($htHome + $htAway) === 4
                    && $firstGoal >= SLOW_DRAW4_LATE_FIRST_MIN
                    && $secondGoal !== null
                    && $secondGoal <= SLOW_DRAW4_EARLY_SECOND_MAX)
                    || $ko <= SLOW_DRAW4_MAX_LINE)
                && (($htHome + $htAway) !== 5
                    || $firstGoal > SLOW_TOTAL5_EARLY_FIRST_MAX
                    || $lastGoal1H >= SLOW_TOTAL5_LAST_GOAL_MIN)
                && (($htHome + $htAway) !== 6
                    || $firstGoal <= SLOW_TOTAL6_FIRST_GOAL_MAX);
            $branch = $isDraw ? 'HT seri' : 'Selisih HT 1';
        } elseif ($patternKey === 'super2') {
            $matchesPattern = abs($htHome - $htAway) <= 1
                && $firstGoal <= SUPER2_FIRST_GOAL_MAX
                && $ko >= SUPER2_MIN_LINE
                && (($htHome + $htAway) !== 5 || ($secondGoal !== null
                    && $secondGoal >= SUPER2_TOTAL5_SECOND_GOAL_MIN
                    && $secondGoal <= SUPER2_TOTAL5_SECOND_GOAL_MAX))
                && (!($isDraw && ($htHome + $htAway) === 4) || ($secondGoal !== null
                    && $secondGoal >= SUPER2_DRAW4_SECOND_GOAL_MIN
                    && $secondGoal <= SUPER2_DRAW4_SECOND_GOAL_MAX));
            $branch = $isDraw ? 'HT seri' : 'Selisih HT 1';
        } else {
            $matchesPattern = abs($htHome - $htAway) <= SUPER_MAX_HT_DIFF
                && $firstGoal <= SUPER_FIRST_GOAL_MAX
                && $ko >= SUPER_MIN_LINE
                && (!$isDraw || $drawException)
                && $nonDrawSecondGoalWindow;
            $branch = $isDraw ? 'Seri + syarat khusus' : 'Selisih HT 1';
        }

        if (!$matchesPattern) {
            continue;
        }

        $qualified[] = [
            'timestamp' => $rowTs,
            'datetime' => $dateText,
            'day' => $day,
            'eval' => $isEval,
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

$evalRows = array_values(array_filter($qualified, static fn($row) => $row['eval']));
$inSampleRows = array_values(array_filter($qualified, static fn($row) => !$row['eval']));

$allStats = summarize($qualified);
$evalStats = summarize($evalRows);
$inSampleStats = summarize($inSampleRows);
$nonDrawStats = summarize($nonDrawRows);
$drawStats = summarize($drawRows);
$failures = array_values(array_filter($qualified, static fn($row) => !$row['hit']));
// Verdict memakai SEMUA tanggal. Rincian in-sample vs data evaluasi tetap
// ditampilkan di bawah sebagai peringatan, tapi tidak lagi memotong sampel.
$ci = wilson95($allStats['hits'], $allStats['total']);
$ciEval = wilson95($evalStats['hits'], $evalStats['total']);

$baselineRate = percent($baseline['hits'], $baseline['total']);
$baselineEvalRate = percent($baselineEval['hits'], $baselineEval['total']);
$edge = ($allStats['rate'] === null || $baselineRate === null) ? null : $allStats['rate'] - $baselineRate;
$edgeEval = ($evalStats['rate'] === null || $baselineEvalRate === null)
    ? null
    : $evalStats['rate'] - $baselineEvalRate;

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
    $dayDate = DateTime::createFromFormat('d/m/Y H:i', $day . ' 00:00');
    $dayRows[] = [
        'day' => $day,
        'is_eval' => $lockedTs > 0 && $dayDate && $dayDate->getTimestamp() >= $lockedTs,
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

// Verdict utama hanya memakai data evaluasi. Data in-sample dan gabungan tetap
// ditampilkan sebagai konteks, tetapi tidak memengaruhi label edge utama.
$hasEvaluation = $evalStats['total'] > 0;
$primaryStats = $allStats;
$primaryBaselineRate = $baselineRate;
$primaryEdge = $edge;
$primaryCi = $ci;
$primaryScope = 'semua tanggal';

if ($primaryStats['total'] === 0) {
    $verdict = 'BELUM ADA SAMPEL';
    $verdictClass = 'neutral';
    $verdictText = "Belum ada match selesai yang memenuhi pola {$patternCode}.";
} elseif ($primaryEdge !== null && $primaryCi[0] !== null && $primaryCi[0] <= $primaryBaselineRate) {
    $verdict = 'EDGE BELUM TERBUKTI';
    $verdictClass = 'down';
    $verdictText = "Akurasi {$patternCode} semua tanggal " . pct($primaryStats['rate']) . ' vs baseline semua match '
        . pct($primaryBaselineRate) . ' (' . signed($primaryEdge) . '), tetapi batas bawah CI 95% ('
        . pct($primaryCi[0]) . ') masih di bawah/menyentuh baseline.';
} elseif ($primaryEdge !== null && $primaryEdge <= 0) {
    $verdict = 'TIDAK ADA EDGE';
    $verdictClass = 'down';
    $verdictText = "{$patternCode} semua tanggal (" . pct($primaryStats['rate'])
        . ') tidak lebih baik daripada baseline semua match (' . pct($primaryBaselineRate) . ').';
} else {
    $verdict = 'EDGE POSITIF';
    $verdictClass = 'same';
    $verdictText = "{$patternCode} semua tanggal " . pct($primaryStats['rate'])
        . ' vs baseline semua match ' . pct($primaryBaselineRate) . ' (' . signed($primaryEdge)
        . '), batas bawah CI 95% ' . pct($primaryCi[0]) . ' masih di atas baseline.';
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
      <option value="slow" <?= $patternKey === 'slow' ? 'selected' : '' ?>>S-LOW — Over 2.5 babak kedua</option>
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
    <div class="rule"><b>SUPER1</b> — total gol HT tepat 3 · gol pertama ≤ 25' · line awal ≥ 6.75 · jika HT 3-0/0-3, line awal ≥ 7.5.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 3).</span></div>
  <?php elseif ($patternKey === 'super2'): ?>
    <div class="rule"><b>SUPER2</b> — selisih HT ≤ 1 · gol pertama ≤ 8' · line awal ≥ 7.25 · jika total HT 5, gol kedua menit 9'–30' · jika HT 2-2, gol kedua menit 14'–30'.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 3).</span></div>
  <?php elseif ($patternKey === 'slow'): ?>
    <div class="rule"><b>S-LOW</b> — selisih HT ≤ 1 · gol pertama ≤ 8' · line awal ≥ 5.75 · buang total HT 1 · total HT 3 dengan gol-1 ≤ 4': line ≥ 7.25 · HT 2-2 dengan gol-1 ≥ 7' dan gol-2 ≤ 10': line ≤ 6.25 · total HT 5 dengan gol-1 ≤ 5': gol terakhir 1H ≥ 30' · total HT 6: gol-1 ≤ 7'.</div>
  <?php elseif ($patternKey === 'hah'): ?>
    <div class="rule"><b>HAH</b> — urutan gol 1H Home–Away–Home, skor HT 2-1, tanpa syarat menit atau line.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
  <?php elseif ($patternKey === 'p1'): ?>
    <div class="rule"><b>P1</b> — selisih HT tepat 1 · gol pertama ≤ 12' · line awal ≥ 5.75 · jika total HT 1, gol pertama ≤ 6' · jika total HT 5, gol kedua menit 9'–30'.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
  <?php elseif ($patternKey === 'p2'): ?>
    <div class="rule"><b>P2</b> — HT 2-1 / 1-2 · gol pertama ≤ 15' · line awal 5.75–7.5 · jika gol pertama ≤ 4', line wajib ≥ 7.25.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
  <?php elseif ($patternKey === 'p3'): ?>
    <div class="rule"><b>P3</b> — total gol HT tepat 3 · gol pertama menit 5'–9' · line awal 5.5–7.5.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
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
    <div class="rule"><b>P11</b> — total gol HT tepat 3 (low) · gol pertama ≤ 12' · line awal ≥ 5.75.<br><span class="muted">Target: <b><?= e($targetLabel) ?></b> (HIT jika gol 2H ≥ 2).</span></div>
  <?php else: ?>
    <div class="rule"><b>SUPER</b> — <b>selisih HT ≤ 1</b>; jika HT seri, gol ke-2 ≤ 25' dan gol terakhir 1H ≥ 35' · gol pertama ≤ 6' · line awal ≥ 5.75 · jika total HT 3 atau 5, gol ke-2 harus menit 9'–30'.<br><span class="muted">Hasil semua tanggal: <b><?= $allStats['hits'] ?>/<?= $allStats['total'] ?> (<?= pct($allStats['rate']) ?>)</b> · evaluasi: <b><?= $evalStats['hits'] ?>/<?= $evalStats['total'] ?> (<?= pct($evalStats['rate']) ?>)</b>.</span></div>
  <?php endif; ?>
  <?php if ($error !== null): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

  <section class="card verdict <?= e($verdictClass) ?>">
    <div class="tag"><?= e($verdict) ?></div>
    <div class="big"><?= pct($primaryStats['rate']) ?> <span class="muted" style="font-size:16px">(<?= $primaryStats['hits'] ?>/<?= $primaryStats['total'] ?> <?= e($primaryScope) ?>)</span></div>
    <div class="muted"><?= e($verdictText) ?></div>
  </section>

  <section class="grid">
    <div class="card stat"><div class="label"><?= e($patternCode) ?> — semua tanggal</div><div class="value" style="color:<?= $edge !== null && $edge > 0 ? 'var(--green)' : 'var(--red)' ?>"><?= $allStats['hits'] ?>/<?= $allStats['total'] ?></div><div class="muted"><?= pct($allStats['rate']) ?> · otomatis ikut data baru</div></div>
    <div class="card stat"><div class="label">Baseline semua match</div><div class="value"><?= pct($baselineRate) ?></div><div class="muted"><?= $baseline['hits'] ?>/<?= $baseline['total'] ?> tanpa filter</div></div>
    <div class="card stat"><div class="label">Edge semua tanggal</div><div class="value" style="color:<?= $edge !== null && $edge > 0 ? 'var(--green)' : 'var(--red)' ?>"><?= signed($edge) ?></div><div class="muted">dibanding baseline semua match</div></div>
    <div class="card stat"><div class="label">Data baru sejak <?= e($lockedText) ?></div><div class="value"><?= $evalStats['hits'] ?>/<?= $evalStats['total'] ?></div><div class="muted"><?= pct($evalStats['rate']) ?> · rincian evaluasi terbaru</div></div>
    <div class="card stat"><div class="label">Sebelumnya (in-sample)</div><div class="value" style="color:var(--muted)"><?= $inSampleStats['hits'] ?>/<?= $inSampleStats['total'] ?></div><div class="muted"><?= pct($inSampleStats['rate']) ?> · aturan diturunkan dari sini</div></div>
    <div class="card stat"><div class="label">Target aktif</div><div class="value">O<?= $targetGoals === 2 ? '1.5' : '2.5' ?></div><div class="muted">HIT jika ≥ <?= $targetGoals ?> gol 2H</div></div>
    <div class="card stat"><div class="label">Syarat line</div><div class="value"><?= e($lineRequirement) ?></div><div class="muted">line KO</div></div>
    <div class="card stat"><div class="label">HT tidak seri — semua tanggal</div><div class="value"><?= $nonDrawStats['hits'] ?>/<?= $nonDrawStats['total'] ?></div><div class="muted"><?= pct($nonDrawStats['rate']) ?></div></div>
    <div class="card stat"><div class="label">HT seri + pengecualian — semua tanggal</div><div class="value"><?= $drawStats['hits'] ?>/<?= $drawStats['total'] ?></div><div class="muted"><?= pct($drawStats['rate']) ?></div></div>
  </section>

  <section class="card stat">
    <div class="label">Interval keyakinan Wilson 95% (semua tanggal)</div>
    <div class="value" style="font-size:18px"><?= pct($primaryCi[0]) ?> – <?= pct($primaryCi[1]) ?></div>
    <div class="muted">Dihitung dari seluruh <?= $primaryStats['hits'] ?>/<?= $primaryStats['total'] ?> match yang memenuhi <?= e($patternCode) ?>. Data baru otomatis ikut dihitung ketika masuk ke CSV. Baseline semua match: <?= pct($primaryBaselineRate) ?>.</div>
  </section>

  <section class="section">
    <h2>Per hari — pattern vs baseline hari yang sama</h2>
    <?php if (!$dayRows): ?>
      <div class="card empty">Belum ada data.</div>
    <?php else: ?>
      <div class="tablebox"><table><thead><tr><th>Hari</th><th>Status</th><th class="num"><?= e($patternCode) ?></th><th class="num">Akurasi</th><th class="num">Baseline</th><th class="num">Edge</th></tr></thead><tbody>
      <?php foreach ($dayRows as $d): ?><tr>
        <td><?= e($d['day']) ?></td>
        <td><?= $d['is_eval'] ? '<b style="color:var(--blue)">evaluasi</b>' : '<span class="muted">in-sample</span>' ?></td>
        <td class="num"><?= $d['hits'] ?>/<?= $d['total'] ?></td>
        <td class="num"><?= pct($d['rate']) ?></td>
        <td class="num"><?= pct($d['b_rate']) ?> <span class="muted">(<?= $d['b_hits'] ?>/<?= $d['b_total'] ?>)</span></td>
        <td class="num <?= $d['edge'] !== null && $d['edge'] > 0 ? 'hit' : 'miss' ?>"><?= signed($d['edge']) ?></td>
      </tr><?php endforeach; ?>
      </tbody></table></div>
      <p class="foot">Verdict utama memakai <b>semua tanggal</b>, sehingga data baru otomatis memperbarui angka 30/30. Kolom status harian tetap membedakan in-sample dan evaluasi agar performa terbaru mudah diperiksa.</p>
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
