(() => {
  'use strict';
  const section=document.getElementById('pattern-sync-history'), status=document.getElementById('pattern-sync-history-status');
  const list=document.getElementById('pattern-sync-history-list'), refresh=document.getElementById('pattern-sync-history-refresh');
  if (!section || !status || !list || !refresh) return;
  const labels={session:'Sesi ditolak atau kedaluwarsa',network:'Koneksi atau sumber gagal',invalid:'Data tidak valid',unknown:'Penyebab tidak tersedia'};
  const formatDate=value => /^\d{4}-\d{2}-\d{2}$/.test(value) ? value.split('-').reverse().join('/') : value;
  async function load() {
    status.textContent='Memuat riwayat…';
    try {
      const response=await fetch('sync-history.php',{cache:'no-store',signal:AbortSignal.timeout(30000)});
      const data=await response.json(); if (!response.ok) throw new Error(data.error || 'Gagal memuat riwayat.');
      list.replaceChildren();
      status.textContent=data.entries.length ? `${data.entries.length} catatan terbaru · waktu WIB` : 'Belum ada catatan sinkronisasi baru.';
      for (const entry of data.entries) {
        const item=document.createElement('details'); item.className='coverage-day';
        const title=document.createElement('summary');
        const when=entry.time ? new Date(entry.time).toLocaleString('id-ID',{timeZone:'Asia/Jakarta',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit'})+' WIB' : 'Waktu tidak tersedia';
        const state=entry.status==='success'?'Tersimpan':entry.status==='partial'?'Sebagian tidak valid':'Gagal';
        title.textContent=`${when} · ${state} · ${entry.stage==='fetch' ? 'Pengambilan '+entry.source : 'Penyimpanan CSV'}${entry.received !== undefined ? ` · ${entry.received} hasil` : ''}`;
        item.append(title);
        const body=document.createElement('p');
        if (entry.stage==='save' && entry.status!=='failed') body.textContent=`Baru: ${entry.inserted || 0} · diperbarui: ${entry.updated || 0} · sudah ada: ${entry.unchanged || 0} · tidak valid: ${entry.invalid || 0}.`;
        else body.textContent=`${labels[entry.reason] || labels.unknown}.`;
        item.append(body);
        const dates=document.createElement('p'); const values=entry.dates || entry.requestedDates || [];
        dates.textContent=`${entry.dates ? 'Tanggal hasil UTC+8' : 'Tanggal yang diminta'}: ${values.length ? values.map(formatDate).join(', ') : 'tidak tersedia'}.`;
        item.append(dates);
        if (entry.leagues?.length) { const leagues=document.createElement('ul'); for(const name of entry.leagues) { const li=document.createElement('li'); li.textContent=name; leagues.append(li); } item.append(leagues); }
        list.append(item);
      }
    } catch (error) { status.textContent='Gagal memuat riwayat: '+error.message; }
  }
  window.SabarajaSyncHistory = {
    async failure(source,date,stage,error) {
      const message=String(error?.message || error || '');
      const reason=/401|403|sesi|login/i.test(message)?'session':/invalid|format|tidak valid/i.test(message)?'invalid':/network|fetch|koneksi|dihubungi|timeout|timeout|jaringan/i.test(message)?'network':'unknown';
      try { const response=await fetch('sync-history.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({source,date,stage,reason}),signal:AbortSignal.timeout(15000)}); if(!response.ok) throw new Error(); if(section.open) load(); return true; } catch (_) { status.textContent='Gagal mencatat riwayat; periksa akses penyimpanan server.'; return false; }
    },
    refresh: () => { if (section.open) load(); }
  };
  section.addEventListener('toggle',()=>{if(section.open) load();});
  refresh.addEventListener('click',load);
})();
