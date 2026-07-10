const TELEGRAM_BOT_TOKEN = '8498249768:AAHuJNth3fhRlR4CBSfvb6eYOFnTzRVR0YA';
const TELEGRAM_CHAT_ID = '6801623296';
const TELEGRAM_API_URL = `https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage`;
const LIVE_INTERVAL_MS = 5000;
const REFRESH_SETTLE_MS = 1500;
const AUTO_SEND_RETRY_COUNT = 2;
const AUTO_SEND_RETRY_DELAY_MS = 1200;
const TARGET_HOST = 'g943gp.bpvmr7u6.com';
const LIVE_ALARM_NAME = 'bpvm-live-cycle';

// --- Notifikasi H2H Over 0.5 (match hari ini) -------------------------------
// Endpoint PHP yang menghitung H2H dari matches.csv (lihat h2h_today_api.php).
const H2H_API_URL = 'http://localhost/lebihsabar/h2h_today_api.php';
const H2H_ALARM_NAME = 'h2h-today-notify';
const H2H_MIN_MATCHES = 10;   // minimal jumlah pertemuan H2H
const H2H_MIN_PCT = 95;       // ambang persentase minimal (live 0-0 babak 2 + digest)
const H2H_MARKET = 'over05';  // 'over05' | 'shg05'
const H2H_POLL_MINUTES = 15;  // interval cek (menit)
// Alert LIVE: match watch (H2H >= ambang) sedang main, masih 0-0, di awal babak 2.
const H2H_LIVE_2H_MIN_FROM = 3;  // menit 2H mulai (inklusif)
const H2H_LIVE_2H_MIN_TO = 5;    // menit 2H selesai (inklusif) — target sekitar 2H 4'

// --- Market percobaan: Kalah 2x beruntun -> Over 1.5 ------------------------
// Watch-list dihitung dari matches.csv (lihat streak_kl2_api.php). Alert dikirim
// saat match tim watch sedang main DAN odd Over 1.75 mencapai target.
const STREAK_API_URL = 'http://localhost/lebihsabar/streak_kl2_api.php';
const STREAK_MIN_PCT = 85;          // ambang Over 1.5 historis (kalah 2x)
const STREAK_MIN_SAMPLE = 20;       // minimal sampel kl_2 (samakan dgn halaman streak)
const STREAK_U15_MIN_PCT = 83;      // ambang Over 1.5 (under 1.5 3x) — samakan halaman streak mode "3"
const STREAK_U15_MIN_SAMPLE = 8;    // minimal sampel under 1.5 3x (halaman MIN_SAMP['3']=8)
const STREAK_DR_MIN_PCT = 83;       // ambang Over 1.5 (draw 2x) — samakan halaman streak mode "dr_2"
const STREAK_DR_MIN_SAMPLE = 12;    // minimal sampel draw 2x (halaman MIN_SAMP['dr_2']=12)
// Market Over 0.5 (hasil o05). Samakan dgn halaman streak (filter o05 = >90%).
const STREAK_DR_O05_MIN_PCT = 95;   // draw 2x -> Over 0.5 (hanya >=95%)
const STREAK_DR_O05_MIN_SAMPLE = 12;
const STREAK_U05_MIN_PCT = 90;      // under 0.5 2x -> Over 0.5
const STREAK_U05_MIN_SAMPLE = 8;
const STREAK_DR3_O05_MIN_PCT = 90;  // draw 3x -> Over 0.5
const STREAK_DR3_O05_MIN_SAMPLE = 8;
const STREAK_O05_2H_FROM = 3;       // window menit babak 2 (target 2H 4')
const STREAK_O05_2H_TO = 5;
// Market Over 1.5: situs tak punya garis o1.75, jadi trigger = live babak 2 (window)
// + total gol masih < 2 (Over 1.5 belum kena). Odd hanya info bila kebetulan ada.
const STREAK_O15_2H_FROM = 3;       // window menit babak 2 (target 2H 4')
const STREAK_O15_2H_TO = 5;
const STREAK_ODD_SELECTION = 'o1.75'; // hanya untuk info odd bila garis ini muncul
const STREAK_ODD_TARGET = 1.80;     // (tidak lagi jadi syarat trigger)

const TARGET_ODD_MARKET = 'o/u';
const TARGET_FT_ODD_MARKET = 'ft.o/u';
const TARGET_ODD_SELECTION = 'o0.5';
const COMPARISON_ODD_SELECTIONS = ['o1.0', 'o1.25'];
const TARGET_ODD_MIN = 1.65;
const DEFAULT_CUSTOM_WATCH_MARKET = TARGET_ODD_SELECTION;
const ODD_HISTORY_LIMIT = 40;
const ODD_SPIKE_DELTA = 0.10;
const ODD_SPIKE_WINDOW_MS = 30000;
const ODD_BREAKOUT_HOLD_MS = 20000;
const MATCH_STATE_RETENTION_MS = 2 * 60 * 60 * 1000;
const MATCH_STATE_MAX_KEYS = 250;
const TARGET_TAB_RELOAD_INTERVAL_MS = 30 * 60 * 1000;
const MAX_ODDS_MARKETS_PER_MATCH = 8;
const MAX_ODDS_BUTTONS_PER_MARKET = 12;
const AUTO_DETAIL_ENABLED = true;
const AUTO_DETAIL_MAX_MATCHES_PER_CYCLE = 20;
const AUTO_DETAIL_SETTLE_MS = 250;
const DETAIL_PAGE_ENRICH_ENABLED = true;
const DETAIL_PAGE_ENRICH_MAX_MATCHES = 2;
const DETAIL_PAGE_LOAD_WAIT_MS = 2200;
const DETAIL_CLICK_ENRICH_ENABLED = false;
const DETAIL_CLICK_ENRICH_MAX_MATCHES = 5;
const DETAIL_CLICK_SETTLE_MS = 2500;
const CUSTOM_WATCH_CONFIG_KEY = 'bpvmCustomWatchConfig';
const DEFAULT_CUSTOM_WATCH_CONFIG = {
    teamRules: [],
    customOddThreshold: TARGET_ODD_MIN,
    customOddSelection: DEFAULT_CUSTOM_WATCH_MARKET
};

const MILESTONES = [
    { id: '1h3', half: '1H', minThreshold: 3 },
    { id: '2h1', half: '2H', minThreshold: 1 },
    { id: '2h7', half: '2H', minThreshold: 7 },
];
