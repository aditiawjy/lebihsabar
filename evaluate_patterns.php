<?php
// Validasi out-of-sample: pisah match per pattern berdasarkan waktu (train=lama, test=baru).
// Tujuan: ungkap overfitting. Akurasi train tinggi tapi test rendah = aturan menghafal data.
//
// Pemakaian:
//   php evaluate_patterns.php            -> test = 14 hari terakhir (relatif match terbaru)
//   php evaluate_patterns.php 21         -> test = 21 hari terakhir
//   php evaluate_patterns.php 2026-06-01 -> test = match pada/sesudah tanggal itu
require_once __DIR__ . '/dashboard_cache.php';

$arg = $argv[1] ?? '14';

$d = buildDashboardData(__DIR__ . '/goal_log.csv', true);
$patterns = $d['patterns'];

// Tentukan cutoff timestamp.
$allTs = [];
foreach ($d['all_matches'] as $m) {
    $ts = parseMatchDateTime($m['datetime'] ?? '');
    if ($ts !== null) $allTs[] = $ts;
}
if (!$allTs) { fwrite(STDERR, "Tidak ada data.\n"); exit(1); }
$maxTs = max($allTs);
$minTs = min($allTs);

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg)) {
    $cutoff = strtotime($arg . ' 00:00:00');
    $label = "cutoff {$arg}";
} else {
    $days = max(1, (int)$arg);
    $cutoff = $maxTs - $days * 86400;
    $label = "{$days} hari terakhir";
}

// Wilson score lower bound (95%) -- estimasi konservatif akurasi sebenarnya.
function wilsonLower(int $h, int $n): float {
    if ($n === 0) return 0.0;
    $z = 1.96; $p = $h / $n;
    $denom = 1 + $z*$z/$n;
    $center = $p + $z*$z/(2*$n);
    $margin = $z * sqrt($p*(1-$p)/$n + $z*$z/(4*$n*$n));
    return max(0.0, ($center - $margin) / $denom) * 100;
}

echo "=== OUT-OF-SAMPLE VALIDATION (target: gol babak 2 / h2c>0) ===\n";
echo "Data: " . date('d/m/Y', $minTs) . " s/d " . date('d/m/Y', $maxTs) . "\n";
echo "Split: TEST = {$label} (>= " . date('d/m/Y H:i', $cutoff) . "), TRAIN = sebelumnya\n\n";

$rows = [];
foreach ($patterns as $p) {
    $trH=0;$trN=0;$teH=0;$teN=0;
    foreach ($p['data'] as $m) {
        $ts = parseMatchDateTime($m['datetime'] ?? '');
        if ($ts === null) continue;
        $hit = ($m['h2c'] ?? 0) > 0;
        if ($ts >= $cutoff) { $teN++; if ($hit) $teH++; }
        else                { $trN++; if ($hit) $trH++; }
    }
    if ($trN + $teN === 0) continue;
    $trPct = $trN ? $trH/$trN*100 : null;
    $tePct = $teN ? $teH/$teN*100 : null;
    $gap = ($trPct !== null && $tePct !== null) ? $trPct - $tePct : null;
    $rows[] = [
        'id'=>$p['id'], 'trH'=>$trH,'trN'=>$trN,'trPct'=>$trPct,
        'teH'=>$teH,'teN'=>$teN,'tePct'=>$tePct,'gap'=>$gap,
        'wlo'=>wilsonLower($teH,$teN),
    ];
}

// Urutkan: yang punya test data dulu, gap terbesar di atas (paling overfit).
usort($rows, function($a,$b){
    $aHas = $a['teN']>0?1:0; $bHas=$b['teN']>0?1:0;
    if ($aHas !== $bHas) return $bHas <=> $aHas;
    return ($b['gap']??-999) <=> ($a['gap']??-999);
});

printf("%-6s | %-14s | %-14s | %-7s | %-9s | %s\n",
    'ID','TRAIN (lama)','TEST (baru)','Gap','Wilson95','Catatan');
echo str_repeat('-', 90)."\n";
foreach ($rows as $r) {
    $tr = $r['trN'] ? sprintf("%d/%d %3.0f%%",$r['trH'],$r['trN'],$r['trPct']) : '-';
    $te = $r['teN'] ? sprintf("%d/%d %3.0f%%",$r['teH'],$r['teN'],$r['tePct']) : 'tdk ada';
    $gap = $r['gap']!==null ? sprintf("%+5.0f%%",-$r['gap']) : '-'; // negatif = turun di test
    $wlo = $r['teN'] ? sprintf("%4.0f%%",$r['wlo']) : '-';
    $note = [];
    if ($r['teN']===0) $note[]='blm teruji live';
    elseif ($r['teN']<5) $note[]='test sampel kecil';
    if ($r['gap']!==null && $r['gap']>=20) $note[]='OVERFIT?';
    if ($r['trN']>0 && $r['trN']<10) $note[]='train sampel kecil';
    printf("%-6s | %-14s | %-14s | %-7s | %-9s | %s\n",
        $r['id'],$tr,$te,$gap,$wlo,implode(', ',$note));
}

// Ringkasan agregat.
$tTeH=0;$tTeN=0;$tTrH=0;$tTrN=0;
foreach ($rows as $r){ $tTeH+=$r['teH'];$tTeN+=$r['teN'];$tTrH+=$r['trH'];$tTrN+=$r['trN']; }
echo "\nAGREGAT semua pattern:\n";
printf("  TRAIN: %d/%d = %.1f%%\n", $tTrH,$tTrN, $tTrN?$tTrH/$tTrN*100:0);
printf("  TEST : %d/%d = %.1f%%  (Wilson95 bawah: %.1f%%)\n", $tTeH,$tTeN, $tTeN?$tTeH/$tTeN*100:0, wilsonLower($tTeH,$tTeN));
echo "\nCatatan: TRAIN dipakai untuk menyusun aturan, jadi akurasinya bias ke atas.\n";
echo "Angka TEST (data baru) lebih dekat ke performa sebenarnya. Gap besar = overfitting.\n";
