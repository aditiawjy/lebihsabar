(() => {
  'use strict';
  const formatter = new Intl.DateTimeFormat('id-ID', {timeZone:'Asia/Singapore',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'});
  const cleanTeam = value => String(value || '').replace(/\s*(?:\(V\)|\[V\])/gi, '').replace(/\s+/g, ' ').trim().toLowerCase();
  const number = value => Number(value).toLocaleString('id-ID', {maximumFractionDigits:2});
  const meetingText = (meeting, home) => {
    const parsed = new Date(meeting.time);
    const when = Number.isNaN(parsed.getTime()) ? 'Tanggal tidak tersedia' : formatter.format(parsed);
    const reverse = home && cleanTeam(home) !== cleanTeam(meeting.home);
    const ft = reverse && /^\d+–\d+$/.test(meeting.ft || '') ? meeting.ft.split('–').reverse().join('–') : meeting.ft || '—';
    return `${when} FT ${ft}`;
  };
  window.SabarajaRecentMeetings = {
    append(target, profile, meetings = [], home = null) {
      if (!profile?.n) return;
      const box = document.createElement('div'); box.className = 'result-recent-meetings result-h2h-profile';
      box.textContent = `H2H ${profile.n} laga · rata-rata ${number(profile.avg)} gol · U1.5 ${number(profile.under15)}% · 0–0 ${number(profile.zero)}%`;
      const recent = meetings.slice(0, 2).map(meeting => meetingText(meeting, home));
      box.title = 'Profil pertemuan sebelumnya di liga yang sama (UTC+8). Informasi karakter pasangan tim, bukan prediksi; odds bandar sudah memperhitungkannya.' +
        (recent.length ? ' Terakhir: ' + recent.join('; ') + '.' : '');
      target.append(box);
    }
  };
})();
