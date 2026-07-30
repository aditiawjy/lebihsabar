<?php
$file = __DIR__ . '/goal_log_vsoccer.csv';
$fh = fopen($file, 'r');
$header = fgetcsv($fh);
$idx = array_flip($header);

function midline($v) {
    $parts = array_values(array_filter(array_map('trim', explode('/', (string)$v)), fn($p) => $p !== '' && is_numeric($p)));
    if (!$parts) return null;
    return array_sum(array_map('floatval', $parts)) / count($parts);
}
function goals($v) {
    preg_match_all("/(\dH)\s+(\d+)'\s*\((\d+)-(\d+)\)/", (string)$v, $m, PREG_SET_ORDER);
    return $m;
}

$rows = [];
while (($row = fgetcsv($fh)) !== false) {
    $g = goals($row[$idx['goals']] ?? '');
    $g1 = [];
    $htH = 0; $htA = 0;
    $finalH = (int)($row[$idx['final_home']] ?? 0);
    $finalA = (int)($row[$idx['final_away']] ?? 0);
    foreach ($g as $x) {
        if ($x[1] === '1H') {
            $g1[] = ['min' => (int)$x[2], 'h' => (int)$x[3], 'a' => (int)$x[4]];
            $htH = (int)$x[3]; $htA = (int)$x[4];
        }
    }
    if (count($g1) !== 3 || $htH !== 2 || $htA !== 1) continue;
    $sides = [];
    $ph = 0; $pa = 0;
    foreach ($g1 as $gg) {
        if ($gg['h'] > $ph) $sides[] = 'H';
        elseif ($gg['a'] > $pa) $sides[] = 'A';
        else $sides[] = '?';
        $ph = $gg['h']; $pa = $gg['a'];
    }
    if ($sides !== ['H', 'A', 'H']) continue;
    $last = $g1[2]['min'];
    if ($last < 25) continue;
    $g2h = ($finalH + $finalA) - 3;
    $ko = midline($row[$idx['line_ko']] ?? '');
    $first = $g1[0]['min'];
    $second = $g1[1]['min'];
    $gap12 = $second - $first;
    $gap23 = $last - $second;
    $span = $last - $first;
    $day = substr((string)($row[$idx['datetime']] ?? ''), 0, 10);
    $hit = $g2h >= 2;
    $rows[] = compact('day', 'first', 'second', 'last', 'gap12', 'gap23', 'span', 'ko', 'g2h', 'hit') + [
        'dt' => $row[$idx['datetime']] ?? '',
        'match' => ($row[$idx['home_team']] ?? '') . ' vs ' . ($row[$idx['away_team']] ?? ''),
    ];
}
fclose($fh);

echo "N=" . count($rows) . " hits=" . count(array_filter($rows, fn($r) => $r['hit'])) . "\n\n";
foreach ($rows as $r) {
    printf(
        "%s | %-40s | g %2d %2d %2d | gap %2d %2d span %2d | ko %s | 2H %d | %s\n",
        $r['dt'],
        substr($r['match'], 0, 40),
        $r['first'], $r['second'], $r['last'],
        $r['gap12'], $r['gap23'], $r['span'],
        $r['ko'] === null ? '?' : number_format($r['ko'], 2),
        $r['g2h'],
        $r['hit'] ? 'HIT' : 'MISS'
    );
}

function test(array $rows, callable $fn, string $name): void {
    $f = array_values(array_filter($rows, $fn));
    $h = count(array_filter($f, fn($r) => $r['hit']));
    $t = count($f);
    $m = $t - $h;
    $acc = $t ? round(100 * $h / $t, 1) : 0;
    // eval only since 29/07
    $eval = array_filter($f, fn($r) => $r['day'] >= '29/07/2026' || strpos($r['dt'], '29/07') === 0 || strpos($r['dt'], '30/07') === 0);
    // day format dd/mm
    $eval = array_values(array_filter($f, function ($r) {
        $d = DateTime::createFromFormat('d/m/Y H:i', $r['dt']);
        if (!$d) return false;
        return $d->format('Y-m-d') >= '2026-07-29';
    }));
    $eh = count(array_filter($eval, fn($r) => $r['hit']));
    $et = count($eval);
    $eacc = $et ? round(100 * $eh / $et, 1) : 0;
    echo sprintf("%-55s %2d/%2d=%5.1f%% miss=%d | eval %2d/%2d=%5.1f%%\n", $name, $h, $t, $acc, $m, $eh, $et, $eacc);
}

echo "\n=== FILTER CANDIDATES ===\n";
test($rows, fn($r) => true, 'baseline g3>=25');
test($rows, fn($r) => $r['last'] >= 28, 'g3>=28');
test($rows, fn($r) => $r['last'] >= 30, 'g3>=30');
test($rows, fn($r) => $r['last'] >= 32, 'g3>=32');
test($rows, fn($r) => $r['last'] >= 33, 'g3>=33');
test($rows, fn($r) => $r['gap12'] >= 5, 'gap12>=5');
test($rows, fn($r) => $r['gap12'] >= 6, 'gap12>=6');
test($rows, fn($r) => $r['gap12'] >= 7, 'gap12>=7');
test($rows, fn($r) => $r['gap12'] >= 8, 'gap12>=8');
test($rows, fn($r) => $r['gap12'] >= 10, 'gap12>=10');
test($rows, fn($r) => $r['gap12'] > 3, 'gap12>3');
test($rows, fn($r) => $r['gap12'] > 6, 'gap12>6');
test($rows, fn($r) => $r['gap23'] >= 5, 'gap23>=5');
test($rows, fn($r) => $r['gap23'] >= 8, 'gap23>=8');
test($rows, fn($r) => $r['gap23'] >= 10, 'gap23>=10');
test($rows, fn($r) => $r['span'] >= 15, 'span>=15');
test($rows, fn($r) => $r['span'] >= 20, 'span>=20');
test($rows, fn($r) => $r['first'] <= 20, 'g1<=20');
test($rows, fn($r) => $r['first'] <= 15, 'g1<=15');
test($rows, fn($r) => $r['first'] <= 12, 'g1<=12');
test($rows, fn($r) => $r['first'] >= 6, 'g1>=6');
test($rows, fn($r) => $r['ko'] === null || $r['ko'] >= 4.5, 'ko>=4.5 or unk');
test($rows, fn($r) => $r['ko'] === null || $r['ko'] >= 5.0, 'ko>=5.0 or unk');
test($rows, fn($r) => $r['ko'] === null || $r['ko'] >= 5.5, 'ko>=5.5 or unk');
test($rows, fn($r) => $r['ko'] !== null && $r['ko'] >= 5.0, 'ko known>=5');
test($rows, fn($r) => !($r['gap12'] <= 6 && $r['first'] <= 11), 'NOT (gap12<=6 g1<=11)');
test($rows, fn($r) => !($r['gap12'] <= 8 && $r['first'] <= 11 && $r['second'] <= 17), 'NOT early tight cluster');
test($rows, fn($r) => !($r['first'] <= 11 && $r['second'] <= 17 && $r['last'] <= 32), 'NOT g1<=11 g2<=17 g3<=32');
test($rows, fn($r) => !($r['gap12'] <= 3), 'NOT gap12<=3');
test($rows, fn($r) => $r['gap12'] >= 7 || $r['last'] >= 33, 'gap12>=7 OR g3>=33');
test($rows, fn($r) => $r['gap12'] >= 8 || $r['last'] >= 33, 'gap12>=8 OR g3>=33');
test($rows, fn($r) => $r['gap12'] >= 6 || $r['last'] >= 33, 'gap12>=6 OR g3>=33');
test($rows, fn($r) => $r['gap12'] >= 7 || $r['last'] >= 35, 'gap12>=7 OR g3>=35');
test($rows, fn($r) => $r['gap12'] >= 10 || $r['last'] >= 33, 'gap12>=10 OR g3>=33');
test($rows, fn($r) => $r['span'] >= 20 || $r['last'] >= 35, 'span>=20 OR g3>=35');
test($rows, fn($r) => $r['gap12'] >= 5 && $r['last'] >= 28, 'gap12>=5 AND g3>=28');
test($rows, fn($r) => $r['gap12'] >= 6 && $r['last'] >= 28, 'gap12>=6 AND g3>=28');
test($rows, fn($r) => $r['gap12'] >= 7 && $r['last'] >= 26, 'gap12>=7 AND g3>=26');
test($rows, fn($r) => !($r['first'] <= 11 && $r['gap12'] <= 6 && $r['last'] <= 32), 'NOT early burst miss shape');
test($rows, fn($r) => !($r['first'] <= 11 && $r['second'] <= 17), 'NOT g1<=11 g2<=17');
test($rows, fn($r) => $r['second'] >= 14, 'g2>=14');
test($rows, fn($r) => $r['second'] >= 16, 'g2>=16');
test($rows, fn($r) => $r['second'] >= 17, 'g2>=17');
test($rows, fn($r) => $r['second'] >= 20, 'g2>=20');
test($rows, fn($r) => $r['first'] >= 6 && $r['gap12'] >= 3, 'g1>=6 gap12>=3');
test($rows, fn($r) => $r['first'] >= 6 && ($r['gap12'] >= 7 || $r['last'] >= 33), 'g1>=6 + (gap>=7|g3>=33)');
test($rows, fn($r) => !($r['first'] <= 11 && $r['gap12'] <= 8 && $r['last'] <= 32), 'NOT (g1<=11 gap<=8 g3<=32)');
test($rows, fn($r) => $r['last'] >= 26 && !($r['first'] <= 11 && $r['second'] <= 17), 'g3>=26 NOT early double');
test($rows, fn($r) => $r['last'] >= 26 && ($r['gap12'] >= 8 || $r['second'] >= 16), 'g3>=26 + (gap>=8|g2>=16)');
test($rows, fn($r) => $r['last'] >= 26 && $r['gap12'] >= 7, 'g3>=26 gap12>=7');
test($rows, fn($r) => $r['last'] >= 26 && $r['gap12'] >= 8, 'g3>=26 gap12>=8');
test($rows, fn($r) => $r['last'] >= 26 && $r['gap12'] >= 10, 'g3>=26 gap12>=10');
test($rows, fn($r) => $r['last'] >= 26 && ($r['first'] >= 14 || $r['gap12'] >= 10 || $r['last'] >= 33), 'combo late/spaced');
test($rows, fn($r) => $r['last'] >= 26 && !($r['first'] <= 11 && $r['gap12'] <= 8), 'g3>=26 NOT early tight');
test($rows, fn($r) => $r['last'] >= 27 && !($r['first'] <= 11 && $r['gap12'] <= 8), 'g3>=27 NOT early tight');
test($rows, fn($r) => $r['last'] >= 26 && ($r['gap12'] >= 9 || $r['last'] >= 33), 'gap>=9 OR g3>=33');
test($rows, fn($r) => $r['last'] >= 26 && ($r['gap12'] >= 10 || $r['last'] >= 33), 'gap>=10 OR g3>=33');
test($rows, fn($r) => $r['last'] >= 26 && ($r['gap12'] >= 8 || $r['last'] >= 33), 'gap>=8 OR g3>=33');
test($rows, fn($r) => $r['last'] >= 26 && ($r['gap12'] >= 7 || $r['last'] >= 33), 'gap>=7 OR g3>=33');
test($rows, fn($r) => $r['last'] >= 26 && ($r['gap12'] >= 6 || $r['last'] >= 33), 'gap>=6 OR g3>=33');
test($rows, fn($r) => $r['last'] >= 26 && ($r['span'] >= 20 || $r['last'] >= 33), 'span>=20 OR g3>=33');
test($rows, fn($r) => $r['last'] >= 26 && ($r['span'] >= 18 || $r['last'] >= 33), 'span>=18 OR g3>=33');
test($rows, fn($r) => $r['last'] >= 26 && ($r['span'] >= 21 || $r['last'] >= 33), 'span>=21 OR g3>=33');
test($rows, fn($r) => $r['last'] >= 26 && ($r['gap23'] >= 10 || $r['gap12'] >= 10), 'gap23|12 >=10');
test($rows, fn($r) => $r['last'] >= 26 && $r['gap23'] >= 8, 'gap23>=8');
test($rows, fn($r) => $r['last'] >= 26 && $r['gap23'] >= 10, 'gap23>=10');
test($rows, fn($r) => $r['last'] >= 26 && $r['gap23'] >= 12, 'gap23>=12');
test($rows, fn($r) => $r['last'] >= 26 && !($r['first'] <= 11 && $r['second'] <= 17 && $r['gap12'] <= 8), 'exclude miss shape A');
test($rows, fn($r) => $r['last'] >= 26 && !($r['first'] <= 11 && $r['second'] <= 17 && $r['last'] <= 32), 'exclude miss shape B');
test($rows, fn($r) => $r['last'] >= 26 && !($r['first'] <= 11 && $r['second'] <= 17 && $r['last'] <= 32 && $r['gap12'] <= 8), 'exclude miss shape C');
test($rows, fn($r) => $r['last'] >= 26 && ($r['ko'] === null || $r['ko'] >= 4.75) && !($r['first'] <= 11 && $r['second'] <= 17 && $r['last'] <= 32), 'ko+exclude early');
test($rows, fn($r) => $r['last'] >= 26 && ($r['second'] - $r['first'] >= 8 || $r['last'] - $r['second'] >= 15), 'spaced goals');
test($rows, fn($r) => $r['last'] >= 26 && ($r['second'] - $r['first'] >= 9 || $r['last'] - $r['second'] >= 12), 'spaced goals v2');
test($rows, fn($r) => $r['last'] >= 26 && ($r['second'] - $r['first'] >= 10 || $r['last'] - $r['second'] >= 13), 'spaced goals v3');
test($rows, fn($r) => $r['last'] >= 26 && ($r['second'] - $r['first'] >= 7 || $r['last'] - $r['second'] >= 15), 'spaced goals v4');
test($rows, fn($r) => $r['last'] >= 26 && ($r['second'] - $r['first'] >= 8 || $r['last'] - $r['second'] >= 13), 'spaced goals v5');
test($rows, fn($r) => $r['last'] >= 26 && ($r['second'] - $r['first'] >= 8 || $r['last'] - $r['second'] >= 14), 'spaced goals v6');
test($rows, fn($r) => $r['last'] >= 26 && ($r['second'] - $r['first'] >= 8 || $r['last'] - $r['second'] >= 16), 'spaced goals v7');
test($rows, fn($r) => $r['last'] >= 26 && ($r['second'] - $r['first'] >= 9 || $r['last'] - $r['second'] >= 14), 'spaced goals v8');
test($rows, fn($r) => $r['last'] >= 26 && ($r['second'] - $r['first'] >= 10 || $r['last'] - $r['second'] >= 15), 'spaced goals v9');
test($rows, fn($r) => $r['last'] >= 26 && ($r['first'] >= 14 || $r['gap12'] >= 10 || $r['gap23'] >= 15), 'late start or spaced');
test($rows, fn($r) => $r['last'] >= 26 && ($r['first'] >= 14 || $r['gap12'] >= 9 || $r['gap23'] >= 13), 'late/spaced v2');
test($rows, fn($r) => $r['last'] >= 26 && ($r['first'] >= 16 || $r['gap12'] >= 10 || $r['gap23'] >= 13), 'late/spaced v3');
test($rows, fn($r) => $r['last'] >= 26 && ($r['first'] >= 14 || $r['gap12'] >= 8 || $r['gap23'] >= 15), 'late/spaced v4');
test($rows, fn($r) => $r['last'] >= 26 && ($r['first'] >= 12 || $r['gap12'] >= 10 || $r['gap23'] >= 15), 'late/spaced v5');
test($rows, fn($r) => $r['last'] >= 26 && !($r['first'] <= 11 && $r['gap12'] <= 8 && $r['gap23'] <= 23), 'NOT early+mid tight');

echo "\nMISSES detail:\n";
foreach ($rows as $r) {
    if (!$r['hit']) {
        echo json_encode($r) . "\n";
    }
}
