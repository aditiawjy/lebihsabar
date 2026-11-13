(() => {
  'use strict';
  const markets = globalThis.SabarajaTeamMarkets;
  function aggregate(snapshots, metric = 'zero') {
    const market = markets[metric];
    const teams = new Map();
    let finished = 0, draws = 0;
    for (const snapshot of snapshots) {
      const seen = new Set();
      for (const match of snapshot.matches || []) {
        if (match.status !== 'Selesai' || !/^\d+[–-]\d+$/.test(match.ft || '')) continue;
        const selectedScore = market.period === 'HT' ? match.ht : match.ft;
        if (!/^\d+[–-]\d+$/.test(selectedScore || '')) continue;
        if (market.period === 'HT' && selectedScore.split(/[–-]/).some((score,index)=>Number(score)>Number(match.ft.split(/[–-]/)[index]))) continue;
        const key = JSON.stringify([match.time, match.home, match.away]);
        if (seen.has(key)) continue;
        seen.add(key);
        finished++;
        const goals = selectedScore.split(/[–-]/).map(Number);
        const total = goals[0] + goals[1];
        const zero = market.comparison === 'over' ? total >= market.goals : total <= market.goals;
        if (zero) draws++;
        for (const [name, opponent, side] of [[match.home, match.away, 'Kandang'], [match.away, match.home, 'Tandang']]) {
          const teamKey = JSON.stringify([snapshot.league, name]);
          if (!teams.has(teamKey)) teams.set(teamKey, {name, league: snapshot.league, label: snapshot.displayName, played: 0, zero: 0, matches: []});
          const team = teams.get(teamKey);
          team.played++;
          if (zero) { team.zero++; team.matches.push({time: match.time, opponent, side, ft: match.ft, score: selectedScore}); }
        }
      }
    }
    const rows = [...teams.values()].filter(team => team.zero > 0).sort((a, b) => b.zero - a.zero || a.name.localeCompare(b.name) || a.league.localeCompare(b.league));
    rows.forEach((row, i) => { row.rank = i && row.zero === rows[i - 1].zero ? rows[i - 1].rank : i + 1; });
    return {rows, finished, draws};
  }
  if (typeof document === 'undefined') { globalThis.aggregateTeamZero = aggregate; return; }
  const root = document.getElementById('team-zero');
  if (!root) return;
  const el = id => document.getElementById('zero-' + id);
  const leagues = ['15', '16', '20', 'vietnam15', 'f24-club', 'f24-international', 'f26-club', 'f26-international'];
  let snapshots = [], history = new Map(), controller, loadedDate = null, loadedMetric = null, progressSort = null;
  const escape = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const formatTime = value => new Intl.DateTimeFormat('id-ID', {hour:'2-digit', minute:'2-digit', hour12:false, timeZone:'Asia/Singapore'}).format(new Date(value));
  const teamKey = (league, name) => JSON.stringify([String(league), name.replace(/\s*(?:\(V\)|\[V\])/gi, '').replace(/\s+/g, ' ').trim().toLowerCase()]);
  const formatDate = date => date.split('-').reverse().join('/');
  function recordProgress(row) {
    const record = history.get(teamKey(row.league, row.name));
    if (!record || record.max <= 0) return '—';
    return (row.zero / record.max * 100).toLocaleString('id-ID', {maximumFractionDigits:1}) + '%';
  }
  function recordCells(row) {
    const record = history.get(teamKey(row.league, row.name));
    if (!record) return '<td>—</td><td>Belum ada riwayat</td>';
    const dates = record.dates.map(date => escape(formatDate(date)));
    const label = dates.length > 1 ? `<details><summary>${dates[dates.length - 1]} (+${dates.length - 1} tanggal)</summary><ul>${dates.map(date => `<li>${date}</li>`).join('')}</ul></details>` : dates[0];
    return `<td><span class="zero-badge">${record.max} kali</span></td><td>${label}</td>`;
  }
  function render() {
    const selected = el('league').value;
    const metric = el('metric').value, market = markets[metric], label = market.label;
    el('title').textContent = `Tim dengan ${label} terbanyak`;
    el('description').textContent = `${market.period === 'HT' ? 'Skor babak pertama' : 'Skor akhir'}: total gol ${market.comparison === 'over' ? 'minimal' : 'maksimal'} ${market.goals}. Hanya pertandingan selesai dengan skor ${market.period} valid yang dihitung.`;
    el('daily-header').textContent = `${label} tanggal dipilih`;
    el('record-header').textContent = `Rekor ${label} / hari`;
    el('matches-header').textContent = `Pertandingan ${label}`;
    el('explanation').textContent = `Satu hari dihitung pukul 00:00–23:59 UTC+8 untuk semua liga, termasuk League 16 dan V-Soccer. Setiap hasil ${label} menambah 1 hitungan untuk masing-masing tim. Tim yang sama di liga berbeda dihitung terpisah. Data harian V-Soccer berasal dari matches.csv; sinkronkan hasil terbaru di Pola Pertandingan. Rekor menggunakan matches.csv dan arsip API tersimpan. Riwayat yang tersedia bisa belum lengkap.`;
    const stats = aggregate(snapshots.filter(snapshot => selected === 'all' || snapshot.league === selected || (selected === 'vsoccer' && snapshot.league.startsWith('vsoccer:'))), metric);
    const search = el('search').value.trim().toLocaleLowerCase();
    const rows = stats.rows.filter(row => row.name.toLocaleLowerCase().includes(search));
    if (progressSort) rows.sort((a, b) => {
      const aRecord = history.get(teamKey(a.league, a.name));
      const bRecord = history.get(teamKey(b.league, b.name));
      const aValue = aRecord?.max > 0 ? a.zero / aRecord.max : null;
      const bValue = bRecord?.max > 0 ? b.zero / bRecord.max : null;
      if (aValue === null || bValue === null) return aValue === bValue ? 0 : aValue === null ? 1 : -1;
      return (progressSort === 'desc' ? bValue - aValue : aValue - bValue) || a.rank - b.rank || a.name.localeCompare(b.name);
    });

    el('summary').innerHTML = `<article><span>Pertandingan ${label}</span><strong>${stats.draws}</strong><p>Dari ${stats.finished} pertandingan selesai</p></article><article><span>Tim dengan ${label}</span><strong>${stats.rows.length}</strong><p>Tim dihitung terpisah per liga</p></article><article><span>Jumlah terbanyak per tim</span><strong>${stats.rows[0]?.zero || 0} kali</strong><p>${stats.rows.length ? stats.rows.filter(row => row.rank === 1).length + ' tim di peringkat pertama' : `Belum ada hasil ${label}`}</p></article>`;
    el('count').textContent = `${rows.length} tim ditampilkan`;
    el('rows').innerHTML = rows.length ? rows.map(row => `<tr><td>#${row.rank}</td><td><span class="zero-team">${escape(row.name)}</span><span class="zero-league-label">${escape(row.label)}</span></td><td><span class="zero-badge">${row.zero} kali</span></td>${recordCells(row)}<td>${recordProgress(row)}</td><td><details><summary>Lihat ${row.zero} pertandingan</summary><ul>${row.matches.slice().sort((a,b)=>a.time.localeCompare(b.time)).map(match=>`<li>${escape(formatTime(match.time))} · vs ${escape(match.opponent)}<br>${escape(match.side)} · ${market.period} ${escape(match.score)}</li>`).join('')}</ul></details></td></tr>`).join('') : `<tr><td colspan="7" class="zero-empty">${search ? 'Tidak ada tim yang cocok dengan pencarian.' : stats.finished ? `Belum ada hasil ${label} pada tanggal dan liga ini.` : 'Belum ada pertandingan selesai yang tersimpan untuk tanggal dan liga ini. Gunakan Perbarui dari API untuk mengambil data.'}</td></tr>`;
  }
  async function request(date, league, signal, refresh = false) {
    const response = await fetch(`pattern-results-data.php?date=${encodeURIComponent(date)}&league=${league}&period=day`, {method:refresh ? 'POST':'GET', signal, cache:'no-store'});
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); } catch { throw new Error('Server tidak mengembalikan data JSON. Coba muat ulang halaman.'); }
    if (!response.ok || data.error) throw new Error(data.error || 'Data Results tidak dapat dimuat.');
    return data;
  }
  async function load(refresh = false) {
    if (!el('date').checkValidity()) { el('date').reportValidity(); return; }
    controller?.abort(); controller = new AbortController();
    const signal = controller.signal, date = el('date').value, metric = el('metric').value;
    el('refresh').disabled = true; el('status').textContent = refresh ? 'Memperbarui API…' : 'Memuat data…';
    el('error').hidden = true;
    if (loadedDate !== date || loadedMetric !== metric) { snapshots = []; loadedDate = null; el('summary').innerHTML = ''; el('count').textContent = ''; el('rows').innerHTML = '<tr><td colspan="7" class="zero-empty">Memuat data…</td></tr>'; }
    let refreshError = null;
    try {
      if (refresh) { try { await request(date, '16', signal, true); } catch (error) { if (signal.aborted) throw error; refreshError = error; } }
      const [results, historyResponse, vsoccerResponse] = await Promise.all([
        Promise.all(leagues.map(league => request(date, league, signal))),
        fetch('team-zero-history.php?metric=' + encodeURIComponent(metric), {signal, cache:'no-store'}).then(async response => {
          if (!response.ok) throw new Error('Rekor historis belum berhasil dimuat. Coba lagi.');
          const data = await response.json();
          if (!Array.isArray(data.records)) throw new Error('Data rekor historis tidak valid.');
          return data;
        }),
        fetch('team-vsoccer-day.php?date=' + encodeURIComponent(date), {signal, cache:'no-store'}).then(async response => {
          if (!response.ok) throw new Error('Data harian V-Soccer belum berhasil dimuat.');
          const data = await response.json();
          if (!Array.isArray(data.snapshots) || !Array.isArray(data.leagues)) throw new Error('Data V-Soccer tidak valid.');
          return data;
        })
      ]);
      if (signal.aborted) return;
      const chosenLeague = el('league').value;
      el('vsoccer-leagues').replaceChildren(...vsoccerResponse.leagues.map(item => new Option(item.name,item.id)));
      if ([...el('league').options].some(option => option.value === chosenLeague)) el('league').value = chosenLeague;
      results.push(...vsoccerResponse.snapshots);
      history = new Map(historyResponse.records.map(record => [teamKey(record.league, record.name), record]));
      // Include the displayed day if a live refresh has just raised the record.
      for (const row of aggregate(results, metric).rows) {
        const key = teamKey(row.league, row.name), previous = history.get(key);
        if (!previous || row.zero > previous.max) history.set(key, {max:row.zero, dates:[date]});
        else if (row.zero === previous.max && !previous.dates.includes(date)) previous.dates.push(date), previous.dates.sort();
      }
      snapshots = results; loadedDate = date; loadedMetric = metric; render();
      const available = results.map(data => data.updatedAt).filter(Boolean).sort();
      el('status').textContent = refreshError ? 'Data tersimpan' : available.length ? `Data ${formatTime(available[0])} UTC+8` : 'Belum ada data';
      if (refreshError) throw refreshError;
    } catch (error) {
      if (signal.aborted) return;
      el('error').textContent = error.message; el('error').hidden = false;
      el('status').textContent = snapshots.length ? 'Data tersimpan · API gagal' : 'Gagal memuat';
      if (!snapshots.length) { el('rows').innerHTML = '<tr><td colspan="7" class="zero-empty">Data belum berhasil dimuat. Coba Perbarui dari API.</td></tr>'; }
    } finally { if (!signal.aborted) el('refresh').disabled = false; }
  }
  el('sort-progress').addEventListener('click', () => {
    progressSort = progressSort === 'desc' ? 'asc' : 'desc';
    el('progress-header').setAttribute('aria-sort', progressSort === 'desc' ? 'descending' : 'ascending');
    el('sort-arrow').textContent = progressSort === 'desc' ? '↓' : '↑';
    el('sort-progress').setAttribute('aria-label', progressSort === 'desc' ? 'Urutkan capaian rekor dari terendah ke tertinggi' : 'Urutkan capaian rekor dari tertinggi ke terendah');
    render();
  });
  el('filters').addEventListener('submit', event => event.preventDefault());
  el('date').addEventListener('change', () => load());
  el('metric').addEventListener('change', () => { history = new Map(); render(); load(); });
  el('league').addEventListener('change', render);
  el('search').addEventListener('input', render);
  el('refresh').addEventListener('click', () => load(true));
  load();
})();
