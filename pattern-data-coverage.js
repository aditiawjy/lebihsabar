(() => {
  'use strict';
  const section=document.getElementById('pattern-coverage');
  const form=document.getElementById('pattern-coverage-form');
  if (!section || !form) return;
  const start=document.getElementById('pattern-coverage-start'), end=document.getElementById('pattern-coverage-end');
  const status=document.getElementById('pattern-coverage-status'), container=document.getElementById('pattern-coverage-days');
  const dateParts=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Singapore',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());
  const parts=Object.fromEntries(dateParts.map(part=>[part.type,part.value]));
  end.value=`${parts.year}-${parts.month}-${parts.day}`;
  const from=new Date(end.value+'T00:00:00Z'); from.setUTCDate(from.getUTCDate()-6); start.value=from.toISOString().slice(0,10);
  const dateLabel=value=>value.split('-').reverse().join('/');
  const timeLabel=value=>new Date(value).toLocaleString('id-ID',{timeZone:'Asia/Singapore',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'})+' UTC+8';
  let loaded=false;
  async function load() {
    const a=Date.parse(start.value+'T00:00:00Z'), b=Date.parse(end.value+'T00:00:00Z');
    if (!Number.isFinite(a) || !Number.isFinite(b) || b<a || (b-a)/86400000>30) { status.textContent='Pilih rentang tanggal yang valid, maksimal 31 hari.'; return; }
    status.textContent='Menghitung data CSV…'; container.replaceChildren();
    try {
      const response=await fetch(`pattern-data-coverage.php?start=${encodeURIComponent(start.value)}&end=${encodeURIComponent(end.value)}`,{cache:'no-store',signal:AbortSignal.timeout(90000)});
      const data=await response.json(); if (!response.ok) throw new Error(data.error || 'Tidak dapat membaca ringkasan.');
      status.textContent=`CSV terakhir berubah ${timeLabel(data.csvUpdatedAt)}. Waktu ini berlaku untuk seluruh file, bukan bukti pembaruan setiap liga.`;
      for (const day of [...data.days].reverse()) {
        const items=Object.entries(day.leagues), total=items.reduce((sum,[,count])=>sum+count,0);
        const box=document.createElement('details'); box.className='coverage-day';
        const heading=document.createElement('summary');
        heading.textContent=`${dateLabel(day.date)} · ${total ? `${total} hasil di ${items.length} liga` : 'Belum ada hasil di CSV'} · ${day.msportsSnapshots.length === 2 ? 'arsip MSports tersedia' : `arsip MSports belum lengkap (${day.msportsSnapshots.length}/2)`}`;
        box.append(heading);
        const note=document.createElement('p');
        note.textContent=(day.msportsSnapshots.length ? 'Arsip MSports: '+day.msportsSnapshots.map(s=>`${dateLabel(s.session)} (${timeLabel(s.updatedAt)})`).join(', ')+'. ' : 'Belum ada arsip sesi MSports untuk tanggal ini. ')+
          (day.vsoccerUpdatedAt ? 'Pembaruan Results V-Soccer untuk tanggal sumber WIB: '+timeLabel(day.vsoccerUpdatedAt)+'.' : 'Pembaruan Results V-Soccer untuk tanggal sumber WIB belum tercatat di cache.');
        box.append(note);
        const list=document.createElement('ul');
        for (const name of data.leagueNames) {
          const item=document.createElement('li'); const count=day.leagues[name] || 0;
          item.textContent=`${name}: ${count ? `${count} hasil` : 'belum ada hasil di CSV'}`;
          if (!count) item.className='coverage-empty'; list.append(item);
        }
        if (!data.leagueNames.length) { const item=document.createElement('li'); item.textContent='Belum ada liga yang dikenali dalam CSV.'; list.append(item); }
        box.append(list); container.append(box);
      }
      loaded=true;
    } catch (error) { status.textContent='Gagal memuat ringkasan: '+error.message; }
  }
  form.addEventListener('submit',event=>{event.preventDefault(); load();});
  section.addEventListener('toggle',()=>{if(section.open && !loaded) load();});
})();
