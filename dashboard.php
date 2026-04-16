<?php header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pattern Accuracy Dashboard</title>
    <link rel="stylesheet" href="dashboard.css">
</head>
<body>
<div id="slide-overlay" onclick="closePanel()"></div>
<div id="slide-panel">
    <div id="slide-header">
        <h3 id="slide-title"></h3>
        <button id="slide-close" onclick="closePanel()">&#x2715;</button>
    </div>
    <div id="slide-body"></div>
</div>

<div class="container">
    <h1>Pattern Accuracy Dashboard</h1>
    <p class="subtitle">Analisis pola gol 1H berdasarkan data goal_log.csv</p>

    <div id="update-indicator">
        <span class="badge badge-green" id="update-status">&#x25CF; LIVE</span>
        <span style="color:#8b949e; font-size:0.8rem; margin-left:8px;" id="update-time"></span>
        <span id="countdown"></span>
        <button class="btn-action" onclick="location.reload()">&#x21BB; Refresh</button>
    </div>

<?php
require_once __DIR__ . '/dashboard_cache.php';
require_once __DIR__ . '/pattern_snapshot.php';

$teamConfig = require __DIR__ . '/dashboard_config.php';

$csvFile = __DIR__ . '/goal_log.csv';
$cacheFile = __DIR__ . '/dashboard_cache.json';
$data = getCachedDashboardData($csvFile, $cacheFile);
$currentSnapTime = time();

$currentSnap = computeSnapshotData($data['patterns'], $data['next_patterns'], $data['late_patterns'] ?? []);
$oldSnap = getSnapshotHourAgo($currentSnapTime);
$oldSnapData = $oldSnap ? $oldSnap['data'] : [];
$oldSnapTime = $oldSnap ? $oldSnap['time'] : null;
saveSnapshot($currentSnap, $currentSnapTime);

$patterns = $data['patterns'];
$nextPatterns = $data['next_patterns'];
$latePatterns = $data['late_patterns'] ?? [];
usort($nextPatterns, function($a, $b) {
    $ta = count($a['data']); $tb = count($b['data']);
    if ($tb != $ta) return $tb <=> $ta;
    $tgt_a = $a['next'];
    $tgt_b = $b['next'];
    $ha = $tgt_a === 'HOME' ? count(array_filter($a['data'], fn($m) => $m['next_goal']==='H')) : count(array_filter($a['data'], fn($m) => $m['next_goal']==='A'));
    $hb = $tgt_b === 'HOME' ? count(array_filter($b['data'], fn($m) => $m['next_goal']==='H')) : count(array_filter($b['data'], fn($m) => $m['next_goal']==='A'));
    $pa = $ta > 0 ? $ha / $ta : 0;
    $pb = $tb > 0 ? $hb / $tb : 0;
    return $pb <=> $pa;
});

usort($latePatterns, function($a, $b) {
    $ta = count($a['data']); $tb = count($b['data']);
    $ha = $ta > 0 ? count(array_filter($a['data'], fn($m) => $m['has_late'])) / $ta : 0;
    $hb = $tb > 0 ? count(array_filter($b['data'], fn($m) => $m['has_late'])) / $tb : 0;
    if ($hb != $ha) return $hb <=> $ha;
    return $tb <=> $ta;
});
$totalMatches = $data['total_matches'];
$patternCount = count($patterns);
$csvExists = $data['csv_exists'];
$csvTime = $data['csv_time'];

usort($patterns, function($a, $b) {
    $ta = count($a['data']); $tb = count($b['data']);
    if ($tb != $ta) return $tb <=> $ta;
    $pa = $ta > 0 ? count(array_filter($a['data'], fn($m) => $m['h2c'] > 0)) / $ta : 0;
    $pb = $tb > 0 ? count(array_filter($b['data'], fn($m) => $m['h2c'] > 0)) / $tb : 0;
    return $pb <=> $pa;
});

if (!$csvExists): ?>
    <div class="no-data-banner">
        <strong>&#x26A0; CSV tidak ditemukan</strong>
        File <code>goal_log.csv</code> belum tersedia atau tidak bisa dibaca.
        Pastikan extension sudah berjalan dan menyimpan data.
    </div>
<?php endif; ?>

    <div class="stats-bar" id="stats-bar">
        <div class="stat-card"><div class="value" id="stat-total"><?= $totalMatches ?></div><div class="label">Total Matches</div></div>
        <div class="stat-card"><div class="value" id="stat-patterns"><?= $patternCount ?></div><div class="label">Patterns</div></div>
        <div class="stat-card"><div class="value" id="stat-updated"><?= $csvTime ? date('d/m H:i', $csvTime) : '-' ?></div><div class="label">Last Update</div></div>
    </div>

    <div id="live-section">
        <h2><span class="live-dot"></span> Live Match Signal</h2>
        <div id="live-status-bar">
            <span id="live-api-badge" class="api-offline">API Offline</span>
            <button id="btn-start-api" class="btn-api-start" onclick="startApiServer()" style="display:none;">&#x25B6; Jalankan API</button>
            <button id="btn-stop-api" class="btn-api-stop" onclick="stopApiServer()" style="display:none;">&#x25A0; Stop API</button>
            <span id="live-last-update"></span>
        </div>
        <div id="live-alerts" class="live-alerts-empty">Belum ada alert pattern live.</div>
        <div id="live-cards"><div class="live-empty">Menunggu data dari extension...</div></div>
    </div>

    <div class="section" id="summary-section">
        <h2>Summary Akurasi</h2>
        <table id="summary-table">
            <thead>
            <tr>
                <th>#</th><th>Pattern</th><th class="sortable" data-table="summary" data-sort="record">Record <span class="sort-arrow"></span></th><th class="sortable" data-table="summary" data-sort="pct">Akurasi <span class="sort-arrow"></span></th><th>Status</th>
                <th id="snap-header" style="color:#8b949e;white-space:nowrap;">+Sample<?= $oldSnapTime ? ' (' . date('H:i', $oldSnapTime) . '→' . date('H:i', $currentSnapTime) . ')' : '' ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody id="summary-body">
<?php foreach ($patterns as $p):
    $total = count($p['data']);
    $has2h = count(array_filter($p['data'], fn($m) => $m['h2c'] > 0));
    $pct = $total > 0 ? round($has2h/$total*100) : 0;
    $cls = $pct >= 95 ? 'pct-high' : ($pct >= 85 ? 'pct-mid' : 'pct-low');
    $badge = $pct >= 95 ? 'badge-green' : ($pct >= 85 ? 'badge-yellow' : 'badge-red');
    $status = $pct >= 95 ? 'EXCELLENT' : ($pct >= 85 ? 'GOOD' : 'WARNING');
    $delta = buildDelta($p['id'], $total, $has2h, $oldSnapData);
?>
            <tr data-pid="<?= esc($p['id']) ?>" data-total="<?= $total ?>" data-hits="<?= $has2h ?>" data-pct="<?= $pct ?>">
                <td><strong><?= esc($p['id']) ?></strong></td>
                <td><?= esc($p['label']) ?></td>
                <td><?= $has2h ?>/<?= $total ?></td>
                <td class="pct <?= $cls ?>"><?= $pct ?>%</td>
                <td><span class="badge <?= $badge ?>"><?= $status ?></span></td>
                <td class="delta-cell" style="font-size:0.8rem;"><?= $delta['html'] ?></td>
                <td><button class="expand-btn" data-pid="<?= esc($p['id']) ?>">Detail</button></td>
            </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="section" id="next-section">
        <h2>Next Goal Pattern (Gol Pertama Babak 2)</h2>
        <table id="next-table">
            <thead>
            <tr>
                <th>#</th><th>Pattern</th><th>Prediksi Next Goal</th><th class="sortable" data-table="next" data-sort="record">Record <span class="sort-arrow"></span></th><th class="sortable" data-table="next" data-sort="pct">Akurasi <span class="sort-arrow"></span></th><th>Status</th>
                <th id="next-snap-header" style="color:#8b949e;white-space:nowrap;">+Sample<?= $oldSnapTime ? ' (' . date('H:i', $oldSnapTime) . '→' . date('H:i', $currentSnapTime) . ')' : '' ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody id="next-body">
<?php foreach ($nextPatterns as $ng):
    $total = count($ng['data']);
    $nh = count(array_filter($ng['data'], fn($m) => $m['next_goal']==='H'));
    $na = count(array_filter($ng['data'], fn($m) => $m['next_goal']==='A'));
    $tgt = $ng['next'];
    $hits = $tgt === 'HOME' ? $nh : $na;
    $pct = $total > 0 ? round($hits/$total*100) : 0;
    $cls = $pct >= 85 ? 'pct-high' : ($pct >= 75 ? 'pct-mid' : 'pct-low');
    $badge = $pct >= 85 ? 'badge-green' : ($pct >= 75 ? 'badge-yellow' : 'badge-red');
    $status = $pct >= 85 ? 'STRONG' : ($pct >= 75 ? 'GOOD' : 'WEAK');
    $nextBadge = $tgt === 'HOME'
        ? '<span class="scorer-h next-badge-home">HOME</span>'
        : '<span class="scorer-a next-badge-away">AWAY</span>';
    $delta = buildDelta($ng['id'], $total, $hits, $oldSnapData);
?>
<tr data-total="<?= count($ng['data']) ?>" data-hits="<?= $hits ?>" data-nh="<?= $nh ?>" data-na="<?= $na ?>" data-pct="<?= $pct ?>">
                <td><strong><?= esc($ng['id']) ?></strong></td>
                <td><?= esc($ng['label']) ?></td>
                <td><?= $nextBadge ?></td>
                <td><?= $hits ?>/<?= count($ng['data']) ?> <span class="record-sub">(H:<?= $nh ?> A:<?= $na ?>)</span></td>
                <td class="pct <?= $cls ?>"><?= $pct ?>%</td>
                <td><span class="badge <?= $badge ?>"><?= $status ?></span></td>
                <td style="font-size:0.8rem;"><?= $delta['html'] ?></td>
                <td><button class="expand-btn" data-pid="<?= esc($ng['id']) ?>">Detail</button></td>
            </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="section" id="late-section">
        <h2>Late Goal Pattern (Gol Menit Akhir 2H)</h2>
        <table id="late-table">
            <thead>
            <tr>
                <th>#</th><th>Pattern</th><th>Record Late Goal</th><th>Akurasi</th><th>Status</th>
                <th id="late-snap-header" style="color:#8b949e;white-space:nowrap;">+Sample<?= $oldSnapTime ? ' (' . date('H:i', $oldSnapTime) . '→' . date('H:i') . ')' : '' ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody id="late-body">
<?php foreach ($latePatterns as $lp):
    $total = count($lp['data']);
    $lateHits = count(array_filter($lp['data'], fn($m) => $m['has_late']));
    $pct = $total > 0 ? round($lateHits / $total * 100) : 0;
    $cls = $pct >= 80 ? 'pct-high' : ($pct >= 70 ? 'pct-mid' : 'pct-low');
    $badge = $pct >= 80 ? 'badge-green' : ($pct >= 70 ? 'badge-yellow' : 'badge-red');
    $status = $pct >= 80 ? 'STRONG' : ($pct >= 70 ? 'GOOD' : 'WATCH');
    $delta = buildDelta($lp['id'], $total, $lateHits, $oldSnapData);
?>
            <tr data-total="<?= $total ?>" data-hits="<?= $lateHits ?>" data-pct="<?= $pct ?>">
                <td><strong><?= esc($lp['id']) ?></strong></td>
                <td><?= esc($lp['label']) ?></td>
                <td><?= $lateHits ?>/<?= $total ?></td>
                <td class="pct <?= $cls ?>"><?= $pct ?>%</td>
                <td><span class="badge <?= $badge ?>"><?= $status ?></span></td>
                <td class="delta-cell" style="font-size:0.8rem;"><?= $delta['html'] ?></td>
                <td><button class="expand-btn" data-pid="<?= esc($lp['id']) ?>">Detail</button></td>
            </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="last-update" id="last-update">
        CSV last modified: <?= $csvTime ? date('d/m/Y H:i:s', $csvTime) : '-' ?> |
        Total <?= $totalMatches ?> matches |
        Auto-refresh: 30s via AJAX
    </p>

<?php
echo '<script id="initial-data" type="application/json">';
$patternDefs = array_map(fn($p) => [
    'id' => $p['id'],
    'label' => $p['label'],
    'tags' => [],
], $patterns);
echo json_encode([
    'all_matches' => $data['all_matches'],
    'patterns' => $patterns,
    'nextPatterns' => $nextPatterns,
    'latePatterns' => $latePatterns,
    'teamConfig' => $teamConfig,
    'patternDefs' => $patternDefs,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo '</script>' . "\n";
?>

</div>

<script src="dashboard.js?v=<?= filemtime(__DIR__ . '/dashboard.js') ?>"></script>
</body>
</html>
