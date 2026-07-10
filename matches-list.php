<?php
// CSV Export handler — must run before any output
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    $csvPathEx = __DIR__ . '/matches.csv';
    $exportMatches = [];
    if (is_readable($csvPathEx) && ($hEx = fopen($csvPathEx, 'r')) !== false) {
        $hdrs = fgetcsv($hEx);
        if (is_array($hdrs)) {
            while (($rowEx = fgetcsv($hEx)) !== false) {
                if (count($rowEx) !== count($hdrs)) continue;
                $m = array_combine($hdrs, $rowEx);
                if ($m === false) continue;
                // Apply same filters as main view
                $exDate = substr((string)($m['match_time'] ?? ''), 0, 10);
                $df = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
                $dt = isset($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');
                if ($df > $dt) [$df, $dt] = [$dt, $df];
                if (!empty($df) && $exDate < $df) continue;
                if (!empty($dt) && $exDate > $dt) continue;
                if (!empty($_GET['league']) && ($m['league'] ?? '') !== $_GET['league']) continue;
                if (!empty($_GET['search'])) {
                    $s = $_GET['search'];
                    if (stripos($m['home_team'] ?? '', $s) === false && stripos($m['away_team'] ?? '', $s) === false) continue;
                }
                if (!empty($_GET['home_team']) && stripos($m['home_team'] ?? '', $_GET['home_team']) === false) continue;
                if (!empty($_GET['away_team']) && stripos($m['away_team'] ?? '', $_GET['away_team']) === false) continue;
                // Status filter
                $exStatus = $_GET['status'] ?? '';
                if (in_array($exStatus, ['upcoming','finished'], true)) {
                    $exHasFt = ($m['ft_home'] ?? '') !== '' && ($m['ft_away'] ?? '') !== '';
                    if ($exStatus === 'finished' && !$exHasFt) continue;
                    if ($exStatus === 'upcoming' && $exHasFt) continue;
                }
                // Time filter
                $exTf = preg_match('/^\d{2}:\d{2}$/', $_GET['time_from'] ?? '') ? $_GET['time_from'] : '';
                $exTt = preg_match('/^\d{2}:\d{2}$/', $_GET['time_to'] ?? '') ? $_GET['time_to'] : '';
                if ($exTf !== '' || $exTt !== '') {
                    $exDt = (string)($m['match_time'] ?? '');
                    $exTime = strlen($exDt) >= 16 ? substr($exDt, 11, 5) : '';
                    if ($exTime !== '') {
                        $tf2 = $exTf !== '' ? $exTf : '00:00';
                        $tt2 = $exTt !== '' ? $exTt : '23:59';
                        if ($tf2 <= $tt2) { if ($exTime < $tf2 || $exTime > $tt2) continue; }
                        else { if ($exTime < $tf2 && $exTime > $tt2) continue; }
                    }
                }
                $exportMatches[] = $m;
            }
        }
        fclose($hEx);
    }
    $filename = 'matches_export_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    if (!empty($exportMatches)) {
        fputcsv($out, array_keys($exportMatches[0]));
        foreach ($exportMatches as $row) fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// Pagination setup
$p = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$perPageOptions = [15, 25, 50, 100];
$perPage = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $perPageOptions) ? (int)$_GET['per_page'] : 15;
$offset = ($p - 1) * $perPage;

$leagueFilter = trim((string)($_GET['league'] ?? ''));
$searchFilter = trim((string)($_GET['search'] ?? ''));
$homeTeamFilter = trim((string)($_GET['home_team'] ?? ''));
$awayTeamFilter = trim((string)($_GET['away_team'] ?? ''));
$h2hHomeFilter = trim((string)($_GET['h2h_home'] ?? ''));
$h2hAwayFilter = trim((string)($_GET['h2h_away'] ?? ''));

// Date defaults and range parsing
$dateRange = $_GET['date_range'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';

if ($dateRange !== '') {
    $parts = explode(' to ', $dateRange);
    $date_from = trim($parts[0] ?? '');
    $date_to = trim($parts[1] ?? $date_from);
} elseif ($date_from !== '' && $date_to !== '') {
    $dateRange = $date_from === $date_to ? $date_from : $date_from . ' to ' . $date_to;
} else {
    $date_from = date('Y-m-d');
    $date_to   = date('Y-m-d');
    $dateRange = $date_from;
}
// Auto-swap if date_from is after date_to
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];

// Sorting parameters
$allowedSorts = ['match_time', 'home_team', 'away_team', 'league'];
$sort = $_GET['sort'] ?? 'match_time';
$order = $_GET['order'] ?? 'desc';
if (!in_array($sort, $allowedSorts)) $sort = 'match_time';
if (!in_array($order, ['asc', 'desc'])) $order = 'desc';

// Display timezone label shown next to match times
define('MATCH_TZ_LABEL', 'WIB');

// Status filter: upcoming (no FT score) | finished (has FT score) | '' (all)
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['', 'upcoming', 'finished'])) $statusFilter = '';

// Time filter HH:MM
$timeFrom = preg_match('/^\d{2}:\d{2}$/', $_GET['time_from'] ?? '') ? $_GET['time_from'] : '';
$timeTo   = preg_match('/^\d{2}:\d{2}$/', $_GET['time_to']   ?? '') ? $_GET['time_to']   : '';

function matchesBuildQuery(array $overrides = []): string {
    $allowedKeys = [
        'page', 'p', 'per_page', 'date_range', 'date_from', 'date_to', 'sort', 'order', 'status',
        'time_from', 'time_to', 'league', 'search', 'home_team', 'away_team', 'h2h_home', 'h2h_away', 'export',
    ];

    $base = ['page' => 'matches'];
    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $_GET)) {
            continue;
        }

        $value = $_GET[$key];
        if (is_array($value)) {
            continue;
        }

        $base[$key] = (string)$value;
    }

    if (array_key_exists('date_from', $overrides) || array_key_exists('date_to', $overrides)) {
        unset($base['date_range']);
    }
    if (array_key_exists('date_range', $overrides)) {
        unset($base['date_from'], $base['date_to']);
    }

    $query = array_merge($base, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    return 'index.php?' . http_build_query($query);
}

// Analisa market per pertandingan: total gol FT, gol babak 2, dan hasil market populer.
// Mengembalikan ['has'=>false] bila skor FT belum ada.
function matchesGoalMarkets(array $match): array {
    $ftH = $match['ft_home'] ?? null;
    $ftA = $match['ft_away'] ?? null;
    if ($ftH === null || $ftA === null) {
        return ['has' => false];
    }
    $ftH = (int)$ftH; $ftA = (int)$ftA;
    $tot = $ftH + $ftA;
    $sh = null;
    if (($match['fh_home'] ?? null) !== null && ($match['fh_away'] ?? null) !== null) {
        $sh = max(0, ($ftH - (int)$match['fh_home']) + ($ftA - (int)$match['fh_away']));
    }
    return [
        'has'   => true,
        'total' => $tot,
        'sh'    => $sh,
        'o15'   => $tot > 1,
        'o25'   => $tot > 2,
        'btts'  => $ftH > 0 && $ftA > 0,
    ];
}

// Badge kecil hasil market: hijau = kena (over/ya), abu = tidak.
function matchesMarketBadge(string $label, bool $hit): string {
    $cls = $hit ? 'bg-emerald-100 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-400 border-slate-200';
    return '<span class="inline-block px-1.5 py-0.5 rounded border text-[9px] font-bold ' . $cls . '">' . htmlspecialchars($label) . '</span>';
}

function matchesBuildH2hTimeSummary(array $allMatches, array $targetMatch): array {
    $homeTeam = trim((string)($targetMatch['home_team'] ?? ''));
    $awayTeam = trim((string)($targetMatch['away_team'] ?? ''));
    $matchDatetime = (string)($targetMatch['match_time'] ?? '');
    $kickoffTime = strlen($matchDatetime) >= 16 ? substr($matchDatetime, 11, 5) : '';

    $summary = [
        'time' => $kickoffTime,
        'total_meetings' => 0,
        'finished_meetings' => 0,
        'under_05' => 0,
        'under_25' => 0,
        'fhg_over_05' => 0,
        'shg_over_05' => 0,
        'over_05' => 0,
        'over_15' => 0,
        'over_25' => 0,
        'btts' => 0,
        'no_btts' => 0,
        'w1' => 0,
        'x' => 0,
        'w2' => 0,
    ];

    if ($homeTeam === '' || $awayTeam === '' || $kickoffTime === '') {
        return $summary;
    }

    foreach ($allMatches as $match) {
        $candidateHome = trim((string)($match['home_team'] ?? ''));
        $candidateAway = trim((string)($match['away_team'] ?? ''));
        $candidateDatetime = (string)($match['match_time'] ?? '');
        $candidateTime = strlen($candidateDatetime) >= 16 ? substr($candidateDatetime, 11, 5) : '';

        $sameOrder = strcasecmp($candidateHome, $homeTeam) === 0 && strcasecmp($candidateAway, $awayTeam) === 0;
        $reverseOrder = strcasecmp($candidateHome, $awayTeam) === 0 && strcasecmp($candidateAway, $homeTeam) === 0;
        if ((!$sameOrder && !$reverseOrder) || $candidateTime !== $kickoffTime) {
            continue;
        }

        $summary['total_meetings']++;

        if (($match['ft_home'] ?? null) === null || ($match['ft_away'] ?? null) === null) {
            continue;
        }

        $summary['finished_meetings']++;
        $ftHome = (int)$match['ft_home'];
        $ftAway = (int)$match['ft_away'];
        $fhHome = (int)($match['fh_home'] ?? 0);
        $fhAway = (int)($match['fh_away'] ?? 0);
        $totalGoals = $ftHome + $ftAway;
        $firstHalfGoals = $fhHome + $fhAway;
        $secondHalfGoals = max(0, ($ftHome - $fhHome) + ($ftAway - $fhAway));

        $team1Goals = strcasecmp($candidateHome, $homeTeam) === 0 ? $ftHome : $ftAway;
        $team2Goals = strcasecmp($candidateAway, $awayTeam) === 0 ? $ftAway : $ftHome;
        if ($team1Goals > $team2Goals) {
            $summary['w1']++;
        } elseif ($team1Goals < $team2Goals) {
            $summary['w2']++;
        } else {
            $summary['x']++;
        }

        if ($totalGoals === 0) {
            $summary['under_05']++;
        }
        if ($totalGoals < 3) {
            $summary['under_25']++;
        }
        if ($firstHalfGoals > 0) {
            $summary['fhg_over_05']++;
        }
        if ($secondHalfGoals > 0) {
            $summary['shg_over_05']++;
        }
        if ($totalGoals > 0) {
            $summary['over_05']++;
        }
        if ($totalGoals > 1) {
            $summary['over_15']++;
        }
        if ($totalGoals > 2) {
            $summary['over_25']++;
        }
        if ($ftHome > 0 && $ftAway > 0) {
            $summary['btts']++;
        } else {
            $summary['no_btts']++;
        }
    }

    return $summary;
}

function matchesBuildH2hDayTimeOverSummary(array $allMatches, array $targetMatch): array {
    $homeTeam = trim((string)($targetMatch['home_team'] ?? ''));
    $awayTeam = trim((string)($targetMatch['away_team'] ?? ''));
    $matchDatetime = (string)($targetMatch['match_time'] ?? '');
    $kickoffTime = strlen($matchDatetime) >= 16 ? substr($matchDatetime, 11, 5) : '';
    $matchDayOfWeek = '';

    try {
        $targetDate = new DateTime($matchDatetime);
        $matchDayOfWeek = $targetDate->format('N');
    } catch (\Exception $e) {
        $matchDayOfWeek = '';
    }

    $summary = [
        'total_meetings' => 0,
        'finished_meetings' => 0,
        'under_25' => 0,
        'over_05' => 0,
        'over_15' => 0,
        'over_25' => 0,
    ];

    if ($homeTeam === '' || $awayTeam === '' || $kickoffTime === '' || $matchDayOfWeek === '') {
        return $summary;
    }

    foreach ($allMatches as $match) {
        $candidateHome = trim((string)($match['home_team'] ?? ''));
        $candidateAway = trim((string)($match['away_team'] ?? ''));
        $candidateDatetime = (string)($match['match_time'] ?? '');
        $candidateTime = strlen($candidateDatetime) >= 16 ? substr($candidateDatetime, 11, 5) : '';

        try {
            $candidateDate = new DateTime($candidateDatetime);
            $candidateDayOfWeek = $candidateDate->format('N');
        } catch (\Exception $e) {
            $candidateDayOfWeek = '';
        }

        $sameOrder = strcasecmp($candidateHome, $homeTeam) === 0 && strcasecmp($candidateAway, $awayTeam) === 0;
        $reverseOrder = strcasecmp($candidateHome, $awayTeam) === 0 && strcasecmp($candidateAway, $homeTeam) === 0;
        if ((!$sameOrder && !$reverseOrder) || $candidateTime !== $kickoffTime || $candidateDayOfWeek !== $matchDayOfWeek) {
            continue;
        }

        if (($match['ft_home'] ?? null) === null || ($match['ft_away'] ?? null) === null) {
            continue;
        }

        $summary['total_meetings']++;
        $summary['finished_meetings']++;
        $totalGoals = (int)$match['ft_home'] + (int)$match['ft_away'];
        if ($totalGoals < 3) {
            $summary['under_25']++;
        }
        if ($totalGoals > 0) {
            $summary['over_05']++;
        }
        if ($totalGoals > 1) {
            $summary['over_15']++;
        }
        if ($totalGoals > 2) {
            $summary['over_25']++;
        }
    }

    return $summary;
}


$csvPath = __DIR__ . '/matches.csv';

// ── Single-pass CSV scan ───────────────────────────────────────────────────
// Filter utama, statistik H2H, HT-check, daftar liga/tim, tanggal berdata,
// dan statistik hari ini dikumpulkan dalam SATU kali baca CSV tanpa
// menahan seluruh isi file di memori.

$h2hStats = [
    'active' => $h2hHomeFilter !== '' && $h2hAwayFilter !== '',
    'home' => $h2hHomeFilter,
    'away' => $h2hAwayFilter,
    'total_meetings' => 0,
    'finished_meetings' => 0,
    'over_05' => 0,
    'over_15' => 0,
    'over_25' => 0,
    'w1' => 0,
    'x' => 0,
    'w2' => 0,
];
$h2hMatches = [];

$htCheckHome     = isset($_GET['ht_home']) && $_GET['ht_home'] !== '' ? (int)$_GET['ht_home'] : null;
$htCheckAway     = isset($_GET['ht_away']) && $_GET['ht_away'] !== '' ? (int)$_GET['ht_away'] : null;
$htCheckTeamHome = trim((string)($_GET['ht_team_home'] ?? ''));
$htCheckTeamAway = trim((string)($_GET['ht_team_away'] ?? ''));
$htCheckActive   = $htCheckHome !== null && $htCheckAway !== null && $htCheckTeamHome !== '' && $htCheckTeamAway !== '';

$htCheckResult = [
    'total'      => 0,
    'shg_over05' => 0,
    'shg_pct'    => null,
    'matches'    => [],
];

$filteredMatches = [];
$leagues = [];
$teams = [];
$datesWithData = [];
$todayStr = date('Y-m-d');
$totalToday = 0;
$finishedToday = 0;
$pendingToday = 0;

$rowPassesFilters = function (array $match) use ($date_from, $date_to, $statusFilter, $timeFrom, $timeTo, $leagueFilter, $searchFilter, $homeTeamFilter, $awayTeamFilter) {
    if ($leagueFilter !== '' && ($match['league'] ?? '') !== $leagueFilter) {
        return false;
    }

    if ($searchFilter !== '') {
        $search = $searchFilter;
        $home = $match['home_team'] ?? '';
        $away = $match['away_team'] ?? '';
        if (stripos($home, $search) === false && stripos($away, $search) === false) {
            return false;
        }
    }

    if ($homeTeamFilter !== '') {
        $home = $match['home_team'] ?? '';
        if (stripos($home, $homeTeamFilter) === false) {
            return false;
        }
    }

    if ($awayTeamFilter !== '') {
        $away = $match['away_team'] ?? '';
        if (stripos($away, $awayTeamFilter) === false) {
            return false;
        }
    }

    $matchDatetime = (string)($match['match_time'] ?? '');
    $matchDate = substr($matchDatetime, 0, 10);
    if (!empty($date_from) && $matchDate < $date_from) {
        return false;
    }
    if (!empty($date_to) && $matchDate > $date_to) {
        return false;
    }

    // Status filter
    if ($statusFilter !== '') {
        $hasFt = $match['ft_home'] !== null && $match['ft_away'] !== null;
        if ($statusFilter === 'finished' && !$hasFt) return false;
        if ($statusFilter === 'upcoming' && $hasFt) return false;
    }

    // Time filter (HH:MM from match_time column, e.g. "2025-10-15 13:30:00")
    if ($timeFrom !== '' || $timeTo !== '') {
        $matchTime = strlen($matchDatetime) >= 16 ? substr($matchDatetime, 11, 5) : '';
        if ($matchTime !== '') {
            $tf = $timeFrom !== '' ? $timeFrom : '00:00';
            $tt = $timeTo   !== '' ? $timeTo   : '23:59';
            if ($tf <= $tt) {
                if ($matchTime < $tf || $matchTime > $tt) return false;
            } else {
                // overnight range
                if ($matchTime < $tf && $matchTime > $tt) return false;
            }
        }
    }

    return true;
};

if (is_readable($csvPath) && ($handle = fopen($csvPath, 'r')) !== false) {
    $headers = fgetcsv($handle);
    if (is_array($headers)) {
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($headers)) {
                continue;
            }

            $match = array_combine($headers, $row);
            if ($match === false) {
                continue;
            }

            foreach (['id', 'fh_home', 'fh_away', 'ft_home', 'ft_away'] as $intField) {
                if (isset($match[$intField]) && $match[$intField] !== '') {
                    $match[$intField] = is_numeric($match[$intField]) ? (int)$match[$intField] : null;
                } else {
                    $match[$intField] = null;
                }
            }

            $home = trim((string)($match['home_team'] ?? ''));
            $away = trim((string)($match['away_team'] ?? ''));
            $league = trim((string)($match['league'] ?? ''));
            $matchDatetime = (string)($match['match_time'] ?? '');
            $matchDate = substr($matchDatetime, 0, 10);

            if ($league !== '') $leagues[$league] = true;
            if ($home !== '') $teams[$home] = true;
            if ($away !== '') $teams[$away] = true;
            if ($matchDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $matchDate)) {
                $datesWithData[$matchDate] = true;
            }

            if ($matchDate === $todayStr) {
                $totalToday++;
                if ($match['ft_home'] !== null && $match['ft_away'] !== null) $finishedToday++;
                else $pendingToday++;
            }

            if ($rowPassesFilters($match)) {
                $filteredMatches[] = $match;
            }

            // H2H Over Summary (mengikuti filter liga/tanggal/jam/status yang aktif)
            if ($h2hStats['active']) {
                $h2hOk = true;
                if ($leagueFilter !== '' && ($match['league'] ?? '') !== $leagueFilter) $h2hOk = false;
                if ($h2hOk && !empty($date_from) && $matchDate < $date_from) $h2hOk = false;
                if ($h2hOk && !empty($date_to) && $matchDate > $date_to) $h2hOk = false;
                if ($h2hOk && ($timeFrom !== '' || $timeTo !== '')) {
                    $matchTime = strlen($matchDatetime) >= 16 ? substr($matchDatetime, 11, 5) : '';
                    if ($matchTime !== '') {
                        $tf = $timeFrom !== '' ? $timeFrom : '00:00';
                        $tt = $timeTo   !== '' ? $timeTo   : '23:59';
                        if ($tf <= $tt) {
                            if ($matchTime < $tf || $matchTime > $tt) $h2hOk = false;
                        } else {
                            if ($matchTime < $tf && $matchTime > $tt) $h2hOk = false;
                        }
                    }
                }
                if ($h2hOk && $statusFilter !== '') {
                    $hasFt = $match['ft_home'] !== null && $match['ft_away'] !== null;
                    if ($statusFilter === 'finished' && !$hasFt) $h2hOk = false;
                    if ($statusFilter === 'upcoming' && $hasFt) $h2hOk = false;
                }
                if ($h2hOk) {
                    $sameOrder = strcasecmp($home, $h2hHomeFilter) === 0 && strcasecmp($away, $h2hAwayFilter) === 0;
                    $reverseOrder = strcasecmp($home, $h2hAwayFilter) === 0 && strcasecmp($away, $h2hHomeFilter) === 0;
                    if ($sameOrder || $reverseOrder) {
                        $h2hStats['total_meetings']++;
                        $h2hMatches[] = $match;
                        if ($match['ft_home'] !== null && $match['ft_away'] !== null) {
                            $h2hStats['finished_meetings']++;
                            $ftHome = (int)$match['ft_home'];
                            $ftAway = (int)$match['ft_away'];
                            $totalGoals = $ftHome + $ftAway;

                            // W1/X/W2 dihitung relatif terhadap urutan tim yang dipilih (Home filter = W1, Away filter = W2).
                            $team1Goals = strcasecmp($home, $h2hHomeFilter) === 0 ? $ftHome : $ftAway;
                            $team2Goals = strcasecmp($away, $h2hAwayFilter) === 0 ? $ftAway : $ftHome;
                            if ($team1Goals > $team2Goals) {
                                $h2hStats['w1']++;
                            } elseif ($team1Goals < $team2Goals) {
                                $h2hStats['w2']++;
                            } else {
                                $h2hStats['x']++;
                            }

                            if ($totalGoals > 0) $h2hStats['over_05']++;
                            if ($totalGoals > 1) $h2hStats['over_15']++;
                            if ($totalGoals > 2) $h2hStats['over_25']++;
                        }
                    }
                }
            }

            // HT-check: H2H dengan skor HT sama, lintas semua tanggal
            if ($htCheckActive
                && $match['fh_home'] !== null && $match['fh_away'] !== null
                && $match['ft_home'] !== null && $match['ft_away'] !== null) {
                $sameTeam    = strcasecmp($home, $htCheckTeamHome) === 0 && strcasecmp($away, $htCheckTeamAway) === 0;
                $reverseTeam = strcasecmp($home, $htCheckTeamAway) === 0 && strcasecmp($away, $htCheckTeamHome) === 0;
                if ($sameTeam || $reverseTeam) {
                    $htHomeNorm = $sameTeam ? (int)$match['fh_home'] : (int)$match['fh_away'];
                    $htAwayNorm = $sameTeam ? (int)$match['fh_away'] : (int)$match['fh_home'];
                    if ($htHomeNorm === $htCheckHome && $htAwayNorm === $htCheckAway) {
                        $htCheckResult['total']++;
                        $shGoals = max(0, ((int)$match['ft_home'] - (int)$match['fh_home']) + ((int)$match['ft_away'] - (int)$match['fh_away']));
                        if ($shGoals > 0) {
                            $htCheckResult['shg_over05']++;
                        }
                        $htCheckResult['matches'][] = array_merge($match, ['_sh_goals' => $shGoals]);
                    }
                }
            }
        }
    }
    fclose($handle);
}

usort($filteredMatches, function ($left, $right) use ($sort, $order) {
    $a = $left[$sort] ?? '';
    $b = $right[$sort] ?? '';

    if ($sort === 'match_time') {
        $a = strtotime((string)$a) ?: 0;
        $b = strtotime((string)$b) ?: 0;
    } else {
        $a = mb_strtolower((string)$a);
        $b = mb_strtolower((string)$b);
    }

    if ($a === $b) {
        return 0;
    }

    $result = ($a < $b) ? -1 : 1;
    return $order === 'asc' ? $result : -$result;
});

$total = count($filteredMatches);
$totalPages = max(1, (int)ceil($total / $perPage));
$p = min($p, $totalPages);
$offset = ($p - 1) * $perPage;
$pagedMatches = array_slice($filteredMatches, $offset, $perPage);

// Daftar liga & tim untuk dropdown/autocomplete (dikumpulkan saat scan CSV)
$leagues = array_keys($leagues);
sort($leagues);
$teams = array_keys($teams);
sort($teams);

if (!empty($h2hMatches)) {
    usort($h2hMatches, function ($left, $right) {
        $a = strtotime((string)($left['match_time'] ?? '')) ?: 0;
        $b = strtotime((string)($right['match_time'] ?? '')) ?: 0;
        return $b <=> $a;
    });
}

$h2hFinishedDenominator = max(1, $h2hStats['finished_meetings']);
$h2hPctOver05 = $h2hStats['finished_meetings'] > 0 ? round(($h2hStats['over_05'] / $h2hFinishedDenominator) * 100, 1) : 0;
$h2hPctOver15 = $h2hStats['finished_meetings'] > 0 ? round(($h2hStats['over_15'] / $h2hFinishedDenominator) * 100, 1) : 0;
$h2hPctOver25 = $h2hStats['finished_meetings'] > 0 ? round(($h2hStats['over_25'] / $h2hFinishedDenominator) * 100, 1) : 0;

// ── HT Score Input → H2H 2H Over 0.5 checker (data dikumpulkan saat scan) ──
if ($htCheckActive) {
    if ($htCheckResult['total'] > 0) {
        $htCheckResult['shg_pct'] = round(($htCheckResult['shg_over05'] / $htCheckResult['total']) * 100);
    }

    // Sort newest first
    usort($htCheckResult['matches'], function ($a, $b) {
        return (strtotime((string)($b['match_time'] ?? '')) ?: 0) <=> (strtotime((string)($a['match_time'] ?? '')) ?: 0);
    });
}

// Build date pagination: tanggal berdata dikumpulkan saat scan CSV
ksort($datesWithData);
// Group by year-month
$datesByMonth = [];
foreach (array_keys($datesWithData) as $d) {
    $ym = substr($d, 0, 7);
    $datesByMonth[$ym][] = $d;
}
// Determine which month to display: prefer month of current date_from, fallback to latest month
$activeDateYm = substr($date_from, 0, 7);
if (!isset($datesByMonth[$activeDateYm])) {
    $activeDateYm = array_key_last($datesByMonth) ?? '';
}
$monthKeys = array_keys($datesByMonth);
?>

<div class="p-4 md:p-8 space-y-6 page-fade-in">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <?php // Statistik hari ini sudah dihitung saat scan CSV ($totalToday, $finishedToday, $pendingToday) ?>
    
    <!-- Quick Stats Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4">
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Hari Ini</p>
            <p class="mt-2 text-2xl font-black text-slate-900"><?php echo $totalToday; ?></p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Selesai</p>
            <p class="mt-2 text-2xl font-black text-emerald-600"><?php echo $finishedToday; ?></p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Pending</p>
            <p class="mt-2 text-2xl font-black text-amber-600"><?php echo $pendingToday; ?></p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Hasil Filter</p>
            <p class="mt-2 text-2xl font-black text-blue-600"><?php echo number_format($total); ?></p>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="bg-white rounded-2xl shadow-md border-0 p-5 md:p-6 transition-all">
        <form id="matches-filter-form" method="GET" class="space-y-5">
            <input type="hidden" name="page" value="matches">
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-12 gap-5">
                <!-- Search -->
                <div class="lg:col-span-3 space-y-2">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        Cari Tim
                    </label>
                    <div class="relative group">
                        <input type="text" 
                               id="teamSearch" 
                               name="search" 
                               value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" 
                               class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm transition-all placeholder:text-slate-400 font-medium"
                               placeholder="Ketik nama tim..." 
                               autocomplete="off">
                        <div id="autocompleteResults" class="hidden absolute top-full left-0 right-0 mt-2 bg-white border border-slate-100 rounded-xl shadow-xl z-50 max-h-60 overflow-y-auto divide-y divide-slate-50"></div>
                    </div>
                </div>

                <!-- League Filter -->
                <div class="lg:col-span-2 space-y-2">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                        Liga
                    </label>
                    <div class="relative">
                        <select id="matches-league-select" name="league" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm appearance-none transition-all cursor-pointer font-medium text-slate-700">
                            <option value="">Semua Liga</option>
                            <?php foreach ($leagues as $league): ?>
                                <option value="<?php echo htmlspecialchars($league); ?>" <?php echo ($_GET['league'] ?? '') == $league ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($league); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </div>
                    </div>
                </div>

                <!-- Date Range -->
                <div class="lg:col-span-3 space-y-2">
                    <label for="matches-date-range" class="text-xs font-bold text-slate-500 uppercase tracking-wider block">Rentang Tanggal</label>
                    <input id="matches-date-range" type="text" name="date_range" value="<?php echo htmlspecialchars($dateRange); ?>"
                           class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm transition-all font-medium text-slate-700 h-[46px]"
                           placeholder="Pilih rentang tanggal...">
                </div>

                <!-- Time Range -->
                <div class="lg:col-span-2 grid grid-cols-2 gap-3">
                    <div class="space-y-2">
                        <label class="text-xs font-bold text-slate-500 uppercase tracking-wider block">Jam</label>
                        <input type="text" name="time_from" value="<?php echo htmlspecialchars($timeFrom); ?>" placeholder="00:00" maxlength="5"
                               pattern="([01][0-9]|2[0-3]):[0-5][0-9]" title="Format jam HH:MM, contoh 08:30"
                               class="w-full px-3 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm transition-all font-medium text-slate-700 h-[46px]">
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs font-bold text-slate-500 uppercase tracking-wider block">-</label>
                        <input type="text" name="time_to" value="<?php echo htmlspecialchars($timeTo); ?>" placeholder="23:59" maxlength="5"
                               pattern="([01][0-9]|2[0-3]):[0-5][0-9]" title="Format jam HH:MM, contoh 21:00"
                               class="w-full px-3 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm transition-all font-medium text-slate-700 h-[46px]">
                    </div>
                </div>

                <!-- Status Filter -->
                <div class="lg:col-span-2 space-y-2">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Status
                    </label>
                    <div class="relative">
                        <select id="matches-status-select" name="status" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm appearance-none transition-all cursor-pointer font-medium text-slate-700">
                            <option value="" <?php echo $statusFilter === '' ? 'selected' : ''; ?>>Semua</option>
                            <option value="finished" <?php echo $statusFilter === 'finished' ? 'selected' : ''; ?>>Selesai</option>
                            <option value="upcoming" <?php echo $statusFilter === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                        </select>
                        <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="space-y-2">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">H2H Home Team</label>
                    <div class="relative group">
                        <input type="text"
                               id="homeTeamSearch"
                               name="h2h_home"
                               value="<?php echo htmlspecialchars($h2hHomeFilter, ENT_QUOTES, 'UTF-8'); ?>"
                               class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm transition-all placeholder:text-slate-400 font-medium"
                               placeholder="Pilih tim home untuk H2H..."
                               autocomplete="off">
                        <div id="homeAutocompleteResults" class="hidden absolute top-full left-0 right-0 mt-2 bg-white border border-slate-100 rounded-xl shadow-xl z-50 max-h-60 overflow-y-auto divide-y divide-slate-50"></div>
                    </div>
                </div>
                <div class="space-y-2">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">H2H Away Team</label>
                    <div class="relative group">
                        <input type="text"
                               id="awayTeamSearch"
                               name="h2h_away"
                               value="<?php echo htmlspecialchars($h2hAwayFilter, ENT_QUOTES, 'UTF-8'); ?>"
                               class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-blue-100 focus:border-blue-500 text-sm transition-all placeholder:text-slate-400 font-medium"
                               placeholder="Pilih tim away untuk H2H..."
                               autocomplete="off">
                        <div id="awayAutocompleteResults" class="hidden absolute top-full left-0 right-0 mt-2 bg-white border border-slate-100 rounded-xl shadow-xl z-50 max-h-60 overflow-y-auto divide-y divide-slate-50"></div>
                    </div>
                </div>
            </div>
            <div class="flex justify-end">
                <button type="button"
                        id="h2hSwapBtn"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-slate-200 bg-slate-50 text-slate-700 text-xs font-bold uppercase tracking-wider hover:bg-slate-100 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7h14m0 0l-3-3m3 3l-3 3M20 17H6m0 0l3-3m-3 3l3 3"/>
                    </svg>
                    Swap Home/Away
                </button>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center gap-3 pt-2 flex-wrap">
                <input type="hidden" name="export" value="">
                <button type="submit" id="filterBtn"
                    class="flex-1 md:flex-none bg-slate-900 text-white px-8 py-3 rounded-xl font-bold text-sm hover:bg-slate-800 transition-all shadow-lg active:scale-95 flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                    Terapkan
                </button>
                <button type="button" id="exportBtn"
                    class="px-5 py-3 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 transition-all font-bold text-sm flex items-center gap-2 shadow-lg active:scale-95">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Export
                </button>
                <a href="index.php?page=matches" class="px-5 py-3 bg-slate-100 text-slate-600 rounded-xl hover:bg-slate-200 hover:text-slate-800 transition-all font-bold text-sm flex items-center gap-2">
                    <span>Reset</span>
                </a>
                <span class="hidden md:block w-px h-8 bg-slate-200"></span>
                <?php
                $quickYesterday = date('Y-m-d', strtotime('-1 day'));
                $quickWeekAgo   = date('Y-m-d', strtotime('-6 days'));
                $quickRanges = [
                    ['label' => 'Hari Ini',  'from' => $todayStr,       'to' => $todayStr],
                    ['label' => 'Kemarin',   'from' => $quickYesterday, 'to' => $quickYesterday],
                    ['label' => '7 Hari',    'from' => $quickWeekAgo,   'to' => $todayStr],
                ];
                foreach ($quickRanges as $qr):
                    $isActiveRange = ($date_from === $qr['from'] && $date_to === $qr['to']);
                ?>
                <a href="<?php echo htmlspecialchars(matchesBuildQuery(['date_from' => $qr['from'], 'date_to' => $qr['to'], 'p' => '1'])); ?>"
                   class="px-4 py-3 rounded-xl font-bold text-sm transition-all <?php echo $isActiveRange ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20' : 'bg-blue-50 text-blue-700 hover:bg-blue-100'; ?>">
                    <?php echo $qr['label']; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </form>
    </div>

    <?php if ($h2hStats['active']): ?>
    <div class="bg-white rounded-2xl shadow-md border-0 p-5 md:p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-700">H2H Over Summary</h2>
            <?php if ($h2hStats['active']): ?>
                <span class="text-xs font-semibold text-slate-500">
                    <?php echo htmlspecialchars($h2hStats['home'], ENT_QUOTES, 'UTF-8'); ?> vs <?php echo htmlspecialchars($h2hStats['away'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if ($h2hStats['active']): ?>
            <div class="mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p class="text-[11px] uppercase tracking-wider font-bold text-slate-500">Total Pertemuan</p>
                    <p class="mt-1 text-2xl font-black text-slate-900"><?php echo (int)$h2hStats['total_meetings']; ?></p>
                    <p class="mt-1 text-xs font-bold text-slate-500 uppercase tracking-wider">
                        W1 <?php echo (int)$h2hStats['w1']; ?> | X <?php echo (int)$h2hStats['x']; ?> | W2 <?php echo (int)$h2hStats['w2']; ?>
                    </p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p class="text-[11px] uppercase tracking-wider font-bold text-slate-500">FT Available</p>
                    <p class="mt-1 text-2xl font-black text-slate-900"><?php echo (int)$h2hStats['finished_meetings']; ?></p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                    <p class="text-[11px] uppercase tracking-wider font-bold text-emerald-700">Over 0.5</p>
                    <p class="mt-1 text-xl font-black text-emerald-800"><?php echo (int)$h2hStats['over_05']; ?> <span class="text-sm font-bold text-emerald-700">/ <?php echo (int)$h2hStats['finished_meetings']; ?></span></p>
                    <p class="text-xs font-semibold text-emerald-700"><?php echo number_format($h2hPctOver05, 1); ?>%</p>
                </div>
                <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3">
                    <p class="text-[11px] uppercase tracking-wider font-bold text-blue-700">Over 1.5</p>
                    <p class="mt-1 text-xl font-black text-blue-800"><?php echo (int)$h2hStats['over_15']; ?> <span class="text-sm font-bold text-blue-700">/ <?php echo (int)$h2hStats['finished_meetings']; ?></span></p>
                    <p class="text-xs font-semibold text-blue-700"><?php echo number_format($h2hPctOver15, 1); ?>%</p>
                </div>
                <div class="rounded-xl border border-purple-200 bg-purple-50 px-4 py-3">
                    <p class="text-[11px] uppercase tracking-wider font-bold text-purple-700">Over 2.5</p>
                    <p class="mt-1 text-xl font-black text-purple-800"><?php echo (int)$h2hStats['over_25']; ?> <span class="text-sm font-bold text-purple-700">/ <?php echo (int)$h2hStats['finished_meetings']; ?></span></p>
                    <p class="text-xs font-semibold text-purple-700"><?php echo number_format($h2hPctOver25, 1); ?>%</p>
                </div>
            </div>

            <div class="mt-4">
                <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">H2H Match List</h3>
                <?php if (!empty($h2hMatches)): ?>
                    <div class="rounded-xl border border-slate-200 overflow-hidden">
                        <div class="max-h-80 overflow-auto">
                            <table class="w-full text-sm border-collapse">
                                <thead class="bg-slate-100 text-slate-600 uppercase text-[11px] tracking-wider">
                                    <tr>
                                        <th class="px-3 py-2 text-left border border-slate-200">Waktu</th>
                                        <th class="px-3 py-2 text-left border border-slate-200">Match</th>
                                        <th class="px-3 py-2 text-center border border-slate-200">Score HT</th>
                                        <th class="px-3 py-2 text-center border border-slate-200">Score FT</th>
                                        <th class="px-3 py-2 text-center border border-slate-200">Goals</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($h2hMatches as $h2hMatch): ?>
                                        <?php
                                            $mTime = (string)($h2hMatch['match_time'] ?? '');
                                            try { $h2hDate = new DateTime($mTime); } catch (\Exception $e) { $h2hDate = null; }
                                            $ftHome = $h2hMatch['ft_home'];
                                            $ftAway = $h2hMatch['ft_away'];
                                            $hasFt = $ftHome !== null && $ftAway !== null;
                                            $goals = $hasFt ? ((int)$ftHome + (int)$ftAway) : null;
                                            $fhHome = $h2hMatch['fh_home'] ?? null;
                                            $fhAway = $h2hMatch['fh_away'] ?? null;
                                            $hasHt = $fhHome !== null && $fhAway !== null;
                                        ?>
                                        <tr class="hover:bg-slate-50">
                                            <td class="px-3 py-2 text-slate-600 whitespace-nowrap border border-slate-200">
                                                <?php echo $h2hDate ? $h2hDate->format('d M Y H:i') : htmlspecialchars($mTime, ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td class="px-3 py-2 text-slate-800 font-semibold border border-slate-200">
                                                <?php echo htmlspecialchars((string)($h2hMatch['home_team'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                                                <span class="text-slate-400 font-normal">vs</span>
                                                <?php echo htmlspecialchars((string)($h2hMatch['away_team'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td class="px-3 py-2 text-center border border-slate-200">
                                                <?php if ($hasHt): ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-lg bg-sky-50 text-sky-700 font-bold border border-sky-200">
                                                        <?php echo (int)$fhHome; ?> - <?php echo (int)$fhAway; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-lg bg-slate-50 text-slate-400 font-bold border border-slate-200">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-3 py-2 text-center border border-slate-200">
                                                <?php if ($hasFt): ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-lg bg-emerald-50 text-emerald-700 font-bold border border-emerald-200">
                                                        <?php echo (int)$ftHome; ?> - <?php echo (int)$ftAway; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-lg bg-amber-50 text-amber-700 font-bold border border-amber-200">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-3 py-2 text-center text-slate-700 font-bold border border-slate-200">
                                                <?php echo $goals !== null ? (int)$goals : '-'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-sm font-medium text-slate-500">
                        Tidak ada match H2H untuk pasangan tim ini pada filter yang dipilih.
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm font-medium text-slate-500">
                Isi kedua input H2H (Home dan Away) lalu klik <strong>Terapkan</strong> untuk melihat statistik Over 0.5 / 1.5 / 2.5.
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Panel: Cek Skor HT → Prediksi 2H Over 0.5 ── -->
    <?php endif; ?>
    <div class="bg-white rounded-2xl shadow-md border-0 p-5 md:p-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-8 h-8 rounded-lg bg-orange-100 flex items-center justify-center shrink-0">
                <svg class="w-4 h-4 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
            </div>
            <div>
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-700">Cek Skor Babak Pertama → 2H Over 0.5</h2>
                <p class="text-xs text-slate-400 mt-0.5">Input tim dan skor HT, lihat dari H2H berapa % ada gol di babak kedua</p>
            </div>
        </div>

        <!-- Form input: tim + skor HT -->
        <div class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <!-- Tim Home -->
                <div class="space-y-1.5">
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Tim Home</label>
                    <div class="relative">
                        <input type="text"
                               id="htTeamHomeInput"
                               name="ht_team_home"
                               form="matches-filter-form"
                               value="<?php echo htmlspecialchars($htCheckTeamHome, ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="Ketik nama tim home..."
                               autocomplete="off"
                               class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-orange-100 focus:border-orange-400 text-sm font-medium transition-all placeholder:text-slate-400">
                        <div id="htHomeAutocomplete" class="hidden absolute top-full left-0 right-0 mt-1 bg-white border border-slate-100 rounded-xl shadow-xl z-50 max-h-52 overflow-y-auto divide-y divide-slate-50"></div>
                    </div>
                </div>
                <!-- Tim Away -->
                <div class="space-y-1.5">
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Tim Away</label>
                    <div class="relative">
                        <input type="text"
                               id="htTeamAwayInput"
                               name="ht_team_away"
                               form="matches-filter-form"
                               value="<?php echo htmlspecialchars($htCheckTeamAway, ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="Ketik nama tim away..."
                               autocomplete="off"
                               class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-4 focus:ring-orange-100 focus:border-orange-400 text-sm font-medium transition-all placeholder:text-slate-400">
                        <div id="htAwayAutocomplete" class="hidden absolute top-full left-0 right-0 mt-1 bg-white border border-slate-100 rounded-xl shadow-xl z-50 max-h-52 overflow-y-auto divide-y divide-slate-50"></div>
                    </div>
                </div>
            </div>

            <!-- Skor HT -->
            <div class="flex flex-wrap items-end gap-3">
                <div class="space-y-1.5">
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Skor HT Home</label>
                    <input type="number" name="ht_home" min="0" max="20" form="matches-filter-form"
                           value="<?php echo $htCheckHome !== null ? $htCheckHome : ''; ?>"
                           placeholder="0"
                           class="w-20 px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-center text-xl font-black text-slate-900 focus:ring-4 focus:ring-orange-100 focus:border-orange-400 transition-all">
                </div>
                <div class="pb-2.5 text-slate-300 font-black text-2xl select-none">–</div>
                <div class="space-y-1.5">
                    <label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Skor HT Away</label>
                    <input type="number" name="ht_away" min="0" max="20" form="matches-filter-form"
                           value="<?php echo $htCheckAway !== null ? $htCheckAway : ''; ?>"
                           placeholder="0"
                           class="w-20 px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-center text-xl font-black text-slate-900 focus:ring-4 focus:ring-orange-100 focus:border-orange-400 transition-all">
                </div>
                <button type="submit" form="matches-filter-form" style="background: #f97316 !important; color: #ffffff !important;"
                        class="px-6 py-2.5 bg-orange-500 hover:bg-orange-600 !text-white rounded-xl font-bold text-sm transition-all shadow-md active:scale-95 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    Cek
                </button>
                <?php if ($htCheckActive): ?>
                    <a href="<?php echo htmlspecialchars(matchesBuildQuery(['ht_team_home' => '', 'ht_team_away' => '', 'ht_home' => '', 'ht_away' => ''])); ?>"
                       class="px-4 py-2.5 bg-slate-100 text-slate-600 rounded-xl font-bold text-sm hover:bg-slate-200 transition-all">
                        Reset
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Hasil -->
        <?php if ($htCheckActive): ?>
            <div class="mt-5 pt-5 border-t border-slate-100">
                <?php if ($htCheckResult['total'] === 0): ?>
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-sm font-medium text-slate-500">
                        Tidak ada data H2H
                        <strong class="text-slate-700"><?php echo htmlspecialchars($htCheckTeamHome); ?> vs <?php echo htmlspecialchars($htCheckTeamAway); ?></strong>
                        dengan skor HT <strong class="text-slate-700"><?php echo $htCheckHome; ?>–<?php echo $htCheckAway; ?></strong>.
                    </div>
                <?php else: ?>
                    <?php
                        $pct = $htCheckResult['shg_pct'];
                        if ($pct >= 80)     { $pctBg = 'bg-emerald-600'; $pctText = 'text-white'; $pctBorder = 'border-emerald-600'; $label = 'Sangat Tinggi'; $labelColor = 'text-emerald-100'; }
                        elseif ($pct >= 60) { $pctBg = 'bg-emerald-100'; $pctText = 'text-emerald-800'; $pctBorder = 'border-emerald-300'; $label = 'Tinggi'; $labelColor = 'text-emerald-700'; }
                        elseif ($pct >= 40) { $pctBg = 'bg-amber-100'; $pctText = 'text-amber-800'; $pctBorder = 'border-amber-300'; $label = 'Sedang'; $labelColor = 'text-amber-700'; }
                        else               { $pctBg = 'bg-rose-100'; $pctText = 'text-rose-800'; $pctBorder = 'border-rose-300'; $label = 'Rendah'; $labelColor = 'text-rose-700'; }
                    ?>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <p class="text-[11px] uppercase tracking-wider font-bold text-slate-500">HT Dicek</p>
                            <p class="mt-1 text-2xl font-black text-slate-900"><?php echo $htCheckHome; ?> – <?php echo $htCheckAway; ?></p>
                            <p class="text-[11px] text-slate-400 mt-0.5"><?php echo htmlspecialchars($htCheckTeamHome); ?> vs <?php echo htmlspecialchars($htCheckTeamAway); ?></p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <p class="text-[11px] uppercase tracking-wider font-bold text-slate-500">Match Ditemukan</p>
                            <p class="mt-1 text-2xl font-black text-slate-900"><?php echo $htCheckResult['total']; ?></p>
                            <p class="text-[11px] text-slate-400 mt-0.5">H2H dengan HT sama</p>
                        </div>
                        <div class="rounded-xl border <?php echo $pctBorder; ?> <?php echo $pctBg; ?> px-4 py-3">
                            <p class="text-[11px] uppercase tracking-wider font-bold <?php echo $labelColor; ?>">2H Over 0.5</p>
                            <p class="mt-1 text-2xl font-black <?php echo $pctText; ?>"><?php echo $pct; ?>%</p>
                            <p class="text-[11px] font-bold mt-0.5 <?php echo $labelColor; ?>">
                                <?php echo $htCheckResult['shg_over05']; ?>/<?php echo $htCheckResult['total']; ?> — <?php echo $label; ?>
                            </p>
                        </div>
                    </div>

                    <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-2">
                        Detail Match — HT <?php echo $htCheckHome; ?>–<?php echo $htCheckAway; ?>
                    </h3>
                    <div class="rounded-xl border border-slate-200 overflow-hidden">
                        <div class="max-h-72 overflow-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-100 text-slate-600 uppercase text-[11px] tracking-wider sticky top-0">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Waktu</th>
                                        <th class="px-3 py-2 text-left">Match</th>
                                        <th class="px-3 py-2 text-center">HT</th>
                                        <th class="px-3 py-2 text-center">FT</th>
                                        <th class="px-3 py-2 text-center">Gol 2H</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($htCheckResult['matches'] as $hm): ?>
                                        <?php
                                            $hmTime = (string)($hm['match_time'] ?? '');
                                            try { $hmDate = new DateTime($hmTime); } catch (\Exception $e) { $hmDate = null; }
                                            $hmShGoals = (int)$hm['_sh_goals'];
                                        ?>
                                        <tr class="hover:bg-slate-50 <?php echo $hmShGoals > 0 ? 'bg-emerald-50/40' : ''; ?>">
                                            <td class="px-3 py-2 text-slate-500 whitespace-nowrap text-xs">
                                                <?php echo $hmDate ? $hmDate->format('d M Y H:i') : htmlspecialchars($hmTime, ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td class="px-3 py-2 text-slate-800 font-semibold text-xs">
                                                <?php echo htmlspecialchars((string)($hm['home_team'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                                                <span class="text-slate-400 font-normal">vs</span>
                                                <?php echo htmlspecialchars((string)($hm['away_team'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-orange-50 border border-orange-200 text-orange-700 font-bold text-xs">
                                                    <?php echo (int)$hm['fh_home']; ?>–<?php echo (int)$hm['fh_away']; ?>
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-700 font-bold text-xs">
                                                    <?php echo (int)$hm['ft_home']; ?>–<?php echo (int)$hm['ft_away']; ?>
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-md font-black text-sm
                                                    <?php echo $hmShGoals > 0 ? 'bg-emerald-100 border border-emerald-300 text-emerald-700' : 'bg-slate-100 border border-slate-200 text-slate-500'; ?>">
                                                    <?php echo $hmShGoals; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Date Pagination -->
    <?php if (!empty($datesByMonth)): ?>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <!-- Month Tabs -->
        <div class="flex items-center gap-0 border-b border-slate-100 overflow-x-auto scrollbar-none" id="monthTabBar">
            <?php foreach ($monthKeys as $ym): ?>
            <?php
                [$yr, $mo] = explode('-', $ym);
                $monthLabel = DateTime::createFromFormat('Y-m', $ym)->format('M Y');
                $isActiveTab = $ym === $activeDateYm;
            ?>
            <button type="button"
                onclick="showMonth('<?= htmlspecialchars($ym) ?>')"
                id="tab-<?= $ym ?>"
                class="month-tab shrink-0 px-4 py-3 min-h-[44px] text-xs font-bold uppercase tracking-wide transition-all border-b-2 whitespace-nowrap
                    <?= $isActiveTab ? 'border-blue-600 text-blue-600 bg-blue-50' : 'border-transparent text-slate-400 hover:text-slate-700 hover:bg-slate-50' ?>">
                <?= htmlspecialchars($monthLabel) ?>
            </button>
            <?php endforeach; ?>
        </div>
        <!-- Date Buttons per Month -->
        <?php foreach ($datesByMonth as $ym => $days): ?>
        <div id="month-<?= $ym ?>" class="month-panel px-4 py-3 <?= $ym !== $activeDateYm ? 'hidden' : '' ?>">
            <div class="flex flex-wrap gap-1.5">
                <?php foreach ($days as $d): ?>
                <?php
                    $dayNum   = (int)substr($d, 8, 2);
                    $dayName  = (new DateTime($d))->format('D');
                    $isSun    = (new DateTime($d))->format('N') == 7;
                    $isSat    = (new DateTime($d))->format('N') == 6;
                    $isActive = ($d === $date_from && $d === $date_to);
                    // Build URL preserving current filters but overriding dates and resetting page
                    $params = ['page' => 'matches', 'date_from' => $d, 'date_to' => $d, 'p' => '1'];
                    foreach (['search','home_team','away_team','h2h_home','h2h_away','league','sort','order','per_page','time_from','time_to','status'] as $k) {
                        if (!empty($_GET[$k])) $params[$k] = $_GET[$k];
                    }
                    $href = 'index.php?' . http_build_query($params);
                    if ($isActive) {
                        $btnClass = 'bg-blue-600 text-white border-blue-600 shadow-md shadow-blue-600/20';
                    } elseif ($isSun) {
                        $btnClass = 'bg-rose-50 text-rose-600 border-rose-200 hover:bg-rose-100';
                    } elseif ($isSat) {
                        $btnClass = 'bg-amber-50 text-amber-700 border-amber-200 hover:bg-amber-100';
                    } else {
                        $btnClass = 'bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100 hover:border-slate-300';
                    }
                ?>
                <a href="<?= htmlspecialchars($href) ?>"
                   class="flex flex-col items-center px-2 py-2 rounded-lg border text-center min-w-[44px] min-h-[44px] justify-center transition-all <?= $btnClass ?>">
                    <span class="text-[9px] font-bold uppercase leading-none mb-0.5 opacity-70"><?= $dayName ?></span>
                    <span class="text-sm font-black leading-none"><?= $dayNum ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Results Area -->
    <div class="space-y-4">
        <?php if (count($pagedMatches) > 0): ?>
            
            <!-- Desktop Table View -->
            <div class="hidden md:block bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="overflow-x-auto">
                <style>
                    .matches-table th, .matches-table td { border: 1px solid #e2e8f0; }
                    .matches-table tr > :first-child { border-left: 0; }
                    .matches-table tr > :last-child { border-right: 0; }
                    .matches-table thead th { border-top: 0; }
                    .matches-table tbody tr:last-child td { border-bottom: 0; }
                </style>
                <table class="matches-table w-full border-collapse min-w-[1040px]">
                    <thead>
                        <tr class="bg-slate-50 text-slate-500">
                            <th class="px-4 py-2 text-left text-[10px] font-bold uppercase tracking-wider">
                                <a href="<?php echo htmlspecialchars(matchesBuildQuery(['sort' => 'match_time', 'order' => ($sort == 'match_time' && $order == 'desc') ? 'asc' : 'desc', 'p' => '1'])); ?>"
                                    class="flex items-center gap-1 hover:text-blue-600 transition-colors">
                                    Waktu
                                    <?php if ($sort == 'match_time'): ?>
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <?php if ($order == 'asc'): ?>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                                            <?php else: ?>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                            <?php endif; ?>
                                        </svg>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-4 py-2 text-center text-[10px] font-bold uppercase tracking-wider">
                                <a href="<?php echo htmlspecialchars(matchesBuildQuery(['sort' => 'home_team', 'order' => ($sort == 'home_team' && $order == 'asc') ? 'desc' : 'asc', 'p' => '1'])); ?>"
                                    class="flex items-center justify-center gap-1 hover:text-blue-600 transition-colors">
                                    Pertandingan
                                    <?php if ($sort == 'home_team' || $sort == 'away_team'): ?>
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <?php if ($order == 'asc'): ?>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                                            <?php else: ?>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                            <?php endif; ?>
                                        </svg>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider">Liga</th>
                            <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider">HT</th>
                            <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider">Skor</th>
                            <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider" title="Total gol FT (angka besar) & gol babak 2 (kecil)">Gol</th>
                            <th class="px-3 py-2 text-center text-[10px] font-bold uppercase tracking-wider" title="Hasil market: Over 1.5 / Over 2.5 / Both Teams To Score">Market</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagedMatches as $match):
                            try { $date = new DateTime($match['match_time']); } catch (\Exception $e) { $date = null; }
                            $hasFt = ($match['ft_home'] ?? '') !== '' && ($match['ft_away'] ?? '') !== '';
                        ?>
                            <tr class="hover:bg-blue-50/50 transition-all duration-200 group">
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <div class="w-9 h-9 rounded-lg <?php echo $hasFt ? 'bg-emerald-100 border border-emerald-200' : 'bg-amber-100 border border-amber-200'; ?> flex flex-col items-center justify-center">
                                            <span class="text-[8px] font-bold uppercase leading-none mb-0.5 <?php echo $hasFt ? 'text-emerald-600' : 'text-amber-600'; ?>"><?php echo $date ? $date->format('M') : '--'; ?></span>
                                            <span class="text-sm font-black <?php echo $hasFt ? 'text-emerald-700' : 'text-amber-700'; ?> leading-none"><?php echo $date ? $date->format('d') : '--'; ?></span>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-[10px] font-bold text-slate-400"><?php echo $date ? $date->format('Y') : '--'; ?></span>
                                            <span class="text-xs font-bold text-slate-700"><?php echo $date ? $date->format('H:i') : '--:--'; ?> <span class="text-slate-400 font-normal">WIB</span></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center justify-center gap-3">
                                        <div class="flex-1 text-right">
                                            <h3 class="text-xs font-bold text-slate-900 group-hover:text-blue-700 transition-colors line-clamp-1">
                                                <?php echo htmlspecialchars($match['home_team']); ?>
                                            </h3>
                                        </div>
                                        <div class="w-6 h-6 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center shrink-0">
                                            <span class="text-[8px] font-black text-slate-400">VS</span>
                                        </div>
                                        <div class="flex-1 text-left">
                                            <h3 class="text-xs font-bold text-slate-900 group-hover:text-blue-700 transition-colors line-clamp-1">
                                                <?php echo htmlspecialchars($match['away_team']); ?>
                                            </h3>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-center whitespace-nowrap">
                                    <span class="inline-block text-[8px] font-semibold text-slate-500 uppercase tracking-[0.1em] whitespace-nowrap"
                                          title="<?php echo htmlspecialchars($match['league']); ?>">
                                        <?php echo htmlspecialchars($match['league']); ?>
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <?php if ($match['fh_home'] !== null): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-amber-50 border border-amber-200 text-xs font-black text-amber-700">
                                            <?php echo $match['fh_home']; ?> : <?php echo $match['fh_away']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-300 font-bold text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <div class="inline-flex items-center gap-1.5 <?php echo $hasFt ? 'bg-emerald-50 border border-emerald-200' : 'bg-slate-50 border border-slate-200'; ?> px-2.5 py-0.5 rounded-md">
                                        <span class="text-sm font-black <?php echo $hasFt ? 'text-emerald-700' : 'text-slate-400'; ?>"><?php echo $match['ft_home'] !== null ? $match['ft_home'] : '-'; ?></span>
                                        <span class="<?php echo $hasFt ? 'text-emerald-300' : 'text-slate-300'; ?> font-bold text-xs">:</span>
                                        <span class="text-sm font-black <?php echo $hasFt ? 'text-emerald-700' : 'text-slate-400'; ?>"><?php echo $match['ft_away'] !== null ? $match['ft_away'] : '-'; ?></span>
                                    </div>
                                </td>
                                <?php $mk = matchesGoalMarkets($match); ?>
                                <td class="px-3 py-2 text-center">
                                    <?php if ($mk['has']): ?>
                                        <span class="text-sm font-black <?php echo $mk['o25'] ? 'text-emerald-700' : ($mk['total'] < 2 ? 'text-rose-600' : 'text-amber-600'); ?>"><?php echo $mk['total']; ?></span>
                                        <?php if ($mk['sh'] !== null): ?><div class="text-[9px] text-slate-400 leading-none mt-0.5">2H <?php echo $mk['sh']; ?></div><?php endif; ?>
                                    <?php else: ?><span class="text-slate-300 font-bold text-xs">-</span><?php endif; ?>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <?php if ($mk['has']): ?>
                                        <div class="flex items-center justify-center gap-1 flex-wrap">
                                            <?php
                                            echo matchesMarketBadge('O1.5', $mk['o15']);
                                            echo matchesMarketBadge('O2.5', $mk['o25']);
                                            echo matchesMarketBadge('BTTS', $mk['btts']);
                                            ?>
                                        </div>
                                    <?php else: ?><span class="text-slate-300 font-bold text-xs">-</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Mobile Card View -->
            <div class="md:hidden space-y-3">
                <?php foreach ($pagedMatches as $match):
                    try { $date = new DateTime($match['match_time']); } catch (\Exception $e) { $date = null; }
                    $hasFt = ($match['ft_home'] ?? '') !== '' && ($match['ft_away'] ?? '') !== '';
                ?>
                    <div class="bg-white rounded-2xl p-4 border-0 shadow-md relative overflow-hidden">
                        <?php if ($hasFt): ?>
                        <div class="absolute top-0 left-0 w-1.5 h-full bg-emerald-500"></div>
                        <?php else: ?>
                        <div class="absolute top-0 left-0 w-1.5 h-full bg-amber-500"></div>
                        <?php endif; ?>

                        <div class="flex items-center justify-between mb-3 pl-2">
                            <div class="flex items-center gap-2">
                                <div class="px-2 py-1 <?php echo $hasFt ? 'bg-emerald-100 border border-emerald-200' : 'bg-amber-100 border border-amber-200'; ?> rounded-lg">
                                    <span class="text-xs font-bold <?php echo $hasFt ? 'text-emerald-600' : 'text-amber-600'; ?>"><?php echo $date ? $date->format('d M') : '--'; ?></span>
                                </div>
                                <span class="text-xs font-bold text-slate-400"><?php echo $date ? $date->format('H:i') : '--:--'; ?></span>
                            </div>
                            <span class="text-[10px] font-bold <?php echo $hasFt ? 'text-emerald-600 bg-emerald-50' : 'text-amber-600 bg-amber-50'; ?> px-2 py-1 rounded border <?php echo $hasFt ? 'border-emerald-200' : 'border-amber-200'; ?> uppercase tracking-wide truncate max-w-[120px]">
                                <?php echo htmlspecialchars($match['league']); ?>
                            </span>
                        </div>

                        <div class="flex items-center justify-between gap-3 pl-2">
                            <!-- Home -->
                            <div class="flex-1 flex flex-col items-center text-center gap-1">
                                <span class="text-sm font-bold text-slate-900 leading-tight line-clamp-2"><?php echo htmlspecialchars($match['home_team']); ?></span>
                            </div>

                            <!-- Score -->
                            <div class="flex flex-col items-center gap-1 shrink-0">
                                <div class="flex items-center gap-1.5 <?php echo $hasFt ? 'bg-emerald-50 border border-emerald-200' : 'bg-slate-50 border border-slate-200'; ?> px-3 py-1.5 rounded-lg">
                                    <span class="text-base font-black <?php echo $hasFt ? 'text-emerald-700' : 'text-slate-400'; ?>"><?php echo $match['ft_home'] !== null ? $match['ft_home'] : '-'; ?></span>
                                    <span class="<?php echo $hasFt ? 'text-emerald-300' : 'text-slate-300'; ?> font-bold">:</span>
                                    <span class="text-base font-black <?php echo $hasFt ? 'text-emerald-700' : 'text-slate-400'; ?>"><?php echo $match['ft_away'] !== null ? $match['ft_away'] : '-'; ?></span>
                                </div>
                                <?php if ($match['fh_home'] !== null): ?>
                                    <span class="text-[10px] font-bold <?php echo $hasFt ? 'text-emerald-600' : 'text-amber-600'; ?>">HT <?php echo $match['fh_home']; ?>-<?php echo $match['fh_away']; ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Away -->
                            <div class="flex-1 flex flex-col items-center text-center gap-1">
                                <span class="text-sm font-bold text-slate-900 leading-tight line-clamp-2"><?php echo htmlspecialchars($match['away_team']); ?></span>
                            </div>
                        </div>

                        <?php $mk = matchesGoalMarkets($match); ?>
                        <?php if ($mk['has']): ?>
                        <div class="mt-3 pl-2 flex items-center justify-between gap-2 border-t border-slate-100 pt-2">
                            <span class="text-[10px] font-bold text-slate-500">
                                Total <span class="<?php echo $mk['o25'] ? 'text-emerald-700' : ($mk['total'] < 2 ? 'text-rose-600' : 'text-amber-600'); ?> font-black"><?php echo $mk['total']; ?></span> gol<?php if ($mk['sh'] !== null): ?> <span class="text-slate-400">· 2H <?php echo $mk['sh']; ?></span><?php endif; ?>
                            </span>
                            <div class="flex items-center gap-1">
                                <?php
                                echo matchesMarketBadge('O1.5', $mk['o15']);
                                echo matchesMarketBadge('O2.5', $mk['o25']);
                                echo matchesMarketBadge('BTTS', $mk['btts']);
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <div class="bg-white rounded-3xl p-12 text-center border border-slate-200 shadow-sm">
                <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6 border border-slate-100">
                    <svg class="w-10 h-10 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                </div>
                <h3 class="text-lg font-bold text-slate-900 mb-2">Tidak Ada Data Ditemukan</h3>
                <p class="text-slate-500 mb-6">Coba ubah filter pencarian Anda atau tambahkan data baru.</p>
                <a href="index.php?page=matches" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white rounded-xl font-bold text-sm hover:bg-blue-700 transition-all shadow-lg shadow-blue-600/20">
                    Reset Filter
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <div class="flex flex-col md:flex-row items-center justify-between gap-4 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <div class="flex items-center gap-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-center md:text-left">
            <div>
                Halaman <span class="text-slate-900"><?php echo $p; ?></span> dari <span class="text-slate-900"><?php echo $totalPages; ?></span>
            </div>
            <div class="flex items-center gap-2">
                <label class="text-slate-400">Tampil:</label>
                <select id="matches-per-page" class="bg-slate-50 border border-slate-200 rounded-lg px-2 py-1 text-xs font-medium text-slate-700 cursor-pointer hover:bg-slate-100 transition-colors">
                    <?php 
                    foreach ($perPageOptions as $option):
                        $url = "?page=matches&p=1";
                        foreach ($_GET as $key => $val) {
                            if ($key != 'p' && $key != 'per_page') $url .= '&' . urlencode($key) . '=' . urlencode($val);
                        }
                        $url .= '&per_page=' . $option;
                    ?>
                        <option value="<?php echo $url; ?>" <?php echo $perPage == $option ? 'selected' : ''; ?>>
                            <?php echo $option; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <?php if ($totalPages > 1): ?>
            <div class="flex flex-wrap justify-center items-center gap-1.5">
                <?php
                $queryString = '';
                foreach ($_GET as $key => $val) {
                    if ($key != 'p') $queryString .= '&' . urlencode($key) . '=' . urlencode($val);
                }
                $navBtnBase = 'h-11 min-w-[44px] flex items-center justify-center text-slate-500 hover:text-blue-600 hover:bg-blue-50 rounded-xl transition-all border border-transparent hover:border-blue-100 px-2 text-xs font-bold';
                $navBtnDisabled = 'h-11 min-w-[44px] flex items-center justify-center text-slate-300 px-2 rounded-xl cursor-not-allowed text-xs font-bold';
                ?>

                <!-- First -->
                <?php if ($p > 1): ?>
                    <a href="?p=1<?php echo $queryString; ?>" class="<?php echo $navBtnBase; ?>" title="Halaman pertama">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
                    </a>
                <?php else: ?>
                    <span class="<?php echo $navBtnDisabled; ?>"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg></span>
                <?php endif; ?>

                <!-- Prev -->
                <?php if ($p > 1): ?>
                    <a href="?p=<?php echo $p - 1; ?><?php echo $queryString; ?>" class="w-11 h-11 flex items-center justify-center text-slate-500 hover:text-blue-600 hover:bg-blue-50 rounded-xl transition-all border border-transparent hover:border-blue-100" title="Sebelumnya">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </a>
                <?php endif; ?>

                <div class="hidden md:flex items-center gap-1.5">
                    <?php
                    $start = max(1, $p - 2);
                    $end = min($totalPages, $p + 2);
                    if ($start > 1) echo '<span class="text-slate-300 px-1">...</span>';
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <a href="?p=<?php echo $i; ?><?php echo $queryString; ?>"
                           class="w-11 h-11 flex items-center justify-center rounded-xl text-sm font-bold transition-all border <?php echo $i == $p ? 'bg-slate-900 text-white border-slate-900 shadow-lg shadow-slate-900/20 scale-105' : 'bg-white text-slate-500 border-slate-200 hover:border-blue-200 hover:text-blue-600 hover:bg-blue-50'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    <?php if ($end < $totalPages) echo '<span class="text-slate-300 px-1">...</span>'; ?>
                </div>

                <!-- Mobile Simple Pagination -->
                <div class="md:hidden flex items-center gap-2">
                    <span class="text-sm font-bold text-slate-900 bg-slate-100 px-3 py-2 rounded-lg"><?php echo $p; ?></span>
                </div>

                <!-- Next -->
                <?php if ($p < $totalPages): ?>
                    <a href="?p=<?php echo $p + 1; ?><?php echo $queryString; ?>" class="w-11 h-11 flex items-center justify-center text-slate-500 hover:text-blue-600 hover:bg-blue-50 rounded-xl transition-all border border-transparent hover:border-blue-100" title="Berikutnya">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                <?php endif; ?>

                <!-- Last -->
                <?php if ($p < $totalPages): ?>
                    <a href="?p=<?php echo $totalPages; ?><?php echo $queryString; ?>" class="<?php echo $navBtnBase; ?>" title="Halaman terakhir">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/></svg>
                    </a>
                <?php else: ?>
                    <span class="<?php echo $navBtnDisabled; ?>"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/></svg></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Refresh button spin
document.addEventListener('DOMContentLoaded', function () {
    const refreshBtn = document.querySelector('[title="Refresh data"]');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            const icon = document.getElementById('refreshIcon');
            if (icon) icon.classList.add('animate-spin');
        });
    }
});

// Global pageLoader loading overlay logic
function showMatchesPageLoader() {
    const pageLoader = document.getElementById('pageLoader');
    if (pageLoader) {
        pageLoader.classList.remove('hidden');
    }
}

// Loading overlay on filter submit
document.addEventListener('DOMContentLoaded', function () {
    const mainForm = document.getElementById('matches-filter-form');
    const exportInput = mainForm ? mainForm.querySelector('input[name="export"]') : null;
    const exportBtn = document.getElementById('exportBtn');

    if (mainForm) {
        mainForm.addEventListener('submit', function () {
            if (exportInput && exportInput.value === 'csv') return; // skip loader for export
            showMatchesPageLoader();
        });
    }

    // Export button: set export=csv on the hidden input then submit
    if (exportBtn && mainForm && exportInput) {
        exportBtn.addEventListener('click', function () {
            exportInput.value = 'csv';
            mainForm.submit();
            // Reset after brief delay so normal filter submit still works
            setTimeout(function () { exportInput.value = ''; }, 500);
        });
    }

    // Auto-submit dropdowns with loading state
    const leagueSelect = document.getElementById('matches-league-select');
    const statusSelect = document.getElementById('matches-status-select');
    if (leagueSelect && mainForm) {
        leagueSelect.addEventListener('change', function() {
            showMatchesPageLoader();
            mainForm.submit();
        });
    }
    if (statusSelect && mainForm) {
        statusSelect.addEventListener('change', function() {
            showMatchesPageLoader();
            mainForm.submit();
        });
    }

    // Per-page select auto-submit with loader
    const perPageSelect = document.getElementById('matches-per-page');
    if (perPageSelect) {
        perPageSelect.addEventListener('change', function() {
            showMatchesPageLoader();
            window.location.href = this.value;
        });
    }

    // Show loader on page navigation/filter/sorting/pagination/quick links
    document.querySelectorAll('.page-fade-in a').forEach(function(link) {
        const href = link.getAttribute('href');
        if (href && (href.startsWith('index.php') || href.includes('page=matches') || href.startsWith('?p='))) {
            link.addEventListener('click', showMatchesPageLoader);
        }
    });
});

function showMonth(ym) {
    document.querySelectorAll('.month-panel').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.month-tab').forEach(el => {
        el.classList.remove('border-blue-600','text-blue-600','bg-blue-50');
        el.classList.add('border-transparent','text-slate-400');
    });
    const panel = document.getElementById('month-' + ym);
    const tab   = document.getElementById('tab-' + ym);
    if (panel) panel.classList.remove('hidden');
    if (tab) {
        tab.classList.remove('border-transparent','text-slate-400');
        tab.classList.add('border-blue-600','text-blue-600','bg-blue-50');
        tab.scrollIntoView({ inline: 'nearest', block: 'nearest' });
    }
}

const TEAMS_DATA = <?php echo json_encode($teams, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function setupAutocomplete(inputId, dropdownId, teams, onSelect) {
    const input = document.getElementById(inputId);
    const dropdown = document.getElementById(dropdownId);
    if (!input || !dropdown) return;

    const ITEM_CLASS = 'px-4 py-3 cursor-pointer text-sm font-medium text-slate-700 transition-colors flex items-center justify-between group';
    const CHECK_SVG = '<svg class="w-4 h-4 text-blue-400 opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
    let activeIndex = -1;

    function closeDropdown() {
        dropdown.classList.add('hidden');
        dropdown.innerHTML = '';
        activeIndex = -1;
    }

    function highlightItem(items) {
        items.forEach((item, idx) => {
            if (idx === activeIndex) {
                item.classList.add('bg-blue-50');
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.classList.remove('bg-blue-50');
            }
        });
    }

    input.addEventListener('input', function () {
        const query = this.value.toLowerCase();
        dropdown.innerHTML = '';
        activeIndex = -1;
        if (query.length < 2) { dropdown.classList.add('hidden'); return; }

        const matches = teams.filter(t => t.toLowerCase().includes(query)).slice(0, 10);
        if (matches.length === 0) { dropdown.classList.add('hidden'); return; }

        matches.forEach(team => {
            const div = document.createElement('div');
            div.className = ITEM_CLASS;
            div.innerHTML = `<span>${team}</span>${CHECK_SVG}`;
            div.addEventListener('click', function () {
                input.value = team;
                closeDropdown();
                if (typeof onSelect === 'function') {
                    onSelect(team);
                }
            });
            dropdown.appendChild(div);
        });
        dropdown.classList.remove('hidden');
    });

    input.addEventListener('keydown', function (e) {
        const items = dropdown.querySelectorAll('div');
        if (dropdown.classList.contains('hidden') || items.length === 0) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = (activeIndex + 1) % items.length;
            highlightItem(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = (activeIndex - 1 + items.length) % items.length;
            highlightItem(items);
        } else if (e.key === 'Enter') {
            if (activeIndex > -1) {
                e.preventDefault();
                items[activeIndex].click();
            }
        } else if (e.key === 'Escape') {
            closeDropdown();
        }
    });

    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
            closeDropdown();
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    setupAutocomplete('teamSearch',     'autocompleteResults',     TEAMS_DATA, function(selectedTeam) {
        const mainForm = document.getElementById('matches-filter-form');
        if (mainForm) {
            showMatchesPageLoader();
            mainForm.submit();
        }
    });
    setupAutocomplete('homeTeamSearch', 'homeAutocompleteResults', TEAMS_DATA);
    setupAutocomplete('awayTeamSearch', 'awayAutocompleteResults', TEAMS_DATA);
    setupAutocomplete('htTeamHomeInput', 'htHomeAutocomplete', TEAMS_DATA);
    setupAutocomplete('htTeamAwayInput', 'htAwayAutocomplete', TEAMS_DATA);

    const swapBtn = document.getElementById('h2hSwapBtn');
    const homeInput = document.getElementById('homeTeamSearch');
    const awayInput = document.getElementById('awayTeamSearch');
    if (swapBtn && homeInput && awayInput) {
        swapBtn.addEventListener('click', function () {
            const tmp = homeInput.value;
            homeInput.value = awayInput.value;
            awayInput.value = tmp;
            homeInput.focus();
        });
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    flatpickr("#matches-date-range", {
        mode: "range",
        dateFormat: "Y-m-d",
        onClose: function(selectedDates, dateStr, instance) {
            if (selectedDates.length === 2) {
                showMatchesPageLoader();
                instance.element.form.submit();
            }
        }
    });
});
</script>
