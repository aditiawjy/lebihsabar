const TELEGRAM_BOT_TOKEN = '8498249768:AAHuJNth3fhRlR4CBSfvb6eYOFnTzRVR0YA';
const TELEGRAM_CHAT_ID = '6801623296';
const TELEGRAM_API_URL = `https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage`;
const LIVE_INTERVAL_MS = 5000;
const REFRESH_SETTLE_MS = 1500;
const AUTO_SEND_RETRY_COUNT = 2;
const AUTO_SEND_RETRY_DELAY_MS = 1200;
const TARGET_HOST = 'g943gp.bpvmr7u6.com';
const LIVE_ALARM_NAME = 'bpvm-live-cycle';
const TARGET_ODD_MARKET = 'o/u';
const TARGET_FT_ODD_MARKET = 'ft.o/u';
const TARGET_ODD_SELECTION = 'o0.75';
const COMPARISON_ODD_SELECTIONS = ['o1.0', 'o1.25'];
const TARGET_ODD_MIN = 1.95;
const DEFAULT_CUSTOM_WATCH_MARKET = TARGET_ODD_SELECTION;
const ODD_HISTORY_LIMIT = 40;
const ODD_SPIKE_DELTA = 0.10;
const ODD_SPIKE_WINDOW_MS = 30000;
const ODD_BREAKOUT_HOLD_MS = 20000;
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
