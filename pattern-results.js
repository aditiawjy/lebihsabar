(() => {
  'use strict';
  const host = document.getElementById('pattern-results-host');
  if (!host) return;
  const date = document.getElementById('pattern-results-date');
  const teamSearch = document.getElementById('pattern-results-team-search');
  const phaseFilter = document.getElementById('pattern-results-phase');
  const leagueFilter = document.getElementById('pattern-results-league-filter');
  const marketFilter = document.getElementById('pattern-results-market-filter');
  const recordFilter = document.getElementById('pattern-results-record-filter');
  const searchStatus = document.getElementById('pattern-results-search-status');
  const refresh = document.getElementById('pattern-results-refresh');
  const save = document.getElementById('pattern-results-save');
  const saveStatus = document.getElementById('pattern-results-save-status');
  const saveSummary = document.getElementById('pattern-results-save-summary');
  const note = document.getElementById('pattern-results-note');
  const body = document.getElementById('pattern-results-body');
  const extraBody = document.getElementById('pattern-results-extra-body');
  const resultLeagues = ['15','16','20','vietnam15','f24-club','f24-international','f26-club','f26-international'];
  const isSessionLeague = league => ['16','vietnam15'].includes(String(league));
  let busy = false, timer = null, stopped = false, rendered = '';
  let saving = false, saveMatches = [], vsoccerSaveMatches = [], vsoccerSaveDate = null, pendingAutoSave = false;
  let savedFingerprint = '';
  let resultSnapshots = [], liveState = null, liveAnalysis = null;
  let apiTimestamp = '', lastLiveTimestamp = null;
  let apiHealth = 'unknown', apiHealthMessage = '', snapshotDate = null;
  const collapsedLeagues = new Set();
  const openRecordGroups = new Set(), openRecordTeams = new Set();
  const openH2hDetails = new Set();
  function reportTelegramHealth(source,status) {
    fetch('pattern-telegram-notify.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({type:'health',source,status}),signal:AbortSignal.timeout(20000)}).catch(()=>{});
  }
  document.addEventListener('pattern-live-analysis',event=>{
    const live=event.detail?.payload;if(!live)return;
    reportTelegramHealth('scraper',!live.online?'offline':!live.fresh?'stale':'ok');
  });
  function updateInfo() {
    const details = document.getElementById('pattern-results-details');
    if (details) details.textContent = [note.textContent, document.getElementById('pattern-results-live-note')?.textContent, saveStatus.title].filter(Boolean).join('\n');
  }
  function setApiState(message, state = 'loading') {
    note.textContent = message; note.hidden = state !== 'error';
    const badge = document.getElementById('pattern-results-api-state');
    if (badge) {
      badge.textContent = state === 'error' ? 'API gagal' : state === 'ok' ? 'API terhubung' : state === 'cached' ? 'API belum diperiksa' : 'API memuat…';
      badge.className = 'results-pill' + (state === 'error' ? ' is-error' : state === 'ok' ? ' is-ok' : '');
      badge.title = message;
    }
    updateInfo();
  }
  function showApiHealth(state, message) {
    apiHealth = state; apiHealthMessage = message;
    const panel = document.getElementById('pattern-api-health');
    panel.className = 'results-api-health' + (state === 'ok' ? ' is-ok' : state === 'error' ? ' is-error' : '');
    document.getElementById('pattern-api-health-title').textContent = state === 'ok' ? 'API MSports terhubung' : state === 'error' ? 'Pembaruan API MSports gagal' : state === 'loading' ? 'Memeriksa API MSports…' : 'Status API MSports belum diperiksa';
    document.getElementById('pattern-api-health-detail').textContent = message;
  }
  function setSaveState(label, detail = label, state = 'ok') {
    saveStatus.textContent = '▤ ' + (label || 'CSV menunggu'); saveStatus.title = detail; saveStatus.hidden = false;
    saveStatus.className = 'results-pill' + (state === 'error' ? ' is-error' : state === 'ok' ? ' is-ok' : '');
    updateInfo();
  }
  const isZeroZero = score => /^\s*0\s*[-–—:]\s*0\s*$/.test(String(score || ''));
  const scoreGoals = score => {
    const parsed = String(score || '').match(/^\s*(\d+)\s*[-–—:]\s*(\d+)\s*$/);
    return parsed ? [Number(parsed[1]),Number(parsed[2])] : null;
  };
  function matchPhase(match) {
    if (match.status === 'Ditunda') return 'postponed';
    if (match.status === 'Selesai') return 'finished';
    const livePhase = String(match.live?.status || '');
    if (match.live) return /^(?:H\.?\s*Time|HT$|Half[ -]?Time)/i.test(livePhase) ? 'halftime' : 'live';
    return match.ht ? 'halftime' : 'scheduled';
  }
  const isUnder15 = score => {
    const goals = scoreGoals(score);
    return goals !== null && goals[0] + goals[1] < 2;
  };
  function under15StreakTeams(matches, league, thresholds) {
    const periodKey = isSessionLeague(league)
      ? time => new Date(Date.parse(time)-4*3600000).toISOString().slice(0,10)
      : time => new Date(Date.parse(time)+8*3600000).toISOString().slice(0,10);
    return resultStreakTeams(matches,1,team => thresholds?.[noGoalTeamKey(team)],periodKey);
  }
  function resultStreakTeams(matches,maxGoals,threshold=3,withinDay=false) {
    const streaks = new Map();
    const lastDays = new Map();
    const nameOf = team => String(team || '').replace(/\s*\(V\)/gi,'').replace(/\s+/g,' ').trim();
    for (const match of [...matches].sort((a,b)=>String(a.time || '').localeCompare(String(b.time || '')))) {
      if (match.status !== 'Selesai') continue;
      const goals = scoreGoals(match.ft);
      if (!goals) continue;
      for (const team of [match.home,match.away]) {
        const name = nameOf(team);
        const day = typeof withinDay === 'function' ? withinDay(match.time) : String(match.time || '').slice(0,10);
        if (withinDay && lastDays.get(name)!==day) streaks.set(name,0);
        lastDays.set(name,day);
        if (name) streaks.set(name,goals[0]+goals[1] <= maxGoals ? (streaks.get(name)||0)+1 : 0);
      }
    }
    // Live scores can break a streak, but cannot add an unfinished result.
    for (const match of matches) {
      if (!match.live?.fresh) continue;
      const goals = scoreGoals(match.live.score);
      if (goals && goals[0]+goals[1] > maxGoals)
        for (const team of [match.home,match.away]) streaks.set(nameOf(team),0);
    }
    return [...streaks].filter(([team,count])=>{ const minimum = typeof threshold === 'function' ? threshold(team) : threshold; return Number.isInteger(minimum) && minimum > 0 && count>=minimum; }).sort((a,b)=>b[1]-a[1]||a[0].localeCompare(b[0]));
  }
  const noGoalTeamKey = name=>String(name || '').replace(/\s*\(V\)/gi,'').replace(/\s+/g,' ').trim().toLowerCase();
  function teamNoGoalStreak(matches,team,threshold,league='15') {
    const sideOf = match => [match.home,match.away].findIndex(name=>noGoalTeamKey(name)===noGoalTeamKey(team));
    let count=0,day=null;
    for (const match of [...matches].sort((a,b)=>String(a.time || '').localeCompare(String(b.time || '')))) {
      const side=sideOf(match);if(side<0 || match.status!=='Selesai')continue;
      const goals=scoreGoals(match.ft);if(!goals)continue;
      const currentDay=isSessionLeague(league) ? new Date(Date.parse(match.time)-4*3600000).toISOString().slice(0,10) : String(match.time || '').slice(0,10);
      if(day!==currentDay)count=0;
      day=currentDay;count=goals[side]===0?count+1:0;
    }
    for(const match of matches) {
      const side=sideOf(match);if(side<0 || match.status==='Selesai')continue;
      const goals=match.live?.fresh?scoreGoals(match.live.score):!match.live?scoreGoals(match.ht):null;
      if(goals && goals[side]>0)count=0;
    }
    return count>=threshold?count:0;
  }
  const zeroZeroThreshold = league => ({'15':5,'16':4,'20':3})[String(league)] || 2;
  function repeatedZeroZeroTeams(matches, league) {
    const counts = new Map();
    for (const match of matches) {
      if (match.status !== 'Selesai' || !isZeroZero(match.ft)) continue;
      for (const team of [match.home,match.away]) {
        const name = String(team || '').replace(/\s*\(V\)/gi,'').replace(/\s+/g,' ').trim();
        if (name) counts.set(name,(counts.get(name)||0)+1);
      }
    }
    return [...counts.entries()].filter(([,count])=>count>=zeroZeroThreshold(league))
      .sort((a,b)=>b[1]-a[1]||a[0].localeCompare(b[0]));
  }
  const teamCodes = {romania:'ROU',italy:'ITA',wales:'WAL',brazil:'BRA',poland:'POL',portugal:'POR',sweden:'SWE',
    netherlands:'NED',germany:'GER',france:'FRA',england:'ENG',spain:'ESP',argentina:'ARG',belgium:'BEL',
    'czech republic':'CZE',denmark:'DEN',finland:'FIN',ghana:'GHA',hungary:'HUN',mexico:'MEX',morocco:'MAR',
    norway:'NOR',qatar:'QAT',scotland:'SCO',ukraine:'UKR',usa:'USA','new zealand':'NZL',
    'korea republic':'KOR','inter milan':'INT','paris saint germain':'PSG'};
  const teamCode = name => teamCodes[name.toLowerCase()] || (name.trim().split(/\s+/).length > 1
    ? name.trim().split(/\s+/).map(part=>part[0]).join('').slice(0,4) : name.slice(0,3)).toUpperCase();
  function resultHistoryAlertsByMatch(matches, league, under15Thresholds = {}) {
    const counts = new Map();
    const under15Counts = new Map();
    const alerts = new Map();
    const scope = isSessionLeague(league) ? 'sesi ini' : 'hari ini';
    const under15Day = match => {
      const timestamp = Date.parse(match.time);
      return Number.isFinite(timestamp) ? new Date(timestamp + 8 * 3600000).toISOString().slice(0,10) : null;
    };
    const chronological = [...matches].sort((a,b)=>String(a.time).localeCompare(String(b.time)));
    for (let start = 0; start < chronological.length;) {
      const time = chronological[start].time;
      let end = start + 1;
      while (end < chronological.length && chronological[end].time === time) end++;
      const group = chronological.slice(start,end);
      for (const match of group) {
        const isFinishedZeroZero = match.status === 'Selesai' && isZeroZero(match.ft);
        const isFinishedUnder15 = match.status === 'Selesai' && isUnder15(match.ft);
        const teamAlerts = [];
        for (const team of [match.home,match.away]) {
          const name = String(team || '').replace(/\s*\(V\)/gi,'').replace(/\s+/g,' ').trim();
          if (!name) continue;
          const previous = counts.get(name) || {zeroZero:0,under15:0};
          const zeroZeroCount = previous.zeroZero + (isFinishedZeroZero ? 1 : 0);
          const day = under15Day(match);
          const under15Key = day && JSON.stringify([day,noGoalTeamKey(name)]);
          const under15Count = (under15Counts.get(under15Key) || 0) + (isFinishedUnder15 ? 1 : 0);
          const under15Threshold = under15Thresholds[noGoalTeamKey(name)];
          if (zeroZeroCount >= zeroZeroThreshold(league)) teamAlerts.push({kind:'zerozero',highFrequency:zeroZeroCount >= 3,label:`${zeroZeroCount >= 3 ? '🔥 ' : ''}${teamCode(name)}: ${zeroZeroCount}× (0-0)`,detail:`${name}: FT 0–0 sudah ${zeroZeroCount}× (≥${zeroZeroThreshold(league)}) ${scope}`});
          if (under15Key && Number.isInteger(under15Threshold) && under15Threshold > 0 && under15Count >= under15Threshold) teamAlerts.push({kind:'under15',label:`${teamCode(name)}: ${under15Count}× (U1.5)`,detail:`${name}: FT Under 1.5 ${under15Count}× pada ${day} UTC+8; rekor harian CSV ${under15Threshold}×.`});
        }
        if (teamAlerts.length) alerts.set(match,teamAlerts);
      }
      for (const match of group) {
        if (match.status !== 'Selesai') continue;
        const goals = scoreGoals(match.ft);
        if (!goals) continue;
        for (const team of [match.home,match.away]) {
          const name = String(team || '').replace(/\s*\(V\)/gi,'').replace(/\s+/g,' ').trim();
          if (!name) continue;
          const previous = counts.get(name) || {zeroZero:0,under15:0};
          if (goals[0] + goals[1] === 0) previous.zeroZero++;
          if (goals[0] + goals[1] < 2 && under15Day(match)) {
            const key = JSON.stringify([under15Day(match),noGoalTeamKey(name)]);
            under15Counts.set(key,(under15Counts.get(key) || 0)+1);
          }
          counts.set(name,previous);
        }
      }
      start = end;
    }
    return alerts;
  }
  function updateSaveButton() {
    const count = saveMatches.length + (vsoccerSaveDate === date.value ? vsoccerSaveMatches.length : 0);
    save.disabled = busy || saving || !count;
    save.textContent = saving ? 'Menyinkronkan…' : 'Sinkronkan ' + count + ' hasil ke matches.csv';
  }
  const interval = 300000;
  function schedule() {
    clearTimeout(timer);
    if (!stopped && !document.hidden) timer = setTimeout(() => load(true), interval);
  }
  async function load(update = false) {
    if (busy || !date.value || stopped || (update && document.hidden)) return;
    clearTimeout(timer);
    const requestedDate = date.value;
    busy = true; refresh.disabled = true; date.disabled = true;
    updateSaveButton();
    if (update) showApiHealth('loading', 'Menghubungi API. Data tersimpan tetap ditampilkan selama pembaruan.');
    setApiState(update ? 'Mengambil hasil terbaru dari API…' : 'Memuat hasil tersimpan…');
    try {
      const snapshots = [];
      let updateError = null;
      // All leagues share the same UTC+8 snapshot: refresh it only once.
      for (const league of resultLeagues) {
        let response = await fetch('pattern-results-data.php?date=' + encodeURIComponent(requestedDate) + '&league=' + league, {
          method: update && league === '15' ? 'POST' : 'GET', cache:'no-store', signal:AbortSignal.timeout(90000)
        });
        let data = await response.json();
        if (!response.ok && update && league === '15') {
          updateError = data.error || 'Pembaruan API gagal.';
          response = await fetch('pattern-results-data.php?date=' + encodeURIComponent(requestedDate) + '&league=' + league, {cache:'no-store', signal:AbortSignal.timeout(90000)});
          data = await response.json();
        }
        if (!response.ok) throw new Error(data.error || 'Gagal memuat hasil API League ' + league + '.');
        snapshots.push(data);
      }
      saveMatches = snapshots.flatMap(data => data.saveMatches || []);
      const liveSnapshot = snapshots.find(data => data.league === '16');
      if (liveSnapshot) {
        // The initial page render reads the saved API snapshot. Give the live
        // evaluator that same schedule immediately, before the first refresh.
        window.SabarajaPatternResults = {
          date:requestedDate,
          fixtures:liveSnapshot.matches,
          snapshots,
          fetchedAt:Date.now(),
          sourceUpdatedAt:liveSnapshot.updatedAt || null
        };
        window.SabarajaPatternLive?.refreshView();
      }
      resultSnapshots = snapshots;
      snapshotDate = requestedDate;
      const times = snapshots.map(data => data.updatedAt && new Date(data.updatedAt).getTime()).filter(Boolean);
      apiTimestamp = times.length ? new Date(Math.min(...times)).toLocaleString('id-ID', {timeZone:'Asia/Singapore',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'}) + ' UTC+8' : '';
      document.getElementById('pattern-results-last-updated').textContent = apiTimestamp ? 'API ' + apiTimestamp : 'API belum tersedia';
      const incomplete = snapshots.some(data => data.snapshotFilesAvailable < data.snapshotFilesExpected);
      if (updateError) {
        await window.SabarajaSyncHistory?.failure('MSports',requestedDate,'fetch',updateError);
        const expired = /401|403|menolak sesi|perbarui sesi login/i.test(updateError);
        reportTelegramHealth('api', expired ? 'denied' : 'error');
        showApiHealth('error', (expired ? 'Sesi ditolak (HTTP 401/403); kemungkinan kedaluwarsa. Perbarui sesi login dengan curl terbaru. ' : updateError + ' ') + (apiTimestamp ? 'Berkas tersimpan terlama: ' + apiTimestamp + '.' : 'Belum ada data tersimpan untuk tanggal ini.') + ' Akan dicoba kembali dalam 5 menit.');
        setApiState(apiHealthMessage, 'error');
      } else {
        if (update) reportTelegramHealth('api',!times.length || Date.now()-Math.min(...times)>180000?'stale':'ok');
        showApiHealth(update ? 'ok' : 'unknown', (update ? 'Pembaruan berhasil. ' : 'Menampilkan data tersimpan; koneksi API belum diperiksa. ') + (apiTimestamp ? 'Berkas tersimpan terlama: ' + apiTimestamp + '.' : 'Belum ada data tersimpan untuk tanggal ini.') + (incomplete ? ' Sebagian berkas sesi belum tersedia; data bisa belum lengkap.' : ''));
        setApiState(`L15/L20: ${requestedDate}, 00:00–23:59 UTC+8 · L16: ${liveSnapshot?.periodLabel || 'sesi 12:00–00:45 hari berikutnya'} · Otomatis setiap 5 menit · ${saveMatches.length} hasil selesai siap disimpan` +
        ' · Vietnam V League 1: sesi 12:00–00:45 hari berikutnya UTC+8 · E-Football F24/F26: tanggal kalender UTC+8 · Vietnam dan E-Football: API saja' +
        (times.length ? ' · Pembaruan terlama ' + new Date(Math.min(...times)).toLocaleString('id-ID') : ''), update ? 'ok' : 'cached');
      }
      rendered = ''; renderResults();
      if (update && !updateError) await saveResults(true);
      // Server reads current local API results itself; no credentials or chat IDs in browser.
      fetch('pattern-telegram-notify.php',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}',signal:AbortSignal.timeout(20000)})
        .then(response=>response.json()).then(result=>{if(!result.success)console.warn('Telegram: notifikasi belum terkirim.');})
        .catch(()=>console.warn('Telegram: menunggu pembaruan berikutnya untuk mencoba lagi.'));
    } catch (error) {
      if (update) await window.SabarajaSyncHistory?.failure('MSports',requestedDate,'fetch',error);
      reportTelegramHealth('api',/401|403|menolak sesi|perbarui sesi login/i.test(error.message || '')?'denied':'error');
      if (snapshotDate !== requestedDate) { resultSnapshots = []; saveMatches = []; }
      showApiHealth('error', (error.message || 'Tidak dapat memuat hasil API.') + (apiTimestamp && snapshotDate === requestedDate ? ' Data tersimpan terakhir: ' + apiTimestamp + '.' : ' Data untuk tanggal ini belum dapat dimuat.') + ' Akan dicoba kembali dalam 5 menit.');
      rendered = ''; renderResults();
      setApiState((error.message || 'Tidak dapat memuat hasil API.') + ' Mencoba kembali dalam 5 menit.', 'error');
    }
    finally {
      document.dispatchEvent(new CustomEvent('pattern-results-cycle', {detail:{date:requestedDate,update}}));
      busy = false; refresh.disabled = false; date.disabled = false;
      updateSaveButton();
      schedule();
    }
  }
  function renderResults() {
      const query = teamSearch.value.normalize('NFKC').trim().toLocaleLowerCase('id-ID');
      const selectedPhase = phaseFilter.value;
      const selectedLeague=leagueFilter.value, selectedMarket=marketFilter.value, selectedRecord=recordFilter.value;
      const matchesSearch = (match,data) => {
        if (query && ![match.home,match.away].some(name => String(name || '').normalize('NFKC').toLocaleLowerCase('id-ID').includes(query))) return false;
        if (selectedPhase !== 'all' && matchPhase(match) !== selectedPhase) return false;
        if (selectedMarket !== 'all') {
          const goals = match.status === 'Selesai' ? scoreGoals(match.ft) : null;
          if (!goals) return false;
          const total=goals[0]+goals[1];
          if (!(selectedMarket === 'zero' ? total===0 : selectedMarket === 'under15' ? total<2 : selectedMarket === 'over15' ? total>=2 : selectedMarket === 'under25' ? total<3 : total>=3)) return false;
        }
        if (selectedRecord !== 'all') {
          const signals = selectedRecord === 'near' ? [...(data.zeroNearRecordSignals || []),...(data.under15NearRecordSignals || [])] : [...(data.zeroRecordSignals || []),...(data.under15RecordSignals || [])];
          if (!signals.some(signal => signal.date === String(match.time || '').slice(0,10) && [match.home,match.away].some(name=>noGoalTeamKey(name)===noGoalTeamKey(signal.team)))) return false;
        }
        return true;
      };
      const snapshots = (resultSnapshots.length ? resultSnapshots : ['15','16','20'].map(league => ({league,date:date.value,timezone:'UTC+8',matches:[]})))
        .map(data => ({...data, displayMatches:window.SabarajaLiveResults.merge(data,liveState,liveAnalysis)}));
      const signature = JSON.stringify([query, selectedPhase,selectedLeague,selectedMarket,selectedRecord, [...collapsedLeagues], snapshots.map(data => [data.league, data.date, data.displayMatches, data.noGoalThresholds, data.under15Thresholds, data.under15StreakThresholds, data.zeroStreakThresholds, data.zeroRecordSignals, data.under15RecordSignals,data.zeroNearRecordSignals,data.under15NearRecordSignals])]);
      if (signature !== rendered) {
        window.SabarajaPatternDisplay = {snapshots};
        const visibleCount = snapshots.filter(data=>selectedLeague==='all'||selectedLeague===data.league).reduce((sum,data) => sum + data.displayMatches.filter(match=>matchesSearch(match,data)).length,0);
        searchStatus.hidden = !query && selectedPhase === 'all' && selectedLeague === 'all' && selectedMarket === 'all' && selectedRecord === 'all';
        searchStatus.textContent = !searchStatus.hidden ? `${visibleCount} pertandingan cocok pada liga MSports. Ringkasan dan sinkronisasi tetap mencakup seluruh pertandingan.` : '';
        const positions = [...host.querySelectorAll('.results-scroll')].map(el => el.scrollTop);
        // Build off-screen so periodic updates never collapse the page mid-scroll.
        const pagePositions = [];
        for (let el = host.parentElement; el; el = el.parentElement) {
          if (el.scrollHeight > el.clientHeight) pagePositions.push([el, el.scrollTop, el.scrollLeft]);
        }
        const viewportPosition = [window.scrollX, window.scrollY];
        const mainCards = document.createDocumentFragment();
        const extraCards = document.createDocumentFragment();
        const extraSection = document.querySelector('.results-api-only-section');
        if (extraSection) extraSection.hidden = selectedLeague !== 'all' && !selectedLeague.startsWith('f24-') && !selectedLeague.startsWith('f26-');
        const restoredScrolls = [];
        for (const [index, data] of snapshots.entries()) {
          if (selectedLeague !== 'all' && selectedLeague !== data.league) continue;
          const card = document.createElement('section'); card.className = 'results-card' + (data.apiOnly ? ' api-only-card' : '');
          card.id = 'results-league-' + data.league;
          card.classList.toggle('results-vietnam-card', data.league === 'vietnam15');
          const heading = document.createElement('h3'); heading.className = 'results-card-heading';
          const headingText = document.createElement('span'); headingText.textContent = (data.displayName || ('League ' + data.league)) + ' · ' + data.timezone;
          heading.append(headingText);
          if (data.apiOnly) {
            heading.title = data.name;
            const apiTag = document.createElement('span'); apiTag.className = 'results-api-only-tag'; apiTag.textContent = 'API saja';
            headingText.append(apiTag);
          }
          const collapse = document.createElement('button'); collapse.type = 'button'; collapse.className = 'results-collapse';
          collapse.textContent = collapsedLeagues.has(data.league) ? 'Buka' : 'Tutup';
          collapse.setAttribute('aria-expanded', String(!collapsedLeagues.has(data.league)));
          collapse.setAttribute('aria-label', `${collapse.textContent} ${data.displayName || ('League ' + data.league)}`);
          heading.append(collapse);
          collapse.addEventListener('click', () => {
            if (collapsedLeagues.has(data.league)) collapsedLeagues.delete(data.league); else collapsedLeagues.add(data.league);
            renderResults();
            document.getElementById('results-league-' + data.league)?.querySelector('.results-collapse')?.focus({preventScroll:true});
          });
          const info = document.createElement('div');
          info.className = 'results-summary' + (data.apiOnly ? ' is-api-only' : '');
          info.setAttribute('aria-label', 'Ringkasan pertandingan ' + (data.displayName || data.league));
          const finishedMatches = data.matches.filter(m => m.status === 'Selesai' && scoreGoals(m.ft));
          const zeroZeroTotal = finishedMatches.filter(m => isZeroZero(m.ft)).length;
          const zeroZeroRate = finishedMatches.length ? (100*zeroZeroTotal/finishedMatches.length).toFixed(1)+'%' : '—';
          const summaryItem = (label, value, detail = '', title = '') => {
            const item = document.createElement('div'); item.className = 'results-summary-item' + (label === 'FT 0–0' ? ' is-zero' : '');
            if (title) item.title = title;
            const name = document.createElement('span'); name.textContent = label;
            const number = document.createElement('strong'); number.textContent = String(value);
            item.append(name, number);
            if (detail) { const extra = document.createElement('small'); extra.textContent = detail; item.append(extra); }
            info.append(item);
          };
          summaryItem('Pertandingan', data.matches.length);
          summaryItem('Selesai', data.matches.filter(m => m.status === 'Selesai').length);
          if (!data.apiOnly) summaryItem('Live', data.displayMatches.filter(m => m.live?.fresh).length);
          summaryItem('FT 0–0', zeroZeroTotal, zeroZeroRate, `${zeroZeroTotal} dari ${finishedMatches.length} pertandingan selesai dengan skor FT valid pada rentang kartu ini.`);
          const period = document.createElement('p'); period.className = 'results-session-range';
          period.textContent = data.periodLabel ? 'Rentang: ' + data.periodLabel : 'Rentang belum tersedia';
          if (data.apiOnly) {
            const source = document.createElement('span'); source.className = 'results-source-note';
            source.textContent = 'Data dari API; tidak tersedia pembaruan live.';
            period.append(source);
          }
          const zeroZeroCount = document.createElement('div');
          zeroZeroCount.className = 'results-zerozero-count';
          const zeroRecordSignals = [
            ...(data.zeroRecordSignals || []).map(signal=>({...signal,metricLabel:'FT 0–0',metricClass:'results-zero-record'})),
            ...(data.under15RecordSignals || []).map(signal=>({...signal,metricLabel:'FT Under 1.5',metricClass:'results-under15-record'}))
          ];
          const recordGroup = document.createElement('details');
          recordGroup.className = 'results-record-group'; recordGroup.open = openRecordGroups.has(data.league);
          const groupTitle = document.createElement('summary');
          const recordTeams = new Set(zeroRecordSignals.map(signal=>noGoalTeamKey(signal.team)));
          groupTitle.textContent = `Rekor harian tercapai · ${recordTeams.size} tim · ${zeroRecordSignals.length} catatan`;
          recordGroup.append(groupTitle);
          recordGroup.addEventListener('toggle', () => { if (recordGroup.open) openRecordGroups.add(data.league); else openRecordGroups.delete(data.league); });
          for (const signal of zeroRecordSignals) {
            const key = JSON.stringify([data.league,signal.team,signal.date,signal.metricLabel]);
            const detail = document.createElement('details'); detail.className = 'results-record-detail'; detail.open = openRecordTeams.has(key);
            detail.addEventListener('toggle', () => { if (detail.open) openRecordTeams.add(key); else openRecordTeams.delete(key); });
            const title = document.createElement('summary');
            title.textContent = `${signal.team} · ${signal.metricLabel} ${signal.count}/${signal.record}× · ${signal.date.split('-').reverse().join('/')}`;
            detail.append(title);
            const explanation = document.createElement('p');
            explanation.textContent = `Rekor tersimpan: ${signal.recordDates.map(day=>day.split('-').reverse().join('/')).join(', ')}. Rekor historis bukan batas jumlah hasil berikutnya.`;
            detail.append(explanation);
            const games = data.matches.filter(match => match.status === 'Selesai' && String(match.time || '').slice(0,10) === signal.date &&
              [match.home,match.away].some(name => noGoalTeamKey(name) === noGoalTeamKey(signal.team)) &&
              (signal.metricLabel === 'FT 0–0' ? isZeroZero(match.ft) : isUnder15(match.ft)));
            const list = document.createElement('ul');
            for (const match of games) {
              const item = document.createElement('li');
              item.textContent = `${String(match.time).slice(11,16)} · ${match.home} vs ${match.away} · FT ${match.ft}`;
              list.append(item);
            }
            if (!games.length) { const item = document.createElement('li'); item.textContent = 'Pertandingan terkait tidak tersedia dalam rentang kartu ini.'; list.append(item); }
            detail.append(list);
            if (games.length < signal.count) { const note = document.createElement('p'); note.textContent = 'Sebagian hasil rekor berasal dari riwayat tersimpan di luar rentang kartu ini.'; detail.append(note); }
            recordGroup.append(detail);
          }
          recordGroup.hidden = !zeroRecordSignals.length;
          const repeatedTeams = repeatedZeroZeroTeams(data.matches,data.league);
          const zeroLabel = document.createElement('span'); zeroLabel.className = 'results-zerozero-label';
          zeroLabel.textContent = 'FT 0–0'; zeroZeroCount.append(zeroLabel);
          if (!repeatedTeams.length) {
            const empty = document.createElement('span'); empty.className = 'results-zerozero-empty';
            empty.textContent = `Belum ada tim ≥${zeroZeroThreshold(data.league)}×`; zeroZeroCount.append(empty);
          }
          for (const [team, count] of repeatedTeams) {
            const chip = document.createElement('span'); chip.className = 'results-zerozero-chip';
            chip.classList.toggle('is-high-frequency', count >= 3);
            chip.textContent = `${count >= 3 ? '🔥 ' : ''}${team} (${count}×)`;
            chip.title = `${team}: FT 0–0 ${count} kali ${isSessionLeague(data.league) ? 'dalam sesi ini' : 'pada tanggal ini'}`;
            zeroZeroCount.append(chip);
          }
          const streakGroups = [{zeroZeroStreak:false,underStreaks:under15StreakTeams(data.displayMatches,data.league,data.under15StreakThresholds)}];
          {
            const periodKey = isSessionLeague(data.league)
              ? time=>new Date(Date.parse(time)-4*3600000).toISOString().slice(0,10) : time=>new Date(Date.parse(time)+8*3600000).toISOString().slice(0,10);
            streakGroups.unshift({zeroZeroStreak:true,underStreaks:resultStreakTeams(data.displayMatches,0,team=>data.zeroStreakThresholds?.[noGoalTeamKey(team)],periodKey)});
          }
          for (const {zeroZeroStreak,underStreaks} of streakGroups) {
          if (underStreaks.length || zeroZeroStreak) {
            const label = document.createElement('span'); label.className = 'results-zerozero-label';
            label.textContent = zeroZeroStreak ? 'FT 0–0 beruntun' : 'U1.5 beruntun'; zeroZeroCount.append(label);
            if (!underStreaks.length) {
              const empty = document.createElement('span'); empty.className = 'results-zerozero-empty';
              empty.textContent = `Belum ada tim yang mencapai rekor CSV ${isSessionLeague(data.league)?'sesi ini':'hari ini'}`;
              zeroZeroCount.append(empty);
            }
            for (const [team,count] of underStreaks) {
              const chip = document.createElement('span'); chip.className = 'results-zerozero-chip results-under-streak';
              chip.textContent = `${team} (${count}× beruntun)`;
              chip.title = zeroZeroStreak
                ? `${team}: ${count} hasil FT 0–0 berturut-turut pada rentang kartu ini; rekor beruntun CSV ${data.zeroStreakThresholds?.[noGoalTeamKey(team)]}×. Hilang saat pertandingan berikutnya mencetak gol.`
                : `${team}: ${count} hasil FT Under 1.5 berturut-turut pada rentang kartu ini; rekor beruntun CSV ${data.under15StreakThresholds?.[noGoalTeamKey(team)]}×. Hilang saat pertandingan berikutnya mencapai total 2 gol.`;
              zeroZeroCount.append(chip);
            }
          }
          }
          const noGoalScope = isSessionLeague(data.league)?'sesi ini':'hari ini';
          const noGoalSignals = ['15','16','20'].includes(String(data.league)) ? Object.entries(data.noGoalThresholds || {}).filter(([,threshold])=>Number.isInteger(threshold) && threshold>0).map(([team,threshold])=>({team,threshold,count:teamNoGoalStreak(data.displayMatches,team,threshold,data.league)})).filter(signal=>signal.count>0) : [];
          for (const signal of noGoalSignals) {
            const chip = document.createElement('span'); chip.className = 'results-zerozero-chip is-high-frequency';
            chip.textContent = `${signal.team}: tidak mencetak gol ${signal.count}× beruntun`;
            chip.title = `Ambang pantauan ${signal.threshold}×, dihitung dari rekor terbaru matches.csv. Gol tim sendiri, kandang/tandang, hanya hasil FT selesai ${noGoalScope}. Hilang jika tim mencetak gol. Bukan jaminan gol pada laga berikutnya.`;
            zeroZeroCount.append(chip);
          }
          const resultAlerts = resultHistoryAlertsByMatch(data.matches,data.league,data.under15Thresholds);
          // Put each active record on one relevant fixture, regardless of table order.
          const selectAlertMatch = (team, day = null) => data.displayMatches
            .filter(match => match.status !== 'Selesai' && match.status !== 'Ditunda' && match.time &&
              (!day || String(match.time).slice(0,10) === day) &&
              [match.home,match.away].some(name => noGoalTeamKey(name) === noGoalTeamKey(team)))
            .sort((a,b) => Number(!!b.live?.fresh) - Number(!!a.live?.fresh) ||
              Number(!!b.ht) - Number(!!a.ht) || String(a.time).localeCompare(String(b.time)))[0];
          const noGoalTargets = new Map(noGoalSignals.map(signal => [signal,selectAlertMatch(signal.team)]));
          const scroll = document.createElement('div'); scroll.className = 'results-scroll';
          const table = document.createElement('table');
          const colgroup = document.createElement('colgroup');
          for (const width of ['68px', '', '40px', '40px']) {
            const col = document.createElement('col');
            if (width) col.style.width = width;
            colgroup.append(col);
          }
          table.append(colgroup);
          const head = table.createTHead().insertRow();
          for (const label of ['Jam / Fase', 'Pertandingan', 'HT', 'Skor']) {
            const th = document.createElement('th'); th.scope = 'col'; th.textContent = label;
            if (label === 'Skor') th.title = 'Skor berjalan saat live; skor FT untuk pertandingan selesai';
            head.append(th);
          }
          const tbody = table.createTBody();
          const visibleMatches = data.displayMatches.filter(match=>matchesSearch(match,data));
          for (const match of visibleMatches) {
            const row = tbody.insertRow();
            const time = row.insertCell();
            time.className = 'result-time-cell';
            time.append(document.createTextNode(match.time ? match.time.slice(11,16) : '—'));
            if (isSessionLeague(data.league) && match.time && data.sessionDate && match.time.slice(0,10) !== data.sessionDate) {
              const day = document.createElement('small'); day.className = 'result-match-day';
              day.textContent = match.time.slice(8,10) + '/' + match.time.slice(5,7); time.append(day);
            }
            if (match.live) row.className = match.live.fresh ? 'result-live-row' : 'result-stale-row';
            const state = document.createElement('span');
            const livePhase = String(match.live?.status || '');
            const liveHalftime = /^(?:H\.?\s*Time|HT$|Half[ -]?Time)/i.test(livePhase);
            let phase = matchPhase(match), phaseLabel = 'Jadwal';
            if (phase === 'postponed') { phaseLabel = 'Ditunda'; }
            else if (phase === 'finished') { phaseLabel = 'Selesai'; }
            else if (match.live) {
              phase = match.live.fresh ? (liveHalftime ? 'halftime' : 'live') : 'stale';
              phaseLabel = (liveHalftime ? 'HT' : 'Live' + (livePhase ? ' · ' + livePhase : '')) + (match.live.fresh ? '' : ' · data lama');
            } else if (match.ht) { phase = 'halftime'; phaseLabel = 'HT'; }
            state.className = 'result-state result-state-' + phase;
            state.textContent = phaseLabel;
            time.append(state);
            const teams = row.insertCell();
            teams.append(document.createTextNode(match.home + ' vs ' + match.away));
            if (match.status !== 'Selesai' && match.status !== 'Ditunda' && match.h2h) {
              const liveStatus = String(match.live?.status || '');
              const afterFirstHalf = /^(?:2H\b|H\.?\s*Time|HT$|Half[ -]?Time)/i.test(liveStatus);
              const liveHt = scoreGoals(match.live?.ht || match.ht);
              const liveScore = scoreGoals(match.live?.score);
              const secondHalfGoal = afterFirstHalf && liveHt && liveScore && (liveScore[0] > liveHt[0] || liveScore[1] > liveHt[1]);
              for (const [label, streak, available] of [
                ['HT 0–0', match.h2h.htZeroStreak, !afterFirstHalf],
                ['2H 0–0', match.h2h.secondHalfZeroStreak, !secondHalfGoal]
              ]) {
                if (!available || !streak?.record || streak.current < streak.record) continue;
                const badge = document.createElement('a');
                badge.className = 'result-h2h-badge';
                badge.textContent = `H2H ${label} · ${streak.current}× beruntun · rekor ${streak.record}×`;
                badge.title = `Riwayat H2H di liga yang sama sebelum kickoff dari CSV. ${label === '2H 0–0' ? '2H 0–0 berarti tidak ada gol pada babak kedua.' : 'HT 0–0 berarti tidak ada gol pada babak pertama.'} Hasil historis bukan jaminan.`;
                badge.href = 'index.php?' + new URLSearchParams({page:'h2h',home:match.home,away:match.away,league:data.historyLeagueName || ''});
                teams.append(badge);
              }
            }
            if (match.status !== 'Selesai' && match.status !== 'Ditunda' && match.h2h?.under25StreakRecord > 0 && match.h2h.under25StreakCurrent >= match.h2h.under25StreakRecord) {
              const badge = document.createElement('a');
              badge.className = 'result-h2h-badge';
              badge.textContent = `H2H UNDER 2.5 · ${match.h2h.under25StreakCurrent}× beruntun · rekor ${match.h2h.under25StreakRecord}×`;
              badge.title = 'Hasil H2H Under 2.5 terbaru mencapai rekor beruntun pasangan ini di liga yang sama dalam CSV sebelum kickoff. Hasil historis bukan jaminan.';
              badge.href = 'index.php?' + new URLSearchParams({page:'h2h',home:match.home,away:match.away,league:data.historyLeagueName || ''});
              teams.append(badge);
            }
            if (match.status !== 'Selesai' && match.status !== 'Ditunda' && match.h2h?.drawStreakRecord > 0 && match.h2h.drawStreakCurrent >= match.h2h.drawStreakRecord) {
              const badge = document.createElement('a');
              badge.className = 'result-h2h-badge';
              badge.textContent = `H2H DRAW · ${match.h2h.drawStreakCurrent}× seri beruntun · rekor ${match.h2h.drawStreakRecord}×`;
              badge.title = 'Hasil seri H2H terbaru mencapai rekor beruntun pasangan ini di liga yang sama dalam CSV sebelum kickoff. Hasil historis bukan jaminan.';
              badge.href = 'index.php?' + new URLSearchParams({page:'h2h',home:match.home,away:match.away,league:data.historyLeagueName || ''});
              teams.append(badge);
            }
            if (match.live?.fresh && match.live.kickoffMatched && /^(?:2H\b|H\.?\s*Time|HT$|Half[ -]?Time)/i.test(match.live.status || '')) {
              const htScore = scoreGoals(match.live.ht || match.ht);
              const currentScore = scoreGoals(match.live.score);
              const sample = htScore && match.secondHalfByHt?.[htScore.join(':')];
              if (sample?.total && sample.goals / sample.total > 0.8 && currentScore && currentScore[0] === htScore[0] && currentScore[1] === htScore[1]) {
                const badge = document.createElement('a');
                badge.className = 'result-h2h-badge';
                badge.textContent = `HT ${htScore.join('–')} · gol 2H ${sample.goals}/${sample.total} (${(100*sample.goals/sample.total).toFixed(1)}%)`;
                badge.title = 'Hasil historis CSV sebelum kickoff, untuk liga dan urutan home–away yang sama. Persentase bukan jaminan gol.';
                badge.href = 'index.php?' + new URLSearchParams({page:'h2h',home:match.home,away:match.away,league:data.historyLeagueName || ''});
                teams.append(badge);
              }
            }
            if ((match.status === 'Selesai' && match.h2hZeroStreakIncludingMatch) || (match.status !== 'Selesai' && match.status !== 'Ditunda' && match.h2hZeroStreak)) {
              const badge = document.createElement('a');
              badge.className = 'result-h2h-badge';
              badge.textContent = match.status === 'Selesai' ? 'STREAK · 3 H2H berturut-turut FT 0–0' : 'PANTAU · 3 H2H terakhir FT 0–0';
              badge.title = match.status === 'Selesai' ? 'Pertandingan ini dan dua pertemuan sebelumnya berakhir 0–0 di liga yang sama. Klik untuk melihat riwayat.' : 'Tiga pertemuan terbaru sebelum pertandingan ini berakhir 0–0 di liga yang sama. Klik untuk melihat riwayat.';
              badge.href = 'index.php?' + new URLSearchParams({page:'h2h', home:match.home, away:match.away, league:data.historyLeagueName || ''});
              teams.append(badge);
            }
            for (const signal of noGoalSignals.filter(signal=>noGoalTargets.get(signal) === match)) {
              const badge=document.createElement('span');badge.className='result-h2h-badge';
              badge.textContent=`PANTAU · ${signal.team} tanpa gol ${signal.count}× beruntun ${noGoalScope}`;
              badge.title='Riwayat hasil selesai; bukan jaminan klub akan mencetak gol.';teams.append(badge);
            }
            window.SabarajaLiveResults.appendMarkets(teams, match.live);
            if (match.h2h) {
              for (const market of Object.values(match.h2h.markets || {})) {
              const {period,side,line,wins,total} = market;
              if (period === 'FT' && side === 'U' && [3.5, 4.5, 5.5].includes(Number(line))) continue;
              if (match.status === 'Selesai' || match.status === 'Ditunda') continue;
              const isFtOver05 = period === 'FT' && side === 'O' && Number(line) === 0.5;
              const isFtOver15 = period === 'FT' && side === 'O' && Number(line) === 1.5;
              if (!isFtOver05 && !isFtOver15 && match.h2h.total < 20) continue;
              const recentMeetings = match.h2h.recentMeetings || [];
              const zeroRecord = match.h2h.zeroStreakRecord || 0;
              const zeroCurrent = match.h2h.zeroStreakCurrent || 0;
              const under15Record = match.h2h.under15StreakRecord || 0;
              const under15Current = match.h2h.under15StreakCurrent || 0;
              if (isFtOver05 ? !zeroRecord || zeroCurrent < zeroRecord : isFtOver15 ? !under15Record || under15Current < under15Record : total < 20 || wins/total < 0.95) continue;
              const phase = match.live?.status || '';
              const firstHalf = /^1H\b/i.test(phase);
              const secondHalf = /^(?:2H\b|H\.?\s*Time|HT$|Half[ -]?Time)/i.test(phase);
              const currentGoals = scoreGoals(match.live?.score || match.ft || match.ht);
              const ht = scoreGoals(match.live?.ht || match.ht);
              if (period === 'HT' && (secondHalf || (!firstHalf && ht))) continue;
              if (period === '2H' && (!secondHalf || !ht || !currentGoals)) continue;
              const goals = currentGoals ? currentGoals[0]+currentGoals[1] - (period === '2H' ? ht[0]+ht[1] : 0) : null;
              if (goals !== null && (goals < 0 || goals > line)) continue;
              const h2hDetail = document.createElement('details'); h2hDetail.className = 'result-h2h-detail';
              const h2hKey = JSON.stringify([data.league,match.time,match.home,match.away,period,side,line]);
              h2hDetail.open = openH2hDetails.has(h2hKey);
              h2hDetail.addEventListener('toggle', () => { if (h2hDetail.open) openH2hDetails.add(h2hKey); else openH2hDetails.delete(h2hKey); });
              const badge = document.createElement('summary'); badge.className = 'result-h2h-badge';
              const perfect = wins === total;
              if (perfect) badge.classList.add('is-perfect-history');
              const formatH2hDate = value => value.split('-').reverse().join('/');
              const dataPeriod = market.firstDate && market.lastDate ? `${formatH2hDate(market.firstDate)}–${formatH2hDate(market.lastDate)}` : 'tanggal tidak tersedia';
              const undated = market.undated || 0;
              badge.textContent = `H2H ${period} ${side}${line} · ${(100*wins/total).toFixed(1)}% (${wins}/${total}) · CSV ${dataPeriod}${undated ? ` · ${undated} tanpa tanggal` : ''}`;
              if (perfect) badge.textContent += ' · sampel, bukan jaminan';
              if (isFtOver05) badge.textContent += ` · ${zeroCurrent} H2H terakhir FT 0–0 · rekor ${zeroRecord}×`;
              if (isFtOver15) badge.textContent += ` · ${under15Current} H2H terakhir Under 1.5 · rekor ${under15Record}×`;
              badge.title = `H2H ${match.home} vs ${match.away}: ${wins} dari ${total} pertemuan memenuhi ${period} ${side}${line}. Tanggal dalam sampel CSV: ${dataPeriod} UTC+8${undated ? `; ${undated} pertemuan tanpa tanggal` : ''}. Per kompetisi, kandang–tandang digabung. CSV saat ini dapat memuat hasil setelah pertandingan yang ditampilkan. ${isFtOver05 ? `Syarat: H2H terbaru berturut-turut FT 0–0 mencapai rekor ${zeroRecord}× di CSV sebelum kickoff.` : isFtOver15 ? `Syarat: H2H terbaru berturut-turut FT Under 1.5 mencapai rekor ${under15Record}× di CSV sebelum kickoff.` : 'Minimal 20 pertemuan dan ≥95%.'} Data yang tersedia mungkin tidak lengkap. O = Over, U = Under. Hasil historis bukan jaminan dan bukan backtest sebelum kickoff.`;
              if (isFtOver05 || isFtOver15) badge.title += ' H2H terbaru sebelum kickoff: ' + recentMeetings.map(meeting => `${meeting.time} FT ${meeting.ft}`).join('; ') + '.';
              h2hDetail.append(badge);
              const detailBody = document.createElement('div'); detailBody.className = 'result-h2h-detail-body';
              const explanation = document.createElement('p');
              explanation.textContent = `${period} ${side}${line}: ${(100*wins/total).toFixed(1)}% · ${wins} dari ${total} hasil dalam CSV · ${dataPeriod} UTC+8${undated ? ` · ${undated} tanpa tanggal` : ''}. Sampel dapat mencakup hasil setelah kickoff pertandingan ini.`;
              detailBody.append(explanation);
              const criterion = document.createElement('p');
              criterion.textContent = isFtOver05 ? `Sinyal: ${zeroCurrent} H2H terakhir FT 0–0 mencapai rekor CSV ${zeroRecord}× sebelum kickoff.` : isFtOver15 ? `Sinyal: ${under15Current} H2H terakhir FT Under 1.5 mencapai rekor CSV ${under15Record}× sebelum kickoff.` : 'Sinyal: setidaknya 20 pertemuan dan 95% memenuhi market.';
              detailBody.append(criterion);
              const heading = document.createElement('strong'); heading.textContent = 'Hingga 5 pertemuan terakhir sebelum kickoff (UTC+8)'; detailBody.append(heading);
              const list = document.createElement('ul');
              for (const meeting of recentMeetings) {
                const item = document.createElement('li');
                const when = meeting.time ? meeting.time.slice(0,10).split('-').reverse().join('/')+' '+meeting.time.slice(11,16) : 'Tanggal tidak tersedia';
                item.textContent = `${when} · ${meeting.home || 'Tim kandang'} vs ${meeting.away || 'Tim tandang'} · FT ${meeting.ft}`;
                list.append(item);
              }
              if (!recentMeetings.length) { const item=document.createElement('li'); item.textContent='Tidak ada pertemuan bertanggal sebelum kickoff dalam CSV.'; list.append(item); }
              detailBody.append(list); h2hDetail.append(detailBody); teams.append(h2hDetail);
              }
            }
            const teamAlerts = resultAlerts.get(match.sourceMatch || match)?.filter(alert => !data.apiOnly || alert.kind === 'zerozero');
            if (teamAlerts?.length) {
              const badges = document.createElement('div');
              badges.className = 'result-history-alerts';
              for (const alert of teamAlerts) {
                const badge = document.createElement('span');
                badge.className = 'result-history-alert alert-' + alert.kind + (alert.highFrequency ? ' is-high-frequency' : '');
                badge.textContent = alert.label;
                badge.title = alert.detail;
                badge.setAttribute('aria-label', alert.detail);
                badges.append(badge);
              }
              teams.append(badges);
            }
            if (match.live && !match.live.kickoffMatched) {
              const hint = document.createElement('small'); hint.className = 'result-live-hint';
              hint.textContent = 'Kickoff belum cocok dengan API'; teams.append(hint);
            }
            const firstHalf = /^1H\b/i.test(match.live?.status || '');
            row.insertCell().textContent = match.live ? (firstHalf ? '—' : match.live.ht || match.ht || '—') : match.ht || '—';
            const score = row.insertCell(); score.textContent = match.live?.score || match.ft || '—';
            if (match.live) score.title = 'Skor berjalan' + (match.live.fresh ? '' : ' · data lama');
          }
          if (!visibleMatches.length) {
            const cell = tbody.insertRow().insertCell(); cell.colSpan = 4; cell.textContent = query || selectedPhase !== 'all' || selectedMarket !== 'all' || selectedRecord !== 'all' ? 'Tidak ada pertandingan yang cocok dengan filter.' : apiHealth === 'error' ? 'Tidak ada pertandingan dalam data tersimpan. Pembaruan API gagal; data mungkin belum lengkap.' : data.snapshotFilesAvailable < data.snapshotFilesExpected ? 'Data tersimpan belum lengkap; pertandingan belum dapat dipastikan.' : 'Tidak ada pertandingan pada data yang tersedia untuk rentang ini.';
          }
          for (const part of [period,info,zeroZeroCount,scroll]) part.hidden = collapsedLeagues.has(data.league);
          recordGroup.hidden = collapsedLeagues.has(data.league) || !zeroRecordSignals.length;
          scroll.append(table); card.append(heading, period, info, recordGroup, zeroZeroCount, scroll);
          ((data.extraCard ?? data.apiOnly) && extraBody ? extraCards : mainCards).append(card);
          restoredScrolls.push([scroll, positions[index] || 0]);
        }
        body.replaceChildren(mainCards);
        extraBody?.replaceChildren(extraCards);
        for (const [el, top] of restoredScrolls) el.scrollTop = top;
        for (const [el, top, left] of pagePositions) { el.scrollTop = top; el.scrollLeft = left; }
        window.scrollTo({left: viewportPosition[0], top: viewportPosition[1], behavior: 'instant'});
        rendered = signature;
        document.dispatchEvent(new CustomEvent('pattern-results-rendered'));
      }
  }
  document.addEventListener('pattern-live-analysis', event => {
    liveState = event.detail.payload; liveAnalysis = event.detail.analysis;
    if (liveState?.timestamp) {
      const raw = String(liveState.timestamp);
      const parsed = new Date(/[zZ]$|[+-]\d\d:\d\d$/.test(raw) ? raw : raw + '+07:00');
      if (!Number.isNaN(parsed.getTime())) lastLiveTimestamp = parsed;
    }
    const liveWarning = document.getElementById('pattern-live-warning');
    liveWarning.hidden = !liveState || (liveState.online && liveState.fresh);
    if (!liveWarning.hidden) {
      document.getElementById('pattern-live-warning-title').textContent = liveState.online ? 'Data live lama' : 'Live terputus';
      document.getElementById('pattern-live-warning-detail').textContent = lastLiveTimestamp
        ? 'Data live terakhir diperbarui ' + lastLiveTimestamp.toLocaleString('id-ID',{timeZone:'Asia/Jakarta',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}) + ' WIB. Hasil API diperbarui terpisah.'
        : 'Waktu pembaruan live terakhir tidak tersedia. Hasil API diperbarui terpisah.';
    }
    const liveNote = document.getElementById('pattern-results-live-note');
    if (liveNote) liveNote.textContent = !liveState ? 'Menghubungkan menit live…' :
      !liveState.online ? 'Sumber live terputus · mencoba kembali setiap 5 detik.' :
      !liveState.fresh ? 'Menit live: data lama · menunggu pembaruan scraper.' :
      'Menit dan skor live otomatis setiap 5 detik · baris hijau sedang berlangsung.';
    const liveBadge = document.getElementById('pattern-results-live-state');
    if (liveBadge) {
      const fresh = liveState?.online && liveState.fresh;
      liveBadge.textContent = !liveState ? 'Live…' : fresh ? 'Live · 5d' : liveState.online ? 'Live · data lama' : 'Live terputus';
      liveBadge.className = 'results-pill live-pill' + (!liveState ? '' : fresh ? ' is-ok' : ' is-error');
      liveBadge.title = liveNote?.textContent || '';
    }
    updateInfo();
    renderResults();
  });
  refresh.addEventListener('click', () => load(true));
  document.getElementById('pattern-live-retry').addEventListener('click', async event => {
    const button = event.currentTarget; button.disabled = true;
    try { await window.SabarajaPatternLive?.retry(); } finally { button.disabled = false; }
  });
  document.querySelector('.results-league-nav').addEventListener('click', event => {
    const button = event.target.closest('button[data-results-league]'); if (!button) return;
    const league = button.dataset.resultsLeague;
    if (league === 'extra') {
      const section = document.querySelector('.results-api-only-section'); section.open = true; section.scrollIntoView({behavior:'smooth',block:'start'}); return;
    }
    if (league === 'vsoccer') {
      const section = document.getElementById('pattern-vsoccer-section'); section.open = true; section.scrollIntoView({behavior:'smooth',block:'start'}); return;
    }
    if (collapsedLeagues.delete(league)) renderResults();
    document.getElementById('results-league-' + league)?.scrollIntoView({behavior:'smooth',block:'start'});
  });
  async function saveResults(automatic = false) {
    const matches = saveMatches.concat(vsoccerSaveDate === date.value ? vsoccerSaveMatches : []);
    if ((!automatic && busy) || saving || !matches.length) return;
    const selectedDate = date.value;
    const fingerprint = JSON.stringify([selectedDate, matches]);
    if (automatic && fingerprint === savedFingerprint) return;
    saving = true; updateSaveButton(); setSaveState('Menyimpan CSV…', 'Menyimpan hasil selesai…', 'loading');
    if (!automatic && saveSummary) { saveSummary.hidden=false; saveSummary.classList.remove('is-error'); saveSummary.textContent='Menyimpan ke matches.csv…'; }
    try {
      const response = await fetch('save_matches_csv.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body:JSON.stringify({matches}), signal:AbortSignal.timeout(45000)
      });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.error || data.message || 'Gagal menyimpan CSV.');
      window.SabarajaSyncHistory?.refresh();
      savedFingerprint = fingerprint;
      if (date.value === selectedDate) {
        if (saveSummary) {
          saveSummary.hidden = false; saveSummary.classList.remove('is-error');
          saveSummary.textContent = `${automatic ? 'Sinkronisasi otomatis' : 'Sinkronisasi selesai'} · ${data.inserted ?? 0} baru · ${data.updated ?? 0} diperbarui · ${data.skipped_duplicate ?? 0} sudah ada dan sama` + (data.skipped_invalid ? ` · ${data.skipped_invalid} tidak valid` : '') + '.';
        }
        setSaveState(data.inserted || data.updated ? `CSV · ${data.inserted} baru / ${data.updated} update` : 'CSV sinkron',
          `${automatic ? 'Sinkron otomatis' : 'Tersimpan'}: ${data.inserted} pertandingan baru, ${data.updated} diperbarui, ${data.skipped_duplicate} sudah ada. Backup CSV dibuat otomatis.`);
        if (data.history_saved === false) setSaveState('CSV tersimpan · riwayat gagal', 'CSV tersimpan, tetapi riwayat tidak dapat dicatat.', 'error');
      }
      try {
        await window.SabarajaPatternEngine?.refreshHistory(true);
        window.SabarajaPatternLive?.refreshView();
      }
      catch (_) { setSaveState('CSV tersimpan · muat ulang pola', saveStatus.title + ' Muat ulang halaman untuk memperbarui statistik pola.', 'error'); }
    } catch (error) {
      if (!automatic && saveSummary && date.value === selectedDate) { saveSummary.classList.add('is-error'); saveSummary.textContent='Gagal menyimpan: '+error.message; }
      if (date.value === selectedDate) setSaveState('CSV gagal disimpan', 'Gagal menyimpan: ' + error.message, 'error');
    } finally {
      saving = false; updateSaveButton();
      if (pendingAutoSave) { pendingAutoSave = false; queueMicrotask(() => saveResults(true)); }
    }
  }
  document.addEventListener('pattern-vsoccer-results', event => {
    if (event.detail.date !== date.value) return;
    vsoccerSaveDate = event.detail.date;
    vsoccerSaveMatches = Array.isArray(event.detail.matches) ? event.detail.matches : [];
    updateSaveButton();
    if (event.detail.update && vsoccerSaveMatches.length) {
      if (saving) pendingAutoSave = true; else saveResults(true);
    }
  });
  save.addEventListener('click', () => saveResults());
  teamSearch.addEventListener('input', renderResults);
  phaseFilter.addEventListener('change', renderResults);
  for (const filter of [leagueFilter,marketFilter,recordFilter]) filter.addEventListener('change', renderResults);
  date.addEventListener('change', () => { resultSnapshots = []; rendered = ''; renderResults(); saveMatches = []; vsoccerSaveMatches = []; vsoccerSaveDate = null; setSaveState(''); updateSaveButton(); load(true); });
  document.addEventListener('visibilitychange', () => {
    clearTimeout(timer);
    if (!document.hidden) load(true);
  });
  window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); });
  window.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; load(true); } });
  load().then(() => load(true));
})();
