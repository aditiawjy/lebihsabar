<?php
/**
 * validate_mining_modes.php
 * ---------------------------------------------------------------------------
 * Uji out-of-sample untuk 10 mode "mining" eksperimental di streak-analysis.php.
 * Metodologi sama dgn 3 mode yg sudah lolos: split train (< SPLIT) vs test (>= SPLIT),
 * lalu sinyal & outcome dihitung persis dgn cara aplikasi (tuple + $nmOut).
 *
 * Sebuah mode LOLOS bila pada TEST: n >= MIN_N dan Wilson lower-bound >= MIN_LB,
 * dan rate test tidak jatuh jauh dari train (drift <= MAX_DRIFT).
 *
 * Jalankan:  php validate_mining_modes.php
 *            php validate_mining_modes.php 2026-05-01   (custom split)
 * ---------------------------------------------------------------------------
 */
date_default_timezone_set('Asia/Jakarta');

$csvPath = __DIR__ . '/matches.csv';
$SPLIT   = $argv[1] ?? '2026-05-01';   // train < SPLIT, test >= SPLIT
$MIN_N   = 30;    // sampel test minimal
$MIN_LB  = 75.0;  // Wilson lower-bound 95% test minimal (%)
$MAX_DRIFT = 15.0; // penurunan rate train->test maksimal (poin %) agar dianggap stabil

// --- Definisi mode eksperimental + target outcome (slot $nmOut 1..25) ---------
// Slot outcome (index di $nmOut): 1=ov15 2=ov05 3=shg 4=fhg 5=u25 6=o25 7=u35
// 8=btts 9=nbtts 10=draw 11=nodraw 12=hg05 13=ag05 ...
$modes = [
    'cm_shfhcs'    => ['conds' => [[8, false, 3], [9, false, 3], [11, false, 3]],  'out' => 2,  'label' => 'SHG 3x + FHG 3x + CS 3x → O0.5'],
    'cm_drnfbt'    => ['conds' => [[6, false, 3], [9, true, 3], [10, true, 2]],    'out' => 2,  'label' => 'Draw 3x + NoFHG 3x + BTTS 2x → O0.5'],
    'cm_o15cshto'  => ['conds' => [[1, true, 3], [11, false, 3], [13, false, 3]],  'out' => 2,  'label' => 'O1.5 3x + CS 3x + HT-Odd 3x → O0.5'],
    'cm_nsbthto'   => ['conds' => [[8, true, 3], [10, true, 2], [13, false, 3]],   'out' => 4,  'label' => 'NoSHG 3x + BTTS 2x + HT-Odd 3x → FHG'],
    'cm_odftshto'  => ['conds' => [[7, false, 4], [12, false, 3], [13, false, 3]], 'out' => 7,  'label' => 'Odd 4x + FTS 3x + HT-Odd 3x → U3.5'],
    'cm_o15o25cs'  => ['conds' => [[1, true, 3], [2, false, 2], [11, false, 3]],   'out' => 13, 'label' => 'O1.5 3x + O2.5 2x + CS 3x → Away O0.5'],
    'cm_nsftshto'  => ['conds' => [[8, true, 3], [12, false, 3], [13, false, 3]],  'out' => 11, 'label' => 'NoSHG 3x + FTS 3x + HT-Odd 3x → NoDraw'],
    'cm_u05evfts'  => ['conds' => [[3, false, 2], [7, true, 4], [12, false, 3]],   'out' => 7,  'label' => 'U0.5 2x + Even 4x + FTS 3x → U3.5'],
    'cm_o25ftsu35' => ['conds' => [[2, false, 2], [12, false, 3], [15, false, 4]], 'out' => 12, 'label' => 'O2.5 2x + FTS 3x + U3.5 4x → Home O0.5'],
    'cm_o25nsu35'  => ['conds' => [[2, false, 2], [8, true, 3], [15, false, 4]],   'out' => 4,  'label' => 'O2.5 2x + NoSHG 3x + U3.5 4x → FHG'],
    // Mode yg sudah dilabeli "lolos validasi" — diuji ulang utk konsistensi metodologi.
    'cm_o25fhcs'   => ['conds' => [[2, false, 2], [9, false, 3], [11, false, 3]],  'out' => 13, 'label' => '[V] O2.5 2x + FHG 3x + CS 3x → Away O0.5'],
    'cm_o15cshte'  => ['conds' => [[1, true, 3], [11, false, 3], [14, false, 3]],  'out' => 13, 'label' => '[V] O1.5 3x + CS 3x + HT-Even 3x → Away O0.5'],
    'cm_klodns'    => ['conds' => [[4, false, 3], [7, false, 4], [8, true, 3]],    'out' => 11, 'label' => '[V] Kalah 3x + Odd 4x + NoSHG 3x → NoDraw'],
];

// --- Scan CSV → sequence per tim|liga (played only), tuple identik aplikasi ----
if (!is_file($csvPath) || ($fh = fopen($csvPath, 'r')) === false) {
    fwrite(STDERR, "matches.csv tidak ditemukan/tak terbaca\n"); exit(1);
}
$team = [];
$hdr = fgetcsv($fh);
if (!is_array($hdr)) { fwrite(STDERR, "header CSV kosong\n"); exit(1); }
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) !== count($hdr)) continue;
    $r = @array_combine($hdr, $row);
    if (!$r) continue;
    $h = trim($r['home_team'] ?? ''); $a = trim($r['away_team'] ?? '');
    if ($h === '' || $a === '') continue;
    $lg = trim($r['league'] ?? '');
    $sk = ($r['match_time'] ?? '');
    $fth = $r['ft_home'] ?? ''; $fta = $r['ft_away'] ?? '';
    if ($fth === '' || $fta === '' || !is_numeric($fth) || !is_numeric($fta)) continue; // belum main
    $ih = (int)$fth; $ia = (int)$fta; $tot = $ih + $ia;
    $u15 = $tot < 2 ? 1 : 0; $o25 = $tot > 2 ? 1 : 0; $u05 = $tot < 1 ? 1 : 0; $u35 = $tot <= 3 ? 1 : 0;
    $loseH = $ih < $ia ? 1 : 0; $loseA = $ia < $ih ? 1 : 0;
    $winH = $ih > $ia ? 1 : 0; $winA = $ia > $ih ? 1 : 0;
    $draw = $ih === $ia ? 1 : 0; $odd = ($tot % 2 !== 0) ? 1 : 0;
    $fhh = $r['fh_home'] ?? ''; $fha = $r['fh_away'] ?? '';
    $hasFh = is_numeric($fhh) && is_numeric($fha);
    $shg = ($hasFh && (($ih - (int)$fhh) + ($ia - (int)$fha)) >= 1) ? 1 : 0;
    $fhg = ($hasFh && ((int)$fhh + (int)$fha) >= 1) ? 1 : 0;
    $nbtts = ($ih === 0 || $ia === 0) ? 1 : 0;
    $fht = $hasFh ? ((int)$fhh + (int)$fha) : null;
    $htodd  = ($fht !== null && $fht % 2 === 1) ? 1 : 0;
    $hteven = ($fht !== null && $fht % 2 === 0) ? 1 : 0;
    $csH = $ia === 0 ? 1 : 0; $ftsH = $ih === 0 ? 1 : 0;
    $csA = $ih === 0 ? 1 : 0; $ftsA = $ia === 0 ? 1 : 0;
    $hg05 = $ih >= 1 ? 1 : 0; $ag05 = $ia >= 1 ? 1 : 0;
    $tg01 = $tot <= 1 ? 1 : 0; $tg23 = ($tot >= 2 && $tot <= 3) ? 1 : 0;
    $tg46 = ($tot >= 4 && $tot <= 6) ? 1 : 0; $tg7 = $tot >= 7 ? 1 : 0;
    $eg1 = $tot === 1 ? 1 : 0; $eg2 = $tot === 2 ? 1 : 0; $eg3 = $tot === 3 ? 1 : 0; $eg4 = $tot === 4 ? 1 : 0;
    $hw = $ih > $ia ? 1 : 0; $aw = $ia > $ih ? 1 : 0;
    // tuple identik streak-analysis.php:102-103
    $team[$h . '|' . $lg][] = [$sk, $u15, $o25, $u05, $loseH, $winH, $draw, $odd, $shg, $fhg, $nbtts, $csH, $ftsH, $htodd, $hteven, $u35, $hg05, $ag05, $tg01, $tg23, $tg46, $tg7, $eg1, $eg2, $eg3, $eg4, $hw, $aw, $a, 1];
    $team[$a . '|' . $lg][] = [$sk, $u15, $o25, $u05, $loseA, $winA, $draw, $odd, $shg, $fhg, $nbtts, $csA, $ftsA, $htodd, $hteven, $u35, $hg05, $ag05, $tg01, $tg23, $tg46, $tg7, $eg1, $eg2, $eg3, $eg4, $hw, $aw, $h, 0];
}
fclose($fh);

// --- Akumulasi train/test per mode --------------------------------------------
$acc = [];
foreach ($modes as $k => $_) $acc[$k] = ['tr' => [0, 0], 'te' => [0, 0]]; // [n, hits]

foreach ($team as $arr) {
    usort($arr, fn($x, $y) => strcmp($x[0], $y[0])); // terlama dulu, sama dgn app
    $n = count($arr);
    for ($i = 0; $i < $n; $i++) {
        $ov15 = $arr[$i][1] ? 0 : 1; $ov05 = $arr[$i][3] ? 0 : 1;
        $shgN = $arr[$i][8]; $fhgN = $arr[$i][9]; $u35N = $arr[$i][15];
        $nbttsN = $arr[$i][10]; $bttsN = $nbttsN ? 0 : 1;
        $drawN = $arr[$i][6]; $nodrawN = $drawN ? 0 : 1;
        $hg05N = $arr[$i][16]; $ag05N = $arr[$i][17];
        $o25N = $arr[$i][2]; $u25N = $arr[$i][2] ? 0 : 1;
        // $nmOut slot 1..25 (0 = count)
        $nmOut = [1, $ov15, $ov05, $shgN, $fhgN, $u25N, $o25N, $u35N, $bttsN, $nbttsN, $drawN, $nodrawN, $hg05N, $ag05N];
        $day = substr($arr[$i][0], 0, 10);
        $bucket = ($day !== '' && $day >= $SPLIT) ? 'te' : 'tr';
        foreach ($modes as $k => $cfg) {
            $ok = true;
            foreach ($cfg['conds'] as [$idx, $neg, $len]) {
                if ($i < $len) { $ok = false; break; }
                for ($j = 1; $j <= $len; $j++) {
                    $flag = (bool)$arr[$i - $j][$idx];
                    if ($neg) $flag = !$flag;
                    if (!$flag) { $ok = false; break; }
                }
                if (!$ok) break;
            }
            if (!$ok) continue;
            $acc[$k][$bucket][0]++;
            $acc[$k][$bucket][1] += ($nmOut[$cfg['out']] ?? 0);
        }
    }
}

// --- Wilson lower bound 95% ----------------------------------------------------
function wilsonLB($hits, $n) {
    if ($n <= 0) return null;
    $z = 1.96; $p = $hits / $n;
    $lb = ($p + $z * $z / (2 * $n) - $z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n))) / (1 + $z * $z / $n);
    return round($lb * 1000) / 10;
}
$rate = fn($b) => $b[0] > 0 ? round($b[1] / $b[0] * 1000) / 10 : null;

// --- Laporan -------------------------------------------------------------------
printf("Split: train < %s  |  test >= %s   (MIN_N=%d, MIN_LB=%.0f%%, MAX_DRIFT=%.0f)\n",
    $SPLIT, $SPLIT, $MIN_N, $MIN_LB, $MAX_DRIFT);
printf("%-14s %-42s | %-16s | %-22s | %s\n", 'mode', 'kondisi', 'TRAIN', 'TEST', 'putusan');
echo str_repeat('-', 120) . "\n";
$passed = [];
foreach ($modes as $k => $cfg) {
    $tr = $acc[$k]['tr']; $te = $acc[$k]['te'];
    $rtr = $rate($tr); $rte = $rate($te);
    $lbte = wilsonLB($te[1], $te[0]);
    $drift = ($rtr !== null && $rte !== null) ? ($rtr - $rte) : null;
    $pass = ($te[0] >= $MIN_N) && ($lbte !== null && $lbte >= $MIN_LB) && ($drift === null || $drift <= $MAX_DRIFT);
    if ($pass) $passed[] = $k;
    printf("%-14s %-42s | n=%-4d r=%-7s | n=%-4d r=%-6s LB=%-6s | %s\n",
        $k, $cfg['label'],
        $tr[0], ($rtr === null ? '-' : $rtr . '%'),
        $te[0], ($rte === null ? '-' : $rte . '%'), ($lbte === null ? '-' : $lbte . '%'),
        $pass ? 'LOLOS ✅' : 'tidak lolos');
}
echo str_repeat('-', 120) . "\n";
echo "LOLOS out-of-sample (" . count($passed) . "): " . ($passed ? implode(', ', $passed) : '—') . "\n";
