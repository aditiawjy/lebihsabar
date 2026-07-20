<?php
// Halaman "Streak U1.5": menganalisis autokorelasi Under 1.5 per tim.
// Pertanyaan: setelah tim Under 1.5 beruntun (1x / 2x / 3x), match berikutnya
// cenderung Under 1.5 lagi atau balik Over? Plus peluang Over 2.5.
// Sumber data: matches.csv. Hasil di-cache (key = mtime+size csv).
date_default_timezone_set('Asia/Jakarta');

$csvPath = __DIR__ . '/matches.csv';

// ---- Cache ------------------------------------------------------------------
$cacheDir = __DIR__ . '/cache/streak';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$cacheKey = md5(json_encode([
    is_file($csvPath) ? filemtime($csvPath) : 0,
    is_file($csvPath) ? filesize($csvPath) : 0,
    'streak_v57_both_o25',
    date('Y-m-d'), // tu15 bergantung tanggal → recompute tiap hari
]));
$cacheFile = $cacheDir . '/' . $cacheKey . '.cache';

$payload = null;
if (is_file($cacheFile)) {
    $raw = @file_get_contents($cacheFile);
    if ($raw !== false && $raw !== '') {
        $tmp = @unserialize($raw, ['allowed_classes' => false]);
        if (is_array($tmp)) $payload = $tmp;
    }
}

if ($payload === null) {
    // ---- Scan CSV -----------------------------------------------------------
    $team = [];           // "tim|liga" => [ [sortkey, isU15, isO25], ... ]
    $nextMatch = [];      // "tim|liga" => ['vs'=>lawan, 'dt'=>match_time]  (jadwal terdekat blm main)
    $gN = 0; $gU15 = 0; $gO25 = 0; $gU05 = 0;
    $gSHG = 0; $gFHG = 0; $gU35 = 0; $gNBTTS = 0; $gDRAW = 0; // baseline semua outcome
    $gHG = 0; $gAG = 0; // baseline: home cetak >=1 gol / away cetak >=1 gol
    $gTG01 = 0; $gTG23 = 0; $gTG46 = 0; $gTG7 = 0; // baseline: total gol 0-1 / 2-3 / 4-6 / 7+
    $gEG1 = 0; $gEG2 = 0; $gEG3 = 0; $gEG4 = 0; // baseline: total gol pas 1 / 2 / 3 / 4
    $gHW = 0; $gAW = 0; // baseline: home win / away win
    $gODD = 0; // baseline: total gol ganjil (odd) — even = gN - gODD
    $csvError = null;
    $nowStr = date('Y-m-d H:i:s');

    if (!is_file($csvPath) || ($fh = @fopen($csvPath, 'r')) === false) {
        $csvError = 'File data pertandingan (matches.csv) tidak ditemukan / tidak dapat dibaca.';
    } else {
        $hdr = fgetcsv($fh);
        if (is_array($hdr)) {
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) !== count($hdr)) continue;
                $r = @array_combine($hdr, $row);
                if (!$r) continue;
                $h = trim($r['home_team'] ?? ''); $a = trim($r['away_team'] ?? '');
                if ($h === '' || $a === '') continue;
                $lg = trim($r['league'] ?? '');
                $sk = ($r['match_time'] ?? '');
                $fth = $r['ft_home'] ?? ''; $fta = $r['ft_away'] ?? '';
                $played = !($fth === '' || $fta === '' || !is_numeric($fth) || !is_numeric($fta));
                if (!$played) {
                    // Jadwal yang belum dimainkan & masih akan datang → kandidat next match
                    if ($sk !== '' && $sk >= $nowStr) {
                        $hk = $h . '|' . $lg; $ak = $a . '|' . $lg;
                        if (!isset($nextMatch[$hk]) || $sk < $nextMatch[$hk]['dt']) $nextMatch[$hk] = ['vs' => $a, 'dt' => $sk, 'home' => 1];
                        if (!isset($nextMatch[$ak]) || $sk < $nextMatch[$ak]['dt']) $nextMatch[$ak] = ['vs' => $h, 'dt' => $sk, 'home' => 0];
                    }
                    continue;
                }
                $tot = (int)$fth + (int)$fta;
                $u15 = $tot < 2 ? 1 : 0;
                $o25 = $tot > 2 ? 1 : 0;
                $u05 = $tot < 1 ? 1 : 0;
                $u35 = $tot <= 3 ? 1 : 0;                                              // Under 3.5
                $ih = (int)$fth; $ia = (int)$fta;
                $loseH = $ih < $ia ? 1 : 0; $loseA = $ia < $ih ? 1 : 0;       // kalah
                $winH  = $ih > $ia ? 1 : 0; $winA  = $ia > $ih ? 1 : 0;       // menang
                $draw  = $ih === $ia ? 1 : 0;                                 // seri (sama utk kedua tim)
                $odd   = ($tot % 2 !== 0) ? 1 : 0;                            // total gol ganjil
                $fhh = $r['fh_home'] ?? ''; $fha = $r['fh_away'] ?? '';
                $hasFh = is_numeric($fhh) && is_numeric($fha);
                $shg = ($hasFh && (($ih - (int)$fhh) + ($ia - (int)$fha)) >= 1) ? 1 : 0; // gol babak 2 >=1
                $fhg = ($hasFh && ((int)$fhh + (int)$fha) >= 1) ? 1 : 0;                 // gol babak 1 >=1
                $nbtts = ($ih === 0 || $ia === 0) ? 1 : 0;                               // No BTTS (ada clean sheet)
                $fht = $hasFh ? ((int)$fhh + (int)$fha) : null;
                $htodd  = ($fht !== null && $fht % 2 === 1) ? 1 : 0;                      // total babak1 ganjil
                $hteven = ($fht !== null && $fht % 2 === 0) ? 1 : 0;                      // total babak1 genap
                $csH = $ia === 0 ? 1 : 0; $ftsH = $ih === 0 ? 1 : 0;                      // home: cleansheet / gagal cetak
                $csA = $ih === 0 ? 1 : 0; $ftsA = $ia === 0 ? 1 : 0;                      // away
                $hg05 = $ih >= 1 ? 1 : 0; $ag05 = $ia >= 1 ? 1 : 0;                       // home cetak >=1 / away cetak >=1 (match-level)
                $tg01 = $tot <= 1 ? 1 : 0; $tg23 = ($tot >= 2 && $tot <= 3) ? 1 : 0;      // total gol 0-1 / 2-3
                $tg46 = ($tot >= 4 && $tot <= 6) ? 1 : 0; $tg7 = $tot >= 7 ? 1 : 0;        // total gol 4-6 / 7+
                $eg1 = $tot === 1 ? 1 : 0; $eg2 = $tot === 2 ? 1 : 0;                      // total gol pas 1 / 2
                $eg3 = $tot === 3 ? 1 : 0; $eg4 = $tot === 4 ? 1 : 0;                      // total gol pas 3 / 4
                $hw = $ih > $ia ? 1 : 0; $aw = $ia > $ih ? 1 : 0;                          // home win / away win (match-level)
                $gN++; $gU15 += $u15; $gO25 += $o25; $gU05 += $u05;
                $gSHG += $shg; $gFHG += $fhg; $gU35 += $u35; $gNBTTS += $nbtts; $gDRAW += $draw;
                $gHG += $hg05; $gAG += $ag05;
                $gTG01 += $tg01; $gTG23 += $tg23; $gTG46 += $tg46; $gTG7 += $tg7;
                $gEG1 += $eg1; $gEG2 += $eg2; $gEG3 += $eg3; $gEG4 += $eg4;
                $gHW += $hw; $gAW += $aw;
                $gODD += $odd;
                // tuple: [..25 eg4,26 hw,27 aw]
                $team[$h . '|' . $lg][] = [$sk, $u15, $o25, $u05, $loseH, $winH, $draw, $odd, $shg, $fhg, $nbtts, $csH, $ftsH, $htodd, $hteven, $u35, $hg05, $ag05, $tg01, $tg23, $tg46, $tg7, $eg1, $eg2, $eg3, $eg4, $hw, $aw, $a, 1];
                $team[$a . '|' . $lg][] = [$sk, $u15, $o25, $u05, $loseA, $winA, $draw, $odd, $shg, $fhg, $nbtts, $csA, $ftsA, $htodd, $hteven, $u35, $hg05, $ag05, $tg01, $tg23, $tg46, $tg7, $eg1, $eg2, $eg3, $eg4, $hw, $aw, $h, 0];
            }
        }
        fclose($fh);
    }

    $rows = [];
    $leagueSet = [];
    // Semua akumulator global: [total, nextOver15, nextOver05]
    $gA  = [1 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 2 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 3 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 4 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]]; // streak U1.5
    $gB  = [1 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 2 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 3 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]];                 // streak U0.5
    $gC  = [1 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 2 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 3 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]]; // streak KALAH (1/2/3x)
    $gW2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $gDR2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                     // 2x MENANG / 2x DRAW
    $gW3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $gDR3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                     // 3x MENANG / 3x DRAW
    $gW3O80 = array_fill(0, 26, 0); $gW3O80H = array_fill(0, 26, 0);   // Menang 3x + lawan Over1.5>=80% (+kandang)
    $gBOTH80 = array_fill(0, 26, 0); $gBOTH85 = array_fill(0, 26, 0);  // Kedua tim (sendiri + lawan) Over1.5>=80% / >=85%
    $gB2560 = array_fill(0, 26, 0); $gB2565 = array_fill(0, 26, 0);    // Kedua tim Over2.5>=60% / >=65%
    $gOD4 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $gEV4 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                    // 4x ODD / 4x EVEN
    $gOD5 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $gEV5 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                    // 5x ODD / 5x EVEN
    $gO25S2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $gO15S3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                // momentum: O2.5 2x / O1.5 3x
    $gO25S3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $gNB3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                  // momentum: O2.5 3x / No BTTS 3x
    $gNFHG2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $gNSHG2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                // No FHG 3x / No SHG 3x
    $gNFHG3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $gNSHG3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                // No FHG 3x / No SHG 3x
    $gOD4U = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $gEV4U = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                  // Odd/Even 5x + >=2 dari 4 U1.5
    $gU15OE3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                                                    // U1.5 3x + 1 Odd + 2 Even (total skor)
    $gDRY2O = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $gBTSO3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                 // Kering total 3x / BTTS+O1.5 3x
    $gKNCS3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $gNFO3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                  // Kalah+kebobolan 3x / NoFHG tapi O0.5 3x
    $gBT2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $gBT3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $gNB2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; // BTTS 2x/3x, NoBTTS 2x
    $gCS2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $gFTS2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                   // Cleansheet 3x / Gagal cetak 3x
    $gHTO3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $gHTE3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                  // HT-Odd 3x / HT-Even 3x

    // Mode streak PANJANG (varian 4x/5x/6x dari keluarga kondisi yang sudah ada).
    // key => [index tuple, negasi flag?, panjang streak]. Dideteksi lewat loop generik
    // (pola sama dgn foreach U1.5 di atas), akumulator 12-slot sama dgn mode lain.
    // Tiap mode = daftar 1+ kondisi [index tuple, negasi?, panjang]; semua harus terpenuhi.
    $newModes = [
        'u15_5'  => [[1, false, 5]],  'u15_6'  => [[1, false, 6]],   // U1.5 5x/6x
        '05_4'   => [[3, false, 4]],                                  // U0.5 4x
        'kl_4'   => [[4, false, 4]],  'kl_5'   => [[4, false, 5]],   // Kalah 4x/5x
        'mn_4'   => [[5, false, 4]],  'mn_5'   => [[5, false, 5]],   // Menang 4x/5x
        'dr_4'   => [[6, false, 4]],                                  // Draw 4x
        'o15s4'  => [[1, true, 4]],   'o15s5'  => [[1, true, 5]],    // O1.5 4x/5x (bukan U1.5)
        'o25s4'  => [[2, false, 4]],  'o25s5'  => [[2, false, 5]],   // O2.5 4x/5x
        'btts4'  => [[10, true, 4]],  'nbtts4' => [[10, false, 4]],  // BTTS 4x (bukan NoBTTS) / NoBTTS 4x
        'od_6'   => [[7, false, 6]],  'ev_6'   => [[7, true, 6]],    // Odd 6x / Even 6x (bukan Odd)
        'shg3'   => [[8, false, 3]],  'fhg3'   => [[9, false, 3]],   // SHG 3x / FHG 3x (selalu ada gol babak 2 / babak 1)
        'u35_4'  => [[15, false, 4]],                                 // Under 3.5 4x
    ];
    // Kombinasi kondisi (preset, langsung tampil di dropdown Kondisi seperti combo lama).
    // Dua kondisi harus terpenuhi bersamaan pada N match terakhir masing-masing.
    $newModes += [
        'c_u15kl' => [[1, false, 3], [4, false, 2]],   // U1.5 3x + Kalah 2x
        'c_u15nf' => [[1, false, 3], [9, true, 3]],    // U1.5 3x + NoFHG 2x
        'c_klfts' => [[4, false, 2], [12, false, 3]],  // Kalah 2x + Gagal cetak 3x
        'c_mno25' => [[5, false, 2], [2, false, 2]],   // Menang 2x + O2.5 2x
        'c_mnbt'  => [[5, false, 2], [10, true, 2]],   // Menang 2x + BTTS 2x
        'c_o15bt' => [[1, true, 3], [10, true, 2]],    // O1.5 3x + BTTS 2x
        'c_u05ns' => [[3, false, 2], [8, true, 3]],    // U0.5 2x + NoSHG 2x
        'c_dru15' => [[6, false, 3], [1, false, 3]],   // Draw 3x + U1.5 3x
        'c_evu15' => [[7, true, 4], [1, false, 3]],    // Even 4x + U1.5 3x
        'c_odo15' => [[7, false, 4], [1, true, 3]],    // Odd 4x + O1.5 3x
        'c_csmn'  => [[11, false, 3], [5, false, 2]],  // Cleansheet 3x + Menang 2x
        'c_ftsnf' => [[12, false, 3], [9, true, 3]],   // Gagal cetak 3x + NoFHG 2x
    ];
    // Kombinasi 2 kondisi — batch 2 (ide tambahan).
    $newModes += [
        'c_klnb'   => [[4, false, 2], [10, false, 3]],  // Kalah 2x + NoBTTS 2x — kalah tanpa balas
        'c_mnnf'   => [[5, false, 2], [9, true, 3]],    // Menang 2x + NoFHG 2x — menang lewat gol babak 2
        'c_drnb'   => [[6, false, 3], [10, false, 3]],  // Draw 3x + NoBTTS 2x — seri kering (0-0)
        'c_dro25'  => [[6, false, 3], [2, false, 2]],   // Draw 3x + O2.5 2x — seri rame gol (2-2)
        'c_u15ns'  => [[1, false, 3], [8, true, 3]],    // U1.5 3x + NoSHG 2x — babak 2 mandek
        'c_o25bt3' => [[2, false, 3], [10, true, 3]],   // O2.5 3x + BTTS 3x — saling serang panjang
        'c_csu15'  => [[11, false, 3], [1, false, 3]],  // Cleansheet 3x + U1.5 3x — gembok ganda
        'c_kl3fts' => [[4, false, 3], [12, false, 3]],  // Kalah 3x + Gagal cetak 3x — terpuruk dalam
        'c_htou15' => [[13, false, 3], [1, false, 3]],  // HT-Odd 3x + U1.5 3x
        'c_hteo15' => [[14, false, 3], [1, true, 3]],   // HT-Even 3x + O1.5 3x
    ];
    // Kombinasi 3 KONDISI (preset): tiga kondisi harus terpenuhi bersamaan.
    $newModes += [
        'c3_u15klnf' => [[1, false, 3], [4, false, 2], [9, true, 3]],   // U1.5 3x + Kalah 2x + NoFHG 2x — mati gaya
        'c3_klftsnf' => [[4, false, 2], [12, false, 3], [9, true, 3]],  // Kalah 2x + Gagal cetak 3x + NoFHG 2x — krisis serangan
        'c3_mnbto25' => [[5, false, 2], [10, true, 2], [2, false, 2]],  // Menang 2x + BTTS 2x + O2.5 2x — mesin gol panas
        'c3_mncs'    => [[5, false, 2], [11, false, 3], [1, true, 3]],  // Menang 2x + Cleansheet 3x + O1.5 3x — solid & produktif
        'c3_u05krg'  => [[3, false, 2], [8, true, 3], [9, true, 3]],    // U0.5 2x + NoSHG 2x + NoFHG 2x — super kering
        'c3_o15bto25'=> [[1, true, 3], [10, true, 2], [2, false, 2]],   // O1.5 3x + BTTS 2x + O2.5 2x — banjir gol stabil
        'c3_dru15nb' => [[6, false, 3], [1, false, 3], [10, false, 3]], // Draw 3x + U1.5 3x + NoBTTS 2x — pertahanan gembok
        'c3_klbto25' => [[4, false, 2], [10, true, 2], [2, false, 2]],  // Kalah 2x + BTTS 2x + O2.5 2x — kalah tapi rame gol
    ];
    // Kombinasi 2 kondisi — batch 3 (ide tambahan).
    $newModes += [
        'c_klo25'  => [[4, false, 2], [2, false, 2]],   // Kalah 2x + O2.5 2x — kalah di laga rame gol
        'c_mnu15'  => [[5, false, 2], [1, false, 3]],   // Menang 2x + U1.5 3x — menang tipis (1-0)
        'c_csnf'   => [[11, false, 3], [9, true, 3]],   // Cleansheet 3x + NoFHG 2x — gembok babak 1
        'c_kl3nb'  => [[4, false, 3], [10, false, 3]],  // Kalah 3x + NoBTTS 2x — kalah bersih tanpa balas
        'c_mn3o25' => [[5, false, 3], [2, false, 3]],   // Menang 3x + O2.5 3x — mesin gol panjang
        'c_odbt'   => [[7, false, 4], [10, true, 2]],   // Odd 4x + BTTS 2x — skor ganjil saling serang
        'c_evnb'   => [[7, true, 4], [10, false, 3]],   // Even 4x + NoBTTS 2x — genap & kering
        'c_dr3u15' => [[6, false, 3], [1, false, 3]],   // Draw 3x + U1.5 3x — seri minim gol beruntun
        'c_nfns'   => [[9, true, 3], [8, true, 3]],     // NoFHG 2x + NoSHG 2x — dua babak sama-sama seret
    ];
    // Kombinasi khusus U1.5 3x/4x (ide tambahan dari filter utama).
    $newModes += [
        'c_u15fts'      => [[1, false, 3], [12, false, 3]],
        'c_u15nb'       => [[1, false, 3], [10, false, 3]],
        'c_u15u35'      => [[1, false, 3], [15, false, 4]],
        'c_u154kl'      => [[1, false, 4], [4, false, 2]],
        'c_u154nf'      => [[1, false, 4], [9, true, 3]],
        'c_u154ns'      => [[1, false, 4], [8, true, 3]],
        'c_u154nb'      => [[1, false, 4], [10, false, 3]],
        'c_u154fts'     => [[1, false, 4], [12, false, 3]],
        'c_u154cs'      => [[1, false, 4], [11, false, 3]],
        'c3_u154klnf'   => [[1, false, 4], [4, false, 2], [9, true, 3]],
        'c3_u154nbns'   => [[1, false, 4], [10, false, 3], [8, true, 3]],
        'c3_u154csnf'   => [[1, false, 4], [11, false, 3], [9, true, 3]],
        'c4_u154gembok' => [[1, false, 4], [11, false, 3], [9, true, 3], [10, false, 3]],
        'c4_u154krisis' => [[1, false, 4], [4, false, 2], [12, false, 3], [8, true, 3]],
    ];
    // Kombinasi 3 kondisi — batch 3.
    $newModes += [
        'c3_csu15nf'  => [[11, false, 3], [1, false, 3], [9, true, 3]],   // Cleansheet 3x + U1.5 3x + NoFHG 2x — super gembok
        'c3_mno25bt3' => [[5, false, 2], [2, false, 3], [10, true, 3]],   // Menang 2x + O2.5 3x + BTTS 3x — dominan tapi terbuka
        'c3_dru15ns'  => [[6, false, 3], [1, false, 3], [8, true, 3]],    // Draw 3x + U1.5 3x + NoSHG 2x — mandek total
        'c3_klu15fts' => [[4, false, 2], [1, false, 3], [12, false, 3]],  // Kalah 2x + U1.5 3x + Gagal cetak 3x — tumpul & keok
    ];
    // Kombinasi 4 KONDISI (preset): empat kondisi harus terpenuhi bersamaan.
    $newModes += [
        'c4_krisis' => [[4, false, 2], [12, false, 3], [9, true, 3], [1, false, 3]],  // Kalah 2x + Gagal cetak 3x + NoFHG 2x + U1.5 3x — krisis total
        'c4_panas'  => [[5, false, 2], [10, true, 2], [2, false, 2], [1, true, 3]],   // Menang 2x + BTTS 2x + O2.5 2x + O1.5 3x — panas maksimal
        'c4_gembok'   => [[11, false, 3], [1, false, 3], [9, true, 3], [10, false, 3]], // Cleansheet 3x + U1.5 3x + NoFHG 2x + NoBTTS 2x — gembok total
        'c4_terpuruk' => [[4, false, 3], [12, false, 3], [10, false, 3], [1, false, 3]], // Kalah 3x + Gagal cetak 3x + NoBTTS 2x + U1.5 3x — terpuruk total
        'c4_badai'    => [[5, false, 3], [2, false, 3], [10, true, 2], [1, true, 3]],   // Menang 3x + O2.5 3x + BTTS 2x + O1.5 3x — badai gol
    ];
    // Kombinasi 5 KONDISI (preset): lima kondisi harus terpenuhi bersamaan.
    $newModes += [
        'c5_krisis' => [[4, false, 2], [12, false, 3], [9, true, 3], [8, true, 3], [1, false, 3]],   // Kalah 2x + Gagal cetak 3x + NoFHG 2x + NoSHG 2x + U1.5 3x
        'c5_gembok' => [[11, false, 3], [1, false, 3], [9, true, 3], [8, true, 3], [10, false, 3]],  // Cleansheet 3x + U1.5 3x + NoFHG 2x + NoSHG 2x + NoBTTS 2x
        'c5_badai'  => [[5, false, 2], [10, true, 2], [2, false, 2], [1, true, 3], [7, false, 4]],   // Menang 2x + BTTS 2x + O2.5 2x + O1.5 3x + Odd 4x
    ];
    // Kombinasi HASIL MINING matches.csv (v51): kombinasi dgn win rate ~100% atau
    // lift terbesar vs baseline pada outcome tertentu (sampel global >= 30).
    $newModes += [
        'cm_shfhcs'   => [[8, false, 3], [9, false, 3], [11, false, 3]],   // SHG 3x + FHG 3x + CS 3x → O0.5 100% (n=61)
        'cm_drnfbt'   => [[6, false, 3], [9, true, 3], [10, true, 2]],     // Draw 3x + NoFHG 3x + BTTS 2x → O0.5 100% (n=42)
        'cm_o15cshto' => [[1, true, 3], [11, false, 3], [13, false, 3]],   // O1.5 3x + CS 3x + HT-Odd 3x → O0.5 100% (n=40)
        'cm_nsbthto'  => [[8, true, 3], [10, true, 2], [13, false, 3]],    // NoSHG 3x + BTTS 2x + HT-Odd 3x → FHG 96.7%
        'cm_odftshto' => [[7, false, 4], [12, false, 3], [13, false, 3]],  // Odd 4x + FTS 3x + HT-Odd 3x → U3.5 91.4%
        'cm_o15o25cs' => [[1, true, 3], [2, false, 2], [11, false, 3]],    // O1.5 3x + O2.5 2x + CS 3x → Away O0.5 95%
        'cm_nsftshto' => [[8, true, 3], [12, false, 3], [13, false, 3]],   // NoSHG 3x + FTS 3x + HT-Odd 3x → NoDraw 93.8%
        'cm_o25ftsu35'=> [[2, false, 2], [12, false, 3], [15, false, 4]],  // O2.5 2x + FTS 3x + U3.5 4x → Home O0.5 & O1.5 90.7%
        'cm_o25nsu35' => [[2, false, 2], [8, true, 3], [15, false, 4]],    // O2.5 2x + NoSHG 3x + U3.5 4x → FHG 90.4%
        // Lolos validasi out-of-sample (train <2026-05-01, test >=2026-05-01;
        // cek ulang via validate_mining_modes.php: test n>=30 & Wilson LB>=75%):
        'cm_o25fhcs'  => [[2, false, 2], [9, false, 3], [11, false, 3]],   // O2.5 2x + FHG 3x + CS 3x → Away O0.5 (test 100%)
        'cm_o15cshte' => [[1, true, 3], [11, false, 3], [14, false, 3]],   // O1.5 3x + CS 3x + HT-Even 3x → Away O0.5 (test 100%)
        'cm_klodns'   => [[4, false, 3], [7, false, 4], [8, true, 3]],     // Kalah 3x + Odd 4x + NoSHG 3x → No Draw
        'cm_u05evfts' => [[3, false, 2], [7, true, 4], [12, false, 3]],    // U0.5 2x + Even 4x + FTS 3x → U3.5 (test n=31, 90.3%, LB 75.1%)
    ];

    // Backtest kemarin/hari ini: definisi kondisi utk mode lama yang sederhana
    // (mode kompleks od4u/u15oe3/dry2o/dst dilewati) + semua mode $newModes.
    $verifyModes = [
        '3' => [[1, false, 3]], '4' => [[1, false, 4]],
        '05_1' => [[3, false, 1]], '05_2' => [[3, false, 2]], '05_3' => [[3, false, 3]],
        'kl_1' => [[4, false, 1]], 'kl_2' => [[4, false, 2]], 'kl_3' => [[4, false, 3]],
        'mn_2' => [[5, false, 2]], 'mn_3' => [[5, false, 3]],
        'dr_3' => [[6, false, 3]],
        'od_4' => [[7, false, 4]], 'od_5' => [[7, false, 5]],
        'ev_4' => [[7, true, 4]], 'ev_5' => [[7, true, 5]],
        'o25s2' => [[2, false, 2]], 'o15s3' => [[1, true, 3]], 'o25s3' => [[2, false, 3]],
        'nbtts3' => [[10, false, 3]], 'nfhg3' => [[9, true, 3]],
        'nshg3' => [[8, true, 3]],
        'btts2' => [[10, true, 2]], 'btts3' => [[10, true, 3]],
        'cs2' => [[11, false, 3]], 'fts2' => [[12, false, 3]],
        'htodd3' => [[13, false, 3]], 'hteven3' => [[14, false, 3]],
    ] + $newModes;
    $vYest = date('Y-m-d', strtotime('-1 day'));
    $vToday = date('Y-m-d');
    // vAcc[hari][mode] = [sampel, hit o15, hit o05, shg, fhg, u25, o25, u35, btts, nbtts, draw, nodraw] (angka mentah)
    $vAcc = ['y' => [], 't' => []];
    $gNM = [];
    foreach ($newModes as $nmKey => $_nmCfg) { $gNM[$nmKey] = array_fill(0, 26, 0); }

    // Pra-pass: rate Over 1.5 tiap tim|liga (untuk faktor lawan di next match).
    $overRateMap = [];
    foreach ($team as $k => $arr) {
        $nn = count($arr); if ($nn === 0) continue;
        $u = 0; foreach ($arr as $e) $u += $e[1]; // e[1] = isU1.5
        $overRateMap[$k] = round((1 - $u / $nn) * 100, 1); // % Over 1.5
    }
    $over25Map = [];
    foreach ($team as $k => $arr) {
        $nn = count($arr); if ($nn === 0) continue;
        $o = 0; foreach ($arr as $e) $o += $e[2]; // e[2] = isO2.5 (tot>2)
        $over25Map[$k] = round($o / $nn * 100, 1); // % Over 2.5
    }

    // Helper akumulasi 26-slot: [0]=total, [1..25]=outcome next-match (urutan sama dgn $mk).
    $accf = function (array &$A, array $v) { $A[0]++; for ($z = 0; $z < 25; $z++) $A[$z + 1] += $v[$z]; };
    if ($csvError === null) {
        foreach ($team as $key => $arr) {
            usort($arr, fn($x, $y) => strcmp($x[0], $y[0])); // terlama dulu
            $n = count($arr);
            [$tm, $lg] = array_pad(explode('|', $key, 2), 2, '');
            if ($lg !== '') $leagueSet[$lg] = true;

            $u15tot = 0;
            // tiap akumulator: [total, nextOver15, nextOver05]
            $a = [1 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 2 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 3 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 4 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]]; // streak U1.5
            $b = [1 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 2 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 3 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]];                 // streak U0.5
            $c = [1 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 2 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 3 => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]]; // streak KALAH (1/2/3x)
            $w2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $dr2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                     // 2x MENANG / 2x DRAW
            $w3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $dr3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                     // 3x MENANG / 3x DRAW
            $w3o80 = array_fill(0, 26, 0); $w3o80h = array_fill(0, 26, 0);   // Menang 3x + lawan Over1.5>=80% (+kandang)
            $both80 = array_fill(0, 26, 0); $both85 = array_fill(0, 26, 0);  // Kedua tim (sendiri + lawan) Over1.5>=80% / >=85%
            $b2560 = array_fill(0, 26, 0); $b2565 = array_fill(0, 26, 0);    // Kedua tim Over2.5>=60% / >=65%
            $selfR = $overRateMap[$key] ?? 0;                                // rate Over1.5 musim tim ini
            $self25R = $over25Map[$key] ?? 0;                                // rate Over2.5 musim tim ini
            $od4 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $ev4 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                    // 4x ODD / 4x EVEN
            $od5 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $ev5 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                    // 5x ODD / 5x EVEN
            $o25s2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $o15s3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                // momentum
            $o25s3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $nb3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                  // momentum
            $nfhg2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $nshg2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                // No FHG 3x / No SHG 3x
            $nfhg3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $nshg3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                // No FHG 3x / No SHG 3x
            $od4u = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $ev4u = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                  // Odd/Even 5x + >=2 dari 4 U1.5
            $u15oe3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                                                  // U1.5 3x + 1 Odd + 2 Even (total skor)
            $dry2o = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $btso3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                // Kering total 3x / BTTS+O1.5 3x
            $kncs3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $nfo3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                 // Kalah+kebobolan 3x / NoFHG tapi O0.5 3x
            $bt2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $bt3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $nb2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; // BTTS 2x/3x, NoBTTS 2x
            $cs2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $fts2 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                   // Cleansheet 3x / Gagal cetak 3x
            $hto3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; $hte3 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];                  // HT-Odd 3x / HT-Even 3x
            $nm = []; foreach ($newModes as $nmKey => $_nmCfg) { $nm[$nmKey] = array_fill(0, 26, 0); } // mode streak panjang
            $curLose = 0;
            for ($i = 0; $i < $n; $i++) {
                $u15tot += $arr[$i][1];
                $ov15 = $arr[$i][1] ? 0 : 1; // Over 1.5 = bukan U1.5
                $ov05 = $arr[$i][3] ? 0 : 1; // Over 0.5 = bukan U0.5 (bukan 0-0)
                $shgN = $arr[$i][8] ?? 0;    // SHG: gol babak 2 di match berikutnya
                $fhgN = $arr[$i][9] ?? 0;    // FHG: gol babak 1 di match berikutnya
                $u25N = $arr[$i][2] ? 0 : 1; // Under 2.5 = bukan Over 2.5 (tot <= 2)
                $o25N = $arr[$i][2] ?? 0;    // Over 2.5
                $u35N = $arr[$i][15] ?? 0;   // Under 3.5 (<= 3 gol)
                $nbttsN = $arr[$i][10] ?? 0; $bttsN = $nbttsN ? 0 : 1;   // BTTS / No BTTS match berikutnya
                $drawN = $arr[$i][6] ?? 0; $nodrawN = $drawN ? 0 : 1;   // Draw / No Draw match berikutnya
                $hg05N = $arr[$i][16] ?? 0; $ag05N = $arr[$i][17] ?? 0; // home cetak >=1 / away cetak >=1 di match berikutnya
                $tg01N = $arr[$i][18] ?? 0; $tg23N = $arr[$i][19] ?? 0; // total gol 0-1 / 2-3 di match berikutnya
                $tg46N = $arr[$i][20] ?? 0; $tg7N = $arr[$i][21] ?? 0;  // total gol 4-6 / 7+ di match berikutnya
                $eg1N = $arr[$i][22] ?? 0; $eg2N = $arr[$i][23] ?? 0;   // total gol pas 1 / 2 di match berikutnya
                $eg3N = $arr[$i][24] ?? 0; $eg4N = $arr[$i][25] ?? 0;   // total gol pas 3 / 4 di match berikutnya
                $hwN = $arr[$i][26] ?? 0; $awN = $arr[$i][27] ?? 0;     // home win / away win di match berikutnya
                $ftoddN = $arr[$i][7] ?? 0; $ftevenN = ($arr[$i][7] ?? 0) ? 0 : 1; // FT total gol ganjil / genap di match berikutnya
                $vals = [$ov15, $ov05, $shgN, $fhgN, $u25N, $o25N, $u35N, $bttsN, $nbttsN, $drawN, $nodrawN, $hg05N, $ag05N, $tg01N, $tg23N, $tg46N, $tg7N, $eg1N, $eg2N, $eg3N, $eg4N, $hwN, $awN, $ftoddN, $ftevenN];
                // Kondisi "kedua tim subur": rate musim tim sendiri & lawan (match berikutnya) sama-sama tinggi.
                $oppRcur = $overRateMap[(($arr[$i][28] ?? '')) . '|' . $lg] ?? 0;
                if ($selfR >= 80 && $oppRcur >= 80) { $accf($both80, $vals); $accf($gBOTH80, $vals); }
                if ($selfR >= 85 && $oppRcur >= 85) { $accf($both85, $vals); $accf($gBOTH85, $vals); }
                // Kedua tim subur Over 2.5 (rate musim O2.5 tim sendiri & lawan)
                $opp25cur = $over25Map[(($arr[$i][28] ?? '')) . '|' . $lg] ?? 0;
                if ($self25R >= 60 && $opp25cur >= 60) { $accf($b2560, $vals); $accf($gB2560, $vals); }
                if ($self25R >= 65 && $opp25cur >= 65) { $accf($b2565, $vals); $accf($gB2565, $vals); }
                foreach ([1, 2, 3, 4] as $k) { // streak U1.5
                    if ($i < $k) continue;
                    $ok = true;
                    for ($j = 1; $j <= $k; $j++) { if (!$arr[$i - $j][1]) { $ok = false; break; } }
                    if (!$ok) continue;
                    $a[$k][0]++; $a[$k][1] += $ov15; $a[$k][2] += $ov05; $a[$k][3] += $shgN; $a[$k][4] += $fhgN; $a[$k][5] += $u25N; $a[$k][6] += $o25N; $a[$k][7] += $u35N; $a[$k][8] += $bttsN; $a[$k][9] += $nbttsN; $a[$k][10] += $drawN; $a[$k][11] += $nodrawN; $a[$k][12] += $hg05N; $a[$k][13] += $ag05N; $a[$k][14] += $tg01N; $a[$k][15] += $tg23N; $a[$k][16] += $tg46N; $a[$k][17] += $tg7N; $a[$k][18] += $eg1N; $a[$k][19] += $eg2N; $a[$k][20] += $eg3N; $a[$k][21] += $eg4N; $a[$k][22] += $hwN; $a[$k][23] += $awN; $a[$k][24] += $ftoddN; $a[$k][25] += $ftevenN;
                    $gA[$k][0]++; $gA[$k][1] += $ov15; $gA[$k][2] += $ov05; $gA[$k][3] += $shgN; $gA[$k][4] += $fhgN; $gA[$k][5] += $u25N; $gA[$k][6] += $o25N; $gA[$k][7] += $u35N; $gA[$k][8] += $bttsN; $gA[$k][9] += $nbttsN; $gA[$k][10] += $drawN; $gA[$k][11] += $nodrawN; $gA[$k][12] += $hg05N; $gA[$k][13] += $ag05N; $gA[$k][14] += $tg01N; $gA[$k][15] += $tg23N; $gA[$k][16] += $tg46N; $gA[$k][17] += $tg7N; $gA[$k][18] += $eg1N; $gA[$k][19] += $eg2N; $gA[$k][20] += $eg3N; $gA[$k][21] += $eg4N; $gA[$k][22] += $hwN; $gA[$k][23] += $awN; $gA[$k][24] += $ftoddN; $gA[$k][25] += $ftevenN;
                }
                foreach ([1, 2, 3] as $k) { // streak U0.5
                    if ($i < $k) continue;
                    $ok = true;
                    for ($j = 1; $j <= $k; $j++) { if (!$arr[$i - $j][3]) { $ok = false; break; } }
                    if (!$ok) continue;
                    $b[$k][0]++; $b[$k][1] += $ov15; $b[$k][2] += $ov05; $b[$k][3] += $shgN; $b[$k][4] += $fhgN; $b[$k][5] += $u25N; $b[$k][6] += $o25N; $b[$k][7] += $u35N; $b[$k][8] += $bttsN; $b[$k][9] += $nbttsN; $b[$k][10] += $drawN; $b[$k][11] += $nodrawN; $b[$k][12] += $hg05N; $b[$k][13] += $ag05N; $b[$k][14] += $tg01N; $b[$k][15] += $tg23N; $b[$k][16] += $tg46N; $b[$k][17] += $tg7N; $b[$k][18] += $eg1N; $b[$k][19] += $eg2N; $b[$k][20] += $eg3N; $b[$k][21] += $eg4N; $b[$k][22] += $hwN; $b[$k][23] += $awN; $b[$k][24] += $ftoddN; $b[$k][25] += $ftevenN;
                    $gB[$k][0]++; $gB[$k][1] += $ov15; $gB[$k][2] += $ov05; $gB[$k][3] += $shgN; $gB[$k][4] += $fhgN; $gB[$k][5] += $u25N; $gB[$k][6] += $o25N; $gB[$k][7] += $u35N; $gB[$k][8] += $bttsN; $gB[$k][9] += $nbttsN; $gB[$k][10] += $drawN; $gB[$k][11] += $nodrawN; $gB[$k][12] += $hg05N; $gB[$k][13] += $ag05N; $gB[$k][14] += $tg01N; $gB[$k][15] += $tg23N; $gB[$k][16] += $tg46N; $gB[$k][17] += $tg7N; $gB[$k][18] += $eg1N; $gB[$k][19] += $eg2N; $gB[$k][20] += $eg3N; $gB[$k][21] += $eg4N; $gB[$k][22] += $hwN; $gB[$k][23] += $awN; $gB[$k][24] += $ftoddN; $gB[$k][25] += $ftevenN;
                }
                foreach ([1, 2, 3] as $k) { // streak KALAH (1/2/3x)
                    if ($i < $k) continue;
                    $ok = true;
                    for ($j = 1; $j <= $k; $j++) { if (!$arr[$i - $j][4]) { $ok = false; break; } }
                    if (!$ok) continue;
                    $c[$k][0]++; $c[$k][1] += $ov15; $c[$k][2] += $ov05; $c[$k][3] += $shgN; $c[$k][4] += $fhgN; $c[$k][5] += $u25N; $c[$k][6] += $o25N; $c[$k][7] += $u35N; $c[$k][8] += $bttsN; $c[$k][9] += $nbttsN; $c[$k][10] += $drawN; $c[$k][11] += $nodrawN; $c[$k][12] += $hg05N; $c[$k][13] += $ag05N; $c[$k][14] += $tg01N; $c[$k][15] += $tg23N; $c[$k][16] += $tg46N; $c[$k][17] += $tg7N; $c[$k][18] += $eg1N; $c[$k][19] += $eg2N; $c[$k][20] += $eg3N; $c[$k][21] += $eg4N; $c[$k][22] += $hwN; $c[$k][23] += $awN; $c[$k][24] += $ftoddN; $c[$k][25] += $ftevenN;
                    $gC[$k][0]++; $gC[$k][1] += $ov15; $gC[$k][2] += $ov05; $gC[$k][3] += $shgN; $gC[$k][4] += $fhgN; $gC[$k][5] += $u25N; $gC[$k][6] += $o25N; $gC[$k][7] += $u35N; $gC[$k][8] += $bttsN; $gC[$k][9] += $nbttsN; $gC[$k][10] += $drawN; $gC[$k][11] += $nodrawN; $gC[$k][12] += $hg05N; $gC[$k][13] += $ag05N; $gC[$k][14] += $tg01N; $gC[$k][15] += $tg23N; $gC[$k][16] += $tg46N; $gC[$k][17] += $tg7N; $gC[$k][18] += $eg1N; $gC[$k][19] += $eg2N; $gC[$k][20] += $eg3N; $gC[$k][21] += $eg4N; $gC[$k][22] += $hwN; $gC[$k][23] += $awN; $gC[$k][24] += $ftoddN; $gC[$k][25] += $ftevenN;
                }
                if ($i >= 2 && $arr[$i - 1][5] && $arr[$i - 2][5]) { // 2x MENANG
                    $w2[0]++; $w2[1] += $ov15; $w2[2] += $ov05; $w2[3] += $shgN; $w2[4] += $fhgN; $w2[5] += $u25N; $w2[6] += $o25N; $w2[7] += $u35N; $w2[8] += $bttsN; $w2[9] += $nbttsN; $w2[10] += $drawN; $w2[11] += $nodrawN; $w2[12] += $hg05N; $w2[13] += $ag05N; $w2[14] += $tg01N; $w2[15] += $tg23N; $w2[16] += $tg46N; $w2[17] += $tg7N; $w2[18] += $eg1N; $w2[19] += $eg2N; $w2[20] += $eg3N; $w2[21] += $eg4N; $w2[22] += $hwN; $w2[23] += $awN; $w2[24] += $ftoddN; $w2[25] += $ftevenN;
                    $gW2[0]++; $gW2[1] += $ov15; $gW2[2] += $ov05; $gW2[3] += $shgN; $gW2[4] += $fhgN; $gW2[5] += $u25N; $gW2[6] += $o25N; $gW2[7] += $u35N; $gW2[8] += $bttsN; $gW2[9] += $nbttsN; $gW2[10] += $drawN; $gW2[11] += $nodrawN; $gW2[12] += $hg05N; $gW2[13] += $ag05N; $gW2[14] += $tg01N; $gW2[15] += $tg23N; $gW2[16] += $tg46N; $gW2[17] += $tg7N; $gW2[18] += $eg1N; $gW2[19] += $eg2N; $gW2[20] += $eg3N; $gW2[21] += $eg4N; $gW2[22] += $hwN; $gW2[23] += $awN; $gW2[24] += $ftoddN; $gW2[25] += $ftevenN;
                }
                if ($i >= 3 && $arr[$i - 1][5] && $arr[$i - 2][5] && $arr[$i - 3][5]) { // 3x MENANG
                    $w3[0]++; $w3[1] += $ov15; $w3[2] += $ov05; $w3[3] += $shgN; $w3[4] += $fhgN; $w3[5] += $u25N; $w3[6] += $o25N; $w3[7] += $u35N; $w3[8] += $bttsN; $w3[9] += $nbttsN; $w3[10] += $drawN; $w3[11] += $nodrawN; $w3[12] += $hg05N; $w3[13] += $ag05N; $w3[14] += $tg01N; $w3[15] += $tg23N; $w3[16] += $tg46N; $w3[17] += $tg7N; $w3[18] += $eg1N; $w3[19] += $eg2N; $w3[20] += $eg3N; $w3[21] += $eg4N; $w3[22] += $hwN; $w3[23] += $awN; $w3[24] += $ftoddN; $w3[25] += $ftevenN;
                    $gW3[0]++; $gW3[1] += $ov15; $gW3[2] += $ov05; $gW3[3] += $shgN; $gW3[4] += $fhgN; $gW3[5] += $u25N; $gW3[6] += $o25N; $gW3[7] += $u35N; $gW3[8] += $bttsN; $gW3[9] += $nbttsN; $gW3[10] += $drawN; $gW3[11] += $nodrawN; $gW3[12] += $hg05N; $gW3[13] += $ag05N; $gW3[14] += $tg01N; $gW3[15] += $tg23N; $gW3[16] += $tg46N; $gW3[17] += $tg7N; $gW3[18] += $eg1N; $gW3[19] += $eg2N; $gW3[20] += $eg3N; $gW3[21] += $eg4N; $gW3[22] += $hwN; $gW3[23] += $awN; $gW3[24] += $ftoddN; $gW3[25] += $ftevenN;
                    // + syarat LAWAN Over1.5 >=80% (rate musim lawan di match berikutnya) & kandang
                    $oppR = $overRateMap[(($arr[$i][28] ?? '')) . '|' . $lg] ?? 0;
                    if ($oppR >= 80) {
                        $accf($w3o80, $vals); $accf($gW3O80, $vals);
                        if (!empty($arr[$i][29])) { $accf($w3o80h, $vals); $accf($gW3O80H, $vals); }
                    }
                }
                if ($i >= 2 && $arr[$i - 1][6] && $arr[$i - 2][6]) { // 2x DRAW
                    $dr2[0]++; $dr2[1] += $ov15; $dr2[2] += $ov05; $dr2[3] += $shgN; $dr2[4] += $fhgN; $dr2[5] += $u25N; $dr2[6] += $o25N; $dr2[7] += $u35N; $dr2[8] += $bttsN; $dr2[9] += $nbttsN; $dr2[10] += $drawN; $dr2[11] += $nodrawN; $dr2[12] += $hg05N; $dr2[13] += $ag05N; $dr2[14] += $tg01N; $dr2[15] += $tg23N; $dr2[16] += $tg46N; $dr2[17] += $tg7N; $dr2[18] += $eg1N; $dr2[19] += $eg2N; $dr2[20] += $eg3N; $dr2[21] += $eg4N; $dr2[22] += $hwN; $dr2[23] += $awN; $dr2[24] += $ftoddN; $dr2[25] += $ftevenN;
                    $gDR2[0]++; $gDR2[1] += $ov15; $gDR2[2] += $ov05; $gDR2[3] += $shgN; $gDR2[4] += $fhgN; $gDR2[5] += $u25N; $gDR2[6] += $o25N; $gDR2[7] += $u35N; $gDR2[8] += $bttsN; $gDR2[9] += $nbttsN; $gDR2[10] += $drawN; $gDR2[11] += $nodrawN; $gDR2[12] += $hg05N; $gDR2[13] += $ag05N; $gDR2[14] += $tg01N; $gDR2[15] += $tg23N; $gDR2[16] += $tg46N; $gDR2[17] += $tg7N; $gDR2[18] += $eg1N; $gDR2[19] += $eg2N; $gDR2[20] += $eg3N; $gDR2[21] += $eg4N; $gDR2[22] += $hwN; $gDR2[23] += $awN; $gDR2[24] += $ftoddN; $gDR2[25] += $ftevenN;
                }
                if ($i >= 3 && $arr[$i - 1][6] && $arr[$i - 2][6] && $arr[$i - 3][6]) { // 3x DRAW
                    $dr3[0]++; $dr3[1] += $ov15; $dr3[2] += $ov05; $dr3[3] += $shgN; $dr3[4] += $fhgN; $dr3[5] += $u25N; $dr3[6] += $o25N; $dr3[7] += $u35N; $dr3[8] += $bttsN; $dr3[9] += $nbttsN; $dr3[10] += $drawN; $dr3[11] += $nodrawN; $dr3[12] += $hg05N; $dr3[13] += $ag05N; $dr3[14] += $tg01N; $dr3[15] += $tg23N; $dr3[16] += $tg46N; $dr3[17] += $tg7N; $dr3[18] += $eg1N; $dr3[19] += $eg2N; $dr3[20] += $eg3N; $dr3[21] += $eg4N; $dr3[22] += $hwN; $dr3[23] += $awN; $dr3[24] += $ftoddN; $dr3[25] += $ftevenN;
                    $gDR3[0]++; $gDR3[1] += $ov15; $gDR3[2] += $ov05; $gDR3[3] += $shgN; $gDR3[4] += $fhgN; $gDR3[5] += $u25N; $gDR3[6] += $o25N; $gDR3[7] += $u35N; $gDR3[8] += $bttsN; $gDR3[9] += $nbttsN; $gDR3[10] += $drawN; $gDR3[11] += $nodrawN; $gDR3[12] += $hg05N; $gDR3[13] += $ag05N; $gDR3[14] += $tg01N; $gDR3[15] += $tg23N; $gDR3[16] += $tg46N; $gDR3[17] += $tg7N; $gDR3[18] += $eg1N; $gDR3[19] += $eg2N; $gDR3[20] += $eg3N; $gDR3[21] += $eg4N; $gDR3[22] += $hwN; $gDR3[23] += $awN; $gDR3[24] += $ftoddN; $gDR3[25] += $ftevenN;
                }
                if ($i >= 4 && $arr[$i - 1][7] && $arr[$i - 2][7] && $arr[$i - 3][7] && $arr[$i - 4][7]) { // 4x ODD
                    $od4[0]++; $od4[1] += $ov15; $od4[2] += $ov05; $od4[3] += $shgN; $od4[4] += $fhgN; $od4[5] += $u25N; $od4[6] += $o25N; $od4[7] += $u35N; $od4[8] += $bttsN; $od4[9] += $nbttsN; $od4[10] += $drawN; $od4[11] += $nodrawN; $od4[12] += $hg05N; $od4[13] += $ag05N; $od4[14] += $tg01N; $od4[15] += $tg23N; $od4[16] += $tg46N; $od4[17] += $tg7N; $od4[18] += $eg1N; $od4[19] += $eg2N; $od4[20] += $eg3N; $od4[21] += $eg4N; $od4[22] += $hwN; $od4[23] += $awN; $od4[24] += $ftoddN; $od4[25] += $ftevenN;
                    $gOD4[0]++; $gOD4[1] += $ov15; $gOD4[2] += $ov05; $gOD4[3] += $shgN; $gOD4[4] += $fhgN; $gOD4[5] += $u25N; $gOD4[6] += $o25N; $gOD4[7] += $u35N; $gOD4[8] += $bttsN; $gOD4[9] += $nbttsN; $gOD4[10] += $drawN; $gOD4[11] += $nodrawN; $gOD4[12] += $hg05N; $gOD4[13] += $ag05N; $gOD4[14] += $tg01N; $gOD4[15] += $tg23N; $gOD4[16] += $tg46N; $gOD4[17] += $tg7N; $gOD4[18] += $eg1N; $gOD4[19] += $eg2N; $gOD4[20] += $eg3N; $gOD4[21] += $eg4N; $gOD4[22] += $hwN; $gOD4[23] += $awN; $gOD4[24] += $ftoddN; $gOD4[25] += $ftevenN;
                }
                if ($i >= 4 && !$arr[$i - 1][7] && !$arr[$i - 2][7] && !$arr[$i - 3][7] && !$arr[$i - 4][7]) { // 4x EVEN
                    $ev4[0]++; $ev4[1] += $ov15; $ev4[2] += $ov05; $ev4[3] += $shgN; $ev4[4] += $fhgN; $ev4[5] += $u25N; $ev4[6] += $o25N; $ev4[7] += $u35N; $ev4[8] += $bttsN; $ev4[9] += $nbttsN; $ev4[10] += $drawN; $ev4[11] += $nodrawN; $ev4[12] += $hg05N; $ev4[13] += $ag05N; $ev4[14] += $tg01N; $ev4[15] += $tg23N; $ev4[16] += $tg46N; $ev4[17] += $tg7N; $ev4[18] += $eg1N; $ev4[19] += $eg2N; $ev4[20] += $eg3N; $ev4[21] += $eg4N; $ev4[22] += $hwN; $ev4[23] += $awN; $ev4[24] += $ftoddN; $ev4[25] += $ftevenN;
                    $gEV4[0]++; $gEV4[1] += $ov15; $gEV4[2] += $ov05; $gEV4[3] += $shgN; $gEV4[4] += $fhgN; $gEV4[5] += $u25N; $gEV4[6] += $o25N; $gEV4[7] += $u35N; $gEV4[8] += $bttsN; $gEV4[9] += $nbttsN; $gEV4[10] += $drawN; $gEV4[11] += $nodrawN; $gEV4[12] += $hg05N; $gEV4[13] += $ag05N; $gEV4[14] += $tg01N; $gEV4[15] += $tg23N; $gEV4[16] += $tg46N; $gEV4[17] += $tg7N; $gEV4[18] += $eg1N; $gEV4[19] += $eg2N; $gEV4[20] += $eg3N; $gEV4[21] += $eg4N; $gEV4[22] += $hwN; $gEV4[23] += $awN; $gEV4[24] += $ftoddN; $gEV4[25] += $ftevenN;
                }
                if ($i >= 5 && $arr[$i - 1][7] && $arr[$i - 2][7] && $arr[$i - 3][7] && $arr[$i - 4][7] && $arr[$i - 5][7]) { // 5x ODD
                    $od5[0]++; $od5[1] += $ov15; $od5[2] += $ov05; $od5[3] += $shgN; $od5[4] += $fhgN; $od5[5] += $u25N; $od5[6] += $o25N; $od5[7] += $u35N; $od5[8] += $bttsN; $od5[9] += $nbttsN; $od5[10] += $drawN; $od5[11] += $nodrawN; $od5[12] += $hg05N; $od5[13] += $ag05N; $od5[14] += $tg01N; $od5[15] += $tg23N; $od5[16] += $tg46N; $od5[17] += $tg7N; $od5[18] += $eg1N; $od5[19] += $eg2N; $od5[20] += $eg3N; $od5[21] += $eg4N; $od5[22] += $hwN; $od5[23] += $awN; $od5[24] += $ftoddN; $od5[25] += $ftevenN;
                    $gOD5[0]++; $gOD5[1] += $ov15; $gOD5[2] += $ov05; $gOD5[3] += $shgN; $gOD5[4] += $fhgN; $gOD5[5] += $u25N; $gOD5[6] += $o25N; $gOD5[7] += $u35N; $gOD5[8] += $bttsN; $gOD5[9] += $nbttsN; $gOD5[10] += $drawN; $gOD5[11] += $nodrawN; $gOD5[12] += $hg05N; $gOD5[13] += $ag05N; $gOD5[14] += $tg01N; $gOD5[15] += $tg23N; $gOD5[16] += $tg46N; $gOD5[17] += $tg7N; $gOD5[18] += $eg1N; $gOD5[19] += $eg2N; $gOD5[20] += $eg3N; $gOD5[21] += $eg4N; $gOD5[22] += $hwN; $gOD5[23] += $awN; $gOD5[24] += $ftoddN; $gOD5[25] += $ftevenN;
                }
                if ($i >= 5 && !$arr[$i - 1][7] && !$arr[$i - 2][7] && !$arr[$i - 3][7] && !$arr[$i - 4][7] && !$arr[$i - 5][7]) { // 5x EVEN
                    $ev5[0]++; $ev5[1] += $ov15; $ev5[2] += $ov05; $ev5[3] += $shgN; $ev5[4] += $fhgN; $ev5[5] += $u25N; $ev5[6] += $o25N; $ev5[7] += $u35N; $ev5[8] += $bttsN; $ev5[9] += $nbttsN; $ev5[10] += $drawN; $ev5[11] += $nodrawN; $ev5[12] += $hg05N; $ev5[13] += $ag05N; $ev5[14] += $tg01N; $ev5[15] += $tg23N; $ev5[16] += $tg46N; $ev5[17] += $tg7N; $ev5[18] += $eg1N; $ev5[19] += $eg2N; $ev5[20] += $eg3N; $ev5[21] += $eg4N; $ev5[22] += $hwN; $ev5[23] += $awN; $ev5[24] += $ftoddN; $ev5[25] += $ftevenN;
                    $gEV5[0]++; $gEV5[1] += $ov15; $gEV5[2] += $ov05; $gEV5[3] += $shgN; $gEV5[4] += $fhgN; $gEV5[5] += $u25N; $gEV5[6] += $o25N; $gEV5[7] += $u35N; $gEV5[8] += $bttsN; $gEV5[9] += $nbttsN; $gEV5[10] += $drawN; $gEV5[11] += $nodrawN; $gEV5[12] += $hg05N; $gEV5[13] += $ag05N; $gEV5[14] += $tg01N; $gEV5[15] += $tg23N; $gEV5[16] += $tg46N; $gEV5[17] += $tg7N; $gEV5[18] += $eg1N; $gEV5[19] += $eg2N; $gEV5[20] += $eg3N; $gEV5[21] += $eg4N; $gEV5[22] += $hwN; $gEV5[23] += $awN; $gEV5[24] += $ftoddN; $gEV5[25] += $ftevenN;
                }
                if ($i >= 2 && $arr[$i - 1][2] && $arr[$i - 2][2]) { // Over 2.5 2x (idx2 = o25)
                    $o25s2[0]++; $o25s2[1] += $ov15; $o25s2[2] += $ov05; $o25s2[3] += $shgN; $o25s2[4] += $fhgN; $o25s2[5] += $u25N; $o25s2[6] += $o25N; $o25s2[7] += $u35N; $o25s2[8] += $bttsN; $o25s2[9] += $nbttsN; $o25s2[10] += $drawN; $o25s2[11] += $nodrawN; $o25s2[12] += $hg05N; $o25s2[13] += $ag05N; $o25s2[14] += $tg01N; $o25s2[15] += $tg23N; $o25s2[16] += $tg46N; $o25s2[17] += $tg7N; $o25s2[18] += $eg1N; $o25s2[19] += $eg2N; $o25s2[20] += $eg3N; $o25s2[21] += $eg4N; $o25s2[22] += $hwN; $o25s2[23] += $awN; $o25s2[24] += $ftoddN; $o25s2[25] += $ftevenN;
                    $gO25S2[0]++; $gO25S2[1] += $ov15; $gO25S2[2] += $ov05; $gO25S2[3] += $shgN; $gO25S2[4] += $fhgN; $gO25S2[5] += $u25N; $gO25S2[6] += $o25N; $gO25S2[7] += $u35N; $gO25S2[8] += $bttsN; $gO25S2[9] += $nbttsN; $gO25S2[10] += $drawN; $gO25S2[11] += $nodrawN; $gO25S2[12] += $hg05N; $gO25S2[13] += $ag05N; $gO25S2[14] += $tg01N; $gO25S2[15] += $tg23N; $gO25S2[16] += $tg46N; $gO25S2[17] += $tg7N; $gO25S2[18] += $eg1N; $gO25S2[19] += $eg2N; $gO25S2[20] += $eg3N; $gO25S2[21] += $eg4N; $gO25S2[22] += $hwN; $gO25S2[23] += $awN; $gO25S2[24] += $ftoddN; $gO25S2[25] += $ftevenN;
                }
                if ($i >= 3 && $arr[$i - 1][2] && $arr[$i - 2][2] && $arr[$i - 3][2]) { // Over 2.5 3x
                    $o25s3[0]++; $o25s3[1] += $ov15; $o25s3[2] += $ov05; $o25s3[3] += $shgN; $o25s3[4] += $fhgN; $o25s3[5] += $u25N; $o25s3[6] += $o25N; $o25s3[7] += $u35N; $o25s3[8] += $bttsN; $o25s3[9] += $nbttsN; $o25s3[10] += $drawN; $o25s3[11] += $nodrawN; $o25s3[12] += $hg05N; $o25s3[13] += $ag05N; $o25s3[14] += $tg01N; $o25s3[15] += $tg23N; $o25s3[16] += $tg46N; $o25s3[17] += $tg7N; $o25s3[18] += $eg1N; $o25s3[19] += $eg2N; $o25s3[20] += $eg3N; $o25s3[21] += $eg4N; $o25s3[22] += $hwN; $o25s3[23] += $awN; $o25s3[24] += $ftoddN; $o25s3[25] += $ftevenN;
                    $gO25S3[0]++; $gO25S3[1] += $ov15; $gO25S3[2] += $ov05; $gO25S3[3] += $shgN; $gO25S3[4] += $fhgN; $gO25S3[5] += $u25N; $gO25S3[6] += $o25N; $gO25S3[7] += $u35N; $gO25S3[8] += $bttsN; $gO25S3[9] += $nbttsN; $gO25S3[10] += $drawN; $gO25S3[11] += $nodrawN; $gO25S3[12] += $hg05N; $gO25S3[13] += $ag05N; $gO25S3[14] += $tg01N; $gO25S3[15] += $tg23N; $gO25S3[16] += $tg46N; $gO25S3[17] += $tg7N; $gO25S3[18] += $eg1N; $gO25S3[19] += $eg2N; $gO25S3[20] += $eg3N; $gO25S3[21] += $eg4N; $gO25S3[22] += $hwN; $gO25S3[23] += $awN; $gO25S3[24] += $ftoddN; $gO25S3[25] += $ftevenN;
                }
                if ($i >= 3 && !$arr[$i - 1][1] && !$arr[$i - 2][1] && !$arr[$i - 3][1]) { // Over 1.5 3x (bukan U1.5)
                    $o15s3[0]++; $o15s3[1] += $ov15; $o15s3[2] += $ov05; $o15s3[3] += $shgN; $o15s3[4] += $fhgN; $o15s3[5] += $u25N; $o15s3[6] += $o25N; $o15s3[7] += $u35N; $o15s3[8] += $bttsN; $o15s3[9] += $nbttsN; $o15s3[10] += $drawN; $o15s3[11] += $nodrawN; $o15s3[12] += $hg05N; $o15s3[13] += $ag05N; $o15s3[14] += $tg01N; $o15s3[15] += $tg23N; $o15s3[16] += $tg46N; $o15s3[17] += $tg7N; $o15s3[18] += $eg1N; $o15s3[19] += $eg2N; $o15s3[20] += $eg3N; $o15s3[21] += $eg4N; $o15s3[22] += $hwN; $o15s3[23] += $awN; $o15s3[24] += $ftoddN; $o15s3[25] += $ftevenN;
                    $gO15S3[0]++; $gO15S3[1] += $ov15; $gO15S3[2] += $ov05; $gO15S3[3] += $shgN; $gO15S3[4] += $fhgN; $gO15S3[5] += $u25N; $gO15S3[6] += $o25N; $gO15S3[7] += $u35N; $gO15S3[8] += $bttsN; $gO15S3[9] += $nbttsN; $gO15S3[10] += $drawN; $gO15S3[11] += $nodrawN; $gO15S3[12] += $hg05N; $gO15S3[13] += $ag05N; $gO15S3[14] += $tg01N; $gO15S3[15] += $tg23N; $gO15S3[16] += $tg46N; $gO15S3[17] += $tg7N; $gO15S3[18] += $eg1N; $gO15S3[19] += $eg2N; $gO15S3[20] += $eg3N; $gO15S3[21] += $eg4N; $gO15S3[22] += $hwN; $gO15S3[23] += $awN; $gO15S3[24] += $ftoddN; $gO15S3[25] += $ftevenN;
                }
                // U1.5 3x beruntun + (total skor) tepat 1 Odd + 2 Even
                if ($i >= 3 && $arr[$i - 1][1] && $arr[$i - 2][1] && $arr[$i - 3][1]
                    && ($arr[$i - 1][7] + $arr[$i - 2][7] + $arr[$i - 3][7]) === 1) {
                    $u15oe3[0]++; $u15oe3[1] += $ov15; $u15oe3[2] += $ov05; $u15oe3[3] += $shgN; $u15oe3[4] += $fhgN; $u15oe3[5] += $u25N; $u15oe3[6] += $o25N; $u15oe3[7] += $u35N; $u15oe3[8] += $bttsN; $u15oe3[9] += $nbttsN; $u15oe3[10] += $drawN; $u15oe3[11] += $nodrawN; $u15oe3[12] += $hg05N; $u15oe3[13] += $ag05N; $u15oe3[14] += $tg01N; $u15oe3[15] += $tg23N; $u15oe3[16] += $tg46N; $u15oe3[17] += $tg7N; $u15oe3[18] += $eg1N; $u15oe3[19] += $eg2N; $u15oe3[20] += $eg3N; $u15oe3[21] += $eg4N; $u15oe3[22] += $hwN; $u15oe3[23] += $awN; $u15oe3[24] += $ftoddN; $u15oe3[25] += $ftevenN;
                    $gU15OE3[0]++; $gU15OE3[1] += $ov15; $gU15OE3[2] += $ov05; $gU15OE3[3] += $shgN; $gU15OE3[4] += $fhgN; $gU15OE3[5] += $u25N; $gU15OE3[6] += $o25N; $gU15OE3[7] += $u35N; $gU15OE3[8] += $bttsN; $gU15OE3[9] += $nbttsN; $gU15OE3[10] += $drawN; $gU15OE3[11] += $nodrawN; $gU15OE3[12] += $hg05N; $gU15OE3[13] += $ag05N; $gU15OE3[14] += $tg01N; $gU15OE3[15] += $tg23N; $gU15OE3[16] += $tg46N; $gU15OE3[17] += $tg7N; $gU15OE3[18] += $eg1N; $gU15OE3[19] += $eg2N; $gU15OE3[20] += $eg3N; $gU15OE3[21] += $eg4N; $gU15OE3[22] += $hwN; $gU15OE3[23] += $awN; $gU15OE3[24] += $ftoddN; $gU15OE3[25] += $ftevenN;
                }
                // Kering total 3x: 3 match terakhir No FHG (idx9) + No SHG (idx8) + U1.5 (idx1) → rebound
                if ($i >= 3 && !$arr[$i - 1][9] && !$arr[$i - 1][8] && $arr[$i - 1][1]
                    && !$arr[$i - 2][9] && !$arr[$i - 2][8] && $arr[$i - 2][1]
                    && !$arr[$i - 3][9] && !$arr[$i - 3][8] && $arr[$i - 3][1]) {
                    $dry2o[0]++; $dry2o[1] += $ov15; $dry2o[2] += $ov05; $dry2o[3] += $shgN; $dry2o[4] += $fhgN; $dry2o[5] += $u25N; $dry2o[6] += $o25N; $dry2o[7] += $u35N; $dry2o[8] += $bttsN; $dry2o[9] += $nbttsN; $dry2o[10] += $drawN; $dry2o[11] += $nodrawN; $dry2o[12] += $hg05N; $dry2o[13] += $ag05N; $dry2o[14] += $tg01N; $dry2o[15] += $tg23N; $dry2o[16] += $tg46N; $dry2o[17] += $tg7N; $dry2o[18] += $eg1N; $dry2o[19] += $eg2N; $dry2o[20] += $eg3N; $dry2o[21] += $eg4N; $dry2o[22] += $hwN; $dry2o[23] += $awN; $dry2o[24] += $ftoddN; $dry2o[25] += $ftevenN;
                    $gDRY2O[0]++; $gDRY2O[1] += $ov15; $gDRY2O[2] += $ov05; $gDRY2O[3] += $shgN; $gDRY2O[4] += $fhgN; $gDRY2O[5] += $u25N; $gDRY2O[6] += $o25N; $gDRY2O[7] += $u35N; $gDRY2O[8] += $bttsN; $gDRY2O[9] += $nbttsN; $gDRY2O[10] += $drawN; $gDRY2O[11] += $nodrawN; $gDRY2O[12] += $hg05N; $gDRY2O[13] += $ag05N; $gDRY2O[14] += $tg01N; $gDRY2O[15] += $tg23N; $gDRY2O[16] += $tg46N; $gDRY2O[17] += $tg7N; $gDRY2O[18] += $eg1N; $gDRY2O[19] += $eg2N; $gDRY2O[20] += $eg3N; $gDRY2O[21] += $eg4N; $gDRY2O[22] += $hwN; $gDRY2O[23] += $awN; $gDRY2O[24] += $ftoddN; $gDRY2O[25] += $ftevenN;
                }
                // BTTS (idx10=NoBTTS → !idx10) + Over1.5 (!idx1) 3x beruntun → serangan stabil
                if ($i >= 3 && !$arr[$i - 1][10] && !$arr[$i - 1][1] && !$arr[$i - 2][10] && !$arr[$i - 2][1] && !$arr[$i - 3][10] && !$arr[$i - 3][1]) {
                    $btso3[0]++; $btso3[1] += $ov15; $btso3[2] += $ov05; $btso3[3] += $shgN; $btso3[4] += $fhgN; $btso3[5] += $u25N; $btso3[6] += $o25N; $btso3[7] += $u35N; $btso3[8] += $bttsN; $btso3[9] += $nbttsN; $btso3[10] += $drawN; $btso3[11] += $nodrawN; $btso3[12] += $hg05N; $btso3[13] += $ag05N; $btso3[14] += $tg01N; $btso3[15] += $tg23N; $btso3[16] += $tg46N; $btso3[17] += $tg7N; $btso3[18] += $eg1N; $btso3[19] += $eg2N; $btso3[20] += $eg3N; $btso3[21] += $eg4N; $btso3[22] += $hwN; $btso3[23] += $awN; $btso3[24] += $ftoddN; $btso3[25] += $ftevenN;
                    $gBTSO3[0]++; $gBTSO3[1] += $ov15; $gBTSO3[2] += $ov05; $gBTSO3[3] += $shgN; $gBTSO3[4] += $fhgN; $gBTSO3[5] += $u25N; $gBTSO3[6] += $o25N; $gBTSO3[7] += $u35N; $gBTSO3[8] += $bttsN; $gBTSO3[9] += $nbttsN; $gBTSO3[10] += $drawN; $gBTSO3[11] += $nodrawN; $gBTSO3[12] += $hg05N; $gBTSO3[13] += $ag05N; $gBTSO3[14] += $tg01N; $gBTSO3[15] += $tg23N; $gBTSO3[16] += $tg46N; $gBTSO3[17] += $tg7N; $gBTSO3[18] += $eg1N; $gBTSO3[19] += $eg2N; $gBTSO3[20] += $eg3N; $gBTSO3[21] += $eg4N; $gBTSO3[22] += $hwN; $gBTSO3[23] += $awN; $gBTSO3[24] += $ftoddN; $gBTSO3[25] += $ftevenN;
                }
                // Kalah (idx4) + selalu kebobolan (idx11=cleansheet → !idx11) 3x beruntun
                if ($i >= 3 && $arr[$i - 1][4] && !$arr[$i - 1][11] && $arr[$i - 2][4] && !$arr[$i - 2][11] && $arr[$i - 3][4] && !$arr[$i - 3][11]) {
                    $kncs3[0]++; $kncs3[1] += $ov15; $kncs3[2] += $ov05; $kncs3[3] += $shgN; $kncs3[4] += $fhgN; $kncs3[5] += $u25N; $kncs3[6] += $o25N; $kncs3[7] += $u35N; $kncs3[8] += $bttsN; $kncs3[9] += $nbttsN; $kncs3[10] += $drawN; $kncs3[11] += $nodrawN; $kncs3[12] += $hg05N; $kncs3[13] += $ag05N; $kncs3[14] += $tg01N; $kncs3[15] += $tg23N; $kncs3[16] += $tg46N; $kncs3[17] += $tg7N; $kncs3[18] += $eg1N; $kncs3[19] += $eg2N; $kncs3[20] += $eg3N; $kncs3[21] += $eg4N; $kncs3[22] += $hwN; $kncs3[23] += $awN; $kncs3[24] += $ftoddN; $kncs3[25] += $ftevenN;
                    $gKNCS3[0]++; $gKNCS3[1] += $ov15; $gKNCS3[2] += $ov05; $gKNCS3[3] += $shgN; $gKNCS3[4] += $fhgN; $gKNCS3[5] += $u25N; $gKNCS3[6] += $o25N; $gKNCS3[7] += $u35N; $gKNCS3[8] += $bttsN; $gKNCS3[9] += $nbttsN; $gKNCS3[10] += $drawN; $gKNCS3[11] += $nodrawN; $gKNCS3[12] += $hg05N; $gKNCS3[13] += $ag05N; $gKNCS3[14] += $tg01N; $gKNCS3[15] += $tg23N; $gKNCS3[16] += $tg46N; $gKNCS3[17] += $tg7N; $gKNCS3[18] += $eg1N; $gKNCS3[19] += $eg2N; $gKNCS3[20] += $eg3N; $gKNCS3[21] += $eg4N; $gKNCS3[22] += $hwN; $gKNCS3[23] += $awN; $gKNCS3[24] += $ftoddN; $gKNCS3[25] += $ftevenN;
                }
                // No FHG (babak1 0-0, !idx9) tapi Over 0.5 (idx3=U0.5 → !idx3) 3x → gol selalu di babak 2
                if ($i >= 3 && !$arr[$i - 1][9] && !$arr[$i - 1][3] && !$arr[$i - 2][9] && !$arr[$i - 2][3] && !$arr[$i - 3][9] && !$arr[$i - 3][3]) {
                    $nfo3[0]++; $nfo3[1] += $ov15; $nfo3[2] += $ov05; $nfo3[3] += $shgN; $nfo3[4] += $fhgN; $nfo3[5] += $u25N; $nfo3[6] += $o25N; $nfo3[7] += $u35N; $nfo3[8] += $bttsN; $nfo3[9] += $nbttsN; $nfo3[10] += $drawN; $nfo3[11] += $nodrawN; $nfo3[12] += $hg05N; $nfo3[13] += $ag05N; $nfo3[14] += $tg01N; $nfo3[15] += $tg23N; $nfo3[16] += $tg46N; $nfo3[17] += $tg7N; $nfo3[18] += $eg1N; $nfo3[19] += $eg2N; $nfo3[20] += $eg3N; $nfo3[21] += $eg4N; $nfo3[22] += $hwN; $nfo3[23] += $awN; $nfo3[24] += $ftoddN; $nfo3[25] += $ftevenN;
                    $gNFO3[0]++; $gNFO3[1] += $ov15; $gNFO3[2] += $ov05; $gNFO3[3] += $shgN; $gNFO3[4] += $fhgN; $gNFO3[5] += $u25N; $gNFO3[6] += $o25N; $gNFO3[7] += $u35N; $gNFO3[8] += $bttsN; $gNFO3[9] += $nbttsN; $gNFO3[10] += $drawN; $gNFO3[11] += $nodrawN; $gNFO3[12] += $hg05N; $gNFO3[13] += $ag05N; $gNFO3[14] += $tg01N; $gNFO3[15] += $tg23N; $gNFO3[16] += $tg46N; $gNFO3[17] += $tg7N; $gNFO3[18] += $eg1N; $gNFO3[19] += $eg2N; $gNFO3[20] += $eg3N; $gNFO3[21] += $eg4N; $gNFO3[22] += $hwN; $gNFO3[23] += $awN; $gNFO3[24] += $ftoddN; $gNFO3[25] += $ftevenN;
                }
                if ($i >= 3 && $arr[$i - 1][10] && $arr[$i - 2][10] && $arr[$i - 3][10]) { // No BTTS 3x (idx10)
                    $nb3[0]++; $nb3[1] += $ov15; $nb3[2] += $ov05; $nb3[3] += $shgN; $nb3[4] += $fhgN; $nb3[5] += $u25N; $nb3[6] += $o25N; $nb3[7] += $u35N; $nb3[8] += $bttsN; $nb3[9] += $nbttsN; $nb3[10] += $drawN; $nb3[11] += $nodrawN; $nb3[12] += $hg05N; $nb3[13] += $ag05N; $nb3[14] += $tg01N; $nb3[15] += $tg23N; $nb3[16] += $tg46N; $nb3[17] += $tg7N; $nb3[18] += $eg1N; $nb3[19] += $eg2N; $nb3[20] += $eg3N; $nb3[21] += $eg4N; $nb3[22] += $hwN; $nb3[23] += $awN; $nb3[24] += $ftoddN; $nb3[25] += $ftevenN;
                    $gNB3[0]++; $gNB3[1] += $ov15; $gNB3[2] += $ov05; $gNB3[3] += $shgN; $gNB3[4] += $fhgN; $gNB3[5] += $u25N; $gNB3[6] += $o25N; $gNB3[7] += $u35N; $gNB3[8] += $bttsN; $gNB3[9] += $nbttsN; $gNB3[10] += $drawN; $gNB3[11] += $nodrawN; $gNB3[12] += $hg05N; $gNB3[13] += $ag05N; $gNB3[14] += $tg01N; $gNB3[15] += $tg23N; $gNB3[16] += $tg46N; $gNB3[17] += $tg7N; $gNB3[18] += $eg1N; $gNB3[19] += $eg2N; $gNB3[20] += $eg3N; $gNB3[21] += $eg4N; $gNB3[22] += $hwN; $gNB3[23] += $awN; $gNB3[24] += $ftoddN; $gNB3[25] += $ftevenN;
                }
                if ($i >= 2 && !$arr[$i - 1][9] && !$arr[$i - 2][9]) { // No FHG 3x (babak1 0-0, idx9=fhg)
                    $nfhg2[0]++; $nfhg2[1] += $ov15; $nfhg2[2] += $ov05; $nfhg2[3] += $shgN; $nfhg2[4] += $fhgN; $nfhg2[5] += $u25N; $nfhg2[6] += $o25N; $nfhg2[7] += $u35N; $nfhg2[8] += $bttsN; $nfhg2[9] += $nbttsN; $nfhg2[10] += $drawN; $nfhg2[11] += $nodrawN; $nfhg2[12] += $hg05N; $nfhg2[13] += $ag05N; $nfhg2[14] += $tg01N; $nfhg2[15] += $tg23N; $nfhg2[16] += $tg46N; $nfhg2[17] += $tg7N; $nfhg2[18] += $eg1N; $nfhg2[19] += $eg2N; $nfhg2[20] += $eg3N; $nfhg2[21] += $eg4N; $nfhg2[22] += $hwN; $nfhg2[23] += $awN; $nfhg2[24] += $ftoddN; $nfhg2[25] += $ftevenN;
                    $gNFHG2[0]++; $gNFHG2[1] += $ov15; $gNFHG2[2] += $ov05; $gNFHG2[3] += $shgN; $gNFHG2[4] += $fhgN; $gNFHG2[5] += $u25N; $gNFHG2[6] += $o25N; $gNFHG2[7] += $u35N; $gNFHG2[8] += $bttsN; $gNFHG2[9] += $nbttsN; $gNFHG2[10] += $drawN; $gNFHG2[11] += $nodrawN; $gNFHG2[12] += $hg05N; $gNFHG2[13] += $ag05N; $gNFHG2[14] += $tg01N; $gNFHG2[15] += $tg23N; $gNFHG2[16] += $tg46N; $gNFHG2[17] += $tg7N; $gNFHG2[18] += $eg1N; $gNFHG2[19] += $eg2N; $gNFHG2[20] += $eg3N; $gNFHG2[21] += $eg4N; $gNFHG2[22] += $hwN; $gNFHG2[23] += $awN; $gNFHG2[24] += $ftoddN; $gNFHG2[25] += $ftevenN;
                }
                if ($i >= 2 && !$arr[$i - 1][8] && !$arr[$i - 2][8]) { // No SHG 3x (babak2 tanpa gol, idx8=shg)
                    $nshg2[0]++; $nshg2[1] += $ov15; $nshg2[2] += $ov05; $nshg2[3] += $shgN; $nshg2[4] += $fhgN; $nshg2[5] += $u25N; $nshg2[6] += $o25N; $nshg2[7] += $u35N; $nshg2[8] += $bttsN; $nshg2[9] += $nbttsN; $nshg2[10] += $drawN; $nshg2[11] += $nodrawN; $nshg2[12] += $hg05N; $nshg2[13] += $ag05N; $nshg2[14] += $tg01N; $nshg2[15] += $tg23N; $nshg2[16] += $tg46N; $nshg2[17] += $tg7N; $nshg2[18] += $eg1N; $nshg2[19] += $eg2N; $nshg2[20] += $eg3N; $nshg2[21] += $eg4N; $nshg2[22] += $hwN; $nshg2[23] += $awN; $nshg2[24] += $ftoddN; $nshg2[25] += $ftevenN;
                    $gNSHG2[0]++; $gNSHG2[1] += $ov15; $gNSHG2[2] += $ov05; $gNSHG2[3] += $shgN; $gNSHG2[4] += $fhgN; $gNSHG2[5] += $u25N; $gNSHG2[6] += $o25N; $gNSHG2[7] += $u35N; $gNSHG2[8] += $bttsN; $gNSHG2[9] += $nbttsN; $gNSHG2[10] += $drawN; $gNSHG2[11] += $nodrawN; $gNSHG2[12] += $hg05N; $gNSHG2[13] += $ag05N; $gNSHG2[14] += $tg01N; $gNSHG2[15] += $tg23N; $gNSHG2[16] += $tg46N; $gNSHG2[17] += $tg7N; $gNSHG2[18] += $eg1N; $gNSHG2[19] += $eg2N; $gNSHG2[20] += $eg3N; $gNSHG2[21] += $eg4N; $gNSHG2[22] += $hwN; $gNSHG2[23] += $awN; $gNSHG2[24] += $ftoddN; $gNSHG2[25] += $ftevenN;
                }
                if ($i >= 3 && !$arr[$i - 1][9] && !$arr[$i - 2][9] && !$arr[$i - 3][9]) { // No FHG 3x
                    $nfhg3[0]++; $nfhg3[1] += $ov15; $nfhg3[2] += $ov05; $nfhg3[3] += $shgN; $nfhg3[4] += $fhgN; $nfhg3[5] += $u25N; $nfhg3[6] += $o25N; $nfhg3[7] += $u35N; $nfhg3[8] += $bttsN; $nfhg3[9] += $nbttsN; $nfhg3[10] += $drawN; $nfhg3[11] += $nodrawN; $nfhg3[12] += $hg05N; $nfhg3[13] += $ag05N; $nfhg3[14] += $tg01N; $nfhg3[15] += $tg23N; $nfhg3[16] += $tg46N; $nfhg3[17] += $tg7N; $nfhg3[18] += $eg1N; $nfhg3[19] += $eg2N; $nfhg3[20] += $eg3N; $nfhg3[21] += $eg4N; $nfhg3[22] += $hwN; $nfhg3[23] += $awN; $nfhg3[24] += $ftoddN; $nfhg3[25] += $ftevenN;
                    $gNFHG3[0]++; $gNFHG3[1] += $ov15; $gNFHG3[2] += $ov05; $gNFHG3[3] += $shgN; $gNFHG3[4] += $fhgN; $gNFHG3[5] += $u25N; $gNFHG3[6] += $o25N; $gNFHG3[7] += $u35N; $gNFHG3[8] += $bttsN; $gNFHG3[9] += $nbttsN; $gNFHG3[10] += $drawN; $gNFHG3[11] += $nodrawN; $gNFHG3[12] += $hg05N; $gNFHG3[13] += $ag05N; $gNFHG3[14] += $tg01N; $gNFHG3[15] += $tg23N; $gNFHG3[16] += $tg46N; $gNFHG3[17] += $tg7N; $gNFHG3[18] += $eg1N; $gNFHG3[19] += $eg2N; $gNFHG3[20] += $eg3N; $gNFHG3[21] += $eg4N; $gNFHG3[22] += $hwN; $gNFHG3[23] += $awN; $gNFHG3[24] += $ftoddN; $gNFHG3[25] += $ftevenN;
                }
                if ($i >= 3 && !$arr[$i - 1][8] && !$arr[$i - 2][8] && !$arr[$i - 3][8]) { // No SHG 3x
                    $nshg3[0]++; $nshg3[1] += $ov15; $nshg3[2] += $ov05; $nshg3[3] += $shgN; $nshg3[4] += $fhgN; $nshg3[5] += $u25N; $nshg3[6] += $o25N; $nshg3[7] += $u35N; $nshg3[8] += $bttsN; $nshg3[9] += $nbttsN; $nshg3[10] += $drawN; $nshg3[11] += $nodrawN; $nshg3[12] += $hg05N; $nshg3[13] += $ag05N; $nshg3[14] += $tg01N; $nshg3[15] += $tg23N; $nshg3[16] += $tg46N; $nshg3[17] += $tg7N; $nshg3[18] += $eg1N; $nshg3[19] += $eg2N; $nshg3[20] += $eg3N; $nshg3[21] += $eg4N; $nshg3[22] += $hwN; $nshg3[23] += $awN; $nshg3[24] += $ftoddN; $nshg3[25] += $ftevenN;
                    $gNSHG3[0]++; $gNSHG3[1] += $ov15; $gNSHG3[2] += $ov05; $gNSHG3[3] += $shgN; $gNSHG3[4] += $fhgN; $gNSHG3[5] += $u25N; $gNSHG3[6] += $o25N; $gNSHG3[7] += $u35N; $gNSHG3[8] += $bttsN; $gNSHG3[9] += $nbttsN; $gNSHG3[10] += $drawN; $gNSHG3[11] += $nodrawN; $gNSHG3[12] += $hg05N; $gNSHG3[13] += $ag05N; $gNSHG3[14] += $tg01N; $gNSHG3[15] += $tg23N; $gNSHG3[16] += $tg46N; $gNSHG3[17] += $tg7N; $gNSHG3[18] += $eg1N; $gNSHG3[19] += $eg2N; $gNSHG3[20] += $eg3N; $gNSHG3[21] += $eg4N; $gNSHG3[22] += $hwN; $gNSHG3[23] += $awN; $gNSHG3[24] += $ftoddN; $gNSHG3[25] += $ftevenN;
                }
                if ($i >= 5) { // Odd/Even 5x beruntun + >=2 dari 4 match terakhir U1.5
                    $u15in4 = $arr[$i-1][1] + $arr[$i-2][1] + $arr[$i-3][1] + $arr[$i-4][1];
                    if ($u15in4 >= 2) {
                        if ($arr[$i-1][7] && $arr[$i-2][7] && $arr[$i-3][7] && $arr[$i-4][7] && $arr[$i-5][7]) { // Odd 5x
                            $od4u[0]++; $od4u[1] += $ov15; $od4u[2] += $ov05; $od4u[3] += $shgN; $od4u[4] += $fhgN; $od4u[5] += $u25N; $od4u[6] += $o25N; $od4u[7] += $u35N; $od4u[8] += $bttsN; $od4u[9] += $nbttsN; $od4u[10] += $drawN; $od4u[11] += $nodrawN; $od4u[12] += $hg05N; $od4u[13] += $ag05N; $od4u[14] += $tg01N; $od4u[15] += $tg23N; $od4u[16] += $tg46N; $od4u[17] += $tg7N; $od4u[18] += $eg1N; $od4u[19] += $eg2N; $od4u[20] += $eg3N; $od4u[21] += $eg4N; $od4u[22] += $hwN; $od4u[23] += $awN; $od4u[24] += $ftoddN; $od4u[25] += $ftevenN;
                            $gOD4U[0]++; $gOD4U[1] += $ov15; $gOD4U[2] += $ov05; $gOD4U[3] += $shgN; $gOD4U[4] += $fhgN; $gOD4U[5] += $u25N; $gOD4U[6] += $o25N; $gOD4U[7] += $u35N; $gOD4U[8] += $bttsN; $gOD4U[9] += $nbttsN; $gOD4U[10] += $drawN; $gOD4U[11] += $nodrawN; $gOD4U[12] += $hg05N; $gOD4U[13] += $ag05N; $gOD4U[14] += $tg01N; $gOD4U[15] += $tg23N; $gOD4U[16] += $tg46N; $gOD4U[17] += $tg7N; $gOD4U[18] += $eg1N; $gOD4U[19] += $eg2N; $gOD4U[20] += $eg3N; $gOD4U[21] += $eg4N; $gOD4U[22] += $hwN; $gOD4U[23] += $awN; $gOD4U[24] += $ftoddN; $gOD4U[25] += $ftevenN;
                        }
                        if (!$arr[$i-1][7] && !$arr[$i-2][7] && !$arr[$i-3][7] && !$arr[$i-4][7] && !$arr[$i-5][7]) { // Even 5x
                            $ev4u[0]++; $ev4u[1] += $ov15; $ev4u[2] += $ov05; $ev4u[3] += $shgN; $ev4u[4] += $fhgN; $ev4u[5] += $u25N; $ev4u[6] += $o25N; $ev4u[7] += $u35N; $ev4u[8] += $bttsN; $ev4u[9] += $nbttsN; $ev4u[10] += $drawN; $ev4u[11] += $nodrawN; $ev4u[12] += $hg05N; $ev4u[13] += $ag05N; $ev4u[14] += $tg01N; $ev4u[15] += $tg23N; $ev4u[16] += $tg46N; $ev4u[17] += $tg7N; $ev4u[18] += $eg1N; $ev4u[19] += $eg2N; $ev4u[20] += $eg3N; $ev4u[21] += $eg4N; $ev4u[22] += $hwN; $ev4u[23] += $awN; $ev4u[24] += $ftoddN; $ev4u[25] += $ftevenN;
                            $gEV4U[0]++; $gEV4U[1] += $ov15; $gEV4U[2] += $ov05; $gEV4U[3] += $shgN; $gEV4U[4] += $fhgN; $gEV4U[5] += $u25N; $gEV4U[6] += $o25N; $gEV4U[7] += $u35N; $gEV4U[8] += $bttsN; $gEV4U[9] += $nbttsN; $gEV4U[10] += $drawN; $gEV4U[11] += $nodrawN; $gEV4U[12] += $hg05N; $gEV4U[13] += $ag05N; $gEV4U[14] += $tg01N; $gEV4U[15] += $tg23N; $gEV4U[16] += $tg46N; $gEV4U[17] += $tg7N; $gEV4U[18] += $eg1N; $gEV4U[19] += $eg2N; $gEV4U[20] += $eg3N; $gEV4U[21] += $eg4N; $gEV4U[22] += $hwN; $gEV4U[23] += $awN; $gEV4U[24] += $ftoddN; $gEV4U[25] += $ftevenN;
                        }
                    }
                }
                if ($i >= 2 && !$arr[$i-1][10] && !$arr[$i-2][10]) { // BTTS 2x (!nbtts)
                    $bt2[0]++; $bt2[1] += $ov15; $bt2[2] += $ov05; $bt2[3] += $shgN; $bt2[4] += $fhgN; $bt2[5] += $u25N; $bt2[6] += $o25N; $bt2[7] += $u35N; $bt2[8] += $bttsN; $bt2[9] += $nbttsN; $bt2[10] += $drawN; $bt2[11] += $nodrawN; $bt2[12] += $hg05N; $bt2[13] += $ag05N; $bt2[14] += $tg01N; $bt2[15] += $tg23N; $bt2[16] += $tg46N; $bt2[17] += $tg7N; $bt2[18] += $eg1N; $bt2[19] += $eg2N; $bt2[20] += $eg3N; $bt2[21] += $eg4N; $bt2[22] += $hwN; $bt2[23] += $awN; $bt2[24] += $ftoddN; $bt2[25] += $ftevenN;
                    $gBT2[0]++; $gBT2[1] += $ov15; $gBT2[2] += $ov05; $gBT2[3] += $shgN; $gBT2[4] += $fhgN; $gBT2[5] += $u25N; $gBT2[6] += $o25N; $gBT2[7] += $u35N; $gBT2[8] += $bttsN; $gBT2[9] += $nbttsN; $gBT2[10] += $drawN; $gBT2[11] += $nodrawN; $gBT2[12] += $hg05N; $gBT2[13] += $ag05N; $gBT2[14] += $tg01N; $gBT2[15] += $tg23N; $gBT2[16] += $tg46N; $gBT2[17] += $tg7N; $gBT2[18] += $eg1N; $gBT2[19] += $eg2N; $gBT2[20] += $eg3N; $gBT2[21] += $eg4N; $gBT2[22] += $hwN; $gBT2[23] += $awN; $gBT2[24] += $ftoddN; $gBT2[25] += $ftevenN;
                }
                if ($i >= 3 && !$arr[$i-1][10] && !$arr[$i-2][10] && !$arr[$i-3][10]) { // BTTS 3x
                    $bt3[0]++; $bt3[1] += $ov15; $bt3[2] += $ov05; $bt3[3] += $shgN; $bt3[4] += $fhgN; $bt3[5] += $u25N; $bt3[6] += $o25N; $bt3[7] += $u35N; $bt3[8] += $bttsN; $bt3[9] += $nbttsN; $bt3[10] += $drawN; $bt3[11] += $nodrawN; $bt3[12] += $hg05N; $bt3[13] += $ag05N; $bt3[14] += $tg01N; $bt3[15] += $tg23N; $bt3[16] += $tg46N; $bt3[17] += $tg7N; $bt3[18] += $eg1N; $bt3[19] += $eg2N; $bt3[20] += $eg3N; $bt3[21] += $eg4N; $bt3[22] += $hwN; $bt3[23] += $awN; $bt3[24] += $ftoddN; $bt3[25] += $ftevenN;
                    $gBT3[0]++; $gBT3[1] += $ov15; $gBT3[2] += $ov05; $gBT3[3] += $shgN; $gBT3[4] += $fhgN; $gBT3[5] += $u25N; $gBT3[6] += $o25N; $gBT3[7] += $u35N; $gBT3[8] += $bttsN; $gBT3[9] += $nbttsN; $gBT3[10] += $drawN; $gBT3[11] += $nodrawN; $gBT3[12] += $hg05N; $gBT3[13] += $ag05N; $gBT3[14] += $tg01N; $gBT3[15] += $tg23N; $gBT3[16] += $tg46N; $gBT3[17] += $tg7N; $gBT3[18] += $eg1N; $gBT3[19] += $eg2N; $gBT3[20] += $eg3N; $gBT3[21] += $eg4N; $gBT3[22] += $hwN; $gBT3[23] += $awN; $gBT3[24] += $ftoddN; $gBT3[25] += $ftevenN;
                }
                if ($i >= 2 && $arr[$i-1][10] && $arr[$i-2][10]) { // NoBTTS 2x
                    $nb2[0]++; $nb2[1] += $ov15; $nb2[2] += $ov05; $nb2[3] += $shgN; $nb2[4] += $fhgN; $nb2[5] += $u25N; $nb2[6] += $o25N; $nb2[7] += $u35N; $nb2[8] += $bttsN; $nb2[9] += $nbttsN; $nb2[10] += $drawN; $nb2[11] += $nodrawN; $nb2[12] += $hg05N; $nb2[13] += $ag05N; $nb2[14] += $tg01N; $nb2[15] += $tg23N; $nb2[16] += $tg46N; $nb2[17] += $tg7N; $nb2[18] += $eg1N; $nb2[19] += $eg2N; $nb2[20] += $eg3N; $nb2[21] += $eg4N; $nb2[22] += $hwN; $nb2[23] += $awN; $nb2[24] += $ftoddN; $nb2[25] += $ftevenN;
                    $gNB2[0]++; $gNB2[1] += $ov15; $gNB2[2] += $ov05; $gNB2[3] += $shgN; $gNB2[4] += $fhgN; $gNB2[5] += $u25N; $gNB2[6] += $o25N; $gNB2[7] += $u35N; $gNB2[8] += $bttsN; $gNB2[9] += $nbttsN; $gNB2[10] += $drawN; $gNB2[11] += $nodrawN; $gNB2[12] += $hg05N; $gNB2[13] += $ag05N; $gNB2[14] += $tg01N; $gNB2[15] += $tg23N; $gNB2[16] += $tg46N; $gNB2[17] += $tg7N; $gNB2[18] += $eg1N; $gNB2[19] += $eg2N; $gNB2[20] += $eg3N; $gNB2[21] += $eg4N; $gNB2[22] += $hwN; $gNB2[23] += $awN; $gNB2[24] += $ftoddN; $gNB2[25] += $ftevenN;
                }
                if ($i >= 3 && $arr[$i-1][11] && $arr[$i-2][11] && $arr[$i-3][11]) { // Cleansheet 3x
                    $cs2[0]++; $cs2[1] += $ov15; $cs2[2] += $ov05; $cs2[3] += $shgN; $cs2[4] += $fhgN; $cs2[5] += $u25N; $cs2[6] += $o25N; $cs2[7] += $u35N; $cs2[8] += $bttsN; $cs2[9] += $nbttsN; $cs2[10] += $drawN; $cs2[11] += $nodrawN; $cs2[12] += $hg05N; $cs2[13] += $ag05N; $cs2[14] += $tg01N; $cs2[15] += $tg23N; $cs2[16] += $tg46N; $cs2[17] += $tg7N; $cs2[18] += $eg1N; $cs2[19] += $eg2N; $cs2[20] += $eg3N; $cs2[21] += $eg4N; $cs2[22] += $hwN; $cs2[23] += $awN; $cs2[24] += $ftoddN; $cs2[25] += $ftevenN;
                    $gCS2[0]++; $gCS2[1] += $ov15; $gCS2[2] += $ov05; $gCS2[3] += $shgN; $gCS2[4] += $fhgN; $gCS2[5] += $u25N; $gCS2[6] += $o25N; $gCS2[7] += $u35N; $gCS2[8] += $bttsN; $gCS2[9] += $nbttsN; $gCS2[10] += $drawN; $gCS2[11] += $nodrawN; $gCS2[12] += $hg05N; $gCS2[13] += $ag05N; $gCS2[14] += $tg01N; $gCS2[15] += $tg23N; $gCS2[16] += $tg46N; $gCS2[17] += $tg7N; $gCS2[18] += $eg1N; $gCS2[19] += $eg2N; $gCS2[20] += $eg3N; $gCS2[21] += $eg4N; $gCS2[22] += $hwN; $gCS2[23] += $awN; $gCS2[24] += $ftoddN; $gCS2[25] += $ftevenN;
                }
                if ($i >= 3 && $arr[$i-1][12] && $arr[$i-2][12] && $arr[$i-3][12]) { // Gagal cetak (FTS) 3x
                    $fts2[0]++; $fts2[1] += $ov15; $fts2[2] += $ov05; $fts2[3] += $shgN; $fts2[4] += $fhgN; $fts2[5] += $u25N; $fts2[6] += $o25N; $fts2[7] += $u35N; $fts2[8] += $bttsN; $fts2[9] += $nbttsN; $fts2[10] += $drawN; $fts2[11] += $nodrawN; $fts2[12] += $hg05N; $fts2[13] += $ag05N; $fts2[14] += $tg01N; $fts2[15] += $tg23N; $fts2[16] += $tg46N; $fts2[17] += $tg7N; $fts2[18] += $eg1N; $fts2[19] += $eg2N; $fts2[20] += $eg3N; $fts2[21] += $eg4N; $fts2[22] += $hwN; $fts2[23] += $awN; $fts2[24] += $ftoddN; $fts2[25] += $ftevenN;
                    $gFTS2[0]++; $gFTS2[1] += $ov15; $gFTS2[2] += $ov05; $gFTS2[3] += $shgN; $gFTS2[4] += $fhgN; $gFTS2[5] += $u25N; $gFTS2[6] += $o25N; $gFTS2[7] += $u35N; $gFTS2[8] += $bttsN; $gFTS2[9] += $nbttsN; $gFTS2[10] += $drawN; $gFTS2[11] += $nodrawN; $gFTS2[12] += $hg05N; $gFTS2[13] += $ag05N; $gFTS2[14] += $tg01N; $gFTS2[15] += $tg23N; $gFTS2[16] += $tg46N; $gFTS2[17] += $tg7N; $gFTS2[18] += $eg1N; $gFTS2[19] += $eg2N; $gFTS2[20] += $eg3N; $gFTS2[21] += $eg4N; $gFTS2[22] += $hwN; $gFTS2[23] += $awN; $gFTS2[24] += $ftoddN; $gFTS2[25] += $ftevenN;
                }
                if ($i >= 3 && $arr[$i-1][13] && $arr[$i-2][13] && $arr[$i-3][13]) { // HT-Odd 3x
                    $hto3[0]++; $hto3[1] += $ov15; $hto3[2] += $ov05; $hto3[3] += $shgN; $hto3[4] += $fhgN; $hto3[5] += $u25N; $hto3[6] += $o25N; $hto3[7] += $u35N; $hto3[8] += $bttsN; $hto3[9] += $nbttsN; $hto3[10] += $drawN; $hto3[11] += $nodrawN; $hto3[12] += $hg05N; $hto3[13] += $ag05N; $hto3[14] += $tg01N; $hto3[15] += $tg23N; $hto3[16] += $tg46N; $hto3[17] += $tg7N; $hto3[18] += $eg1N; $hto3[19] += $eg2N; $hto3[20] += $eg3N; $hto3[21] += $eg4N; $hto3[22] += $hwN; $hto3[23] += $awN; $hto3[24] += $ftoddN; $hto3[25] += $ftevenN;
                    $gHTO3[0]++; $gHTO3[1] += $ov15; $gHTO3[2] += $ov05; $gHTO3[3] += $shgN; $gHTO3[4] += $fhgN; $gHTO3[5] += $u25N; $gHTO3[6] += $o25N; $gHTO3[7] += $u35N; $gHTO3[8] += $bttsN; $gHTO3[9] += $nbttsN; $gHTO3[10] += $drawN; $gHTO3[11] += $nodrawN; $gHTO3[12] += $hg05N; $gHTO3[13] += $ag05N; $gHTO3[14] += $tg01N; $gHTO3[15] += $tg23N; $gHTO3[16] += $tg46N; $gHTO3[17] += $tg7N; $gHTO3[18] += $eg1N; $gHTO3[19] += $eg2N; $gHTO3[20] += $eg3N; $gHTO3[21] += $eg4N; $gHTO3[22] += $hwN; $gHTO3[23] += $awN; $gHTO3[24] += $ftoddN; $gHTO3[25] += $ftevenN;
                }
                if ($i >= 3 && $arr[$i-1][14] && $arr[$i-2][14] && $arr[$i-3][14]) { // HT-Even 3x
                    $hte3[0]++; $hte3[1] += $ov15; $hte3[2] += $ov05; $hte3[3] += $shgN; $hte3[4] += $fhgN; $hte3[5] += $u25N; $hte3[6] += $o25N; $hte3[7] += $u35N; $hte3[8] += $bttsN; $hte3[9] += $nbttsN; $hte3[10] += $drawN; $hte3[11] += $nodrawN; $hte3[12] += $hg05N; $hte3[13] += $ag05N; $hte3[14] += $tg01N; $hte3[15] += $tg23N; $hte3[16] += $tg46N; $hte3[17] += $tg7N; $hte3[18] += $eg1N; $hte3[19] += $eg2N; $hte3[20] += $eg3N; $hte3[21] += $eg4N; $hte3[22] += $hwN; $hte3[23] += $awN; $hte3[24] += $ftoddN; $hte3[25] += $ftevenN;
                    $gHTE3[0]++; $gHTE3[1] += $ov15; $gHTE3[2] += $ov05; $gHTE3[3] += $shgN; $gHTE3[4] += $fhgN; $gHTE3[5] += $u25N; $gHTE3[6] += $o25N; $gHTE3[7] += $u35N; $gHTE3[8] += $bttsN; $gHTE3[9] += $nbttsN; $gHTE3[10] += $drawN; $gHTE3[11] += $nodrawN; $gHTE3[12] += $hg05N; $gHTE3[13] += $ag05N; $gHTE3[14] += $tg01N; $gHTE3[15] += $tg23N; $gHTE3[16] += $tg46N; $gHTE3[17] += $tg7N; $gHTE3[18] += $eg1N; $gHTE3[19] += $eg2N; $gHTE3[20] += $eg3N; $gHTE3[21] += $eg4N; $gHTE3[22] += $hwN; $gHTE3[23] += $awN; $gHTE3[24] += $ftoddN; $gHTE3[25] += $ftevenN;
                }
                // Mode streak PANJANG: deteksi generik dari config $newModes.
                $nmOut = [1, $ov15, $ov05, $shgN, $fhgN, $u25N, $o25N, $u35N, $bttsN, $nbttsN, $drawN, $nodrawN, $hg05N, $ag05N, $tg01N, $tg23N, $tg46N, $tg7N, $eg1N, $eg2N, $eg3N, $eg4N, $hwN, $awN, $ftoddN, $ftevenN];
                foreach ($newModes as $nmKey => $nmConds) {
                    $ok = true;
                    foreach ($nmConds as [$nmIdx, $nmNeg, $nmLen]) {
                        if ($i < $nmLen) { $ok = false; break; }
                        for ($j = 1; $j <= $nmLen; $j++) {
                            $flag = (bool)$arr[$i - $j][$nmIdx];
                            if ($nmNeg) $flag = !$flag;
                            if (!$flag) { $ok = false; break; }
                        }
                        if (!$ok) break;
                    }
                    if (!$ok) continue;
                    for ($s = 0; $s < 26; $s++) { $nm[$nmKey][$s] += $nmOut[$s]; $gNM[$nmKey][$s] += $nmOut[$s]; }
                }
                // Backtest: bila match hasil (arr[i]) terjadi KEMARIN/HARI INI, catat
                // apakah tiap kondisi terpenuhi sebelum match itu + outcome-nya (angka mentah).
                $vRowDate = substr($arr[$i][0], 0, 10);
                if ($vRowDate === $vYest || $vRowDate === $vToday) {
                    $vDay = $vRowDate === $vToday ? 't' : 'y';
                    foreach ($verifyModes as $vKey => $vConds) {
                        $ok = true;
                        foreach ($vConds as [$vIdx, $vNeg, $vLen]) {
                            if ($i < $vLen) { $ok = false; break; }
                            for ($j = 1; $j <= $vLen; $j++) {
                                $flag = (bool)$arr[$i - $j][$vIdx];
                                if ($vNeg) $flag = !$flag;
                                if (!$flag) { $ok = false; break; }
                            }
                            if (!$ok) break;
                        }
                        if (!$ok) continue;
                        if (!isset($vAcc[$vDay][$vKey])) $vAcc[$vDay][$vKey] = array_fill(0, 26, 0);
                        for ($s = 0; $s < 26; $s++) { $vAcc[$vDay][$vKey][$s] += $nmOut[$s]; }
                    }
                }
            }
            // current streak U1.5 (dari paling akhir mundur)
            $cur = 0;
            for ($i = $n - 1; $i >= 0; $i--) { if ($arr[$i][1]) $cur++; else break; }
            // current streak KALAH
            for ($i = $n - 1; $i >= 0; $i--) { if ($arr[$i][4]) $curLose++; else break; }
            // current streak U0.5 (0-0)
            $curU05 = 0;
            for ($i = $n - 1; $i >= 0; $i--) { if ($arr[$i][3]) $curU05++; else break; }
            // current streak MENANG / DRAW
            $curWin = 0; for ($i = $n - 1; $i >= 0; $i--) { if ($arr[$i][5]) $curWin++; else break; }
            $curDraw = 0; for ($i = $n - 1; $i >= 0; $i--) { if ($arr[$i][6]) $curDraw++; else break; }
            // current streak ODD / EVEN
            $curOdd = 0; for ($i = $n - 1; $i >= 0; $i--) { if ($arr[$i][7]) $curOdd++; else break; }
            $curEven = 0; for ($i = $n - 1; $i >= 0; $i--) { if (!$arr[$i][7]) $curEven++; else break; }
            // current streak momentum: Over1.5 (!u15), Over2.5 (idx2), No BTTS (idx10)
            $curO15 = 0; for ($i = $n - 1; $i >= 0; $i--) { if (!$arr[$i][1]) $curO15++; else break; }
            $curO25 = 0; for ($i = $n - 1; $i >= 0; $i--) { if ($arr[$i][2]) $curO25++; else break; }
            $curNB  = 0; for ($i = $n - 1; $i >= 0; $i--) { if ($arr[$i][10]) $curNB++; else break; }
            $curNFHG = 0; for ($i = $n - 1; $i >= 0; $i--) { if (!$arr[$i][9]) $curNFHG++; else break; }
            $curNSHG = 0; for ($i = $n - 1; $i >= 0; $i--) { if (!$arr[$i][8]) $curNSHG++; else break; }
            $curSHG = 0; for ($i = $n - 1; $i >= 0; $i--) { if ($arr[$i][8]) $curSHG++; else break; }
            $curFHG = 0; for ($i = $n - 1; $i >= 0; $i--) { if ($arr[$i][9]) $curFHG++; else break; }
            $curU35 = 0; for ($i = $n - 1; $i >= 0; $i--) { if ($arr[$i][15]) $curU35++; else break; }
            $curBTTS = 0; for ($i = $n - 1; $i >= 0; $i--) { if (!$arr[$i][10]) $curBTTS++; else break; }
            $curCS = 0; for ($i = $n - 1; $i >= 0; $i--) { if ($arr[$i][11]) $curCS++; else break; }
            $curFTS = 0; for ($i = $n - 1; $i >= 0; $i--) { if ($arr[$i][12]) $curFTS++; else break; }
            $curHTO = 0; for ($i = $n - 1; $i >= 0; $i--) { if ($arr[$i][13]) $curHTO++; else break; }
            $curHTE = 0; for ($i = $n - 1; $i >= 0; $i--) { if ($arr[$i][14]) $curHTE++; else break; }
            // jumlah pertandingan HARI INI (sudah selesai) yang berakhir Under 1.5
            $todayStr = date('Y-m-d');
            $tu15 = 0;
            for ($i = 0; $i < $n; $i++) { if ($arr[$i][1] && substr($arr[$i][0], 0, 10) === $todayStr) $tu15++; }

            if ($n < 150 || $a[2][0] < 10) continue; // sampel minimal
            $base = $u15tot / $n;
            $pct = fn($num, $den) => $den > 0 ? round($num / $den * 100, 1) : null;
            // next match (jadwal terdekat belum main)
            $nx = $nextMatch[$key] ?? null;
            $nextStr = null;
            $oppOver = null;
            if ($nx) {
                $ts = strtotime($nx['dt']);
                // Tampilan dikurangi 1 jam (hanya di view, data CSV tetap)
                $viewTs = $ts ? $ts - 3600 : null;
                $nextStr = 'vs ' . $nx['vs'] . ($viewTs ? ' · ' . date('d/m H:i', $viewTs) : '');
                $nextMin = $viewTs ? ((int) date('H', $viewTs) * 60 + (int) date('i', $viewTs)) : null;
                $oppOver = $overRateMap[$nx['vs'] . '|' . $lg] ?? null; // rate Over1.5 lawan
            }
            // tiap mode → [over15%, over05%, sampel]
            $mk = fn($acc) => [$pct($acc[1], $acc[0]), $pct($acc[2], $acc[0]), $acc[0], $pct($acc[3], $acc[0]), $pct($acc[4], $acc[0]), $pct($acc[5], $acc[0]), $pct($acc[6], $acc[0]), $pct($acc[7], $acc[0]), $pct($acc[8], $acc[0]), $pct($acc[9], $acc[0]), $pct($acc[10], $acc[0]), $pct($acc[11], $acc[0]), $pct($acc[12] ?? 0, $acc[0]), $pct($acc[13] ?? 0, $acc[0]), $pct($acc[14] ?? 0, $acc[0]), $pct($acc[15] ?? 0, $acc[0]), $pct($acc[16] ?? 0, $acc[0]), $pct($acc[17] ?? 0, $acc[0]), $pct($acc[18] ?? 0, $acc[0]), $pct($acc[19] ?? 0, $acc[0]), $pct($acc[20] ?? 0, $acc[0]), $pct($acc[21] ?? 0, $acc[0]), $pct($acc[22] ?? 0, $acc[0]), $pct($acc[23] ?? 0, $acc[0]), $pct($acc[24] ?? 0, $acc[0]), $pct($acc[25] ?? 0, $acc[0])];
            $rows[] = [
                't'    => $tm,
                'l'    => $lg,
                'n'    => $n,
                'next' => $nextStr,
                'nextMin' => $nextMin,
                'base' => round($base * 100, 1),
                'lift2'=> $base > 0 && $a[2][0] > 0 ? round((($a[2][0] - $a[2][1]) / $a[2][0]) / $base, 2) : null,
                'm'    => [
                    '1'    => $mk($a[1]), '2' => $mk($a[2]), '3' => $mk($a[3]), '4' => $mk($a[4]),
                    '05_1' => $mk($b[1]), '05_2' => $mk($b[2]), '05_3' => $mk($b[3]),
                    'kl_1' => $mk($c[1]), 'kl_2' => $mk($c[2]), 'kl_3' => $mk($c[3]),
                    'mn_2' => $mk($w2), 'dr_2' => $mk($dr2), 'od_4' => $mk($od4), 'ev_4' => $mk($ev4), 'od_5' => $mk($od5), 'ev_5' => $mk($ev5),
                    'mn_3' => $mk($w3), 'dr_3' => $mk($dr3),
                    'c_mn3op80' => $mk($w3o80), 'c_mn3op80h' => $mk($w3o80h),
                    'c_both80' => $mk($both80), 'c_both85' => $mk($both85),
                    'c_b2560' => $mk($b2560), 'c_b2565' => $mk($b2565),
                    'o25s2' => $mk($o25s2), 'o15s3' => $mk($o15s3), 'o25s3' => $mk($o25s3), 'nbtts3' => $mk($nb3),
                    'nfhg2' => $mk($nfhg2), 'nshg2' => $mk($nshg2),
                    'nfhg3' => $mk($nfhg3), 'nshg3' => $mk($nshg3),
                    'od4u' => $mk($od4u), 'ev4u' => $mk($ev4u), 'u15oe3' => $mk($u15oe3),
                    'dry2o' => $mk($dry2o), 'btso3' => $mk($btso3), 'kncs3' => $mk($kncs3), 'nfo3' => $mk($nfo3),
                    'btts2' => $mk($bt2), 'btts3' => $mk($bt3), 'nbtts2' => $mk($nb2),
                    'cs2' => $mk($cs2), 'fts2' => $mk($fts2), 'htodd3' => $mk($hto3), 'hteven3' => $mk($hte3),
                ] + array_map($mk, $nm),
                'cur' => $cur, 'curU' => $curU05, 'curL' => $curLose,
                'curW' => $curWin, 'curD' => $curDraw, 'curO' => $curOdd, 'curE' => $curEven,
                'curO15' => $curO15, 'curO25' => $curO25, 'curNB' => $curNB,
                'curNFHG' => $curNFHG, 'curNSHG' => $curNSHG,
                'curSHG' => $curSHG, 'curFHG' => $curFHG, 'curU35' => $curU35,
                'curBTTS' => $curBTTS, 'curCS' => $curCS, 'curFTS' => $curFTS, 'curHTO' => $curHTO, 'curHTE' => $curHTE,
                'tu15' => $tu15, // pertandingan U1.5 hari ini (utk syarat mode "2")
                'oppOver' => $oppOver, // % Over 1.5 lawan di next match
                'nextHome' => $nx ? ($nx['home'] ?? null) : null, // 1 bila tim main kandang di next match
                'selfO25' => $over25Map[$key] ?? null, // rate Over2.5 musim tim ini
                'oppO25' => $nx ? ($over25Map[$nx['vs'] . '|' . $lg] ?? null) : null, // rate Over2.5 lawan next match
            ];
        }
    }

    $leagues = array_keys($leagueSet); sort($leagues);
    // global per mode → [over15%, over05%, sampel]
    $gpct = fn($num, $den) => $den > 0 ? round($num / $den * 100, 1) : 0;
    $gmk = fn($acc) => [$gpct($acc[1], $acc[0]), $gpct($acc[2], $acc[0]), $acc[0], $gpct($acc[3], $acc[0]), $gpct($acc[4], $acc[0]), $gpct($acc[5], $acc[0]), $gpct($acc[6], $acc[0]), $gpct($acc[7], $acc[0]), $gpct($acc[8], $acc[0]), $gpct($acc[9], $acc[0]), $gpct($acc[10], $acc[0]), $gpct($acc[11], $acc[0]), $gpct($acc[12] ?? 0, $acc[0]), $gpct($acc[13] ?? 0, $acc[0]), $gpct($acc[14] ?? 0, $acc[0]), $gpct($acc[15] ?? 0, $acc[0]), $gpct($acc[16] ?? 0, $acc[0]), $gpct($acc[17] ?? 0, $acc[0]), $gpct($acc[18] ?? 0, $acc[0]), $gpct($acc[19] ?? 0, $acc[0]), $gpct($acc[20] ?? 0, $acc[0]), $gpct($acc[21] ?? 0, $acc[0]), $gpct($acc[22] ?? 0, $acc[0]), $gpct($acc[23] ?? 0, $acc[0]), $gpct($acc[24] ?? 0, $acc[0]), $gpct($acc[25] ?? 0, $acc[0])];
    $payload = [
        'rows'      => $rows,
        'leagues'   => $leagues,
        'baseU15'   => $gN ? round($gU15 / $gN * 100, 1) : 0,
        'baseO15'   => $gN ? round((1 - $gU15 / $gN) * 100, 1) : 0,
        'baseO05'   => $gN ? round((1 - $gU05 / $gN) * 100, 1) : 0,
        'baseO25'   => $gN ? round($gO25 / $gN * 100, 1) : 0,
        // Baseline tiap outcome (rata-rata SEMUA match, tanpa syarat streak) — untuk kolom lift.
        'baseOut'   => $gN ? [
            'o15' => round((1 - $gU15 / $gN) * 100, 1), 'u15' => round($gU15 / $gN * 100, 1),
            'o05' => round((1 - $gU05 / $gN) * 100, 1), 'u05' => round($gU05 / $gN * 100, 1),
            'o25' => round($gO25 / $gN * 100, 1), 'u25' => round((1 - $gO25 / $gN) * 100, 1),
            'u35' => round($gU35 / $gN * 100, 1), 'o35' => round((1 - $gU35 / $gN) * 100, 1),
            'shg' => round($gSHG / $gN * 100, 1), 'fhg' => round($gFHG / $gN * 100, 1),
            'btts' => round((1 - $gNBTTS / $gN) * 100, 1), 'nbtts' => round($gNBTTS / $gN * 100, 1),
            'draw' => round($gDRAW / $gN * 100, 1), 'nodraw' => round((1 - $gDRAW / $gN) * 100, 1),
            'hg05' => round($gHG / $gN * 100, 1), 'ag05' => round($gAG / $gN * 100, 1),
            'tg01' => round($gTG01 / $gN * 100, 1), 'tg23' => round($gTG23 / $gN * 100, 1),
            'tg46' => round($gTG46 / $gN * 100, 1), 'tg7' => round($gTG7 / $gN * 100, 1),
            'eg1' => round($gEG1 / $gN * 100, 1), 'eg2' => round($gEG2 / $gN * 100, 1),
            'eg3' => round($gEG3 / $gN * 100, 1), 'eg4' => round($gEG4 / $gN * 100, 1),
            'hw' => round($gHW / $gN * 100, 1), 'aw' => round($gAW / $gN * 100, 1),
            'ftodd' => round($gODD / $gN * 100, 1), 'fteven' => round((1 - $gODD / $gN) * 100, 1),
            'dc1x' => round(($gHW + $gDRAW) / $gN * 100, 1), 'dcx2' => round(($gAW + $gDRAW) / $gN * 100, 1),
        ] : [],
        'verify'    => ['y' => $vAcc['y'], 't' => $vAcc['t'], 'ydate' => $vYest, 'tdate' => $vToday],
        'global'    => [
            '1' => $gmk($gA[1]), '2' => $gmk($gA[2]), '3' => $gmk($gA[3]), '4' => $gmk($gA[4]),
            '05_1' => $gmk($gB[1]), '05_2' => $gmk($gB[2]), '05_3' => $gmk($gB[3]),
            'kl_1' => $gmk($gC[1]), 'kl_2' => $gmk($gC[2]), 'kl_3' => $gmk($gC[3]),
            'mn_2' => $gmk($gW2), 'dr_2' => $gmk($gDR2), 'od_4' => $gmk($gOD4), 'ev_4' => $gmk($gEV4), 'od_5' => $gmk($gOD5), 'ev_5' => $gmk($gEV5),
            'mn_3' => $gmk($gW3), 'dr_3' => $gmk($gDR3),
            'c_mn3op80' => $gmk($gW3O80), 'c_mn3op80h' => $gmk($gW3O80H),
            'c_both80' => $gmk($gBOTH80), 'c_both85' => $gmk($gBOTH85),
            'c_b2560' => $gmk($gB2560), 'c_b2565' => $gmk($gB2565),
            'o25s2' => $gmk($gO25S2), 'o15s3' => $gmk($gO15S3), 'o25s3' => $gmk($gO25S3), 'nbtts3' => $gmk($gNB3),
            'nfhg2' => $gmk($gNFHG2), 'nshg2' => $gmk($gNSHG2),
            'nfhg3' => $gmk($gNFHG3), 'nshg3' => $gmk($gNSHG3),
            'od4u' => $gmk($gOD4U), 'ev4u' => $gmk($gEV4U), 'u15oe3' => $gmk($gU15OE3),
            'dry2o' => $gmk($gDRY2O), 'btso3' => $gmk($gBTSO3), 'kncs3' => $gmk($gKNCS3), 'nfo3' => $gmk($gNFO3),
            'btts2' => $gmk($gBT2), 'btts3' => $gmk($gBT3), 'nbtts2' => $gmk($gNB2),
            'cs2' => $gmk($gCS2), 'fts2' => $gmk($gFTS2), 'htodd3' => $gmk($gHTO3), 'hteven3' => $gmk($gHTE3),
        ] + array_map($gmk, $gNM),
        'matches'   => $gN,
        'csvError'  => $csvError,
        'builtAt'   => date('Y-m-d H:i'),
    ];
    if ($csvError === null) {
        @file_put_contents($cacheFile, serialize($payload), LOCK_EX);
    }
}

$rows      = $payload['rows'];
$leagues   = $payload['leagues'];
$baseU15   = $payload['baseU15'];
$baseO15   = $payload['baseO15'];
$baseO25   = $payload['baseO25'];
$baseO05   = $payload['baseO05'] ?? 0;
$gl        = $payload['global'];
$csvError  = $payload['csvError'];
$rowsJson  = json_encode($rows, JSON_UNESCAPED_UNICODE);
?>
<style>
    .streak-filter-panel {
        display: grid;
        grid-template-columns: minmax(240px, 1.5fr) minmax(200px, 1fr) minmax(120px, .65fr) minmax(120px, .65fr);
        gap: 14px 12px;
        align-items: end;
        position: relative;
        padding: 16px 16px 18px;
        border: 1px solid rgba(148, 163, 184, .28);
        border-radius: 16px;
        background: linear-gradient(180deg, #fff 0%, #f8fbff 100%);
        box-shadow: 0 10px 28px rgba(15, 23, 42, .06);
    }
    .streak-filter-panel .streak-field {
        display: flex;
        min-width: 0;
        flex-direction: column;
        gap: 6px;
    }
    .streak-filter-panel .streak-field:first-child { grid-column: span 2; }
    .streak-filter-panel .streak-field > span,
    .streak-filter-panel > label > span:first-child {
        color: #64748b !important;
        font-size: 10px !important;
        font-weight: 800 !important;
        letter-spacing: 0 !important;
        line-height: 1.2;
    }
    .streak-filter-panel select,
    .streak-filter-panel input[type="search"],
    .streak-filter-panel input[type="number"],
    .streak-filter-panel input[type="time"] {
        width: 100%;
        min-width: 0;
        height: 42px;
        border-color: #cbd5e1 !important;
        border-radius: 10px !important;
        background: #fff;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .8);
    }
    .streak-filter-panel select {
        padding-top: 0 !important;
        padding-bottom: 2px !important;
        display: block;
        line-height: 42px !important;
    }
    .streak-filter-panel select:focus,
    .streak-filter-panel input:focus {
        border-color: #2563eb !important;
        outline: none;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, .14);
    }
    .streak-filter-panel #stkMode { min-width: 0; }
    .streak-filter-panel #stkLeague { grid-column: span 2; }
    .streak-filter-panel .streak-check {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 38px;
        padding: 8px 10px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #fff;
        color: #475569;
        font-size: 13px;
        font-weight: 650;
        cursor: pointer;
        user-select: none;
    }
    .streak-filter-panel .streak-check input { flex: 0 0 auto; }
    @media (max-width: 1200px) {
        .streak-filter-panel { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .streak-filter-panel .streak-field:first-child,
        .streak-filter-panel .streak-field:nth-child(2),
        .streak-filter-panel #stkLeague { grid-column: span 2; }
    }
    @media (max-width: 760px) {
        .streak-filter-panel { grid-template-columns: 1fr; padding: 12px; }
        .streak-filter-panel .streak-field:first-child,
        .streak-filter-panel .streak-field:nth-child(2),
        .streak-filter-panel #stkLeague { grid-column: auto; }

    }
</style>
<div class="p-4 sm:p-6 lg:p-8 space-y-6">
    <?php if ($csvError): ?>
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-700">
            <?= htmlspecialchars($csvError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php else: ?>

    <header class="space-y-1">
        <h2 class="text-2xl font-extrabold text-slate-900">Streak Under 1.5 → Over 1.5</h2>
        <p class="text-sm text-slate-500">
            Pilih: tim yang sudah Under 1.5 berapa kali beruntun, lalu lihat peluang match berikutnya
            <b>Over 1.5</b>. Data <?= number_format($payload['matches']) ?> match.
        </p>
    </header>

    <!-- Tabel peluang 100% (semua market & semua hasil) -->
    <div class="rounded-2xl border border-amber-300 bg-amber-50/40 p-4 space-y-3">
        <div class="flex flex-wrap items-center gap-3">
            <h3 class="text-lg font-extrabold text-amber-700">🎯 Peluang 100% / meleset maks 3x — semua pilihan</h3>
            <span class="text-xs text-slate-500">Otomatis dari semua market & hasil, tanpa buka dropdown.</span>
            <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer select-none ml-auto">
                <input id="stk100Cur" type="checkbox" checked class="w-4 h-4 rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                Streak skrg cukup
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer select-none">
                <input id="stk100Next" type="checkbox" checked class="w-4 h-4 rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                Ada Next Match
            </label>
            <span id="stk100Count" class="text-sm font-semibold text-slate-500"></span>
        </div>
        <div class="overflow-x-auto rounded-xl border border-amber-200 bg-white">
            <table class="w-full text-sm" id="stk100Table">
                <thead class="bg-amber-100/60 text-slate-600">
                    <tr class="text-left">
                        <th data-k="t"    class="px-4 py-3 font-bold cursor-pointer whitespace-nowrap">Tim</th>
                        <th data-k="mk"   class="px-4 py-3 font-bold cursor-pointer whitespace-nowrap">Market</th>
                        <th data-k="outT" class="px-4 py-3 font-bold cursor-pointer whitespace-nowrap">Hasil</th>
                        <th data-k="l"    class="px-4 py-3 font-bold cursor-pointer whitespace-nowrap">Liga</th>
                        <th data-k="over" class="px-4 py-3 font-bold cursor-pointer text-center whitespace-nowrap">Peluang</th>
                        <th data-k="samp" class="px-4 py-3 font-bold cursor-pointer text-center whitespace-nowrap" title="Menang / total sampel.">Menang/Total</th>
                        <th data-k="lb"   class="px-4 py-3 font-bold cursor-pointer text-center whitespace-nowrap" title="Wilson lower-bound 95%. Makin tinggi makin andal.">Keandalan</th>
                        <th data-k="cur"  class="px-4 py-3 font-bold cursor-pointer text-center whitespace-nowrap">Streak skrg</th>
                    </tr>
                </thead>
                <tbody id="stk100Body"></tbody>
            </table>
        </div>
        <p class="text-[11px] text-slate-400">Syarat tampil: peluang 100% (meleset 0x) tampil tanpa batas sampel; meleset 1x butuh sampel > 100; meleset 2x butuh > 200; meleset 3x butuh > 300. Urutkan kolom "Keandalan" untuk memilah. Ikut filter "Cari tim/liga" & "liga" di bawah.</p>
    </div>

    <!-- Pilihan utama -->
    <div class="streak-filter-panel">
        <label class="streak-field">
            <span class="text-[11px] uppercase tracking-wide text-slate-400 font-bold">Pilih kondisi</span>
            <select id="stkMode" class="px-4 py-3 rounded-xl border border-slate-300 text-sm font-semibold bg-white min-w-[240px]">
                <option value="ALL" selected>★ Semua market (1 tabel)</option>
                <option value="05_4">Under 0.5 4x beruntun</option>
                <option value="05_3">Under 0.5 3x beruntun</option>
                <option value="kl_2">Kalah 2x beruntun</option>
                <option value="kl_3">Kalah 3x beruntun</option>
                <option value="mn_2">Menang 2x beruntun</option>
                <option value="dr_3">Draw 3x beruntun</option>
                <option value="dr_4">Draw 4x beruntun</option>
                <option value="od_4">Odd 4x beruntun</option>
                <option value="od_5">Odd 5x beruntun</option>
                <option value="ev_4">Even 4x beruntun</option>
                <option value="ev_5">Even 5x beruntun</option>
                <option value="o15s3">Over 1.5 3x beruntun (momentum)</option>
                <option value="o25s3">Over 2.5 3x beruntun (momentum)</option>
                <option value="nbtts3">No BTTS 3x beruntun</option>
                <option value="nfhg3">No FHG 3x beruntun (babak 1 tanpa gol)</option>
                <option value="nshg3">No SHG 3x beruntun (babak 2 tanpa gol)</option>
                <option value="od4u">Odd 5x + ≥2 dari 4 U1.5</option>
                <option value="ev4u">Even 5x + ≥2 dari 4 U1.5</option>
                <option value="u15oe3">U1.5 3x + 1 Odd + 2 Even (total skor)</option>
                <option value="dry2o">Kering total 3x (No FHG+No SHG+U1.5) → rebound</option>
                <option value="btso3">BTTS + Over 1.5 3x (serangan stabil)</option>
                <option value="kncs3">Kalah + selalu kebobolan 3x</option>
                <option value="nfo3">No FHG tapi Over 0.5 3x (gol babak 2)</option>
                <option value="btts3">BTTS 3x beruntun</option>
                <option value="cs2">Cleansheet 3x beruntun (kebobolan 0)</option>
                <option value="fts2">Gagal cetak gol 3x beruntun</option>
                <option value="htodd3">HT Odd 3x beruntun (total babak 1 ganjil)</option>
                <option value="hteven3">HT Even 3x beruntun (total babak 1 genap)</option>
                <option value="shg3">SHG 3x beruntun (selalu ada gol babak 2)</option>
                <option value="fhg3">FHG 3x beruntun (selalu ada gol babak 1)</option>
                <option value="u35_4">Under 3.5 4x beruntun</option>
                <optgroup label="— Streak Panjang —">
                    <option value="u15_5">Under 1.5 5x beruntun</option>
                    <option value="u15_6">Under 1.5 6x beruntun</option>
                    <option value="kl_4">Kalah 4x beruntun</option>
                    <option value="kl_5">Kalah 5x beruntun</option>
                    <option value="mn_4">Menang 4x beruntun</option>
                    <option value="mn_5">Menang 5x beruntun</option>
                    <option value="o15s4">Over 1.5 4x beruntun (momentum)</option>
                    <option value="o15s5">Over 1.5 5x beruntun (momentum)</option>
                    <option value="o25s4">Over 2.5 4x beruntun (momentum)</option>
                    <option value="o25s5">Over 2.5 5x beruntun (momentum)</option>
                    <option value="btts4">BTTS 4x beruntun</option>
                    <option value="nbtts4">No BTTS 4x beruntun</option>
                    <option value="od_6">Odd 6x beruntun</option>
                    <option value="ev_6">Even 6x beruntun</option>
                </optgroup>
                <optgroup label="— Kombinasi —">
                    <option value="c_u15kl">U1.5 3x + Kalah 2x</option>
                    <option value="c_u15nf">U1.5 3x + No FHG 3x</option>
                    <option value="c_klfts">Kalah 2x + Gagal cetak 3x</option>
                    <option value="c_mno25">Menang 2x + Over 2.5 2x</option>
                    <option value="c_mnbt">Menang 2x + BTTS 2x</option>
                    <option value="c_o15bt">Over 1.5 3x + BTTS 2x</option>
                    <option value="c_u05ns">U0.5 2x + No SHG 3x</option>
                    <option value="c_dru15">Draw 3x + U1.5 3x</option>
                    <option value="c_evu15">Even 4x + U1.5 3x</option>
                    <option value="c_odo15">Odd 4x + Over 1.5 3x</option>
                    <option value="c_csmn">Cleansheet 3x + Menang 2x</option>
                    <option value="c_ftsnf">Gagal cetak 3x + No FHG 3x</option>
                    <option value="c_klnb">Kalah 2x + No BTTS 3x (kalah tanpa balas)</option>
                    <option value="c_mnnf">Menang 2x + No FHG 3x (menang lewat babak 2)</option>
                    <option value="c_drnb">Draw 3x + No BTTS 3x (seri kering 0-0)</option>
                    <option value="c_dro25">Draw 3x + Over 2.5 2x (seri rame gol)</option>
                    <option value="c_u15ns">U1.5 3x + No SHG 3x (babak 2 mandek)</option>
                    <option value="c_o25bt3">Over 2.5 3x + BTTS 3x (saling serang panjang)</option>
                    <option value="c_csu15">Cleansheet 3x + U1.5 3x (gembok ganda)</option>
                    <option value="c_kl3fts">Kalah 3x + Gagal cetak 3x (terpuruk dalam)</option>
                    <option value="c_htou15">HT Odd 3x + U1.5 3x</option>
                    <option value="c_hteo15">HT Even 3x + Over 1.5 3x</option>
                    <option value="c_klo25">Kalah 2x + Over 2.5 2x (kalah di laga rame)</option>
                    <option value="c_mnu15">Menang 2x + U1.5 3x (menang tipis)</option>
                    <option value="c_csnf">Cleansheet 3x + No FHG 3x (gembok babak 1)</option>
                    <option value="c_kl3nb">Kalah 3x + No BTTS 3x (kalah tanpa balas panjang)</option>
                    <option value="c_mn3o25">Menang 3x + Over 2.5 3x (mesin gol panjang)</option>
                    <option value="c_mn3op80">Menang 3x + Lawan Over 1.5 ≥80% (lawan subur)</option>
                    <option value="c_mn3op80h">Menang 3x + Lawan Over 1.5 ≥80% + Kandang</option>
                    <option value="c_both80">Kedua tim Over 1.5 ≥80% (duel subur) — tanpa streak</option>
                    <option value="c_both85">Kedua tim Over 1.5 ≥85% (duel super subur) — tanpa streak</option>
                    <option value="c_b2560">Kedua tim Over 2.5 ≥60% (untuk pasang Over 2.5)</option>
                    <option value="c_b2565">Kedua tim Over 2.5 ≥65% (untuk pasang Over 2.5)</option>
                    <option value="c_odbt">Odd 4x + BTTS 2x (ganjil saling serang)</option>
                    <option value="c_evnb">Even 4x + No BTTS 3x (genap & kering)</option>
                    <option value="c_dr3u15">Draw 3x + U1.5 3x (seri minim gol)</option>
                    <option value="c_nfns">No FHG 3x + No SHG 3x (dua babak seret)</option>
                </optgroup>
                <optgroup label="Kombinasi U1.5 3x/4x">
                    <option value="c_u15fts">U1.5 3x + Gagal cetak 3x</option>
                    <option value="c_u15nb">U1.5 3x + No BTTS 3x</option>
                    <option value="c_u15u35">U1.5 3x + Under 3.5 4x</option>
                    <option value="c_u154kl">U1.5 4x + Kalah 2x</option>
                    <option value="c_u154nf">U1.5 4x + No FHG 3x</option>
                    <option value="c_u154ns">U1.5 4x + No SHG 3x</option>
                    <option value="c_u154nb">U1.5 4x + No BTTS 3x</option>
                    <option value="c_u154fts">U1.5 4x + Gagal cetak 3x</option>
                    <option value="c_u154cs">U1.5 4x + Cleansheet 3x</option>
                    <option value="c3_u154klnf">U1.5 4x + Kalah 2x + No FHG 3x</option>
                    <option value="c3_u154nbns">U1.5 4x + No BTTS 3x + No SHG 3x</option>
                    <option value="c3_u154csnf">U1.5 4x + Cleansheet 3x + No FHG 3x</option>
                    <option value="c4_u154gembok">U1.5 4x + Cleansheet + No FHG + No BTTS</option>
                    <option value="c4_u154krisis">U1.5 4x + Kalah + Gagal cetak + No SHG</option>
                </optgroup>
                <optgroup label="— Kombinasi 3 Kondisi —">
                    <option value="c3_u15klnf">U1.5 3x + Kalah 2x + No FHG 3x (mati gaya)</option>
                    <option value="c3_klftsnf">Kalah 2x + Gagal cetak 3x + No FHG 3x (krisis serangan)</option>
                    <option value="c3_mnbto25">Menang 2x + BTTS 2x + Over 2.5 2x (mesin gol)</option>
                    <option value="c3_mncs">Menang 2x + Cleansheet 3x + Over 1.5 3x (solid & produktif)</option>
                    <option value="c3_u05krg">U0.5 2x + No SHG 3x + No FHG 3x (super kering)</option>
                    <option value="c3_o15bto25">Over 1.5 3x + BTTS 2x + Over 2.5 2x (banjir gol)</option>
                    <option value="c3_dru15nb">Draw 3x + U1.5 3x + No BTTS 3x (pertahanan gembok)</option>
                    <option value="c3_klbto25">Kalah 2x + BTTS 2x + Over 2.5 2x (kalah tapi rame gol)</option>
                    <option value="c3_csu15nf">Cleansheet 3x + U1.5 3x + No FHG 3x (super gembok)</option>
                    <option value="c3_mno25bt3">Menang 2x + Over 2.5 3x + BTTS 3x (dominan tapi terbuka)</option>
                    <option value="c3_dru15ns">Draw 3x + U1.5 3x + No SHG 3x (mandek total)</option>
                    <option value="c3_klu15fts">Kalah 2x + U1.5 3x + Gagal cetak 3x (tumpul & keok)</option>
                </optgroup>
                <optgroup label="— Kombinasi 4 Kondisi —">
                    <option value="c4_krisis">Kalah 2x + Gagal cetak 3x + No FHG 3x + U1.5 3x (krisis total)</option>
                    <option value="c4_panas">Menang 2x + BTTS 2x + Over 2.5 2x + Over 1.5 3x (panas maksimal)</option>
                    <option value="c4_gembok">Cleansheet 3x + U1.5 3x + No FHG 3x + No BTTS 3x (gembok total)</option>
                    <option value="c4_terpuruk">Kalah 3x + Gagal cetak 3x + No BTTS 3x + U1.5 3x (terpuruk total)</option>
                    <option value="c4_badai">Menang 3x + Over 2.5 3x + BTTS 2x + Over 1.5 3x (badai gol)</option>
                </optgroup>
                <optgroup label="— ✅ Kombinasi Tervalidasi Out-of-Sample —">
                    <option value="cm_o25fhcs">O2.5 2x + FHG 3x + CS 3x → Away O0.5 (lolos validasi)</option>
                    <option value="cm_o15cshte">O1.5 3x + CS 3x + HT Even 3x → Away O0.5 (lolos validasi)</option>
                    <option value="cm_klodns">Kalah 3x + Odd 4x + No SHG 3x → No Draw (lolos validasi)</option>
                    <option value="cm_u05evfts">U0.5 2x + Even 4x + Gagal cetak 3x → U3.5 (lolos validasi)</option>
                </optgroup>
                <optgroup label="— ⚠️ Eksperimental (mining in-sample, BELUM divalidasi) —">
                    <option value="cm_shfhcs">SHG 3x + FHG 3x + CS 3x → O0.5</option>
                    <option value="cm_drnfbt">Draw 3x + No FHG 3x + BTTS 2x → O0.5</option>
                    <option value="cm_o15cshto">O1.5 3x + CS 3x + HT Odd 3x → O0.5</option>
                    <option value="cm_nsbthto">No SHG 3x + BTTS 2x + HT Odd 3x → FHG</option>
                    <option value="cm_odftshto">Odd 4x + Gagal cetak 3x + HT Odd 3x → U3.5</option>
                    <option value="cm_o15o25cs">O1.5 3x + O2.5 2x + CS 3x → Away O0.5</option>
                    <option value="cm_nsftshto">No SHG 3x + Gagal cetak 3x + HT Odd 3x → No Draw</option>
                    <option value="cm_o25ftsu35">O2.5 2x + Gagal cetak 3x + U3.5 4x → Home O0.5</option>
                    <option value="cm_o25nsu35">O2.5 2x + No SHG 3x + U3.5 4x → FHG</option>
                </optgroup>
                <optgroup label="— Kombinasi 5 Kondisi —">
                    <option value="c5_krisis">Kalah 2x + Gagal cetak 3x + No FHG 3x + No SHG 3x + U1.5 3x (krisis ekstrem)</option>
                    <option value="c5_gembok">Cleansheet 3x + U1.5 3x + No FHG 3x + No SHG 3x + No BTTS 3x (gembok ekstrem)</option>
                    <option value="c5_badai">Menang 2x + BTTS 2x + Over 2.5 2x + Over 1.5 3x + Odd 4x (badai ekstrem)</option>
                </optgroup>
            </select>
        </label>
        <label class="streak-field">
            <span class="text-[11px] uppercase tracking-wide text-slate-400 font-bold">Hasil</span>
            <select id="stkOut" class="px-4 py-3 rounded-xl border border-slate-300 text-sm font-semibold bg-white">
                <option value="o15">→ Over 1.5</option>
                <option value="o05" selected>→ Over 0.5</option>
                <option value="shg">→ SHG Over 0.5 (gol babak 2)</option>
                <option value="o25">→ Over 2.5 (min 3 gol)</option>
                <option value="btts">→ BTTS (kedua tim cetak)</option>
                <option value="nbtts">→ No BTTS (ada tim gagal cetak)</option>
                <option value="draw">→ Draw (seri)</option>
                <option value="nodraw">→ No Draw (ada pemenang)</option>
                <option value="hg05">→ Goal Home Over 0.5 (tuan rumah cetak)</option>
                <option value="ag05">→ Goal Away Over 0.5 (tamu cetak)</option>
                <optgroup label="— Total Gol (rentang) —">
                    <option value="tg01">→ Total Gol 0-1</option>
                    <option value="tg23">→ Total Gol 2-3</option>
                    <option value="tg46">→ Total Gol 4-6</option>
                </optgroup>
                <optgroup label="— Exactly Total Gol —">
                    <option value="eg1">→ Exactly 1 Gol</option>
                    <option value="eg2">→ Exactly 2 Gol</option>
                    <option value="eg3">→ Exactly 3 Gol</option>
                    <option value="eg4">→ Exactly 4 Gol</option>
                </optgroup>
                <optgroup label="— Hasil Pertandingan —">
                    <option value="hw">→ Home Win (menang tuan rumah)</option>
                    <option value="aw">→ Away Win (menang tamu)</option>
                </optgroup>
                <optgroup label="— Double Chance —">
                    <option value="dc1x">→ DC 1X (Home menang atau Draw)</option>
                    <option value="dcx2">→ DC X2 (Away menang atau Draw)</option>
                </optgroup>
                <optgroup label="— FT Skor Ganjil/Genap —">
                    <option value="ftodd">→ FT Skor Ganjil (total gol ganjil)</option>
                    <option value="fteven">→ FT Skor Genap (total gol genap)</option>
                </optgroup>
                <optgroup label="— Paket Under/Over —">
                    <option value="u15">→ Under 1.5 (maks 1 gol)</option>
                    <option value="u05">→ Under 0.5 (0-0)</option>
                    <option value="o35">→ Over 3.5 (min 4 gol)</option>
                </optgroup>
            </select>
        </label>
        <label class="streak-field">
            <span class="text-[11px] uppercase tracking-wide text-slate-400 font-bold" title="Ambang minimal peluang agar baris tampil. Kosongkan untuk memakai ambang default per hasil.">Min Peluang %</span>
            <input id="stkMinOver" type="number" min="0" max="100" step="1" value="99"
                class="px-3 py-3 rounded-xl border border-slate-300 text-sm font-semibold bg-white w-24">
        </label>
        <label class="streak-field">
            <span class="text-[11px] uppercase tracking-wide text-slate-400 font-bold" title="Baris hanya tampil bila sampel kondisi (angka di kolom Sampel, mis. 14/14) lebih dari angka ini.">Min Sampel</span>
            <input id="stkMinN" type="number" min="0" step="1" value="5"
                class="px-3 py-3 rounded-xl border border-slate-300 text-sm font-semibold bg-white w-24">
        </label>
        <input id="stkSearch" type="search" placeholder="Cari tim / liga…"
            class="px-3 py-3 rounded-xl border border-slate-300 text-sm min-w-[200px] focus:outline-none focus:ring-2 focus:ring-blue-500/40">
        <select id="stkLeague" class="px-3 py-3 rounded-xl border border-slate-300 text-sm bg-white">
            <option value="">Semua liga</option>
            <?php foreach ($leagues as $lg): ?>
                <option value="<?= htmlspecialchars($lg, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lg, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <label class="streak-field">
            <span class="text-[11px] uppercase tracking-wide text-slate-400 font-bold" title="Filter jam next match mulai dari waktu ini.">Dari Jam</span>
            <input id="stkTimeFrom" type="time" class="px-3 py-3 rounded-xl border border-slate-300 text-sm font-semibold bg-white">
        </label>
        <label class="streak-field">
            <span class="text-[11px] uppercase tracking-wide text-slate-400 font-bold" title="Filter jam next match sampai waktu ini.">Sampai Jam</span>
            <input id="stkTimeTo" type="time" class="px-3 py-3 rounded-xl border border-slate-300 text-sm font-semibold bg-white">
        </label>
        <label class="streak-field">
            <span class="text-[11px] uppercase tracking-wide text-slate-400 font-bold" title="Saring rekor Over 1.5 lawan di next match. Terbukti dari data (BTTS 3x): Lawan>=80 & Tim>=85 -> 91.4%; Lawan>=82 & Tim>=88 -> 93.6%; Lawan>=85 & Tim>=90 -> 96.0%.">Min Lawan O1.5%</span>
            <input id="stkOppOver" type="number" min="0" max="100" step="1" value=""
                class="px-3 py-3 rounded-xl border border-slate-300 text-sm font-semibold bg-white w-24">
        </label>
        <label class="streak-field">
            <span class="text-[11px] uppercase tracking-wide text-slate-400 font-bold" title="Rekor Over 1.5 tim itu sendiri. Gabung dgn Min Lawan O1.5% (kedua tim Over) menaikkan akurasi: 82/88 -> 93.6%, 85/90 -> 96.0%.">Min Tim O1.5%</span>
            <input id="stkTeamOver" type="number" min="0" max="100" step="1" value=""
                class="px-3 py-3 rounded-xl border border-slate-300 text-sm font-semibold bg-white w-24">
        </label>
        <label class="streak-check">
            <input id="stkCurOnly" type="checkbox" checked
                class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
            Streak skrg &ge; <span id="stkCurNeed">1</span>
        </label>
        <label class="streak-check">
            <input id="stkNextOnly" type="checkbox" checked
                class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
            Ada Next Match
        </label>

    </div>

    <!-- Ringkasan untuk kondisi terpilih -->
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <p class="text-sm text-slate-500">Rata-rata semua tim (<span id="stkModeLabel">2x</span>):
            peluang match berikutnya <b class="text-emerald-600" id="stkGlobalOver">–</b> <span id="stkOutLabel">Over 1.5</span>
            <span id="stkLift" class="font-bold"></span>.
            Baseline normal: Over 1.5 = <b><?= $baseO15 ?>%</b> · Over 0.5 = <b><?= $baseO05 ?>%</b>.</p>
        <p id="stkVerify" class="text-sm text-slate-500 mt-1 border-t border-slate-100 pt-2"></p>
        <div id="stkExpWarn" class="hidden mt-2 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-[12px] text-amber-800 leading-snug"></div>
    </div>

    <!-- Tabel sederhana -->
    <div class="rounded-2xl border border-slate-200 bg-white overflow-x-auto">
        <table class="w-full text-sm" id="stkTable">
            <thead class="bg-slate-50 text-slate-600">
                <tr class="text-left">
                    <th data-k="t"    class="px-4 py-3 font-bold cursor-pointer whitespace-nowrap">Tim</th>
                    <th data-k="mk"   class="px-4 py-3 font-bold cursor-pointer whitespace-nowrap">Market</th>
                    <th data-k="l"    class="px-4 py-3 font-bold cursor-pointer whitespace-nowrap">Liga</th>
                    <th data-k="over" class="px-4 py-3 font-bold cursor-pointer text-center whitespace-nowrap">Peluang <span id="stkColOut">Over 1.5</span></th>
                    <th data-k="lift" class="px-4 py-3 font-bold cursor-pointer text-center whitespace-nowrap" title="Selisih vs baseline (rata-rata semua match tanpa syarat streak). Positif = streak ini menambah sinyal; mendekati 0 = cuma noise.">± Base</th>
                    <th data-k="samp" class="px-4 py-3 font-bold cursor-pointer text-center whitespace-nowrap" title="Menang / total sampel. Angka kiri = berapa kali kondisi ini benar dari total match yang cocok.">Menang/Total</th>
                    <th data-k="lb" class="px-4 py-3 font-bold cursor-pointer text-center whitespace-nowrap" title="Wilson lower-bound 95%: batas bawah peluang sebenarnya. Makin tinggi makin andal. Sampel kecil membuat angka ini jatuh meski rate 100%.">Keandalan</th>
                    <th data-k="cur"  class="px-4 py-3 font-bold cursor-pointer text-center">Streak skrg</th>
                    <th data-k="tu15" class="px-4 py-3 font-bold cursor-pointer text-center whitespace-nowrap" title="Jumlah pertandingan tim ini HARI INI yang berakhir Under 1.5. Syarat tampil untuk mode 'Under 1.5 2x beruntun'.">U1.5 hari ini</th>
                    <th data-k="oppOver" class="px-4 py-3 font-bold cursor-pointer text-center whitespace-nowrap" title="Rekor Over 1.5 lawan di next match. Makin tinggi, makin besar peluang Over 1.5 (terbukti di data).">Lawan O1.5%</th>
                    <th data-k="teamOver" class="px-4 py-3 font-bold cursor-pointer text-center whitespace-nowrap" title="Rekor Over 1.5 tim itu sendiri. Lawan & Tim sama-sama tinggi = peluang Over 1.5 naik (mis. keduanya >=80% -> 86.5%).">Tim O1.5%</th>
                </tr>
            </thead>
            <tbody id="stkBody"></tbody>
        </table>
    </div>
    <p class="text-[11px] text-slate-400">
        Dihitung: <?= htmlspecialchars($payload['builtAt']) ?>. ⚠️ Sampel kecil (terutama pilihan 3x) = angka lebih berisik.
        "Streak skrg" = sudah berapa kali tim Under 1.5 beruntun sampai saat ini.
        Syarat tampil: meleset 0x butuh total sampel > 5; meleset 1x butuh > 100; meleset 2x butuh > 200; meleset 3x butuh > 300 (kecuali diisi manual lewat "Min Peluang %").
    </p>

    <script>
    (function () {
        const DATA = <?= $rowsJson ?>;
        const GLOBAL = <?= json_encode($gl) ?>; // mode -> [over15, over05, sampel]
        const BASE_OUT = <?= json_encode($payload['baseOut'] ?? []) ?>; // outcome -> baseline % semua match
        const VERIFY = <?= json_encode($payload['verify'] ?? ['y' => [], 't' => [], 'ydate' => '', 'tdate' => '']) ?>; // backtest kemarin/hari ini
        const body = document.getElementById('stkBody');
        const modeSel = document.getElementById('stkMode');
        const outSel = document.getElementById('stkOut');
        const search = document.getElementById('stkSearch');
        const leagueSel = document.getElementById('stkLeague');
        const timeFromInp = document.getElementById('stkTimeFrom');
        const timeToInp = document.getElementById('stkTimeTo');
        const curOnly = document.getElementById('stkCurOnly');
        const nextOnly = document.getElementById('stkNextOnly');
        const oppOverInp = document.getElementById('stkOppOver');
        const minOverInp = document.getElementById('stkMinOver');
        const minNInp = document.getElementById('stkMinN');
        const teamOverInp = document.getElementById('stkTeamOver');
        const countEl = document.getElementById('stkCount');
        const modeLabel = document.getElementById('stkModeLabel');
        const globalOver = document.getElementById('stkGlobalOver');
        const outLabel = document.getElementById('stkOutLabel');
        const colOut = document.getElementById('stkColOut');
        let sortKey = 'over', asc = false;

        const timeToMin = (v) => {
            if (!v || !/^\d{2}:\d{2}$/.test(v)) return null;
            const [h, m] = v.split(':').map(Number);
            return h * 60 + m;
        };
        const inTimeRange = (min, from, to) => {
            if (from === null && to === null) return true;
            if (min === null || min === undefined) return false;
            if (from !== null && to !== null) return from <= to ? (min >= from && min <= to) : (min >= from || min <= to);
            if (from !== null) return min >= from;
            return min <= to;
        };
        // Wilson 95% lower bound dari rate(%) & sampel n. Ukuran keandalan: makin
        // tinggi makin yakin. n kecil -> LB jatuh jauh meski rate 100%.
        function wilsonLB(rate, n) {
            if (rate === null || rate === undefined || !n || n <= 0) return null;
            const z = 1.96, p = rate / 100;
            const lb = (p + z*z/(2*n) - z*Math.sqrt(p*(1-p)/n + z*z/(4*n*n))) / (1 + z*z/n);
            return Math.round(lb * 1000) / 10;
        }
        // --- Kontrol overfitting / multiple-testing --------------------------
        // Puluhan mode/kombinasi diuji terhadap data yang sama, jadi rate mentah
        // "100%" pada sampel kecil sangat mungkin muncul kebetulan (p-hacking).
        // Hanya 3 mode cm_ yang lolos uji out-of-sample; sisanya + kombinasi
        // orde-tinggi (c4_/c5_) diperlakukan sebagai EKSPERIMENTAL dan dikenai
        // syarat sampel & keandalan (Wilson lower bound) yang jauh lebih ketat.
        const VALIDATED_MODES = new Set(['cm_o25fhcs', 'cm_o15cshte', 'cm_klodns', 'cm_u05evfts']);
        function isMinedMode(mk) { return mk.indexOf('cm_') === 0 && !VALIDATED_MODES.has(mk); }
        function isHiOrder(mk) { return mk.indexOf('c4_') === 0 || mk.indexOf('c5_') === 0; }
        function isExperimental(mk) { return isMinedMode(mk) || isHiOrder(mk); }
        // Ambang untuk mode eksperimental: n minimal + Wilson LB minimal.
        const EXP_MIN_N = 30, EXP_MIN_LB = 75;
        // ambil data sesuai mode + hasil (o15/o05): m[mode] = [over15, over05, sampel]
        function pick(r, mode, out) {
            const a = (r.m && r.m[mode]) ? r.m[mode] : [null, null, 0, null, null, null, null, null];
            // Paket Under/Over turunan: komplemen dari slot yang sudah ada (null-safe).
            const comp = (v) => v === null || v === undefined ? null : Math.round((100 - v) * 10) / 10;
            // Double chance turunan: DC 1X = HW% + Draw%, DC X2 = AW% + Draw% (sampel sama, clamp 100).
            if (out === 'dc1x' || out === 'dcx2') {
                const w = out === 'dc1x' ? a[22] : a[23];
                const dc = (w === null || w === undefined || a[10] === null || a[10] === undefined) ? null : Math.min(100, Math.round((w + a[10]) * 10) / 10);
                return { over: dc, samp: a[2] };
            }
            const over = out === 'o05' ? a[1] : (out === 'shg' ? (a[3] ?? null) : (out === 'fhg' ? (a[4] ?? null) : (out === 'u25' ? (a[5] ?? null) : (out === 'o25' ? (a[6] ?? null) : (out === 'u35' ? (a[7] ?? null) : (out === 'btts' ? (a[8] ?? null) : (out === 'nbtts' ? (a[9] ?? null) : (out === 'draw' ? (a[10] ?? null) : (out === 'nodraw' ? (a[11] ?? null) : (out === 'hg05' ? (a[12] ?? null) : (out === 'ag05' ? (a[13] ?? null) : (out === 'tg01' ? (a[14] ?? null) : (out === 'tg23' ? (a[15] ?? null) : (out === 'tg46' ? (a[16] ?? null) : (out === 'tg7' ? (a[17] ?? null) : (out === 'eg1' ? (a[18] ?? null) : (out === 'eg2' ? (a[19] ?? null) : (out === 'eg3' ? (a[20] ?? null) : (out === 'eg4' ? (a[21] ?? null) : (out === 'hw' ? (a[22] ?? null) : (out === 'aw' ? (a[23] ?? null) : (out === 'ftodd' ? (a[24] ?? null) : (out === 'fteven' ? (a[25] ?? null) : (out === 'u15' ? comp(a[0]) : (out === 'u05' ? comp(a[1]) : (out === 'o35' ? comp(a[7] ?? null) : a[0]))))))))))))))))))))))))));
            return { over: over, samp: a[2] };
        }
        const MIN_SAMP = { '3': 8, '4': 5, '05_3': 5, '05_1': 20, '05_2': 8, 'kl_1': 40, 'kl_2': 20, 'kl_3': 10, 'mn_2': 20, 'mn_3': 10, 'dr_2': 12, 'dr_3': 8, 'od_4': 10, 'ev_4': 10, 'od_5': 8, 'ev_5': 8, 'o25s2': 20, 'o15s3': 20, 'o25s3': 15, 'nbtts3': 15, 'nfhg2': 15, 'nshg2': 15, 'nfhg3': 10, 'nshg3': 10, 'od4u': 10, 'ev4u': 10, 'btts2': 20, 'btts3': 15, 'nbtts2': 15, 'cs2': 10, 'fts2': 10, 'htodd3': 15, 'hteven3': 15, 'u15oe3': 8, 'dry2o': 5, 'btso3': 10, 'kncs3': 10, 'nfo3': 10, 'u15_5': 4, 'u15_6': 3, '05_4': 3, 'kl_4': 6, 'kl_5': 4, 'mn_4': 6, 'mn_5': 4, 'dr_4': 4, 'o15s4': 12, 'o15s5': 8, 'o25s4': 8, 'o25s5': 5, 'btts4': 8, 'nbtts4': 8, 'od_6': 5, 'ev_6': 5, 'shg3': 15, 'fhg3': 15, 'u35_4': 10, 'c_mn3op80': 8, 'c_mn3op80h': 6, 'c_both80': 10, 'c_both85': 8, 'c_b2560': 10, 'c_b2565': 8 };
        const MODE_TEXT = { '3': 'U1.5 3x', '4': 'U1.5 4x', '05_3': 'U0.5 3x', '05_1': 'U0.5 1x', '05_2': 'U0.5 2x', 'kl_1': 'Kalah 1x', 'kl_2': 'Kalah 2x', 'kl_3': 'Kalah 3x', 'mn_2': 'Menang 2x', 'mn_3': 'Menang 3x', 'dr_2': 'Draw 3x', 'dr_3': 'Draw 3x', 'od_4': 'Odd 4x', 'ev_4': 'Even 4x', 'od_5': 'Odd 5x', 'ev_5': 'Even 5x', 'ev_4': 'Even 4x', 'o25s2': 'O2.5 2x', 'o15s3': 'O1.5 3x', 'o25s3': 'O2.5 3x', 'nbtts3': 'NoBTTS 3x', 'nfhg2': 'NoFHG 2x', 'nshg2': 'NoSHG 2x', 'nfhg3': 'NoFHG 3x', 'nshg3': 'NoSHG 3x', 'od4u': 'Odd5x+2U1.5', 'ev4u': 'Even5x+2U1.5', 'u15oe3': 'U1.5 3x+1O2E', 'dry2o': 'Kering 3x', 'btso3': 'BTTS+O1.5 3x', 'kncs3': 'Kalah+Bobol 3x', 'nfo3': 'NoFHG+O0.5 3x', 'btts2': 'BTTS 2x', 'btts3': 'BTTS 3x', 'nbtts2': 'NoBTTS 2x', 'cs2': 'Cleansheet 3x', 'fts2': 'Gagal cetak 3x', 'htodd3': 'HT-Odd 3x', 'hteven3': 'HT-Even 3x', 'u15_5': 'U1.5 5x', 'u15_6': 'U1.5 6x', '05_4': 'U0.5 4x', 'kl_4': 'Kalah 4x', 'kl_5': 'Kalah 5x', 'mn_4': 'Menang 4x', 'mn_5': 'Menang 5x', 'dr_4': 'Draw 4x', 'o15s4': 'O1.5 4x', 'o15s5': 'O1.5 5x', 'o25s4': 'O2.5 4x', 'o25s5': 'O2.5 5x', 'btts4': 'BTTS 4x', 'nbtts4': 'NoBTTS 4x', 'od_6': 'Odd 6x', 'ev_6': 'Even 6x', 'shg3': 'SHG 3x', 'fhg3': 'FHG 3x', 'u35_4': 'U3.5 4x', 'c_mn3op80': 'Menang 3x + Lawan O1.5≥80%', 'c_mn3op80h': 'Menang 3x + Lawan O1.5≥80% + Kandang', 'c_both80': 'Kedua tim O1.5≥80%', 'c_both85': 'Kedua tim O1.5≥85%', 'c_b2560': 'Kedua tim O2.5≥60%', 'c_b2565': 'Kedua tim O2.5≥65%' };
        const LOSS_MODE = { 'kl_1': 1, 'kl_2': 1, 'kl_3': 1, 'kl_4': 1, 'kl_5': 1 };
        // Mode kombinasi preset: key -> [mode dasar A, mode dasar B] (untuk curOf & label).
        const COMBO_DEFS = {
            'c_u15kl': ['3', 'kl_2'], 'c_u15nf': ['3', 'nfhg3'], 'c_klfts': ['kl_2', 'fts2'],
            'c_mno25': ['mn_2', 'o25s2'], 'c_mnbt': ['mn_2', 'btts2'], 'c_o15bt': ['o15s3', 'btts2'],
            'c_u05ns': ['05_2', 'nshg3'], 'c_dru15': ['dr_3', '3'], 'c_evu15': ['ev_4', '3'],
            'c_odo15': ['od_4', 'o15s3'], 'c_csmn': ['cs2', 'mn_2'], 'c_ftsnf': ['fts2', 'nfhg3'],
            // Kombinasi 2 kondisi — batch 2
            'c_klnb': ['kl_2', 'nbtts3'], 'c_mnnf': ['mn_2', 'nfhg3'],
            'c_drnb': ['dr_3', 'nbtts3'], 'c_dro25': ['dr_3', 'o25s2'],
            'c_u15ns': ['3', 'nshg3'], 'c_o25bt3': ['o25s3', 'btts3'],
            'c_csu15': ['cs2', '3'], 'c_kl3fts': ['kl_3', 'fts2'],
            'c_htou15': ['htodd3', '3'], 'c_hteo15': ['hteven3', 'o15s3'],
            // Kombinasi 3 kondisi
            'c3_u15klnf': ['3', 'kl_2', 'nfhg3'], 'c3_klftsnf': ['kl_2', 'fts2', 'nfhg3'],
            'c3_mnbto25': ['mn_2', 'btts2', 'o25s2'], 'c3_mncs': ['mn_2', 'cs2', 'o15s3'],
            'c3_u05krg': ['05_2', 'nshg3', 'nfhg3'], 'c3_o15bto25': ['o15s3', 'btts2', 'o25s2'],
            'c3_dru15nb': ['dr_3', '3', 'nbtts3'], 'c3_klbto25': ['kl_2', 'btts2', 'o25s2'],
            // Kombinasi 2 kondisi — batch 3
            'c_klo25': ['kl_2', 'o25s2'], 'c_mnu15': ['mn_2', '3'],
            'c_csnf': ['cs2', 'nfhg3'], 'c_kl3nb': ['kl_3', 'nbtts3'],
            'c_mn3o25': ['mn_3', 'o25s3'], 'c_odbt': ['od_4', 'btts2'],
            'c_evnb': ['ev_4', 'nbtts3'], 'c_dr3u15': ['dr_3', '3'],
            'c_nfns': ['nfhg3', 'nshg3'],
            // Kombinasi khusus U1.5 3x/4x
            'c_u15fts': ['3', 'fts2'], 'c_u15nb': ['3', 'nbtts3'], 'c_u15u35': ['3', 'u35_4'],
            'c_u154kl': ['4', 'kl_2'], 'c_u154nf': ['4', 'nfhg3'], 'c_u154ns': ['4', 'nshg3'],
            'c_u154nb': ['4', 'nbtts3'], 'c_u154fts': ['4', 'fts2'], 'c_u154cs': ['4', 'cs2'],
            'c3_u154klnf': ['4', 'kl_2', 'nfhg3'], 'c3_u154nbns': ['4', 'nbtts3', 'nshg3'],
            'c3_u154csnf': ['4', 'cs2', 'nfhg3'],
            'c4_u154gembok': ['4', 'cs2', 'nfhg3', 'nbtts3'],
            'c4_u154krisis': ['4', 'kl_2', 'fts2', 'nshg3'],            // Kombinasi 3 kondisi — batch 3
            'c3_csu15nf': ['cs2', '3', 'nfhg3'], 'c3_mno25bt3': ['mn_2', 'o25s3', 'btts3'],
            'c3_dru15ns': ['dr_3', '3', 'nshg3'], 'c3_klu15fts': ['kl_2', '3', 'fts2'],
            // Kombinasi 4 kondisi
            'c4_krisis': ['kl_2', 'fts2', 'nfhg3', '3'], 'c4_panas': ['mn_2', 'btts2', 'o25s2', 'o15s3'],
            'c4_gembok': ['cs2', '3', 'nfhg3', 'nbtts3'], 'c4_terpuruk': ['kl_3', 'fts2', 'nbtts3', '3'],
            'c4_badai': ['mn_3', 'o25s3', 'btts2', 'o15s3'],
            // Kombinasi 5 kondisi
            'c5_krisis': ['kl_2', 'fts2', 'nfhg3', 'nshg3', '3'],
            'c5_gembok': ['cs2', '3', 'nfhg3', 'nshg3', 'nbtts3'],
            'c5_badai': ['mn_2', 'btts2', 'o25s2', 'o15s3', 'od_4'],
            // Kombinasi hasil mining matches.csv (win rate tertinggi)
            'cm_shfhcs': ['shg3', 'fhg3', 'cs2'], 'cm_drnfbt': ['dr_3', 'nfhg3', 'btts2'],
            'cm_o15cshto': ['o15s3', 'cs2', 'htodd3'], 'cm_nsbthto': ['nshg3', 'btts2', 'htodd3'],
            'cm_odftshto': ['od_4', 'fts2', 'htodd3'], 'cm_o15o25cs': ['o15s3', 'o25s2', 'cs2'],
            'cm_nsftshto': ['nshg3', 'fts2', 'htodd3'], 'cm_u05evfts': ['05_2', 'ev_4', 'fts2'],
            'cm_o25ftsu35': ['o25s2', 'fts2', 'u35_4'], 'cm_o25nsu35': ['o25s2', 'nshg3', 'u35_4'],
            'cm_o25fhcs': ['o25s2', 'fhg3', 'cs2'], 'cm_o15cshte': ['o15s3', 'cs2', 'hteven3'],
            'cm_klodns': ['kl_3', 'od_4', 'nshg3'],
        };
        const STREAK_LEN = { '3': 3, '4': 4, '05_3': 3, '05_1': 1, '05_2': 2, 'kl_1': 1, 'kl_2': 2, 'kl_3': 3, 'mn_2': 2, 'mn_3': 3, 'dr_2': 2, 'dr_3': 3, 'od_4': 4, 'ev_4': 4, 'od_5': 5, 'ev_5': 5, 'o25s2': 2, 'o15s3': 3, 'o25s3': 3, 'nbtts3': 3, 'nfhg2': 2, 'nshg2': 2, 'nfhg3': 3, 'nshg3': 3, 'od4u': 5, 'ev4u': 5, 'btts2': 2, 'btts3': 3, 'nbtts2': 2, 'cs2': 3, 'fts2': 3, 'htodd3': 3, 'hteven3': 3, 'u15oe3': 3, 'dry2o': 3, 'btso3': 3, 'kncs3': 3, 'nfo3': 3, 'u15_5': 5, 'u15_6': 6, '05_4': 4, 'kl_4': 4, 'kl_5': 5, 'mn_4': 4, 'mn_5': 5, 'dr_4': 4, 'o15s4': 4, 'o15s5': 5, 'o25s4': 4, 'o25s5': 5, 'btts4': 4, 'nbtts4': 4, 'od_6': 6, 'ev_6': 6, 'shg3': 3, 'fhg3': 3, 'u35_4': 4, 'c_mn3op80': 1, 'c_mn3op80h': 1, 'c_both80': 1, 'c_both85': 1, 'c_b2560': 1, 'c_b2565': 1 };
        // Registrasi metadata mode kombinasi — HARUS setelah deklarasi STREAK_LEN di atas.
        Object.keys(COMBO_DEFS).forEach(k => {
            MODE_TEXT[k] = COMBO_DEFS[k].map(x => MODE_TEXT[x]).join('+');
            MIN_SAMP[k] = COMBO_DEFS[k].length >= 4 ? 3 : (COMBO_DEFS[k].length >= 3 ? 4 : 5); // makin banyak kondisi makin langka
            STREAK_LEN[k] = 1; // curOf mode kombinasi = 1 bila SEMUA streak berjalan cukup
        });
        // streak "saat ini" yang relevan per mode
        function curOf(r, mode) {
            if (COMBO_DEFS[mode]) { // kombinasi: SEMUA streak berjalan harus sudah cukup panjang
                return COMBO_DEFS[mode].every(c => curOf(r, c) >= (STREAK_LEN[c] || 1)) ? 1 : 0;
            }
            // Menang 3x + syarat lawan Over1.5>=80% (dan kandang) di match berikutnya
            if (mode === 'c_mn3op80') return (r.curW >= 3 && r.oppOver !== null && r.oppOver >= 80) ? 1 : 0;
            if (mode === 'c_mn3op80h') return (r.curW >= 3 && r.oppOver !== null && r.oppOver >= 80 && r.nextHome === 1) ? 1 : 0;
            // Kedua tim subur: rate musim tim sendiri (100-base) & lawan berikutnya sama-sama tinggi
            if (mode === 'c_both80') return (r.oppOver !== null && r.oppOver >= 80 && r.base !== null && (100 - r.base) >= 80) ? 1 : 0;
            if (mode === 'c_both85') return (r.oppOver !== null && r.oppOver >= 85 && r.base !== null && (100 - r.base) >= 85) ? 1 : 0;
            // Kedua tim subur Over 2.5: rate musim O2.5 tim sendiri & lawan berikutnya sama-sama tinggi
            if (mode === 'c_b2560') return (r.oppO25 !== null && r.oppO25 >= 60 && r.selfO25 !== null && r.selfO25 >= 60) ? 1 : 0;
            if (mode === 'c_b2565') return (r.oppO25 !== null && r.oppO25 >= 65 && r.selfO25 !== null && r.selfO25 >= 65) ? 1 : 0;
            if (mode.indexOf('mn_') === 0) return r.curW; // streak menang berjalan (2x/3x)
            if (mode.indexOf('dr_') === 0) return r.curD; // streak draw berjalan (2x/3x)
            if (mode === 'od_4' || mode === 'od_5' || mode === 'od_6') return r.curO;           // streak odd berjalan
            if (mode === 'ev_4' || mode === 'ev_5' || mode === 'ev_6') return r.curE;           // streak even berjalan
            if (mode === 'o25s2' || mode === 'o25s3' || mode === 'o25s4' || mode === 'o25s5') return r.curO25; // streak Over 2.5 berjalan
            if (mode === 'o15s3' || mode === 'o15s4' || mode === 'o15s5') return r.curO15;        // streak Over 1.5 berjalan
            if (mode === 'btts4') return r.curBTTS;       // streak BTTS berjalan
            if (mode === 'nbtts4') return r.curNB;        // streak No BTTS berjalan
            if (mode === 'nbtts3') return r.curNB;        // streak No BTTS berjalan
            if (mode === 'nfhg2' || mode === 'nfhg3') return r.curNFHG; // streak No FHG berjalan
            if (mode === 'nshg2' || mode === 'nshg3') return r.curNSHG; // streak No SHG berjalan
            if (mode === 'u15oe3') return r.cur;          // current streak U1.5 (komponen utama kombinasi)
            if (mode === 'dry2o') return r.cur;           // kering total → pakai streak U1.5 berjalan
            if (mode === 'btso3') return r.curBTTS;       // BTTS berjalan
            if (mode === 'kncs3') return r.curL;          // kalah berjalan
            if (mode === 'nfo3') return r.curNFHG;        // No FHG berjalan
            if (mode === 'od4u') return r.curO;           // streak odd berjalan (4x + U1.5)
            if (mode === 'ev4u') return r.curE;           // streak even berjalan (4x + U1.5)
            if (mode === 'btts2' || mode === 'btts3') return r.curBTTS;
            if (mode === 'nbtts2') return r.curNB;
            if (mode === 'cs2') return r.curCS;
            if (mode === 'fts2') return r.curFTS;
            if (mode === 'shg3') return r.curSHG;         // streak SHG berjalan
            if (mode === 'fhg3') return r.curFHG;         // streak FHG berjalan
            if (mode === 'u35_4') return r.curU35;        // streak U3.5 berjalan
            if (mode === 'htodd3') return r.curHTO;
            if (mode === 'hteven3') return r.curHTE;
            if (mode.indexOf('05') === 0) return r.curU;  // streak U0.5 berjalan
            if (LOSS_MODE[mode]) return r.curL;           // streak kalah berjalan
            return r.cur;                                  // streak U1.5 berjalan
        }
        const curNeedEl = document.getElementById('stkCurNeed');
        function overClass(v) {
            if (v === null) return 'text-slate-300';
            if (v >= 85) return 'text-emerald-600 font-extrabold';
            if (v >= 78) return 'text-emerald-600 font-bold';
            return 'text-slate-700';
        }
        // Sorot baris yang jam pertandingannya = jam sekarang (mis. sekarang 11:xx → sorot 11:00–11:59).
        function hourHiStyle(nextMin) {
            if (nextMin === null || nextMin === undefined) return '';
            return Math.floor(nextMin / 60) === new Date().getHours()
                ? ' style="background-color:#dbeafe;box-shadow:inset 3px 0 0 #2563eb"' : '';
        }
        function render() {
            const mode = modeSel.value;
            const out = outSel.value; // 'o15' | 'o05'
            const outText = out === 'dc1x' ? 'DC 1X (Home/Draw)' : out === 'dcx2' ? 'DC X2 (Away/Draw)' : out === 'o05' ? 'Over 0.5' : (out === 'shg' ? 'SHG Over 0.5' : (out === 'fhg' ? 'FHG Over 0.5' : (out === 'u25' ? 'Under 2.5' : (out === 'o25' ? 'Over 2.5' : (out === 'u35' ? 'Under 3.5' : (out === 'btts' ? 'BTTS' : (out === 'nbtts' ? 'No BTTS' : (out === 'draw' ? 'Draw' : (out === 'nodraw' ? 'No Draw' : (out === 'hg05' ? 'Goal Home Over 0.5' : (out === 'ag05' ? 'Goal Away Over 0.5' : (out === 'tg01' ? 'Total Gol 0-1' : (out === 'tg23' ? 'Total Gol 2-3' : (out === 'tg46' ? 'Total Gol 4-6' : (out === 'tg7' ? 'Total Gol 7+' : (out === 'eg1' ? 'Exactly 1 Gol' : (out === 'eg2' ? 'Exactly 2 Gol' : (out === 'eg3' ? 'Exactly 3 Gol' : (out === 'eg4' ? 'Exactly 4 Gol' : (out === 'hw' ? 'Home Win' : (out === 'aw' ? 'Away Win' : (out === 'ftodd' ? 'FT Skor Ganjil' : (out === 'fteven' ? 'FT Skor Genap' : (out === 'u15' ? 'Under 1.5' : (out === 'u05' ? 'Under 0.5' : (out === 'o35' ? 'Over 3.5' : 'Over 1.5'))))))))))))))))))))))))));
            const q = search.value.toLowerCase().trim();
            const lg = leagueSel.value;
            const ALL_MODES = ['3','4','05_2','05_3','kl_2','kl_3','mn_2','mn_3','dr_3','od_4','ev_4','od_5','ev_5','o25s2','o15s3','o25s3','nbtts3','nfhg3','nshg3','od4u','ev4u','u15oe3','dry2o','btso3','kncs3','nfo3','btts2','btts3','cs2','fts2','htodd3','hteven3','u15_5','u15_6','05_4','kl_4','kl_5','mn_4','mn_5','dr_4','o15s4','o15s5','o25s4','o25s5','btts4','nbtts4','od_6','ev_6','c_u15kl','c_u15nf','c_klfts','c_mno25','c_mnbt','c_o15bt','c_u05ns','c_dru15','c_evu15','c_odo15','c_csmn','c_ftsnf','c_klnb','c_mnnf','c_drnb','c_dro25','c_u15ns','c_o25bt3','c_csu15','c_kl3fts','c_htou15','c_hteo15','c3_u15klnf','c3_klftsnf','c3_mnbto25','c3_mncs','c3_u05krg','c3_o15bto25','c3_dru15nb','c3_klbto25','c_klo25','c_mnu15','c_csnf','c_kl3nb','c_mn3o25','c_odbt','c_evnb','c_dr3u15','c_nfns','c_u15fts','c_u15nb','c_u15u35','c_u154kl','c_u154nf','c_u154ns','c_u154nb','c_u154fts','c_u154cs','c3_u154klnf','c3_u154nbns','c3_u154csnf','c4_u154gembok','c4_u154krisis','c3_csu15nf','c3_mno25bt3','c3_dru15ns','c3_klu15fts','c4_krisis','c4_panas','c4_gembok','c4_terpuruk','c4_badai','c5_krisis','c5_gembok','c5_badai','shg3','fhg3','u35_4','cm_shfhcs','cm_drnfbt','cm_o15cshto','cm_nsbthto','cm_odftshto','cm_o15o25cs','cm_nsftshto','cm_u05evfts','cm_o25ftsu35','cm_o25nsu35','cm_o25fhcs','cm_o15cshte','cm_klodns','c_mn3op80','c_mn3op80h','c_both80','c_both85','c_b2560','c_b2565'];
            const isAll = mode === 'ALL';
            const modesList = isAll ? ALL_MODES : [mode];

            modeLabel.textContent = isAll ? 'Semua market' : (MODE_TEXT[mode] || mode);
            // Banner peringatan overfitting utk mode eksperimental / ALL.
            const expWarn = document.getElementById('stkExpWarn');
            if (expWarn) {
                if (!isAll && isExperimental(mode)) {
                    expWarn.classList.remove('hidden');
                    expWarn.innerHTML = '⚠️ <b>Mode eksperimental (hasil data-mining, belum divalidasi out-of-sample).</b> ' +
                        'Ratusan kombinasi diuji terhadap data yang sama, sehingga rate tinggi pada sampel kecil sering hanya kebetulan (multiple-testing / p-hacking). ' +
                        'Untuk mode ini baris hanya tampil bila sampel ≥ ' + EXP_MIN_N + ' dan keandalan (Wilson LB) ≥ ' + EXP_MIN_LB + '%. ' +
                        'Utamakan grup "✅ Tervalidasi" dan kolom <b>Keandalan</b>, bukan angka Peluang mentah.';
                } else if (isAll) {
                    expWarn.classList.remove('hidden');
                    expWarn.innerHTML = '⚠️ Tampilan "Semua market" mencakup mode eksperimental (ditandai <span class="rounded bg-amber-100 px-1 font-bold text-amber-700">⚠ exp</span>). ' +
                        'Perlakukan angka mode bertanda itu sebagai hipotesis, bukan rekomendasi — cek kolom <b>Keandalan</b>.';
                } else {
                    expWarn.classList.add('hidden');
                    expWarn.innerHTML = '';
                }
            }
            outLabel.textContent = outText;
            colOut.textContent = outText;
            const gk = GLOBAL[mode];
            const dcv = (g, w) => Math.min(100, Math.round(((g[w] ?? 0) + (g[10] ?? 0)) * 10) / 10);
            const gv = isAll ? null : (gk ? (out === 'dc1x' ? dcv(gk, 22) : out === 'dcx2' ? dcv(gk, 23) : out === 'o05' ? gk[1] : (out === 'shg' ? (gk[3] ?? 0) : (out === 'fhg' ? (gk[4] ?? 0) : (out === 'u25' ? (gk[5] ?? 0) : (out === 'o25' ? (gk[6] ?? 0) : (out === 'u35' ? (gk[7] ?? 0) : (out === 'btts' ? (gk[8] ?? 0) : (out === 'nbtts' ? (gk[9] ?? 0) : (out === 'draw' ? (gk[10] ?? 0) : (out === 'nodraw' ? (gk[11] ?? 0) : (out === 'hg05' ? (gk[12] ?? 0) : (out === 'ag05' ? (gk[13] ?? 0) : (out === 'tg01' ? (gk[14] ?? 0) : (out === 'tg23' ? (gk[15] ?? 0) : (out === 'tg46' ? (gk[16] ?? 0) : (out === 'tg7' ? (gk[17] ?? 0) : (out === 'eg1' ? (gk[18] ?? 0) : (out === 'eg2' ? (gk[19] ?? 0) : (out === 'eg3' ? (gk[20] ?? 0) : (out === 'eg4' ? (gk[21] ?? 0) : (out === 'hw' ? (gk[22] ?? 0) : (out === 'aw' ? (gk[23] ?? 0) : (out === 'ftodd' ? (gk[24] ?? 0) : (out === 'fteven' ? (gk[25] ?? 0) : (out === 'u15' ? Math.round((100 - gk[0]) * 10) / 10 : (out === 'u05' ? Math.round((100 - gk[1]) * 10) / 10 : (out === 'o35' ? Math.round((100 - (gk[7] ?? 0)) * 10) / 10 : gk[0]))))))))))))))))))))))))))) : 0);
            globalOver.textContent = isAll ? '–' : gv + '%';
            // Lift vs baseline: selisih rata-rata mode ini terhadap rata-rata semua match.
            const liftEl = document.getElementById('stkLift');
            const baseAll = BASE_OUT[out] ?? null;
            if (isAll || gv === null || baseAll === null) { liftEl.textContent = ''; }
            else {
                const gl2 = Math.round((gv - baseAll) * 10) / 10;
                liftEl.innerHTML = ' <span class="' + (gl2 >= 3 ? 'text-emerald-600' : (gl2 <= -3 ? 'text-rose-500' : 'text-slate-400')) + '">(' + (gl2 > 0 ? '+' : '') + gl2 + '% vs baseline ' + baseAll + '%)</span>';
            }
            // Backtest kemarin & hari ini utk kondisi + hasil terpilih.
            const OUT_IDX = { o15: 1, o05: 2, shg: 3, fhg: 4, u25: 5, o25: 6, u35: 7, btts: 8, nbtts: 9, draw: 10, nodraw: 11, hg05: 12, ag05: 13, tg01: 14, tg23: 15, tg46: 16, tg7: 17, eg1: 18, eg2: 19, eg3: 20, eg4: 21, hw: 22, aw: 23, ftodd: 24, fteven: 25 };
            const vOf = (day) => {
                const v = (VERIFY[day] || {})[mode];
                if (!v || !v[0]) return null;
                const n = v[0];
                const h = out === 'dc1x' ? Math.min(n, (v[22] || 0) + (v[10] || 0)) : out === 'dcx2' ? Math.min(n, (v[23] || 0) + (v[10] || 0)) : out === 'u15' ? n - v[1] : (out === 'u05' ? n - v[2] : (out === 'o35' ? n - (v[7] || 0) : v[OUT_IDX[out]] || 0));
                return { n: n, h: h, pct: Math.round(h / n * 1000) / 10 };
            };
            const verifyEl = document.getElementById('stkVerify');
            if (isAll) { verifyEl.textContent = 'Backtest per hari: pilih satu kondisi (bukan ALL) untuk melihat hasil kemarin/hari ini.'; }
            else {
                const vy = vOf('y'), vt = vOf('t');
                const fmt = (v) => v === null ? 'tidak ada sinyal' : ('<b class="' + (v.pct >= (baseAll ?? 50) ? 'text-emerald-600' : 'text-rose-500') + '">' + v.h + '/' + v.n + ' kena (' + v.pct + '%)</b>');
                verifyEl.innerHTML = '📋 Backtest ' + outText + ' — Kemarin (' + (VERIFY.ydate || '-') + '): ' + fmt(vy) + ' · Hari ini (' + (VERIFY.tdate || '-') + '): ' + fmt(vt) +
                    (vy === null && vt === null && MODE_TEXT[mode] && !VERIFY.y[mode] && !VERIFY.t[mode] && ['od4u','ev4u','u15oe3','dry2o','btso3','kncs3','nfo3'].indexOf(mode) !== -1 ? ' <span class="text-slate-400">(backtest tidak tersedia utk mode kombinasi kompleks lama)</span>' : '');
            }
            curNeedEl.textContent = isAll ? 'len' : (STREAK_LEN[mode] || 1);

            const timeFrom = timeToMin(timeFromInp.value);
            const timeTo = timeToMin(timeToInp.value);
            const minN = parseInt(minNInp.value, 10) || 0;
            const oppMin = parseFloat(oppOverInp.value) || 0;
            const teamMin = parseFloat(teamOverInp.value) || 0;
            // "Min Peluang %": bila diisi, ambang manual ini menggantikan ambang default per hasil.
            const minOverV = minOverInp.value === '' ? null : parseFloat(minOverInp.value);
            const overOK = (v) => v !== null && (minOverV !== null && !isNaN(minOverV) ? v >= minOverV : (out === 'dc1x' || out === 'dcx2' ? v >= 80 : out === 'o05' ? v > 97 : ((out === 'shg' || out === 'fhg') ? v >= 90 : (out === 'u25' ? v >= 75 : (out === 'o25' ? v > 70 : (out === 'u35' ? v > 80 : (out === 'btts' ? v >= 80 : (out === 'nbtts' ? v >= 80 : (out === 'draw' ? v >= 60 : (out === 'nodraw' ? v >= 80 : (out === 'hg05' ? v >= 80 : (out === 'ag05' ? v >= 80 : (out === 'tg01' ? v >= 60 : (out === 'tg23' ? v >= 60 : (out === 'tg46' ? v >= 40 : (out === 'tg7' ? v >= 20 : (out === 'eg1' ? v >= 40 : (out === 'eg2' ? v >= 40 : (out === 'eg3' ? v >= 40 : (out === 'eg4' ? v >= 30 : (out === 'hw' ? v >= 50 : (out === 'aw' ? v >= 50 : (out === 'ftodd' ? v >= 55 : (out === 'fteven' ? v >= 55 : (out === 'u15' ? v >= 60 : (out === 'u05' ? v >= 40 : (out === 'o35' ? v >= 30 : v >= 99)))))))))))))))))))))))))));

            let d = [];
            DATA.forEach(r => {
                const oppOver = (r.oppOver === null || r.oppOver === undefined) ? null : r.oppOver;
                const teamOver = (r.base === null || r.base === undefined) ? null : Math.round((100 - r.base) * 10) / 10;
                if (oppMin > 0 && !(oppOver !== null && oppOver >= oppMin)) return;
                if (teamMin > 0 && !(teamOver !== null && teamOver >= teamMin)) return;
                if (lg && r.l !== lg) return;
                if (q && !(r.t.toLowerCase().includes(q) || r.l.toLowerCase().includes(q))) return;
                if (nextOnly.checked && !r.next) return;
                if (!inTimeRange(r.nextMin, timeFrom, timeTo)) return;
                modesList.forEach(mk => {
                    const p = pick(r, mk, out);
                    if (p.over === null) return;
                    if (minN > 0 && !(p.samp > minN)) return; // sampel kondisi harus > Min Sampel
                    const hits = Math.round(p.over * p.samp / 100);
                    const miss = p.samp - hits; // berapa kali meleset
                    const exp = isExperimental(mk);
                    // Mode eksperimental (mining in-sample / kombinasi orde-tinggi):
                    // butuh sampel besar DAN Wilson lower bound tinggi agar tak lolos
                    // hanya karena kebetulan pada n kecil — apa pun ambang lain di bawah.
                    if (exp) {
                        if (p.samp < EXP_MIN_N) return;
                        const lbExp = wilsonLB(p.over, p.samp);
                        if (lbExp === null || lbExp < EXP_MIN_LB) return;
                    }
                    if (minOverV !== null && !isNaN(minOverV)) {
                        // "Min Peluang %" diisi manual -> pakai ambang manual ini, tanpa toleransi meleset.
                        if (!overOK(p.over)) return;
                    } else {
                        // syarat mutlak: meleset 0x > sampel 6; 1x > sampel 100; 2x > sampel 200; 3x > sampel 300
                        if (miss === 0 && p.samp < 6) return;
                        else if (miss === 1 && p.samp <= 100) return;
                        else if (miss === 2 && p.samp <= 200) return;
                        else if (miss === 3 && p.samp <= 300) return;
                        else if (miss > 3) return;
                    }
                    const cur = curOf(r, mk);
                    const need = STREAK_LEN[mk] || 1;
                    if (curOnly.checked && cur < need) return;
                    const baseV = BASE_OUT[out] ?? null;
                    d.push({ t: r.t, mk: MODE_TEXT[mk] || mk, exp: exp, l: r.l, cur: cur, over: p.over, samp: p.samp, hits: hits, miss: miss,
                        lift: baseV === null || p.over === null ? null : Math.round((p.over - baseV) * 10) / 10,
                        lb: wilsonLB(p.over, p.samp), tu15: r.tu15 || 0, oppOver: oppOver, teamOver: teamOver, next: r.next, nextMin: r.nextMin });
                });
            });

            d.sort((a, b) => {
                let x = a[sortKey], y = b[sortKey];
                if (x === null) x = -1; if (y === null) y = -1;
                if (typeof x === 'string') return asc ? x.localeCompare(y) : y.localeCompare(x);
                return asc ? x - y : y - x;
            });
            if (countEl) countEl.textContent = '';
            body.innerHTML = d.map(r => `<tr class="border-t border-slate-100 hover:bg-indigo-50/30"${hourHiStyle(r.nextMin)}>
                <td class="px-4 py-2.5"><div class="font-semibold text-slate-900 whitespace-nowrap">${r.t}</div>${r.next ? `<div class="text-[10px] text-slate-500 mt-0.5 whitespace-nowrap">${r.next}</div>` : ''}</td>
                <td class="px-4 py-2.5 text-[11px] font-semibold text-indigo-600 whitespace-nowrap">${r.mk || '-'}${r.exp ? ' <span class="ml-1 rounded bg-amber-100 px-1 text-[9px] font-bold text-amber-700" title="Mode eksperimental hasil data-mining, belum tervalidasi out-of-sample. Angka % kemungkinan optimistis.">⚠ exp</span>' : ''}</td>
                <td class="px-4 py-2.5 text-[11px] text-slate-500" style="max-width:240px;white-space:normal;line-height:1.25">${r.l}</td>
                <td class="px-4 py-2.5 text-center text-base ${overClass(r.over)}">${r.over}%</td>
                <td class="px-4 py-2.5 text-center font-bold ${r.lift===null?'text-slate-300':(r.lift>=5?'text-emerald-600':(r.lift>0?'text-emerald-500':(r.lift>=-2?'text-slate-400':'text-rose-500')))}">${r.lift===null?'-':(r.lift>0?'+':'')+r.lift}%</td>
                <td class="px-4 py-2.5 text-center text-slate-400"><span class="font-semibold text-emerald-600">${r.hits}</span>/${r.samp}</td>
                <td class="px-4 py-2.5 text-center font-semibold ${r.lb===null?'text-slate-300':(r.lb>=80?'text-emerald-600':(r.lb>=70?'text-amber-600':'text-rose-500'))}">${r.lb===null?'-':r.lb+'%'}</td>
                <td class="px-4 py-2.5 text-center font-bold ${r.cur>=2?'text-indigo-600':'text-slate-400'}">${r.cur || '-'}</td>
                <td class="px-4 py-2.5 text-center font-bold ${r.tu15>=1?'text-emerald-600':'text-slate-300'}">${r.tu15 || '-'}</td>
                <td class="px-4 py-2.5 text-center font-semibold ${r.oppOver===null?'text-slate-300':(r.oppOver>=85?'text-emerald-600':(r.oppOver>=80?'text-emerald-500':'text-slate-500'))}">${r.oppOver===null?'-':r.oppOver+'%'}</td>
                <td class="px-4 py-2.5 text-center font-semibold ${r.teamOver===null?'text-slate-300':(r.teamOver>=85?'text-emerald-600':(r.teamOver>=80?'text-emerald-500':'text-slate-500'))}">${r.teamOver===null?'-':r.teamOver+'%'}</td>
            </tr>`).join('') || `<tr><td colspan="11" class="px-4 py-8 text-center text-slate-400">Tidak ada tim yang cukup sampelnya untuk kondisi ini.</td></tr>`;
        }
        document.querySelectorAll('#stkTable th').forEach(th => th.addEventListener('click', () => {
            const k = th.dataset.k;
            if (k === sortKey) asc = !asc; else { sortKey = k; asc = (k === 't' || k === 'l'); }
            render();
        }));
        [modeSel, outSel, search, leagueSel, timeFromInp, timeToInp, curOnly, nextOnly, oppOverInp, teamOverInp, minOverInp, minNInp].forEach(el => el.addEventListener('input', render));
        [curOnly, nextOnly].forEach(el => el.addEventListener('change', render));

        // ---- Tabel peluang 100% (scan semua market × semua hasil) -------------
        const ALL_MODES_100 = ['3','4','05_2','05_3','kl_2','kl_3','mn_2','mn_3','dr_3','od_4','ev_4','od_5','ev_5','o25s2','o15s3','o25s3','nbtts3','nfhg3','nshg3','od4u','ev4u','u15oe3','dry2o','btso3','kncs3','nfo3','btts2','btts3','cs2','fts2','htodd3','hteven3','u15_5','u15_6','05_4','kl_4','kl_5','mn_4','mn_5','dr_4','o15s4','o15s5','o25s4','o25s5','btts4','nbtts4','od_6','ev_6','c_u15kl','c_u15nf','c_klfts','c_mno25','c_mnbt','c_o15bt','c_u05ns','c_dru15','c_evu15','c_odo15','c_csmn','c_ftsnf','c_klnb','c_mnnf','c_drnb','c_dro25','c_u15ns','c_o25bt3','c_csu15','c_kl3fts','c_htou15','c_hteo15','c3_u15klnf','c3_klftsnf','c3_mnbto25','c3_mncs','c3_u05krg','c3_o15bto25','c3_dru15nb','c3_klbto25','c_klo25','c_mnu15','c_csnf','c_kl3nb','c_mn3o25','c_odbt','c_evnb','c_dr3u15','c_nfns','c_u15fts','c_u15nb','c_u15u35','c_u154kl','c_u154nf','c_u154ns','c_u154nb','c_u154fts','c_u154cs','c3_u154klnf','c3_u154nbns','c3_u154csnf','c4_u154gembok','c4_u154krisis','c3_csu15nf','c3_mno25bt3','c3_dru15ns','c3_klu15fts','c4_krisis','c4_panas','c4_gembok','c4_terpuruk','c4_badai','c5_krisis','c5_gembok','c5_badai','shg3','fhg3','u35_4','cm_shfhcs','cm_drnfbt','cm_o15cshto','cm_nsbthto','cm_odftshto','cm_o15o25cs','cm_nsftshto','cm_u05evfts','cm_o25ftsu35','cm_o25nsu35','cm_o25fhcs','cm_o15cshte','cm_klodns','c_mn3op80','c_mn3op80h','c_both80','c_both85','c_b2560','c_b2565'];
        const OUTS_100 = [
            { k: 'o15', t: 'Over 1.5' }, { k: 'o05', t: 'Over 0.5' },
            { k: 'shg', t: 'SHG O0.5' },
            { k: 'o25', t: 'Over 2.5' }, { k: 'btts', t: 'BTTS' }, { k: 'nbtts', t: 'No BTTS' }, { k: 'draw', t: 'Draw' }, { k: 'nodraw', t: 'No Draw' },
            { k: 'hg05', t: 'Home O0.5' }, { k: 'ag05', t: 'Away O0.5' },
            { k: 'tg01', t: 'TG 0-1' }, { k: 'tg23', t: 'TG 2-3' }, { k: 'tg46', t: 'TG 4-6' },
            { k: 'eg1', t: 'Exact 1' }, { k: 'eg2', t: 'Exact 2' }, { k: 'eg3', t: 'Exact 3' }, { k: 'eg4', t: 'Exact 4' },
            { k: 'hw', t: 'Home Win' }, { k: 'aw', t: 'Away Win' },
            { k: 'dc1x', t: 'DC 1X' }, { k: 'dcx2', t: 'DC X2' },
            { k: 'ftodd', t: 'FT Ganjil' }, { k: 'fteven', t: 'FT Genap' },
            { k: 'u15', t: 'Under 1.5' }, { k: 'u05', t: 'Under 0.5' }, { k: 'o35', t: 'Over 3.5' },
        ];
        const body100 = document.getElementById('stk100Body');
        const count100 = document.getElementById('stk100Count');
        const cur100 = document.getElementById('stk100Cur');
        const next100 = document.getElementById('stk100Next');
        let sortKey100 = 'lb', asc100 = false;
        function render100() {
            const q = search.value.toLowerCase().trim();
            const lg = leagueSel.value;
            const timeFrom = timeToMin(timeFromInp.value);
            const timeTo = timeToMin(timeToInp.value);
            let d = [];
            const minN = parseInt(minNInp.value, 10) || 0;
            DATA.forEach(r => {
                if (lg && r.l !== lg) return;
                if (q && !(r.t.toLowerCase().includes(q) || r.l.toLowerCase().includes(q))) return;
                if (next100.checked && !r.next) return;
                if (!inTimeRange(r.nextMin, timeFrom, timeTo)) return;
                ALL_MODES_100.forEach(mk => {
                    const cur = curOf(r, mk);
                    const need = STREAK_LEN[mk] || 1;
                    if (cur100.checked && cur < need) return;
                    OUTS_100.forEach(o => {
                        const p = pick(r, mk, o.k);
                        if (p.over === null) return;
                        if (minN > 0 && !(p.samp > minN)) return; // sampel kondisi harus > Min Sampel
                        const hits = Math.round(p.over * p.samp / 100);
                        const miss = p.samp - hits; // berapa kali meleset
                        const exp = isExperimental(mk);
                        // Mode eksperimental: sampel ≥ EXP_MIN_N & Wilson LB ≥ EXP_MIN_LB.
                        if (exp) {
                            if (p.samp < EXP_MIN_N) return;
                            const lbExp = wilsonLB(p.over, p.samp);
                            if (lbExp === null || lbExp < EXP_MIN_LB) return;
                        }
                        // syarat mutlak: meleset 0x (peluang 100%) butuh sampel > 6 (bukan tanpa batas — cegah 100% palsu pada n kecil); 1x > 100; 2x > 200; 3x > 300
                        if (miss === 0 && p.samp < 6) return;
                        if (miss === 1 && p.samp <= 100) return;
                        if (miss === 2 && p.samp <= 200) return;
                        if (miss === 3 && p.samp <= 300) return;
                        if (miss > 3) return;
                        d.push({ t: r.t, mk: MODE_TEXT[mk] || mk, exp: exp, outT: o.t, l: r.l, cur: cur,
                            over: p.over, samp: p.samp, hits: hits, miss: miss,
                            lb: wilsonLB(p.over, p.samp), next: r.next, nextMin: r.nextMin });
                    });
                });
            });
            d.sort((a, b) => {
                let x = a[sortKey100], y = b[sortKey100];
                if (x === null) x = -1; if (y === null) y = -1;
                if (typeof x === 'string') return asc100 ? x.localeCompare(y) : y.localeCompare(x);
                return asc100 ? x - y : y - x;
            });
            count100.textContent = d.length + ' baris';
            body100.innerHTML = d.map(r => `<tr class="border-t border-slate-100 hover:bg-amber-50/40"${hourHiStyle(r.nextMin)}>
                <td class="px-4 py-2.5"><div class="font-semibold text-slate-900 whitespace-nowrap">${r.t}</div>${r.next ? `<div class="text-[10px] text-slate-500 mt-0.5 whitespace-nowrap">${r.next}</div>` : ''}</td>
                <td class="px-4 py-2.5 text-[11px] font-semibold text-indigo-600 whitespace-nowrap">${r.mk}${r.exp ? ' <span class="ml-1 rounded bg-amber-100 px-1 text-[9px] font-bold text-amber-700" title="Eksperimental hasil data-mining, belum tervalidasi out-of-sample.">⚠ exp</span>' : ''}</td>
                <td class="px-4 py-2.5 text-[11px] font-bold text-emerald-700 whitespace-nowrap">${r.outT}</td>
                <td class="px-4 py-2.5 text-[11px] text-slate-500" style="max-width:240px;white-space:normal;line-height:1.25">${r.l}</td>
                <td class="px-4 py-2.5 text-center text-base text-emerald-600 font-extrabold">${r.over}%</td>
                <td class="px-4 py-2.5 text-center text-slate-400"><span class="font-semibold text-emerald-600">${r.hits}</span>/${r.samp}</td>
                <td class="px-4 py-2.5 text-center font-semibold ${r.lb===null?'text-slate-300':(r.lb>=80?'text-emerald-600':(r.lb>=70?'text-amber-600':'text-rose-500'))}">${r.lb===null?'-':r.lb+'%'}</td>
                <td class="px-4 py-2.5 text-center font-bold ${r.cur>=2?'text-indigo-600':'text-slate-400'}">${r.cur || '-'}</td>
            </tr>`).join('') || `<tr><td colspan="8" class="px-4 py-8 text-center text-slate-400">Belum ada kombinasi dengan peluang 100% & sampel memadai.</td></tr>`;
        }
        document.querySelectorAll('#stk100Table th').forEach(th => th.addEventListener('click', () => {
            const k = th.dataset.k;
            if (k === sortKey100) asc100 = !asc100; else { sortKey100 = k; asc100 = (k === 't' || k === 'l' || k === 'mk' || k === 'outT'); }
            render100();
        }));
        [search, leagueSel, timeFromInp, timeToInp, minNInp].forEach(el => el.addEventListener('input', render100));
        [cur100, next100, nextOnly].forEach(el => el.addEventListener('change', render100));

        render();
        render100();
    })();
    </script>

    <?php endif; ?>
</div>
