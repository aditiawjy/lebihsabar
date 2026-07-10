<?php
// Analisis P77: cari signature (tanpa team) yang 100% akurat (h2c > 0).
require_once __DIR__ . '/dashboard_cache.php';

$csvFile = __DIR__ . '/goal_log.csv';
$csvExists = file_exists($csvFile) && is_readable($csvFile);

// --- reproduksi loading row seperti buildDashboardData ---
$rows = [];
if ($csvExists) {
    $fh = fopen($csvFile, 'r');
    if ($fh !== false) {
        fgetcsv($fh);
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < 7) continue;
            $row = array_pad($row, 10, '');
            if (trim($row[8] ?? '') !== 'OK' || trim($row[9] ?? '') !== 'OK') continue;
            $goalsStr = trim($row[4] ?? '');
            if ($goalsStr === '' && (int)($row[5] ?? 0) === 0 && (int)($row[6] ?? 0) === 0) continue;
            $rows[] = $row;
        }
        fclose($fh);
    }
}
$matches = parseMatches($rows);

// --- group by signature, hanya match h1c == 1 ---
$groups = [];
foreach ($matches as $m) {
    if (($m['h1c'] ?? 0) !== 1) continue;
    $sig = p77SingleGoalSignature($m);
    if (!isset($groups[$sig])) $groups[$sig] = ['total' => 0, 'hits' => 0];
    $groups[$sig]['total']++;
    if (($m['h2c'] ?? 0) > 0) $groups[$sig]['hits']++;
}

// --- minimum sample untuk dianggap layak ---
$MIN_SAMPLE = isset($argv[1]) ? (int)$argv[1] : 3;

$pure = [];   // 100% akurat
$impure = [];
foreach ($groups as $sig => $g) {
    if ($g['total'] < $MIN_SAMPLE) continue;
    if ($g['hits'] === $g['total']) {
        $pure[$sig] = $g;
    } else {
        $impure[$sig] = $g;
    }
}

// urutkan pure berdasarkan total desc
uasort($pure, fn($a, $b) => $b['total'] <=> $a['total']);

echo "=== P77 PURE SIGNATURES (100% h2c>0, min sample {$MIN_SAMPLE}) ===\n";
echo "Total signature unik (h1c=1): " . count($groups) . "\n";
echo "Pure (100%, >= min): " . count($pure) . " | Impure (>= min): " . count($impure) . "\n\n";

$totHits = 0; $totN = 0;
foreach ($pure as $sig => $g) {
    $totHits += $g['hits']; $totN += $g['total'];
    printf("  '%s' => true, // %d/%d\n", $sig, $g['hits'], $g['total']);
}
echo "\nGabungan pure: {$totHits}/{$totN} = " . ($totN ? round($totHits/$totN*100,2) : 0) . "%\n\n";

echo "--- Signature LAMA yang sekarang impure / hilang ---\n";
$oldKeys = [
    '15min|1|H|1-0|1|22','20min|1|A|0-1|9|20','15min|1|A|0-1|5|15','15min|1|H|1-0|4|7',
    '20min|1|A|0-1|6|19','20min|1|H|1-0|7|21','15min|1|A|0-1|2|11','15min|1|A|0-1|3|11',
    '15min|1|A|0-1|4|23','15min|1|A|0-1|5|11','15min|1|H|1-0|1|18','15min|1|H|1-0|3|16',
    '16min|1|A|0-1|4|11','16min|1|A|0-1|5|21','16min|1|H|1-0|1|14','16min|1|H|1-0|4|20',
    '20min|1|A|0-1|2|9','20min|1|A|0-1|5|14','20min|1|A|0-1|9|19','20min|1|H|1-0|1|13',
    '20min|1|H|1-0|1|15','20min|1|H|1-0|4|21','20min|1|H|1-0|7|23','20min|1|H|1-0|8|14',
];
foreach ($oldKeys as $k) {
    $g = $groups[$k] ?? null;
    if ($g === null) { echo "  {$k} : tidak ada data\n"; continue; }
    $status = ($g['hits']===$g['total']) ? 'PURE' : 'IMPURE';
    printf("  %-22s : %d/%d  %s%s\n", $k, $g['hits'], $g['total'], $status, $g['total']<$MIN_SAMPLE?' (sample<min)':'');
}
