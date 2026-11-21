<?php
$patternScriptVersion = filemtime(__DIR__ . '/patternsaba.js');
?>
<link rel="stylesheet" href="pattern.css?v=<?= (int) filemtime(__DIR__ . '/pattern.css') ?>">
<div class="pattern-page-intro">
  <h1>Hasil &amp; Pola Pertandingan</h1>
  <p>Pantau jadwal, skor, rekor tim, dan pola pertandingan dari hasil API.</p>
</div>
<section id="pattern-results-host">
  <div class="results-header">
    <div class="results-title-group">
    <h2 id="pattern-results-title">Hasil API · Semua League</h2>
      <span class="results-info">
        <button type="button" class="results-info-button" aria-label="Informasi rentang sesi dan pembaruan manual" aria-describedby="pattern-results-details">ⓘ</button>
        <span id="pattern-results-details" role="tooltip">Hasil diperbarui lewat tombol. Menit dan skor live diperbarui tiap 5 detik.</span>
      </span>
    </div>
  </div>
  <div id="pattern-live-warning" class="results-live-warning" role="status" hidden>
    <div><strong id="pattern-live-warning-title">Live terputus</strong><span id="pattern-live-warning-detail">Waktu pembaruan live terakhir belum tersedia.</span></div>
    <button id="pattern-live-retry" type="button">Coba sambungkan kembali</button>
  </div>
  <div class="results-brief">
    <p><span id="pattern-results-live-state" class="results-pill live-pill" role="status">Live…</span> <span id="pattern-results-api-state" class="results-pill" role="status">API memuat…</span> <span id="pattern-results-save-status" role="status" class="results-pill">▤ CSV menunggu</span> · Terakhir: <span id="pattern-results-last-updated">API belum tersedia</span></p>
  </div>
  <div class="results-controls">
    <label class="results-date-label">Tanggal <input id="pattern-results-date" type="date" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>"></label>
    <label class="results-team-search">Cari tim <input id="pattern-results-team-search" type="search" placeholder="Nama tim atau negara" autocomplete="off" aria-describedby="pattern-results-search-status"></label>
    <button id="pattern-results-refresh" type="button" class="results-primary" title="Ambil hasil terbaru untuk tanggal terpilih dan simpan otomatis ke matches.csv">Perbarui tanggal ini</button>
  </div>
  <details class="results-more">
    <summary>Filter lanjutan</summary>
    <div class="results-controls">
    <label class="results-team-search">Fase <select id="pattern-results-phase"><option value="all">Semua fase</option><option value="scheduled">Jadwal</option><option value="halftime">HT</option><option value="live">Live</option><option value="finished">Selesai</option><option value="postponed">Ditunda</option></select></label>
    <label class="results-team-search">Liga <select id="pattern-results-league-filter"><option value="all">Semua liga</option><option value="15">League 15</option><option value="16">League 16</option><option value="20">League 20</option><option value="vietnam15">Vietnam</option><option value="f24-club">F24 · Club</option><option value="f24-international">F24 · International</option><option value="f26-club">F26 · Club</option><option value="f26-international">F26 · International</option><option value="vsoccer">V-Soccer</option></select></label>
    <label class="results-team-search">Market hasil FT <select id="pattern-results-market-filter"><option value="all">Semua hasil</option><option value="zero">FT 0–0</option><option value="under15">FT Under 1.5</option><option value="over15">FT Over 1.5</option><option value="under25">FT Under 2.5</option><option value="over25">FT Over 2.5</option></select></label>
    <label class="results-team-search">Capaian rekor <select id="pattern-results-record-filter"><option value="all">Semua tim</option><option value="near">Mendekati (≥80%, belum tercapai)</option><option value="reached">Sudah mencapai rekor</option></select></label>
    </div>
    <p class="results-search-status">Filter market memakai skor FT pertandingan selesai. Rekor dihitung per hari (UTC+8).</p>
  </details>
  <p id="pattern-results-search-status" class="results-search-status" role="status" aria-live="polite" hidden></p>
  <p id="pattern-results-note" role="status" class="results-error-note" hidden>Memuat hasil API…</p>
  <p id="pattern-results-save-summary" role="status" aria-live="polite" class="results-save-summary" hidden></p>
  <p id="pattern-all-summary" role="status" aria-live="polite" class="results-save-summary" hidden></p>
  <details class="results-more">
    <summary>Info, status API &amp; alat data</summary>
  <div id="pattern-api-health" class="results-api-health" role="status" aria-live="polite">
    <strong id="pattern-api-health-title">Status API MSports: belum diperiksa</strong>
    <span id="pattern-api-health-detail">Memuat data tersimpan. Status koneksi baru diketahui setelah pembaruan API.</span>
  </div>
  <div class="results-controls">
    <button id="pattern-all-refresh" type="button" class="results-save-secondary is-blue" title="Ambil dan simpan hasil kemarin dan hari ini untuk semua liga, termasuk V-Soccer">Perbarui kemarin + hari ini</button>
    <button id="pattern-results-save" type="button" disabled class="results-save-secondary" title="Hanya perlu jika penyimpanan otomatis gagal" aria-describedby="pattern-results-save-help">Simpan ulang ke CSV</button>
  </div>
  <div class="results-context">
    <p class="results-timezone"><strong>Waktu pertandingan MSports: UTC+8</strong> · 1 jam lebih cepat dari WIB. V-Soccer menggunakan WIB (UTC+7).</p>
    <details class="results-legend"><summary>Informasi liga, sinkronisasi, dan istilah</summary>
      <p>League 15, League 20, dan E-Football memakai hari kalender. League 16 dan Vietnam memakai sesi 12.00–00.45 hari berikutnya; sebelum 12.00 UTC+8 yang tampil adalah sesi sebelumnya.</p>
      <p id="pattern-results-save-help"><strong>Perbarui tanggal ini</strong> mengambil hasil dan langsung menyimpannya ke matches.csv. <strong>Perbarui kemarin + hari ini</strong> melakukan hal yang sama untuk semua liga dan V-Soccer. <strong>Simpan ulang</strong> hanya perlu jika penyimpanan otomatis gagal. Data yang sama dilewati.</p>
      <p><strong>HT</strong>: skor babak pertama. <strong>FT</strong>: skor akhir. <strong>U1.5</strong>: Under 1.5 (0–1 gol). <strong>O0.5</strong>: Over 0.5 (minimal 1 gol). <strong>H2H</strong>: riwayat pertemuan. <strong>API saja</strong>: tanpa pembaruan live.</p>
    </details>
  </div>
  <p id="pattern-signal-history-status" class="results-search-status" role="status">Sinyal yang pernah tampil disimpan per pertandingan untuk evaluasi.</p>
  <details id="pattern-sync-history" class="results-coverage">
    <summary>Riwayat sinkronisasi</summary>
    <p>Riwayat penyimpanan ke matches.csv, per liga dan tanggal. Kegagalan pengambilan dicatat terpisah.</p>
    <button id="pattern-sync-history-refresh" type="button">Muat ulang riwayat</button>
    <p id="pattern-sync-history-status" role="status" aria-live="polite">Buka untuk memuat riwayat.</p>
    <div id="pattern-sync-history-list"></div>
  </details>
  <details id="pattern-coverage" class="results-coverage">
    <summary>Ringkasan data per tanggal dan liga</summary>
    <p>Jumlah hasil FT tersimpan di matches.csv (tanggal UTC+8). Hari kosong bisa berarti data belum disinkronkan.</p>
    <form id="pattern-coverage-form" class="coverage-controls">
      <label>Dari <input id="pattern-coverage-start" type="date" required></label>
      <label>Sampai <input id="pattern-coverage-end" type="date" required></label>
      <button type="submit">Tampilkan</button>
    </form>
    <p id="pattern-coverage-status" role="status" aria-live="polite">Pilih rentang maksimal 31 hari.</p>
    <div id="pattern-coverage-days"></div>
  </details>
  </details>
  <p id="pattern-results-live-note" hidden>Menghubungkan menit live…</p>
  <nav class="results-league-nav" aria-label="Navigasi liga">
    <button type="button" data-results-league="15">League 15</button><button type="button" data-results-league="16">League 16</button><button type="button" data-results-league="20">League 20</button><button type="button" data-results-league="vietnam15">Vietnam</button><button type="button" data-results-league="extra">E-Football</button><button type="button" data-results-league="vsoccer">V-Soccer</button>
  </nav>
  <div id="pattern-results-body" class="pattern-results-grid"></div>
  <details class="results-api-only-section" aria-labelledby="pattern-results-extra-title" open>
    <summary class="results-api-only-heading">
      <span id="pattern-results-extra-title">E-Football · API saja (4 liga)</span>
      <span>Data dari API; tidak tersedia pembaruan live</span>
    </summary>
    <div id="pattern-results-extra-body" class="pattern-results-grid results-extra-grid"></div>
  </details>
  <details id="pattern-vsoccer-section" class="results-api-only-section" open>
    <summary class="results-api-only-heading"><span id="pattern-vsoccer-title">V-Soccer · Hasil pertandingan</span><span>WIB (UTC+7) · data Results</span></summary>
    <div class="vsoccer-controls"><label>Liga <select id="pattern-vsoccer-league"><option value="">Semua liga</option></select></label><button id="pattern-vsoccer-refresh" type="button">Perbarui V-Soccer</button><span id="pattern-vsoccer-status" role="status">Memuat…</span></div>
    <p class="vsoccer-note">Skor HT dan FT dari Results V-Soccer, ikut disinkronkan ke matches.csv (waktu dikonversi ke UTC+8).</p>
    <div class="results-scroll vsoccer-scroll"><table class="vsoccer-table"><thead><tr><th>Jam WIB</th><th>Liga</th><th>Pertandingan</th><th>HT</th><th>FT</th></tr></thead><tbody id="pattern-vsoccer-rows"><tr><td colspan="5">Memuat hasil…</td></tr></tbody></table></div>
  </details>
</section>
<script src="pattern-signal-evaluation.js?v=<?= (int) filemtime(__DIR__.'/pattern-signal-evaluation.js') ?>" defer></script>
<script src="pattern-signal-history.js?v=<?= (int) filemtime(__DIR__.'/pattern-signal-history.js') ?>" defer></script>
<script src="pattern-recent-meetings.js?v=<?= (int) filemtime(__DIR__.'/pattern-recent-meetings.js') ?>" defer></script>
<script src="pattern-live-results.js?v=<?= (int) filemtime(__DIR__.'/pattern-live-results.js') ?>" defer></script>
<script src="vsoccer-results.js?v=<?= (int) filemtime(__DIR__.'/vsoccer-results.js') ?>" defer></script>
<script src="pattern-all-results.js?v=<?= (int) filemtime(__DIR__.'/pattern-all-results.js') ?>" defer></script>
<script src="pattern-data-coverage.js?v=<?= (int) filemtime(__DIR__.'/pattern-data-coverage.js') ?>" defer></script>
<script src="pattern-sync-history.js?v=<?= (int) filemtime(__DIR__.'/pattern-sync-history.js') ?>" defer></script>
<script src="pattern-results.js?v=<?= (int) filemtime(__DIR__.'/pattern-results.js') ?>" defer></script>
<!-- Engine mount only; analysis tables have been removed from the page. -->
<div id="pattern-table-host" hidden></div>
<script>
(() => {
    const prefix = 'sabaraja-pattern:';
    window.GM_getValue = function (key, fallback) {
        try {
            const value = localStorage.getItem(prefix + key);
            return value === null ? fallback : JSON.parse(value);
        } catch (_) {
            return fallback;
        }
    };
    window.GM_setValue = function (key, value) {
        localStorage.setItem(prefix + key, JSON.stringify(value));
    };
})();
</script>
<script src="patternsaba.js?v=<?= (int) $patternScriptVersion ?>" defer></script>
<script src="pattern-live.js?v=<?= (int) filemtime(__DIR__ . '/pattern-live.js') ?>" defer></script>

