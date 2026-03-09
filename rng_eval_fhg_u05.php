<?php
require_once 'koneksi.php';

$today = date('Y-m-d');

$leagueParam = $_GET['league'] ?? '';
$dateFromInput = $_GET['date_from'] ?? $today;
$dateToInput = $_GET['date_to'] ?? $today;
$timeFromInput = $_GET['time_from'] ?? '00:00';
$timeToInput = $_GET['time_to'] ?? '23:59';
$cutoffInput = $_GET['cutoff'] ?? '';
$trainRatio = (float)($_GET['train_ratio'] ?? '0.7');
$bucketHours = (int)($_GET['bucket_hours'] ?? 8);
$signalThreshold = (float)($_GET['signal_p'] ?? '0.70');
$modelType = $_GET['model'] ?? 'poisson';
$blend = ($_GET['blend'] ?? '1') !== '0';
$priorK = (float)($_GET['prior_k'] ?? '5');
$blendK = (float)($_GET['blend_k'] ?? '30');
$limitRows = (int)($_GET['limit'] ?? 0);

if ($trainRatio <= 0 || $trainRatio >= 1) $trainRatio = 0.7;
if ($bucketHours <= 0 || $bucketHours > 24) $bucketHours = 8;
if ($signalThreshold <= 0 || $signalThreshold >= 1) $signalThreshold = 0.70;
if (!in_array($modelType, ['poisson', 'binary'], true)) $modelType = 'poisson';
if ($priorK <= 0) $priorK = 5.0;
if ($blendK <= 0) $blendK = 30.0;
if ($limitRows < 0) $limitRows = 0;

$parsedFrom = strtotime($dateFromInput);
$parsedTo = strtotime($dateToInput);
$dateFromVal = $parsedFrom ? date('Y-m-d', $parsedFrom) : $today;
$dateToVal = $parsedTo ? date('Y-m-d', $parsedTo) : $today;
if ($dateFromVal > $dateToVal) {
    $tmp = $dateFromVal;
    $dateFromVal = $dateToVal;
    $dateToVal = $tmp;
}

$timeFromVal = preg_match('/^\d{2}:\d{2}$/', $timeFromInput) ? $timeFromInput : '00:00';
$timeToVal = preg_match('/^\d{2}:\d{2}$/', $timeToInput) ? $timeToInput : '23:59';

$timeFromSql = $conn->real_escape_string($timeFromVal . ':00');
$timeToSql = $conn->real_escape_string($timeToVal . ':59');
$dateFromSql = $conn->real_escape_string($dateFromVal);
$dateToSql = $conn->real_escape_string($dateToVal);
$isOvernight = $timeFromVal > $timeToVal;

$matchCondition = "((fh_home + fh_away) < 1)";

$where = " WHERE ft_home IS NOT NULL AND ft_away IS NOT NULL AND DATE(match_time) >= '$dateFromSql' AND DATE(match_time) <= '$dateToSql'";
if ($isOvernight) {
    $where .= " AND (TIME(match_time) >= '$timeFromSql' OR TIME(match_time) <= '$timeToSql')";
} else {
    $where .= " AND (TIME(match_time) >= '$timeFromSql' AND TIME(match_time) <= '$timeToSql')";
}
if (!empty($leagueParam)) {
    $leagueEsc = $conn->real_escape_string($leagueParam);
    $where .= " AND league = '$leagueEsc'";
}

$limitClause = $limitRows > 0 ? " LIMIT $limitRows" : "";

$query = "
    SELECT match_time, home_team, away_team, league, fh_home, fh_away, ft_home, ft_away
    FROM matches
    $where
    ORDER BY match_time ASC
    $limitClause
";

function bucket_label(string $timeStr, int $bucketHours): string {
    $hour = (int)substr($timeStr, 0, 2);
    $start = (int)(floor($hour / $bucketHours) * $bucketHours);
    $end = $start + $bucketHours - 1;
    if ($end > 23) $end = 23;
    return sprintf('%02d-%02d', $start, $end);
}

function clamp01(float $p): float {
    if ($p < 0.0) return 0.0;
    if ($p > 1.0) return 1.0;
    return $p;
}

function rate_smoothed(int $num, int $den, int $a = 1, int $b = 2): float {
    return ($num + $a) / max(1, ($den + $b));
}

function mean_smoothed(float $sum, int $den, float $priorMean, float $k): float {
    return ($sum + $priorMean * $k) / max(1.0, ($den + $k));
}

$result = $conn->query($query);

echo "<h2>RNG Evaluation: Prediksi FHG Under 0.5 (FH 0-0)</h2>";

echo "<div style='margin-bottom:10px; padding:10px; border:1px solid #ccc;'>";
echo "<b>Filter</b><br>";
echo "League: " . (empty($leagueParam) ? "<i>ALL</i>" : htmlspecialchars($leagueParam)) . "<br>";
echo "Date: " . htmlspecialchars($dateFromVal) . " sampai " . htmlspecialchars($dateToVal) . "<br>";
echo "Time: " . htmlspecialchars($timeFromVal) . " sampai " . htmlspecialchars($timeToVal) . ($isOvernight ? " (overnight)" : "") . "<br>";
echo "Bucket: " . (int)$bucketHours . " jam<br>";
echo "Model: " . htmlspecialchars($modelType) . "<br>";
echo "Blend baseline: " . ($blend ? "ON" : "OFF") . "<br>";
echo "Signal p>= " . htmlspecialchars((string)$signalThreshold) . "<br>";
if ($limitRows > 0) echo "Limit rows: " . (int)$limitRows . "<br>";
echo "</div>";

if (!$result) {
    echo "<div style='color:#b00'><b>SQL Error:</b> " . htmlspecialchars($conn->error) . "</div>";
    exit;
}

$trainHome = [];
$trainAway = [];
$trainBucket = [];
$trainBucketGlobal = [];
$trainAll = ['m' => 0, 'h' => 0];
$globalHome = ['m' => 0, 'gs' => 0.0, 'gc' => 0.0];
$globalAway = ['m' => 0, 'gs' => 0.0, 'gc' => 0.0];

$testRows = [];
$totalRows = 0;
$minTime = null;
$maxTime = null;

$allTimes = [];
while ($row = $result->fetch_assoc()) {
    $totalRows++;
    $mt = $row['match_time'];
    if ($minTime === null || $mt < $minTime) $minTime = $mt;
    if ($maxTime === null || $mt > $maxTime) $maxTime = $mt;
    $allTimes[] = $mt;
    $testRows[] = $row;
}

if ($totalRows === 0) {
    echo "<div style='color:#b00'><b>Tidak ada data</b> untuk filter ini.</div>";
    exit;
}

sort($allTimes);

$cutoffDateTime = '';
if (!empty($cutoffInput)) {
    $ts = strtotime($cutoffInput);
    if ($ts) $cutoffDateTime = date('Y-m-d H:i:s', $ts);
}
if (empty($cutoffDateTime)) {
    $idx = (int)floor($totalRows * $trainRatio);
    if ($idx < 1) $idx = 1;
    if ($idx >= $totalRows) $idx = $totalRows - 1;
    $cutoffDateTime = $allTimes[$idx];
}

$trainCount = 0;
$testCount = 0;

$finalTestRows = [];
foreach ($testRows as $row) {
    if ($row['match_time'] < $cutoffDateTime) {
        $trainCount++;

        $home = $row['home_team'];
        $away = $row['away_team'];
        $league = $row['league'] ?? '-';
        $timeOnly = substr($row['match_time'], 11, 5);
        $bucket = bucket_label($timeOnly, $bucketHours);
        $bucketKey = $league . '|' . $bucket;

        $fhHome = (int)$row['fh_home'];
        $fhAway = (int)$row['fh_away'];
        $isHit = (($fhHome + $fhAway) < 1) ? 1 : 0;

        if (!isset($trainBucket[$bucketKey])) $trainBucket[$bucketKey] = ['m' => 0, 'h' => 0];
        $trainBucket[$bucketKey]['m'] += 1;
        $trainBucket[$bucketKey]['h'] += $isHit;

        if (!isset($trainBucketGlobal[$bucket])) $trainBucketGlobal[$bucket] = ['m' => 0, 'h' => 0];
        $trainBucketGlobal[$bucket]['m'] += 1;
        $trainBucketGlobal[$bucket]['h'] += $isHit;
        $trainAll['m'] += 1;
        $trainAll['h'] += $isHit;

        if (!isset($trainHome[$home])) $trainHome[$home] = ['m' => 0, 'sc' => 0, 'cc' => 0];
        $trainHome[$home]['m'] += 1;
        if ($fhHome > 0) $trainHome[$home]['sc'] += 1;
        if ($fhAway > 0) $trainHome[$home]['cc'] += 1;
        if (!isset($trainHome[$home]['gs'])) $trainHome[$home]['gs'] = 0.0;
        if (!isset($trainHome[$home]['gc'])) $trainHome[$home]['gc'] = 0.0;
        $trainHome[$home]['gs'] += $fhHome;
        $trainHome[$home]['gc'] += $fhAway;

        if (!isset($trainAway[$away])) $trainAway[$away] = ['m' => 0, 'sc' => 0, 'cc' => 0];
        $trainAway[$away]['m'] += 1;
        if ($fhAway > 0) $trainAway[$away]['sc'] += 1;
        if ($fhHome > 0) $trainAway[$away]['cc'] += 1;
        if (!isset($trainAway[$away]['gs'])) $trainAway[$away]['gs'] = 0.0;
        if (!isset($trainAway[$away]['gc'])) $trainAway[$away]['gc'] = 0.0;
        $trainAway[$away]['gs'] += $fhAway;
        $trainAway[$away]['gc'] += $fhHome;

        $globalHome['m'] += 1;
        $globalHome['gs'] += $fhHome;
        $globalHome['gc'] += $fhAway;
        $globalAway['m'] += 1;
        $globalAway['gs'] += $fhAway;
        $globalAway['gc'] += $fhHome;
    } else {
        $testCount++;
        $finalTestRows[] = $row;
    }
}

if ($trainCount === 0 || $testCount === 0) {
    echo "<div style='color:#b00'><b>Split gagal:</b> train=$trainCount test=$testCount. Coba atur cutoff atau rentang tanggal.</div>";
    exit;
}

$eps = 1e-6;
$brierBase = 0.0;
$brierRaw = 0.0;
$brierFinal = 0.0;
$loglossBase = 0.0;
$loglossRaw = 0.0;
$loglossFinal = 0.0;

$signalsBase = 0;
$signalsRaw = 0;
$signalsFinal = 0;
$hitsOnSignalsBase = 0;
$hitsOnSignalsRaw = 0;
$hitsOnSignalsFinal = 0;

$hitsTest = 0;

$priorHomeScore = $globalHome['m'] > 0 ? ($globalHome['gs'] / $globalHome['m']) : 0.5;
$priorHomeConcede = $globalHome['m'] > 0 ? ($globalHome['gc'] / $globalHome['m']) : 0.5;
$priorAwayScore = $globalAway['m'] > 0 ? ($globalAway['gs'] / $globalAway['m']) : 0.5;
$priorAwayConcede = $globalAway['m'] > 0 ? ($globalAway['gc'] / $globalAway['m']) : 0.5;
$priorHitRate = $trainAll['m'] > 0 ? ($trainAll['h'] / $trainAll['m']) : 0.5;

$topPred = [];
foreach ($finalTestRows as $row) {
    $home = $row['home_team'];
    $away = $row['away_team'];
    $league = $row['league'] ?? '-';
    $timeOnly = substr($row['match_time'], 11, 5);
    $bucket = bucket_label($timeOnly, $bucketHours);
    $bucketKey = $league . '|' . $bucket;

    $fhHome = (int)$row['fh_home'];
    $fhAway = (int)$row['fh_away'];
    $y = (($fhHome + $fhAway) < 1) ? 1.0 : 0.0;
    $hitsTest += (int)$y;

    $bucketStats = $trainBucket[$bucketKey] ?? ['m' => 0, 'h' => 0];
    $bucketGlobalStats = $trainBucketGlobal[$bucket] ?? ['m' => 0, 'h' => 0];

    if ((int)$bucketStats['m'] > 0) {
        $pBase = rate_smoothed((int)$bucketStats['h'], (int)$bucketStats['m']);
    } elseif ((int)$bucketGlobalStats['m'] > 0) {
        $pBase = rate_smoothed((int)$bucketGlobalStats['h'], (int)$bucketGlobalStats['m']);
    } else {
        $pBase = (float)$priorHitRate;
    }
    $pBase = clamp01((float)$pBase);

    $hA = $trainHome[$home] ?? ['m' => 0, 'sc' => 0, 'cc' => 0, 'gs' => 0.0, 'gc' => 0.0];
    $aB = $trainAway[$away] ?? ['m' => 0, 'sc' => 0, 'cc' => 0, 'gs' => 0.0, 'gc' => 0.0];

    $pRaw = 0.0;
    if ($modelType === 'binary') {
        $pA_score = rate_smoothed((int)$hA['sc'], (int)$hA['m']);
        $pA_concede = rate_smoothed((int)$hA['cc'], (int)$hA['m']);
        $pB_score = rate_smoothed((int)$aB['sc'], (int)$aB['m']);
        $pB_concede = rate_smoothed((int)$aB['cc'], (int)$aB['m']);

        $pA = 0.5 * $pA_score + 0.5 * $pB_concede;
        $pB = 0.5 * $pB_score + 0.5 * $pA_concede;

        $pRaw = (1.0 - clamp01($pA)) * (1.0 - clamp01($pB));
    } else {
        $lambdaHomeScore = mean_smoothed((float)$hA['gs'], (int)$hA['m'], $priorHomeScore, $priorK);
        $lambdaHomeConcede = mean_smoothed((float)$hA['gc'], (int)$hA['m'], $priorHomeConcede, $priorK);
        $lambdaAwayScore = mean_smoothed((float)$aB['gs'], (int)$aB['m'], $priorAwayScore, $priorK);
        $lambdaAwayConcede = mean_smoothed((float)$aB['gc'], (int)$aB['m'], $priorAwayConcede, $priorK);

        $lambdaA = 0.5 * $lambdaHomeScore + 0.5 * $lambdaAwayConcede;
        $lambdaB = 0.5 * $lambdaAwayScore + 0.5 * $lambdaHomeConcede;
        $pRaw = exp(-max(0.0, ($lambdaA + $lambdaB)));
    }

    $pRaw = clamp01($pRaw);

    $mSum = (int)$hA['m'] + (int)$aB['m'];
    $w = $mSum > 0 ? ($mSum / ($mSum + $blendK)) : 0.0;
    $w = clamp01($w);
    $pFinal = $blend ? ($w * $pRaw + (1.0 - $w) * $pBase) : $pRaw;
    $pFinal = clamp01($pFinal);

    $brierBase += ($pBase - $y) * ($pBase - $y);
    $brierRaw += ($pRaw - $y) * ($pRaw - $y);
    $brierFinal += ($pFinal - $y) * ($pFinal - $y);

    $pBaseClamped = min(1.0 - $eps, max($eps, $pBase));
    $pRawClamped = min(1.0 - $eps, max($eps, $pRaw));
    $pFinalClamped = min(1.0 - $eps, max($eps, $pFinal));
    $loglossBase += -($y * log($pBaseClamped) + (1.0 - $y) * log(1.0 - $pBaseClamped));
    $loglossRaw += -($y * log($pRawClamped) + (1.0 - $y) * log(1.0 - $pRawClamped));
    $loglossFinal += -($y * log($pFinalClamped) + (1.0 - $y) * log(1.0 - $pFinalClamped));

    if ($pBase >= $signalThreshold) {
        $signalsBase++;
        $hitsOnSignalsBase += (int)$y;
    }
    if ($pRaw >= $signalThreshold) {
        $signalsRaw++;
        $hitsOnSignalsRaw += (int)$y;
    }
    if ($pFinal >= $signalThreshold) {
        $signalsFinal++;
        $hitsOnSignalsFinal += (int)$y;
    }

    $topPred[] = [
        'match_time' => $row['match_time'],
        'league' => $league,
        'home' => $home,
        'away' => $away,
        'bucket' => $bucket,
        'p_base' => $pBase,
        'p_raw' => $pRaw,
        'p_final' => $pFinal,
        'w' => $w,
        'm_sum' => $mSum,
        'y' => $y,
        'fh' => $fhHome . '-' . $fhAway,
    ];
}

$brierBase /= $testCount;
$brierRaw /= $testCount;
$brierFinal /= $testCount;
$loglossBase /= $testCount;
$loglossRaw /= $testCount;
$loglossFinal /= $testCount;

$baseRateTest = $hitsTest / $testCount;
$precisionBase = $signalsBase > 0 ? ($hitsOnSignalsBase / $signalsBase) : 0.0;
$precisionRaw = $signalsRaw > 0 ? ($hitsOnSignalsRaw / $signalsRaw) : 0.0;
$precisionFinal = $signalsFinal > 0 ? ($hitsOnSignalsFinal / $signalsFinal) : 0.0;

echo "<div style='margin:10px 0; padding:10px; border:1px solid #ccc;'>";
echo "<b>Split</b><br>";
echo "Total rows: $totalRows<br>";
echo "Train: $trainCount<br>";
echo "Test: $testCount<br>";
echo "Cutoff: " . htmlspecialchars($cutoffDateTime) . "<br>";
echo "</div>";

echo "<h3>Hasil Utama (Test)</h3>";
echo "<table border='1' cellpadding='6' cellspacing='0'>";
echo "<tr><th>Metrik</th><th>Baseline (league+bucket)</th><th>Model raw</th><th>Model final</th></tr>";
echo "<tr><td>Base rate FH 0-0 (test)</td><td colspan='3'><b>" . number_format($baseRateTest * 100, 2) . "%</b></td></tr>";
echo "<tr><td>Brier (lebih kecil lebih baik)</td><td>" . number_format($brierBase, 6) . "</td><td>" . number_format($brierRaw, 6) . "</td><td><b>" . number_format($brierFinal, 6) . "</b></td></tr>";
echo "<tr><td>Logloss (lebih kecil lebih baik)</td><td>" . number_format($loglossBase, 6) . "</td><td>" . number_format($loglossRaw, 6) . "</td><td><b>" . number_format($loglossFinal, 6) . "</b></td></tr>";
echo "<tr><td>Signals (p >= " . htmlspecialchars((string)$signalThreshold) . ")</td><td>$signalsBase</td><td>$signalsRaw</td><td><b>$signalsFinal</b></td></tr>";
echo "<tr><td>Winrate signals</td><td>" . ($signalsBase ? "<b>" . number_format($precisionBase * 100, 2) . "%</b>" : "-") . "</td><td>" . ($signalsRaw ? "<b>" . number_format($precisionRaw * 100, 2) . "%</b>" : "-") . "</td><td>" . ($signalsFinal ? "<b>" . number_format($precisionFinal * 100, 2) . "%</b>" : "-") . "</td></tr>";
echo "</table>";

usort($topPred, function($a, $b) {
    if ($a['p_final'] === $b['p_final']) return 0;
    return $a['p_final'] < $b['p_final'] ? 1 : -1;
});

$showN = min(30, count($topPred));
echo "<h3>Top $showN Prediksi (p_final terbesar)</h3>";
echo "<table border='1' cellpadding='6' cellspacing='0'>";
echo "<tr><th>Time</th><th>League</th><th>Match</th><th>Bucket</th><th>p_baseline</th><th>p_raw</th><th>p_final</th><th>w</th><th>FH</th><th>Hit?</th></tr>";
for ($i = 0; $i < $showN; $i++) {
    $r = $topPred[$i];
    $bg = ($r['y'] > 0.5) ? "#d4edda" : "#f8d7da";
    echo "<tr style='background:$bg'>";
    echo "<td>" . htmlspecialchars($r['match_time']) . "</td>";
    echo "<td>" . htmlspecialchars($r['league']) . "</td>";
    echo "<td>" . htmlspecialchars($r['home'] . " vs " . $r['away']) . "</td>";
    echo "<td>" . htmlspecialchars($r['bucket']) . "</td>";
    echo "<td>" . number_format($r['p_base'], 4) . "</td>";
    echo "<td>" . number_format($r['p_raw'], 4) . "</td>";
    echo "<td><b>" . number_format($r['p_final'], 4) . "</b></td>";
    echo "<td>" . number_format($r['w'], 3) . "</td>";
    echo "<td>" . htmlspecialchars($r['fh']) . "</td>";
    echo "<td><b>" . ($r['y'] > 0.5 ? "YES" : "NO") . "</b></td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>Catatan Interpretasi</h3>";
echo "<ul>";
echo "<li>Kalau model tidak konsisten mengalahkan baseline di Brier/Logloss, kemungkinan besar pola yang terlihat hanya noise RNG.</li>";
echo "<li>Fokus ke out-of-sample (split time) agar tidak tertipu data leakage.</li>";
echo "<li>Kalau coverage kecil tapi winrate tinggi, cek apakah sample signals terlalu sedikit.</li>";
echo "</ul>";

?>

