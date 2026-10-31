<?php
$escape = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$clean = static fn($v) => trim(preg_replace('/\s+/', ' ', preg_replace('/\s*\(V\)/i', '', (string)$v)));
$key = static fn($v) => strtolower($clean($v));
$home = trim((string)($_GET['home'] ?? '')); $away = trim((string)($_GET['away'] ?? ''));
$league = trim((string)($_GET['league'] ?? ''));
$teams = []; $leagues = []; $found = []; $seen = []; $error = '';
$file = @fopen(__DIR__.'/matches.csv', 'r');
if (!$file) $error = 'File matches.csv tidak dapat dibaca.';
else {
    $header = fgetcsv($file); if ($header) $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
    while ($header && ($fields = fgetcsv($file)) !== false) {
        if (count($fields) !== count($header)) continue;
        $r = array_combine($header, $fields);
        $h = $clean($r['home_team'] ?? ''); $a = $clean($r['away_team'] ?? '');
        if ($h !== '') $teams[$key($h)] = $h;
        if ($a !== '') $teams[$key($a)] = $a;
        if (empty($r['league'])) continue;
        $leagues[$r['league']] = $r['league'];
        if ($home === '' || $away === '' || ($league !== '' && $league !== $r['league'])) continue;
        if (!(($key($h) === $key($home) && $key($a) === $key($away)) || ($key($h) === $key($away) && $key($a) === $key($home)))) continue;
        $id = implode('|', [$r['league'], $r['match_time'], $key($h), $key($a)]);
        if (isset($seen[$id])) continue;
        $seen[$id] = true; $found[] = $r;
    }
    fclose($file);
}
natcasesort($teams); natcasesort($leagues);
usort($found, static fn($a,$b) => strcmp($b['match_time'], $a['match_time']));
$streaks = [];
foreach ($found as $r) {
    $name = $r['league'];
    if (!isset($streaks[$name])) $streaks[$name] = ['count'=>0, 'ended'=>false];
    if ($streaks[$name]['ended']) continue;
    if ((string)$r['ft_home'] === '0' && (string)$r['ft_away'] === '0') $streaks[$name]['count']++;
    else $streaks[$name]['ended'] = true;
}
$scored = array_filter($found, static fn($r) => ctype_digit((string)$r['ft_home']) && ctype_digit((string)$r['ft_away']));
$over = count(array_filter($scored, static fn($r) => (int)$r['ft_home'] + (int)$r['ft_away'] > 0));
?>
<style>
.h2h-page{max-width:1100px;margin:auto;color:#1e293b}.h2h-intro{margin-bottom:24px}.h2h-intro h2{font-size:clamp(1.4rem,3vw,2rem);font-weight:700;letter-spacing:-.03em}.h2h-intro p{color:#64748b;margin-top:6px;font-size:.9rem}.h2h-form{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:22px;display:grid;grid-template-columns:1fr 1fr;gap:16px}.h2h-field label{display:block;font-size:.8rem;font-weight:600;margin-bottom:6px}.h2h-field input,.h2h-field select{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:10px 12px;background:#fff;font-size:.9rem}.h2h-actions{display:flex;align-items:end;gap:10px}.h2h-actions button{background:#2563eb;color:white;border-radius:8px;padding:11px 20px;font-weight:600}.h2h-actions a{color:#475569;padding:10px}.h2h-summary{display:flex;gap:28px;flex-wrap:wrap;margin:24px 0 15px}.h2h-summary strong{display:block;font-size:1.35rem;color:#0f172a}.h2h-summary span{font-size:.78rem;color:#64748b}.h2h-table-wrap{overflow-x:auto;background:white;border:1px solid #e2e8f0;border-radius:12px}.h2h-table{width:100%;border-collapse:collapse;font-size:.83rem;text-align:left;min-width:700px}.h2h-table th{background:#f8fafc;color:#64748b;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em}.h2h-table th,.h2h-table td{padding:12px 14px;border-bottom:1px solid #e2e8f0}.h2h-table tr:last-child td{border-bottom:0}.h2h-score{font-weight:700;white-space:nowrap}.h2h-message{margin-top:24px;padding:18px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;color:#475569;font-size:.9rem}@media(max-width:640px){.h2h-form{grid-template-columns:1fr;padding:16px}.h2h-summary{gap:18px}.h2h-actions button{flex:1}}
</style>
<section class="h2h-page">
 <div class="h2h-intro"><h2>Head to Head</h2><p>Temukan riwayat pertemuan kedua tim dari matches.csv. Kandang dan tandang digabung.</p></div>
 <form class="h2h-form" method="get"><input type="hidden" name="page" value="h2h">
  <div class="h2h-field"><label for="h2h-home">Tim home</label><input id="h2h-home" name="home" list="h2h-teams" value="<?= $escape($home) ?>" placeholder="Contoh: Czech Republic" required autocomplete="off"></div>
  <div class="h2h-field"><label for="h2h-away">Tim away</label><input id="h2h-away" name="away" list="h2h-teams" value="<?= $escape($away) ?>" placeholder="Contoh: Netherlands" required autocomplete="off"></div>
  <datalist id="h2h-teams"><?php foreach ($teams as $team): ?><option value="<?= $escape($team) ?>"><?php endforeach; ?></datalist>
  <div class="h2h-field"><label for="h2h-league">Liga</label><select id="h2h-league" name="league"><option value="">Semua liga</option><?php foreach ($leagues as $item): ?><option value="<?= $escape($item) ?>" <?= $league === $item ? 'selected' : '' ?>><?= $escape($item) ?></option><?php endforeach; ?></select></div>
  <div class="h2h-actions"><button type="submit">Cari pertemuan</button><a href="index.php?page=h2h">Reset</a></div>
 </form>
 <?php if ($error): ?><p class="h2h-message" role="alert"><?= $escape($error) ?></p>
 <?php elseif ($home !== '' && $away !== ''): ?>
  <?php if (!$found): ?><p class="h2h-message">Tidak ada pertemuan ditemukan. Pilih nama tim dari saran dan periksa pilihan liga.</p>
  <?php else: ?>
  <?php foreach ($streaks as $streakLeague => $streak): if ($streak['count'] < 3) continue; ?>
   <div role="status" style="margin-top:20px;padding:16px 18px;border:1px solid #f59e0b;border-radius:10px;background:#fffbeb;color:#78350f"><strong>Perhatian: FT 0–0 <?= $streak['count'] ?> kali berturut-turut</strong><br><span style="font-size:.85rem"><?= $escape($home) ?> vs <?= $escape($away) ?> · <?= $escape($streakLeague) ?>. Dihitung dari pertemuan terbaru yang tersedia di CSV.</span></div>
  <?php endforeach; ?>
  <div class="h2h-summary"><div><strong><?= count($found) ?></strong><span>Pertemuan</span></div><div><strong><?= count($scored) ? number_format(100*$over/count($scored),1) . '%' : '—' ?></strong><span>FT Over 0.5 · <?= $over ?>/<?= count($scored) ?></span></div><div><strong><?= count($scored)-$over ?></strong><span>FT 0–0</span></div></div>
  <div class="h2h-table-wrap"><table class="h2h-table"><thead><tr><th>Waktu</th><th>Liga</th><th>Home</th><th>HT</th><th>FT</th><th>Away</th></tr></thead><tbody>
  <?php foreach ($found as $r): ?><tr><td><?= $escape($r['match_time']) ?></td><td><?= $escape($r['league']) ?></td><td><?= $escape($clean($r['home_team'])) ?></td><td class="h2h-score"><?= $escape(($r['fh_home'] !== '' && $r['fh_away'] !== '') ? $r['fh_home'].'–'.$r['fh_away'] : '—') ?></td><td class="h2h-score"><?= $escape(($r['ft_home'] !== '' && $r['ft_away'] !== '') ? $r['ft_home'].'–'.$r['ft_away'] : '—') ?></td><td><?= $escape($clean($r['away_team'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><p style="margin-top:10px;color:#64748b;font-size:.75rem">Waktu ditampilkan sesuai data di CSV; zona waktu dapat berbeda antar sumber.</p><?php endif; ?>
 <?php else: ?><p class="h2h-message">Masukkan dua nama tim untuk melihat seluruh pertemuan yang tersimpan.</p><?php endif; ?>
</section>
