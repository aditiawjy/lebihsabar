(() => {
  'use strict';
  const formatter = new Intl.DateTimeFormat('id-ID', {timeZone:'Asia/Singapore',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'});
  const cleanTeam = value => String(value || '').replace(/\s*(?:\(V\)|\[V\])/gi, '').replace(/\s+/g, ' ').trim().toLowerCase();
  function repeatedFt(meetings, home) {
    const recent=meetings.slice(0,2);
    if(recent.length!==2)return null;
    const scores=recent.map(meeting=>{
      if(meeting.totalGoals===null)return null;
      const score=String(meeting.ft || '').match(/^(\d+)\s*[-–:]\s*(\d+)$/);
      if(!score)return null;
      const reverse=home && cleanTeam(home)!==cleanTeam(meeting.home);
      return Number(score[reverse?2:1])+'–'+Number(score[reverse?1:2]);
    });
    return scores[0]!==null && scores[0]===scores[1]?scores[0]:null;
  }
  window.SabarajaRecentMeetings = {
    repeatedFt,
    append(target, meetings, ht = null, byHt = null, groups = {}, home = null, htRun = null) {
      const box = document.createElement('div'); box.className = 'result-recent-meetings';
      const heading = document.createElement('strong'); heading.textContent = byHt ? 'Rangkaian rekor HT ' + ht.replace(':', '–') + ' · ' + meetings.length + '× berturut-turut' : '2 pertemuan terakhir'; box.append(heading);
      box.title = 'Pertemuan di liga yang sama dengan posisi home–away yang sama sebelum kickoff, terbaru lebih dulu. Waktu UTC+8; skor mengikuti urutan tim pada judul pertandingan.';
      const recent = byHt ? meetings : meetings.slice(0, 2);
      const sameFt=!byHt?repeatedFt(meetings,home):null;
      if (!byHt) {
        target.closest('tr')?.classList.toggle('result-repeat-ht', false);
        target.closest('tr')?.classList.toggle('result-repeat-ft', !!sameFt);
        if(sameFt) {
          const badge=document.createElement('strong');badge.className='result-h2h-badge result-repeat-ft-badge';
          badge.textContent='2 H2H terakhir FT sama · '+sameFt;
          badge.title='Dua pertemuan terakhir sebelum kickoff di liga yang sama memiliki skor FT persis sama setelah disesuaikan ke urutan tim pertandingan ini. Catatan hasil, bukan prediksi.';
          target.append(badge);
          box.title=badge.title+' Waktu riwayat UTC+8.';
        }
      }
      if (byHt) {
        const current = String(ht || '').match(/^(\d+)\s*[-–:]\s*(\d+)$/);
        const clean = value => String(value || '').replace(/\s*(?:\(V\)|\[V\])/gi, '').replace(/\s+/g, ' ').trim().toLowerCase();
        const scores = (groups.highlightMeetings || []).slice(0, 2).map(meeting => {
          const ft = String(meeting.ft || '').match(/^(\d+)\s*[-–:]\s*(\d+)$/);
          if (!ft || !Number.isInteger(meeting.htHome) || !Number.isInteger(meeting.htAway)) return null;
          const reverse = home && clean(home) !== clean(meeting.home);
          return {htHome:reverse ? meeting.htAway : meeting.htHome, htAway:reverse ? meeting.htHome : meeting.htAway,
            ftHome:Number(ft[reverse ? 2 : 1]), ftAway:Number(ft[reverse ? 1 : 2])};
        });
        const repeated = !!current && scores.length === 2 && scores.every(score => score &&
          score.htHome === Number(current[1]) && score.htAway === Number(current[2])) &&
          scores[0].ftHome === scores[1].ftHome && scores[0].ftAway === scores[1].ftAway;
        target.closest('tr')?.classList.toggle('result-repeat-ht', repeated);
        if (repeated) {
          box.classList.add('result-recent-same-ht');
          box.title += ' Dua pertemuan terakhir dengan HT ini memiliki FT sama.';
          box.title += ' Kedua riwayat dengan HT ini memiliki skor HT dan FT identik; HT pertandingan saat ini juga sama.';
        }
      }

      if (!recent.length) {
        const empty = document.createElement('span'); empty.textContent = byHt ? 'Belum ada pertemuan dengan HT ini.' : 'Belum ada riwayat pertemuan.'; box.append(empty);
      } else {
        const list = document.createElement('ol');
        for (const meeting of recent) {
          const item = document.createElement('li');
          const time = document.createElement('time'); time.dateTime = meeting.time;
          const parsed = new Date(meeting.time);
          time.textContent = Number.isNaN(parsed.getTime()) ? 'Tanggal tidak tersedia' : formatter.format(parsed);
          const clean = value => String(value || '').replace(/\s*(?:\(V\)|\[V\])/gi, '').trim().toLowerCase();
          const reverse = home && clean(home) !== clean(meeting.home);
          const score = document.createElement('span'); score.className = 'recent-score';
          const ht = Number.isInteger(meeting.htHome) && Number.isInteger(meeting.htAway) ? (reverse ? meeting.htAway + '–' + meeting.htHome : meeting.htHome + '–' + meeting.htAway) : '—';
          const ft = reverse && /^\d+–\d+$/.test(meeting.ft || '') ? meeting.ft.split('–').reverse().join('–') : meeting.ft || '—';
          score.textContent = 'HT ' + ht + ' · FT ' + ft;
          item.append(time, score); list.append(item);
        }
        box.append(list);
        if (recent.length === 1) { const note = document.createElement('span'); note.textContent = 'Baru 1 pertemuan tersedia.'; box.append(note); }
      }
      if (byHt || sameFt) target.append(box);
      if (!byHt && ht) {
        const score = String(ht).match(/^(\d+)\s*[-–:]\s*(\d+)$/);
        if (score) {
          const key = Number(score[1]) + ':' + Number(score[2]);
          const previous = htRun?.score === key && Number.isInteger(htRun.previous) ? htRun.previous : 0;
          const streak = document.createElement('div'); streak.className = 'result-ht-run';
          const record = htRun?.records?.[key] || 0;
          streak.textContent = 'HT ' + key.replace(':', '–') + ' · saat ini ' + (previous + 1) + '× · rekor ' + record + '× berturut-turut';
          streak.title = previous + ' pertemuan terakhir berturut-turut + pertandingan saat ini. Rekor dihitung dari seluruh riwayat H2H di liga ini dengan posisi home–away yang sama sebelum kickoff. Daftar menampilkan satu rangkaian rekor terbaru bila ada rekor yang sama. HT berbeda atau tidak tersedia memutus streak. Skor mengikuti urutan tim pada judul.';



        }
      }
    }
  };
})();
