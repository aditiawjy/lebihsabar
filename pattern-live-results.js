(() => {
  'use strict';
  const teamKey = value => String(value || '').replace(/\s*\(V\)/gi, '').normalize('NFKC').toLowerCase().replace(/\s+/g, ' ').trim();
  const calendarDay = now => new Intl.DateTimeFormat('en-CA', {timeZone:'Asia/Singapore',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(now));
  function merge(snapshot, payload, analysis, now = Date.now()) {
    const rows = snapshot.matches.map(match => ({...match, sourceMatch:match}));
    const elapsedAge = payload?.fetchedAt ? Math.max(0, (now-payload.fetchedAt)/1000) : 0;
    const age = payload?.age == null ? Infinity : payload.age + elapsedAge;
    const fresh = !!(payload?.online && payload.fresh && age <= 30);
    const inPeriod = snapshot.league === '16' && snapshot.periodStart && snapshot.periodEnd
      ? now >= Date.parse(snapshot.periodStart) && now < Date.parse(snapshot.periodEnd) + 16*60000
      : snapshot.date === calendarDay(now);
    if (!snapshot.apiOnly && inPeriod && age <= 300) {
      const league16 = (payload.matches || []).filter(match => String(match.league || '16') === '16');
      const used = new Set();
      for (const match of payload.matches || []) {
        if (String(match.league || '16') !== String(snapshot.league)) continue;
        const minute = String(match.status || '').match(/^([12])H\s+(\d+)/i);
        const halftime = /^(?:H\.?\s*Time|HT$|Half[ -]?Time)/i.test(match.status || '');
        if (!minute && !halftime) continue;
        const duration = Number(snapshot.league);
        const played = halftime ? duration/2 : Number(minute[2]) + (minute[1] === '2' ? duration/2 : 0);
        const recent = rows.filter(row => !used.has(row) && !row.live && row.status === 'Belum selesai' &&
          teamKey(row.home) === teamKey(match.home) && teamKey(row.away) === teamKey(match.away) &&
          Date.parse(row.time) <= now+60000 && now-Date.parse(row.time) <= (duration+15)*60000);
        // Feed clocks can lag the schedule. A unique unfinished fixture in this
        // bounded window is sufficient; use the clock only to resolve repeats.
        const candidates = recent.length === 1 ? recent : recent.filter(row =>
          Math.abs((now-Date.parse(row.time))/60000-played) <= (halftime ? 4 : 3));
        // Ambiguous or absent kickoff stays explicitly unknown instead of using another fixture.
        const row = candidates.length === 1 ? candidates[0] : {time:null, home:match.home.replace(/\s*\(V\)/gi,''), away:match.away.replace(/\s*\(V\)/gi,''), status:'Belum selesai', ht:null, ft:null};
        if (candidates.length !== 1) rows.push(row);
        used.add(row);
        const evaluation = fresh && String(snapshot.league) === '16'
          ? analysis?.matches?.find(result => result.index === league16.indexOf(match)) : null;
        row.live = {status:match.status, score:match.score, ht:match.ht, fresh, markets:match.markets || [],
          signals:evaluation?.signals || [], evaluationReady:!!analysis?.ready,
          phase:evaluation?.phase || null, htAvailable:!!evaluation?.htAvailable,
          kickoffMatched:candidates.length === 1};
      }
    }
    const rank = row => row.live ? 0 : row.status === 'Belum selesai' ? 1 : 2;
    return rows.sort((a,b) => rank(a)-rank(b) || (rank(a) === 1 ? Date.parse(a.time)-Date.parse(b.time) : Date.parse(b.time || 0)-Date.parse(a.time || 0)));
  }
  function appendMarkets(container, live) {
    if (!live) return;
    const wrap = document.createElement('div'); wrap.className = 'live-ou-markets';
    for (const market of live.markets || []) {
      const badge = document.createElement('span'); badge.className = 'live-ou-market';
      const label = document.createElement('strong'); label.textContent = market.period + ' O/U';
      badge.append(label, document.createTextNode(' '));
      const prices = market.locked ? 'Terkunci' : market.selections.length
        ? market.selections.map(selection => `${selection.side} ${selection.line} @${selection.price}`).join(' · ') : 'Belum tersedia';
      badge.append(document.createTextNode(prices + (live.fresh ? '' : ' · data lama')));
      badge.title = (market.period === 'FT' ? 'Total gol pertandingan penuh' : market.period === '1H' ? 'Total gol babak pertama' : 'Total gol babak kedua') + ' · O = Over, U = Under · @ = odds dari sumber live';
      if (market.locked || !live.fresh) badge.classList.add('live-ou-unavailable');
      wrap.append(badge);
    }
    if (!wrap.childNodes.length) {
      const missing = document.createElement('span'); missing.className = 'live-ou-missing';
      missing.textContent = 'O/U belum tersedia'; wrap.append(missing);
    }
    container.append(wrap);
  }
  window.SabarajaLiveResults = {merge,appendMarkets};
})();
