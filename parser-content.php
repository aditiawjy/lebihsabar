<div class="p-4 md:p-8 max-w-6xl mx-auto space-y-6 page-fade-in">
    <!-- Broadcast Header -->
    <div class="rounded-2xl border border-slate-800 bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 text-white p-5 md:p-6 shadow-xl">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="space-y-1">
                <p class="text-[11px] uppercase tracking-[0.2em] text-sky-300 font-bold">Sport Broadcast Console</p>
                <h1 class="text-2xl md:text-3xl font-black tracking-tight">
                    Parser <span class="text-sky-300">Match Feed</span>
                </h1>
                <p class="text-slate-300 text-sm md:text-base">Validasi data pertandingan sebelum masuk ke database produksi.</p>
            </div>
            <div class="flex items-center gap-3">
                <div class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-emerald-500/15 border border-emerald-400/30">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="text-xs font-bold uppercase tracking-wider text-emerald-200">Data Feed Active</span>
                </div>
                <div class="px-3 py-2 rounded-lg bg-slate-700/70 border border-slate-600 text-xs font-bold text-slate-200" id="serverClock">--:--:-- WIB</div>
            </div>
        </div>
    </div>

    <!-- Daily Health Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 md:gap-4" id="dailyHealthCards">
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Hari Ini</p>
            <p id="metricTotalToday" class="mt-2 text-2xl font-black text-slate-900">0</p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">League Aktif</p>
            <p id="metricLeagueActive" class="mt-2 text-2xl font-black text-slate-900">0</p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Pending Score</p>
            <p id="metricPendingScore" class="mt-2 text-2xl font-black text-amber-600">0</p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Data Invalid</p>
            <p id="metricInvalidData" class="mt-2 text-2xl font-black text-rose-600">0</p>
        </div>
        <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Duplicate</p>
            <p id="metricDuplicate" class="mt-2 text-2xl font-black text-amber-600">0</p>
        </div>
    </div>
    
    <!-- Main Input Card -->
    <div class="bg-white rounded-3xl shadow-lg shadow-slate-200/50 border border-slate-200 p-6 md:p-8 transition-all hover:shadow-xl hover:shadow-slate-200/60 relative overflow-hidden group">
        <!-- Decoration -->
        <div class="absolute top-0 right-0 w-64 h-64 bg-indigo-50 rounded-full -mr-32 -mt-32 opacity-50 group-hover:scale-110 transition-transform duration-700"></div>
        
        <div class="relative z-10 space-y-8">
            <!-- Input Area -->
            <div class="space-y-3">
                <div class="flex justify-between items-center">
                    <label for="inputText" class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Input Data Pertandingan
                    </label>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-medium text-slate-400 bg-slate-50 px-2 py-1 rounded-md border border-slate-100">Format:</span>
                        <select id="formatSelect" class="text-xs font-medium bg-white border border-slate-200 rounded-md px-2 py-1 focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500">
                            <option value="standard">3 Baris/Match</option>
                            <option value="table">Tabel (Tab-separated)</option>
                        </select>
                    </div>
                </div>
                <div class="relative group/input">
                    <textarea 
                        id="inputText" 
                        class="w-full h-64 p-5 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-4 focus:ring-indigo-100 focus:border-indigo-500 text-sm font-mono transition-all resize-none shadow-inner placeholder:text-slate-400"
                        placeholder="Pilih format '3 Baris/Match':
2026-01-14 12:00 AM
Team A v Team B | 0 - 0 | 1 - 1
Info tambahan...

Atau format 'Tabel' (copy dari Excel/spreadsheet):
Game time (UTC 7)    Leagues    Events    Home score    Away score    Details
2026-01-30 00:00    V-Soccer Korea    Team A vs Team B    3    2    Show details"
                        aria-label="Area input data pertandingan"
                    ></textarea>
                    <!-- Focus indicator corner -->
                    <div class="absolute bottom-4 right-4 pointer-events-none opacity-0 group-focus-within/input:opacity-100 transition-opacity">
                        <span class="text-[10px] font-bold text-indigo-400 uppercase tracking-widest">Typing...</span>
                    </div>
                </div>
            </div>
            
            <!-- Controls Area -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- League Selection -->
                <div class="space-y-3" id="leagueSection">
                    <label for="leagueSelect" class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                        Pilih Liga/Kompetisi
                    </label>
                    <div class="space-y-2">
                        <div class="relative">
                            <select 
                                id="leagueSelect" 
                                class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-4 focus:ring-indigo-100 focus:border-indigo-500 text-sm appearance-none transition-all cursor-pointer font-medium text-slate-700 shadow-sm hover:border-indigo-300"
                                aria-label="Pilih liga atau kompetisi"
                            >
                                <option value="">-- Sedang memuat liga... --</option>
                            </select>
                            <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </div>
                        </div>
                        
                        <div class="relative group/new-league" id="newLeagueContainer">
                            <input 
                                type="text" 
                                id="newLeagueInput" 
                                class="hidden w-full px-4 py-3 bg-indigo-50 border border-indigo-200 rounded-xl focus:ring-4 focus:ring-indigo-100 focus:border-indigo-500 text-sm transition-all placeholder:text-indigo-300 text-indigo-900 font-medium relative z-10"
                                placeholder="Ketik nama liga baru lalu tekan Enter..."
                                aria-label="Input nama liga baru"
                            >
                            <div id="enterToAddHint" class="hidden absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-indigo-400 uppercase tracking-wider bg-white/50 px-2 py-1 rounded pointer-events-none">Enter to Add</div>
                        </div>
                        
                        <div id="leagueStatus" class="flex items-center gap-2 text-[11px] font-bold text-slate-400 uppercase tracking-wider" role="status" aria-live="polite">
                            <span class="w-2 h-2 rounded-full bg-slate-300 animate-pulse"></span>
                            Memuat data...
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="flex items-end gap-3">
                    <button 
                        id="parseBtn"
                        onclick="parseData()" 
                        class="group relative flex-1 bg-slate-900 text-white px-6 py-3.5 rounded-xl font-bold text-sm hover:bg-slate-800 transition-all shadow-lg shadow-slate-900/20 hover:shadow-slate-900/30 active:scale-[0.98] flex items-center justify-center gap-2 overflow-hidden focus:ring-4 focus:ring-slate-200 focus:outline-none"
                        aria-label="Proses dan parse data pertandingan"
                    >
                        <div class="absolute inset-0 w-full h-full bg-gradient-to-r from-transparent via-white/10 to-transparent -translate-x-full group-hover:animate-shimmer"></div>
                        <svg class="w-5 h-5 transition-transform group-hover:rotate-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                        <span>Validasi & Proses</span>
                    </button>
                    
                    <button
                        id="saveBtn"
                        onclick="saveToDatabase()"
                        class="hidden group flex-1 bg-indigo-600 text-white px-6 py-3.5 rounded-xl font-bold text-sm hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-600/20 hover:shadow-indigo-600/30 active:scale-[0.98] flex items-center justify-center gap-2 focus:ring-4 focus:ring-indigo-200 focus:outline-none"
                        aria-label="Simpan hasil ke database"
                    >
                        <svg class="w-5 h-5 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                        <span>Simpan Database</span>
                    </button>

                </div>
            </div>
            
            <div id="validationSummary" class="hidden rounded-2xl border p-4 md:p-5" aria-live="polite"></div>
            <div id="saveStatus" aria-live="polite"></div>
        </div>
    </div>

    <!-- Results Section -->
    <div id="result" class="hidden space-y-4" aria-live="polite">
        <div class="flex items-center justify-between px-2">
            <h2 class="text-xl font-bold text-slate-800 tracking-tight flex items-center gap-2">
                <span class="w-2 h-6 bg-indigo-500 rounded-full"></span>
                Preview Hasil Parsing
            </h2>
            <span id="matchCountBadge" class="px-4 py-1.5 bg-white border border-slate-200 text-slate-600 rounded-full text-xs font-bold uppercase tracking-wider shadow-sm"></span>
        </div>
        
        <div id="parsedContent" class="grid gap-4">
            <!-- Content will be injected here -->
        </div>
    </div>

    <!-- Error Section -->
    <div id="error" class="hidden error-slide-in" role="alert">
        <div class="bg-red-50 border border-red-100 rounded-2xl p-6 flex items-start gap-4 shadow-sm">
            <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center shrink-0 shadow-sm text-red-500 border border-red-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <h4 class="text-sm font-bold text-red-900 uppercase tracking-wide">Error Terdeteksi</h4>
                <p class="text-sm text-red-600 font-medium mt-1 leading-relaxed"></p>
            </div>
            <button onclick="this.parentElement.parentElement.classList.add('hidden')" class="ml-auto text-red-400 hover:text-red-600 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </div>
</div>

<style>
    /* Page fade in animation */
    @keyframes pageFadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .page-fade-in {
        animation: pageFadeIn 0.5s ease-out;
    }

    /* Card slide up animation */
    @keyframes slideUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .card-animate {
        animation: slideUp 0.4s ease-out forwards;
    }

    /* Error slide in animation */
    @keyframes errorSlideIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .error-slide-in {
        animation: errorSlideIn 0.3s ease-out;
    }

    /* Success slide in animation */
    @keyframes successSlideIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .success-slide-in {
        animation: successSlideIn 0.5s ease-out;
    }

    /* Shimmer effect */
    @keyframes shimmer {
        100% { transform: translateX(100%); }
    }
    .animate-shimmer {
        animation: shimmer 2s infinite;
    }

    /* Smooth scroll */
    html {
        scroll-behavior: smooth;
    }
</style>

<script>
// Clear any unwanted output
if (typeof console !== 'undefined') {
    console.clear();
}

// State Management
const state = {
    leagues: [],
    matches: [],
    isLoading: false,
    validation: {
        invalidCount: 0,
        warningCount: 0,
        validCount: 0,
        duplicateCount: 0,
        details: []
    }
};

// DOM Elements
const elements = {
    leagueSelect: document.getElementById('leagueSelect'),
    leagueStatus: document.getElementById('leagueStatus'),
    newLeagueInput: document.getElementById('newLeagueInput'),
    formatSelect: document.getElementById('formatSelect'),
    inputText: document.getElementById('inputText'),
    resultDiv: document.getElementById('result'),
    errorDiv: document.getElementById('error'),
    parsedContent: document.getElementById('parsedContent'),
    matchCountBadge: document.getElementById('matchCountBadge'),
    validationSummary: document.getElementById('validationSummary'),
    parseBtn: document.getElementById('parseBtn'),
    saveBtn: document.getElementById('saveBtn'),
    saveStatus: document.getElementById('saveStatus'),
    metricTotalToday: document.getElementById('metricTotalToday'),
    metricLeagueActive: document.getElementById('metricLeagueActive'),
    metricPendingScore: document.getElementById('metricPendingScore'),
    metricInvalidData: document.getElementById('metricInvalidData'),
    metricDuplicate: document.getElementById('metricDuplicate'),
    serverClock: document.getElementById('serverClock')
};

// Utils
const formatTime = (date) => date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
const formatDate = (date) => date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
const toIntOrNull = (value) => (value === null || value === '' || value === undefined) ? null : parseInt(value, 10);

function tickClock() {
    if (!elements.serverClock) return;
    const now = new Date();
    elements.serverClock.textContent = `${now.toLocaleTimeString('id-ID', { hour12: false })} WIB`;
}

function isValidMatchTime(value) {
    if (!value) return false;
    const normalized = String(value).trim();
    const hasAmPm = /\b(AM|PM)\b/i.test(normalized);
    const withSeconds = /^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/.test(normalized);
    const ts = Date.parse(normalized);
    return Number.isFinite(ts) && (hasAmPm || withSeconds || /^\d{4}-\d{2}-\d{2}\s\d{1,2}:\d{2}$/.test(normalized));
}

function validateMatch(match, index) {
    const errors = [];
    const warnings = [];

    const home = (match.home_team || '').trim();
    const away = (match.away_team || '').trim();
    const league = (match.league || '').trim();

    if (!isValidMatchTime(match.match_time)) errors.push('Waktu pertandingan tidak valid');
    if (home === '') errors.push('Tim home wajib diisi');
    if (away === '') errors.push('Tim away wajib diisi');
    if (league === '') errors.push('League wajib diisi');
    if (home !== '' && away !== '' && home.toLowerCase() === away.toLowerCase()) errors.push('Tim home dan away tidak boleh sama');

    const scoreFields = ['fh_home', 'fh_away', 'ft_home', 'ft_away'];
    scoreFields.forEach((field) => {
        const score = toIntOrNull(match[field]);
        if (score !== null && (!Number.isInteger(score) || score < 0)) {
            errors.push(`Skor ${field.replace('_', ' ')} tidak valid`);
        }
    });

    const fhHome = toIntOrNull(match.fh_home);
    const fhAway = toIntOrNull(match.fh_away);
    const ftHome = toIntOrNull(match.ft_home);
    const ftAway = toIntOrNull(match.ft_away);
    if ((ftHome !== null || ftAway !== null) && fhHome === null && fhAway === null) {
        warnings.push('FT ada, tetapi FH kosong');
    }

    return { index, errors, warnings, status: errors.length ? 'invalid' : (warnings.length ? 'warning' : 'valid') };
}

async function loadDailyHealthMetrics(payloadMatches = []) {
    try {
        const response = await fetch('check_matches_health.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ matches: payloadMatches })
        });
        if (!response.ok) throw new Error('Health endpoint gagal');
        const data = await response.json();
        if (!data.success) throw new Error(data.error || 'Gagal membaca health metrics');

        const metrics = data.daily_metrics || {};
        if (elements.metricTotalToday) elements.metricTotalToday.textContent = metrics.total_today ?? 0;
        if (elements.metricLeagueActive) elements.metricLeagueActive.textContent = metrics.league_count_today ?? 0;
        if (elements.metricPendingScore) elements.metricPendingScore.textContent = metrics.pending_score ?? 0;

        return new Set(data.duplicate_indexes || []);
    } catch (err) {
        console.error('Health metrics error:', err);
        return new Set();
    }
}

function renderValidationSummary(summary) {
    if (!elements.validationSummary) return;
    elements.validationSummary.classList.remove('hidden', 'border-emerald-200', 'bg-emerald-50', 'border-amber-200', 'bg-amber-50', 'border-rose-200', 'bg-rose-50');

    if (summary.invalidCount > 0) {
        elements.validationSummary.classList.add('border-rose-200', 'bg-rose-50');
    } else if (summary.warningCount > 0 || summary.duplicateCount > 0) {
        elements.validationSummary.classList.add('border-amber-200', 'bg-amber-50');
    } else {
        elements.validationSummary.classList.add('border-emerald-200', 'bg-emerald-50');
    }

    elements.validationSummary.innerHTML = `
        <div class="flex flex-wrap items-center gap-2 md:gap-3">
            <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-700">Valid: ${summary.validCount}</span>
            <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-100 text-amber-700">Warning: ${summary.warningCount}</span>
            <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-100 text-rose-700">Invalid: ${summary.invalidCount}</span>
            <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-slate-200 text-slate-700">Duplicate: ${summary.duplicateCount}</span>
        </div>
        <p class="mt-3 text-sm font-medium text-slate-700">${summary.invalidCount > 0 ? 'Perbaiki data invalid sebelum menyimpan.' : 'Data siap diproses. Duplicate akan dilewati saat simpan.'}</p>
    `;
}

function applyStatusToCards(details, duplicateSet) {
    const cards = elements.parsedContent.querySelectorAll('[data-match-index]');
    cards.forEach((card) => {
        card.classList.add('relative');
        const oldBadge = card.querySelector('.validation-badge');
        if (oldBadge) oldBadge.remove();

        const idx = parseInt(card.getAttribute('data-match-index'), 10);
        const info = details[idx];
        if (!info) return;

        const isDup = duplicateSet.has(idx);
        const badge = document.createElement('span');
        badge.className = 'validation-badge absolute top-3 right-3 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide';

        if (info.status === 'invalid') {
            badge.classList.add('bg-rose-100', 'text-rose-700', 'border', 'border-rose-200');
            badge.textContent = 'Invalid';
        } else if (isDup) {
            badge.classList.add('bg-amber-100', 'text-amber-700', 'border', 'border-amber-200');
            badge.textContent = 'Duplicate';
        } else if (info.status === 'warning') {
            badge.classList.add('bg-amber-100', 'text-amber-700', 'border', 'border-amber-200');
            badge.textContent = 'Warning';
        } else {
            badge.classList.add('bg-emerald-100', 'text-emerald-700', 'border', 'border-emerald-200');
            badge.textContent = 'Valid';
        }

        card.appendChild(badge);
    });
}

async function finalizeParsedMatches(parsedMatches) {
    const details = parsedMatches.map((match, idx) => validateMatch(match, idx));
    const duplicateSet = await loadDailyHealthMetrics(parsedMatches);

    const summary = {
        validCount: details.filter((d) => d.status === 'valid').length,
        warningCount: details.filter((d) => d.status === 'warning').length,
        invalidCount: details.filter((d) => d.status === 'invalid').length,
        duplicateCount: duplicateSet.size,
        details
    };

    state.validation = summary;
    if (elements.metricInvalidData) elements.metricInvalidData.textContent = summary.invalidCount;
    if (elements.metricDuplicate) elements.metricDuplicate.textContent = summary.duplicateCount;

    renderValidationSummary(summary);
    applyStatusToCards(details, duplicateSet);

    const hasData = parsedMatches.length > 0;
    const canSave = hasData && summary.invalidCount === 0;
    elements.saveBtn.classList.toggle('hidden', !hasData);
    elements.saveBtn.disabled = !canSave;
    elements.saveBtn.classList.toggle('opacity-50', !canSave);
    elements.saveBtn.classList.toggle('cursor-not-allowed', !canSave);
}

// Load leagues from database
async function loadLeagues() {
    try {
        elements.leagueStatus.innerHTML = '<span class="w-2 h-2 rounded-full bg-indigo-500 animate-pulse"></span> Memuat data...';
        
        const response = await fetch('get_leagues.php');
        
        if (!response.ok) {
            throw new Error('Gagal menghubungi server');
        }

        const data = await response.json();
        
        elements.leagueSelect.innerHTML = '<option value="">-- Pilih Liga --</option>';
        
        if (data.success && data.leagues.length > 0) {
            const fragment = document.createDocumentFragment();
            data.leagues.forEach(league => {
                const option = document.createElement('option');
                option.value = league;
                option.textContent = league;
                fragment.appendChild(option);
            });
            elements.leagueSelect.appendChild(fragment);
            elements.leagueStatus.innerHTML = `<span class="w-2 h-2 rounded-full bg-green-500"></span> ${data.leagues.length} liga tersimpan`;
        } else {
            // Default Fallback
            const defaultLeagues = [
                'SABA CLUB FRIENDLY Virtual PES 21 - 15 Mins Play',
                'SABA INTERNATIONAL FRIENDLY Virtual PES 21 - 20 Mins Play'
            ];
            defaultLeagues.forEach(league => {
                const option = document.createElement('option');
                option.value = league;
                option.textContent = league;
                elements.leagueSelect.appendChild(option);
            });
            elements.leagueStatus.innerHTML = '<span class="w-2 h-2 rounded-full bg-yellow-500"></span> Mode Default';
        }

        // Add "Add New" option
        const otherOption = document.createElement('option');
        otherOption.value = 'other';
        otherOption.textContent = '+ Tambah Liga Baru';
        otherOption.classList.add('font-bold', 'text-indigo-600');
        elements.leagueSelect.appendChild(otherOption);

    } catch (error) {
        console.error('Error loading leagues:', error);
        elements.leagueStatus.innerHTML = '<span class="w-2 h-2 rounded-full bg-red-500"></span> Gagal memuat database';
        elements.leagueStatus.classList.add('text-red-500');
    }
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function () {
    loadLeagues();
    loadDailyHealthMetrics();
    tickClock();
    setInterval(tickClock, 1000);
});

elements.leagueSelect.addEventListener('change', function() {
    if (this.value === 'other') {
        elements.newLeagueInput.classList.remove('hidden');
        document.getElementById('enterToAddHint').classList.remove('hidden'); // Show hint
        requestAnimationFrame(() => elements.newLeagueInput.focus());
    } else {
        elements.newLeagueInput.classList.add('hidden');
        document.getElementById('enterToAddHint').classList.add('hidden');
        elements.newLeagueInput.value = '';
    }
});

// Format select event listener - toggle league section visibility
elements.formatSelect.addEventListener('change', function() {
    const leagueSection = document.getElementById('leagueSection');
    const leagueLabel = leagueSection.querySelector('label');
    
    if (this.value === 'table') {
        // For table format, league is optional (can use from data or override)
        leagueSection.style.opacity = '0.7';
        leagueLabel.innerHTML = `
            <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
            Pilih Liga (Opsional - Override)
        `;
    } else {
        // Show league section for standard format
        leagueSection.style.opacity = '1';
        leagueSection.style.pointerEvents = 'auto';
        leagueLabel.innerHTML = `
            <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
            Pilih Liga/Kompetisi
        `;
    }
});

elements.newLeagueInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        e.stopPropagation();
        
        const newLeagueValue = this.value.trim();
        
        if (newLeagueValue) {
            const newOption = document.createElement('option');
            newOption.value = newLeagueValue;
            newOption.textContent = newLeagueValue;
            newOption.selected = true;
            
            const otherOption = elements.leagueSelect.querySelector('option[value="other"]');
            elements.leagueSelect.insertBefore(newOption, otherOption);
            
            // Hide input and hint
            this.classList.add('hidden');
            document.getElementById('enterToAddHint').classList.add('hidden');
            this.value = '';
            
            // Visual feedback
            elements.leagueSelect.classList.add('ring-2', 'ring-green-500');
            setTimeout(() => elements.leagueSelect.classList.remove('ring-2', 'ring-green-500'), 500);
            
            // Update league status text
            elements.leagueStatus.innerHTML = `<span class="w-2 h-2 rounded-full bg-green-500"></span> Liga "${newLeagueValue}" ditambahkan`;
        }
    }
});

function getSelectedLeague() {
    if (elements.leagueSelect.value === 'other' && elements.newLeagueInput.value.trim()) {
        return elements.newLeagueInput.value.trim();
    }
    return elements.leagueSelect.value;
}

// Parser untuk format Tabel (Tab-separated)
function parseTableFormat(input) {
    const lines = input.trim().split('\n');
    const fragment = document.createDocumentFragment();
    const parsedMatches = [];
    
    // Skip header baris pertama
    const dataLines = lines.slice(1).filter(line => line.trim());
    
    if (dataLines.length === 0) {
        throw new Error('Format tidak valid. Pastikan ada data setelah header.');
    }
    
    dataLines.forEach((line, index) => {
        try {
            // Split by tab atau multiple spaces (min 2 spaces)
            const parts = line.split(/\t+|\s{2,}/).map(p => p.trim()).filter(p => p);
            
            // Minimal 5 kolom: datetime, league, events, home_score, away_score
            if (parts.length < 5) {
                throw new Error(`Baris ${index + 2}: Format tidak lengkap. Ditemukan ${parts.length} kolom.`);
            }
            
            const datetimeStr = parts[0];
            const leagueFromData = parts[1];
            const events = parts[2];
            const homeScore = parts[3];
            const awayScore = parts[4];
            
            // Use selected league from dropdown if available, otherwise use from data
            const selectedLeague = getSelectedLeague();
            const league = selectedLeague || leagueFromData;
            
            // Parse datetime: YYYY-MM-DD HH:MM (24 jam format)
            const datetimeMatch = datetimeStr.match(/(\d{4}-\d{2}-\d{2})\s+(\d{1,2}:\d{2})/);
            if (!datetimeMatch) {
                throw new Error(`Baris ${index + 2}: Format waktu tidak valid "${datetimeStr}"`);
            }
            
            const datetime = `${datetimeMatch[1]} ${datetimeMatch[2]}`;
            
            // Parse teams dari events: "Team A vs Team B" atau "Team A [V] vs Team B [V]"
            const teamMatch = events.match(/^(.+?)\s+vs\s+(.+)$/i);
            if (!teamMatch) {
                throw new Error(`Baris ${index + 2}: Format tim tidak valid "${events}"`);
            }
            
            let homeClub = teamMatch[1].trim();
            let awayClub = teamMatch[2].trim();
            
            // Parse scores (bisa angka atau -)
            const normalizeScore = (s) => {
                if (s === '-' || s === '' || s === ':-' || s.toLowerCase() === 'null') return null;
                const num = parseInt(s);
                return isNaN(num) ? null : num;
            };
            
            const ftHome = normalizeScore(homeScore);
            const ftAway = normalizeScore(awayScore);
            
            // Format untuk database (konversi ke AM/PM untuk konsistensi dengan parser lama)
            const dateObj = new Date(datetime);
            const hours = dateObj.getHours();
            const minutes = dateObj.getMinutes();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            const displayHours = hours % 12 || 12;
            const displayMinutes = minutes.toString().padStart(2, '0');
            const datetimeFormatted = `${datetimeMatch[1]} ${displayHours}:${displayMinutes} ${ampm}`;
            
            const matchData = {
                match_time: datetimeFormatted,
                home_team: homeClub,
                away_team: awayClub,
                league: league,
                fh_home: null, // Format tabel tidak ada FH
                fh_away: null,
                ft_home: ftHome,
                ft_away: ftAway
            };
            
            parsedMatches.push(matchData);
            
            // UI Generation
            const isNotStarted = ftHome === null || ftAway === null;
            const displayFt = isNotStarted ? 'vs' : `${ftHome} : ${ftAway}`;
            
            const card = document.createElement('div');
            card.setAttribute('data-match-index', String(parsedMatches.length - 1));
            card.className = `flex flex-col md:flex-row md:items-center gap-6 p-6 rounded-2xl border transition-all hover:shadow-md card-animate ${
                isNotStarted ? 'bg-amber-50/50 border-amber-200' : 'bg-white border-slate-100'
            }`;
            card.style.animationDelay = `${index * 50}ms`;
            
            card.innerHTML = `
                <div class="md:w-32 shrink-0 flex md:block items-center gap-2 md:gap-0">
                    <span class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">${displayHours}:${displayMinutes} ${ampm}</span>
                    <span class="block text-sm font-black text-slate-800">${formatDate(dateObj)}</span>
                </div>
                
                <div class="flex-1 flex items-center justify-between gap-4">
                    <div class="flex-1 text-right">
                        <span class="text-sm font-bold text-slate-800">${homeClub}</span>
                    </div>
                    
                    <div class="shrink-0 flex flex-col items-center gap-1 px-4 min-w-[100px]">
                        <div class="flex items-center justify-center gap-2 px-4 py-1.5 rounded-xl shadow-sm border w-full ${
                            isNotStarted ? 'bg-amber-100 border-amber-200 text-amber-700' : 'bg-slate-50 border-slate-100 text-slate-900'
                        }">
                            <span class="text-lg font-black">${displayFt}</span>
                        </div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">${league}</span>
                    </div>
                    
                    <div class="flex-1 text-left">
                        <span class="text-sm font-bold text-slate-800">${awayClub}</span>
                    </div>
                </div>
            `;
            
            fragment.appendChild(card);
            
        } catch (e) {
            const errorCard = document.createElement('div');
            errorCard.className = 'p-4 bg-red-50 border border-red-100 rounded-xl text-xs text-red-600 font-medium flex items-center gap-2';
            errorCard.innerHTML = `<span class="font-bold">Baris ${index + 2}:</span> ${e.message}`;
            fragment.appendChild(errorCard);
        }
    });
    
    state.matches = parsedMatches;
    elements.parsedContent.innerHTML = '';
    elements.parsedContent.appendChild(fragment);
    elements.matchCountBadge.textContent = `${parsedMatches.length} Data`;
    
    elements.resultDiv.classList.remove('hidden');
    if (parsedMatches.length > 0) {
        finalizeParsedMatches(parsedMatches);
        elements.resultDiv.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// Parser untuk format Standard (3 baris per match)
function parseStandardFormat(input) {
    const lines = input.trim().split('\n');
    const rawMatches = [];
    
    // Group lines by 3
    for (let i = 0; i < lines.length; i += 3) {
        if (i + 2 < lines.length) {
            rawMatches.push({
                datetimeLine: lines[i],
                matchLine: lines[i + 1],
                infoLine: lines[i + 2]
            });
        }
    }
    
    if (rawMatches.length === 0) throw new Error('Format tidak valid. Pastikan setiap pertandingan memiliki 3 baris data.');
    
    const selectedLeague = getSelectedLeague();
    const fragment = document.createDocumentFragment();
    const parsedMatches = [];
    
    rawMatches.forEach((match, index) => {
        try {
            // Parsing Logic
            const datetimeMatch = match.datetimeLine.match(/(\d{4}-\d{2}-\d{2})\s+(\d{1,2}:\d{2})\s*(AM|PM)/i);
            if (!datetimeMatch) throw new Error(`Format waktu baris ke-${index * 3 + 1} tidak valid`);
            
            const datetime = `${datetimeMatch[1]} ${datetimeMatch[2]} ${datetimeMatch[3].toUpperCase()}`;
            const normalizedLine = match.matchLine.replace(/\t/g, ' | ');
            const isRefund = /refund/i.test(match.datetimeLine) || /refund/i.test(match.matchLine) || /refund/i.test(match.infoLine);
            const teamMatch = normalizedLine.match(/^(.+?)\s+v\s+(.+?)(?:\s+\||\s+\d|$)/i);
            const refundHome = teamMatch ? teamMatch[1].trim() : 'Unknown Team';
            const refundAway = teamMatch ? teamMatch[2].trim() : 'Unknown Team';

            if (isRefund) {
                const dateObj = new Date(datetime);
                const refundCard = document.createElement('div');
                refundCard.className = 'flex flex-col md:flex-row md:items-center gap-6 p-6 rounded-2xl border border-red-200 bg-red-50/60 shadow-sm card-animate';
                refundCard.style.animationDelay = `${index * 50}ms`;
                refundCard.innerHTML = `
                    <div class="md:w-32 shrink-0 flex md:block items-center gap-2 md:gap-0">
                        <span class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">${formatTime(dateObj)}</span>
                        <span class="block text-sm font-black text-slate-800">${formatDate(dateObj)}</span>
                    </div>
                    <div class="flex-1 flex items-center justify-between gap-4">
                        <div class="flex-1 text-right">
                            <span class="text-sm font-bold text-slate-800">${refundHome}</span>
                        </div>
                        <div class="shrink-0 flex flex-col items-center gap-1 px-4 min-w-[120px]">
                            <div class="flex items-center justify-center gap-2 px-4 py-1.5 rounded-xl shadow-sm border w-full bg-red-100 border-red-200 text-red-700">
                                <span class="text-sm font-black">REFUND</span>
                            </div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-red-600">Tidak disimpan</span>
                        </div>
                        <div class="flex-1 text-left">
                            <span class="text-sm font-bold text-slate-800">${refundAway}</span>
                        </div>
                    </div>
                `;
                fragment.appendChild(refundCard);
                return;
            }
            
            // Regex Patterns
            const patterns = [
                /^(.+?)\s+v\s+(.+?)\s*\|\s*(\d+)\s*-\s*(\d+)\s*\|\s*(\d+)\s*-\s*(\d+)$/, // Completed
                /^(.+?)\s+v\s+(.+?)\s*\|\s*(\d+)\s*-\s*(\d+)\s*\|\s*-:-$/, // Running
                /^(.+?)\s+v\s+(.+?)\s*\|\s*-:-\s*\|\s*-:-$/, // Not Started
                /^(.+?)\s+v\s+(.+?)\s+(\d+|\:-)\s*-\s*(\d+|\:-)\s+(\d+|\:-)\s*-\s*(\d+|\:-)$/ // Fallback
            ];
            
            let matchMatch = null;
            for (const pattern of patterns) {
                matchMatch = normalizedLine.match(pattern);
                if (matchMatch) {
                    // Normalize matches based on pattern
                    if (matchMatch.length === 5 && normalizedLine.includes('-:-')) { // Running pattern adjustment
                        matchMatch = [...matchMatch.slice(0, 5), ':-', ':-'];
                    } else if (matchMatch.length === 3) { // Not Started pattern adjustment
                        matchMatch = [...matchMatch.slice(0, 3), ':-', ':-', ':-', ':-'];
                    }
                    break;
                }
            }
            
            if (!matchMatch) throw new Error(`Gagal mengenali format skor/tim: "${match.matchLine}"`);
            
            let [, homeClub, awayClub, fhHome, fhAway, ftHome, ftAway] = matchMatch;
            
            // Normalize Scores
            const normalizeScore = (s) => (s === '-' || s === '' || s === ':-') ? ':-' : s;
            fhHome = normalizeScore(fhHome); fhAway = normalizeScore(fhAway);
            ftHome = normalizeScore(ftHome); ftAway = normalizeScore(ftAway);
            
            const convertScore = (score) => score === ':-' ? null : parseInt(score);
            
            const matchData = {
                match_time: datetime,
                home_team: homeClub.trim(),
                away_team: awayClub.trim(),
                league: selectedLeague,
                fh_home: convertScore(fhHome),
                fh_away: convertScore(fhAway),
                ft_home: convertScore(ftHome),
                ft_away: convertScore(ftAway)
            };
            
            parsedMatches.push(matchData);
            
            // UI Generation
            const isRunning = (ftHome === ':-' && fhHome !== ':-');
            const isNotStarted = (ftHome === ':-' && fhHome === ':-');
            
            const displayFt = isNotStarted ? 'vs' : `${ftHome === ':-' ? '-' : ftHome} : ${ftAway === ':-' ? '-' : ftAway}`;
            const displayHt = isNotStarted ? 'Belum Main' : (isRunning ? `Live HT ${fhHome}-${fhAway}` : `HT ${fhHome}-${fhAway}`);
            
            const card = document.createElement('div');
            card.setAttribute('data-match-index', String(parsedMatches.length - 1));
            card.className = `flex flex-col md:flex-row md:items-center gap-6 p-6 rounded-2xl border transition-all hover:shadow-md card-animate ${
                isRunning ? 'bg-green-50/50 border-green-200' : 
                (isNotStarted ? 'bg-amber-50/50 border-amber-200' : 'bg-white border-slate-100')
            }`;
            card.style.animationDelay = `${index * 50}ms`;
            
            const dateObj = new Date(datetime);
            
            card.innerHTML = `
                <div class="md:w-32 shrink-0 flex md:block items-center gap-2 md:gap-0">
                    <span class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">${formatTime(dateObj)}</span>
                    <span class="block text-sm font-black text-slate-800">${formatDate(dateObj)}</span>
                </div>
                
                <div class="flex-1 flex items-center justify-between gap-4">
                    <div class="flex-1 text-right">
                        <span class="text-sm font-bold text-slate-800">${homeClub.trim()}</span>
                    </div>
                    
                    <div class="shrink-0 flex flex-col items-center gap-1 px-4 min-w-[100px]">
                        <div class="flex items-center justify-center gap-2 px-4 py-1.5 rounded-xl shadow-sm border w-full ${
                            isRunning ? 'bg-green-100 border-green-200 text-green-700' : 'bg-slate-50 border-slate-100 text-slate-900'
                        }">
                            <span class="text-lg font-black">${displayFt}</span>
                        </div>
                        <span class="text-[10px] font-bold uppercase tracking-wider ${
                            isRunning ? 'text-green-600 animate-pulse' : 'text-slate-400'
                        }">${displayHt}</span>
                    </div>
                    
                    <div class="flex-1 text-left">
                        <span class="text-sm font-bold text-slate-800">${awayClub.trim()}</span>
                    </div>
                </div>
            `;
            
            fragment.appendChild(card);
            
        } catch (e) {
            const errorCard = document.createElement('div');
            errorCard.className = 'p-4 bg-red-50 border border-red-100 rounded-xl text-xs text-red-600 font-medium flex items-center gap-2';
            errorCard.innerHTML = `<span class="font-bold">Baris ${index * 3 + 1}:</span> ${e.message}`;
            fragment.appendChild(errorCard);
        }
    });
    
    state.matches = parsedMatches;
    elements.parsedContent.innerHTML = '';
    elements.parsedContent.appendChild(fragment);
    elements.matchCountBadge.textContent = `${parsedMatches.length} Data`;
    
    elements.resultDiv.classList.remove('hidden');
    if (parsedMatches.length > 0) {
        finalizeParsedMatches(parsedMatches);
        // Scroll to results
        elements.resultDiv.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function parseData() {
    const input = elements.inputText.value;
    const formatType = elements.formatSelect.value;
    
    // Reset UI
    elements.resultDiv.classList.add('hidden');
    elements.errorDiv.classList.add('hidden');
    elements.saveBtn.classList.add('hidden');
    elements.saveStatus.innerHTML = '';
    
    try {
        if (!input.trim()) throw new Error('Mohon isi data pertandingan terlebih dahulu.');
        
        // Pilih parser berdasarkan format
        if (formatType === 'table') {
            parseTableFormat(input);
        } else {
            parseStandardFormat(input);
        }
        
    } catch (error) {
        elements.errorDiv.querySelector('p').textContent = error.message;
        elements.errorDiv.classList.remove('hidden');
    }
}

function saveToDatabase() {
    if (state.validation.invalidCount > 0) {
        elements.errorDiv.querySelector('p').textContent = 'Masih ada data invalid. Perbaiki terlebih dahulu sebelum menyimpan.';
        elements.errorDiv.classList.remove('hidden');
        return;
    }

    elements.saveBtn.disabled = true;
    const originalContent = elements.saveBtn.innerHTML;
    elements.saveBtn.innerHTML = '<svg class="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> <span class="animate-pulse">Menyimpan...</span>';
    
    fetch('save_matches_csv.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ matches: state.matches })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Gagal menghubungi server');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            elements.saveStatus.innerHTML = `
                <div class="mt-6 p-6 bg-indigo-600 rounded-2xl text-white flex flex-col md:flex-row items-center justify-between gap-4 success-slide-in shadow-xl shadow-indigo-200">
                    <div class="flex items-center gap-4 w-full md:w-auto">
                        <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center shrink-0 backdrop-blur-sm">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <div>
                            <p class="font-bold text-lg tracking-tight">${data.message}</p>
                            <p class="text-xs text-indigo-100 font-medium opacity-80">Database diperbarui: ${new Date().toLocaleTimeString()}</p>
                        </div>
                    </div>
                    <button onclick="resetForm()" class="w-full md:w-auto bg-white text-indigo-600 px-6 py-3 rounded-xl font-bold text-sm hover:bg-indigo-50 transition-colors shadow-sm">
                        Input Data Baru
                    </button>
                </div>
            `;
            elements.inputText.value = '';
            elements.saveBtn.classList.add('hidden');
            elements.resultDiv.classList.add('hidden');
            elements.validationSummary.classList.add('hidden');
            state.matches = [];
            state.validation = { invalidCount: 0, warningCount: 0, validCount: 0, duplicateCount: 0, details: [] };
            if (elements.metricInvalidData) elements.metricInvalidData.textContent = '0';
            if (elements.metricDuplicate) elements.metricDuplicate.textContent = '0';
            loadDailyHealthMetrics();
        } else {
            throw new Error(data.message || 'Gagal menyimpan data');
        }
    })
    .catch(error => {
        elements.saveStatus.innerHTML = `
            <div class="mt-6 p-4 bg-red-50 border border-red-100 rounded-xl text-red-600 flex items-center gap-3">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span class="text-sm font-medium">Gagal menyimpan: ${error.message}</span>
            </div>
        `;
    })
    .finally(() => {
        // Selalu reset tombol setelah selesai (sukses atau gagal)
        elements.saveBtn.disabled = false;
        elements.saveBtn.innerHTML = originalContent;
    });
}

function resetForm() {
    location.reload();
}
</script>
