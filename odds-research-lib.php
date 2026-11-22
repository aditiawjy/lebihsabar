<?php
/**
 * Riset odds kickoff: dipakai analyze-odds-edge.php (CLI) dan odds-research.php (halaman).
 * Odds kickoff Over DAN Under + skor akhir diambil dari baris yang sama di goal_log_bpvm.csv (hanya ko_score 0 - 0).
 * goal_log.csv tidak dipakai: kolom ko_line di sana tercatat saat laga sudah berjalan (line = gol akhir + ~0.75).
 * Profil tim/pasangan dihitung dari matches.csv, hanya laga sebelum kickoff.
 */
const ODDS_RESEARCH_LOG = __DIR__ . '/goal_log_bpvm.csv';
const ODDS_RESEARCH_MATCHES = __DIR__ . '/matches.csv';
const ODDS_RESEARCH_TARGET = 5000;

function oddsResearchLoadBets(): array {
    $wib = new DateTimeZone('Asia/Jakarta');
    $bets = []; $seen = [];
    if (!is_readable(ODDS_RESEARCH_LOG) || !($fh = fopen(ODDS_RESEARCH_LOG, 'r'))) return [];
    $c = array_flip(fgetcsv($fh) ?: []);
    while (($r = fgetcsv($fh)) !== false) {
        if (($r[$c['ko_market']] ?? '') !== 'FT. O/U' || trim($r[$c['ko_score']] ?? '') !== '0 - 0') continue;
        if (!preg_match('/^o ([\d.]+):([\d.]+) \| u ([\d.]+):([\d.]+)$/', trim($r[$c['ko_ou_ft']] ?? ''), $m) || $m[1] !== $m[3]) continue;
        $d = DateTime::createFromFormat('d/m/Y H:i', $r[0], $wib);
        $fhScore = $r[$c['final_home']] ?? ''; $faScore = $r[$c['final_away']] ?? '';
        if (!$d || (float)$m[1] <= 0 || (float)$m[2] <= 1 || (float)$m[4] <= 1 || $fhScore === '' || $faScore === '') continue;
        $league = $r[1]; $home = trim($r[2]); $away = trim($r[3]);
        $key = $league . '|' . $home . '|' . $away . '|' . intdiv($d->getTimestamp(), 1800);
        if (isset($seen[$key])) continue;
        $seen[$key] = 1;
        $bets[] = ['ts' => $d->getTimestamp(), 'league' => $league, 'home' => $home, 'away' => $away, 'ht' => trim($r[$c['ht']] ?? ''),
            'final' => (int)$fhScore . '-' . (int)$faScore, 'line' => (float)$m[1], 'over' => (float)$m[2], 'under' => (float)$m[4],
            'goals' => (int)$fhScore + (int)$faScore];
    }
    fclose($fh);
    usort($bets, fn($a, $b) => $a['ts'] <=> $b['ts']);
    return $bets;
}

function oddsResearchAttachProfiles(array $bets): array {
    if (!$bets || !is_readable(ODDS_RESEARCH_MATCHES)) return $bets;
    $sg = new DateTimeZone('Asia/Singapore');
    $hist = [];
    $fh = fopen(ODDS_RESEARCH_MATCHES, 'r'); fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) {
        if (($r[7] ?? '') === '' || ($r[8] ?? '') === '') continue;
        try { $ts = (new DateTime($r[1], $sg))->getTimestamp(); } catch (Exception $e) { continue; }
        $hist[] = [$ts, $r[4], trim($r[2]), trim($r[3]), (int)$r[7] + (int)$r[8]];
    }
    fclose($fh);
    usort($hist, fn($a, $b) => $a[0] <=> $b[0]);
    $team = []; $pair = []; $lg = []; $i = 0; $n = count($hist);
    $avg = fn($x) => $x && $x[1] ? $x[0] / $x[1] : null;
    foreach ($bets as &$b) {
        while ($i < $n && $hist[$i][0] < $b['ts'] - 3600) {
            [, $L, $h, $a, $g] = $hist[$i++];
            foreach ([$L . '|' . $h, $L . '|' . $a] as $k) { $team[$k][0] = ($team[$k][0] ?? 0) + $g; $team[$k][1] = ($team[$k][1] ?? 0) + 1; }
            $k = $L . '|' . $h . '|' . $a; $pair[$k][0] = ($pair[$k][0] ?? 0) + $g; $pair[$k][1] = ($pair[$k][1] ?? 0) + 1;
            $lg[$L][0] = ($lg[$L][0] ?? 0) + $g; $lg[$L][1] = ($lg[$L][1] ?? 0) + 1;
        }
        $L = $b['league']; $th = $team[$L . '|' . $b['home']] ?? null; $ta = $team[$L . '|' . $b['away']] ?? null; $l = $avg($lg[$L] ?? null);
        $b['pred'] = $th && $ta && $th[1] >= 100 && $ta[1] >= 100 ? $avg($th) + $avg($ta) - $l : null;
        $p = $pair[$L . '|' . $b['home'] . '|' . $b['away']] ?? null;
        $b['pairPred'] = $p && $p[1] >= 15 ? $avg($p) : null;
    }
    unset($b);
    return $bets;
}

function oddsResearchSettle(float $line, int $goals, float $odd, bool $over): float {
    $one = function (float $l) use ($goals, $odd, $over) {
        if ($goals == $l) return 0.0;
        return (($goals > $l) === $over) ? $odd - 1 : -1.0;
    };
    return abs(fmod($line * 2, 1) - 0.5) < 1e-9 ? ($one($line - 0.25) + $one($line + 0.25)) / 2 : $one($line);
}

function oddsResearchRoi(array $set, bool $over): array {
    $x = array_map(fn($b) => oddsResearchSettle($b['line'], $b['goals'], $over ? $b['over'] : $b['under'], $over), $set);
    $n = count($x);
    if (!$n) return [0.0, 0.0];
    $m = array_sum($x) / $n;
    $v = array_sum(array_map(fn($y) => ($y - $m) ** 2, $x)) / max(1, $n - 1);
    return [$m, 1.96 * sqrt($v / $n)];
}

function oddsResearchSummary(string $label, array $set): array {
    $n = count($set);
    [$o, $oe] = oddsResearchRoi($set, true);
    [$u, $ue] = oddsResearchRoi($set, false);
    return ['label' => $label, 'n' => $n, 'line' => $n ? array_sum(array_column($set, 'line')) / $n : 0,
        'goals' => $n ? array_sum(array_column($set, 'goals')) / $n : 0, 'roiOver' => $o, 'ciOver' => $oe, 'roiUnder' => $u, 'ciUnder' => $ue];
}

function oddsResearchQuintiles(array $bets, string $key): array {
    $set = array_values(array_filter($bets, fn($b) => ($b[$key] ?? null) !== null));
    usort($set, fn($a, $b) => ($a[$key] - $a['line']) <=> ($b[$key] - $b['line']));
    $q = count($set);
    if ($q < 100) return [];
    $out = [];
    for ($j = 0; $j < 5; $j++) {
        $part = array_slice($set, intdiv($q * $j, 5), intdiv($q * ($j + 1), 5) - intdiv($q * $j, 5));
        $out[] = oddsResearchSummary(sprintf('Kelompok %d (selisih %+.2f..%+.2f)', $j + 1, $part[0][$key] - $part[0]['line'], end($part)[$key] - end($part)['line']), $part);
    }
    return $out;
}

/** Ringkasan untuk halaman, di-cache per versi goal_log_bpvm.csv + matches.csv. */
function oddsResearchReport(): array {
    $version = implode('|', array_map(fn($f) => is_file($f) ? filemtime($f) . '-' . filesize($f) : '0', [ODDS_RESEARCH_LOG, ODDS_RESEARCH_MATCHES]));
    $cache = sys_get_temp_dir() . '/sabaraja-odds-research-' . md5(__DIR__) . '.json';
    $cached = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
    if (($cached['version'] ?? null) === $version) return $cached;
    $bets = oddsResearchAttachProfiles(oddsResearchLoadBets());
    $byLeague = [];
    foreach ($bets as $b) $byLeague[$b['league']][] = $b;
    uasort($byLeague, fn($a, $b) => count($b) <=> count($a));
    $report = ['version' => $version, 'generatedAt' => time(), 'bets' => $bets,
        'all' => oddsResearchSummary('Semua laga', $bets),
        'leagues' => array_map(fn($L) => oddsResearchSummary($L, $byLeague[$L]), array_keys($byLeague)),
        'team' => oddsResearchQuintiles($bets, 'pred'), 'pair' => oddsResearchQuintiles($bets, 'pairPred')];
    $tmp = $cache . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($report)) !== false && !@rename($tmp, $cache)) @unlink($tmp);
    return $report;
}
