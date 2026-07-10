<?php
date_default_timezone_set('Asia/Jakarta');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$csvFile = __DIR__ . '/odds_log.csv';

$teamFilter = trim((string)($_GET['team'] ?? ''));
$selectionFilter = trim((string)($_GET['selection'] ?? ''));
$limit = max(10, min(2000, (int)($_GET['limit'] ?? 200)));

$rows = [];
$totalRows = 0;
if (is_file($csvFile) && is_readable($csvFile)) {
    $fh = fopen($csvFile, 'r');
    fgetcsv($fh); // header
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 10) continue;
        $totalRows++;
        if ($teamFilter !== ''
            && stripos($row[2], $teamFilter) === false
            && stripos($row[3], $teamFilter) === false) continue;
        if ($selectionFilter !== '' && stripos($row[7], $selectionFilter) === false) continue;
        $rows[] = $row;
        // Simpan hanya $limit baris terakhir yang cocok (hemat memori untuk file besar)
        if (count($rows) > $limit) array_shift($rows);
    }
    fclose($fh);
}
$rows = array_reverse($rows); // terbaru dulu

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Odds Log</title>
    <link rel="stylesheet" href="dashboard.css">
</head>
<body>
<div class="container">
    <h1>Odds Log</h1>
    <p class="subtitle">Riwayat perubahan odds (target O/U + Next Goal) dari odds_log.csv &mdash; total <?= $totalRows ?> event tercatat</p>

    <form method="GET" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="text" name="team" value="<?= e($teamFilter) ?>" placeholder="Filter team..."
               style="padding:6px 10px;border-radius:6px;border:1px solid #30363d;background:#0d1117;color:#e6edf3;">
        <select name="selection" style="padding:6px 10px;border-radius:6px;border:1px solid #30363d;background:#0d1117;color:#e6edf3;">
            <option value="">Semua selection</option>
            <?php foreach (['ft:o0.75', 'ft:o1.0', 'ft:o1.25', '1h:o0.75', '1h:o1.0', '1h:o1.25', 'ng:home', 'ng:away', 'ng:none'] as $sel): ?>
                <option value="<?= e($sel) ?>" <?= $selectionFilter === $sel ? 'selected' : '' ?>><?= e($sel) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="limit" style="padding:6px 10px;border-radius:6px;border:1px solid #30363d;background:#0d1117;color:#e6edf3;">
            <?php foreach ([100, 200, 500, 1000, 2000] as $opt): ?>
                <option value="<?= $opt ?>" <?= $limit === $opt ? 'selected' : '' ?>><?= $opt ?> baris</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-action">Filter</button>
        <a href="dashboard.php" class="btn-action" style="text-decoration:none;">&larr; Dashboard</a>
    </form>

<?php if (!is_file($csvFile)): ?>
    <div class="no-data-banner">
        <strong>&#x26A0; odds_log.csv belum ada</strong>
        Data akan mulai terkumpul setelah extension live-scraper berjalan dengan fitur odd logging.
    </div>
<?php elseif (!$rows): ?>
    <div class="no-data-banner">Tidak ada event yang cocok dengan filter.</div>
<?php else: ?>
    <div class="section">
        <table>
            <thead>
            <tr>
                <th>Waktu</th><th>Match</th><th>Liga</th><th>Status</th><th>Skor</th>
                <th>Selection</th><th>Odd</th><th>Sebelumnya</th><th>&Delta;</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $odd = (float)$r[8];
                $prev = $r[9] !== '' ? (float)$r[9] : null;
                $delta = $prev !== null ? $odd - $prev : null;
                $deltaColor = $delta === null ? '#8b949e' : ($delta > 0 ? '#3fb950' : ($delta < 0 ? '#f85149' : '#8b949e'));
            ?>
            <tr>
                <td style="white-space:nowrap;"><?= e($r[0]) ?></td>
                <td><?= e($r[2]) ?> vs <?= e($r[3]) ?></td>
                <td style="font-size:0.75rem;color:#8b949e;"><?= e($r[1]) ?></td>
                <td><?= e($r[4]) ?></td>
                <td><?= e($r[5]) ?></td>
                <td><code><?= e($r[7]) ?></code></td>
                <td><strong><?= number_format($odd, 2) ?></strong></td>
                <td><?= $prev !== null ? number_format($prev, 2) : '&mdash;' ?></td>
                <td style="color:<?= $deltaColor ?>;font-weight:600;">
                    <?= $delta !== null ? ($delta > 0 ? '+' : '') . number_format($delta, 2) : '&mdash;' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>
</body>
</html>
