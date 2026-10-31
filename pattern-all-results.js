(() => {
  'use strict';
  const button = document.getElementById('pattern-all-refresh');
  const summary = document.getElementById('pattern-all-summary');
  if (!button || !summary) return;
  const today = () => {
    const parts = new Intl.DateTimeFormat('en-CA', {timeZone:'Asia/Jakarta',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());
    const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
    return `${values.year}-${values.month}-${values.day}`;
  };
  const previous = value => {
    const date = new Date(value+'T00:00:00Z'); date.setUTCDate(date.getUTCDate()-1);
    return date.toISOString().slice(0,10);
  };
  async function request(url, options = {}) {
    const response = await fetch(url, {...options, cache:'no-store', signal:AbortSignal.timeout(180000)});
    let data;
    try { data = await response.json(); } catch (_) { throw new Error('Respons server tidak valid.'); }
    if (!response.ok) throw new Error(data.error || 'Permintaan gagal.');
    return data;
  }
  async function save(matches) {
    const total = {inserted:0, updated:0, skipped_duplicate:0, skipped_invalid:0};
    let historyFailed = false;
    for (let offset = 0; offset < matches.length; offset += 1000) {
      const data = await request('save_matches_csv.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({matches:matches.slice(offset,offset+1000)})});
      if (!data.success) throw new Error(data.error || data.message || 'Gagal menyimpan CSV.');
      if (data.history_saved === false) historyFailed = true;
      for (const key of Object.keys(total)) total[key] += Number(data[key] || 0);
    }
    return {...total, historyFailed};
  }
  const display = (name, stats) => `${name}: ${stats.inserted} baru, ${stats.updated} diperbarui, ${stats.skipped_duplicate} sudah ada${stats.skipped_invalid ? `, ${stats.skipped_invalid} tidak valid` : ''}${stats.historyFailed ? ' (riwayat gagal dicatat)' : ''}`;
  button.addEventListener('click', async () => {
    button.disabled = true; summary.hidden = false; summary.classList.remove('is-error');
    const end = today(), start = previous(end), lines = [], failures = [];
    summary.textContent = `Memperbarui semua data ${start}–${end}…`;
    try {
      let msportsFetched = false;
      try {
        const data = await request('pattern-all-results.php', {method:'POST'});
        if (data.start !== start || data.end !== end || !Array.isArray(data.matches)) throw new Error('Tanggal atau format hasil MSports tidak sesuai.');
        msportsFetched = true;
        const stats = await save(data.matches);
        lines.push(display('SABA dan E-Football',stats) + (data.missingHt ? `; ${data.missingHt} hasil tanpa HT dilewati (termasuk kemungkinan duplikat antar sesi)` : ''));
      } catch (error) { failures.push('SABA dan E-Football: '+error.message); if (!msportsFetched) await window.SabarajaSyncHistory?.failure('MSports',end,'fetch',error); }
      const vsoccer = [];
      for (const day of [start,end]) {
        summary.textContent = `Mengambil V-Soccer ${day}…`;
        try {
          const data = await request('vsoccer-results-data.php?date='+encodeURIComponent(day), {method:'POST'});
          if (data.date !== day || !Array.isArray(data.saveMatches)) throw new Error('Tanggal atau format hasil tidak sesuai.');
          vsoccer.push(...data.saveMatches);
        } catch (error) { failures.push(`V-Soccer ${day}: ${error.message}`); await window.SabarajaSyncHistory?.failure('V-Soccer',day,'fetch',error); }
      }
      try { lines.push(display('V-Soccer', await save(vsoccer))); }
      catch (error) { failures.push('Penyimpanan V-Soccer: '+error.message); }
      summary.classList.toggle('is-error', failures.length > 0);
      summary.textContent = `${failures.length ? 'Pembaruan belum lengkap' : 'Pembaruan selesai'} (${start}–${end}) · ${lines.join(' · ')}${failures.length ? ' · Gagal: '+failures.join('; ') : ''}. Tanggal MSports UTC+8; V-Soccer WIB.`;
      window.SabarajaSyncHistory?.refresh();
      try { await window.SabarajaPatternEngine?.refreshHistory(true); window.SabarajaPatternLive?.refreshView(); } catch (_) {}
    } finally { button.disabled = false; }
  });
})();
