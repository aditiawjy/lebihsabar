(() => {
  'use strict';
  const host=document.getElementById('pattern-moment-scanner');
  if (!host) return;
  const el=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;return n;};
  function render({payload,analysis}) {
    const note=host.querySelector('.moment-scanner-note'),wrap=host.querySelector('.moment-scanner-table');
    wrap.replaceChildren();
    const liveReady=payload?.online && payload.fresh && analysis?.ready;
    const rows=[];
    for(const evaluation of liveReady ? analysis.matches : []) {
      const match=payload.matches[evaluation.index];
      const patterns=evaluation.signals.filter(s=>s.state==='active');
      const moments=[...(evaluation.moment?[evaluation.moment]:[]),...(evaluation.moments||[])].filter(m=>m.state==='watch'||m.state==='waiting');
      if(!patterns.length&&!moments.length)continue;
      const title=match.home.replace(/\s*\(V\)/gi,'')+' vs '+match.away.replace(/\s*\(V\)/gi,'');
      const time=evaluation.kickoff ? new Date(evaluation.kickoff).toLocaleTimeString('id-ID',{timeZone:'Asia/Singapore',hour:'2-digit',minute:'2-digit'}) : 'Kickoff belum pasti';
      const formulas=[...patterns.map(s=>s.id+' · '+s.market),...moments.map(m=>(m.experimental||m.id.startsWith('L16-')?'UJI · ':'')+m.name+(m.detail?' · '+m.detail:''))];
      const afterL1=moments.some(m=>m.id.startsWith('L16-L1:'));
      const status=afterL1?'AFTER L1 🔥 · PANTAU PEMICU BERIKUTNYA':moments.some(m=>m.id==='L16-HTT')?'MOMEN 2H O0.5 · UJI':evaluation.phase==='1H'?'PANTAU BABAK PERTAMA / HT':evaluation.htAvailable?'PEMICU AKTIF · PANTAU 2H':'STANDBY · TUNGGU HT';
      rows.push([time+' · L16',title,match.status+' · Skor '+(match.score||'—')+' · HT '+(match.ht||'—'),formulas.join('\n'),status]);
    }
    for(const match of window.SabarajaPatternEngine?.scanUpcoming() || []) {
      const time=new Date(match.t).toLocaleTimeString('id-ID',{timeZone:'Asia/Singapore',hour:'2-digit',minute:'2-digit'});
      rows.push([time+' · L16',match.teams.join(' vs '),'Jadwal API · belum kickoff',
        match.rules.map(r=>r.id+' · '+r.market).join('\n'),'STANDBY · KONFIRMASI SAAT LIVE']);
    }
    const api=window.SabarajaPatternResults;
    if(api && Date.now()-api.fetchedAt<=120000) for(const snapshot of api.snapshots || []) {
      if(snapshot.league==='16')continue;
      for(const match of snapshot.matches) {
        const t=Date.parse(match.time),ht=String(match.ht||'').match(/^(\d+)\s*[-–:]\s*(\d+)$/);
        if(match.status!=='Belum selesai'||!ht||+ht[1]>1||+ht[2]>1||t>Date.now()||Date.now()-t>35*60000)continue;
        rows.push([match.time.slice(11,16)+' · L'+snapshot.league,match.home+' vs '+match.away,'HT tercatat '+match.ht+' · status fase live belum tersedia',
          'Prasyarat Half-Time Trap: HT ketat; pemicu pola 2H belum terkonfirmasi','STANDBY · PERLU PEMICU 2H']);
      }
    }
    note.textContent='UTC+8 · L16 memakai evaluator live; L15/L20 hanya standby HT dari API, bukan sinyal entry · UJI, bukan jaminan gol.' + (!liveReady?' Sumber live L16 belum siap.':'');
    if(!rows.length){wrap.append(el('p','Belum ada momen aktif yang memenuhi syarat saat ini.'));return;}
    const table=el('table'),head=table.createTHead().insertRow();
    for(const label of ['Waktu & League','Pertandingan','Kondisi Saat Ini','Formula / Pola Terpicu','Status Momen'])head.append(el('th',label));
    const body=table.createTBody();for(const values of rows){const row=body.insertRow();for(const value of values){const td=row.insertCell();td.textContent=value;td.style.whiteSpace='pre-line';}}
    wrap.append(table);
  }
  document.addEventListener('pattern-live-analysis',event=>render(event.detail));
  const run=document.getElementById('pattern-miner-run'),output=document.getElementById('pattern-miner-output');
  run.addEventListener('click',async()=>{
    run.disabled=true;output.textContent='Mencari kombinasi dan menghitung validasi kronologis…';
    try{
      const response=await fetch('pattern-miner.php',{method:'POST',cache:'no-store',signal:AbortSignal.timeout(120000)});
      const report=await response.json();if(!response.ok)throw Error(report.error||'Miner gagal.');
      output.replaceChildren(el('p',`${report.combinations} kombinasi diuji · ${report.candidates.length} kandidat memenuhi syarat pencarian · hanya 50 terbaik ditampilkan.`));
      output.append(el('p',report.warning));
      if(!report.candidates.length){output.append(el('p','Belum ada kombinasi yang lolos. Ambang tidak dilonggarkan otomatis.'));return;}
      const table=el('table'),head=table.createTHead().insertRow();
      for(const label of ['Formula (A + B + C)','Pencarian W / N','Akurasi','Max L','Validasi W / N','Akurasi validasi'])head.append(el('th',label));
      const body=table.createTBody();for(const candidate of report.candidates.slice(0,50)){
        const row=body.insertRow();for(const value of [candidate.formula,`${candidate.train.win}/${candidate.train.total}`,candidate.train.accuracy+'%',candidate.train.maxLoseStreak,`${candidate.validation.win}/${candidate.validation.total}`,candidate.validation.total?candidate.validation.accuracy+'%':'Belum ada sampel'])row.insertCell().textContent=value;
      }output.append(table);
    }catch(error){output.textContent='Gagal: '+error.message;}finally{run.disabled=false;}
  });
})();
