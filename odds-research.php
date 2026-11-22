<?php
if (!defined('SABARAJA_APP')) { exit('No direct script access allowed'); }
require_once __DIR__ . '/odds-research-lib.php';

$report = oddsResearchReport();
$bets = $report['bets'];
$wib = new DateTimeZone('Asia/Jakarta');
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$pct = fn($v) => sprintf('%+.1f%%', 100 * $v);
$fmtTime = fn(int $ts, string $format = 'd/m H:i') => (new DateTimeImmutable('@' . $ts))->setTimezone($wib)->format($format);
$shortLeague = function (string $league): string {
    $map = ['SABA CLUB FRIENDLY Virtual PES 21 - 15' => 'L15', 'SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16' => 'L16', 'SABA INTERNATIONAL FRIENDLY Virtual PES 21 - 20' => 'L20', 'SABA VIETNAM V LEAGUE 1' => 'Vietnam', 'SABA ENGLISH PREMIER LEAGUE' => 'EPL PES 26', 'SABA ELITE FRIENDLY' => 'Elite 5m'];
    foreach ($map as $prefix => $label) if (str_starts_with($league, $prefix)) return $label;
    return $league;
};
$outcome = function (float $r, float $odd): array {
    if ($r > 0.001) return [abs($r - ($odd - 1) / 2) < 0.001 ? 'Menang ½' : 'Menang', 'is-win'];
    if ($r < -0.001) return [abs($r + 0.5) < 0.001 ? 'Kalah ½' : 'Kalah', 'is-loss'];
    return ['Kembali', 'is-push'];
};

$total = count($bets);
$progress = min(1, $total / ODDS_RESEARCH_TARGET);
$logAge = is_file(ODDS_RESEARCH_LOG) ? (time() - filemtime(ODDS_RESEARCH_LOG)) / 60 : null;
$lastBet = $bets ? end($bets) : null;
$last24 = count(array_filter($bets, fn($b) => $b['ts'] >= time() - 86400));
$groups = array_merge($report['leagues'], $report['team'], $report['pair']);
$winners = array_values(array_filter($groups, fn($g) => $g['n'] >= 100 && (($g['roiOver'] - $g['ciOver'] > 0) || ($g['roiUnder'] - $g['ciUnder'] > 0))));

$leagueFilter = (string)($_GET['league'] ?? 'all');
$dateFilter = preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string)($_GET['date'] ?? '')) ? $_GET['date'] : '';
$leagues = array_values(array_unique(array_map(fn($b) => $shortLeague($b['league']), $bets)));
sort($leagues);
$rows = array_reverse(array_values(array_filter($bets, fn($b) =>
    ($leagueFilter === 'all' || $shortLeague($b['league']) === $leagueFilter) && ($dateFilter === '' || $fmtTime($b['ts'], 'Y-m-d') === $dateFilter))));
$shown = array_slice($rows, 0, 200);

$roiCell = function (float $roi, float $ci, int $n) use ($pct) {
    if ($n < 20) return '<td class="or-muted">—</td>';
    $cls = $roi - $ci > 0 ? 'is-sure-win' : ($roi + $ci < 0 ? 'is-sure-loss' : '');
    return '<td class="' . $cls . '">' . $pct($roi) . ' <small>±' . number_format(100 * $ci, 1) . '</small></td>';
};
$table = function (string $title, string $note, array $items, bool $short = false) use ($e, $roiCell, $shortLeague) {
    echo '<section class="or-card"><div class="or-card-head"><h2>' . $e($title) . '</h2><p>' . $e($note) . '</p></div>';
    if (!$items) { echo '<p class="or-empty">Data belum cukup (perlu minimal 100 laga dengan profil).</p></section>'; return; }
    echo '<div class="or-scroll"><table><thead><tr><th>Kelompok</th><th>Laga</th><th>Line rata-rata</th><th>Gol rata-rata</th><th>Untung/rugi pasang Over</th><th>Untung/rugi pasang Under</th></tr></thead><tbody>';
    foreach ($items as $g) {
        echo '<tr><td>' . $e($short ? $shortLeague($g['label']) : $g['label']) . '</td><td>' . (int)$g['n'] . '</td><td>' . number_format($g['line'], 2) . '</td><td>' . number_format($g['goals'], 2) . '</td>'
            . $roiCell($g['roiOver'], $g['ciOver'], $g['n']) . $roiCell($g['roiUnder'], $g['ciUnder'], $g['n']) . '</tr>';
    }
    echo '</tbody></table></div></section>';
};
?>
<section class="or-page">
  <div class="or-heading">
    <div>
      <p class="or-eyebrow">RISET ODDS KICKOFF</p>
      <h1>Goal Log &amp; Riset Odds</h1>
      <p>Laga SABA yang terlacak sejak kickoff: odds Over/Under saat kickoff, skor HT dan akhir, lalu dihitung untung/rugi bila memasang Over atau Under di odds itu. Sumber <code>goal_log_bpvm.csv</code>.</p>
    </div>
    <span class="or-status <?= $logAge === null || $logAge > 60 ? 'is-bad' : 'is-ok' ?>" role="status">
      <?= $logAge === null ? 'Belum ada data' : ($logAge > 60 ? 'Pencatatan berhenti ' . $e(number_format($logAge / 60, 1, ',', '')) . ' jam' : 'Pencatatan aktif') ?>
    </span>
  </div>

  <div class="or-stats">
    <article>
      <span>Laga terkumpul</span>
      <strong><?= number_format($total, 0, ',', '.') ?> <small>/ <?= number_format(ODDS_RESEARCH_TARGET, 0, ',', '.') ?></small></strong>
      <div class="or-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?= ODDS_RESEARCH_TARGET ?>" aria-valuenow="<?= $total ?>"><i style="width:<?= round(100 * $progress, 1) ?>%"></i></div>
      <p><?= round(100 * $progress) ?>% menuju target untuk mendeteksi celah kecil (2–3%).</p>
    </article>
    <article>
      <span>Laga terakhir tercatat</span>
      <strong><?= $lastBet ? $e($fmtTime($lastBet['ts'])) : '—' ?></strong>
      <p><?= $lastBet ? $e($lastBet['home'] . ' vs ' . $lastBet['away']) : 'Belum ada laga.' ?> · <?= $last24 ?> laga dalam 24 jam terakhir.</p>
    </article>
    <article>
      <span>Selalu pasang Over / Under</span>
      <strong><?= $total ? $e($pct($report['all']['roiOver'])) . ' / ' . $e($pct($report['all']['roiUnder'])) : '—' ?></strong>
      <p>Rata-rata untung/rugi per taruhan di odds kickoff, semua laga.</p>
    </article>
  </div>

  <div class="or-verdict <?= $winners ? 'is-attention' : '' ?>">
    <strong>Kesimpulan sementara:</strong>
    <?php if ($total < 100): ?>
      Data masih terlalu sedikit untuk disimpulkan.
    <?php elseif ($winners): ?>
      <?= count($winners) ?> kelompok terlihat untung dengan rentang kepercayaan 95% di atas nol (<?= $e(implode(', ', array_map(fn($g) => $shortLeague($g['label']), $winners))) ?>). Karena banyak kelompok diuji sekaligus, satu kelompok bisa tampak untung karena kebetulan. Anggap nyata hanya bila tetap untung saat data bertambah.
    <?php else: ?>
      Belum ada kelompok yang untung secara meyakinkan. Odds bandar sejauh ini sudah memperhitungkan karakter tim dan pasangan.
    <?php endif; ?>
    <span>Hijau = untung meyakinkan, merah = rugi meyakinkan (rentang ± tidak melewati nol). Lainnya masih dalam batas kebetulan.</span>
  </div>

  <?php
  $table('Per liga', 'Untung/rugi rata-rata per taruhan 1 unit di odds kickoff.', $report['leagues'], true);
  $table('Profil gabungan kedua tim', 'Prediksi gol dari rata-rata kedua tim (data sebelum kickoff) dikurangi line bandar. Kelompok 1 = jauh di bawah line (kandidat Under).', $report['team']);
  $table('Profil pasangan H2H', 'Rata-rata gol pertemuan sebelumnya (minimal 15) dikurangi line bandar.', $report['pair']);
  ?>

  <section class="or-card">
    <div class="or-card-head"><h2>Goal log</h2><p><?= count($rows) ?> laga cocok dengan filter<?= count($rows) > 200 ? ', 200 terbaru ditampilkan' : '' ?>. Waktu WIB.</p></div>
    <form class="or-filters" method="get">
      <input type="hidden" name="page" value="odds-research">
      <label>Liga<select name="league" onchange="this.form.submit()"><option value="all">Semua liga</option><?php foreach ($leagues as $league): ?><option value="<?= $e($league) ?>"<?= $leagueFilter === $league ? ' selected' : '' ?>><?= $e($league) ?></option><?php endforeach; ?></select></label>
      <label>Tanggal<input type="date" name="date" value="<?= $e($dateFilter) ?>" onchange="this.form.submit()"></label>
      <?php if ($leagueFilter !== 'all' || $dateFilter !== ''): ?><a href="index.php?page=odds-research">Reset</a><?php endif; ?>
    </form>
    <div class="or-scroll"><table>
      <thead><tr><th>Waktu</th><th>Liga</th><th>Pertandingan</th><th>HT</th><th>FT</th><th>Line</th><th>Odds O / U</th><th>Hasil Over</th><th>Hasil Under</th></tr></thead>
      <tbody>
      <?php if (!$shown): ?><tr><td colspan="9" class="or-empty">Belum ada laga yang cocok.</td></tr><?php endif; ?>
      <?php foreach ($shown as $b): [$oLabel, $oCls] = $outcome(oddsResearchSettle($b['line'], $b['goals'], $b['over'], true), $b['over']); [$uLabel, $uCls] = $outcome(oddsResearchSettle($b['line'], $b['goals'], $b['under'], false), $b['under']); ?>
        <tr><td><?= $e($fmtTime($b['ts'])) ?></td><td><?= $e($shortLeague($b['league'])) ?></td><td><?= $e($b['home'] . ' vs ' . $b['away']) ?></td><td><?= $e($b['ht'] ?: '—') ?></td><td><?= $e($b['final']) ?></td>
          <td><?= $e(rtrim(rtrim(number_format($b['line'], 2), '0'), '.')) ?></td><td><?= number_format($b['over'], 2) ?> / <?= number_format($b['under'], 2) ?></td>
          <td class="<?= $oCls ?>"><?= $oLabel ?></td><td class="<?= $uCls ?>"><?= $uLabel ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <p class="or-foot">Diperbarui otomatis saat goal_log_bpvm.csv atau matches.csv berubah · dihitung <?= $e($fmtTime((int)$report['generatedAt'], 'd/m H:i')) ?> WIB · versi CLI: <code>php analyze-odds-edge.php</code></p>
</section>
<style>
.or-page{padding:24px;max-width:1440px;margin:auto;color:#0f172a}
.or-heading{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:20px}
.or-eyebrow{font-size:10px;font-weight:700;letter-spacing:1.6px;color:#2563eb;margin:0 0 8px}
.or-heading h1{font-size:24px;font-weight:700;margin:0 0 8px;line-height:1.4}
.or-heading p{font-size:13px;line-height:1.7;color:#64748b;margin:0;max-width:820px}
.or-status{font-size:12px;font-weight:600;white-space:nowrap;padding:7px 12px;border-radius:20px}
.or-status.is-ok{background:#dcfce7;color:#166534}.or-status.is-bad{background:#fee2e2;color:#b91c1c}
.or-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:16px}
.or-stats article,.or-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px}
.or-stats article{padding:18px}.or-stats span{font-size:12px;color:#64748b}
.or-stats strong{display:block;font-size:24px;line-height:1.5;margin:4px 0;color:#1e3a8a}.or-stats strong small{font-size:14px;color:#64748b;font-weight:500}
.or-stats p{font-size:12px;color:#64748b;margin:0;line-height:1.6}
.or-bar{height:8px;background:#e2e8f0;border-radius:6px;overflow:hidden;margin:6px 0 8px}.or-bar i{display:block;height:100%;background:#2563eb}
.or-verdict{background:#f8fafc;border:1px solid #cbd5e1;border-radius:12px;padding:14px 16px;font-size:13px;line-height:1.7;margin-bottom:16px}
.or-verdict.is-attention{background:#fffbeb;border-color:#fcd34d}.or-verdict span{display:block;color:#64748b;font-size:12px;margin-top:4px}
.or-card{margin-bottom:16px}.or-card-head{padding:16px 18px 10px}.or-card-head h2{font-size:15px;font-weight:600;margin:0 0 4px}.or-card-head p{font-size:12px;color:#64748b;margin:0;line-height:1.6}
.or-scroll{overflow-x:auto}.or-page table{width:100%;border-collapse:collapse;font-size:13px}
.or-page th,.or-page td{padding:9px 12px;border-top:1px solid #e2e8f0;text-align:left;white-space:nowrap;font-variant-numeric:tabular-nums}
.or-page th{background:#f8fafc;color:#475569;font-size:12px;font-weight:600}.or-page td small{color:#94a3b8}
.or-page td.is-sure-win{color:#166534;font-weight:600;background:#f0fdf4}.or-page td.is-sure-loss{color:#b91c1c;background:#fef2f2}
.or-page td.is-win{color:#166534;font-weight:600}.or-page td.is-loss{color:#b91c1c}.or-page td.is-push,.or-muted{color:#94a3b8}
.or-empty{padding:16px 18px;color:#64748b;font-size:13px}
.or-filters{display:flex;flex-wrap:wrap;gap:12px;align-items:end;padding:0 18px 12px}
.or-filters label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:600;color:#334155}
.or-filters select,.or-filters input{padding:8px 10px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;font:inherit;min-height:38px}
.or-filters a{font-size:12px;color:#2563eb;padding:10px 4px}
.or-foot{font-size:12px;color:#94a3b8}
@media(max-width:860px){.or-stats{grid-template-columns:1fr}.or-heading{flex-direction:column}}
</style>
