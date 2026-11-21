(() => {
  'use strict';
  const host = document.getElementById('pattern-results-host');
  if (!host) return;
  const date = document.getElementById('pattern-results-date');
  const teamSearch = document.getElementById('pattern-results-team-search');
  const phaseFilter = document.getElementById('pattern-results-phase');
  const leagueFilter = document.getElementById('pattern-results-league-filter');
  const marketFilter = document.getElementById('pattern-results-market-filter');
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
  let busy = false, stopped = false, rendered = '';
  let saving = false, saveMatches = [], vsoccerSaveMatches = [], vsoccerSaveDate = null, pendingAutoSave = false;
  let savedFingerprint = '';
  let resultSnapshots = [], liveState = null;
  let apiTimestamp = '', lastLiveTimestamp = null;
  let apiHealth = 'unknown', apiHealthMessage = '', snapshotDate = null;
  const collapsedLeagues = new Set();
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
    if (state === 'error') { const details = panel.closest('details'); if (details) details.open = true; }
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
  function updateSaveButton() {
    const count = saveMatches.length + (vsoccerSaveDate === date.value ? vsoccerSaveMatches.length : 0);
    save.disabled = busy || saving || !count;
    save.textContent = saving ? 'Menyimpan…' : 'Simpan ulang ' + count + ' hasil ke CSV';
  }
  async function load(update = false) {
    if (busy || !date.value || stopped || (update && document.hidden)) return;
    const requestedDate = date.value;
    busy = true; refresh.disabled = true; date.disabled = true;
    updateSaveButton();
    if (update) showApiHealth('loading', 'Menghubungi API. Data tersimpan tetap ditampilkan selama pembaruan.');
    setApiState(update ? 'Mengambil hasil terbaru dari API…' : 'Memuat hasil tersimpan…');
    try {
      const snapshots = [];
      let updateError = null;
      // All leagues share the same UTC+8 snapshot: refresh it only once.
      const fetchLeague = async (league, post) => {
        const url = 'pattern-results-data.php?date=' + encodeURIComponent(requestedDate) + '&league=' + league;
        let response = await fetch(url, {method: post ? 'POST' : 'GET', cache:'no-store', signal:AbortSignal.timeout(90000)});
        let data = await response.json();
        if (!response.ok && post) {
          updateError = data.error || 'Pembaruan API gagal.';
          response = await fetch(url, {cache:'no-store', signal:AbortSignal.timeout(90000)});
          data = await response.json();
        }
        if (!response.ok) throw new Error(data.error || 'Gagal memuat hasil API League ' + league + '.');
        // Restore each league immediately; do not hide every match until all requests finish.
        if (date.value === requestedDate) {
          if (snapshotDate !== requestedDate) resultSnapshots = [];
          const existing = resultSnapshots.findIndex(snapshot => String(snapshot.league) === String(data.league));
          if (existing < 0) resultSnapshots.push(data); else resultSnapshots[existing] = data;
          resultSnapshots.sort((x, y) => resultLeagues.indexOf(String(x.league)) - resultLeagues.indexOf(String(y.league)));
          snapshotDate = requestedDate;
          rendered = ''; renderResults();
        }
        return data;
      };
      snapshots.push(await fetchLeague(resultLeagues[0], update));
      snapshots.push(...await Promise.all(resultLeagues.slice(1).map(league => fetchLeague(league, false))));
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
      apiTimestamp = times.length ? new Date(Math.min(...times)).toLocaleString('id-ID', {timeZone:'Asia/Singapore',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'}) + ' UTC+8 (' + new Date(Math.min(...times)).toLocaleTimeString('id-ID', {timeZone:'Asia/Jakarta',hour:'2-digit',minute:'2-digit'}) + ' WIB)' : '';
      document.getElementById('pattern-results-last-updated').textContent = apiTimestamp ? 'API ' + apiTimestamp : 'API belum tersedia';
      const incomplete = snapshots.some(data => data.snapshotFilesAvailable < data.snapshotFilesExpected);
      if (updateError) {
        await window.SabarajaSyncHistory?.failure('MSports',requestedDate,'fetch',updateError);
        const expired = /401|403|menolak sesi|perbarui sesi login/i.test(updateError);
        reportTelegramHealth('api', expired ? 'denied' : 'error');
        showApiHealth('error', (expired ? 'Sesi ditolak (HTTP 401/403); kemungkinan kedaluwarsa. Perbarui sesi login dengan curl terbaru. ' : updateError + ' ') + (apiTimestamp ? 'Berkas tersimpan terlama: ' + apiTimestamp + '.' : 'Belum ada data tersimpan untuk tanggal ini.') + ' Tekan Perbarui tanggal ini untuk mencoba lagi.');
        setApiState(apiHealthMessage, 'error');
      } else {
        if (update) reportTelegramHealth('api',!times.length || Date.now()-Math.min(...times)>180000?'stale':'ok');
        showApiHealth(update ? 'ok' : 'unknown', (update ? 'Pembaruan berhasil. ' : 'Menampilkan data tersimpan; koneksi API belum diperiksa. ') + (apiTimestamp ? 'Berkas tersimpan terlama: ' + apiTimestamp + '.' : 'Belum ada data tersimpan untuk tanggal ini.') + (incomplete ? ' Sebagian berkas sesi belum tersedia; data bisa belum lengkap.' : ''));
        setApiState(`L15/L20: ${requestedDate}, 00:00–23:59 UTC+8 · L16: ${liveSnapshot?.periodLabel || 'sesi 12:00–00:45 hari berikutnya'} · Pembaruan hasil manual · ${saveMatches.length} hasil selesai siap disimpan` +
        ' · Vietnam V League 1: sesi 12:00–00:45 hari berikutnya UTC+8 · E-Football F24/F26: tanggal kalender UTC+8 · Vietnam dan E-Football: API saja' +
        (times.length ? ' · Pembaruan terlama ' + new Date(Math.min(...times)).toLocaleString('id-ID') : ''), update ? 'ok' : 'cached');
      }
      rendered = ''; renderResults();
      if (update && !updateError) await saveResults(true);
    } catch (error) {
      if (update) await window.SabarajaSyncHistory?.failure('MSports',requestedDate,'fetch',error);
      reportTelegramHealth('api',/401|403|menolak sesi|perbarui sesi login/i.test(error.message || '')?'denied':'error');
      if (snapshotDate !== requestedDate) { resultSnapshots = []; saveMatches = []; }
      showApiHealth('error', (error.message || 'Tidak dapat memuat hasil API.') + (apiTimestamp && snapshotDate === requestedDate ? ' Data tersimpan terakhir: ' + apiTimestamp + '.' : ' Data untuk tanggal ini belum dapat dimuat.') + ' Tekan Perbarui tanggal ini untuk mencoba lagi.');
      rendered = ''; renderResults();
      setApiState((error.message || 'Tidak dapat memuat hasil API.') + ' Tekan Perbarui tanggal ini untuk mencoba lagi.', 'error');
    }
    finally {
      document.dispatchEvent(new CustomEvent('pattern-results-cycle', {detail:{date:requestedDate,update:false}}));
      busy = false; refresh.disabled = false; date.disabled = false; renderResults();
      updateSaveButton();
    }
  }
  function renderResults() {
      const query = teamSearch.value.normalize('NFKC').trim().toLocaleLowerCase('id-ID');
      const selectedPhase = phaseFilter.value;
      const selectedLeague=leagueFilter.value, selectedMarket=marketFilter.value;
      const matchesSearch = (match,data) => {
        if (query && ![match.home,match.away].some(name => String(name || '').normalize('NFKC').toLocaleLowerCase('id-ID').includes(query))) return false;
        if (selectedPhase !== 'all' && matchPhase(match) !== selectedPhase) return false;
        if (selectedMarket !== 'all') {
          const goals = match.status === 'Selesai' ? scoreGoals(match.ft) : null;
          if (!goals) return false;
          const total=goals[0]+goals[1];
          if (!(selectedMarket === 'zero' ? total===0 : selectedMarket === 'under15' ? total<2 : selectedMarket === 'over15' ? total>=2 : selectedMarket === 'under25' ? total<3 : total>=3)) return false;
        }
        return true;
      };
      const placeholders = ['15','16','20'].filter(league => !resultSnapshots.some(data => String(data.league) === league))
        .filter(() => busy || !resultSnapshots.length).map(league => ({league,date:date.value,timezone:'UTC+8',matches:[],loading:busy}));
      const snapshots = [...resultSnapshots, ...placeholders].sort((x, y) => resultLeagues.indexOf(String(x.league)) - resultLeagues.indexOf(String(y.league)))
        .map(data => ({...data, displayMatches:window.SabarajaLiveResults.merge(data,liveState,null)}));
      const signature = JSON.stringify([query, selectedPhase,selectedLeague,selectedMarket, [...collapsedLeagues], snapshots.map(data => [data.league, data.loading, data.date, data.displayMatches])]);
      if (signature !== rendered) {
        const visibleCount = snapshots.filter(data=>selectedLeague==='all'||selectedLeague===data.league).reduce((sum,data) => sum + data.displayMatches.filter(match=>matchesSearch(match,data)).length,0);
        searchStatus.hidden = !query && selectedPhase === 'all' && selectedLeague === 'all' && selectedMarket === 'all';
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
          if (data.loading) {
            const skeleton = document.createElement('div'); skeleton.className = 'results-skeleton'; skeleton.setAttribute('aria-hidden', 'true');
            skeleton.innerHTML = '<div class="sk-summary">' + '<span></span>'.repeat(4) + '</div>' + '<div class="sk-row"><span></span><span></span><span></span><span></span></div>'.repeat(6);
            collapse.hidden = true; card.setAttribute('aria-busy', 'true');
            card.append(heading, skeleton); mainCards.append(card); continue;
          }
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
            window.SabarajaRecentMeetings.append(teams, match.h2h?.profile, match.h2h?.recentMeetings || [], match.home);
            window.SabarajaLiveResults.appendMarkets(teams, match.live);
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
            const cell = tbody.insertRow().insertCell(); cell.colSpan = 4; cell.textContent = query || selectedPhase !== 'all' || selectedMarket !== 'all' ? 'Tidak ada pertandingan yang cocok dengan filter.' : apiHealth === 'error' ? 'Tidak ada pertandingan dalam data tersimpan. Pembaruan API gagal; data mungkin belum lengkap.' : data.snapshotFilesAvailable < data.snapshotFilesExpected ? 'Data tersimpan belum lengkap; pertandingan belum dapat dipastikan.' : 'Tidak ada pertandingan pada data yang tersedia untuk rentang ini.';
          }
          for (const part of [period,info,scroll]) part.hidden = collapsedLeagues.has(data.league);
          scroll.append(table); card.append(heading, period, info, scroll);
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
    liveState = event.detail.payload;
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
  refresh.addEventListener('click', () => { if (!document.getElementById('pattern-all-refresh').disabled) load(true); });
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
          saveSummary.textContent = `${automatic ? 'Sinkronisasi otomatis' : 'Sinkronisasi selesai'} · ${data.inserted ?? 0} baru · ${data.updated ?? 0} diperbarui · ${data.skipped_duplicate ?? 0} sudah ada dan sama` + (data.skipped_invalid ? ` · ${data.skipped_invalid} tidak valid` : '') + '.' + (data.updated ? ` Skor ${data.updated} pertandingan di CSV berbeda dari API dan sudah diganti dengan skor API.` : '');
          saveSummary.classList.toggle('has-updates', Boolean(data.updated));
        }
        setSaveState(data.inserted || data.updated ? `CSV · ${data.inserted} baru / ${data.updated} update` : 'CSV sinkron',
          `${automatic ? 'Sinkron otomatis' : 'Tersimpan'}: ${data.inserted} pertandingan baru, ${data.updated} diperbarui, ${data.skipped_duplicate} sudah ada. Backup CSV dibuat otomatis.`);
        if (data.history_saved === false) setSaveState('CSV tersimpan · riwayat gagal', 'CSV tersimpan, tetapi riwayat tidak dapat dicatat.', 'error');
      }
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
  for (const filter of [leagueFilter,marketFilter]) filter.addEventListener('change', renderResults);
  date.addEventListener('change', () => { resultSnapshots = []; rendered = ''; renderResults(); saveMatches = []; vsoccerSaveMatches = []; vsoccerSaveDate = null; setSaveState(''); updateSaveButton(); load(false); });
  window.addEventListener('pagehide', () => { stopped = true; });
  window.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; load(false); } });
  document.addEventListener('pattern-results-cache-refresh', () => load(false));
  load(false);
})();
