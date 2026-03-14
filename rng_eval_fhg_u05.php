<?php
require_once 'koneksi.php';
require_once 'matches_data_helper.php';

$today = date('Y-m-d');

$leagueParam = $_GET['league'] ?? '';
$dateFromInput = $_GET['date_from'] ?? $today;
$dateToInput = $_GET['date_to'] ?? $today;
$timeFromInput = $_GET['time_from'] ?? '00:00';
$timeToInput = $_GET['time_to'] ?? '23:59';
$cutoffInput = $_GET['cutoff'] ?? '';
$trainRatio = (float) ($_GET['train_ratio'] ?? '0.7');
$bucketHours = (int) ($_GET['bucket_hours'] ?? 8);
$signalThreshold = (float) ($_GET['signal_p'] ?? '0.70');
$modelType = $_GET['model'] ?? 'poisson';
$blend = ($_GET['blend'] ?? '1') !== '0';
$priorK = (float) ($_GET['prior_k'] ?? '5');
$blendK = (float) ($_GET['blend_k'] ?? '30');
$limitRows = (int) ($_GET['limit'] ?? 0);
$evalMode = $_GET['eval_mode'] ?? 'walk_forward';
$wfTestDays = (int) ($_GET['wf_test_days'] ?? 7);
$wfMinTrainRows = (int) ($_GET['wf_min_train_rows'] ?? 300);
$wfMinTestRows = (int) ($_GET['wf_min_test_rows'] ?? 30);
$wfMaxFolds = (int) ($_GET['wf_max_folds'] ?? 8);

if ($trainRatio <= 0 || $trainRatio >= 1) {
    $trainRatio = 0.7;
}
if ($bucketHours <= 0 || $bucketHours > 24) {
    $bucketHours = 8;
}
if ($signalThreshold <= 0 || $signalThreshold >= 1) {
    $signalThreshold = 0.70;
}
if (!in_array($modelType, ['poisson', 'binary'], true)) {
    $modelType = 'poisson';
}
if ($priorK <= 0) {
    $priorK = 5.0;
}
if ($blendK <= 0) {
    $blendK = 30.0;
}
if ($limitRows < 0) {
    $limitRows = 0;
}
if (!in_array($evalMode, ['walk_forward', 'single'], true)) {
    $evalMode = 'walk_forward';
}
if ($wfTestDays <= 0 || $wfTestDays > 30) {
    $wfTestDays = 7;
}
if ($wfMinTrainRows < 50) {
    $wfMinTrainRows = 50;
}
if ($wfMinTestRows < 10) {
    $wfMinTestRows = 10;
}
if ($wfMaxFolds <= 0 || $wfMaxFolds > 20) {
    $wfMaxFolds = 8;
}

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

$isOvernight = $timeFromVal > $timeToVal;

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function bucket_label(string $timeStr, int $bucketHours): string
{
    $hour = (int) substr($timeStr, 0, 2);
    $start = (int) (floor($hour / $bucketHours) * $bucketHours);
    $end = $start + $bucketHours - 1;
    if ($end > 23) {
        $end = 23;
    }
    return sprintf('%02d-%02d', $start, $end);
}

function clamp01(float $p): float
{
    if ($p < 0.0) {
        return 0.0;
    }
    if ($p > 1.0) {
        return 1.0;
    }
    return $p;
}

function rate_smoothed(int $num, int $den, int $a = 1, int $b = 2): float
{
    return ($num + $a) / max(1, ($den + $b));
}

function mean_smoothed(float $sum, int $den, float $priorMean, float $k): float
{
    return ($sum + $priorMean * $k) / max(1.0, ($den + $k));
}

function format_pct(float $value): string
{
    return number_format($value * 100, 2) . '%';
}

function format_metric(float $value): string
{
    return number_format($value, 6);
}

function format_period(?string $start, ?string $end): string
{
    if (empty($start) || empty($end)) {
        return '-';
    }
    return h($start) . ' &rarr; ' . h($end);
}

function buildTrainingStats(array $trainRows, int $bucketHours): array
{
    $trainHome = [];
    $trainAway = [];
    $trainBucket = [];
    $trainBucketGlobal = [];
    $trainAll = ['m' => 0, 'h' => 0];
    $globalHome = ['m' => 0, 'gs' => 0.0, 'gc' => 0.0];
    $globalAway = ['m' => 0, 'gs' => 0.0, 'gc' => 0.0];

    foreach ($trainRows as $row) {
        $home = $row['home_team'];
        $away = $row['away_team'];
        $league = $row['league'] ?? '-';
        $timeOnly = substr($row['match_time'], 11, 5);
        $bucket = bucket_label($timeOnly, $bucketHours);
        $bucketKey = $league . '|' . $bucket;

        $fhHome = (int) $row['fh_home'];
        $fhAway = (int) $row['fh_away'];
        $isHit = (($fhHome + $fhAway) < 1) ? 1 : 0;

        if (!isset($trainBucket[$bucketKey])) {
            $trainBucket[$bucketKey] = ['m' => 0, 'h' => 0];
        }
        $trainBucket[$bucketKey]['m'] += 1;
        $trainBucket[$bucketKey]['h'] += $isHit;

        if (!isset($trainBucketGlobal[$bucket])) {
            $trainBucketGlobal[$bucket] = ['m' => 0, 'h' => 0];
        }
        $trainBucketGlobal[$bucket]['m'] += 1;
        $trainBucketGlobal[$bucket]['h'] += $isHit;

        $trainAll['m'] += 1;
        $trainAll['h'] += $isHit;

        if (!isset($trainHome[$home])) {
            $trainHome[$home] = ['m' => 0, 'sc' => 0, 'cc' => 0, 'gs' => 0.0, 'gc' => 0.0];
        }
        $trainHome[$home]['m'] += 1;
        if ($fhHome > 0) {
            $trainHome[$home]['sc'] += 1;
        }
        if ($fhAway > 0) {
            $trainHome[$home]['cc'] += 1;
        }
        $trainHome[$home]['gs'] += $fhHome;
        $trainHome[$home]['gc'] += $fhAway;

        if (!isset($trainAway[$away])) {
            $trainAway[$away] = ['m' => 0, 'sc' => 0, 'cc' => 0, 'gs' => 0.0, 'gc' => 0.0];
        }
        $trainAway[$away]['m'] += 1;
        if ($fhAway > 0) {
            $trainAway[$away]['sc'] += 1;
        }
        if ($fhHome > 0) {
            $trainAway[$away]['cc'] += 1;
        }
        $trainAway[$away]['gs'] += $fhAway;
        $trainAway[$away]['gc'] += $fhHome;

        $globalHome['m'] += 1;
        $globalHome['gs'] += $fhHome;
        $globalHome['gc'] += $fhAway;

        $globalAway['m'] += 1;
        $globalAway['gs'] += $fhAway;
        $globalAway['gc'] += $fhHome;
    }

    return [
        'trainHome' => $trainHome,
        'trainAway' => $trainAway,
        'trainBucket' => $trainBucket,
        'trainBucketGlobal' => $trainBucketGlobal,
        'trainAll' => $trainAll,
        'priorHomeScore' => $globalHome['m'] > 0 ? ($globalHome['gs'] / $globalHome['m']) : 0.5,
        'priorHomeConcede' => $globalHome['m'] > 0 ? ($globalHome['gc'] / $globalHome['m']) : 0.5,
        'priorAwayScore' => $globalAway['m'] > 0 ? ($globalAway['gs'] / $globalAway['m']) : 0.5,
        'priorAwayConcede' => $globalAway['m'] > 0 ? ($globalAway['gc'] / $globalAway['m']) : 0.5,
        'priorHitRate' => $trainAll['m'] > 0 ? ($trainAll['h'] / $trainAll['m']) : 0.5,
    ];
}

function scoreMatch(array $row, array $stats, string $modelType, bool $blend, float $priorK, float $blendK, int $bucketHours): array
{
    $home = $row['home_team'];
    $away = $row['away_team'];
    $league = $row['league'] ?? '-';
    $timeOnly = substr($row['match_time'], 11, 5);
    $bucket = bucket_label($timeOnly, $bucketHours);
    $bucketKey = $league . '|' . $bucket;

    $fhHome = (int) $row['fh_home'];
    $fhAway = (int) $row['fh_away'];
    $y = (($fhHome + $fhAway) < 1) ? 1.0 : 0.0;

    $bucketStats = $stats['trainBucket'][$bucketKey] ?? ['m' => 0, 'h' => 0];
    $bucketGlobalStats = $stats['trainBucketGlobal'][$bucket] ?? ['m' => 0, 'h' => 0];
    if ((int) $bucketStats['m'] > 0) {
        $pBase = rate_smoothed((int) $bucketStats['h'], (int) $bucketStats['m']);
    } elseif ((int) $bucketGlobalStats['m'] > 0) {
        $pBase = rate_smoothed((int) $bucketGlobalStats['h'], (int) $bucketGlobalStats['m']);
    } else {
        $pBase = (float) $stats['priorHitRate'];
    }
    $pBase = clamp01((float) $pBase);

    $hA = $stats['trainHome'][$home] ?? ['m' => 0, 'sc' => 0, 'cc' => 0, 'gs' => 0.0, 'gc' => 0.0];
    $aB = $stats['trainAway'][$away] ?? ['m' => 0, 'sc' => 0, 'cc' => 0, 'gs' => 0.0, 'gc' => 0.0];

    if ($modelType === 'binary') {
        $pA_score = rate_smoothed((int) $hA['sc'], (int) $hA['m']);
        $pA_concede = rate_smoothed((int) $hA['cc'], (int) $hA['m']);
        $pB_score = rate_smoothed((int) $aB['sc'], (int) $aB['m']);
        $pB_concede = rate_smoothed((int) $aB['cc'], (int) $aB['m']);

        $pA = 0.5 * $pA_score + 0.5 * $pB_concede;
        $pB = 0.5 * $pB_score + 0.5 * $pA_concede;
        $pRaw = (1.0 - clamp01($pA)) * (1.0 - clamp01($pB));
    } else {
        $lambdaHomeScore = mean_smoothed((float) $hA['gs'], (int) $hA['m'], $stats['priorHomeScore'], $priorK);
        $lambdaHomeConcede = mean_smoothed((float) $hA['gc'], (int) $hA['m'], $stats['priorHomeConcede'], $priorK);
        $lambdaAwayScore = mean_smoothed((float) $aB['gs'], (int) $aB['m'], $stats['priorAwayScore'], $priorK);
        $lambdaAwayConcede = mean_smoothed((float) $aB['gc'], (int) $aB['m'], $stats['priorAwayConcede'], $priorK);

        $lambdaA = 0.5 * $lambdaHomeScore + 0.5 * $lambdaAwayConcede;
        $lambdaB = 0.5 * $lambdaAwayScore + 0.5 * $lambdaHomeConcede;
        $pRaw = exp(-max(0.0, ($lambdaA + $lambdaB)));
    }

    $pRaw = clamp01((float) $pRaw);
    $mSum = (int) $hA['m'] + (int) $aB['m'];
    $w = $mSum > 0 ? ($mSum / ($mSum + $blendK)) : 0.0;
    $w = clamp01($w);
    $pFinal = $blend ? ($w * $pRaw + (1.0 - $w) * $pBase) : $pRaw;
    $pFinal = clamp01($pFinal);

    return [
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

function evaluateFold(array $trainRows, array $testRows, array $config, string $label): array
{
    if (count($trainRows) === 0 || count($testRows) === 0) {
        return [
            'valid' => false,
            'label' => $label,
            'message' => 'Train atau test kosong.',
        ];
    }

    $stats = buildTrainingStats($trainRows, $config['bucketHours']);
    $eps = 1e-6;
    $sumBrierBase = 0.0;
    $sumBrierRaw = 0.0;
    $sumBrierFinal = 0.0;
    $sumLoglossBase = 0.0;
    $sumLoglossRaw = 0.0;
    $sumLoglossFinal = 0.0;
    $signalsBase = 0;
    $signalsRaw = 0;
    $signalsFinal = 0;
    $hitsOnSignalsBase = 0;
    $hitsOnSignalsRaw = 0;
    $hitsOnSignalsFinal = 0;
    $hitsTest = 0;
    $topPred = [];

    foreach ($testRows as $row) {
        $scored = scoreMatch(
            $row,
            $stats,
            $config['modelType'],
            $config['blend'],
            $config['priorK'],
            $config['blendK'],
            $config['bucketHours']
        );

        $y = $scored['y'];
        $hitsTest += (int) $y;

        $sumBrierBase += ($scored['p_base'] - $y) * ($scored['p_base'] - $y);
        $sumBrierRaw += ($scored['p_raw'] - $y) * ($scored['p_raw'] - $y);
        $sumBrierFinal += ($scored['p_final'] - $y) * ($scored['p_final'] - $y);

        $pBaseClamped = min(1.0 - $eps, max($eps, $scored['p_base']));
        $pRawClamped = min(1.0 - $eps, max($eps, $scored['p_raw']));
        $pFinalClamped = min(1.0 - $eps, max($eps, $scored['p_final']));

        $sumLoglossBase += -($y * log($pBaseClamped) + (1.0 - $y) * log(1.0 - $pBaseClamped));
        $sumLoglossRaw += -($y * log($pRawClamped) + (1.0 - $y) * log(1.0 - $pRawClamped));
        $sumLoglossFinal += -($y * log($pFinalClamped) + (1.0 - $y) * log(1.0 - $pFinalClamped));

        if ($scored['p_base'] >= $config['signalThreshold']) {
            $signalsBase++;
            $hitsOnSignalsBase += (int) $y;
        }
        if ($scored['p_raw'] >= $config['signalThreshold']) {
            $signalsRaw++;
            $hitsOnSignalsRaw += (int) $y;
        }
        if ($scored['p_final'] >= $config['signalThreshold']) {
            $signalsFinal++;
            $hitsOnSignalsFinal += (int) $y;
        }

        $scored['fold_label'] = $label;
        $topPred[] = $scored;
    }

    $testCount = count($testRows);
    $brierBase = $sumBrierBase / $testCount;
    $brierRaw = $sumBrierRaw / $testCount;
    $brierFinal = $sumBrierFinal / $testCount;
    $loglossBase = $sumLoglossBase / $testCount;
    $loglossRaw = $sumLoglossRaw / $testCount;
    $loglossFinal = $sumLoglossFinal / $testCount;

    return [
        'valid' => true,
        'label' => $label,
        'train_count' => count($trainRows),
        'test_count' => $testCount,
        'train_start' => $trainRows[0]['match_time'] ?? null,
        'train_end' => $trainRows[count($trainRows) - 1]['match_time'] ?? null,
        'test_start' => $testRows[0]['match_time'] ?? null,
        'test_end' => $testRows[count($testRows) - 1]['match_time'] ?? null,
        'hits_test' => $hitsTest,
        'base_rate_test' => $testCount > 0 ? ($hitsTest / $testCount) : 0.0,
        'sum_brier_base' => $sumBrierBase,
        'sum_brier_raw' => $sumBrierRaw,
        'sum_brier_final' => $sumBrierFinal,
        'sum_logloss_base' => $sumLoglossBase,
        'sum_logloss_raw' => $sumLoglossRaw,
        'sum_logloss_final' => $sumLoglossFinal,
        'brier_base' => $brierBase,
        'brier_raw' => $brierRaw,
        'brier_final' => $brierFinal,
        'logloss_base' => $loglossBase,
        'logloss_raw' => $loglossRaw,
        'logloss_final' => $loglossFinal,
        'signals_base' => $signalsBase,
        'signals_raw' => $signalsRaw,
        'signals_final' => $signalsFinal,
        'hits_on_signals_base' => $hitsOnSignalsBase,
        'hits_on_signals_raw' => $hitsOnSignalsRaw,
        'hits_on_signals_final' => $hitsOnSignalsFinal,
        'precision_base' => $signalsBase > 0 ? ($hitsOnSignalsBase / $signalsBase) : 0.0,
        'precision_raw' => $signalsRaw > 0 ? ($hitsOnSignalsRaw / $signalsRaw) : 0.0,
        'precision_final' => $signalsFinal > 0 ? ($hitsOnSignalsFinal / $signalsFinal) : 0.0,
        'beats_baseline' => ($brierFinal < $brierBase) && ($loglossFinal < $loglossBase),
        'top_predictions' => $topPred,
    ];
}

function buildSingleSplitFold(array $rows, float $trainRatio, string $cutoffInput): array
{
    $totalRows = count($rows);
    if ($totalRows < 2) {
        return [];
    }

    $cutoffDateTime = '';
    if (!empty($cutoffInput)) {
        $ts = strtotime($cutoffInput);
        if ($ts) {
            $cutoffDateTime = date('Y-m-d H:i:s', $ts);
        }
    }

    if ($cutoffDateTime === '') {
        $idx = (int) floor($totalRows * $trainRatio);
        if ($idx < 1) {
            $idx = 1;
        }
        if ($idx >= $totalRows) {
            $idx = $totalRows - 1;
        }
        $cutoffDateTime = $rows[$idx]['match_time'];
    }

    $trainRows = [];
    $testRows = [];
    foreach ($rows as $row) {
        if ($row['match_time'] < $cutoffDateTime) {
            $trainRows[] = $row;
        } else {
            $testRows[] = $row;
        }
    }

    return [[
        'label' => 'Single Split',
        'cutoff' => $cutoffDateTime,
        'train_rows' => $trainRows,
        'test_rows' => $testRows,
    ]];
}

function buildWalkForwardFolds(array $rows, int $testDays, int $minTrainRows, int $minTestRows, int $maxFolds): array
{
    $rowsByDate = [];
    foreach ($rows as $row) {
        $dateKey = substr($row['match_time'], 0, 10);
        if (!isset($rowsByDate[$dateKey])) {
            $rowsByDate[$dateKey] = [];
        }
        $rowsByDate[$dateKey][] = $row;
    }

    $uniqueDates = array_keys($rowsByDate);
    if (count($uniqueDates) < 2) {
        return [];
    }

    $startCursor = null;
    $runningTrainCount = 0;
    foreach ($uniqueDates as $index => $dateKey) {
        if ($runningTrainCount >= $minTrainRows) {
            $startCursor = $index;
            break;
        }
        $runningTrainCount += count($rowsByDate[$dateKey]);
    }

    if ($startCursor === null) {
        return [];
    }

    $folds = [];
    $cursor = $startCursor;
    $foldNumber = 1;
    while ($cursor < count($uniqueDates) && count($folds) < $maxFolds) {
        $testStartDate = $uniqueDates[$cursor];
        $testStartTs = strtotime($testStartDate . ' 00:00:00');
        if ($testStartTs === false) {
            break;
        }
        $testEndExclusive = date('Y-m-d', strtotime('+' . $testDays . ' days', $testStartTs));
        $testStartBoundary = $testStartDate . ' 00:00:00';
        $testEndBoundary = $testEndExclusive . ' 00:00:00';

        $trainRows = [];
        $testRows = [];
        foreach ($rows as $row) {
            if ($row['match_time'] < $testStartBoundary) {
                $trainRows[] = $row;
            } elseif ($row['match_time'] < $testEndBoundary) {
                $testRows[] = $row;
            }
        }

        if (count($trainRows) >= $minTrainRows && count($testRows) >= $minTestRows) {
            $folds[] = [
                'label' => 'Fold ' . $foldNumber,
                'train_rows' => $trainRows,
                'test_rows' => $testRows,
                'test_start_boundary' => $testStartBoundary,
                'test_end_boundary' => $testEndBoundary,
            ];
            $foldNumber++;
        }

        while ($cursor < count($uniqueDates) && $uniqueDates[$cursor] < $testEndExclusive) {
            $cursor++;
        }
    }

    return $folds;
}

function aggregateFoldResults(array $foldResults): array
{
    $agg = [
        'fold_count' => count($foldResults),
        'total_test_rows' => 0,
        'total_train_rows' => 0,
        'hits_test' => 0,
        'sum_brier_base' => 0.0,
        'sum_brier_raw' => 0.0,
        'sum_brier_final' => 0.0,
        'sum_logloss_base' => 0.0,
        'sum_logloss_raw' => 0.0,
        'sum_logloss_final' => 0.0,
        'signals_base' => 0,
        'signals_raw' => 0,
        'signals_final' => 0,
        'hits_on_signals_base' => 0,
        'hits_on_signals_raw' => 0,
        'hits_on_signals_final' => 0,
        'won_folds' => 0,
        'top_predictions' => [],
    ];

    foreach ($foldResults as $fold) {
        $agg['total_test_rows'] += $fold['test_count'];
        $agg['total_train_rows'] += $fold['train_count'];
        $agg['hits_test'] += $fold['hits_test'];
        $agg['sum_brier_base'] += $fold['sum_brier_base'];
        $agg['sum_brier_raw'] += $fold['sum_brier_raw'];
        $agg['sum_brier_final'] += $fold['sum_brier_final'];
        $agg['sum_logloss_base'] += $fold['sum_logloss_base'];
        $agg['sum_logloss_raw'] += $fold['sum_logloss_raw'];
        $agg['sum_logloss_final'] += $fold['sum_logloss_final'];
        $agg['signals_base'] += $fold['signals_base'];
        $agg['signals_raw'] += $fold['signals_raw'];
        $agg['signals_final'] += $fold['signals_final'];
        $agg['hits_on_signals_base'] += $fold['hits_on_signals_base'];
        $agg['hits_on_signals_raw'] += $fold['hits_on_signals_raw'];
        $agg['hits_on_signals_final'] += $fold['hits_on_signals_final'];
        if ($fold['beats_baseline']) {
            $agg['won_folds']++;
        }
        $agg['top_predictions'] = array_merge($agg['top_predictions'], $fold['top_predictions']);
    }

    $totalTestRows = max(1, $agg['total_test_rows']);
    $agg['base_rate_test'] = $agg['hits_test'] / $totalTestRows;
    $agg['brier_base'] = $agg['sum_brier_base'] / $totalTestRows;
    $agg['brier_raw'] = $agg['sum_brier_raw'] / $totalTestRows;
    $agg['brier_final'] = $agg['sum_brier_final'] / $totalTestRows;
    $agg['logloss_base'] = $agg['sum_logloss_base'] / $totalTestRows;
    $agg['logloss_raw'] = $agg['sum_logloss_raw'] / $totalTestRows;
    $agg['logloss_final'] = $agg['sum_logloss_final'] / $totalTestRows;
    $agg['precision_base'] = $agg['signals_base'] > 0 ? ($agg['hits_on_signals_base'] / $agg['signals_base']) : 0.0;
    $agg['precision_raw'] = $agg['signals_raw'] > 0 ? ($agg['hits_on_signals_raw'] / $agg['signals_raw']) : 0.0;
    $agg['precision_final'] = $agg['signals_final'] > 0 ? ($agg['hits_on_signals_final'] / $agg['signals_final']) : 0.0;

    usort($agg['top_predictions'], function ($a, $b) {
        if ($a['p_final'] === $b['p_final']) {
            return 0;
        }
        return $a['p_final'] < $b['p_final'] ? 1 : -1;
    });

    return $agg;
}

function buildVerdict(array $agg): array
{
    if (($agg['fold_count'] ?? 0) === 0) {
        return ['label' => 'Tidak cukup data', 'note' => 'Fold valid belum terbentuk dari filter yang dipilih.'];
    }

    $winShare = $agg['won_folds'] / max(1, $agg['fold_count']);
    $improvedBrier = $agg['brier_final'] < $agg['brier_base'];
    $improvedLogloss = $agg['logloss_final'] < $agg['logloss_base'];
    $enoughSignals = $agg['signals_final'] >= 20;

    if ($improvedBrier && $improvedLogloss && $winShare >= 0.60 && $enoughSignals) {
        return [
            'label' => 'Stabil',
            'note' => 'Model final konsisten mengalahkan baseline di beberapa fold dan coverage signal masih cukup sehat.',
        ];
    }

    if (($improvedBrier || $improvedLogloss) && $winShare >= 0.50) {
        $note = $enoughSignals
            ? 'Ada perbaikan out-of-sample, tetapi edge belum cukup dominan di semua fold.'
            : 'Ada tanda edge, tetapi jumlah signal masih tipis sehingga belum terlalu kuat secara praktis.';
        return [
            'label' => 'Lumayan stabil',
            'note' => $note,
        ];
    }

    return [
        'label' => 'Cenderung noise',
        'note' => 'Baseline masih lebih tahan banting atau performa model berubah-ubah antar fold.',
    ];
}

$dataSource = sabarajaDataConnectionReady($conn ?? null, $db_error ?? '') ? 'database' : (sabarajaDataCsvAvailable() ? 'csv' : 'unavailable');
$rows = sabarajaDataLoadMatchRows(
    $conn ?? null,
    $db_error ?? '',
    [
        'date_from' => $dateFromVal,
        'date_to' => $dateToVal,
        'time_from' => $timeFromVal,
        'time_to' => $timeToVal,
        'league' => $leagueParam,
        'limit' => $limitRows,
        'require_ft' => true,
    ]
);

echo '<style>
body{font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:24px;}
.container{max-width:1400px;margin:0 auto;}
.hero{background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;padding:22px 24px;border-radius:20px;box-shadow:0 20px 50px rgba(15,23,42,.18);margin-bottom:18px;}
.hero h2{margin:0 0 8px;font-size:28px;}
.hero p{margin:0;color:#cbd5e1;line-height:1.6;}
.panel{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:18px 20px;box-shadow:0 12px 32px rgba(148,163,184,.12);margin-bottom:16px;}
.panel h3{margin:0 0 12px;font-size:18px;}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:16px;}
.stat{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:16px;box-shadow:0 10px 24px rgba(148,163,184,.10);}
.stat .label{font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:#64748b;font-weight:700;}
.stat .value{font-size:28px;font-weight:800;margin-top:8px;color:#0f172a;}
.stat .note{font-size:13px;line-height:1.6;color:#475569;margin-top:8px;}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:14px;overflow:hidden;}
th,td{border:1px solid #e2e8f0;padding:10px 12px;text-align:left;font-size:13px;vertical-align:top;}
th{background:#e2e8f0;color:#0f172a;font-weight:700;}
.good{background:#ecfdf5;}
.bad{background:#fef2f2;}
.neutral{background:#f8fafc;}
.pill{display:inline-block;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;}
.pill.good{background:#dcfce7;color:#166534;}
.pill.warn{background:#fef3c7;color:#92400e;}
.pill.bad{background:#fee2e2;color:#991b1b;}
ul.note-list{margin:0;padding-left:18px;line-height:1.7;color:#475569;}
.muted{color:#64748b;}
</style>';

echo '<div class="container">';
echo '<div class="hero">';
echo '<h2>RNG Evaluation: Prediksi FHG Under 0.5 (FH 0-0)</h2>';
echo '<p>Mode default sekarang memakai expanding walk-forward backtest supaya edge lebih tahan banting terhadap noise dibanding single cutoff saja.</p>';
echo '</div>';

if ($dataSource === 'csv') {
    echo '<div class="panel"><span class="pill warn">Mode CSV</span><div style="margin-top:10px;color:#475569;line-height:1.7;">Database environment belum aktif, jadi evaluasi RNG membaca data langsung dari <code>matches.csv</code>.</div></div>';
} elseif ($dataSource === 'unavailable') {
    echo '<div class="panel bad"><b>Sumber data tidak tersedia.</b><br>Database belum aktif dan file <code>matches.csv</code> tidak bisa dibaca.</div>';
    echo '</div>';
    exit;
}

echo '<div class="panel">';
echo '<h3>Filter & Konfigurasi</h3>';
echo '<div class="stats">';
echo '<div class="stat"><div class="label">League</div><div class="value" style="font-size:20px;">' . (empty($leagueParam) ? 'ALL' : h($leagueParam)) . '</div><div class="note">Rentang tanggal ' . h($dateFromVal) . ' sampai ' . h($dateToVal) . '</div></div>';
echo '<div class="stat"><div class="label">Jam</div><div class="value" style="font-size:20px;">' . h($timeFromVal) . ' - ' . h($timeToVal) . '</div><div class="note">' . ($isOvernight ? 'Window overnight aktif.' : 'Window intraday biasa.') . '</div></div>';
echo '<div class="stat"><div class="label">Model</div><div class="value" style="font-size:20px;">' . h($modelType) . '</div><div class="note">Bucket ' . (int) $bucketHours . ' jam, blend ' . ($blend ? 'ON' : 'OFF') . ', signal p &ge; ' . h((string) $signalThreshold) . '</div></div>';
echo '<div class="stat"><div class="label">Mode Evaluasi</div><div class="value" style="font-size:20px;">' . h($evalMode) . '</div><div class="note">';
if ($evalMode === 'walk_forward') {
    echo 'Test ' . (int) $wfTestDays . ' hari, min train ' . (int) $wfMinTrainRows . ', min test ' . (int) $wfMinTestRows . ', max fold ' . (int) $wfMaxFolds;
} else {
    echo 'Train ratio ' . h((string) $trainRatio) . (!empty($cutoffInput) ? ', cutoff ' . h($cutoffInput) : ', cutoff otomatis');
}
echo '</div></div>';
echo '<div class="stat"><div class="label">Data Source</div><div class="value" style="font-size:20px;">' . h(strtoupper($dataSource)) . '</div><div class="note">' . ($dataSource === 'database' ? 'Query langsung ke tabel matches.' : 'Scan file matches.csv sebagai fallback.') . '</div></div>';
echo '</div>';
echo '</div>';

$minTime = null;
$maxTime = null;
foreach ($rows as $row) {
    $mt = $row['match_time'];
    if ($minTime === null || $mt < $minTime) {
        $minTime = $mt;
    }
    if ($maxTime === null || $mt > $maxTime) {
        $maxTime = $mt;
    }
}

$totalRows = count($rows);
if ($totalRows === 0) {
    echo '<div class="panel bad"><b>Tidak ada data</b> untuk filter ini.</div>';
    echo '</div>';
    exit;
}

$config = [
    'bucketHours' => $bucketHours,
    'signalThreshold' => $signalThreshold,
    'modelType' => $modelType,
    'blend' => $blend,
    'priorK' => $priorK,
    'blendK' => $blendK,
];

if ($evalMode === 'walk_forward') {
    $foldDefs = buildWalkForwardFolds($rows, $wfTestDays, $wfMinTrainRows, $wfMinTestRows, $wfMaxFolds);
} else {
    $foldDefs = buildSingleSplitFold($rows, $trainRatio, $cutoffInput);
}

$foldResults = [];
foreach ($foldDefs as $foldDef) {
    $fold = evaluateFold($foldDef['train_rows'], $foldDef['test_rows'], $config, $foldDef['label']);
    if ($fold['valid']) {
        if (isset($foldDef['cutoff'])) {
            $fold['cutoff'] = $foldDef['cutoff'];
        }
        $foldResults[] = $fold;
    }
}

echo '<div class="panel">';
echo '<h3>Data Coverage</h3>';
echo '<table>';
echo '<tr><th>Total rows</th><th>Periode data</th><th>Mode aktif</th><th>Fold valid</th></tr>';
echo '<tr>';
echo '<td>' . number_format($totalRows) . '</td>';
echo '<td>' . format_period($minTime, $maxTime) . '</td>';
echo '<td>' . h($evalMode) . '</td>';
echo '<td>' . number_format(count($foldResults)) . '</td>';
echo '</tr>';
echo '</table>';
echo '</div>';

if (count($foldResults) === 0) {
    echo '<div class="panel bad"><b>Fold valid tidak terbentuk.</b><br>Coba kurangi <code>wf_min_train_rows</code>, kecilkan <code>wf_min_test_rows</code>, atau perluas rentang tanggal.</div>';
    echo '</div>';
    exit;
}

$agg = aggregateFoldResults($foldResults);
$verdict = buildVerdict($agg);

if ($evalMode === 'walk_forward') {
    echo '<div class="panel">';
    echo '<h3>Per Fold Walk-Forward</h3>';
    echo '<table>';
    echo '<tr><th>Fold</th><th>Train Period</th><th>Test Period</th><th>Train</th><th>Test</th><th>Brier Base</th><th>Brier Final</th><th>Logloss Base</th><th>Logloss Final</th><th>Signals Final</th><th>Winrate Final</th><th>Status</th></tr>';
    foreach ($foldResults as $fold) {
        $rowClass = $fold['beats_baseline'] ? 'good' : 'bad';
        echo '<tr class="' . $rowClass . '">';
        echo '<td><b>' . h($fold['label']) . '</b></td>';
        echo '<td>' . format_period($fold['train_start'], $fold['train_end']) . '</td>';
        echo '<td>' . format_period($fold['test_start'], $fold['test_end']) . '</td>';
        echo '<td>' . number_format($fold['train_count']) . '</td>';
        echo '<td>' . number_format($fold['test_count']) . '</td>';
        echo '<td>' . format_metric($fold['brier_base']) . '</td>';
        echo '<td><b>' . format_metric($fold['brier_final']) . '</b></td>';
        echo '<td>' . format_metric($fold['logloss_base']) . '</td>';
        echo '<td><b>' . format_metric($fold['logloss_final']) . '</b></td>';
        echo '<td>' . number_format($fold['signals_final']) . '</td>';
        echo '<td>' . ($fold['signals_final'] > 0 ? format_pct($fold['precision_final']) : '-') . '</td>';
        echo '<td>' . ($fold['beats_baseline'] ? '<span class="pill good">Menang</span>' : '<span class="pill bad">Kalah</span>') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '</div>';
}

echo '<div class="panel">';
echo '<h3>' . ($evalMode === 'walk_forward' ? 'Agregat Walk-Forward' : 'Hasil Utama (Single Split Test)') . '</h3>';
echo '<table>';
echo '<tr><th>Metrik</th><th>Baseline (league+bucket)</th><th>Model raw</th><th>Model final</th></tr>';
echo '<tr><td>Base rate FH 0-0 (test)</td><td colspan="3"><b>' . format_pct($agg['base_rate_test']) . '</b></td></tr>';
echo '<tr><td>Brier (lebih kecil lebih baik)</td><td>' . format_metric($agg['brier_base']) . '</td><td>' . format_metric($agg['brier_raw']) . '</td><td><b>' . format_metric($agg['brier_final']) . '</b></td></tr>';
echo '<tr><td>Logloss (lebih kecil lebih baik)</td><td>' . format_metric($agg['logloss_base']) . '</td><td>' . format_metric($agg['logloss_raw']) . '</td><td><b>' . format_metric($agg['logloss_final']) . '</b></td></tr>';
echo '<tr><td>Signals (p &gt;= ' . h((string) $signalThreshold) . ')</td><td>' . number_format($agg['signals_base']) . '</td><td>' . number_format($agg['signals_raw']) . '</td><td><b>' . number_format($agg['signals_final']) . '</b></td></tr>';
echo '<tr><td>Winrate signals</td><td>' . ($agg['signals_base'] ? '<b>' . format_pct($agg['precision_base']) . '</b>' : '-') . '</td><td>' . ($agg['signals_raw'] ? '<b>' . format_pct($agg['precision_raw']) . '</b>' : '-') . '</td><td>' . ($agg['signals_final'] ? '<b>' . format_pct($agg['precision_final']) . '</b>' : '-') . '</td></tr>';
if ($evalMode === 'walk_forward') {
    echo '<tr><td>Fold menang vs baseline</td><td colspan="3"><b>' . number_format($agg['won_folds']) . ' / ' . number_format($agg['fold_count']) . '</b></td></tr>';
} elseif (!empty($foldResults[0]['cutoff'])) {
    echo '<tr><td>Cutoff</td><td colspan="3"><b>' . h($foldResults[0]['cutoff']) . '</b></td></tr>';
}
echo '</table>';
echo '</div>';

echo '<div class="stats">';
echo '<div class="stat"><div class="label">Verdict</div><div class="value" style="font-size:24px;">' . h($verdict['label']) . '</div><div class="note">' . h($verdict['note']) . '</div></div>';
echo '<div class="stat"><div class="label">Signals Final</div><div class="value">' . number_format($agg['signals_final']) . '</div><div class="note">Coverage signal akhir di semua test out-of-sample.</div></div>';
echo '<div class="stat"><div class="label">Winrate Final</div><div class="value">' . ($agg['signals_final'] ? format_pct($agg['precision_final']) : '-') . '</div><div class="note">Hitrate khusus signal yang lolos threshold.</div></div>';
echo '<div class="stat"><div class="label">Test Rows</div><div class="value">' . number_format($agg['total_test_rows']) . '</div><div class="note">Total sampel out-of-sample yang ikut evaluasi.</div></div>';
echo '</div>';

$showN = min(30, count($agg['top_predictions']));
echo '<div class="panel">';
echo '<h3>Top ' . number_format($showN) . ' Prediksi Out-of-Sample (p_final terbesar)</h3>';
echo '<table>';
echo '<tr><th>Fold</th><th>Time</th><th>League</th><th>Match</th><th>Bucket</th><th>p_baseline</th><th>p_raw</th><th>p_final</th><th>w</th><th>FH</th><th>Hit?</th></tr>';
for ($i = 0; $i < $showN; $i++) {
    $r = $agg['top_predictions'][$i];
    $rowClass = ($r['y'] > 0.5) ? 'good' : 'bad';
    echo '<tr class="' . $rowClass . '">';
    echo '<td><b>' . h($r['fold_label']) . '</b></td>';
    echo '<td>' . h($r['match_time']) . '</td>';
    echo '<td>' . h($r['league']) . '</td>';
    echo '<td>' . h($r['home'] . ' vs ' . $r['away']) . '</td>';
    echo '<td>' . h($r['bucket']) . '</td>';
    echo '<td>' . number_format($r['p_base'], 4) . '</td>';
    echo '<td>' . number_format($r['p_raw'], 4) . '</td>';
    echo '<td><b>' . number_format($r['p_final'], 4) . '</b></td>';
    echo '<td>' . number_format($r['w'], 3) . '</td>';
    echo '<td>' . h($r['fh']) . '</td>';
    echo '<td><b>' . ($r['y'] > 0.5 ? 'YES' : 'NO') . '</b></td>';
    echo '</tr>';
}
echo '</table>';
echo '</div>';

echo '<div class="panel">';
echo '<h3>Catatan Interpretasi</h3>';
echo '<ul class="note-list">';
echo '<li>Walk-forward lebih jujur dibanding single cutoff karena setiap fold hanya belajar dari masa lalu lalu dites di masa sesudahnya.</li>';
echo '<li>Kalau model final tidak konsisten mengalahkan baseline di Brier dan Logloss, kemungkinan besar pola yang terlihat masih noise RNG.</li>';
echo '<li>Signals yang sedikit tetapi winrate tinggi tetap harus dicurigai; coverage rendah sering membuat hasil terlihat lebih bagus dari kenyataan.</li>';
echo '<li>Kalau ingin langkah berikutnya, paling cocok adalah tambah threshold scan atau calibration report di atas output ini.</li>';
echo '</ul>';
echo '</div>';

echo '</div>';

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>
