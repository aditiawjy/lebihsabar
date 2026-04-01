<?php
date_default_timezone_set('Asia/Jakarta');

function h2hHasFT(array $m): bool {
    return $m['ft_home'] !== '' && $m['ft_away'] !== ''
        && is_numeric($m['ft_home']) && is_numeric($m['ft_away']);
}

function h2hReadMatches(string $csvPath, callable $onMatch): void {
    if (!is_readable($csvPath) || ($fh = fopen($csvPath, 'r')) === false) {
        return;
    }
    $hdrs = fgetcsv($fh);
    if (!is_array($hdrs)) { fclose($fh); return; }
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) !== count($hdrs)) continue;
        $raw = array_combine($hdrs, $row);
        if (!$raw) continue;
        $home = trim($raw['home_team'] ?? '');
        $away = trim($raw['away_team'] ?? '');
        if ($home === '' || $away === '') continue;
        $dt = $raw['match_time'] ?? '';
        $onMatch([
            'date'    => substr($dt, 0, 10),
            'time'    => substr($dt, 11, 5),
            'home'    => $home,
            'away'    => $away,
            'league'  => trim($raw['league'] ?? ''),
            'ft_home' => $raw['ft_home'] ?? '',
            'ft_away' => $raw['ft_away'] ?? '',
            'fh_home' => $raw['fh_home'] ?? '',
            'fh_away' => $raw['fh_away'] ?? '',
        ]);
    }
    fclose($fh);
}

function h2hKey(string $a, string $b): string {
    $teams = [$a, $b];
    sort($teams);
    return implode(' vs ', $teams);
}

$marketOptions = [
    'over05'  => ['label' => 'Over 0.5',      'short' => 'O0.5',  'class' => 'bg-emerald-500 text-white'],
    'shg05'   => ['label' => 'SHG Over 0.5',  'short' => 'SHGO',  'class' => 'bg-fuchsia-500 text-white'],
];

$csvPath    = __DIR__ . '/matches.csv';
$today      = date('Y-m-d');
$mktParam   = $_GET['mkt'] ?? 'over05';
if (!array_key_exists($mktParam, $marketOptions)) $mktParam = 'over05';
$lgFilter    = trim($_GET['league'] ?? '');
$searchTerm  = trim($_GET['search'] ?? '');
$filterHome  = trim($_GET['home'] ?? '');
$filterAway  = trim($_GET['away'] ?? '');
$minMatches = max(1, (int)($_GET['min'] ?? 3));
$sortCol    = $_GET['sort'] ?? 'pct';
$sortOrder  = $_GET['order'] ?? 'desc';
$pg         = max(1, (int)($_GET['pg'] ?? 1));
$perPage    = 50;

if (!in_array($sortCol, ['h2h', 'hits', 'pct', 'last_date'], true)) $sortCol = 'pct';
if (!in_array($sortOrder, ['asc', 'desc'], true)) $sortOrder = 'desc';

// -- Scan CSV ------------------------------------------------------------------
$h2hStats  = []; // key => ['total'=>int, 'hits'=>int, 'last_date'=>str, 'last_score'=>str, 'league'=>str, 'home'=>str, 'away'=>str]
$leagueSet = [];
$teamSet   = [];
$nextMatch = []; // h2hKey => next match info

h2hReadMatches($csvPath, function(array $m) use (
    $lgFilter, $today, $mktParam,
    &$h2hStats, &$leagueSet, &$teamSet, &$nextMatch
): void {
    if ($m['league'] !== '') $leagueSet[$m['league']] = true;
    $teamSet[$m['home']] = true;
    $teamSet[$m['away']] = true;
    if ($lgFilter && $m['league'] !== $lgFilter) return;

    $key = h2hKey($m['home'], $m['away']);

    if (!h2hHasFT($m)) {
        // upcoming match
        if ($m['date'] >= $today) {
            if (!isset($nextMatch[$key]) || ($m['date'].$m['time']) < ($nextMatch[$key]['date'].$nextMatch[$key]['time'])) {
                $nextMatch[$key] = [
                    'home' => $m['home'], 'away' => $m['away'],
                    'date' => $m['date'], 'time' => $m['time'],
                    'league' => $m['league'],
                ];
            }
        }
        return;
    }

    $ftH = (int)$m['ft_home'];
    $ftA = (int)$m['ft_away'];
    $fhH = (int)$m['fh_home'];
    $fhA = (int)$m['fh_away'];
    $isHit = match($mktParam) {
        'shg05'  => (($ftH - $fhH) + ($ftA - $fhA)) >= 1,
        default  => ($ftH + $ftA) >= 1,
    };

    if (!isset($h2hStats[$key])) {
        $h2hStats[$key] = [
            'home'      => $m['home'],
            'away'      => $m['away'],
            'league'    => $m['league'],
            'total'     => 0,
            'hits'      => 0,
            'last_date' => '',
            'last_score'=> '',
            'last_fh'   => '',
        ];
    }

    $h2hStats[$key]['total']++;
    if ($isHit) $h2hStats[$key]['hits']++;

    if ($m['date'] > $h2hStats[$key]['last_date']) {
        $h2hStats[$key]['last_date']  = $m['date'];
        $h2hStats[$key]['last_score'] = $m['home'].' '.$m['ft_home'].'-'.$m['ft_away'].' '.$m['away'];
        $h2hStats[$key]['last_fh']    = $m['fh_home'].'-'.$m['fh_away'];
        $h2hStats[$key]['league']     = $m['league'];
    }
});

$leagueList = array_keys($leagueSet);
sort($leagueList);
$teamList = array_keys($teamSet);
sort($teamList);

// -- Build rows ----------------------------------------------------------------
$rows = [];
foreach ($h2hStats as $key => $s) {
    if ($s['total'] < $minMatches) continue;
    $pct = round(($s['hits'] / $s['total']) * 100, 1);
    $rows[] = [
        'h2h'       => $key,
        'home'      => $s['home'],
        'away'      => $s['away'],
        'league'    => $s['league'],
        'total'     => $s['total'],
        'hits'      => $s['hits'],
        'pct'       => $pct,
        'last_date' => $s['last_date'],
        'last_score'=> $s['last_score'],
        'last_fh'   => $s['last_fh'],
        'next'      => $nextMatch[$key] ?? null,
    ];
}

// Search
if ($searchTerm !== '') {
    $sl = mb_strtolower($searchTerm, 'UTF-8');
    $rows = array_values(array_filter($rows, fn($r) =>
        mb_strpos(mb_strtolower($r['h2h'], 'UTF-8'), $sl) !== false ||
        mb_strpos(mb_strtolower($r['league'], 'UTF-8'), $sl) !== false
    ));
}
if ($filterHome !== '') {
    $hl = mb_strtolower($filterHome, 'UTF-8');
    $rows = array_values(array_filter($rows, fn($r) =>
        mb_strpos(mb_strtolower($r['home'], 'UTF-8'), $hl) !== false ||
        mb_strpos(mb_strtolower($r['away'], 'UTF-8'), $hl) !== false
    ));
}
if ($filterAway !== '') {
    $al = mb_strtolower($filterAway, 'UTF-8');
    $rows = array_values(array_filter($rows, fn($r) =>
        mb_strpos(mb_strtolower($r['home'], 'UTF-8'), $al) !== false ||
        mb_strpos(mb_strtolower($r['away'], 'UTF-8'), $al) !== false
    ));
}

// Sort
usort($rows, function($a, $b) use ($sortCol, $sortOrder) {
    $cmp = match($sortCol) {
        'hits'      => $a['hits'] <=> $b['hits'],
        'pct'       => $a['pct'] <=> $b['pct'],
        'last_date' => strcmp($a['last_date'], $b['last_date']),
        default     => strcmp($a['h2h'], $b['h2h']),
    };
    return $sortOrder === 'asc' ? $cmp : -$cmp;
});

$totalRows  = count($rows);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$pg         = min($pg, $totalPages);
$offset     = ($pg - 1) * $perPage;
$pageRows   = array_slice($rows, $offset, $perPage);

// -- URL helpers ---------------------------------------------------------------
function h2hUrl(array $override = []): string {
    $params = array_merge([
        'page'   => 'h2h-over05',
        'mkt'    => $_GET['mkt'] ?? 'over05',
        'league' => $_GET['league'] ?? '',
        'search' => $_GET['search'] ?? '',
        'home'   => $_GET['home'] ?? '',
        'away'   => $_GET['away'] ?? '',
        'min'    => $_GET['min'] ?? '3',
        'sort'   => $_GET['sort'] ?? 'pct',
        'order'  => $_GET['order'] ?? 'desc',
        'pg'     => $_GET['pg'] ?? '1',
    ], $override);
    return 'index.php?' . http_build_query(array_filter($params, fn($v) => $v !== ''));
}

function h2hSortUrl(string $col, string $cur, string $curOrder): string {
    $order = ($col === $cur && $curOrder === 'desc') ? 'asc' : 'desc';
    return h2hUrl(['sort' => $col, 'order' => $order, 'pg' => '1']);
}

function h2hPctClass(float $pct): string {
    if ($pct >= 90) return 'bg-emerald-600 text-white';
    if ($pct >= 75) return 'bg-emerald-100 text-emerald-700';
    if ($pct >= 60) return 'bg-yellow-100 text-yellow-700';
    return 'bg-slate-100 text-slate-500';
}
?>

<div class="p-4 md:p-6 space-y-5">

    <!-- Header -->
    <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-900">H2H <?= htmlspecialchars($marketOptions[$mktParam]['label']) ?></h2>
                <p class="text-slate-400 text-sm mt-0.5">Persentase H2H berdasarkan market yang dipilih</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="px-3 py-1.5 rounded-xl bg-emerald-50 text-emerald-700 text-sm font-bold border border-emerald-100"><?= $totalRows ?> pasangan</span>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
        <form method="GET" action="index.php" class="flex flex-wrap gap-3 items-end">
            <input type="hidden" name="page" value="h2h-over05">

            <div class="flex flex-col gap-1">
                <label class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Market</label>
                <select name="mkt" class="px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 transition-all">
                    <?php foreach ($marketOptions as $val => $opt): ?>
                        <option value="<?= $val ?>" <?= $mktParam === $val ? 'selected' : '' ?>><?= htmlspecialchars($opt['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Home</label>
                <input type="text" name="home" value="<?= htmlspecialchars($filterHome) ?>"
                    placeholder="Tim home..."
                    list="team-list-home"
                    autocomplete="off"
                    class="px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 transition-all w-44">
                <datalist id="team-list-home">
                    <?php foreach ($teamList as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Away</label>
                <input type="text" name="away" value="<?= htmlspecialchars($filterAway) ?>"
                    placeholder="Tim away..."
                    list="team-list-away"
                    autocomplete="off"
                    class="px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 transition-all w-44">
                <datalist id="team-list-away">
                    <?php foreach ($teamList as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Liga</label>
                <select name="league" class="px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 transition-all">
                    <option value="">Semua Liga</option>
                    <?php foreach ($leagueList as $lg): ?>
                        <option value="<?= htmlspecialchars($lg) ?>" <?= $lgFilter === $lg ? 'selected' : '' ?>><?= htmlspecialchars($lg) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Min Pertemuan</label>
                <input type="number" name="min" value="<?= $minMatches ?>" min="1" max="50"
                    class="px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 transition-all w-24">
            </div>

            <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold transition-all shadow-sm">
                Filter
            </button>
            <a href="<?= htmlspecialchars(h2hUrl(['search'=>'','home'=>'','away'=>'','league'=>'','pg'=>'1'])) ?>"
               class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-bold transition-all">
                Reset
            </a>
        </form>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-slate-50 text-slate-700 sticky top-0 z-10">
                    <tr>
                        <th class="px-4 py-3 text-left font-bold">#</th>
                        <th class="px-4 py-3 text-left">
                            <a href="<?= htmlspecialchars(h2hSortUrl('h2h', $sortCol, $sortOrder)) ?>" class="flex items-center gap-1 hover:text-amber-600 font-bold">H2H <?= $sortCol==='h2h' ? ($sortOrder==='asc'?'▲':'▼') : '' ?></a>
                        </th>
                        <th class="px-4 py-3 text-center">
                            <a href="<?= htmlspecialchars(h2hSortUrl('hits', $sortCol, $sortOrder)) ?>" class="flex items-center justify-center gap-1 hover:text-amber-600 font-bold">Hits <?= $sortCol==='hits' ? ($sortOrder==='asc'?'▲':'▼') : '' ?></a>
                        </th>
                        <th class="px-4 py-3 text-center">
                            <a href="<?= htmlspecialchars(h2hSortUrl('pct', $sortCol, $sortOrder)) ?>" class="flex items-center justify-center gap-1 hover:text-amber-600 font-bold"><?= htmlspecialchars($marketOptions[$mktParam]['short']) ?> % <?= $sortCol==='pct' ? ($sortOrder==='asc'?'▲':'▼') : '' ?></a>
                        </th>
                        <th class="px-4 py-3 text-center">
                            <a href="<?= htmlspecialchars(h2hSortUrl('last_date', $sortCol, $sortOrder)) ?>" class="flex items-center justify-center gap-1 hover:text-amber-600 font-bold">Last Match <?= $sortCol==='last_date' ? ($sortOrder==='asc'?'▲':'▼') : '' ?></a>
                        </th>
                        <th class="px-4 py-3 text-center font-bold">Next Match</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php if (!$pageRows): ?>
                    <tr><td colspan="6" class="px-4 py-12 text-center text-slate-400 font-medium">
                        <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        Tidak ada data untuk filter ini.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($pageRows as $i => $r): ?>
                    <tr class="hover:bg-blue-50/30 transition-all duration-200">
                        <td class="px-4 py-3 text-slate-400 font-medium"><?= $offset + $i + 1 ?></td>
                        <td class="px-4 py-3">
                            <div class="font-bold text-slate-900"><?= htmlspecialchars($r['home']) ?></div>
                            <div class="text-[10px] text-slate-400 font-medium">vs</div>
                            <div class="font-bold text-slate-900"><?= htmlspecialchars($r['away']) ?></div>
                            <div class="text-[10px] text-slate-500 uppercase tracking-wide mt-0.5"><?= htmlspecialchars($r['league']) ?></div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-3 py-1.5 rounded-full text-xs font-black bg-emerald-100 text-emerald-700"><?= $r['hits'] ?>/<?= $r['total'] ?></span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-3 py-1.5 rounded-full text-xs font-black <?= h2hPctClass($r['pct']) ?>"><?= $r['pct'] ?>%</span>
                        </td>
                        <td class="px-4 py-3 text-center text-slate-600">
                            <?php if ($r['last_score']): ?>
                                <div class="text-[10px] font-bold text-slate-700"><?= htmlspecialchars($r['last_score']) ?></div>
                                <?php if ($r['last_fh'] !== '-' && $r['last_fh'] !== ''): ?>
                                <div class="text-[10px] text-slate-500">(HT <?= htmlspecialchars($r['last_fh']) ?>)</div>
                                <?php endif; ?>
                                <div class="text-[10px] text-slate-400"><?= htmlspecialchars(date('d/m/y', strtotime($r['last_date']))) ?></div>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center text-slate-600">
                            <?php if ($r['next']): ?>
                                <div class="font-bold text-slate-800 text-xs"><?= htmlspecialchars($r['next']['home']) ?></div>
                                <div class="text-[10px] text-slate-400">vs</div>
                                <div class="font-bold text-slate-800 text-xs"><?= htmlspecialchars($r['next']['away']) ?></div>
                                <div class="text-[10px] text-slate-500"><?= htmlspecialchars(date('d/m', strtotime($r['next']['date'])).' '.$r['next']['time']) ?></div>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="px-5 py-4 border-t border-slate-100 flex flex-wrap items-center justify-center gap-2 text-sm">
            <?php if ($pg > 1): ?>
                <a href="<?= htmlspecialchars(h2hUrl(['pg' => $pg-1])) ?>" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 font-bold text-slate-700 transition-all">&lt; Prev</a>
            <?php endif; ?>
            <?php for ($p = max(1,$pg-2); $p <= min($totalPages,$pg+2); $p++): ?>
                <a href="<?= htmlspecialchars(h2hUrl(['pg' => $p])) ?>"
                   class="px-4 py-2 rounded-xl font-bold transition-all <?= $p===$pg ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>
            <?php if ($pg < $totalPages): ?>
                <a href="<?= htmlspecialchars(h2hUrl(['pg' => $pg+1])) ?>" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 font-bold text-slate-700 transition-all">Next &gt;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
