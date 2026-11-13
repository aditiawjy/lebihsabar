(() => {
  'use strict';
  const records = new Map(), dirty = new Map();
  let timer = null, saving = false, localTimer = null;
  const clean = value => String(value || '').replace(/\s*(?:\(V\)|\[V\])/gi,'').replace(/\s+/g,' ').trim().toLowerCase();
  const storageKey = 'sabaraja-signals-v1:' + location.pathname;
  try { for (const [key,record] of Object.entries(JSON.parse(localStorage.getItem(storageKey) || '{}'))) records.set(key,record); } catch (_) {}
  const status = () => document.getElementById('pattern-signal-history-status');
  function inform(message, failed = false) { const node=status(); if(node){node.textContent=message;node.classList.toggle('is-error',failed);} }
  function localSave() { try { localStorage.setItem(storageKey,JSON.stringify(Object.fromEntries(records))); } catch (_) { inform('Arsip lokal penuh; penyimpanan server tetap dicoba.',true); } }
  function merge(incoming) {
    for (const [key,record] of Object.entries(incoming || {})) {
      const previous = records.get(key);
      records.set(key,{fixture:record.fixture,signals:{...(previous?.signals || {}),...record.signals}});
    }
  }
  function countSaved() {
    return [...records.values()].reduce((sum, record) => sum + Object.keys(record.signals).length, 0);
  }
  async function load(date) {
    const dates = [-1,0,1].map(offset=>{ const day=new Date(date+'T12:00:00Z');day.setUTCDate(day.getUTCDate()+offset);return day.toISOString().slice(0,10); });
    try {
      for (const day of dates) {
        const response=await fetch('pattern-signal-history.php?date='+day,{cache:'no-store',signal:AbortSignal.timeout(15000)});
        const data=await response.json(); if(!response.ok)throw Error();
        for(const [key,record] of records) {
          if(record.fixture.time.slice(0,10)!==day)continue;
          if(Object.keys(record.signals).some(id=>!data.records?.[key]?.signals?.[id]))dirty.set(key,record);
        }
        merge(data.records);
      }
      localSave(); if(dirty.size){clearTimeout(timer);timer=setTimeout(flush,350);} inform(countSaved() + ' sinyal dalam arsip dimuat; sinyal tersimpan tampil pada baris pertandingan terkait, termasuk yang selesai.');
    } catch (_) { inform('Arsip server belum terbaca; menggunakan sinyal tersimpan di browser ini.',true); }
  }
  async function flush() {
    if(saving || !dirty.size)return;
    saving=true;
    const batch=new Map(dirty); dirty.clear();
    try {
      const days=new Map();
      for(const [key,record] of batch){const day=record.fixture.time.slice(0,10);if(!days.has(day))days.set(day,{});days.get(day)[key]=record;}
      for(const [date,entries] of days){
        const response=await fetch('pattern-signal-history.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({date,records:entries}),keepalive:false,signal:AbortSignal.timeout(20000)});
        const data=await response.json();if(!response.ok)throw Error();merge(data.records);
      }
      localSave();inform(countSaved() + ' sinyal tersimpan untuk evaluasi; tetap tampil pada pertandingan terkait setelah HT/FT.');
    } catch (_) {
      for(const [key,record] of batch) if(!dirty.has(key))dirty.set(key,record);
      inform('Arsip server belum tersimpan; salinan browser tersedia. Mencoba ulang.',true);
    } finally { saving=false;if(dirty.size){clearTimeout(timer);timer=setTimeout(flush,5000);} }
  }
  // Historical fixtures predating the recorder can recover only rules based on pre-kickoff CSV.
  function historicalSignals(match) {
    if(match.status !== 'Selesai' || !match.h2h)return [];
    const h=match.h2h, result=[];
    const add=(prefix,current,record,label)=>{
      if(record >= (prefix === 'H2H FT O0.5' ? 2 : 1) && current>=record)result.push({prefix,label:label(current,record)});
    };
    add('H2H HT 0–0',h.htZeroStreak?.current,h.htZeroStreak?.record,(c,r)=>`H2H HT 0–0 · ${c}× beruntun · rekor ${r}×`);
    add('H2H 2H 0–0',h.secondHalfZeroStreak?.current,h.secondHalfZeroStreak?.record,(c,r)=>`H2H 2H 0–0 · ${c}× beruntun · rekor ${r}×`);
    add('H2H UNDER 2.5',h.under25StreakCurrent,h.under25StreakRecord,(c,r)=>`H2H UNDER 2.5 · ${c}× beruntun · rekor ${r}×`);
    add('H2H DRAW',h.drawStreakCurrent,h.drawStreakRecord,(c,r)=>`H2H DRAW · ${c}× seri beruntun · rekor ${r}×`);
    add('H2H FT O0.5',h.zeroStreakCurrent,h.zeroStreakRecord,(c,r)=>`H2H FT O0.5 · ${c} H2H terakhir FT 0–0 · rekor ${r}×`);
    add('H2H FT O1.5',h.under15StreakCurrent,h.under15StreakRecord,(c,r)=>`H2H FT O1.5 · ${c} H2H terakhir Under 1.5 · rekor ${r}×`);
    return result;
  }
  function capture(container, league, match) {
    if(!match.time || (match.live && !match.live.kickoffMatched))return;
    const stamp=new Date(match.time);if(Number.isNaN(stamp.getTime()))return;
    const key=JSON.stringify([String(league),stamp.toISOString(),clean(match.home),clean(match.away)]);
    const entry=records.get(key) || {fixture:{league:String(league),time:match.time,home:match.home,away:match.away},signals:{}};
    const visible=new Set();let changed=false;
    for(const node of container.querySelectorAll('.result-h2h-badge:not(.result-historical-signal), .result-history-alert')) {
      if(node.closest('.result-saved-signal'))continue;
      const details=node.closest('.result-h2h-detail');
      const label=node.dataset.signalLabel || node.textContent.trim();if(!label)continue;
      visible.add(label);
      if(!entry.signals[label]) {
        const href=node.getAttribute('href') || '';
        let rule=null,analysis=null;
        try{rule=node.dataset.signalRule?JSON.parse(node.dataset.signalRule):window.SabarajaSignalEvaluation.inferRule(label);analysis=node.dataset.signalHistory?JSON.parse(node.dataset.signalHistory):rule?.history?match.signalAnalysis?.triggerHistory?.[rule.history]:null;}catch(_){}

        analysis=window.SabarajaSignalEvaluation.counterHistory(analysis,rule);
        entry.signals[label]={label,rule,analysis,title:node.title || '',href:href.startsWith('index.php?')?href:'',detail:details?.querySelector('.result-h2h-detail-body')?.textContent || '',firstSeen:new Date().toISOString(),phase:match.live?.status || match.status || '',ht:match.live?.ht || match.ht || '',score:match.live?.score || match.ft || '',afterFt:match.status==='Selesai'};
        changed=true;
      }
    }
    for(const signal of historicalSignals(match)) {
      const observed=Object.values(entry.signals).some(saved=>saved.label.startsWith(signal.prefix));
      if(observed || [...visible].some(label=>label.startsWith(signal.prefix)))continue;
      const badge=document.createElement('span');badge.className='result-h2h-badge result-historical-signal';
      badge.textContent=signal.label+' · historis';
      badge.title='Dihitung ulang dari seluruh riwayat CSV sebelum kickoff pertandingan ini. Bukan bukti sinyal pernah terekam saat live. Skor pertandingan ini tidak dipakai sebagai pemicu.';
      container.append(badge);
    }
    if(changed){records.set(key,entry);dirty.set(key,entry);clearTimeout(localTimer);localTimer=setTimeout(localSave,50);clearTimeout(timer);timer=setTimeout(flush,350);}
    for(const signal of Object.values(entry.signals)) {
      if(visible.has(signal.label))continue;
      if(signal.label.startsWith('H2H FT O0.5') && Number(signal.label.match(/rekor (\d+)/)?.[1] || 0) < 2)continue;
      const wrapper=document.createElement('details');wrapper.className='result-saved-signal';
      const badge=document.createElement('summary');badge.className='result-h2h-badge';badge.textContent=signal.label+' · tersimpan';
      badge.title='Sinyal pernah tampil; disimpan untuk evaluasi, bukan sinyal aktif baru.';
      const detail=document.createElement('div');detail.className='result-h2h-detail-body';
      const context=document.createElement('p');
      context.textContent='Tercatat '+new Date(signal.firstSeen).toLocaleString('id-ID',{timeZone:'Asia/Singapore'})+' UTC+8 · '+signal.phase+(signal.ht?' · HT '+signal.ht:'')+(signal.score?' · skor '+signal.score:'')+(signal.afterFt?' · pertama tercatat setelah FT':'');
      const explanation=document.createElement('p');explanation.textContent=signal.detail || signal.title;
      detail.append(context,explanation);
      if(signal.href?.startsWith('index.php?')){const link=document.createElement('a');link.href=signal.href;link.textContent='Lihat riwayat H2H';detail.append(link);}
      wrapper.append(badge,detail);container.append(wrapper);
    }
    window.SabarajaSignalEvaluation.decorate(container, match, entry.signals);
  }
  document.addEventListener('visibilitychange',()=>{if(document.hidden){localSave();flush();}});
  window.SabarajaSignalHistory={load,capture,flush};
})();
