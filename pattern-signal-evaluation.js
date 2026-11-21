(() => {
  'use strict';
  const openAnalysis = new Set();
  const teamKey = value => String(value || '').replace(/\s*(?:\(V\)|\[V\])/gi,'').replace(/\s+/g,' ').trim().toLowerCase();
  const score = value => {const m=String(value || '').match(/^(\d+)\s*[-–:]\s*(\d+)$/);return m?[Number(m[1]),Number(m[2])]:null;};
  function inferRule(label) {
    if(/^H2H UNDER 2\.5/.test(label))return {period:'FT',kind:'total',side:'O',line:2.5,history:'under25'};
    if(/^H2H DRAW/.test(label))return {period:'FT',kind:'notDraw',history:'draw'};
    if(/^H2H HT 0[–-]0/.test(label))return {period:'HT',kind:'total',side:'O',line:0.5,history:'htZero'};
    if(/^H2H 2H 0[–-]0/.test(label))return {period:'2H',kind:'total',side:'O',line:0.5,history:'secondZero'};
    const exact=label.match(/^HT persis (\d+)[–-](\d+)/);
    if(exact)return {period:'HT',kind:'notExact',home:Number(exact[1]),away:Number(exact[2]),history:'htExact'};
    const market=label.match(/^H2H (FT|HT|2H) ([OU])(\d+(?:\.\d+)?)/);
    if(market)return {period:market[1],kind:'total',side:market[2],line:Number(market[3]),history:market[1]==='FT'&&market[2]==='O'?(Number(market[3])===0.5?'over05':Number(market[3])===1.5?'over15':null):null};
    if(/^HT .*gol 2H/.test(label))return {period:'2H',kind:'total',side:'O',line:0.5};
    return null;
  }
  function settled(rule, match) {
    const finished=match.status==='Selesai';
    if(rule.period==='HT')return !/^1H\b/i.test(match.live?.status || '') && !!score(match.live?.ht || match.ht);
    return finished;
  }
  function evaluate(rule, match, observed = null) {
    if(!rule)return {text:'Informasi',state:'info',reason:'Catatan riwayat ini tidak menentukan market hasil.'};
    if(match.status==='Ditunda')return {text:'Ditunda',state:'info',reason:'Pertandingan ditunda; belum dievaluasi.'};
    const afterHt=rule.period==='HT' && (score(observed?.ht) || /^(?:2H\b|HT$|H[.]?\s*Time|Half[ -]?Time)/i.test(observed?.phase || ''));
    if(observed && (observed.afterFt || afterHt))return {text:'Pascahasil',state:'info',reason:'Pertama tercatat setelah hasil babak terkait diketahui; tidak dihitung sebagai evaluasi sinyal sebelum hasil.'};
    if(match.status==='Selesai' && rule.period==='HT' && !score(match.ht))return {text:'Data belum lengkap',state:'info',reason:'Skor HT tidak tersedia.'};
    if(!settled(rule,match))return {text:'Menunggu',state:'pending',reason:'Menunggu hasil akhir babak '+rule.period+'.'};
    const ht=score(match.live?.ht || match.ht), ft=score(match.ft);
    let scores=rule.period==='HT'?ht:ft;
    if(rule.period==='2H')scores=ht&&ft&&ft[0]>=ht[0]&&ft[1]>=ht[1]?[ft[0]-ht[0],ft[1]-ht[1]]:null;
    if(!scores)return {text:'Data belum lengkap',state:'info',reason:'Skor HT/FT yang diperlukan belum tersedia.'};
    const total=scores[0]+scores[1];let hit=null;
    if(rule.kind==='draw')hit=scores[0]===scores[1];
    if(rule.kind==='notDraw')hit=scores[0]!==scores[1];
    if(rule.kind==='notExact')hit=scores[0]!==rule.home||scores[1]!==rule.away;
    if(rule.kind==='exact')hit=scores[0]===rule.home&&scores[1]===rule.away;
    if(rule.kind==='teamGoal' && [0,1].includes(rule.teamSide))hit=scores[rule.teamSide]>0;
    if(rule.kind==='total')hit=rule.side==='O'?total>rule.line:rule.side==='U'?total<rule.line:null;
    if(hit===null)return {text:'Informasi',state:'info',reason:'Aturan evaluasi belum tersedia.'};
    return {text:hit?'Kena':'Gagal',state:hit?'hit':'miss',reason:`Hasil ${rule.period} ${scores.join('–')}; evaluasi ${direction(rule)}; arah lawan untuk sinyal streak.`};
  }
  function direction(rule) {
    if(!rule)return '';
    if(rule.kind==='notDraw')return 'FT tidak seri';
    if(rule.kind==='notExact')return 'HT berbeda';
    if(rule.kind==='teamGoal')return rule.period+' gol '+(rule.teamSide===0?'kandang':'tandang');
    if(rule.kind==='total')return rule.period+' '+rule.side+rule.line;
    return rule.period;
  }
  function counterHistory(history,rule) {
    if(!history || history.mode==='counter-streak')return history;
    const reverse=['htZero','secondZero','under25','draw','htExact'].includes(rule?.history);
    return {...history,hits:reverse?history.total-history.hits:history.hits,mode:'counter-streak'};
  }
  const historyText = history => !history?.total ? 'Belum ada sampel hasil sebelumnya untuk pemicu ini.' : `Setelah pemicu yang sama: ${history.hits}/${history.total} sesuai market (${(100*history.hits/history.total).toFixed(1)}%)${history.unknown?' · '+history.unknown+' hasil tidak lengkap':''}.`;
  function badge(parent,label,rule,history,historical=false) {
    history=counterHistory(history,rule);
    const node=document.createElement('span');node.className='result-h2h-badge'+(historical?' result-historical-signal':'');
    node.textContent=label+(historical?' · historis':'');node.dataset.signalLabel=label;node.dataset.signalRule=JSON.stringify(rule);
    if(history)node.dataset.signalHistory=JSON.stringify(history);
    node.title='Dari CSV sebelum kickoff; home–away tetap untuk H2H. '+historyText(history);
    parent.append(node);
    if(history){const sample=document.createElement('p');sample.className='result-trigger-history';sample.textContent=historyText(history);parent.append(sample);}
  }
  function append(container,match,league='') {
    const a=match.signalAnalysis;if(!a)return;
    const details=document.createElement('details');details.className='result-analysis';
    const key=JSON.stringify([league,match.time,teamKey(match.home),teamKey(match.away)]);
    details.open=openAnalysis.has(key);
    details.addEventListener('toggle',()=>{if(details.open)openAnalysis.add(key);else openAnalysis.delete(key);});
    const summary=document.createElement('summary');summary.textContent='Analisis CSV';details.append(summary);
    const body=document.createElement('div');body.className='result-h2h-detail-body';
    const historical=match.status==='Selesai';
    for(const side of [0,1]) {
      const name=side===0?match.home:match.away;
      const entries=(a.teamNoGoal || []).filter(entry=>teamKey(entry.team)===teamKey(name));
      const line=document.createElement('p');line.textContent=(side===0?'Kandang':'Tandang')+' tanpa gol: '+entries.map(e=>e.period+' '+e.current+'× (rekor '+e.record+'×)').join(' · ');body.append(line);
      for(const entry of entries)if(entry.current>=2 && entry.current>=entry.record) {
        badge(body,`Gol ${entry.period} · ${side===0?'kandang':'tandang'} · setelah ${entry.current}× tanpa gol`,{period:entry.period,kind:'teamGoal',teamSide:side},entry.after,historical);
      }
    }
    const historyNames={htZero:'HT O0.5 setelah HT 0–0',secondZero:'2H O0.5 setelah 2H 0–0',under25:'FT O2.5 setelah Under 2.5',draw:'FT tidak seri setelah seri',over05:'FT O0.5 setelah FT 0–0',over15:'FT O1.5 setelah Under 1.5'};
    for(const [key,history] of Object.entries(a.triggerHistory || {})) {
      if(!history.triggerRun)continue;
      const line=document.createElement('p');line.textContent=historyNames[key]+' · pemicu '+history.triggerRun+'×: '+historyText(counterHistory(history,{history:key}));body.append(line);
    }
    const note=document.createElement('p');note.className='result-analysis-note';note.textContent='Streak tanpa reset hari/sesi. Pemicu historis memakai panjang streak yang sama persis; sampel hanya sebelum kickoff. Data tersimpan dapat belum lengkap. Evaluasi streak memakai arah lawannya; Over yang sudah eksplisit tetap Over; persentase adalah sampel historis, bukan jaminan.';body.append(note);
    details.append(body);container.append(details);
  }
  function exactTriggerValid(label, match) {
    const exact=label.match(/^HT persis (\d+)[–-](\d+) · (\d+)× sebelum kickoff · rekor (\d+)×/);
    if(!exact || !match.signalAnalysis?.htExact)return true;
    const current=match.signalAnalysis.htExact;
    return current.score===`${Number(exact[1])}:${Number(exact[2])}` &&
      current.previous===Number(exact[3]) && current.record===Number(exact[4]);
  }
  function decorate(container,match,observed={}) {
    for(const node of container.querySelectorAll('.result-h2h-badge, .result-history-alert')) {
      if(node.querySelector('.signal-outcome'))continue;
      const label=node.dataset.signalLabel || node.textContent.replace(/ · (?:historis|tersimpan)$/,'').trim();
      let rule=null,history=null;
      try{rule=node.dataset.signalRule?JSON.parse(node.dataset.signalRule):null;history=node.dataset.signalHistory?JSON.parse(node.dataset.signalHistory):null;}catch(_){}
      const stored=observed[label];rule=inferRule(label) || stored?.rule || rule;
      history=stored?.analysis || history || (rule?.history?match.signalAnalysis?.triggerHistory?.[rule.history]:null);
      const labelRun=label.match(/(?:· |setelah )(\d+)(?:×| H2H terakhir)/);
      if(history?.triggerRun && labelRun && Number(labelRun[1])!==history.triggerRun)history=null;
      history=counterHistory(history,rule);
      const valid=exactTriggerValid(label,match);
      const result=valid?evaluate(rule,match,stored || null):{text:'Pemicu tidak valid',state:'info',reason:'Sinyal tersimpan tidak cocok dengan perhitungan ulang riwayat sebelum kickoff pada CSV terbaru; hasil ini tidak dihitung sebagai Kena/Gagal.'};
      const outcome=document.createElement('span');outcome.className='signal-outcome is-'+result.state;outcome.textContent=(rule?direction(rule)+' · ':'')+result.text;
      outcome.title=result.reason+(history?' '+historyText(history):'');node.append(outcome);
      const context=history?historyText(history):'';
      if(context)node.title=(node.title || '')+' '+context;
      const detail=node.closest('.result-h2h-detail, .result-saved-signal')?.querySelector('.result-h2h-detail-body');
      if(detail){const note=document.createElement('p');note.className='signal-evaluation-detail';note.textContent=result.reason+(context?' '+context:'');detail.append(note);}

    }
  }
  window.SabarajaSignalEvaluation={inferRule,evaluate,append,decorate,historyText,counterHistory,exactTriggerValid};
})();
