let currentViewFilter = 'all';
let autoTimer = null;
let lastGroups = [];

const TARGET_HOST = '1x2aaa.com';

// ---------------------------------------------------------------------------
// EXTRACTOR — runs INSIDE the page (via chrome.scripting.executeScript).
// Must be fully self-contained: no closures over popup scope.
// ---------------------------------------------------------------------------
function extractMatchesFromPage() {
    const text = (el) => (el ? el.textContent.trim() : '');

    const parseVerticalCell = (cell) => {
        // Returns { ou: [{line, odd}], x2: [home, draw, away] }
        const result = { ou: [], x2: [] };
        if (!cell) return result;

        // O/U + Handicap markets: each has a left line label + an odd.
        cell.querySelectorAll('.eventlist_asia_fe_sharedGrid_singleMarket').forEach((m) => {
            const lineEl = m.querySelector('.eventlist_asia_fe_sharedGrid_singleLeftLive, .eventlist_asia_fe_sharedGrid_singleLeft');
            const oddEl = m.querySelector('.eventlist_asia_fe_OddsArrow_oddsArrowNumber');
            if (lineEl && oddEl) {
                result.ou.push({ line: lineEl.textContent.trim(), odd: oddEl.textContent.trim() });
            } else if (!lineEl && oddEl) {
                // 1X2 style: bare odds with no line label.
                result.x2.push(oddEl.textContent.trim());
            }
        });
        return result;
    };

    const groups = [];
    document.querySelectorAll('.eventlist_asia_fe_EventListLeague_container').forEach((leagueEl) => {
        const league = text(leagueEl.querySelector('.eventlist_asia_fe_EventListLeague_leagueName span'))
            || 'Unknown League';
        const matches = [];

        leagueEl.querySelectorAll('.eventlist_asia_fe_EventListLeague_singleEvent').forEach((ev) => {
            const teamNames = Array.from(ev.querySelectorAll('.eventlist_asia_fe_EventCard_teamNameText'))
                .map((n) => n.textContent.trim())
                .filter((n) => n && n.toLowerCase() !== 'draw');

            const score = text(ev.querySelector('.eventlist_asia_fe_EventTime_scoreLive'));
            const half = text(ev.querySelector('.eventlist_asia_fe_EventTime_gamePart'));
            const minEl = ev.querySelector('.eventlist_asia_fe_EventTime_gameProgress span:last-child');
            const minute = minEl ? minEl.textContent.trim() : '';
            const status = [half, minute].filter(Boolean).join(' ');

            const cells = ev.querySelectorAll('.eventlist_asia_fe_sharedGrid_verticalCellWrapper');
            const ft = parseVerticalCell(cells[0]);
            const fh = parseVerticalCell(cells[1]);

            const suspended = ev.querySelector('.eventlist_asia_fe_sharedGrid_suspendedWrapper')
                && ft.ou.length === 0 && ft.x2.length === 0;

            matches.push({
                homeTeam: teamNames[0] || '-',
                awayTeam: teamNames[1] || '-',
                score: score || '-',
                status: status || '-',
                ft,
                fh,
                suspended: Boolean(suspended)
            });
        });

        if (matches.length) groups.push({ league, matches });
    });

    return { ok: true, groups, time: new Date().toLocaleTimeString() };
}

// ---------------------------------------------------------------------------
// POPUP side
// ---------------------------------------------------------------------------
function showError(msg) {
    document.getElementById('errorBox').innerHTML = msg ? `<div class="error-box">${msg}</div>` : '';
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

function isSecondHalf(m) {
    return /^2H/i.test(String(m.status || ''));
}

// Scalp candidate: live 2H match with an active O/U line (good for live
// over/under scalping). Suspended or pre-pick markets are skipped.
function isScalpCandidate(m) {
    return isSecondHalf(m) && !m.suspended && Array.isArray(m.ft.ou) && m.ft.ou.length > 0;
}

function passesFilter(m) {
    if (currentViewFilter === '2h') return isSecondHalf(m);
    if (currentViewFilter === 'scalp') return isScalpCandidate(m);
    return true;
}

// Render O/U pairs. Convention from the feed: first row = Over line, second = "U".
function renderOu(ou) {
    if (!Array.isArray(ou) || !ou.length) return '<span class="suspended">-</span>';
    const over = ou[0];
    const under = ou[1];
    const parts = [];
    if (over) parts.push(`<span class="ou-line">O ${escapeHtml(over.line)}</span> @ <span class="odds-over">${escapeHtml(over.odd)}</span>`);
    if (under) parts.push(`<span class="odds-under">U @ ${escapeHtml(under.odd)}</span>`);
    return parts.join('<br>');
}

function render1x2(x2) {
    if (!Array.isArray(x2) || x2.length < 3) return '<span class="suspended">-</span>';
    return `<span class="odds-x2">1 ${escapeHtml(x2[0])} | X ${escapeHtml(x2[1])} | 2 ${escapeHtml(x2[2])}</span>`;
}

function renderTable(groups) {
    const container = document.getElementById('matchesTable');

    const filtered = groups
        .map((g) => ({ league: g.league, matches: g.matches.filter(passesFilter) }))
        .filter((g) => g.matches.length);

    const total = filtered.reduce((acc, g) => acc + g.matches.length, 0);
    document.getElementById('matchCount').textContent = `${total} matches`;

    if (!filtered.length) {
        const msg = currentViewFilter === 'scalp'
            ? 'Belum ada kandidat scalping (2H dengan O/U aktif).'
            : currentViewFilter === '2h'
                ? 'Belum ada match babak kedua.'
                : 'No matches found.';
        container.innerHTML = `<div style="text-align:center;padding:40px;background:white;">${msg}</div>`;
        return;
    }

    container.innerHTML = filtered.map(({ league, matches }) => {
        const rows = matches.map((m) => {
            const timeClass = /H\.?Time|HT/i.test(m.status) ? 'time-ht' : 'time-live';
            const scalp = isScalpCandidate(m) ? '<span class="scalp-badge">SCALP</span>' : '';
            return `<tr>
                <td class="team-name">${escapeHtml(m.homeTeam)}</td>
                <td class="team-name">${escapeHtml(m.awayTeam)}${scalp}</td>
                <td><span class="${timeClass}">${escapeHtml(m.status)}</span></td>
                <td class="score">${escapeHtml(m.score)}</td>
                <td>${m.suspended ? '<span class="suspended">suspended</span>' : renderOu(m.ft.ou)}</td>
                <td>${renderOu(m.fh.ou)}</td>
                <td>${render1x2(m.ft.x2.length ? m.ft.x2 : m.fh.x2)}</td>
            </tr>`;
        }).join('');

        return `<div class="league-group">
            <div class="league-title">${escapeHtml(league)}</div>
            <table>
                <thead>
                    <tr>
                        <th style="width:16%;">Home</th>
                        <th style="width:16%;">Away</th>
                        <th style="width:9%;">Time</th>
                        <th style="width:7%;">Score</th>
                        <th style="width:18%;">FT O/U</th>
                        <th style="width:16%;">1H O/U</th>
                        <th style="width:18%;">1X2</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
    }).join('');
}

async function getActiveTab() {
    const tabs = await chrome.tabs.query({ active: true, currentWindow: true });
    return tabs[0] || null;
}

async function refreshData() {
    showError('');
    const tab = await getActiveTab();
    if (!tab || !tab.url || !tab.url.includes(TARGET_HOST)) {
        document.getElementById('pageStatus').textContent = 'X Bukan halaman 1x2aaa.com';
        document.getElementById('pageStatus').style.color = '#dc3545';
        showError('Buka tab Virtual Soccer di *.1x2aaa.com lalu coba lagi.');
        return;
    }

    try {
        const [res] = await chrome.scripting.executeScript({
            target: { tabId: tab.id },
            func: extractMatchesFromPage
        });
        const data = res?.result;
        if (!data?.ok) {
            showError('Gagal mengambil data dari halaman.');
            return;
        }
        lastGroups = data.groups || [];
        renderTable(lastGroups);
        document.getElementById('pageStatus').textContent = 'OK On target page';
        document.getElementById('pageStatus').style.color = '#28a745';
        document.getElementById('lastUpdate').textContent = `Last update: ${data.time}`;
    } catch (e) {
        showError(e.message || 'Extract error');
    }
}

function updateAutoUI(on) {
    const ind = document.getElementById('autoStatus');
    document.getElementById('stopBtn').disabled = !on;
    document.getElementById('autoBtn').disabled = on;
    ind.className = `live-indicator ${on ? 'live-on' : 'live-off'}`;
    ind.textContent = on ? 'AUTO: ON (3s)' : 'AUTO: OFF';
}

function startAuto() {
    if (autoTimer) return;
    refreshData();
    autoTimer = setInterval(refreshData, 3000);
    updateAutoUI(true);
}

function stopAuto() {
    if (autoTimer) clearInterval(autoTimer);
    autoTimer = null;
    updateAutoUI(false);
}

function setFilter(f) {
    currentViewFilter = f;
    ['all', '2h', 'scalp'].forEach((k) => {
        const id = { all: 'viewAllBtn', '2h': 'view2HBtn', scalp: 'viewScalpBtn' }[k];
        document.getElementById(id).classList.toggle('active', k === f);
    });
    renderTable(lastGroups);
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('refreshBtn').addEventListener('click', refreshData);
    document.getElementById('autoBtn').addEventListener('click', startAuto);
    document.getElementById('stopBtn').addEventListener('click', stopAuto);
    document.getElementById('viewAllBtn').addEventListener('click', () => setFilter('all'));
    document.getElementById('view2HBtn').addEventListener('click', () => setFilter('2h'));
    document.getElementById('viewScalpBtn').addEventListener('click', () => setFilter('scalp'));
    refreshData();
});
