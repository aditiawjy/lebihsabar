<?php
/**
 * Pemantau ROI aturan babak kedua terhadap market di titik masuk.
 *
 * Bukan pencari pattern. Tugasnya satu: mengukur aturan yang SUDAH dikunci
 * terhadap taruhan yang benar-benar bisa dipasang, dipecah per hari, supaya
 * kelihatan apakah sebuah aturan bertahan lintas hari dan lintas pasar atau
 * cuma menumpang rentetan gol di satu jendela waktu.
 *
 * Dua pasar diukur dengan aturan yang sama persis:
 *   V-Soccer -> goal_log_vsoccer.csv, titik masuk = line m46
 *   SABA     -> goal_log_bpvm.csv,    titik masuk = market O/U saat "H.Time"
 *
 * Baca-saja. Tidak menulis apa pun.
 */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
date_default_timezone_set('Asia/Jakarta');

const ODDS_BOOK_MIN = 1.0;   // sama dengan validasi di vsoccer_headless.py
const ODDS_BOOK_MAX = 1.20;
// Ambang selisih minimal untuk R5. Sengaja dijadikan konstanta supaya kelihatan
// bahwa R5 punya angka yang di-tuning -- R1 tidak punya satu pun.
const R5_MIN_MARGIN = 1.5;
// Ambang "panas" untuk R7. Sama seperti R5, ini angka yang di-tuning.
const R7_MIN_HEAT = 1.0;
// R8 menuntut kedua sinyal sama-sama melewati ambang ini.
const R8_MIN_BOTH = 1.0;
// Ambang menit dinyatakan dalam skala sepakbola 90 menit. Jam SABA jauh lebih
// pendek, jadi menitnya diskalakan dulu (lihat sabaMinuteTo90).
const R3_FIRST_GOAL_MAX = 15;
const R4_FIRST_2H_MAX = 55;

const VSOCCER_FILE = __DIR__ . '/goal_log_vsoccer.csv';
const SABA_FILE = __DIR__ . '/goal_log_bpvm.csv';

// Pasar mana yang ditampilkan. Keduanya tetap dihitung; ini hanya mengatur apa
// yang tampil, supaya angka dua pasar tidak tercampur saat dibaca.
$pasarAktif = $_GET['pasar'] ?? 'vsoccer';
if (!in_array($pasarAktif, ['vsoccer', 'saba', 'semua'], true)) {
    $pasarAktif = 'vsoccer';
}
$tampilVsoccer = in_array($pasarAktif, ['vsoccer', 'semua'], true);
$tampilSaba = in_array($pasarAktif, ['saba', 'semua'], true);

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** Total implied probability pasangan odds masuk akal? Sama seperti di scraper. */
function bookOk(float $over, float $under): bool
{
    if ($over <= 1 || $under <= 1) {
        return false;
    }
    $book = 1 / $over + 1 / $under;
    return $book >= ODDS_BOOK_MIN && $book <= ODDS_BOOK_MAX;
}

/** Pecah line Asian: "3/3.5" -> [3.0, 3.5]. */
function legs($v): array
{
    $p = array_values(array_filter(
        array_map('trim', explode('/', (string)$v)),
        static fn($x) => $x !== '' && is_numeric($x)
    ));
    return array_map('floatval', $p);
}

/**
 * Settle Over/Under Asian. Line pecahan = stake dibagi rata ke tiap leg,
 * dan leg yang sama dengan total gol dihitung push (bukan kalah).
 * Kembalikan profit per 1 unit stake, atau null kalau tak bisa disettle.
 */
function settle(int $total, string $lineText, float $odds, string $side): ?float
{
    $L = legs($lineText);
    if (!$L || $odds <= 1) {
        return null;
    }
    $sum = 0.0;
    foreach ($L as $l) {
        if ($total == $l) {
            continue; // push
        }
        $win = $side === 'over' ? $total > $l : $total < $l;
        $sum += $win ? $odds - 1 : -1;
    }
    return $sum / count($L);
}

function pct(?float $v, int $dec = 1): string
{
    return $v === null ? '–' : number_format($v, $dec, ',', '.') . '%';
}

function signed(?float $v): string
{
    if ($v === null) {
        return '–';
    }
    return ($v >= 0 ? '+' : '−') . number_format(abs($v), 1, ',', '.') . '%';
}

// ------------------------------------------------------------ aturan diuji
// Dikunci sengaja. Menambah aturan baru setelah melihat hasil = mengulang
// siklus overfitting; kontrol di bawah ada supaya itu langsung kelihatan.
$RULES = [
    'R1' => [
        'weak'  => true,
        'label' => 'htTot > tuntutan market → Over',
        'pick'  => static fn(array $r) => $r['ht_total'] > $r['demand'] ? 'over' : 'under',
    ],
    'R2' => [
        'weak'  => true,
        'label' => 'htTot ≥ 4 → Over, ≤ 3 → Under',
        'pick'  => static fn(array $r) => $r['ht_total'] >= 4 ? 'over' : 'under',
    ],
    'R3' => [
        'weak'  => true,
        'label' => 'R1 + gol pertama ≤ ' . R3_FIRST_GOAL_MAX . '’',
        'pick'  => static function (array $r) {
            if ($r['first_goal'] === null || $r['first_goal'] > R3_FIRST_GOAL_MAX) {
                return null;
            }
            return $r['ht_total'] > $r['demand'] ? 'over' : 'under';
        },
    ],
    'R4' => [
        'weak'  => true,
        'label' => 'Gol 2H ≤ ' . R4_FIRST_2H_MAX . '’ → Over (line saat gol itu)',
        'pick'  => static function (array $r) {
            $g = $r['first_2h'];
            if (!$g || $g['minute'] > R4_FIRST_2H_MAX) {
                return null;
            }
            return ['over', $g['line'], $g['over']];
        },
    ],
    'R5' => [
        'weak'  => true,
        'label' => 'R1 tapi hanya kalau selisih ≥ 1,5 gol',
        'pick'  => static function (array $r) {
            $margin = $r['ht_total'] - $r['demand'];
            if (abs($margin) < R5_MIN_MARGIN) {
                return null; // sinyal terlalu tipis, tidak pasang
            }
            return $margin > 0 ? 'over' : 'under';
        },
    ],
    'R6' => [
        'label' => 'Babak 1 lebih panas dari ekspektasi kickoff → Over',
        'pick'  => static function (array $r) {
            if ($r['heat'] === null) {
                return null;
            }
            return $r['heat'] > 0 ? 'over' : 'under';
        },
    ],
    'R7' => [
        'label' => 'R6 tapi hanya kalau |heat| ≥ 1',
        'pick'  => static function (array $r) {
            if ($r['heat'] === null || abs($r['heat']) < R7_MIN_HEAT) {
                return null;
            }
            return $r['heat'] > 0 ? 'over' : 'under';
        },
    ],
    'R8' => [
        'label' => 'Dua sinyal sepakat & keduanya ≥ 1',
        'pick'  => static function (array $r) {
            if ($r['heat'] === null) {
                return null;
            }
            $a = $r['ht_total'] - $r['demand'];
            $b = $r['heat'];
            if (abs($a) < R8_MIN_BOTH || abs($b) < R8_MIN_BOTH) {
                return null; // salah satu sinyal terlalu lemah
            }
            if (($a > 0) !== ($b > 0)) {
                return null; // dua sinyal bertentangan
            }
            return $a > 0 ? 'over' : 'under';
        },
    ],
    'K1' => [
        'label' => 'KONTROL: selalu Over',
        'pick'  => static fn(array $r) => 'over',
        'control' => true,
    ],
    'K2' => [
        'label' => 'KONTROL: selalu Under',
        'pick'  => static fn(array $r) => 'under',
        'control' => true,
    ],
];

/**
 * Hitung performa satu aturan pada sekumpulan match.
 *
 * pick() boleh mengembalikan:
 *   - 'over' / 'under'      -> taruhan di line titik masuk (default)
 *   - ['over', line, odds]  -> taruhan di line lain, mis. saat gol 2H
 *   - null                  -> match dilewati aturan ini
 */
function evaluate(array $rows, callable $pick): ?array
{
    $pl = 0.0;
    $win = 0;
    $lose = 0;
    $push = 0;
    $odds = [];
    foreach ($rows as $r) {
        $out = $pick($r);
        if ($out === null) {
            continue;
        }
        if (is_array($out)) {
            [$side, $lineText, $od] = $out;
        } else {
            $side = $out;
            $lineText = $r['line'];
            $od = $side === 'over' ? $r['over'] : $r['under'];
        }
        $s = settle($r['ft'], $lineText, $od, $side);
        if ($s === null) {
            continue;
        }
        $pl += $s;
        $odds[] = $od;
        if ($s > 0) {
            $win++;
        } elseif ($s < 0) {
            $lose++;
        } else {
            $push++;
        }
    }
    $n = $win + $lose + $push;
    if ($n === 0) {
        return null;
    }
    $p = $win / $n;
    $se = sqrt($p * (1 - $p) / $n);
    $avgOdds = array_sum($odds) / count($odds);
    $breakeven = 1 / $avgOdds * 100;
    return [
        'n' => $n, 'win' => $win, 'lose' => $lose, 'push' => $push,
        'winrate' => $p * 100,
        'pl' => $pl,
        'roi' => $pl / $n * 100,
        'ci_lo' => max(0, $p - 1.96 * $se) * 100,
        'ci_hi' => min(1, $p + 1.96 * $se) * 100,
        'breakeven' => $breakeven,
        // Satu-satunya lampu hijau yang berarti: batas bawah CI 95% di atas
        // breakeven. ROI tinggi dengan CI menyentuh breakeven belum apa-apa.
        'proven' => (($p - 1.96 * $se) * 100) > $breakeven,
    ];
}

/** Jalankan semua aturan pada satu kumpulan match, lengkap dengan rincian harian. */
function runRules(array $RULES, array $rows, array $days): array
{
    $out = [];
    foreach ($RULES as $code => $rule) {
        $perDay = [];
        foreach ($days as $d) {
            $seg = array_values(array_filter($rows, static fn($r) => $r['day'] === $d));
            $perDay[$d] = evaluate($seg, $rule['pick']);
        }
        $out[$code] = $rule + [
            'all' => evaluate($rows, $rule['pick']),
            'per_day' => $perDay,
            'positive_days' => count(array_filter($perDay, static fn($x) => $x && $x['roi'] > 0)),
        ];
    }
    return $out;
}

// ------------------------------------------------------------ data V-Soccer
$rows = [];
$stats = ['total' => 0, 'no_goals' => 0, 'bad_odds' => 0, 'no_line' => 0];
$error = null;

if (!is_file(VSOCCER_FILE)) {
    $error = 'goal_log_vsoccer.csv tidak ditemukan.';
} elseif (($fh = fopen(VSOCCER_FILE, 'r')) === false) {
    $error = 'goal_log_vsoccer.csv tidak dapat dibuka.';
} else {
    $header = fgetcsv($fh);
    $idx = array_flip($header ?: []);
    $need = ['datetime', 'home_team', 'away_team', 'goals', 'final_home', 'final_away',
             'm46_line', 'm46_over', 'm46_under', 'goal_markets', 'ko_line'];
    foreach ($need as $col) {
        if (!isset($idx[$col])) {
            $error = "Kolom CSV tidak ditemukan: {$col}.";
            break;
        }
    }
    while ($error === null && ($r = fgetcsv($fh)) !== false) {
        $stats['total']++;

        // Baris tanpa gol tercatat dibuang: hasilnya tidak bisa diverifikasi.
        preg_match_all("/(1H|2H)\\s+(\\d+)'\\s*\\((\\d+)-(\\d+)\\)/", (string)($r[$idx['goals']] ?? ''), $m, PREG_SET_ORDER);
        if (!$m) {
            $stats['no_goals']++;
            continue;
        }

        $over = (float)($r[$idx['m46_over']] ?? 0);
        $under = (float)($r[$idx['m46_under']] ?? 0);
        if (!bookOk($over, $under)) {
            $stats['bad_odds']++;
            continue;
        }
        $lineText = trim((string)($r[$idx['m46_line']] ?? ''));
        $L = legs($lineText);
        if (!$L) {
            $stats['no_line']++;
            continue;
        }

        $htHome = 0;
        $htAway = 0;
        $goals2H = 0;
        $mins1H = [];
        foreach ($m as $s) {
            if ($s[1] === '1H') {
                $mins1H[] = (int)$s[2];
                $htHome = (int)$s[3];
                $htAway = (int)$s[4];
            } else {
                $goals2H++;
            }
        }

        $dateText = trim((string)($r[$idx['datetime']] ?? ''));
        $dt = DateTime::createFromFormat('d/m/Y H:i', $dateText);
        $mid = array_sum($L) / count($L);
        $htTotal = $htHome + $htAway;
        $KL = legs((string)($r[$idx['ko_line']] ?? ''));
        $koMid = $KL ? array_sum($KL) / count($KL) : null;

        // Market pada gol PERTAMA babak kedua. Dipakai aturan yang masuk setelah
        // gol awal 2H, jadi line & odds-nya pun harus yang berlaku saat itu.
        preg_match_all(
            "/(1H|2H)\\s+(\\d+)'\\s*\\((\\d+)-(\\d+)\\)\\s*Line\\s+([\\d.\\/]+)\\s+O\\s+([\\d.]+)\\s+U\\s+([\\d.]+)/",
            (string)($r[$idx['goal_markets']] ?? ''),
            $gm,
            PREG_SET_ORDER
        );
        $first2H = null;
        foreach ($gm as $g) {
            if ($g[1] === '2H') {
                $fo = (float)$g[6];
                $fu = (float)$g[7];
                if (legs($g[5]) && bookOk($fo, $fu)) {
                    $first2H = ['minute' => (int)$g[2], 'line' => $g[5], 'over' => $fo, 'under' => $fu];
                }
                break; // hanya gol 2H pertama
            }
        }

        $rows[] = [
            'ts' => $dt ? $dt->getTimestamp() : 0,
            'datetime' => $dateText,
            'day' => substr($dateText, 0, 10),
            'home' => trim((string)($r[$idx['home_team']] ?? '')),
            'away' => trim((string)($r[$idx['away_team']] ?? '')),
            'ht' => "{$htHome}-{$htAway}",
            'ht_total' => $htTotal,
            'goals_2h' => $goals2H,
            'ft' => (int)($r[$idx['final_home']] ?? 0) + (int)($r[$idx['final_away']] ?? 0),
            'line' => $lineText,
            'mid' => $mid,
            'over' => $over,
            'under' => $under,
            // Berapa gol lagi yang dituntut market di babak kedua.
            'demand' => $mid - $htTotal,
            'first_goal' => $mins1H ? $mins1H[0] : null,
            'first_2h' => $first2H,
            // "Panas" babak pertama diukur terhadap ekspektasi KICKOFF, bukan
            // terhadap line m46. Separuh line kickoff = perkiraan gol satu babak.
            'heat' => $koMid === null ? null : $htTotal - $koMid / 2,
        ];
    }
    if (isset($fh) && is_resource($fh)) {
        fclose($fh);
    }
}
usort($rows, static fn($a, $b) => $a['ts'] <=> $b['ts']);
$days = array_values(array_unique(array_column($rows, 'day')));
$results = runRules($RULES, $rows, $days);

// ---------------------------------------------------------------- data SABA
// Bentuk barisnya dibuat sama persis dengan V-Soccer, sehingga $RULES dan
// evaluate() dipakai ulang tanpa perubahan.

/**
 * Panjang satu babak SABA dalam menit tampilan, dibaca dari nama liga
 * ("... - 15 Mins Play" -> 7,5 menit per babak). null kalau tidak terbaca.
 */
function sabaHalfLength(string $league): ?float
{
    if (!preg_match('/(\d+)\s*Mins\s*Play/i', $league, $m)) {
        return null;
    }
    $durasi = (float)$m[1];
    return $durasi > 0 ? $durasi / 2 : null;
}

/**
 * Ubah menit SABA ke skala sepakbola 90 menit.
 *
 * Wajib: jam SABA berjalan 0–7 (liga 15 menit) atau 0–10 (liga 20 menit),
 * sedangkan V-Soccer memakai 0–45 / 46–90. Tanpa penyesuaian, ambang menit di
 * R3 selalu terpenuhi di SABA dan R4 tidak pernah tercapai -- keduanya jadi
 * tidak berarti.
 */
function sabaMinuteTo90(?float $halfLen, int $minute, string $half): ?int
{
    if (!$halfLen) {
        return null;
    }
    $dalamBabak = min(45, (int)round($minute / $halfLen * 45));
    return $half === '2H' ? 45 + $dalamBabak : $dalamBabak;
}

/** Pecah "o 3.25:2.01 | u 3.25:1.75" -> [line, mid, over, under]. */
function sabaOdds(?string $teks): ?array
{
    $teks = (string)$teks;
    if ($teks === '' || strpos($teks, '[LOCKED]') !== false) {
        return null;
    }
    if (!preg_match('/\bo\s*([\d.]+)\s*:\s*([\d.]+)/i', $teks, $o)) {
        return null;
    }
    preg_match('/\bu\s*([\d.]+)\s*:\s*([\d.]+)/i', $teks, $u);
    $over = (float)$o[2];
    $under = $u ? (float)$u[2] : 0.0;
    if (!bookOk($over, $under)) {
        return null;
    }
    return ['line' => $o[1], 'mid' => (float)$o[1], 'over' => $over, 'under' => $under];
}

$sabaRows = [];
$sabaStats = ['total' => 0, 'no_ht' => 0, 'bad_odds' => 0, 'no_ko' => 0];
$sabaError = null;

if (!is_file(SABA_FILE)) {
    $sabaError = 'goal_log_bpvm.csv belum ada — jalankan live-scraper/start_headless.bat.';
} elseif (($sf = fopen(SABA_FILE, 'r')) === false) {
    $sabaError = 'goal_log_bpvm.csv tidak dapat dibuka.';
} else {
    $sh = fgetcsv($sf);
    $si = array_flip($sh ?: []);
    foreach (['datetime', 'league', 'ht', 'final_home', 'final_away', 'ht_ou_ft', 'ko_ou_ft'] as $col) {
        if (!isset($si[$col])) {
            $sabaError = "Kolom belum ada di goal_log_bpvm.csv: {$col}.";
            break;
        }
    }
    while ($sabaError === null && ($r = fgetcsv($sf)) !== false) {
        $sabaStats['total']++;

        // Skor babak pertama wajib: tanpa itu tidak ada sinyal yang bisa dihitung.
        $htTeks = trim((string)($r[$si['ht']] ?? ''));
        if (!preg_match('/^(\d+)\s*-\s*(\d+)$/', $htTeks, $hm)) {
            $sabaStats['no_ht']++;
            continue;
        }
        $htTotal = (int)$hm[1] + (int)$hm[2];

        $pasar = sabaOdds($r[$si['ht_ou_ft']] ?? '');
        if (!$pasar) {
            $sabaStats['bad_odds']++;
            continue;
        }
        $ko = sabaOdds($r[$si['ko_ou_ft']] ?? '');
        if (!$ko) {
            $sabaStats['no_ko']++;   // tetap dipakai; R6/R7/R8 saja yang melewatinya
        }

        $liga = trim((string)($r[$si['league']] ?? ''));
        $halfLen = sabaHalfLength($liga);

        $golPertama = null;
        if (isset($si['goal_minutes'])) {
            preg_match_all("/1H\\s+(\\d+)'/", (string)($r[$si['goal_minutes']] ?? ''), $gm);
            $menit1H = array_map('intval', $gm[1] ?? []);
            if ($menit1H) {
                $golPertama = sabaMinuteTo90($halfLen, min($menit1H), '1H');
            }
        }

        // Market pada gol pertama babak kedua, dari goal_markets:
        // "2H 4' (2-1) FT. O/U o 2:1.45 | u 2:2.55". Tanda "+" sesudah nama
        // market berarti harga direkam sesaat SESUDAH gol (market sempat
        // terkunci), jadi tetap dipakai tapi bukan harga persis di detik gol.
        $first2H = null;
        if (isset($si['goal_markets'])) {
            preg_match_all(
                "/2H\\s+(\\d+)'\\s*\\([^)]*\\)\\s*[^|]*?O\\/U\\+?\\s+(o[^|]+\\|[^|]+)/i",
                (string)($r[$si['goal_markets']] ?? ''),
                $g2,
                PREG_SET_ORDER
            );
            foreach ($g2 as $g) {
                $pasarGol = sabaOdds(trim($g[2]));
                $menit90 = sabaMinuteTo90($halfLen, (int)$g[1], '2H');
                if ($pasarGol && $menit90 !== null) {
                    $first2H = [
                        'minute' => $menit90,
                        'line' => $pasarGol['line'],
                        'over' => $pasarGol['over'],
                        'under' => $pasarGol['under'],
                    ];
                    break;
                }
            }
        }

        $dateText = trim((string)($r[$si['datetime']] ?? ''));
        $dt = DateTime::createFromFormat('d/m/Y H:i', $dateText);
        $ft = (int)($r[$si['final_home']] ?? 0) + (int)($r[$si['final_away']] ?? 0);

        $sabaRows[] = [
            'ts' => $dt ? $dt->getTimestamp() : 0,
            'datetime' => $dateText,
            'day' => substr($dateText, 0, 10),
            'home' => trim((string)($r[$si['home_team']] ?? '')),
            'away' => trim((string)($r[$si['away_team']] ?? '')),
            'ht' => $htTeks,
            'ht_total' => $htTotal,
            'goals_2h' => max(0, $ft - $htTotal),
            'ft' => $ft,
            'line' => $pasar['line'],
            'mid' => $pasar['mid'],
            'over' => $pasar['over'],
            'under' => $pasar['under'],
            'demand' => $pasar['mid'] - $htTotal,
            // Sudah diskalakan ke 90 menit, jadi ambang R3/R4 berlaku sama
            // seperti di V-Soccer. null kalau durasi liga tidak terbaca --
            // lebih baik aturannya melewati match itu daripada salah menyaring.
            'first_goal' => $golPertama,
            'first_2h' => $first2H,
            'heat' => $ko ? $htTotal - $ko['mid'] / 2 : null,
            'half_len' => $halfLen,
        ];
    }
    if (isset($sf) && is_resource($sf)) {
        fclose($sf);
    }
}
usort($sabaRows, static fn($a, $b) => $a['ts'] <=> $b['ts']);
$sabaDays = array_values(array_unique(array_column($sabaRows, 'day')));
$sabaResults = $sabaRows ? runRules($RULES, $sabaRows, $sabaDays) : [];

// Durasi liga yang benar-benar ada di data, untuk menerjemahkan ambang menit.
$sabaDurasi = [];
foreach ($sabaRows as $r) {
    if ($r['half_len']) {
        $sabaDurasi[(string)(int)($r['half_len'] * 2)] = $r['half_len'];
    }
}
ksort($sabaDurasi, SORT_NUMERIC);

/** "2,5' (15m) / 3,3' (20m)" — ambang skala-90 diterjemahkan ke jam SABA. */
function sabaAmbang(array $durasi, int $menit90, string $babak = '1H'): string
{
    $dalamBabak = $babak === '2H' ? $menit90 - 45 : $menit90;
    $bagian = [];
    foreach ($durasi as $total => $halfLen) {
        $bagian[] = number_format($dalamBabak / 45 * $halfLen, 1, ',', '.') . "' ({$total}m)";
    }
    return implode(' / ', $bagian);
}

// Label khusus SABA: ambang menit ditulis dalam jam SABA, bukan skala 90.
// Tanpa ini tabel menyebut "≤ 15'" padahal babak pertama SABA cuma sampai 7'.
$sabaLabel = [];
if ($sabaDurasi) {
    $sabaLabel['R3'] = 'R1 + gol pertama ≤ ' . sabaAmbang($sabaDurasi, R3_FIRST_GOAL_MAX);
    $sabaLabel['R4'] = 'Gol 2H ≤ ' . sabaAmbang($sabaDurasi, R4_FIRST_2H_MAX, '2H') . ' → Over (line saat gol itu)';
}

// Hubungan gol 1H vs gol 2H: dasar mekanisme R1/R2. Kalau r mengecil ke nol,
// alasan memakai aturan-aturan itu ikut hilang.
$corr = null;
if (count($rows) > 3) {
    $x = array_column($rows, 'ht_total');
    $y = array_column($rows, 'goals_2h');
    $n = count($x);
    $mx = array_sum($x) / $n;
    $my = array_sum($y) / $n;
    $sxy = 0.0;
    $sx = 0.0;
    $sy = 0.0;
    for ($k = 0; $k < $n; $k++) {
        $sxy += ($x[$k] - $mx) * ($y[$k] - $my);
        $sx += ($x[$k] - $mx) ** 2;
        $sy += ($y[$k] - $my) ** 2;
    }
    if ($sx > 0 && $sy > 0) {
        $rr = $sxy / sqrt($sx * $sy);
        $t = abs($rr) < 1 ? $rr * sqrt(($n - 2) / (1 - $rr * $rr)) : INF;
        $corr = ['r' => $rr, 't' => $t, 'n' => $n, 'sig' => abs($t) > 1.96];
    }
}

// Market vs kenyataan per total gol HT (V-Soccer).
$byHt = [];
foreach ($rows as $r) {
    $k = $r['ht_total'] >= 6 ? '6+' : (string)$r['ht_total'];
    $byHt[$k]['n'] = ($byHt[$k]['n'] ?? 0) + 1;
    $byHt[$k]['demand'] = ($byHt[$k]['demand'] ?? 0) + $r['demand'];
    $byHt[$k]['actual'] = ($byHt[$k]['actual'] ?? 0) + $r['goals_2h'];
}
uksort($byHt, static fn($a, $b) => (float)$a <=> (float)$b);

/** Satu baris tabel performa aturan. */
function barisAturan(string $code, array $res, int $jumlahHari, array $labelKhusus): string
{
    $a = $res['all'];
    $kelas = !empty($res['control']) ? ' class="ctrl"' : '';
    $tagLemah = !empty($res['weak'])
        ? ' <span class="tag weak" title="Dibangun di atas patokan line titik masuk (sinyal-A). Uji menunjukkan patokan line kickoff lebih baik.">lemah</span>'
        : '';
    $label = e($labelKhusus[$code] ?? $res['label']);
    $html = "<tr{$kelas}><td><b>" . e($code) . "</b>{$tagLemah}</td><td>{$label}</td>";
    if (!$a) {
        return $html . '<td class="num" colspan="9"><span class="muted">tidak ada sampel</span></td></tr>';
    }
    $push = $a['push'] ? ' <span class="muted">(' . $a['push'] . ' push)</span>' : '';
    $plKelas = $a['pl'] >= 0 ? 'pos' : 'neg';
    $plTeks = ($a['pl'] >= 0 ? '+' : '−') . number_format(abs($a['pl']), 2, ',', '.');
    $html .= '<td class="num">' . $a['n'] . '</td>'
        . '<td class="num">' . $a['win'] . $push . '</td>'
        . '<td class="num">' . pct($a['winrate']) . '</td>'
        . '<td class="num">' . pct($a['ci_lo'], 0) . ' – ' . pct($a['ci_hi'], 0) . '</td>'
        . '<td class="num">' . pct($a['breakeven'], 0) . '</td>'
        . '<td class="num ' . $plKelas . '">' . $plTeks . '</td>'
        . '<td class="num ' . ($a['roi'] >= 0 ? 'pos' : 'neg') . '">' . signed($a['roi']) . '</td>'
        . '<td class="num">' . $res['positive_days'] . '/' . $jumlahHari . '</td>'
        . '<td><span class="tag ' . ($a['proven'] ? 'yes' : 'no') . '">' . ($a['proven'] ? 'YA' : 'BELUM') . '</span></td>';
    return $html . '</tr>';
}

/** Satu baris tabel ROI per hari. */
function barisHarian(string $code, array $res, array $hari, array $labelKhusus): string
{
    $kelas = !empty($res['control']) ? ' class="ctrl"' : '';
    $label = e($labelKhusus[$code] ?? $res['label']);
    $html = "<tr{$kelas}><td><b>" . e($code) . "</b></td><td>{$label}</td>";
    foreach ($hari as $d) {
        $x = $res['per_day'][$d] ?? null;
        $kls = $x ? ($x['roi'] >= 0 ? 'pos' : 'neg') : '';
        $isi = $x ? signed($x['roi']) . ' <span class="muted">(' . $x['n'] . ')</span>' : '–';
        $html .= '<td class="num ' . $kls . '">' . $isi . '</td>';
    }
    return $html . '</tr>';
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="120">
<title>Pemantau ROI Market Babak Kedua</title>
<style>
:root{--bg:#0c1016;--card:#151b24;--border:#2b3543;--text:#e7edf5;--muted:#92a0b3;--green:#59db8b;--red:#ff7185;--yellow:#f3c969;--blue:#78b7ff}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
.wrap{max-width:1240px;margin:auto;padding:24px 16px 48px}
.top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}
h1{font-size:24px;margin:0}.sub{color:var(--muted);margin:5px 0 0}
.actions{display:flex;gap:8px}.btn{color:var(--text);text-decoration:none;border:1px solid var(--border);background:#111720;padding:7px 11px;border-radius:7px}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px}
.note{margin:18px 0;padding:14px 16px;border-left:4px solid var(--yellow);background:var(--card);border-radius:12px}
.note b{color:var(--yellow)}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:16px 0}
@media(max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}}
.stat{padding:14px}.stat .label{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em}
.stat .value{font:700 22px/1.25 ui-monospace,Consolas,monospace;margin-top:4px}
.muted{color:var(--muted)}.section{margin-top:26px}.section h2{font-size:15px;margin:0 0 4px}
.section .hint{color:var(--muted);font-size:12px;margin:0 0 10px}
.tablebox{overflow:auto;border:1px solid var(--border);border-radius:10px}
table{width:100%;border-collapse:collapse;white-space:nowrap;background:#10161e}
th,td{padding:8px 10px;border-bottom:1px solid #222c38;text-align:left}
th{color:var(--muted);font-size:11px;text-transform:uppercase}
.num{text-align:right;font-variant-numeric:tabular-nums}
.pos{color:var(--green);font-weight:700}.neg{color:var(--red);font-weight:700}
.ctrl td{background:#131820;color:var(--muted)}
.tag{display:inline-block;font-size:10px;font-weight:800;letter-spacing:.06em;padding:2px 6px;border-radius:4px}
.tag.no{background:#3a1f27;color:var(--red)}.tag.yes{background:#1d3d2a;color:var(--green)}
.tag.weak{background:#2b2620;color:var(--yellow);font-weight:600}
.switch{display:flex;gap:8px;align-items:center;margin:14px 0 4px;flex-wrap:wrap}
.switch .btn.on{background:#1d3d2a;border-color:#2f7d54;color:var(--green);font-weight:700}
.market-head{margin:26px 0 0;padding:10px 14px;border-radius:10px;background:#131a24;border:1px solid var(--border);border-left:4px solid var(--blue)}
.market-head h2{margin:0;font-size:16px;color:var(--text);text-transform:none;letter-spacing:0}
.market-head .src{color:var(--muted);font-size:12px}
.error{background:#421923;border:1px solid #8d3041;color:#ffbac5;padding:12px;border-radius:8px;margin:15px 0}
.foot{color:var(--muted);font-size:12px;margin-top:18px}
.empty{padding:18px;color:var(--muted)}
</style>
</head>
<body>
<main class="wrap">
  <div class="top">
    <div>
      <h1>Pemantau ROI Market Babak Kedua</h1>
      <p class="sub">Aturan diukur terhadap taruhan yang benar-benar bisa dipasang di titik masuk babak kedua.</p>
    </div>
    <div class="actions">
      <a class="btn" href="vsoccer-live.php">← Live</a>
      <a class="btn" href="check-super-accuracy.php">Akurasi pattern</a>
      <a class="btn" href="javascript:location.reload()">↻ Refresh</a>
    </div>
  </div>

  <div class="switch">
    <span class="muted">Pasar:</span>
    <?php foreach (['vsoccer' => 'V-Soccer', 'saba' => 'SABA', 'semua' => 'Bandingkan dua-duanya'] as $kode => $nama): ?>
      <a class="btn<?= $pasarAktif === $kode ? ' on' : '' ?>" href="?pasar=<?= e($kode) ?>"><?= e($nama) ?></a>
    <?php endforeach; ?>
    <span class="muted" style="margin-left:auto">
      V-Soccer <?= count($rows) ?> match · SABA <?= count($sabaRows) ?> match
    </span>
  </div>

  <div class="note">
    <b>Cara membaca halaman ini.</b> ROI tinggi bukan bukti. Yang menentukan dua hal:
    <b>(1)</b> batas bawah CI 95% harus di atas breakeven, dan
    <b>(2)</b> ROI harus positif di banyak hari berbeda, bukan cuma satu.
    Baris <span class="muted">KONTROL</span> adalah aturan tanpa logika apa pun — kalau aturan sungguhan
    tidak jelas mengalahkannya, aturan itu tidak menambah nilai. Kalau kedua kontrol
    berkebalikan tajam antar hari, yang terlihat rentetan gol, bukan edge.
    <br><br>
    Aturan bertanda <span class="tag weak">lemah</span> (R1–R5) memakai patokan line titik masuk.
    Uji perbandingan menunjukkan patokan <b>line kickoff</b> (R6–R8) lebih baik. Utamakan R6–R8.
    <br><br>
    <b>Menit.</b> Ambang menit ditulis dalam skala sepakbola 90 menit. Jam SABA jauh lebih pendek
    (babak pertama 0–7 untuk liga 15 menit), jadi menitnya diskalakan lebih dulu dan label di tabel
    SABA menampilkan menit versi SABA.
  </div>

  <?php if ($tampilVsoccer): ?>
  <?php if ($error !== null): ?><div class="error"><?= e($error) ?></div><?php endif; ?>

  <div class="market-head">
    <h2>V-Soccer</h2>
    <span class="src">goal_log_vsoccer.csv · titik masuk: line m46 (menit 46 babak kedua) · jam 0–90</span>
  </div>

  <section class="grid">
    <div class="card stat">
      <div class="label">Match siap dipakai</div>
      <div class="value"><?= count($rows) ?></div>
      <div class="muted">dari <?= $stats['total'] ?> baris CSV</div>
    </div>
    <div class="card stat">
      <div class="label">Hari terkumpul</div>
      <div class="value"><?= count($days) ?></div>
      <div class="muted"><?= count($days) < 5 ? 'butuh ≥ 5 hari' : 'cukup untuk uji lintas hari' ?></div>
    </div>
    <div class="card stat">
      <div class="label">Korelasi gol 1H ↔ 2H</div>
      <div class="value"><?= $corr ? ($corr['r'] >= 0 ? '+' : '−') . number_format(abs($corr['r']), 3, ',', '.') : '–' ?></div>
      <div class="muted"><?= $corr ? ($corr['sig'] ? 'signifikan (t=' . number_format($corr['t'], 2, ',', '.') . ')' : 'tidak signifikan') : '–' ?></div>
    </div>
    <div class="card stat">
      <div class="label">Baris dibuang</div>
      <div class="value"><?= $stats['no_goals'] + $stats['bad_odds'] + $stats['no_line'] ?></div>
      <div class="muted">gol kosong <?= $stats['no_goals'] ?> · odds <?= $stats['bad_odds'] ?> · line <?= $stats['no_line'] ?></div>
    </div>
  </section>

  <section class="section">
    <h2>Performa aturan (seluruh data)</h2>
    <p class="hint">Kolom <b>TERBUKTI</b> hanya menyala kalau batas bawah CI 95% melewati breakeven.</p>
    <?php if (!$rows): ?>
      <div class="card empty">Belum ada match yang bisa dipakai.</div>
    <?php else: ?>
    <div class="tablebox"><table>
      <thead><tr>
        <th>Kode</th><th>Aturan</th><th class="num">n</th><th class="num">Menang</th>
        <th class="num">Win rate</th><th class="num">CI 95%</th><th class="num">Breakeven</th>
        <th class="num">P&amp;L</th><th class="num">ROI</th><th class="num">Hari +</th><th>Terbukti</th>
      </tr></thead>
      <tbody>
      <?php foreach ($results as $code => $res) {
          echo barisAturan($code, $res, count($days), []);
      } ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>

  <section class="section">
    <h2>ROI per hari — ini ukuran konsistensi</h2>
    <p class="hint">Aturan yang nyata positif di banyak hari. Positif di satu hari saja tidak berarti apa-apa.</p>
    <?php if (!$days): ?>
      <div class="card empty">Belum ada data.</div>
    <?php else: ?>
    <div class="tablebox"><table>
      <thead><tr><th>Kode</th><th>Aturan</th>
        <?php foreach ($days as $d): ?><th class="num"><?= e(substr($d, 0, 5)) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($results as $code => $res) {
          echo barisHarian($code, $res, $days, []);
      } ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>

  <section class="section">
    <h2>V-Soccer — market vs kenyataan per total gol babak pertama</h2>
    <p class="hint">Dasar mekanisme R1/R2: kolom tuntutan market cenderung rata, kolom aktual naik mengikuti gol 1H. Kalau pola itu hilang, R1/R2 kehilangan alasannya.</p>
    <?php if (!$byHt): ?>
      <div class="card empty">Belum ada data.</div>
    <?php else: ?>
    <div class="tablebox"><table>
      <thead><tr><th>Gol HT</th><th class="num">n</th><th class="num">Market tuntut</th><th class="num">Aktual gol 2H</th><th class="num">Selisih</th></tr></thead>
      <tbody>
      <?php foreach ($byHt as $k => $v):
        $dem = $v['demand'] / $v['n'];
        $act = $v['actual'] / $v['n'];
        $dif = $act - $dem; ?>
        <tr>
          <td><?= e($k) ?></td>
          <td class="num"><?= $v['n'] ?></td>
          <td class="num"><?= number_format($dem, 2, ',', '.') ?></td>
          <td class="num"><?= number_format($act, 2, ',', '.') ?></td>
          <td class="num <?= $dif >= 0 ? 'pos' : 'neg' ?>"><?= ($dif >= 0 ? '+' : '−') . number_format(abs($dif), 2, ',', '.') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
  <?php endif; /* tampilVsoccer */ ?>

  <?php if ($tampilSaba): ?>
  <?php if ($sabaError !== null): ?><div class="error"><?= e($sabaError) ?></div><?php endif; ?>

  <div class="market-head">
    <h2>SABA</h2>
    <span class="src">
      goal_log_bpvm.csv · titik masuk: market O/U saat status H.Time ·
      jam <?= $sabaDurasi ? e(implode(' / ', array_map(static fn($h, $t) => "0–" . (int)$h . " ({$t}m)", $sabaDurasi, array_keys($sabaDurasi)))) : 'pendek' ?>
    </span>
  </div>

  <?php if (!$sabaRows && $sabaError === null): ?>
    <div class="card empty" style="margin-top:12px">
      Belum ada match SABA yang siap dipakai (dari <?= $sabaStats['total'] ?> baris:
      <?= $sabaStats['no_ht'] ?> tanpa skor HT, <?= $sabaStats['bad_odds'] ?> odds H.Time tidak sah).
    </div>
  <?php elseif ($sabaRows): ?>
  <section class="section">
    <h2>Performa aturan (seluruh data)</h2>
    <p class="hint">
      <?= count($sabaRows) ?> match siap dipakai dari <?= $sabaStats['total'] ?> baris ·
      dibuang: <?= $sabaStats['no_ht'] ?> tanpa skor HT, <?= $sabaStats['bad_odds'] ?> odds H.Time tidak sah/terkunci ·
      <?= $sabaStats['no_ko'] ?> tanpa odds kickoff (R6–R8 melewatinya).
      Ambang menit di R3/R4 sudah diterjemahkan ke jam SABA.
    </p>
    <div class="tablebox"><table>
      <thead><tr>
        <th>Kode</th><th>Aturan</th><th class="num">n</th><th class="num">Menang</th>
        <th class="num">Win rate</th><th class="num">CI 95%</th><th class="num">Breakeven</th>
        <th class="num">P&amp;L</th><th class="num">ROI</th><th class="num">Hari +</th><th>Terbukti</th>
      </tr></thead>
      <tbody>
      <?php foreach ($sabaResults as $code => $res) {
          echo barisAturan($code, $res, count($sabaDays), $sabaLabel);
      } ?>
      </tbody>
    </table></div>
  </section>

  <section class="section">
    <h2>ROI per hari — ini ukuran konsistensi</h2>
    <div class="tablebox"><table>
      <thead><tr><th>Kode</th><th>Aturan</th>
        <?php foreach ($sabaDays as $d): ?><th class="num"><?= e(substr($d, 0, 5)) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($sabaResults as $code => $res) {
          echo barisHarian($code, $res, $sabaDays, $sabaLabel);
      } ?>
      </tbody>
    </table></div>
    <p class="foot">
      Aturan yang nyata mestinya bertahan di <b>dua pasar</b>, bukan cuma satu — bandingkan dengan tabel V-Soccer.
    </p>
  </section>
  <?php endif; ?>
  <?php endif; /* tampilSaba */ ?>

  <p class="foot">
    Baca-saja · baris tanpa hasil yang bisa diverifikasi dan odds di luar
    <?= number_format(ODDS_BOOK_MIN, 2, ',', '.') ?>–<?= number_format(ODDS_BOOK_MAX, 2, ',', '.') ?>
    (implied probability) dibuang · halaman refresh otomatis tiap 2 menit.
  </p>
</main>
</body>
</html>
