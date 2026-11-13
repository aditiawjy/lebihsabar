const fs = require('fs');
const path = require('path');
const vm = require('vm');
const crypto = require('crypto');
const root = path.resolve(__dirname, '..');
const day = t => new Date(t - 4 * 3600000).toISOString().slice(0, 10);
const end = day(Date.now());
const start = new Date(Date.parse(end + 'T00:00:00Z') - 20 * 86400000).toISOString().slice(0, 10);
const split = new Date(Date.parse(end + 'T00:00:00Z') - 4 * 86400000).toISOString().slice(0, 10);
const stats = events => {
  const win = events.filter(e => e.check.result === 'BERHASIL').length;
  const fail = events.filter(e => e.check.result === 'TIDAK TERPENUHI').length;
  return { win, fail, total: win + fail, pending: events.length - win - fail, accuracy: win + fail ? win / (win + fail) * 100 : null };
};
const pct = s => s.accuracy === null ? '—' : s.accuracy.toFixed(1) + '%';
const quote = v => '"' + String(v ?? '').replaceAll('"', '""') + '"';

(async () => {
  const response = await fetch('http://localhost/lebihsabar/pattern-data.php', { cache: 'no-store' });
  if (!response.ok) throw new Error('CSV endpoint: ' + response.status);
  const data = await response.json();
  const source = fs.readFileSync(path.join(root, 'patternsaba.js'), 'utf8');
  const prefix = source.slice(0, source.indexOf('  // Konfigurasi Kotak Utama UI'));
  const context = { document: { createElement: () => ({}), head: { append() {} } } };
  vm.createContext(context);
  vm.runInContext(prefix + 'globalThis.evaluate=rows=>l16BuildEvents(rows);})();', context);
  const pair = (a, b) => /^\d+$/.test(a) && /^\d+$/.test(b) ? [+a, +b] : null;
  const rows = data.rows.filter(r => r.league === 'SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16 Mins Play').map((r, i) => ({
    id: String(i), league: r.league, t: Date.parse(r.match_time.replace(' ', 'T') + '+08:00'),
    teams: [r.home_team, r.away_team], fh: pair(r.fh_home, r.fh_away), ft: pair(r.ft_home, r.ft_away), complete: !!pair(r.ft_home, r.ft_away)
  }));
  const { events, rules } = context.evaluate(rows);
  const within = events.filter(e => day(e.r.t) >= start && day(e.r.t) <= end);
  const result = rules.map(rule => {
    const es = within.filter(e => e.rule.id === rule.id);
    const overall = stats(es), train = stats(es.filter(e => day(e.r.t) < split)), test = stats(es.filter(e => day(e.r.t) >= split));
    if (overall.total !== train.total + test.total) throw new Error(rule.id + ' count mismatch');
    return { code: rule.id, name: rule.name, market: rule.market, experimental: !!rule.experimental, overall, train, test };
  }).sort((a, b) => (b.overall.accuracy ?? -1) - (a.overall.accuracy ?? -1) || b.overall.total - a.overall.total);
  if (new Set(rules.map(r => r.id)).size !== rules.length) throw new Error('Duplicate rule ID');
  if (rules.some(r => ['L16-15', 'L16-22'].includes(r.id))) throw new Error('Deleted rule still active');
  const duplicateEvents = new Set();
  for (const e of within) {
    const key = e.rule.id + ':' + e.r.id;
    if (duplicateEvents.has(key)) throw new Error('Duplicate event: ' + key);
    duplicateEvents.add(key);
    if (e.rule.id === 'L16-30' && e.r.fh[0] + e.r.fh[1] !== 3) throw new Error('L16-30 predicate');
    if (e.rule.id === 'L16-35' && e.r.fh[0] + e.r.fh[1] > 1) throw new Error('L16-35 predicate');
    if (e.rule.id === 'L16-10' && e.r.fh[0] + e.r.fh[1] > 1) throw new Error('L16-10 predicate');
    if (e.rule.id === 'L16-11' && e.r.fh[0] === e.r.fh[1]) throw new Error('L16-11 predicate');
    if (e.rule.id === 'L16-36' && e.r.fh[0] !== e.r.fh[1]) throw new Error('L16-36 predicate');
  }
  const includedRows = rows.filter(r => day(r.t) >= start && day(r.t) <= end);
  const report = { generatedAt: new Date().toISOString(), start, split, end, matches: includedRows.length,
    sessions: new Set(includedRows.map(r => day(r.t))).size, rules: rules.length,
    csvSha256: crypto.createHash('sha256').update(fs.readFileSync(path.join(root, 'matches.csv'))).digest('hex'), results: result };
  fs.writeFileSync(path.join(__dirname, 'backtest_49_pola.json'), JSON.stringify(report, null, 2));
  let md = '# Backtest seluruh pola aktif League 16\n\nSesi **' + start + '–' + end + '**; data akhir diperiksa terpisah mulai **' + split + '**. ' + report.matches + ' pertandingan, ' + report.sessions + ' hari sesi, ' + report.rules + ' pola. Waktu SABA UTC+8, cutoff sesi 12:00. Riwayat sebelum rentang tetap dipakai untuk pemicu.\n\nPemisahan ini bukan validasi baru: data akhir pernah dipakai dalam riset dan pemilihan 23 kandidat baru. Akurasi kandidat baru telah terseleksi pada data yang sama sehingga bias optimistis. Belum ada validasi prospektif atau odds. Pola saling tumpang tindih; L16-31 adalah satu sinyal gabungan dua pertandingan, sehingga total seluruh pola bukan jumlah pertandingan unik.\n\n| Kode | Status | Market | Semua W/L | Akurasi | Sampel | Uji akhir W/L | Akurasi uji |\n|---|---|---|---:|---:|---:|---:|---:|\n';
  let csv = 'code,name,market,status,win,fail,total,accuracy,test_win,test_fail,test_total,test_accuracy\n';
  for (const r of result) {
    const a = r.overall, b = r.test;
    md += '| ' + [r.code, r.experimental ? 'UJI' : 'Aktif', r.market, `${a.win}/${a.fail}`, pct(a), a.total, `${b.win}/${b.fail}`, pct(b)].join(' | ') + ' |\n';
    csv += [r.code, r.name, r.market, r.experimental ? 'UJI' : 'Aktif', a.win, a.fail, a.total, a.accuracy, b.win, b.fail, b.total, b.accuracy].map(quote).join(',') + '\n';
  }
  fs.writeFileSync(path.join(__dirname, 'backtest_49_pola.md'), md);
  fs.writeFileSync(path.join(__dirname, 'backtest_49_pola.csv'), '\ufeff' + csv);
  console.log(JSON.stringify({ start, split, end, matches: report.matches, sessions: report.sessions, patterns: result.length,
    above80: result.filter(r => r.overall.accuracy >= 80).length,
    below80: result.filter(r => r.overall.accuracy !== null && r.overall.accuracy < 80),
    small: result.filter(r => r.overall.total < 10).map(r => ({ code: r.code, ...r.overall })),
    testBelow80: result.filter(r => r.test.total >= 8 && r.test.accuracy < 80).map(r => ({ code: r.code, ...r.test })) }));
})().catch(error => { console.error(error); process.exit(1); });
