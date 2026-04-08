// Fungsi utama untuk inisialisasi
function initParser() {
    // UPDATE v5.3: Fix Duplikat & Cleanup
    
    // 1. Cleanup elemen lama (cegah duplikat saat reload extension)
    const oldContainer = document.getElementById('sabar-parser-container');
    if (oldContainer) oldContainer.remove();

    // 2. Hanya jalankan di IFRAME
    if (window.self === window.top) return; 

    // 3. Filter Iframe Kecil (misal: tracking pixel / iklan / hidden frames)
    // Hanya tampilkan di iframe yang cukup besar (lebar > 200px)
    if (window.innerWidth < 200 || window.innerHeight < 200) return;

    console.log(`Sabar Msports Parser v5.3: Initializing in IFRAME (${window.innerWidth}x${window.innerHeight})...`);
    
    // Buat container untuk kontrol
    const container = document.createElement('div');
    container.id = 'sabar-parser-container';
    
    // Note: Styling posisi dan tampilan sekarang ditangani oleh styles.css
    
    // Buat tombol Parsing
    const btn = document.createElement('button');
    btn.id = 'sabar-parse-btn';
    btn.innerHTML = '<span>🚀</span> START AUTO-REFRESH'; 
    btn.onclick = toggleAutoRefresh; // Ganti ke fungsi toggle
    
    // Buat area status
    const status = document.createElement('div');
    status.id = 'sabar-parse-status';
    status.innerText = 'Ready v5.8 (DB Sync Fix)';

    // Tambahkan elemen ke container
    container.appendChild(btn);
    container.appendChild(status);
    
    // Jalankan Live Ticker (untuk update detik & countdown)
    startLiveTicker();
    
    // Tambahkan ke body
    document.body.appendChild(container);
}

// Konfigurasi API Endpoint
const API_ENDPOINT = 'http://127.0.0.1/sabaraja/api_msports_sync.php';

// Konfigurasi Filter Default - Menggunakan KEYWORD matching (lebih fleksibel)
// Format: Array of keywords yang HARUS ADA di nama liga (case-insensitive)
const TARGET_LEAGUE_KEYWORDS = [
    ["saba", "club friendly", "virtual", "pes 21"],           // SABA CLUB FRIENDLY Virtual PES 21
    ["saba", "international friendly", "virtual", "pes 21"],  // SABA INTERNATIONAL FRIENDLY Virtual PES 21
    ["saba", "international friendly", "virtual", "fc 24"],   // SABA INTERNATIONAL FRIENDLY Virtual FC 24
    ["saba", "vietnam", "v league", "virtual", "pes 24"]      // SABA VIETNAM V LEAGUE 1 Virtual PES 24
];

// Liga yang harus di-EXCLUDE (tidak boleh masuk meskipun partial match)
const EXCLUDED_LEAGUES = [
    "CLUB FRIENDLY"  // Liga CLUB FRIENDLY biasa (bukan SABA CLUB FRIENDLY Virtual)
];

// Variable untuk menyimpan interval ID
let refreshInterval = null;
let liveTickerInterval = null; // Interval untuk update detik (UI only)
let nextRefreshTime = 0;       // Waktu target refresh berikutnya
let lastParseStats = { leagues: 0, items: 0 }; // Cache hasil terakhir
let lastSyncStats = { inserted: 0, updated: 0 }; // Cache hasil sync terakhir
const REFRESH_RATE = 60000; // 60 detik

// Fungsi Toggle Auto Refresh
function toggleAutoRefresh() {
    const btn = document.getElementById('sabar-parse-btn');
    const status = document.getElementById('sabar-parse-status');

    if (refreshInterval) {
        // STOP
        clearInterval(refreshInterval);
        refreshInterval = null;
        
        btn.innerHTML = '<span>🚀</span> START AUTO-REFRESH';
        btn.style.background = 'linear-gradient(135deg, #8b5cf6, #7c3aed)'; // Reset warna ungu
        
        status.innerText = '⏸️ Auto-Refresh Stopped';
        status.style.color = '#94a3b8';
    } else {
        // START
        startParsing(); // Parse langsung sekali
        
        // Setup Interval Refresh Data (10s)
        refreshInterval = setInterval(startParsing, REFRESH_RATE);
        
        // Set target waktu berikutnya
        nextRefreshTime = Date.now() + REFRESH_RATE;
        
        btn.innerHTML = '<span>⏹️</span> STOP AUTO-REFRESH';
        btn.style.background = 'linear-gradient(135deg, #ef4444, #dc2626)'; // Merah untuk stop
        
        status.innerText = '⏳ Auto-Refresh Active (60s)';
        status.style.color = '#fbbf24';
    }
}

// Fungsi Live Ticker (Berjalan setiap 1 detik untuk efek visual)
function startLiveTicker() {
    if (liveTickerInterval) clearInterval(liveTickerInterval);
    
    liveTickerInterval = setInterval(() => {
        // 1. Cek apakah container masih ada (jika extension di-reload/hapus)
        const container = document.getElementById('sabar-parser-container');
        if (!container) {
            clearInterval(liveTickerInterval);
            return;
        }

        // 2. Update Countdown Status (Jika Auto-Refresh Aktif)
        const status = document.getElementById('sabar-parse-status');
        if (status && refreshInterval && nextRefreshTime > 0) {
            const remaining = Math.ceil((nextRefreshTime - Date.now()) / 1000);
            const seconds = remaining > 0 ? remaining : 0;
            const syncInfo = lastSyncStats.inserted > 0 || lastSyncStats.updated > 0 
                ? ` | 📥${lastSyncStats.inserted} 📝${lastSyncStats.updated}` 
                : '';
            status.innerText = `✅ ${lastParseStats.items} Items${syncInfo} | Next: ${seconds}s`;
        }

        // 3. Update Jam Pertandingan di Modal (Simulasi Detik Berjalan)
        const modal = document.getElementById('sabar-results-modal');
        if (modal) {
            const rows = modal.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const statusBadge = row.querySelector('.sabar-status-badge');
                const timeSpan = row.querySelector('.sabar-time');
                
                // Hanya update jika status Running (bukan HT/Waiting) dan elemen ada
                if (statusBadge && timeSpan && statusBadge.classList.contains('sabar-status-running')) {
                    let timeText = timeSpan.innerText.trim();
                    // Cek format MM:SS (contoh: 12:34)
                    if (/^\d{1,2}:\d{2}$/.test(timeText)) {
                        let [mm, ss] = timeText.split(':').map(Number);
                        ss++;
                        if (ss >= 60) {
                            ss = 0;
                            mm++;
                        }
                        // Update teks (Padding 0 didepan jika < 10)
                        timeSpan.innerText = `${mm.toString().padStart(2, '0')}:${ss.toString().padStart(2, '0')}`;
                    }
                }
            });
        }
    }, 1000);
}

// Fungsi logika parsing (Robust Reverse Lookup + Filtering)
function startParsing() {
    const status = document.getElementById('sabar-parse-status');
    if (!status) return; // Guard clause jika elemen hilang

    status.innerText = '⏳ Scanning...';
    status.style.color = '#fbbf24'; // Yellow

    console.log(`Sabar Parser v5: Starting scan in ${window.location.href}`);

    // 1. Cari SEMUA Match Cards secara global
    const allMatchCards = document.querySelectorAll('div[class*="style_cardMatch"]');
    
    console.log(`Debug: Found ${allMatchCards.length} total match cards in DOM.`);

    if (allMatchCards.length === 0) {
        status.innerText = '❌ No Matches Found';
        status.style.color = '#ef4444';
        
        alert('Tidak ditemukan data match yang sesuai filter di Iframe ini.\n\nCoba cari tombol "PARSE (IFRAME)" di area lain atau iframe lain.');
        return;
    }

    const leaguesMap = new Map();

    allMatchCards.forEach((card, index) => {
        try {
            // --- A. Find League (Reverse Lookup) ---
            let leagueName = 'Unknown League';
            let foundLeague = false;
            let steps = 0;
            const maxSteps = 20; 

            const collapseContainer = card.closest('div[class*="ReactCollapse--collapse"]');
            
            if (collapseContainer) {
                let prev = collapseContainer.previousElementSibling;
                while (prev && steps < maxSteps) {
                    if (prev.className && prev.className.includes('style_trLeague')) {
                        const titleEl = prev.querySelector('div[class*="style_txt"]');
                        if (titleEl) {
                            leagueName = titleEl.innerText.trim();
                            foundLeague = true;
                        }
                        break;
                    }
                    prev = prev.previousElementSibling;
                    steps++;
                }
            } else {
                let prev = card.previousElementSibling;
                while (prev && steps < maxSteps) {
                    if (prev.className && prev.className.includes('style_trLeague')) {
                        const titleEl = prev.querySelector('div[class*="style_txt"]');
                        if (titleEl) {
                            leagueName = titleEl.innerText.trim();
                            foundLeague = true;
                        }
                        break;
                    }
                    prev = prev.previousElementSibling;
                    steps++;
                }
            }

            // --- B. FILTERING LOGIC ---
            const leagueLower = leagueName.toLowerCase();

            // B1. Cek EXCLUDE list dulu (prioritas lebih tinggi)
            // Liga yang PERSIS match dengan exclude list akan diblokir
            const isExcluded = EXCLUDED_LEAGUES.some(excluded => {
                const excludedLower = excluded.toLowerCase();
                // Exact match atau liga dimulai dengan excluded name (tanpa prefix SABA)
                return leagueLower === excludedLower ||
                       (leagueLower.startsWith(excludedLower) && !leagueLower.includes('saba'));
            });

            if (isExcluded) {
                console.log('🚫 EXCLUDED league:', leagueName);
                return; // Skip liga yang di-exclude
            }

            // B2. Cek apakah nama liga mengandung SEMUA keywords dari salah satu target
            // Liga harus mengandung semua keyword dalam satu set untuk dianggap match
            const isTargetLeague = TARGET_LEAGUE_KEYWORDS.some(keywords => {
                // Semua keyword dalam array harus ada di nama liga
                return keywords.every(keyword => leagueLower.includes(keyword.toLowerCase()));
            });

            // Debug: Log liga yang ditemukan untuk troubleshooting
            if (leagueLower.includes('saba') || leagueLower.includes('international') || leagueLower.includes('vietnam')) {
                console.log('🔍 SABA league detected:', leagueName, '| isTarget:', isTargetLeague);
            }

            if (!isTargetLeague) {
                return; // Skip match ini jika bukan liga yang diinginkan
            }

            // --- C. Parse Match Data (Hanya jika lolos filter) ---
            const matchData = {};

            // Time & Status
            const dateEl = card.querySelector('span[class*="style_date"]');
            const timeEl = card.querySelector('span[class*="style_time"], span[class*="style_txtTime"], span[class*="style_clock"]');
            const statusEl = card.querySelector('span[class*="style_status"]');
            const statusText = statusEl ? statusEl.innerText.trim() : '';

            // Ambil date dan time secara terpisah
            const dateText = dateEl ? dateEl.innerText.trim() : '';
            const timeText = timeEl ? timeEl.innerText.trim() : '';

            // Gabungkan untuk parsing
            const rawTime = dateText || timeText || '';
            const normalizedTime = rawTime.replace(/\s+/g, ' ').trim();
            const combinedTime = `${normalizedTime} ${statusText}`.replace(/\s+/g, ' ').trim();

            // Pattern matching dengan prioritas
            const dateTimeMatch = combinedTime.match(/\d{4}-\d{2}-\d{2}\s+\d{1,2}:\d{2}\s*[AP]M/i);
            const ampmMatch = combinedTime.match(/\b\d{1,2}:\d{2}\s*[AP]M\b/i);
            const timeMatch = combinedTime.match(/\b\d{1,2}:\d{2}\b/);

            let parsedTime = (dateTimeMatch && dateTimeMatch[0]) || (ampmMatch && ampmMatch[0]) || (timeMatch && timeMatch[0]) || rawTime;

            // Jika hanya dapat waktu tanpa tanggal (contoh: "11:45 AM"), tambahkan tanggal hari ini
            if (parsedTime && !parsedTime.match(/\d{4}-\d{2}-\d{2}/)) {
                const today = new Date().toISOString().split('T')[0]; // YYYY-MM-DD
                parsedTime = `${today} ${parsedTime}`;
            }

            matchData.time = parsedTime;
            matchData.status = statusText;

            if (!matchData.time && matchData.status && /\d{1,2}:\d{2}/.test(matchData.status)) {
                matchData.time = matchData.status;
            }

            // Teams
            const teamTd = card.querySelector('tbody tr td:first-child');
            if (teamTd) {
                const teamText = (teamTd.innerText || '').trim();
                let teams = teamText.split(/\s+v\s+/i);

                if (teams.length < 2) {
                    teams = teamText.split(/\s+vs\.?\s+/i);
                }

                if (teams.length < 2) {
                    teams = teamText.split(/\r?\n/).map(part => part.trim()).filter(Boolean);
                }

                if (teams.length >= 2) {
                    matchData.home_team = teams[0].trim();
                    matchData.away_team = teams[1].trim();
                } else {
                    matchData.raw_teams = teamText;
                }
            }

            if (!matchData.home_team || !matchData.away_team) {
                const cardText = (card.innerText || '').replace(/\s+/g, ' ').trim();
                const lines = (card.innerText || '')
                    .split(/\r?\n/)
                    .map(line => line.trim())
                    .filter(Boolean);
                const candidates = Array.from(new Set(lines)).filter(line => {
                    if (/^\d{4}-\d{2}-\d{2}/.test(line)) return false;
                    if (/^\d{1,2}:\d{2}(\s*[AP]M)?$/i.test(line)) return false;
                    if (/^\d+\s*[-:]\s*\d+$/.test(line)) return false;
                    if (/^(RUNNING|WAITING|HT|FT|LIVE|1H|2H)$/i.test(line)) return false;
                    if (line.length < 2) return false;
                    return /[a-zA-Z]/.test(line);
                });

                if (candidates.length >= 2) {
                    matchData.home_team = candidates[0].trim();
                    matchData.away_team = candidates[1].trim();
                    matchData.raw_teams = `${candidates[0]} vs ${candidates[1]}`;
                } else if (!matchData.raw_teams && cardText) {
                    matchData.raw_teams = cardText;
                }
            }

            // Debug log untuk RUNNING matches
            if (matchData.status && matchData.status.toLowerCase().includes('running')) {
                console.log('🏃 RUNNING match found:', {
                    time: matchData.time,
                    status: matchData.status,
                    home: matchData.home_team,
                    away: matchData.away_team,
                    dateEl: dateText,
                    timeEl: timeText
                });

                if (!matchData.time || !matchData.home_team || !matchData.away_team) {
                    console.warn('⚠️ RUNNING missing fields:', {
                        time: matchData.time,
                        status: matchData.status,
                        home: matchData.home_team,
                        away: matchData.away_team,
                        raw_teams: matchData.raw_teams,
                        card: (card.innerText || '').slice(0, 200)
                    });
                }
            }

            // Score
            const fhEl = card.querySelector('td[class*="style_tdFH"]');
            const ftEl = card.querySelector('td[class*="style_tdFT"]');
            matchData.score_fh = fhEl ? fhEl.innerText.trim() : '-';
            matchData.score_ft = ftEl ? ftEl.innerText.trim() : '-';


            // --- D. Grouping ---
            if (!leaguesMap.has(leagueName)) {
                leaguesMap.set(leagueName, []);
            }
            leaguesMap.get(leagueName).push(matchData);

        } catch (err) {
            console.error(`Error parsing match #${index}:`, err);
        }
    });

    // Convert Map to Array format for UI
    const results = [];
    leaguesMap.forEach((matches, league) => {
        results.push({
            league: league,
            items_count: matches.length,
            items: matches
        });
    });

    // Update Status
    const totalLeagues = results.length;
    const totalItems = results.reduce((acc, curr) => acc + curr.items_count, 0);
    
    // Cache stats untuk live ticker
    lastParseStats = { leagues: totalLeagues, items: totalItems };
    
    // Reset timer untuk refresh berikutnya (jika auto-refresh aktif)
    if (refreshInterval) {
        nextRefreshTime = Date.now() + REFRESH_RATE;
        status.innerText = `✅ Found: ${totalItems} Items | Next: 60s`;
    } else {
        status.innerText = `✅ Found: ${totalLeagues} Leagues (Filtered), ${totalItems} Items`;
    }
    status.style.color = '#4ade80'; // Green
    
    console.log('✅ SABAR PARSED DATA (v5 Filtered):', results);
    
    // Auto-sync ke database
    syncToDatabase(results);
    
    // Tampilkan Modal Hasil (Update konten jika modal sudah terbuka)
    updateResultsModal(results);
}

// Fungsi untuk sync data ke database via Background Service Worker
// Background script bypass Chrome Private Network Access restrictions
function syncToDatabase(data) {
    if (!data || data.length === 0) return;

    const updateSyncBadge = (text, color) => {
        const syncBadge = document.getElementById('sabar-sync-status');
        if (syncBadge) {
            syncBadge.innerText = text;
            syncBadge.style.background = color;
        }
    };

    // Langsung kirim ke background script (bypass CORS)
    if (typeof chrome === 'undefined' || !chrome.runtime || !chrome.runtime.sendMessage) {
        console.error('❌ chrome.runtime not available - extension context invalidated');
        updateSyncBadge('❌ Refresh Page!', '#ef4444');

        if (!window._sabarAlertShown) {
            window._sabarAlertShown = true;
            alert('Extension context invalid!\n\nPlease REFRESH this page (F5) after reloading the extension.');
        }
        return;
    }

    // PRIORITAS: Filter RUNNING matches dulu (paling penting)
    // Lalu filter WAITING/upcoming, terakhir COMPLETED
    const prioritizedData = data.map(league => {
        const runningItems = league.items.filter(m =>
            m.status && m.status.toLowerCase().includes('running')
        );
        const waitingItems = league.items.filter(m =>
            m.status && (m.status.toLowerCase().includes('waiting') || m.status.toLowerCase().includes('1h') || m.status.toLowerCase().includes('ht'))
        );
        const otherItems = league.items.filter(m =>
            !m.status || (!m.status.toLowerCase().includes('running') && !m.status.toLowerCase().includes('waiting') && !m.status.toLowerCase().includes('1h') && !m.status.toLowerCase().includes('ht'))
        );

        // Gabungkan dengan prioritas: RUNNING > WAITING > COMPLETED
        // Batasi total 50 item per league untuk performa
        const prioritized = [...runningItems, ...waitingItems, ...otherItems].slice(0, 50);

        return {
            league: league.league,
            items_count: prioritized.length,
            items: prioritized
        };
    }).filter(league => league.items_count > 0);

    const totalItems = prioritizedData.reduce((acc, l) => acc + l.items_count, 0);
    updateSyncBadge(`⏳ Syncing ${totalItems}...`, '#3b82f6');
    console.log('📤 Sending to background script...', prioritizedData.length, 'leagues,', totalItems, 'items (prioritized)');

    // Wrapper dengan retry logic
    const sendWithRetry = (retryCount = 0) => {
        chrome.runtime.sendMessage(
            { action: 'syncToDatabase', data: prioritizedData },
            (response) => {
                if (chrome.runtime.lastError) {
                    const errMsg = chrome.runtime.lastError.message || '';
                    console.error('❌ BG Error:', errMsg);

                    if (retryCount < 1 && errMsg.includes('channel closed')) {
                        console.log('🔄 Retrying sync...');
                        setTimeout(() => sendWithRetry(retryCount + 1), 500);
                        return;
                    }

                    updateSyncBadge('❌ Reload Ext', '#ef4444');
                    return;
                }

                console.log('📥 BG Response:', response);

                if (response && response.success && response.result) {
                    const result = response.result;
                    lastSyncStats = {
                        inserted: result.inserted || 0,
                        updated: result.updated || 0
                    };
                    console.log('✅ SYNC SUCCESS:', result.message);
                    updateSyncBadge(`✅ ${result.message}`, '#22c55e');
                } else if (response && response.error) {
                    console.error('❌ SYNC FAILED:', response.error);
                    updateSyncBadge(`❌ ${response.error}`, '#ef4444');
                } else {
                    console.error('❌ No response from background');
                    updateSyncBadge('❌ No Response', '#ef4444');
                }
            }
        );
    };

    sendWithRetry();
}

// Fungsi untuk menampilkan/update Modal Hasil
function updateResultsModal(data) {
    let modal = document.getElementById('sabar-results-modal');
    
    // Generate Body Content
    const bodyContent = `
        ${data.length === 0 ? `
            <div style="text-align:center; padding:40px; color: var(--sabar-text-muted);">
                <div style="font-size: 40px; margin-bottom: 16px;">🔍</div>
                <p>No matches found for the selected leagues.</p>
                <p style="font-size: 0.9em; margin-top: 8px;">Please check if the data is loaded on the page.</p>
            </div>
        ` : ''}
        ${data.map(league => `
            <div class="sabar-league-group">
                <div class="sabar-league-title">
                    <span>${league.league}</span>
                    <span style="background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 12px; font-size: 0.8em;">${league.items_count} Matches</span>
                </div>
                <table class="sabar-match-table">
                    <thead>
                        <tr>
                            <th style="width: 15%">Time & Status</th>
                            <th style="width: 35%">Home Team</th>
                            <th style="width: 35%">Away Team</th>
                            <th style="width: 7%; text-align: center;">FH</th>
                            <th style="width: 7%; text-align: center;">FT</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${league.items.map(match => `
                            <tr>
                                <td>
                                    <span class="sabar-time">${match.time}</span>
                                    <span class="sabar-status-badge ${match.status.toLowerCase().includes('running') ? 'sabar-status-running' : 'sabar-status-waiting'}">${match.status}</span>
                                </td>
                                <td><span class="sabar-team-name">${match.home_team || match.raw_teams}</span></td>
                                <td><span class="sabar-team-name">${match.away_team || '-'}</span></td>
                                <td style="text-align: center;"><span class="sabar-score-box">${match.score_fh}</span></td>
                                <td style="text-align: center;"><span class="sabar-score-box">${match.score_ft}</span></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `).join('')}
    `;

    // Jika modal belum ada, buat baru
    if (!modal) {
        const modalHtml = `
            <div id="sabar-results-modal">
                <div class="sabar-modal-content">
                    <div class="sabar-modal-header">
                        <h2>
                            <span style="font-size: 1.2em;">📊</span> 
                            Parsing Results
                            <span style="font-size: 0.6em; background: var(--sabar-accent); padding: 2px 6px; border-radius: 4px; vertical-align: middle; margin-left: 8px;">LIVE</span>
                            <span id="sabar-sync-status" style="font-size: 0.6em; background: #3b82f6; padding: 2px 6px; border-radius: 4px; vertical-align: middle; margin-left: 4px;">⏳ Syncing...</span>
                        </h2>
                        <button class="sabar-close-btn" onclick="document.getElementById('sabar-results-modal').remove()">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        </button>
                    </div>
                    <div class="sabar-modal-body" id="sabar-modal-body-content">
                        ${bodyContent}
                    </div>
                    <div class="sabar-modal-footer">
                        <button class="sabar-btn sabar-btn-close" onclick="document.getElementById('sabar-results-modal').remove()">Close</button>
                        <button class="sabar-btn sabar-btn-copy" id="sabar-copy-json">
                            📋 Copy JSON Data
                        </button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Setup listener copy (hanya sekali saat pembuatan modal)
        document.getElementById('sabar-copy-json').addEventListener('click', () => {
             // Re-fetch data terbaru dari DOM jika perlu, atau gunakan variable global 'results' jika disimpan.
             // Untuk simplisitas, kita ambil dari argumen 'data' yang di-pass terakhir kali.
             // Namun, karena ini closure, kita perlu cara dinamis.
             // Solusi: Simpan data terakhir di property element modal
             const currentData = document.getElementById('sabar-results-modal')._currentData;
             const jsonStr = JSON.stringify(currentData, null, 2);
             navigator.clipboard.writeText(jsonStr).then(() => {
                const btn = document.getElementById('sabar-copy-json');
                const originalText = btn.innerText;
                btn.innerText = '✅ Copied!';
                setTimeout(() => btn.innerText = originalText, 2000);
            });
        });
    } else {
        // Jika modal sudah ada, HANYA update konten body-nya saja (biar ga kedip parah)
        document.getElementById('sabar-modal-body-content').innerHTML = bodyContent;
    }

    // Simpan data terbaru ke properti modal untuk keperluan copy
    const modalEl = document.getElementById('sabar-results-modal');
    if (modalEl) modalEl._currentData = data;
}

// Jalankan saat halaman selesai dimuat
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initParser);
} else {
    initParser();
}