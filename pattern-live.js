(() => {
  'use strict';
  let payload = null, fetching = false, timer = null;
  const htLocks = new Map();
  function stabilizeHt(data, now = Date.now()) {
    for (const [key,value] of htLocks) if (now-value.seenAt > 30*60000) htLocks.delete(key);
    if (!data.online || !data.fresh) return data;
    data.matches = (data.matches || []).map(match => {
      const key = [match.league || '16',match.home,match.away].join('|').toLowerCase();
      const firstHalf = /^1H\s+\d+/i.test(match.status || '');
      const afterFirstHalf = /^(?:2H\s+\d+|H\.?\s*Time|HT$|Half[ -]?Time)/i.test(match.status || '');
      if (firstHalf) { htLocks.delete(key); return { ...match, ht:null }; }
      if (!afterFirstHalf) return match;
      const locked = htLocks.get(key);
      if (locked) { locked.seenAt = now; return { ...match, ht:locked.score }; }
      if (/^\d+\s*[-:–]\s*\d+$/.test(String(match.ht ?? ''))) htLocks.set(key,{score:match.ht,seenAt:now});
      return match;
    });
    return data;
  }
  const node = (tag, text, className) => {
    const el = document.createElement(tag);
    if (text !== undefined) el.textContent = text;
    if (className) el.className = className;
    return el;
  };
  function refreshView() {
    const league16 = payload ? {...payload, matches:payload.matches.filter(match => (match.league || '16') === '16')} : null;
    const analysis = league16 && window.SabarajaPatternEngine ? window.SabarajaPatternEngine.evaluateLive(league16) : null;
    const activeIds = new Set(analysis?.activeRuleIds || []);
    const afterL1Ids = new Set((analysis?.matches || []).flatMap(match =>
      (match.moments || []).filter(moment => moment.id.startsWith('L16-L1:')).map(moment => moment.id.slice('L16-L1:'.length))));
    for (const row of document.querySelectorAll('.pattern-rules-table tr[data-pattern-id]')) {
      const active = activeIds.has(row.dataset.patternId);
      row.classList.toggle('pattern-live-active', active);
      row.querySelector('.pattern-live-marker')?.remove();
      row.querySelector('.after-l1-badge')?.remove();
      if (active) row.cells[1].append(node('span', 'SINYAL LIVE', 'pattern-live-marker'));
      if (active && afterL1Ids.has(row.dataset.patternId)) row.cells[1].append(node('span','AFTER L1 🔥','after-l1-badge'));
    }
    document.dispatchEvent(new CustomEvent('pattern-live-analysis', {detail:{payload,analysis}}));
  }
  async function poll() {
    if (fetching || document.hidden) return;
    if (!document.getElementById('pattern-results-host')) {
      clearTimeout(timer); timer = setTimeout(poll, 5000);
      return;
    }
    fetching = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 6000);
    try {
      const response = await fetch('pattern-live-data.php', { cache: 'no-store', signal: controller.signal });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      payload = stabilizeHt(await response.json());
      payload.fetchedAt = Date.now();
      try { await window.SabarajaPatternEngine?.refreshHistory(); }
      catch (error) { console.warn('Pembaruan riwayat CSV:', error.message); }
    } catch (_) {
      payload = { ...payload, online: false, fresh: false, matches: payload?.matches || [], message: 'Tidak dapat menghubungi sumber live-scraper. Mencoba kembali otomatis.' };
    } finally {
      clearTimeout(timeout); fetching = false; refreshView();
      clearTimeout(timer); timer = setTimeout(poll, 5000);
    }
  }
  window.SabarajaPatternLive = { refreshView, retry: () => { clearTimeout(timer); return poll(); } };
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { clearTimeout(timer); poll(); } });
  window.addEventListener('pagehide', () => clearTimeout(timer));
  refreshView(); poll();
})();
