// Automatic CSV sync on the authenticated Msports Results page only.
(() => {
    'use strict';
    const LEAGUE = 'SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16 Mins Play';
    let lastSaved = '', busy = false;
    const norm = text => String(text || '').replace(/\s+/g, ' ').trim();
    const score = text => {
        const m = norm(text).match(/^(\d+)\s*[-:]\s*(\d+)$/);
        return m ? [+m[1], +m[2]] : null;
    };
    function dateTime(text) {
        const m = norm(text).match(/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM)?$/i);
        if (!m) return null; // Never invent a date from the local clock.
        let h = +m[2];
        if (m[5]) { if (h < 1 || h > 12) return null; h = h % 12 + (m[5].toUpperCase() === 'PM' ? 12 : 0); }
        if (h > 23 || +m[3] > 59 || +(m[4] || 0) > 59) return null;
        return m[1] + ' ' + String(h).padStart(2,'0') + ':' + m[3] + ':' + (m[4] || '00');
    }
    function collect() {
        const matches = new Map();
        for (const heading of document.querySelectorAll('[class*="style_trLeague"]')) {
            if (norm(heading.querySelector('[class*="style_txt"]')?.textContent).toLowerCase() !== LEAGUE.toLowerCase()) continue;
            for (const table of heading.nextElementSibling?.querySelectorAll('table') || []) {
                const cells = table.querySelectorAll('tbody tr:first-child td');
                if (cells.length !== 3) continue;
                const status = norm(table.querySelector('[class*="style_status"]')?.textContent);
                if (!/^(?:COMPLETED|FT|FULL[ -]?TIME)$/i.test(status)) continue;
                const teams = norm(cells[0].textContent).split(/\s+v(?:s\.?)?\s+/i);
                const time = dateTime(table.querySelector('[class*="style_date"]')?.textContent);
                const ht = score(cells[1].textContent), ft = score(cells[2].textContent);
                if (teams.length !== 2 || !teams.every(Boolean) || !time || !ht || !ft || ft.some((g,i)=>g<ht[i])) continue;
                const row = { match_time:time,home_team:teams[0],away_team:teams[1],league:LEAGUE,
                    fh_home:ht[0],fh_away:ht[1],ft_home:ft[0],ft_away:ft[1] };
                matches.set([time,...teams].join('|'),row);
            }
        }
        return [...matches.values()].sort((a,b)=>a.match_time.localeCompare(b.match_time));
    }
    async function sync() {
        if (busy) return;
        const matches = collect();
        if (!matches.length) return;
        const fingerprint = JSON.stringify(matches);
        if (fingerprint === lastSaved) return;
        busy = true;
        try {
            const reply = await chrome.runtime.sendMessage({action:'syncLeague16Results',matches});
            if (!reply?.success) throw new Error(reply?.error || 'CSV sync failed');
            lastSaved = fingerprint;
            console.info('League 16 Results → lebihsabar CSV:', reply.result.message);
        } catch (error) { console.warn('League 16 CSV sync:',error.message); }
        finally { busy = false; }
    }
    setInterval(sync,15000);
    setInterval(() => {
        for (const heading of document.querySelectorAll('[class*="style_trLeague"]')) {
            if (norm(heading.querySelector('[class*="style_txt"]')?.textContent).toLowerCase() !== LEAGUE.toLowerCase()) continue;
            heading.querySelector('svg[class*="style_reloadIcon"]')?.dispatchEvent(new MouseEvent('click',{bubbles:true}));
        }
    },60000);
    sync();
})();
