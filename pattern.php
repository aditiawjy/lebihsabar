<?php
$patternScriptVersion = filemtime(__DIR__ . '/patternsaba.js');
?>
<div class="pattern-page-intro" style="margin:24px 24px 0">
  <h1 style="font-size:22px;font-weight:700;line-height:1.4;margin:0;color:#0f172a">Hasil &amp; Pola Pertandingan</h1>
  <p style="font-size:12px;line-height:1.6;margin:6px 0 0;color:#64748b">Pantau jadwal, skor, rekor tim, dan pola pertandingan dari hasil API.</p>
</div>
<section id="pattern-results-host" style="margin:20px 24px 0;padding:16px;background:#fff;border:1px solid #cbd5e1;border-radius:12px">
  <div class="results-header">
    <div class="results-title-group">
    <h2 id="pattern-results-title" style="font-size:16px;font-weight:600;margin:0">Hasil API · Semua League</h2>
      <span class="results-info">
        <button type="button" class="results-info-button" aria-label="Informasi rentang sesi dan pembaruan otomatis" aria-describedby="pattern-results-details">ⓘ</button>
        <span id="pattern-results-details" role="tooltip">L15/L20: tanggal kalender UTC+8. L16/Vietnam: sesi 12:00–00:45 hari berikutnya. Hasil API diperbarui setiap 5 menit; menit dan skor live setiap 5 detik.</span>
      </span>
    </div>
  </div>
  <div id="pattern-live-warning" class="results-live-warning" role="status" hidden>
    <div><strong id="pattern-live-warning-title">Live terputus</strong><span id="pattern-live-warning-detail">Waktu pembaruan live terakhir belum tersedia.</span></div>
    <button id="pattern-live-retry" type="button">Coba sambungkan kembali</button>
  </div>
  <div class="results-brief">
    <p><strong>Sesi</strong> Kalender: L15, L20, E-Football · Sesi: L16, Vietnam 12.00–00.45 UTC+8</p>
    <p><strong>Pembaruan terakhir</strong> <span id="pattern-results-last-updated">API belum tersedia</span> · otomatis setiap 5 menit</p>
    <p><strong>Status sumber</strong> <span id="pattern-results-live-state" class="results-pill live-pill" role="status">Live…</span> <span id="pattern-results-api-state" class="results-pill" role="status">API memuat…</span> <span id="pattern-results-save-status" role="status" class="results-pill">▤ CSV menunggu</span></p>
  </div>
  <div id="pattern-api-health" class="results-api-health" role="status" aria-live="polite">
    <strong id="pattern-api-health-title">Status API MSports: belum diperiksa</strong>
    <span id="pattern-api-health-detail">Memuat data tersimpan. Status koneksi baru diketahui setelah pembaruan API.</span>
  </div>
  <div class="results-controls">
    <label style="font-size:12px">Tanggal <input id="pattern-results-date" type="date" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" style="padding:7px;border:1px solid #cbd5e1;border-radius:6px"></label>
    <label class="results-team-search">Cari tim <input id="pattern-results-team-search" type="search" placeholder="Nama tim atau negara" autocomplete="off" aria-describedby="pattern-results-search-status"></label>
    <label class="results-team-search">Fase <select id="pattern-results-phase"><option value="all">Semua fase</option><option value="scheduled">Jadwal</option><option value="halftime">HT</option><option value="live">Live</option><option value="finished">Selesai</option><option value="postponed">Ditunda</option></select></label>
    <label class="results-team-search">Liga <select id="pattern-results-league-filter"><option value="all">Semua liga</option><option value="15">League 15</option><option value="16">League 16</option><option value="20">League 20</option><option value="vietnam15">Vietnam</option><option value="f24-club">F24 · Club</option><option value="f24-international">F24 · International</option><option value="f26-club">F26 · Club</option><option value="f26-international">F26 · International</option><option value="vsoccer">V-Soccer</option></select></label>
    <label class="results-team-search">Market hasil FT <select id="pattern-results-market-filter"><option value="all">Semua hasil</option><option value="zero">FT 0–0</option><option value="under15">FT Under 1.5</option><option value="over15">FT Over 1.5</option><option value="under25">FT Under 2.5</option><option value="over25">FT Over 2.5</option></select></label>
    <label class="results-team-search">Capaian rekor <select id="pattern-results-record-filter"><option value="all">Semua tim</option><option value="near">Mendekati (≥80%, belum tercapai)</option><option value="reached">Sudah mencapai rekor</option></select></label>
    <button id="pattern-results-refresh" type="button" style="padding:8px 12px;background:#2563eb;color:white;border:0;border-radius:6px;cursor:pointer">Perbarui dari API</button>
    <button id="pattern-all-refresh" type="button" style="padding:8px 12px;background:#1d4ed8;color:white;border:0;border-radius:6px;cursor:pointer">Perbarui semua data</button>
    <button id="pattern-results-save" type="button" disabled class="results-save-secondary" aria-describedby="pattern-results-save-help">Sinkronkan hasil ke matches.csv</button>
  </div>
  <p id="pattern-results-search-status" class="results-search-status" role="status" aria-live="polite" hidden></p>
  <p class="results-search-status">Market menyaring skor FT pertandingan selesai. Rekor dihitung per hari UTC+8 untuk FT 0–0 dan Under 1.5; filter rekor tersedia untuk liga MSports.</p>
  <div class="results-context">
    <p class="results-timezone"><strong>Waktu pertandingan MSports: UTC+8</strong> · 1 jam lebih cepat dari WIB. V-Soccer menggunakan WIB (UTC+7).</p>
    <details class="results-legend"><summary>Informasi liga, sinkronisasi, dan istilah</summary>
      <p>Filter tanggal memakai hari kalender untuk League 15, League 20, dan E-Football. League 16 dan Vietnam memakai sesi 12.00–00.45 hari berikutnya; sebelum 12.00 UTC+8, tanggal hari ini menampilkan sesi sebelumnya. Rentang setiap liga tercantum di bawah namanya.</p>
      <p id="pattern-results-save-help">Sinkronisasi menambahkan hasil selesai yang baru, memperbarui skor yang berubah, dan melewati hasil yang sudah sama. Sinkronisasi juga berjalan otomatis setelah pembaruan API.</p>
      <p><strong>HT</strong>: skor babak pertama. <strong>FT</strong>: skor akhir. <strong>U1.5</strong>: Under 1.5, total 0 atau 1 gol. <strong>O0.5</strong>: Over 0.5, minimal 1 gol. <strong>H2H</strong>: riwayat pertemuan kedua tim. <strong>API saja</strong>: data dari API; tidak tersedia pembaruan live.</p>
    </details>
  </div>
  <p id="pattern-results-note" role="status" class="results-error-note" hidden>Memuat hasil API…</p>
  <p id="pattern-results-save-summary" role="status" aria-live="polite" class="results-save-summary" hidden></p>
  <p id="pattern-all-summary" role="status" aria-live="polite" class="results-save-summary" hidden></p>
  <details id="pattern-sync-history" class="results-coverage">
    <summary>Riwayat sinkronisasi</summary>
    <p>Catatan baru berlaku sejak fitur ini dipasang. Setiap penyimpanan ditampilkan per kelompok data; liga dan tanggal adalah yang benar-benar dikirim ke CSV. Kegagalan pengambilan dicatat terpisah.</p>
    <button id="pattern-sync-history-refresh" type="button">Muat ulang riwayat</button>
    <p id="pattern-sync-history-status" role="status" aria-live="polite">Buka untuk memuat riwayat.</p>
    <div id="pattern-sync-history-list"></div>
  </details>
  <details id="pattern-coverage" class="results-coverage">
    <summary>Ringkasan data per tanggal dan liga</summary>
    <p>Jumlah adalah hasil FT yang tersimpan di matches.csv, berdasarkan tanggal UTC+8. Hari kosong belum tentu tidak ada pertandingan; data mungkin belum tersinkron. Sesi League 16 dan Vietnam dapat melintasi tengah malam.</p>
    <form id="pattern-coverage-form" class="coverage-controls">
      <label>Dari <input id="pattern-coverage-start" type="date" required></label>
      <label>Sampai <input id="pattern-coverage-end" type="date" required></label>
      <button type="submit">Tampilkan</button>
    </form>
    <p id="pattern-coverage-status" role="status" aria-live="polite">Pilih rentang maksimal 31 hari.</p>
    <div id="pattern-coverage-days"></div>
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
    <p class="vsoccer-note">Skor HT dan FT dari Results V-Soccer. Hasil selesai ikut disinkronkan ke matches.csv; skor HT yang tidak tersedia disimpan kosong. Waktu di CSV dikonversi ke UTC+8.</p>
    <div class="results-scroll vsoccer-scroll"><table class="vsoccer-table"><thead><tr><th>Jam WIB</th><th>Liga</th><th>Pertandingan</th><th>HT</th><th>FT</th></tr></thead><tbody id="pattern-vsoccer-rows"><tr><td colspan="5">Memuat hasil…</td></tr></tbody></table></div>
  </details>
</section>
<style>
#pattern-results-host .results-under15-record{background:#f5f3ff!important;color:#6d28d9!important;border:1px solid #c4b5fd;border-radius:5px;padding:4px 7px;white-space:normal;line-height:1.6}

#pattern-results-host .results-zero-record{background:#fff7ed!important;color:#9a3412!important;border:1px solid #fdba74;border-radius:5px;padding:4px 7px;white-space:normal;line-height:1.6}

#pattern-results-host .results-header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}
#pattern-results-host .results-live-warning{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:#fff7ed;color:#9a3412;border:1px solid #fdba74;border-radius:8px;padding:12px;margin:0 0 12px;font-size:12px}
#pattern-results-host .results-live-warning[hidden]{display:none}
#pattern-results-host .results-live-warning strong,#pattern-results-host .results-live-warning span{display:block}
#pattern-results-host .results-live-warning button{padding:7px 10px;background:white;border:1px solid #fb923c;border-radius:6px;color:#9a3412;font-weight:600}
#pattern-results-host .results-brief{display:grid;gap:5px;margin:0 0 14px;color:#475569;font-size:11px;line-height:1.6}
#pattern-results-host .results-brief p{margin:0}
#pattern-results-host .results-api-health{display:grid;gap:4px;margin:0 0 14px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;color:#334155;font-size:12px;line-height:1.5}
#pattern-results-host .results-api-health.is-ok{background:#f0fdf4;border-color:#86efac;color:#166534}
#pattern-results-host .results-api-health.is-error{background:#fff7ed;border-color:#fdba74;color:#9a3412}
#pattern-results-host .results-coverage{margin:0 0 14px;padding:12px;border:1px solid #cbd5e1;border-radius:8px;color:#334155;font-size:12px}
#pattern-results-host .results-coverage summary{cursor:pointer;font-weight:700;color:#1e40af}
#pattern-results-host .results-coverage p{line-height:1.6;margin:8px 0;color:#64748b}
#pattern-results-host .coverage-controls{display:flex;flex-wrap:wrap;align-items:end;gap:8px}
#pattern-results-host .coverage-controls label{display:grid;gap:4px}
#pattern-results-host .coverage-controls input,#pattern-results-host .coverage-controls button{padding:7px;border:1px solid #cbd5e1;border-radius:6px;background:white;color:#334155}
#pattern-results-host .coverage-day{border-top:1px solid #e2e8f0;padding:9px 0}
#pattern-results-host .coverage-day summary{color:#334155}
#pattern-results-host .coverage-day ul{margin:8px 0;padding-left:20px;line-height:1.7}
#pattern-results-host .coverage-empty{color:#9a3412}
#pattern-results-host .results-brief strong{display:inline-block;min-width:112px;color:#334155}
#pattern-results-host .results-league-nav{display:flex;gap:7px;flex-wrap:wrap;margin:0 0 14px}
#pattern-results-host .results-league-nav button{padding:7px 10px;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:7px;font-size:11px;font-weight:600}
#pattern-results-host .vsoccer-controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:11px;margin-bottom:8px}
#pattern-results-host .vsoccer-controls select,#pattern-results-host .vsoccer-controls button{padding:7px 9px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#334155}
#pattern-results-host .vsoccer-controls button{color:#1d4ed8;border-color:#93c5fd}
#pattern-results-host #pattern-vsoccer-status{color:#64748b}
#pattern-results-host .vsoccer-note{font-size:11px;line-height:1.6;color:#64748b;margin:0 0 10px}
#pattern-results-host .vsoccer-scroll{border:1px solid #e2e8f0;border-radius:8px}
#pattern-results-host .vsoccer-table{min-width:650px;table-layout:auto}
#pattern-results-host .vsoccer-table th,#pattern-results-host .vsoccer-table td{width:auto;text-align:left;white-space:normal;padding:7px 9px}
#pattern-results-host .vsoccer-table th:first-child,#pattern-results-host .vsoccer-table td:first-child,#pattern-results-host .vsoccer-table th:nth-child(4),#pattern-results-host .vsoccer-table td:nth-child(4),#pattern-results-host .vsoccer-table th:nth-child(5),#pattern-results-host .vsoccer-table td:nth-child(5){white-space:nowrap;width:1%;text-align:center}
#pattern-results-host .results-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:5px;padding:8px 10px 10px}
#pattern-results-host .results-record-group{margin:0 10px 10px;padding:7px 9px;border:1px solid #fcd34d;border-radius:7px;background:#fffbeb;color:#92400e;font-size:10px;line-height:1.5}
#pattern-results-host .results-record-group[hidden]{display:none}
#pattern-results-host .results-record-group summary{cursor:pointer;font-weight:700}
#pattern-results-host .results-record-detail{margin-top:6px;padding-top:6px;border-top:1px solid #fde68a}
#pattern-results-host .results-record-detail summary{font-weight:600}
#pattern-results-host .results-record-detail p{padding:4px 0;margin:0;color:#78350f;font-size:10px}
#pattern-results-host .results-record-detail ul{margin:4px 0;padding-left:16px}
#pattern-results-host .results-summary.is-api-only{grid-template-columns:repeat(3,minmax(0,1fr))}
#pattern-results-host .results-summary-item{min-width:0;padding:6px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px}
#pattern-results-host .results-summary-item span{display:block;font-size:9px;line-height:1.3;color:#64748b}
#pattern-results-host .results-summary-item strong{display:block;margin-top:3px;font-size:12px;line-height:1.3;color:#1e293b;font-variant-numeric:tabular-nums}
#pattern-results-host .results-summary-item.is-zero strong{color:#9a3412}
#pattern-results-host .results-summary-item small{display:block;color:#9a3412;font-size:9px;line-height:1.3}
@media(max-width:420px){#pattern-results-host .results-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
#pattern-results-host .results-title-group,#pattern-results-host .results-statuses,#pattern-results-host .results-controls{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
#pattern-results-host .results-controls{gap:12px;margin-bottom:12px}
#pattern-results-host .results-team-search{display:inline-flex;align-items:center;gap:7px;font-size:12px}
#pattern-results-host .results-team-search input{width:200px;max-width:60vw;padding:7px 9px;border:1px solid #cbd5e1;border-radius:6px;color:#0f172a;background:#fff}
#pattern-results-host .results-team-search select{padding:7px 9px;border:1px solid #cbd5e1;border-radius:6px;color:#0f172a;background:#fff}
#pattern-results-host .results-search-status{margin:0 0 12px;font-size:11px;color:#475569}
#pattern-results-host .results-context{margin:0 0 14px;padding:10px 12px;border:1px solid #dbeafe;border-radius:8px;background:#f8fafc;color:#475569;font-size:11px;line-height:1.6}
#pattern-results-host .results-context p{margin:3px 0}
#pattern-results-host .results-context .results-timezone{color:#1e40af;font-size:12px}
#pattern-results-host .results-legend{margin-top:5px}
#pattern-results-host .results-legend summary{cursor:pointer;color:#1d4ed8;font-weight:600}
#pattern-results-host .results-legend p{margin:6px 0 0}
#pattern-results-host .results-save-summary{margin:0 0 12px;padding:9px 12px;border:1px solid #a7f3d0;border-radius:7px;background:#ecfdf5;color:#065f46;font-size:12px;line-height:1.5}
#pattern-results-host .results-save-summary.is-error{border-color:#fecaca;background:#fef2f2;color:#991b1b}
#pattern-results-host .results-pill{display:inline-flex;align-items:center;padding:4px 8px;border:1px solid #e2e8f0;border-radius:999px;background:#f8fafc;color:#64748b;font-size:10px;line-height:1.4;font-variant-numeric:tabular-nums;white-space:nowrap}
#pattern-results-host .results-pill[hidden]{display:none}
#pattern-results-host .results-pill.is-ok{background:#ecfdf5;border-color:#bbf7d0;color:#166534}
#pattern-results-host .results-pill.is-error{background:#fffbeb;border-color:#fde68a;color:#92400e}
#pattern-results-host .live-pill::before{content:'';width:6px;height:6px;margin-right:5px;border-radius:50%;background:currentColor}
#pattern-results-host .live-pill.is-ok::before{animation:results-live-pulse 2.4s ease-in-out infinite}
@keyframes results-live-pulse{0%,100%{opacity:1}50%{opacity:.35}}
@media(prefers-reduced-motion:reduce){#pattern-results-host .live-pill.is-ok::before{animation:none}}
#pattern-results-host .results-error-note{font-size:12px;color:#92400e;margin:0 0 12px}
#pattern-results-host .results-save-secondary{padding:8px 12px;background:white;color:#047857;border:1px solid #a7d8c7;border-radius:6px;cursor:pointer}
#pattern-results-host .results-save-secondary:hover:not(:disabled){background:#ecfdf5;border-color:#047857}
#pattern-results-host .results-info{position:relative;display:inline-flex}
#pattern-results-host .results-info-button{border:0;background:transparent;color:#64748b;padding:3px 5px;cursor:help;font-size:16px;line-height:1}
#pattern-results-host .results-info-button:focus-visible{outline:2px solid #2563eb;outline-offset:2px;border-radius:4px}
#pattern-results-details{display:none;position:absolute;left:0;top:100%;z-index:40;width:min(420px,75vw);padding:12px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;box-shadow:0 8px 24px #0f172a1a;font-size:11px;line-height:1.7;white-space:pre-line}
#pattern-results-host .results-info:hover #pattern-results-details,#pattern-results-host .results-info:focus-within #pattern-results-details{display:block}
#pattern-results-host th,#pattern-results-host td{padding:7px 4px;border-bottom:1px solid #e2e8f0;text-align:left}
#pattern-results-host th{position:sticky;top:0;z-index:2;background:#f1f5f9;color:#475569;box-shadow:inset 0 -1px #cbd5e1}
#pattern-results-host th:not(:nth-child(2)),#pattern-results-host td:not(:nth-child(2)){white-space:nowrap;width:1%;font-variant-numeric:tabular-nums}
#pattern-results-host th:first-child,#pattern-results-host td:first-child{width:68px}
#pattern-results-host th:nth-child(3),#pattern-results-host td:nth-child(3),#pattern-results-host th:nth-child(4),#pattern-results-host td:nth-child(4){width:40px;text-align:center;font-variant-numeric:tabular-nums;white-space:nowrap}
#pattern-results-host button:disabled{opacity:.6;cursor:wait!important}
#pattern-results-host .pattern-results-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
#pattern-results-host .results-card{min-width:0;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden}
#pattern-results-host .results-card h3{font-size:14px;font-weight:600;margin:0;padding:12px;background:#eff6ff;color:#1e40af}
#pattern-results-host .results-card-heading{display:flex;align-items:center;justify-content:space-between;gap:8px}
#pattern-results-host .results-collapse{flex-shrink:0;padding:4px 7px;border:1px solid #bfdbfe;border-radius:5px;background:#fff;color:#1d4ed8;font-size:10px;font-weight:600}
#pattern-results-host .results-card [hidden]{display:none!important}
#pattern-results-host .results-api-only-section{border-top:1px solid #e2e8f0;margin-top:18px;padding-top:16px}
#pattern-results-host .results-api-only-heading{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;margin-bottom:12px}
#pattern-results-host .results-api-only-heading h2{font-size:15px;font-weight:600;margin:0;color:#0f172a}
#pattern-results-host .results-api-only-heading>span{font-size:11px;color:#64748b}
#pattern-results-host summary.results-api-only-heading{cursor:pointer;align-items:center;list-style:none;padding:6px 0;margin:0}
#pattern-results-host summary.results-api-only-heading::before{content:'▸';color:#64748b}
#pattern-results-host details[open]>summary.results-api-only-heading::before{content:'▾'}
#pattern-results-host details[open]>summary.results-api-only-heading{margin-bottom:12px}
#pattern-results-host summary #pattern-results-extra-title{font-size:14px;font-weight:600;color:#0f172a}
#pattern-results-host summary.results-api-only-heading:focus-visible{outline:2px solid #2563eb;outline-offset:4px;border-radius:4px}
#pattern-results-host .results-zerozero-metric{color:#92400e;font-weight:600}
#pattern-results-host .results-extra-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
#pattern-results-host .results-extra-grid .results-card{margin-bottom:14px}
#pattern-results-host .results-api-only-tag{display:inline-block;margin-left:8px;padding:2px 5px;border:1px solid #cbd5e1;border-radius:4px;background:white;color:#64748b;font-size:9px;font-weight:500;vertical-align:middle}
#pattern-results-host .api-only-card .results-zerozero-count{min-height:0;padding:0}
#pattern-results-host .api-only-card .results-scroll{max-height:240px}
#pattern-results-host .results-card p{font-size:11px;color:#64748b;padding:8px 10px;margin:0}
#pattern-results-host .results-scroll{position:relative;isolation:isolate;max-height:350px;overflow-y:auto;overflow-x:hidden;scrollbar-width:thin;scrollbar-color:#cbd5e1 transparent;scrollbar-gutter:stable}
#pattern-results-host .results-scroll::-webkit-scrollbar{width:5px;height:5px}
#pattern-results-host .results-scroll::-webkit-scrollbar-track{background:transparent}
#pattern-results-host .results-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:99px}
#pattern-results-host .results-scroll::-webkit-scrollbar-thumb:hover{background:#94a3b8}
#pattern-results-host .results-scroll::-webkit-scrollbar-button{display:none;width:0;height:0}
#pattern-results-host table{width:100%;table-layout:fixed;border-collapse:collapse;font-size:11px}
#pattern-results-host .result-time-cell{font-size:10px}
#pattern-results-host .result-match-day{display:block;color:#64748b;font-size:9px}
#pattern-results-host .results-card .results-session-range{padding:7px 10px 0;color:#1e40af;font-size:10px}
#pattern-results-host .results-session-range .results-source-note{display:block;margin-top:4px;color:#64748b;font-size:10px;line-height:1.5}
#pattern-results-host .result-state{display:block;width:max-content;max-width:100%;margin-top:4px;padding:2px 5px;border:1px solid transparent;border-radius:5px;font-size:9px;font-weight:700;line-height:1.35;white-space:normal}
#pattern-results-host .result-state-scheduled{background:#f1f5f9;color:#64748b}
#pattern-results-host .result-state-halftime{background:#dbeafe;color:#1d4ed8}
#pattern-results-host .result-state-finished{background:#e0f2fe;color:#075985;border-color:#bae6fd}
#pattern-results-host .result-state-postponed{background:#fee2e2;color:#b91c1c}
#pattern-results-host .result-state-live{background:#dcfce7;color:#166534}
#pattern-results-host .result-state-stale{background:#fef3c7;color:#92400e}
#pattern-results-host .result-live-row td{background:#f0fdf4}
#pattern-results-host .result-stale-row td{background:#fffbeb}
#pattern-results-host .result-live-hint{display:block;margin-top:4px;color:#64748b;font-size:9px}
#pattern-results-host .result-live-signal{display:block;margin-top:4px;padding:3px 4px;border-radius:4px;background:#dbeafe;color:#1e40af;font-size:9px;line-height:1.4}
#pattern-results-host .result-live-signal.is-active{background:#dcfce7;color:#166534;border:1px solid #86efac;font-size:10px;font-weight:600;animation:pattern-signal-glow 2s ease-in-out infinite}
#pattern-results-host .result-live-signal.is-achieved{background:#eff6ff;color:#1e40af}
@keyframes pattern-signal-glow{50%{box-shadow:0 0 0 2px #bbf7d0}}
@media(prefers-reduced-motion:reduce){#pattern-results-host .result-live-signal.is-active{animation:none}}
.live-ou-markets{display:flex;flex-direction:column;align-items:flex-start;gap:3px;margin-top:5px}
.live-ou-market{display:inline-block;padding:3px 5px;border:1px solid #dbeafe;border-radius:5px;background:#eff6ff;color:#334155;font-size:9px;line-height:1.5;font-variant-numeric:tabular-nums;white-space:normal;overflow-wrap:anywhere}
.live-ou-market strong{color:#1e40af;font-size:9px;margin-right:3px;white-space:nowrap}
.live-ou-market.live-ou-unavailable{background:#fffbeb;border-color:#fde68a;color:#92400e}
.live-ou-missing{font-size:9px;color:#94a3b8}
#pattern-results-host .result-history-alerts{display:flex;flex-wrap:wrap;gap:3px;margin-top:4px}
#pattern-results-host .result-history-alert{display:inline-flex;align-items:center;gap:4px;padding:2px 5px;border-radius:4px;font-size:9px;font-weight:600;line-height:1.4;white-space:nowrap;font-variant-numeric:tabular-nums;cursor:help}
#pattern-results-host .result-history-alert::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;flex:none}
#pattern-results-host .alert-zerozero{background:#fffbeb;color:#92400e}
#pattern-results-host .alert-zerozero.is-high-frequency{background:#b91c1c;color:#fff}
#pattern-results-host .alert-zerozero.is-high-frequency::before{display:none}
#pattern-results-host .alert-under15{background:#fff7ed;color:#c2410c}
#pattern-results-host .results-zerozero-count{display:flex;align-items:center;flex-wrap:wrap;gap:4px;box-sizing:border-box;min-height:34px;padding:0 10px 10px;font-size:10px}
#pattern-results-host .results-zerozero-label{color:#64748b;margin-right:2px;font-weight:600}
#pattern-results-host .results-zerozero-empty{color:#94a3b8}
#pattern-results-host .results-zerozero-chip{display:inline-flex;padding:2px 6px;border:1px solid #fde68a;border-radius:999px;background:#fffbeb;color:#92400e;line-height:1.5}
#pattern-results-host .results-zerozero-chip.is-high-frequency{background:#b91c1c;border-color:#b91c1c;color:#fff;font-weight:600}
#pattern-results-host .results-zerozero-chip.results-under-streak{background:#ede9fe;border-color:#c4b5fd;color:#5b21b6;font-weight:600}
#pattern-results-host .result-h2h-badge{display:block;width:fit-content;margin-top:4px;padding:3px 6px;border-radius:5px;background:#ecfdf5;color:#166534;border:1px solid #bbf7d0;font-size:10px;font-weight:600;line-height:1.5}
#pattern-results-host .result-h2h-badge.is-perfect-history{background:#065f46;color:#fff;border-color:#047857;font-weight:700}
#pattern-results-host .result-h2h-badge{cursor:pointer;list-style:none}
#pattern-results-host .result-h2h-badge::before{content:'▸ ';}
#pattern-results-host .result-h2h-detail[open]>.result-h2h-badge::before{content:'▾ ';}
#pattern-results-host .result-h2h-detail-body{margin-top:4px;padding:7px;border:1px solid #cbd5e1;border-radius:6px;background:#f8fafc;color:#334155;font-size:10px;line-height:1.5;overflow-wrap:anywhere}
#pattern-results-host .result-h2h-detail-body p{padding:0;margin:0 0 6px;color:inherit;font-size:10px}
#pattern-results-host .result-h2h-detail-body ul{margin:4px 0 0;padding-left:16px}
#pattern-results-host .results-vietnam-card{margin-top:24px}
#pattern-results-host .pattern-results-grid{align-items:start}
@media(max-width:1200px){#pattern-results-host .pattern-results-grid{grid-template-columns:minmax(0,1fr)}}
</style>
<script src="pattern-live-results.js?v=<?= (int) filemtime(__DIR__.'/pattern-live-results.js') ?>" defer></script>
<script src="vsoccer-results.js?v=<?= (int) filemtime(__DIR__.'/vsoccer-results.js') ?>" defer></script>
<script src="pattern-all-results.js?v=<?= (int) filemtime(__DIR__.'/pattern-all-results.js') ?>" defer></script>
<script src="pattern-data-coverage.js?v=<?= (int) filemtime(__DIR__.'/pattern-data-coverage.js') ?>" defer></script>
<script src="pattern-sync-history.js?v=<?= (int) filemtime(__DIR__.'/pattern-sync-history.js') ?>" defer></script>
<script src="pattern-results.js?v=<?= (int) filemtime(__DIR__.'/pattern-results.js') ?>" defer></script>
<!-- Engine mount only; analysis tables have been removed from the page. -->
<div id="pattern-table-host" hidden></div>
<style>
#pattern-table-host .after-l1-badge { display:inline-block; width:max-content; margin-left:6px; padding:2px 5px; border-radius:5px; background:#ffedd5; color:#c2410c; font-size:9px; font-weight:800; vertical-align:middle; white-space:nowrap; }
#pattern-table-host tr.pattern-live-active td { background:#ecfdf5; }
#pattern-table-host .pattern-live-marker { display:block; width:max-content; margin-top:5px; padding:3px 6px; background:#bbf7d0; color:#166534; border-radius:5px; font-size:10px; font-weight:700; }
#pattern-table-host .pattern-trigger-badges { display:flex; flex-wrap:wrap; gap:4px; margin-top:6px; }
#pattern-table-host .pattern-trigger-block{width:100%;min-width:0}
#pattern-table-host .pattern-trigger-description{display:block;margin-top:4px;font-size:10px;line-height:1.5;color:#64748b}
#pattern-table-host .trigger-live{background:#dbeafe;color:#1e40af}
#pattern-table-host .pattern-trigger-badge { border:1px solid transparent; padding:3px 5px; border-radius:5px; font:600 10px/1.4 system-ui,sans-serif; white-space:nowrap; cursor:pointer; }
#pattern-table-host .trigger-win { background:#d1fae5; color:#065f46; }
#pattern-table-host .trigger-fail { background:#fee2e2; color:#991b1b; }
#pattern-table-host .trigger-pending { background:#f1f5f9; color:#475569; }
#pattern-table-host .pattern-trigger-badge[aria-pressed="true"] { border-color:currentColor; }
#pattern-table-host .pattern-trigger-badge:focus-visible { outline:2px solid #2563eb; outline-offset:2px; }
#pattern-table-host .pattern-match-selection { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin:0 0 12px; padding:10px; background:#fef9c3; color:#854d0e; border-radius:8px; font-size:12px; }
#pattern-table-host .pattern-match-selection button { font:inherit; color:inherit; background:transparent; border:0; text-decoration:underline; cursor:pointer; }
#pattern-table-host tr.pattern-trigger-match td { background:#fefce8; }
#pattern-table-host .pattern-matches-table { table-layout:fixed; }
#pattern-table-host .pattern-matches-table th, #pattern-table-host .pattern-matches-table td { padding:12px 6px; }
#pattern-table-host .pattern-matches-table .match-time { width:56px; white-space:nowrap; font-variant-numeric:tabular-nums; }
#pattern-table-host .pattern-matches-table .match-score { width:38px; white-space:nowrap; text-align:center; font-variant-numeric:tabular-nums; }
#pattern-table-host .pattern-matches-table .match-date { width:90px; white-space:nowrap; font-variant-numeric:tabular-nums; }
#pattern-table-host .pattern-matches-table .match-teams { overflow-wrap:anywhere; }
#pattern-table-host .pattern-match-state{display:block;margin-top:4px;color:#64748b;font-size:9px;font-weight:600}
#pattern-table-host tr.pattern-match-live td{background:#f0fdf4}
#pattern-table-host tr.pattern-match-stale td{background:#fffbeb}
#pattern-table-host .pattern-matches-table th.match-score{font-size:10px}
#pattern-table-host .pattern-clickable-row { cursor:pointer; }
#pattern-table-host .pattern-clickable-row:hover .pattern-rule-button { color:#2563eb; text-decoration:underline; }
#pattern-table-host .pattern-clickable-row .pattern-rule-button { color:#1d4ed8; }
#pattern-table-host .pattern-rule-button:focus-visible { outline:2px solid #2563eb; outline-offset:3px; }
#pattern-table-host .pattern-last-date { display:block; margin-top:12px; padding:8px 10px; border:1px solid #bfdbfe; border-radius:8px; background:#eff6ff; color:#1d4ed8; font:inherit; cursor:pointer; }
#pattern-table-host tr.pattern-active td { background:#ecfdf5; }
#pattern-table-host .pattern-signal { display:block; width:max-content; margin-top:5px; padding:3px 6px; border-radius:6px; color:#047857; background:#d1fae5; font-size:10px; font-weight:700; }
#pattern-table-host .pattern-result { display:inline-block; margin-right:3px; padding:2px 4px; border-radius:4px; font-size:10px; font-weight:700; }
#pattern-table-host .result-win { color:#047857; background:#d1fae5; }
#pattern-table-host .result-fail { color:#b91c1c; background:#fee2e2; }
#pattern-table-host .pattern-history { padding:16px; border-top:1px solid #e2e8f0; font-size:12px; }
#pattern-table-host .pattern-history summary { cursor:pointer; font-weight:600; color:#2563eb; }
#pattern-table-host .pattern-history-list { max-height:320px; overflow:auto; margin-top:12px; }
#pattern-table-host .pattern-history-list p { padding:10px 0; border-bottom:1px solid #e2e8f0; line-height:1.6; }
#pattern-table-host .pattern-rules-table th, #pattern-table-host .pattern-rules-table td { padding:12px 5px; }
#pattern-table-host .pattern-rules-table th:nth-child(2) { width:100%; }
#pattern-table-host .pattern-rules-table td:nth-child(2) { min-width:180px; line-height:1.6; }
#pattern-table-host .pattern-rules-table th:nth-child(n+3), #pattern-table-host .pattern-rules-table td:nth-child(n+3) { white-space:nowrap; width:1%; }
#pattern-table-host .pattern-rules-table th:nth-child(n+4), #pattern-table-host .pattern-rules-table td:nth-child(n+4) { text-align:right; font-variant-numeric:tabular-nums; }
#pattern-table-host .pattern-market-badge { display:inline-block; padding:3px 7px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:11px; font-weight:600; white-space:nowrap; }
#pattern-table-host .pattern-market-badge.market-ft { background:#dcfce7; color:#166534; }
#pattern-table-host .pattern-market-badge.market-ht { background:#ede9fe; color:#6d28d9; }
#pattern-table-host .pattern-market-badge.market-other { background:#f1f5f9; color:#475569; }
#pattern-table-host .pattern-code-badge{display:inline-block;padding:3px 5px;border:1px solid #dbeafe;border-radius:5px;background:#eff6ff;color:#1e40af;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:11px;font-weight:600;line-height:1.5}
#pattern-table-host .pattern-accuracy-badge{display:inline-block;min-width:51px;padding:3px 5px;border-radius:999px;text-align:center;font-size:11px;font-weight:600;line-height:1.5;font-variant-numeric:tabular-nums}
#pattern-table-host .accuracy-perfect{background:#166534;color:white}
#pattern-table-host .accuracy-high{background:#dcfce7;color:#166534}
#pattern-table-host .accuracy-mid{background:#eff6ff;color:#1e40af}
#pattern-table-host .accuracy-low{background:#fef3c7;color:#92400e}
#pattern-table-host .accuracy-empty{background:#f1f5f9;color:#64748b}
#pattern-table-host .pattern-analysis-header{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:20px 0 16px;border-top:1px solid #cbd5e1;margin-bottom:6px}
#pattern-table-host .pattern-analysis-header h2{margin:0 0 5px;font-size:17px;font-weight:600;color:#0f172a}
#pattern-table-host .pattern-analysis-header p{margin:0;font-size:12px;color:#64748b}
#pattern-table-host .pattern-analysis-header .pattern-league-tabs{margin:0;gap:6px}
#pattern-table-host .pattern-analysis-header .pattern-league-tabs button{padding:8px 13px;font-size:13px}
#pattern-table-host .pattern-stats-segments{display:inline-flex;flex-wrap:wrap;gap:2px;max-width:100%;padding:4px;margin:0 0 16px;border-radius:9px;background:#f1f5f9}
#pattern-table-host .pattern-stats-segments button{border:0;border-radius:6px;padding:6px 12px;background:transparent;color:#64748b;font:inherit;font-size:13px;line-height:1.5;cursor:pointer;white-space:nowrap}
#pattern-table-host .pattern-stats-segments button:hover{background:#e2e8f0;color:#0f172a}
#pattern-table-host .pattern-stats-segments button[aria-pressed="true"]{background:white;color:#0f172a;font-weight:600;box-shadow:0 1px 3px #0f172a1a}
#pattern-table-host .pattern-stats-segments button:focus-visible{outline:2px solid #2563eb;outline-offset:1px}
#pattern-table-host .pattern-trial-badge{display:inline-block;margin-right:6px;padding:1px 5px;border:1px solid #fde68a;border-radius:4px;background:#fffbeb;color:#92400e;font-size:9px;font-weight:700;line-height:1.5;vertical-align:middle}
#pattern-table-host .pattern-rule-name.is-trial{text-transform:capitalize}
#pattern-table-host .pattern-search { display:flex; flex-direction:column; gap:6px; color:#475569; font-size:12px; margin-bottom:14px; }
#pattern-table-host .pattern-search input { width:100%; box-sizing:border-box; padding:11px 12px; background:white; border:1px solid #cbd5e1; border-radius:8px; color:#0f172a; font:inherit; }
#pattern-table-host .pattern-rule-button { border:0; padding:0; background:transparent; color:inherit; font:inherit; text-align:left; cursor:pointer; }
#pattern-table-host .pattern-rule-button:hover { color:#2563eb; text-decoration:underline; }
#pattern-table-host tr.pattern-selected td { background:#dbeafe; color:#1e40af; }
#pattern-table-host tr.pattern-selected td:first-child { box-shadow:inset 3px 0 #2563eb; }
#pattern-table-host .pattern-match-filters input:disabled { background:#e2e8f0; color:#94a3b8; border-color:#cbd5e1; cursor:not-allowed; opacity:0.7; }
#pattern-table-host .pattern-sort-button { border:0; padding:0; background:transparent; color:inherit; font:inherit; font-weight:inherit; cursor:pointer; white-space:nowrap; }
#pattern-table-host .pattern-sort-button:hover { color:#2563eb; }
#pattern-table-host .pattern-sort-button:focus-visible { outline:2px solid #2563eb; outline-offset:4px; }
#pattern-table-host .pattern-match-filters { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
#pattern-table-host .pattern-match-filters label { display:flex; flex-direction:column; gap:6px; font-size:12px; color:#475569; flex:1; min-width:140px; }
#pattern-table-host .pattern-match-filters input, #pattern-table-host .pattern-match-filters select { width:100%; min-width:0; padding:10px; border:1px solid #cbd5e1; border-radius:8px; background:white; color:#0f172a; font:inherit; }
#pattern-table-host .pattern-grid { display:grid; grid-template-columns:minmax(0, 38fr) minmax(0, 62fr); gap:16px; align-items:start; }
#pattern-table-host .pattern-grid > section { min-width:0; }
#pattern-table-host h2 { font-size:16px; font-weight:600; margin:0 0 12px; }
#pattern-table-host .pattern-empty { padding:20px; color:#64748b; font-size:13px; background:white; border:1px solid #e2e8f0; border-radius:12px; }
@media (max-width:1100px) { #pattern-table-host .pattern-grid { grid-template-columns:minmax(0,1fr); } }
#pattern-table-host .pattern-league-tabs { display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap; }
#pattern-table-host .pattern-league-tabs button { padding:10px 18px; border:1px solid #cbd5e1; border-radius:8px; background:white; color:#475569; font:inherit; cursor:pointer; }
#pattern-table-host .pattern-league-tabs button[aria-pressed="true"] { background:#2563eb; color:white; border-color:#2563eb; }
#pattern-table-host .pattern-league-tabs button:focus-visible { outline:2px solid #2563eb; outline-offset:3px; }
#pattern-table-host .pattern-table-wrap { overflow-x:auto; background:white; border:1px solid #e2e8f0; border-radius:12px; }
#pattern-table-host table { width:100%; border-collapse:collapse; font-size:13px; }
#pattern-table-host th, #pattern-table-host td { padding:14px 16px; text-align:left; border-bottom:1px solid #e2e8f0; }
#pattern-table-host th { background:#f1f5f9; color:#475569; white-space:nowrap; }
#pattern-table-host tbody tr:last-child td { border-bottom:0; }
#pattern-table-host tbody tr:hover { background:#f8fafc; }
#pattern-table-host .pattern-code { white-space:nowrap; color:#2563eb; font-weight:600; }
#pattern-table-host .pattern-note { color:#64748b; font-size:13px; margin:0 0 16px; }
</style>
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
