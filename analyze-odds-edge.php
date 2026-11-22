<?php
/**
 * Uji edge: apakah profil tim (dari matches.csv, hanya laga SEBELUM kickoff) mengalahkan odds bandar?
 * Odds kickoff Over DAN Under + skor akhir diambil dari baris yang sama di goal_log_bpvm.csv (hanya ko_score 0 - 0).
 * goal_log.csv tidak dipakai: kolom ko_line di sana tercatat saat laga sudah berjalan (line = gol akhir + ~0.75).
 * Pemakaian: php analyze-odds-edge.php
 */
ini_set('memory_limit', '1024M');
require __DIR__ . '/odds-research-lib.php';

$bets = oddsResearchAttachProfiles(oddsResearchLoadBets());
if (!$bets) { echo "Belum ada laga dengan odds kickoff di goal_log_bpvm.csv.\n"; exit; }
$show = function (string $label, array $set) {
    if (count($set) < 20) { printf("  %-42s n=%d (terlalu sedikit)\n", $label, count($set)); return; }
    $s = oddsResearchSummary($label, $set);
    printf("  %-42s n=%-5d line=%.2f gol=%.2f | ROI Over %+6.1f%% (+/-%.1f) | ROI Under %+6.1f%% (+/-%.1f)\n", $label, $s['n'],
        $s['line'], $s['goals'], 100 * $s['roiOver'], 100 * $s['ciOver'], 100 * $s['roiUnder'], 100 * $s['ciUnder']);
};
printf("Laga dengan odds kickoff Over+Under dan skor akhir: %d (%s s/d %s)\n\n", count($bets), date('Y-m-d', $bets[0]['ts']), date('Y-m-d', end($bets)['ts']));
echo "SEMUA\n"; $show('Semua laga', $bets);
$byL = []; foreach ($bets as $b) $byL[$b['league']][] = $b;
echo "\nPER LIGA\n"; foreach ($byL as $L => $set) $show(substr($L, 0, 42), $set);
foreach (['pred' => 'PROFIL TIM: prediksi gol - line bandar', 'pairPred' => 'PROFIL PASANGAN H2H: rata-rata gol - line bandar'] as $k => $title) {
    echo "\n$title (5 kelompok; kelompok 1 = profil jauh DI BAWAH line -> kandidat Under)\n";
    $groups = oddsResearchQuintiles($bets, $k);
    if (!$groups) { echo "  data kurang\n"; continue; }
    foreach ($groups as $g) printf("  %-42s n=%-5d line=%.2f gol=%.2f | ROI Over %+6.1f%% (+/-%.1f) | ROI Under %+6.1f%% (+/-%.1f)\n", $g['label'], $g['n'],
        $g['line'], $g['goals'], 100 * $g['roiOver'], 100 * $g['ciOver'], 100 * $g['roiUnder'], 100 * $g['ciUnder']);
}

echo "\nUJI KETAHANAN aturan: rata-rata gol pasangan H2H minimal 0.25 DI BAWAH line -> pasang Under\n";
$rule = array_values(array_filter($bets, fn($b) => $b['pairPred'] !== null && $b['pairPred'] - $b['line'] <= -0.25));
$rest = array_values(array_filter($bets, fn($b) => $b['pairPred'] !== null && $b['pairPred'] - $b['line'] > -0.25));
$show('Kena aturan', $rule); $show('Tidak kena aturan', $rest);
$mid = $bets[intdiv(count($bets), 2)]['ts'];
$show('Kena aturan, paruh waktu PERTAMA', array_values(array_filter($rule, fn($b) => $b['ts'] < $mid)));
$show('Kena aturan, paruh waktu KEDUA', array_values(array_filter($rule, fn($b) => $b['ts'] >= $mid)));
$byL = []; foreach ($rule as $b) $byL[$b['league']][] = $b;
foreach ($byL as $L => $set) $show('Kena aturan, ' . substr($L, 5, 28), $set);
foreach ([[0, 1.75], [1.76, 2.25], [2.26, 2.75], [2.76, 9]] as [$lo, $hi]) $show("Semua laga, line $lo-$hi", array_values(array_filter($bets, fn($b) => $b['line'] >= $lo && $b['line'] <= $hi)));
foreach ([[0, 1.75], [1.76, 2.25], [2.26, 2.75], [2.76, 9]] as [$lo, $hi]) $show("Kena aturan, line $lo-$hi", array_values(array_filter($rule, fn($b) => $b['line'] >= $lo && $b['line'] <= $hi)));
printf("  rata-rata odds Under kena aturan: %.2f\n", array_sum(array_column($rule, 'under')) / max(1, count($rule)));
