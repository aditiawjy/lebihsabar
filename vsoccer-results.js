(() => {
  'use strict';
  const section = document.getElementById('pattern-vsoccer-section');
  if (!section) return;
  const date = document.getElementById('pattern-results-date');
  const search = document.getElementById('pattern-results-team-search');
  const phase = document.getElementById('pattern-results-phase');
  const allLeague = document.getElementById('pattern-results-league-filter');
  const market = document.getElementById('pattern-results-market-filter');
  const record = document.getElementById('pattern-results-record-filter');
  const league = document.getElementById('pattern-vsoccer-league');
  const refresh = document.getElementById('pattern-vsoccer-refresh');
  const status = document.getElementById('pattern-vsoccer-status');
  const body = document.getElementById('pattern-vsoccer-rows');
  let data = null, active = null, error = '';
  const clean = value => String(value || '').replace(/\s*\[V\]/gi,'').trim();
  const key = value => clean(value).normalize('NFKC').toLocaleLowerCase('id-ID');
  function render() {
    section.hidden = allLeague.value !== 'all' && allLeague.value !== 'vsoccer';
    const selected = league.value, query = key(search.value);
    const matches = (data?.matches || []).filter(match => (!selected || match.leagueId === selected) &&
      (!query || key(match.home).includes(query) || key(match.away).includes(query)) &&
      (phase.value === 'all' || phase.value === 'finished') && record.value === 'all' && (() => {
        if (market.value === 'all') return true;
        const score=String(match.ft || '').match(/^\s*(\d+)\s*[-–—:]\s*(\d+)\s*$/);
        if (!score) return false;
        const total=Number(score[1])+Number(score[2]);
        return market.value === 'zero' ? total === 0 : market.value === 'under15' ? total < 2 : market.value === 'over15' ? total >= 2 : market.value === 'under25' ? total < 3 : total >= 3;
      })());
    const fragment = document.createDocumentFragment();
    for (const match of matches) {
      const row = document.createElement('tr');
      for (const value of [match.time.slice(11,16), clean(match.league), clean(match.home)+' vs '+clean(match.away), match.ht || '—', match.ft]) {
        const cell = document.createElement('td'); cell.textContent = value; row.append(cell);
      }
      fragment.append(row);
    }
    if (!matches.length) {
      const row = document.createElement('tr'), cell = document.createElement('td'); cell.colSpan = 5;
      cell.textContent = record.value !== 'all' ? 'Filter rekor belum tersedia untuk V-Soccer.' : data?.updatedAt ? 'Tidak ada hasil V-Soccer yang cocok dengan filter.' : 'Belum ada hasil tersimpan untuk tanggal ini.';
      row.append(cell); fragment.append(row);
    }
    body.replaceChildren(fragment);
    const timestamp = data?.updatedAt ? new Date(data.updatedAt).toLocaleString('id-ID',{timeZone:'Asia/Jakarta',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'})+' WIB' : 'belum tersedia';
    status.textContent = `${error ? error + ' · ' : ''}${matches.length} ditampilkan · pembaruan ${timestamp}`;
  }
  async function load(requestedDate, update) {
    if (!requestedDate) return;
    active?.abort(); const controller = new AbortController(); active = controller;
    refresh.disabled = true; status.textContent = update ? 'Memperbarui V-Soccer…' : 'Memuat V-Soccer…';
    if (data?.date !== requestedDate) { data = null; error = ''; }
    try {
      const response = await fetch('vsoccer-results-data.php?date='+encodeURIComponent(requestedDate),{method:update?'POST':'GET',cache:'no-store',signal:controller.signal});
      const result = await response.json();
      if (!response.ok || !Array.isArray(result.matches)) throw new Error(result.error || 'Gagal memuat V-Soccer');
      if (controller.signal.aborted || date.value !== requestedDate) return;
      data = result; error = '';
      document.dispatchEvent(new CustomEvent('pattern-vsoccer-results', {detail:{date:requestedDate,matches:result.saveMatches || [],update}}));
      const selected = league.value, options = new Map();
      for (const match of data.matches) options.set(match.leagueId,match.league);
      league.replaceChildren(new Option('Semua liga',''));
      for (const [id,name] of [...options].sort((a,b)=>a[1].localeCompare(b[1]))) league.add(new Option(clean(name),id));
      if (options.has(selected)) league.value = selected;
    } catch (exception) {
      if (controller.signal.aborted) return;
      error = exception.message || 'Gagal memuat V-Soccer';
      if (update) await window.SabarajaSyncHistory?.failure('V-Soccer',requestedDate,'fetch',exception);
    } finally { if (!controller.signal.aborted) { refresh.disabled = false; render(); } }
  }
  document.addEventListener('pattern-results-cycle', event => load(event.detail.date,event.detail.update));
  refresh.addEventListener('click', () => load(date.value,true));
  date.addEventListener('change', () => load(date.value,false));
  league.addEventListener('change',render);
  search.addEventListener('input',render);
  phase.addEventListener('change',render);
  for (const filter of [allLeague,market,record]) filter.addEventListener('change',render);
  load(date.value,false);
})();
