<?php
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/dashboard_cache.php';
require_once __DIR__ . '/pattern_snapshot.php';

$csvFile = __DIR__ . '/goal_log.csv';
$data = getCachedDashboardData($csvFile, __DIR__ . '/dashboard_cache.json');

$currentSnap = computeSnapshotData($data['patterns'], $data['next_patterns'], $data['late_patterns'] ?? []);
$oldSnap = getSnapshotHourAgo();
$oldSnapData = $oldSnap ? $oldSnap['data'] : [];
$oldSnapTime = $oldSnap ? $oldSnap['time'] : null;
saveSnapshot($currentSnap);

$patternDefs = array_map(fn($p) => [
    'id' => $p['id'],
    'label' => $p['label'],
    'tags' => extractPatternTags($p['id'], $p['label']),
], $data['patterns']);

$response = [
    'ok' => true,
    'from_cache' => $data['from_cache'] ?? false,
    'csv_exists' => $data['csv_exists'],
    'csv_time' => $data['csv_time'],
    'csv_time_str' => $data['csv_time'] ? date('d/m H:i', $data['csv_time']) : null,
    'total_matches' => $data['total_matches'],
    'pattern_count' => count($data['patterns']),
    'snapshot_time' => $oldSnapTime,
    'snapshot_label' => $oldSnapTime ? buildSnapshotLabel($oldSnapTime) : null,
    'patterns' => buildPatternSummary($data['patterns'], $oldSnapData),
    'next_patterns' => buildNextPatternSummary($data['next_patterns'], $oldSnapData),
    'late_patterns' => buildLatePatternSummary($data['late_patterns'] ?? [], $oldSnapData),
    'pattern_defs' => $patternDefs,
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function buildPatternSummary(array $patterns, array $oldSnapData): array {
    usort($patterns, function($a, $b) {
        $ta = count($a['data']); $tb = count($b['data']);
        if ($tb != $ta) return $tb <=> $ta;
        $pa = $ta > 0 ? count(array_filter($a['data'], fn($m) => $m['h2c'] > 0)) / $ta : 0;
        $pb = $tb > 0 ? count(array_filter($b['data'], fn($m) => $m['h2c'] > 0)) / $tb : 0;
        return $pb <=> $pa;
    });

    return array_map(function($p) use ($oldSnapData) {
        $total = count($p['data']);
        $has2h = count(array_filter($p['data'], fn($m) => $m['h2c'] > 0));
        $pct = $total > 0 ? round($has2h/$total*100) : 0;
        $cls = $pct >= 95 ? 'pct-high' : ($pct >= 85 ? 'pct-mid' : 'pct-low');
        $badge = $pct >= 95 ? 'badge-green' : ($pct >= 85 ? 'badge-yellow' : 'badge-red');
        $status = $pct >= 95 ? 'EXCELLENT' : ($pct >= 85 ? 'GOOD' : 'WARNING');
        $delta = buildDelta($p['id'], $total, $has2h, $oldSnapData);
        return [
            'id' => $p['id'],
            'label' => $p['label'],
            'total' => $total,
            'has2h' => $has2h,
            'pct' => $pct,
            'cls' => $cls,
            'badge' => $badge,
            'status' => $status,
            'delta' => $delta,
        ];
    }, $patterns);
}

function buildNextPatternSummary(array $nextPatterns, array $oldSnapData): array {
    usort($nextPatterns, function($a, $b) {
        if ($a['next'] !== $b['next']) {
            return $a['next'] === 'HOME' ? -1 : 1;
        }
        $ta = count($a['data']); $tb = count($b['data']);
        $tgt_a = $a['next'];
        $tgt_b = $b['next'];
        $ha = $tgt_a === 'HOME' ? count(array_filter($a['data'], fn($m) => $m['next_goal']==='H')) : count(array_filter($a['data'], fn($m) => $m['next_goal']==='A'));
        $hb = $tgt_b === 'HOME' ? count(array_filter($b['data'], fn($m) => $m['next_goal']==='H')) : count(array_filter($b['data'], fn($m) => $m['next_goal']==='A'));
        $pa = $ta > 0 ? $ha / $ta : 0;
        $pb = $tb > 0 ? $hb / $tb : 0;
        if ($pb != $pa) return $pb <=> $pa;
        return $tb <=> $ta;
    });
    return array_map(function($ng) use ($oldSnapData) {
        $total = count($ng['data']);
        $nh = count(array_filter($ng['data'], fn($m) => $m['next_goal']==='H'));
        $na = count(array_filter($ng['data'], fn($m) => $m['next_goal']==='A'));
        $tgt = $ng['next'];
        $hits = $tgt === 'HOME' ? $nh : $na;
        $pct = $total > 0 ? round($hits/$total*100) : 0;
        $cls = $pct >= 85 ? 'pct-high' : ($pct >= 75 ? 'pct-mid' : 'pct-low');
        $badge = $pct >= 85 ? 'badge-green' : ($pct >= 75 ? 'badge-yellow' : 'badge-red');
        $status = $pct >= 85 ? 'STRONG' : ($pct >= 75 ? 'GOOD' : 'WEAK');
        $delta = buildDelta($ng['id'], $total, $hits, $oldSnapData);
        return [
            'id' => $ng['id'],
            'label' => $ng['label'],
            'next' => $tgt,
            'total' => $total,
            'hits' => $hits,
            'nh' => $nh,
            'na' => $na,
            'pct' => $pct,
            'cls' => $cls,
            'badge' => $badge,
            'status' => $status,
            'delta' => $delta,
        ];
    }, $nextPatterns);
}

function buildLatePatternSummary(array $latePatterns, array $oldSnapData): array {
    return array_map(function($lp) use ($oldSnapData) {
        $total = count($lp['data']);
        $lateHits = count(array_filter($lp['data'], fn($m) => $m['has_late']));
        $pct = $total > 0 ? round($lateHits / $total * 100) : 0;
        $cls = $pct >= 80 ? 'pct-high' : ($pct >= 70 ? 'pct-mid' : 'pct-low');
        $badge = $pct >= 80 ? 'badge-green' : ($pct >= 70 ? 'badge-yellow' : 'badge-red');
        $status = $pct >= 80 ? 'STRONG' : ($pct >= 70 ? 'GOOD' : 'WATCH');
        $delta = buildDelta($lp['id'], $total, $lateHits, $oldSnapData);
        return [
            'id' => $lp['id'],
            'label' => $lp['label'],
            'total' => $total,
            'late_hits' => $lateHits,
            'pct' => $pct,
            'cls' => $cls,
            'badge' => $badge,
            'status' => $status,
            'delta' => $delta,
        ];
    }, $latePatterns);
}



function buildSnapshotLabel(int $oldSnapTime): string {
    $minsAgo = round((time() - $oldSnapTime) / 60);
    return date('H:i', $oldSnapTime) . '→' . date('H:i') . " ({$minsAgo} mnt lalu)";
}

function extractPatternTags(string $id, string $label): array {
    $tags = [];
    if (strpos($label, '16min') !== false) $tags[] = '16min';
    if (strpos($label, '15min') !== false) $tags[] = '15min';
    if (strpos($label, '20min') !== false) $tags[] = '20min';
    if (strpos($label, 'seri') !== false || strpos($label, 'Seri') !== false || strpos($label, '1-1') !== false) $tags[] = 'draw';
    if (strpos($label, 'AWAY') !== false || strpos($label, 'Away') !== false) $tags[] = 'away';
    if (strpos($label, 'HOME') !== false || strpos($label, 'Home') !== false) $tags[] = 'home';
    if (strpos($label, 'selisih') !== false || strpos($label, 'Selisih') !== false) $tags[] = 'diff';
    if (strpos($id, 'NG') === 0) $tags[] = 'nextgoal';
    return $tags;
}
