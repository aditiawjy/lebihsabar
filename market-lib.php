<?php
/**
 * Pemantau ROI aturan babak kedua terhadap market di titik masuk.
 *
 * Bukan pencari pattern. Tugasnya satu: mengukur aturan yang SUDAH dikunci
 * terhadap taruhan yang benar-benar bisa dipasang, dipecah per hari, supaya
 * kelihatan apakah sebuah aturan bertahan lintas hari dan lintas pasar atau
 * cuma menumpang rentetan gol di satu jendela waktu.
 *
 * Aturan dasar dipakai untuk dua pasar, tetapi R10/R11 SABA punya kalibrasi
 * sendiri karena skala waktu dan distribusi golnya berbeda dari V-Soccer:
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
// Ambang uji-t untuk cap "Terbukti". Halaman menguji ~50 kombinasi aturan x
// durasi; pada 50 uji, ambang 95% biasa (t 1,96) akan diloloskan dua-tiga
// aturan kosong semata-mata karena kebetulan. Koreksi Bonferroni 0,05/50
// menuntut p < 0,001, yang setara t sekitar 3,3.
const T_BONFERRONI = 3.3;
// Odds ganjil/genap yang dipakai untuk menghitung ROI paritas. CSV tidak
// menyimpannya, jadi ini ANGKA ASUMSI. Ditaruh di sini supaya monitor dan
// forward-test memakai harga yang sama -- kalau berbeda, kedua halaman akan
// menampilkan ROI berlainan untuk aturan yang persis sama.
const ODDS_PARITAS = 1.80;
// Sebuah hari disebut "kering gol" kalau gol babak kedua yang benar-benar
// tercipta tertinggal sejauh ini di bawah yang dituntut market. Potongan bandar
// sudah tertutup pada selisih sekitar -0,10, jadi -0,25 adalah hari yang jelas
// salah harga, bukan sekadar riak. Dipakai untuk menghitung SEBERAPA SERING
// hari seperti itu datang -- itulah yang menentukan untung-rugi jangka panjang
// aturan yang menunggu peluang langka, bukan ROI rata-ratanya.
const HARI_KERING_AMBANG = -0.25;
// Syarat odds pada VS-HT34-ODD: odds Under tinggi berarti pasar sedang condong
// ke Over, dan di situlah salah harganya paling besar.
const ODDS_UNDER_MIN = 1.90;
// Tanggal kunci tes maju. Hari sebelum ini adalah data yang melahirkan
// aturannya, jadi tidak boleh ikut menghitung laju hari kering -- kalau ikut,
// lajunya digelembungkan oleh hari yang memang dipilih karena bagus.
const KUNCI_TES_MAJU = '03/08/2026';
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
// Pemanggil boleh menetapkan $pasarAktif sebelum require (forward-test.php
// butuh kedua pasar termuat); kalau tidak, ikuti query string seperti biasa.
$pasarAktif = $pasarAktif ?? ($_GET['pasar'] ?? 'vsoccer');
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

/**
 * Pecah line Asian: "3/3.5" -> [3.0, 3.5]. Bentuk desimal seperempat juga
 * dipecah: "2.25" -> [2.0, 2.5], "1.75" -> [1.5, 2.0]. Tanpa ini, line .25/.75
 * disettle sebagai satu leg penuh sehingga separuh-menang dan separuh-kalah
 * hilang. Titik tengahnya tetap sama, jadi pemakai legs() yang lain tak berubah.
 */
function legs($v): array
{
    $p = array_values(array_filter(
        array_map('trim', explode('/', (string)$v)),
        static fn($x) => $x !== '' && is_numeric($x)
    ));
    $out = array_map('floatval', $p);
    if (count($out) === 1 && abs(fmod(abs($out[0]), 0.5)) > 1e-9) {
        $out = [$out[0] - 0.25, $out[0] + 0.25];
    }
    return $out;
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

/**
 * Sama seperti settle(), tapi ikut melaporkan porsi menang/kalah/push.
 *
 * Line seperempat menghasilkan setengah-menang atau setengah-kalah. Menghitung
 * itu sebagai menang/kalah penuh membuat win rate melenceng, jadi tiap leg
 * menyumbang 1/jumlah-leg ke salah satu dari tiga ember. Ketiganya berjumlah 1.
 */
function settleRinci(int $total, string $lineText, float $odds, string $side): ?array
{
    $L = legs($lineText);
    if (!$L || $odds <= 1) {
        return null;
    }
    $bagian = 1 / count($L);
    $out = ['pl' => 0.0, 'win' => 0.0, 'lose' => 0.0, 'push' => 0.0];
    foreach ($L as $l) {
        if ($total == $l) {
            $out['push'] += $bagian;
            continue;
        }
        $menang = $side === 'over' ? $total > $l : $total < $l;
        $out['pl'] += $menang ? $odds - 1 : -1;
        $out[$menang ? 'win' : 'lose'] += $bagian;
    }
    $out['pl'] *= $bagian;
    return $out;
}

/** Pecahan menang/kalah/push: "59" atau "58,5" — tanpa nol di belakang koma. */
function angka(float $v): string
{
    $s = number_format($v, 1, ',', '.');
    return substr($s, -2) === ',0' ? substr($s, 0, -2) : $s;
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
    'R9' => [
        'label' => 'R8 arah Over saja (A ≥ 1 dan heat ≥ 1)',
        'pick'  => static function (array $r) {
            $a = $r['ht_total'] - $r['demand'];
            $b = $r['heat'];
            if ($b === null || $a < 1 || $b < 1) {
                return null;
            }
            return 'over';
        },
    ],
    'R10' => [
        'label' => 'R8 + total HT 4–5 (Over)',
        'pick'  => static function (array $r) {
            $a = $r['ht_total'] - $r['demand'];
            $b = $r['heat'];
            if ($b === null || $a < 1 || $b < 1 || $r['ht_total'] < 4 || $r['ht_total'] > 5) {
                return null;
            }
            return 'over';
        },
    ],
    'R11' => [
        'label' => 'R10 + gol pertama 1H > 15’ (Over)',
        'pick'  => static function (array $r) {
            $a = $r['ht_total'] - $r['demand'];
            $b = $r['heat'];
            if ($b === null || $a < 1 || $b < 1 || $r['ht_total'] < 4 || $r['ht_total'] > 5
                || $r['first_goal'] === null || $r['first_goal'] <= 15) {
                return null;
            }
            return 'over';
        },
    ],
    // R12 & R13 dipilih dari 731 match V-Soccer dengan syarat lebih ketat dari
    // aturan sebelumnya: harus positif di KEDUA paruh waktu DAN positif di
    // kedua pasar. Keduanya belum lolos batas CI, jadi tetap kandidat.
    'R12' => [
        'label' => 'Total gol HT tepat 3 → Under',
        'pick'  => static fn(array $r) => $r['ht_total'] === 3 ? 'under' : null,
    ],
    'R13' => [
        'label' => 'A ≥ 1 & total HT 4–5 → Over (R10 tanpa syarat heat)',
        'pick'  => static function (array $r) {
            $a = $r['ht_total'] - $r['demand'];
            if ($a < 1 || $r['ht_total'] < 4 || $r['ht_total'] > 5) {
                return null;
            }
            return 'over';
        },
    ],
    // R14 lahir dari mencari sel mana yang bagus, BUKAN dari hipotesis lebih
    // dulu. Karena itu ia ditandai in-sample: angkanya di tabel ini hampir pasti
    // menyusut pada data baru. Penilaian sebenarnya ada di forward-test.php
    // (kode VS-HT34), bukan di sini.
    //
    // Dasarnya: di V-Soccer, memasang Over rugi -20,4% dengan t -4,61 -- satu-
    // satunya angka yang lolos ambang Bonferroni. Arah Under itulah yang bekerja,
    // dan keunggulannya terkonsentrasi saat babak pertama menghasilkan 3-4 gol:
    // pasar menaikkan line seolah tempo itu berlanjut, padahal tidak.
    'R14' => [
        'insample' => true,
        'label' => 'Total gol HT 3–4 → Under',
        'pick' => static fn(array $r) => ($r['ht_total'] >= 3 && $r['ht_total'] <= 4)
            ? 'under' : null,
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
 * SABA tidak boleh mewarisi ambang R10/R11 V-Soccer mentah-mentah.
 *
 * Pada liga 15m/16m, nilai sinyal SABA lebih kecil sehingga syarat absolut
 * "keduanya >= 1" terlalu keras. Pada 20m, sampel yang tersedia justru
 * mendukung filter HT 4-5 seperti R10 lama. R11 juga memakai arah menit yang
 * berbeda: liga pendek cenderung lebih baik dengan gol pertama yang relatif
 * lebih lambat, sedangkan 20m dengan gol pertama yang relatif awal.
 *
 * Ini tetap monitor-only. V-Soccer memakai $RULES asli; tabel SABA memakai
 * salinan ini sehingga dua pasar tidak saling mengubah definisi.
 */
$SABA_RULES = $RULES;
$SABA_RULES['R1A'] = [
    'weak'  => true,
    'label' => 'R1 + tuntutan market ≤ 1 gol → arah R1',
    'pick'  => static function (array $r) {
        if (!isset($r['demand']) || $r['demand'] > 1.0) {
            return null;
        }
        return $r['ht_total'] > $r['demand'] ? 'over' : 'under';
    },
];
$SABA_RULES['R1B'] = [
    'weak'  => true,
    'label' => 'R1 + margin ≥ 1 gol → Over saja',
    'pick'  => static function (array $r) {
        if (!isset($r['demand'])) {
            return null;
        }
        return ($r['ht_total'] - $r['demand']) >= 1.0 ? 'over' : null;
    },
];
$SABA_RULES['R2A'] = [
    'weak'  => true,
    'label' => 'R2 + HT genap (>=4) -> Over; HT ganjil (<=3) -> Under',
    'pick'  => static function (array $r) {
        // Yang dibagi genap/ganjil adalah TOTAL GOL HT, bukan skor FT.
        // Cabang terbaik pada data SABA: HT >= 4 genap untuk Over, sedangkan
        // HT <= 3 ganjil untuk Under.
        $ht = (int)($r['ht_total'] ?? -1);
        if ($ht < 0) {
            return null;
        }
        if ($ht >= 4) {
            return ($ht % 2 === 0) ? 'over' : null;
        }
        return ($ht % 2 !== 0) ? 'under' : null;
    },
];
$SABA_RULES['R2B'] = [
    'weak'  => true,
    'label' => 'R2 terbalik + HT ganjil (>=4) -> Over; HT genap (<=3) -> Under',
    'pick'  => static function (array $r) {
        // Kebalikan paritas R2A: HT tinggi ganjil untuk Over, HT rendah
        // genap untuk Under.
        $ht = (int)($r['ht_total'] ?? -1);
        if ($ht < 0) {
            return null;
        }
        if ($ht >= 4) {
            return ($ht % 2 !== 0) ? 'over' : null;
        }
        return ($ht % 2 === 0) ? 'under' : null;
    },
];
$PARITY_RULES = [
    // Satu-satunya aturan di seluruh berkas ini yang mekanismenya pasti secara
    // matematis, bukan hasil mengaduk data.
    //
    // Paritas FT = paritas HT + paritas gol babak kedua. Skor HT sudah diketahui
    // di titik masuk, jadi menebak paritas FT sama saja menebak paritas gol
    // babak kedua saja. Untuk sebaran Poisson dengan rata-rata lambda, peluang
    // jumlah genap = (1 + e^-2lambda) / 2. Babak SABA sangat pendek (rata-rata
    // ~0,9 gol pada liga 15 menit) sehingga peluang genapnya ~58%; babak
    // V-Soccer menghasilkan ~3,1 gol sehingga peluangnya 50,1% -- praktis
    // lempar koin dan tidak mungkin dikalahkan setelah potongan bandar.
    //
    // Karena itu tebakannya: ikuti paritas HT.
    'P-HT' => [
        'label' => 'Ikut paritas HT: total HT genap -> tebak genap, ganjil -> tebak ganjil',
        'predict' => static function (array $r): string {
            return ((int)$r['ht_total']) % 2 === 0 ? 'even' : 'odd';
        },
    ],
    'R2C' => [
        'label' => 'R2 -> hasil FT genap jika HT >= 4; ganjil jika HT <= 3',
        'predict' => static function (array $r): string {
            // Target R2C adalah paritas total gol FT, bukan Over/Under.
            return $r['ht_total'] >= 4 ? 'even' : 'odd';
        },
    ],
];
$SABA_PARITY_RULES = $PARITY_RULES;

$SABA_RULES['R2'] = [
    'weak'  => true,
    'label' => 'SABA: 15/16m htTot ≥ 3 → Over, ≤ 2 → Under; 20m ≥ 4 / ≤ 3',
    'pick'  => static function (array $r) {
        // Babak 15/16 menit terlalu pendek untuk mencetak 4 gol, jadi ambang
        // ≥4 nyaris tak pernah kena di sana. 20m dibiarkan pakai ambang asli.
        $durasi = $r['half_len'] ? (int)round($r['half_len'] * 2) : null;
        $ambang = in_array($durasi, [15, 16], true) ? 3 : 4;
        return $r['ht_total'] >= $ambang ? 'over' : 'under';
    },
];
$SABA_RULES['R10'] = [
    'label' => 'SABA: 15/16m konsensus Over; 20m R8 + HT 4-5',
    'pick' => static function (array $r) {
        if ($r['heat'] === null) {
            return null;
        }
        $signal = $r['ht_total'] - $r['demand'];
        $durasi = $r['half_len'] ? (int)round($r['half_len'] * 2) : null;

        if (in_array($durasi, [15, 16], true)) {
            // Skala pendek: cukup dua indikator sama-sama positif.
            return ($signal > 0 && $r['heat'] > 0) ? 'over' : null;
        }
        if ($durasi === 20) {
            // Skala 20m: pertahankan filter HT tinggi yang teruji sementara.
            if ($signal < 1 || $r['heat'] < 1 || $r['ht_total'] < 4 || $r['ht_total'] > 5) {
                return null;
            }
            return 'over';
        }
        return null;
    },
];
$SABA_RULES['R11'] = [
    'label' => 'SABA: R10 + ambang menit lokal sesuai durasi',
    'pick' => static function (array $r) {
        // Aturan khusus SABA. Baris V-Soccer tidak punya first_goal_saba/half_len,
        // jadi diperiksa keberadaannya dulu -- tanpa ini muncul ratusan warning
        // saat $SABA_RULES kebetulan dijalankan pada data V-Soccer.
        if (($r['first_goal_saba'] ?? null) === null || ($r['heat'] ?? null) === null
            || empty($r['half_len'])) {
            return null;
        }
        $signal = $r['ht_total'] - $r['demand'];
        $durasi = $r['half_len'] ? (int)round($r['half_len'] * 2) : null;
        // 15' skala sepakbola = sepertiga babak SABA, bukan menit literal.
        $ambangLokal = $r['half_len'] / 3;

        if (in_array($durasi, [15, 16], true)) {
            $base = $signal > 0 && $r['heat'] > 0;
            return ($base && $r['first_goal_saba'] > $ambangLokal) ? 'over' : null;
        }
        if ($durasi === 20) {
            $base = $signal >= 1 && $r['heat'] >= 1
                && $r['ht_total'] >= 4 && $r['ht_total'] <= 5;
            return ($base && $r['first_goal_saba'] <= $ambangLokal) ? 'over' : null;
        }
        return null;
    },
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
    $win = 0.0;
    $lose = 0.0;
    $push = 0.0;
    $odds = [];
    $sampel = [];
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
        $d = settleRinci($r['ft'], $lineText, $od, $side);
        if ($d === null) {
            continue;
        }
        $pl += $d['pl'];
        $sampel[] = $d['pl'];
        $odds[] = $od;
        $win += $d['win'];
        $lose += $d['lose'];
        $push += $d['push'];
    }
    $n = count($sampel);
    if ($n === 0) {
        return null;
    }
    // Push dikeluarkan dari penyebut. Breakeven dihitung dari odds saja, yang
    // mengasumsikan taruhan pasti diputuskan -- kalau push ikut di penyebut,
    // aturan ber-push banyak terlihat jauh lebih buruk daripada kenyataannya.
    $diputuskan = $win + $lose;
    $p = $diputuskan > 0 ? $win / $diputuskan : 0.0;
    $avgOdds = array_sum($odds) / count($odds);
    $breakeven = 1 / $avgOdds * 100;
    // Uji-t atas P/L per taruhan. Ini menguji yang benar-benar penting (uang),
    // bukan win rate, dan otomatis memperhitungkan setengah-menang/kalah.
    $mean = $pl / $n;
    $varian = 0.0;
    foreach ($sampel as $s) {
        $varian += ($s - $mean) ** 2;
    }
    $sd = $n > 1 ? sqrt($varian / ($n - 1)) : 0.0;
    $t = $sd > 0 ? $mean / ($sd / sqrt($n)) : 0.0;
    return [
        'n' => $n, 'win' => $win, 'lose' => $lose, 'push' => $push,
        'n_decided' => $diputuskan,
        'winrate' => $p * 100,
        'pl' => $pl,
        'roi' => $pl / $n * 100,
        'sd' => $sd,
        't' => $t,
        'breakeven' => $breakeven,
        // Tiga tingkat, bukan lampu hijau/merah. Halaman ini menguji sekitar 50
        // kombinasi aturan x durasi, jadi t > 1,96 saja belum cukup: dari 50 uji,
        // dua-tiga akan melewatinya semata-mata karena kebetulan.
        'proven' => $t > T_BONFERRONI,
        'lewat95' => $t > 1.96,
    ];
}

/** Evaluasi prediksi paritas hasil akhir (bukan taruhan Over/Under). */
function evaluateParity(array $rows, callable $predict): ?array
{
    $correct = 0;
    $wrong = 0;
    foreach ($rows as $r) {
        $guess = $predict($r);
        if ($guess !== 'even' && $guess !== 'odd') {
            continue;
        }
        $actual = ((int)$r['ft'] % 2 === 0) ? 'even' : 'odd';
        if ($guess === $actual) {
            $correct++;
        } else {
            $wrong++;
        }
    }
    $n = $correct + $wrong;
    if ($n === 0) {
        return null;
    }
    $p = $correct / $n;
    $se = sqrt($p * (1 - $p) / $n);
    return [
        'n' => $n,
        'correct' => $correct,
        'wrong' => $wrong,
        'accuracy' => $p * 100,
        'ci_lo' => max(0, $p - 1.96 * $se) * 100,
        'ci_hi' => min(1, $p + 1.96 * $se) * 100,
        // CSV tidak menyimpan odds ganjil/genap, jadi ROI tak bisa dihitung.
        // Yang bisa diberikan: harga minimal supaya akurasi ini menghasilkan
        // untung. Di bawah angka ini, tebakan benar pun tetap rugi.
        'odds_min' => $p > 0 ? 1 / $p : null,
        // Ini uji "lebih baik dari lempar koin", BUKAN uji untung. Untung atau
        // tidak tetap bergantung pada odds yang tidak kita punya.
        'proven' => (($p - 1.96 * $se) * 100) > 50,
    ];
}

/** Evaluasi paritas sebagai taruhan dengan odds tetap, bukan sekadar akurasi. */
function evaluateParityBet(array $rows, callable $predict, float $odds): ?array
{
    if ($odds <= 1) {
        return null;
    }
    $correct = 0;
    $wrong = 0;
    $sampel = [];
    foreach ($rows as $r) {
        $guess = $predict($r);
        if ($guess !== 'even' && $guess !== 'odd') {
            continue;
        }
        $actual = ((int)$r['ft'] % 2 === 0) ? 'even' : 'odd';
        $menang = $guess === $actual;
        $menang ? $correct++ : $wrong++;
        $sampel[] = $menang ? $odds - 1 : -1;
    }
    $n = $correct + $wrong;
    if ($n === 0) {
        return null;
    }
    $p = $correct / $n;
    $pl = array_sum($sampel);
    $mean = $pl / $n;
    $varian = 0.0;
    foreach ($sampel as $s) {
        $varian += ($s - $mean) ** 2;
    }
    $sd = $n > 1 ? sqrt($varian / ($n - 1)) : 0.0;
    $se = sqrt($p * (1 - $p) / $n);
    $t = $sd > 0 ? $mean / ($sd / sqrt($n)) : 0.0;
    return [
        'n' => $n, 'correct' => $correct, 'wrong' => $wrong,
        'win' => $correct, 'lose' => $wrong, 'push' => 0,
        'accuracy' => $p * 100, 'winrate' => $p * 100,
        'ci_lo' => max(0, $p - 1.96 * $se) * 100,
        'ci_hi' => min(1, $p + 1.96 * $se) * 100,
        'odds_min' => 1 / $p, 'breakeven' => 100 / $odds,
        'odds' => $odds, 'pl' => $pl, 'roi' => $pl / $n * 100,
        'sd' => $sd, 't' => $t,
        'proven' => (($p - 1.96 * $se) * 100) > (100 / $odds),
    ];
}

/** Jalankan aturan paritas dengan rincian harian. */
function runParityRules(array $rules, array $rows, array $days): array
{
    $out = [];
    foreach ($rules as $code => $rule) {
        // Dinilai pada ODDS_PARITAS supaya kolom ROI dan "ROI -hari terbaik"
        // terisi seperti aturan Over/Under, dan supaya angkanya sama persis
        // dengan forward-test.php yang memakai konstanta yang sama.
        $perDay = [];
        foreach ($days as $d) {
            $seg = array_values(array_filter($rows, static fn($r) => $r['day'] === $d));
            $perDay[$d] = evaluateParityBet($seg, $rule['predict'], ODDS_PARITAS);
        }
        $puncak = null;
        foreach ($perDay as $d => $x) {
            if ($x && ($puncak === null || $x['pl'] > $perDay[$puncak]['pl'])) {
                $puncak = $d;
            }
        }
        $tanpaPuncak = null;
        if ($puncak !== null && count($days) > 1) {
            $sisa = array_values(array_filter($rows, static fn($r) => $r['day'] !== $puncak));
            $tanpaPuncak = $sisa ? evaluateParityBet($sisa, $rule['predict'], ODDS_PARITAS) : null;
        }
        $out[$code] = $rule + [
            'all' => evaluateParityBet($rows, $rule['predict'], ODDS_PARITAS),
            'per_day' => $perDay,
            'peak_day' => $puncak,
            'ex_peak' => $tanpaPuncak,
            'positive_days' => count(array_filter($perDay, static fn($x) => $x && $x['accuracy'] >= 50)),
        ];
    }
    return $out;
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
        // ROI tanpa hari penyumbang terbesar. Sebuah aturan yang seluruh
        // keunggulannya berasal dari satu hari akan runtuh di kolom ini, dan itu
        // ketahuan seketika. Tanpa uji ini, satu hari luar biasa bisa membuat n
        // besar terlihat meyakinkan padahal tidak -- persis yang pernah terjadi
        // pada K1 V-Soccer (t -3,54 seluruhnya ditopang satu hari).
        $puncak = null;
        foreach ($perDay as $d => $x) {
            if ($x && ($puncak === null || $x['pl'] > $perDay[$puncak]['pl'])) {
                $puncak = $d;
            }
        }
        $tanpaPuncak = null;
        if ($puncak !== null && count($days) > 1) {
            $sisa = array_values(array_filter($rows, static fn($r) => $r['day'] !== $puncak));
            $tanpaPuncak = $sisa ? evaluate($sisa, $rule['pick']) : null;
        }
        $out[$code] = $rule + [
            'all' => evaluate($rows, $rule['pick']),
            'per_day' => $perDay,
            'peak_day' => $puncak,
            'ex_peak' => $tanpaPuncak,
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
            // Disimpan supaya perbedaan antar liga bisa dipantau otomatis.
            // Sejauh ini sebarannya murni derau (chi2 11,51 pada db 11), tapi
            // itu justru perlu diuji ulang saat datanya sudah jauh lebih banyak.
            'league' => trim((string)($r[$idx['league']] ?? '')),
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
// Paritas juga dihitung untuk V-Soccer. Hasilnya penting justru karena negatif:
// babak kedua V-Soccer terlalu banyak gol sehingga paritasnya lempar koin.
$parityResults = $rows ? runParityRules($PARITY_RULES, $rows, $days) : [];

// ---------------------------------------------------------------- data SABA
// Bentuk barisnya dibuat sama dengan V-Soccer, sehingga evaluate() tetap
// dipakai ulang. Definisi R10/R11 SABA berasal dari $SABA_RULES di atas.

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
$sabaStats = ['total' => 0, 'no_ht' => 0, 'bad_odds' => 0, 'invalid_ht_line' => 0, 'no_ko' => 0];
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
        // Skor HT diambil dari event gol, BUKAN dari kolom "ht".
        //
        // Kolom itu sering tercatat sesaat setelah babak kedua dimulai sehingga
        // gol-gol awal 2H ikut terhitung: 22% baris SABA punya kolom ht yang
        // tidak cocok dengan event golnya. Contohnya Colombia v Bosnia
        // 03/08/2026 22:32 -- kolom ht menulis 2-2 padahal saat turun minum
        // skornya 1-2, dan gol keempat baru masuk pada menit 1 babak kedua.
        // Line H.Time-nya 4, yang masuk akal untuk 3 gol tetapi tampak mustahil
        // untuk 4 gol (Under tak akan pernah bisa menang).
        //
        // Kesalahan ini paling merusak aturan paritas, karena satu gol tambahan
        // membalik genap/ganjil sepenuhnya. Loader V-Soccer sejak awal sudah
        // memakai event gol; SABA kini disamakan.
        preg_match_all(
            "/(1H|2H)\\s+\\d+'\\s*\\((\\d+)-(\\d+)\\)/",
            (string)($r[$si['goal_markets']] ?? ''),
            $evt,
            PREG_SET_ORDER
        );
        $htHomeS = null;
        $htAwayS = null;
        foreach ($evt as $ev) {
            if ($ev[1] === '1H') {
                $htHomeS = (int)$ev[2];
                $htAwayS = (int)$ev[3];
            }
        }
        if ($htHomeS === null && $evt) {
            // Ada event tapi tak satu pun di babak pertama: benar-benar 0-0 saat HT.
            $htHomeS = 0;
            $htAwayS = 0;
        }
        if ($htHomeS === null) {
            // Tidak ada event sama sekali -- jatuh kembali ke kolom ht.
            $htTeks = trim((string)($r[$si['ht']] ?? ''));
            if (!preg_match('/^(\d+)\s*-\s*(\d+)$/', $htTeks, $hm)) {
                $sabaStats['no_ht']++;
                continue;
            }
            $htHomeS = (int)$hm[1];
            $htAwayS = (int)$hm[2];
        }
        $htTeks = "{$htHomeS}-{$htAwayS}";
        $htTotal = $htHomeS + $htAwayS;

        $pasar = sabaOdds($r[$si['ht_ou_ft']] ?? '');
        if (!$pasar) {
            $sabaStats['bad_odds']++;
            continue;
        }
        // Line FT pada H.Time tidak mungkin berada di bawah jumlah gol yang
        // sudah tercipta. Abaikan snapshot stale/mismatch agar ROI tidak bias.
        if ($pasar['mid'] + 0.001 < $htTotal) {
            $sabaStats['invalid_ht_line']++;
            continue;
        }
        $ko = sabaOdds($r[$si['ko_ou_ft']] ?? '');
        if (!$ko) {
            $sabaStats['no_ko']++;   // tetap dipakai; R6/R7/R8 saja yang melewatinya
        }

        $liga = trim((string)($r[$si['league']] ?? ''));
        $halfLen = sabaHalfLength($liga);

        $golPertama = null;
        $golPertamaSaba = null;
        if (isset($si['goal_minutes'])) {
            preg_match_all("/1H\\s+(\\d+)'/", (string)($r[$si['goal_minutes']] ?? ''), $gm);
            $menit1H = array_map('intval', $gm[1] ?? []);
            if ($menit1H) {
                $golPertamaSaba = min($menit1H);
                $golPertama = sabaMinuteTo90($halfLen, $golPertamaSaba, '1H');
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
            // first_goal dipakai untuk ambang skala-90 R3. R11 SABA memakai
            // first_goal_saba agar label dan logikanya tetap dalam menit lokal.
            'first_goal' => $golPertama,
            'first_goal_saba' => $golPertamaSaba,
            'first_2h' => $first2H,
            'heat' => $ko ? $htTotal - $ko['mid'] / 2 : null,
            'half_len' => $halfLen,
            'league' => $liga,
        ];
    }
    if (isset($sf) && is_resource($sf)) {
        fclose($sf);
    }
}
usort($sabaRows, static fn($a, $b) => $a['ts'] <=> $b['ts']);
$sabaDays = array_values(array_unique(array_column($sabaRows, 'day')));
$sabaResults = $sabaRows ? runRules($SABA_RULES, $sabaRows, $sabaDays) : [];
$sabaParityResults = $sabaRows ? runParityRules($SABA_PARITY_RULES, $sabaRows, $sabaDays) : [];

// SABA tidak boleh dibaca sebagai satu pasar homogen: liga 15m, 16m, dan 20m
// punya tempo serta jendela gol berbeda. Simpan evaluasi per durasi supaya
// hasil satu durasi tidak menutupi durasi lain.
$sabaPerDurasi = [];
foreach ($sabaRows as $r) {
    $durasi = $r['half_len'] ? (string)(int)($r['half_len'] * 2) : 'lain';
    $sabaPerDurasi[$durasi][] = $r;
}
uksort($sabaPerDurasi, static function ($a, $b): int {
    if ($a === 'lain') return 1;
    if ($b === 'lain') return -1;
    return (int)$a <=> (int)$b;
});
foreach ($sabaPerDurasi as $durasi => $items) {
    $hariDurasi = array_values(array_unique(array_column($items, 'day')));
    $sabaPerDurasi[$durasi] = [
        'rows' => $items,
        'days' => $hariDurasi,
        'results' => runRules($SABA_RULES, $items, $hariDurasi),
    ];
}
$sabaParityPerDurasi = [];
foreach ($sabaPerDurasi as $durasi => $bagian) {
    $sabaParityPerDurasi[$durasi] = [
        'days' => $bagian['days'],
        'results' => runParityRules($SABA_PARITY_RULES, $bagian['rows'], $bagian['days']),
    ];
}


// Durasi liga yang benar-benar ada di data, untuk menerjemahkan ambang menit.
$sabaDurasi = [];
foreach ($sabaRows as $r) {
    if ($r['half_len']) {
        $sabaDurasi[(string)(int)($r['half_len'] * 2)] = $r['half_len'];
    }
}
ksort($sabaDurasi, SORT_NUMERIC);
