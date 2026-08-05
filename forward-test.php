<?php
/**
 * Pencatat tes maju.
 *
 * Semua angka di market-2h-monitor.php dihitung atas data yang sama dengan data
 * yang melahirkan aturannya. Halaman ini melakukan satu hal yang tidak bisa
 * dilakukan halaman itu: mengukur aturan HANYA pada match yang terjadi setelah
 * aturannya dikunci, dengan ambang lolos yang ditetapkan di muka.
 *
 * Ambang tidak boleh ditentukan setelah melihat hasil. Karena itu ambang di sini
 * dihitung dari simpangan baku data in-sample dan target jumlah taruhan, bukan
 * dari performa pasca-kunci.
 */

$pasarAktif = 'semua';
require __DIR__ . '/market-lib.php';

// Data yang ada berhenti 02/08/2026, jadi semuanya sudah terlihat saat aturan
// dibuat. Semua match sejak tanggal ini belum pernah dipakai menyusun aturan.
const TES_MULAI_DEFAULT = '03/08/2026';
// Satu sisi, 95%. Ambang ROI = Z * sd / sqrt(target).
const Z_SATU_SISI = 1.645;
// Odds ganjil/genap yang dipakai untuk menghitung ROI. CSV tidak menyimpannya,
// jadi ini ANGKA ASUMSI -- disamakan untuk semua kandidat paritas supaya bisa
// dibandingkan setara. Ganti di sini kalau harga sebenarnya sudah diketahui.
const ODDS_PARITAS = 1.80;
// Nilai stake tetap yang ditampilkan pada log taruhan.
const STAKE_RUPIAH = 20000;

$mulaiTeks = (string)($_GET['mulai'] ?? TES_MULAI_DEFAULT);
$mulaiDt = DateTime::createFromFormat('d/m/Y', $mulaiTeks);
if (!$mulaiDt || $mulaiDt->format('d/m/Y') !== $mulaiTeks) {
    $mulaiTeks = TES_MULAI_DEFAULT;
    $mulaiDt = DateTime::createFromFormat('d/m/Y', $mulaiTeks);
}
$mulaiTs = $mulaiDt->setTime(0, 0)->getTimestamp();

/**
 * Kandidat yang sedang diuji. Target dan alasannya sengaja ditulis di sini
 * supaya terlihat bahwa keduanya ditetapkan sebelum datanya ada.
 */
$KANDIDAT = [
    // ---- V-Soccer. Temuan yang paling kuat sejauh ini bukan sebuah aturan,
    // melainkan arah: memasang Over di V-Soccer rugi -20,4% dengan t -4,61,
    // satu-satunya angka yang lolos ambang Bonferroni di seluruh monitor.
    // Kandidat di bawah semuanya memasang Under; yang diuji adalah KAPAN.
    [
        'kode' => 'VS-HT34', 'pasar' => 'vsoccer', 'durasi' => null, 'target' => 200,
        'label' => 'V-Soccer: total gol HT 3–4 → Under',
        'pick' => static fn(array $r) => ($r['ht_total'] >= 3 && $r['ht_total'] <= 4) ? 'under' : null,
        'alasan' => 'Kandidat terkuat yang pernah ditemukan: t 3,02 dan positif di ketiga hari '
            . '(+13% / +35% / +8%). Sel yang dibuang besar dan netral (170 match, −4,6% ≈ sebesar '
            . 'potongan bandar saja), jadi keunggulannya benar-benar terkonsentrasi di HT 3–4, '
            . 'bukan hasil mengecilkan sampel. Mekanismenya masuk akal: saat babak pertama '
            . 'menghasilkan 3–4 gol, pasar menaikkan line seolah tempo itu berlanjut. '
            . 'CATATAN JUJUR: batas 3–4 dipilih SETELAH melihat sel mana yang bagus, jadi +19,4% '
            . 'hampir pasti menyusut pada data baru.',
    ],
    [
        'kode' => 'VS-HT34-ODD', 'pasar' => 'vsoccer', 'durasi' => null, 'target' => 100,
        'label' => 'V-Soccer: HT 3–4 + odds Under ≥ 1,90 → Under',
        'pick' => static fn(array $r) => ($r['ht_total'] >= 3 && $r['ht_total'] <= 4
            && (float)$r['under'] >= 1.90) ? 'under' : null,
        'alasan' => 'Menguji pengamatan bahwa odds mendekati 2,00 lebih menguntungkan — odds Under '
            . 'tinggi berarti pasar sedang condong ke Over, dan di situlah biasnya paling kuat. '
            . 'ROI in-sample memang tertinggi (+29,1%), tapi sampelnya tinggal 66 dan hari ketiga '
            . 'sudah −18%. Diuji berdampingan dengan VS-HT34 untuk menjawab satu pertanyaan: '
            . 'apakah saringan odds menambah nilai, atau cuma membuang data?',
    ],
    [
        'kode' => 'VS-UNDER', 'pasar' => 'vsoccer', 'durasi' => null, 'target' => 300,
        'label' => 'V-Soccer: KONTROL — selalu Under',
        'pick' => static fn(array $r) => 'under',
        'alasan' => 'Kontrol tanpa logika apa pun. Kalau VS-HT34 tidak jelas mengalahkan baris ini, '
            . 'seluruh seleksi HT 3–4 tidak menambah nilai dan cukup pasang Under ke semua match.',
    ],
    [
        'kode' => 'R12', 'pasar' => 'vsoccer', 'durasi' => null, 'target' => 200,
        'alasan' => 'Pembanding sempit untuk VS-HT34 (R12 = HT tepat 3 saja). Keunggulannya '
            . 'bertumpu pada satu hari: +6% / +46% / −22%. Kalau VS-HT34 lolos sementara R12 '
            . 'tidak, berarti sel HT 4 memang menyumbang dan bukan pengganggu.',
    ],
    // ---- Ganjil/genap. CSV tidak menyimpan odds Odd/Even, jadi yang diuji
    // BUKAN untung-rugi melainkan "lebih baik dari lempar koin". Ambangnya pun
    // pada akurasi, bukan ROI. Untung tetap bergantung harga yang tidak kita punya.
    [
        'kode' => 'PAR-SABA15', 'pasar' => 'saba', 'durasi' => '15', 'target' => 200,
        'jenis' => 'paritas',
        'fixed_odds' => ODDS_PARITAS,
        'label' => 'SABA 15m: ikut paritas HT (genap→genap, ganjil→ganjil)',
        'predict' => static fn(array $r) => ((int)$r['ht_total']) % 2 === 0 ? 'even' : 'odd',
        'alasan' => 'Satu-satunya aturan yang mekanismenya pasti secara matematis. Babak SABA 15 '
            . 'menit hanya menghasilkan ~0,92 gol, dan untuk sebaran Poisson dengan rata-rata itu '
            . 'peluang jumlah genap = 57,9%. Terukur 58,6% — teori dan data cocok. Odds minimal '
            . 'agar untung: 1,71.',
    ],
    [
        'kode' => 'PAR-VS', 'pasar' => 'vsoccer', 'durasi' => null, 'target' => 300,
        'jenis' => 'paritas',
        'fixed_odds' => ODDS_PARITAS,
        'label' => 'V-Soccer: ikut paritas HT',
        'predict' => static fn(array $r) => ((int)$r['ht_total']) % 2 === 0 ? 'even' : 'odd',
        'alasan' => 'Kontrol teori. Babak kedua V-Soccer menghasilkan ~3,1 gol sehingga peluang '
            . 'genap hanya 50,1% — praktis lempar koin. Baris ini HARUS gagal. Kalau ia justru '
            . 'lolos, berarti pemahaman kita tentang mekanismenya salah dan PAR-SABA15 pun '
            . 'patut dicurigai.',
    ],
    [
        'kode' => 'R2C-VS', 'pasar' => 'vsoccer', 'durasi' => null, 'target' => 300,
        'jenis' => 'paritas',
        'fixed_odds' => ODDS_PARITAS,
        'label' => 'V-Soccer: HT ≥ 4 → tebak genap; HT ≤ 3 → tebak ganjil',
        'predict' => static fn(array $r) => $r['ht_total'] >= 4 ? 'even' : 'odd',
        'alasan' => 'Tampak kuat (56,2% dari 413, cap YA) padahal matematikanya bilang paritas '
            . 'V-Soccer mustahil ditebak. Sumbernya: paritas gol babak kedua tampak berbeda per '
            . 'sel HT (chi-kuadrat 15,07 db=6, lewat 95% tapi tipis dan ditopang satu sel — HT 5 '
            . 'dengan n=39). Tidak ada mekanisme yang membuat total babak pertama mengubah '
            . 'paritas babak kedua. Diuji untuk membuktikan itu riak acak.',
    ],
    // ---- SABA
    [
        'kode' => 'R1A', 'durasi' => '15', 'target' => 200,
        'alasan' => 'Kedua kaki positif (Under +16,0% n=49, Over +15,0% n=68), kedua hari '
            . 'positif, sampel terbesar, dan mekanismenya masuk akal: hanya pasang saat line '
            . 'tinggal ≤1 gol di atas skor. Kandidat terkuat sejauh ini.',
    ],
    [
        'kode' => 'R2A', 'durasi' => '20', 'target' => 100,
        'alasan' => 'ROI tertinggi, tapi 84% taruhannya cuma kaki Under dan pembagi genap/ganjil '
            . 'tidak punya mekanisme. Sel HT 2 (+15,7%) merusak klaim paritasnya. Diuji justru '
            . 'karena besar kemungkinan gugur.',
    ],
    [
        'kode' => 'R2', 'durasi' => '20', 'target' => 100,
        'alasan' => 'Pembanding untuk R2A: kalau R2A memang menambah nilai di atas R2, R2A harus '
            . 'lolos sementara R2 tidak.',
    ],
    [
        'kode' => 'R2', 'durasi' => '15', 'target' => 200,
        'alasan' => 'Ambang ≥3 untuk liga 15 menit ini SAYA yang kalibrasi setelah melihat data, '
            . 'jadi wajib diuji terpisah dari varian 20 menit. In-sample hanya +1,3% dan pasca-kunci '
            . 'sudah −8,7% (tanpa hari terbaik −13,9%) — sejauh ini gagal, dan baris ini ada supaya '
            . 'kegagalan itu tercatat, bukan tersembunyi di balik angka gabungan semua durasi.',
    ],
    [
        'kode' => 'R2', 'durasi' => '16', 'target' => 100,
        'alasan' => 'Varian 16 menit dari kalibrasi yang sama. Volumenya paling kecil (~12 taruhan '
            . 'per hari), jadi paling lama terjawab. In-sample −5,2%, pasca-kunci −20,7%.',
    ],
    [
        'kode' => 'R10', 'durasi' => null, 'target' => 100,
        'alasan' => 'Satu-satunya aturan yang positif di ketiga durasi SABA (+11,3% / +28,9% / '
            . '+44,3%). Konsistensi lintas durasi lebih meyakinkan daripada ROI tinggi di satu tempat.',
    ],
];

/**
 * Baris untuk satu kandidat. V-Soccer selalu satu kumpulan; SABA dipisah per
 * durasi karena tempo 15m, 16m, dan 20m berbeda dan tidak boleh dicampur.
 */
function barisKandidat(array $k, array $vsoccer, array $sabaPerDurasi, array $sabaSemua): array
{
    if (($k['pasar'] ?? 'saba') === 'vsoccer') {
        return $vsoccer;
    }
    if (($k['durasi'] ?? null) === null) {
        return $sabaSemua;
    }
    return $sabaPerDurasi[$k['durasi']]['rows'] ?? [];
}

/** Log per taruhan supaya bisa dicocokkan satu per satu dengan taruhan nyata. */
function logTaruhan(array $rows, callable $pick): array
{
    $log = [];
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
        $d = settleRinci($r['ft'], $lineText, (float)$od, $side);
        if ($d === null) {
            continue;
        }
        $oddsFloat = (float)$od;
        $plUnit = (float)$d['pl'];
        $log[] = [
            'waktu' => $r['datetime'], 'match' => $r['home'] . ' v ' . $r['away'],
            'ht' => $r['ht'], 'line' => $lineText, 'mid' => $r['mid'],
            'sisi' => $side, 'odds' => $od, 'ft' => $r['ft'], 'pl' => $plUnit,
            'stake' => STAKE_RUPIAH, 'total' => STAKE_RUPIAH * $oddsFloat,
            'pnl_nominal' => STAKE_RUPIAH * $plUnit,
        ];
    }
    return $log;
}

/** Log tebakan ganjil/genap, dengan odds opsional untuk simulasi taruhan. */
function logParitas(array $rows, callable $predict, ?float $odds = null): array
{
    $log = [];
    foreach ($rows as $r) {
        $tebak = $predict($r);
        if ($tebak !== 'even' && $tebak !== 'odd') {
            continue;
        }
        $nyata = ((int)$r['ft'] % 2 === 0) ? 'even' : 'odd';
        $item = [
            'waktu' => $r['datetime'], 'match' => $r['home'] . ' v ' . $r['away'],
            'ht' => $r['ht'], 'ht_total' => (int)$r['ht_total'],
            'tebak' => $tebak, 'ft' => (int)$r['ft'], 'nyata' => $nyata,
            'benar' => $tebak === $nyata,
        ];
        if ($odds !== null) {
            $plUnit = $item['benar'] ? $odds - 1 : -1;
            $item['odds'] = $odds;
            $item['stake'] = STAKE_RUPIAH;
            $item['total'] = STAKE_RUPIAH * $odds;
            $item['pl'] = $plUnit;
            $item['pnl_nominal'] = STAKE_RUPIAH * $plUnit;
        }
        $log[] = $item;
    }
    return $log;
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


$hasil = [];
foreach ($KANDIDAT as $k) {
    $pasar = $k['pasar'] ?? 'saba';
    // Kandidat boleh membawa pick sendiri (aturan baru yang belum ada di monitor),
    // atau merujuk kode aturan yang sudah dipakai halaman monitor.
    $daftar = $pasar === 'vsoccer' ? $RULES : $SABA_RULES;
    $paritas = ($k['jenis'] ?? '') === 'paritas';
    $fixedOdds = $paritas && isset($k['fixed_odds']) ? (float)$k['fixed_odds'] : null;
    $pick = $k['pick'] ?? ($k['predict'] ?? ($daftar[$k['kode']]['pick'] ?? null));
    $label = $k['label'] ?? ($daftar[$k['kode']]['label'] ?? $k['kode']);
    if (!$pick) {
        continue;
    }
    $semuaBaris = barisKandidat($k, $rows, $sabaPerDurasi, $sabaRows);
    $sebelum = array_values(array_filter($semuaBaris, static fn($r) => $r['ts'] < $mulaiTs));
    $sesudah = array_values(array_filter($semuaBaris, static fn($r) => $r['ts'] >= $mulaiTs));

    if ($paritas && $fixedOdds !== null) {
        $insample = $sebelum ? evaluateParityBet($sebelum, $pick, $fixedOdds) : null;
        $maju = $sesudah ? evaluateParityBet($sesudah, $pick, $fixedOdds) : null;
        $sd = $insample['sd'] ?? null;
        $ambang = $sd !== null && $k['target'] > 0
            ? Z_SATU_SISI * $sd / sqrt($k['target']) * 100
            : null;
        $capai = $maju['roi'] ?? null;
    } elseif ($paritas) {
        $insample = $sebelum ? evaluateParity($sebelum, $pick) : null;
        $maju = $sesudah ? evaluateParity($sesudah, $pick) : null;
        // Tanpa odds Odd/Even, untung-rugi tak terukur. Yang diuji: apakah
        // akurasinya benar-benar di atas lempar koin. Simpangan baku lempar
        // koin selalu 0,5, jadi ambangnya tidak butuh data in-sample sama
        // sekali -- murni ditentukan target, dan itu justru lebih kuat.
        $ambang = $k['target'] > 0 ? 50 + Z_SATU_SISI * 50 / sqrt($k['target']) : null;
        $capai = $maju['accuracy'] ?? null;
    } else {
        $insample = $sebelum ? evaluate($sebelum, $pick) : null;
        $maju = $sesudah ? evaluate($sesudah, $pick) : null;
        // Ambang dihitung dari sd in-sample dan target -- bukan dari hasil pasca-kunci.
        $sd = $insample['sd'] ?? null;
        $ambang = $sd !== null && $k['target'] > 0
            ? Z_SATU_SISI * $sd / sqrt($k['target']) * 100
            : null;
        $capai = $maju['roi'] ?? null;
    }

    $n = $maju['n'] ?? 0;
    // Ambang di atas dihitung untuk jumlah taruhan DI TARGET. Selama target
    // belum tercapai, membandingkan ROI sekarang dengan ambang itu tidak adil:
    // sampel yang lebih kecil berayun lebih lebar, jadi barnya seharusnya lebih
    // tinggi. Ambang setara ini memakai n yang benar-benar sudah terkumpul,
    // supaya tidak ada kandidat yang terlihat lulus lebih awal dari semestinya.
    // Skalanya harus mengikuti $capai: akurasi hanya untuk paritas tanpa odds,
    // selebihnya ROI -- termasuk paritas yang sudah punya fixed_odds.
    if ($n < 2) {
        $ambangKini = null;
    } elseif ($paritas && $fixedOdds === null) {
        $ambangKini = 50 + Z_SATU_SISI * 50 / sqrt($n);
    } else {
        $sdKini = $maju['sd'] ?? ($insample['sd'] ?? null);
        $ambangKini = $sdKini !== null ? Z_SATU_SISI * $sdKini / sqrt($n) * 100 : null;
    }

    if ($ambang === null) {
        // Tanpa data in-sample tidak ada sd, jadi tidak ada ambang yang sah.
        // Menyebutnya GUGUR di sini keliru -- yang benar: belum bisa dinilai.
        $status = 'AMBANG BELUM ADA';
        $statusKelas = 'no';
    } elseif ($n < $k['target']) {
        $status = 'BELUM CUKUP';
        $statusKelas = 'no';
    } elseif ($capai !== null && $capai >= $ambang) {
        $status = 'LOLOS';
        $statusKelas = 'yes';
    } else {
        $status = 'GUGUR';
        $statusKelas = 'no';
    }

    $hasil[] = [
        'k' => $k, 'label' => $label, 'insample' => $insample, 'maju' => $maju,
        'ambang' => $ambang, 'ambangKini' => $ambangKini,
        'status' => $status, 'kelas' => $statusKelas,
        'paritas' => $paritas && $fixedOdds === null,
        'parity_bet' => $paritas && $fixedOdds !== null, 'fixed_odds' => $fixedOdds, 'capai' => $capai,
        'log' => $paritas ? logParitas($sesudah, $pick, $fixedOdds) : logTaruhan($sesudah, $pick),
    ];
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tes Maju — Aturan Babak Kedua</title>
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
.kand{margin-top:22px;padding:16px;background:var(--card);border:1px solid var(--border);border-radius:12px}
.kand h2{margin:0 0 2px;font-size:16px}
.kand .rule{color:var(--muted);font-size:12px;margin:0 0 10px}
.kand .why{color:var(--muted);font-size:12px;margin:10px 0 0;padding-left:10px;border-left:2px solid var(--border)}
.metrics{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-top:10px}
@media(max-width:900px){.metrics{grid-template-columns:repeat(2,1fr)}}
.m{padding:10px;background:#10161e;border:1px solid var(--border);border-radius:8px}
.m .label{color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.06em}
.m .value{font:700 18px/1.3 ui-monospace,Consolas,monospace;margin-top:3px}
.bar{height:7px;background:#10161e;border:1px solid var(--border);border-radius:5px;overflow:hidden;margin-top:10px}
.bar i{display:block;height:100%;background:var(--blue)}
.tablebox{overflow:auto;border:1px solid var(--border);border-radius:10px;margin-top:12px}
table{width:100%;border-collapse:collapse;white-space:nowrap;background:#10161e}
th,td{padding:7px 10px;border-bottom:1px solid #222c38;text-align:left}
th{color:var(--muted);font-size:11px;text-transform:uppercase}
.num{text-align:right;font-variant-numeric:tabular-nums}
.pos{color:var(--green);font-weight:700}.neg{color:var(--red);font-weight:700}
.tag{display:inline-block;font-size:10px;font-weight:800;letter-spacing:.06em;padding:2px 6px;border-radius:4px}
.tag.no{background:#3a1f27;color:var(--red)}.tag.yes{background:#1d3d2a;color:var(--green)}
.muted{color:var(--muted)}
.empty{padding:14px;color:var(--muted);font-size:13px}
.foot{color:var(--muted);font-size:12px;margin-top:22px}
</style>
</head>
<body>
<main class="wrap">
  <div class="top">
    <div>
      <h1>Tes Maju</h1>
      <p class="sub">Aturan diukur <b>hanya</b> pada match sejak <?= e($mulaiTeks) ?> — data yang belum pernah dipakai menyusun aturannya.</p>
    </div>
    <div class="actions">
      <a class="btn" href="market-2h-monitor.php?pasar=saba">← Monitor</a>
      <a class="btn" href="javascript:location.reload()">↻ Refresh</a>
    </div>
  </div>


  <?php if (!$sabaRows): ?>
    <div class="empty card">Belum ada data SABA yang bisa dipakai (<?= e($sabaError ?? 'goal_log_bpvm.csv kosong') ?>).</div>
  <?php endif; ?>

  <?php
  // Kandidat gugur dikeluarkan dari daftar utama supaya halaman tidak penuh
  // baris mati, tapi TIDAK dihapus -- diringkas jadi satu baris di bawah.
  // Menghapusnya sama saja menyisakan hanya yang sedang bagus, dan itu bentuk
  // penipuan diri yang justru ingin dicegah halaman ini.
  $aktif = array_values(array_filter($hasil, static fn($h) => $h['status'] !== 'GUGUR'));
  $gugur = array_values(array_filter($hasil, static fn($h) => $h['status'] === 'GUGUR'));
  ?>
  <?php foreach ($aktif as $h):
      $k = $h['k'];
      $maju = $h['maju'];
      $n = $maju['n'] ?? 0;
      $persen = $k['target'] > 0 ? min(100, 100 * $n / $k['target']) : 0; ?>
    <?php
      $nominalStake = 0.0;
      $nominalTotal = 0.0;
      $nominalPnl = 0.0;
      if (!$h['paritas'] || $h['fixed_odds'] !== null) {
          foreach ($h['log'] as $b) {
              $nominalStake += (float)($b['stake'] ?? STAKE_RUPIAH);
              $nominalTotal += (float)($b['total'] ?? 0);
              $nominalPnl += (float)($b['pnl_nominal'] ?? 0);
          }
      }
    ?>
    <section class="kand">
      <h2><?= e($k['kode']) ?> · <?= ($k['pasar'] ?? 'saba') === 'vsoccer'
            ? 'V-Soccer'
            : 'SABA ' . ($k['durasi'] === null ? 'semua durasi' : e($k['durasi']) . ' menit') ?>
        <span class="tag <?= $h['kelas'] ?>" style="margin-left:6px"><?= $h['status'] ?></span></h2>
      <p class="rule"><?= e($h['label']) ?></p>
      <?php if ($h['fixed_odds'] !== null): ?>
        <p class="rule">Odds asumsi: <?= number_format($h['fixed_odds'], 2, ',', '.') ?></p>
      <?php endif; ?>

      <div class="metrics">
        <div class="m">
          <div class="label">Taruhan terkumpul</div>
          <div class="value"><?= $n ?> <span class="muted" style="font-size:13px">/ <?= $k['target'] ?></span></div>
        </div>
        <div class="m">
          <div class="label"><?= $h['paritas'] ? 'Akurasi pasca-kunci' : 'ROI pasca-kunci' ?></div>
          <div class="value <?= $h['capai'] === null ? '' : (($h['capai'] >= ($h['ambang'] ?? 0)) ? 'pos' : 'neg') ?>">
            <?= $h['capai'] === null ? '–' : ($h['paritas'] ? pct($h['capai']) : signed($h['capai'])) ?></div>
        </div>
        <div class="m">
          <div class="label">Ambang lolos <span class="muted">(di <?= $k['target'] ?> taruhan)</span></div>
          <div class="value"><?= $h['ambang'] === null
              ? '–' : ($h['paritas'] ? pct($h['ambang']) : signed($h['ambang'])) ?></div>
        </div>
        <div class="m">
          <div class="label">Ambang setara <span class="muted">(pada n sekarang)</span></div>
          <div class="value <?= $h['ambangKini'] === null || $h['capai'] === null ? ''
              : ($h['capai'] >= $h['ambangKini'] ? 'pos' : 'neg') ?>">
            <?= $h['ambangKini'] === null
                ? '–' : ($h['paritas'] ? pct($h['ambangKini']) : signed($h['ambangKini'])) ?></div>
        </div>
        <?php if (!$h['paritas']): ?>
        <div class="m">
          <div class="label">Total stake</div>
          <div class="value">Rp<?= number_format($nominalStake, 0, ',', '.') ?></div>
        </div>
        <div class="m">
          <div class="label">Total kotor (stake x odd)</div>
          <div class="value">Rp<?= number_format($nominalTotal, 0, ',', '.') ?></div>
        </div>
        <div class="m">
          <div class="label">P/L nominal</div>
          <div class="value <?= $nominalPnl >= 0 ? 'pos' : 'neg' ?>">
            <?= $nominalPnl >= 0 ? '+' : '-' ?>Rp<?= number_format(abs($nominalPnl), 0, ',', '.') ?></div>
        </div>
        <?php endif; ?>
        <div class="m">
          <div class="label"><?= $h['paritas'] ? 'Odds minimal agar untung' : 'Menang (tanpa push)' ?></div>
          <div class="value"><?php if ($h['paritas']) {
              echo $maju && $maju['odds_min'] !== null ? number_format($maju['odds_min'], 2, ',', '.') : '–';
          } else {
              echo $maju ? pct($maju['winrate']) : '–';
          } ?></div>
        </div>
        <div class="m">
          <div class="label">In-sample <span class="muted">(pembanding)</span></div>
          <div class="value muted" style="font-size:15px">
            <?php if (!$h['insample']) {
                echo '–';
            } elseif ($h['paritas']) {
                echo pct($h['insample']['accuracy']) . ' · n=' . $h['insample']['n'];
            } else {
                echo signed($h['insample']['roi']) . ' · n=' . $h['insample']['n'];
            } ?></div>
        </div>
      </div>
      <div class="bar"><i style="width:<?= number_format($persen, 1, '.', '') ?>%"></i></div>

      <p class="why"><b>Kenapa diuji:</b> <?= e($k['alasan']) ?>
        <?php if ($h['ambang'] !== null && $h['paritas']): ?>
          <br><b>Asal ambang:</b> lempar koin punya simpangan baku 0,5, jadi ambang =
          50% + 1,645 × 50% ÷ √<?= $k['target'] ?> = <?= pct($h['ambang']) ?>.
          Ambang ini tidak memakai data in-sample sama sekali.
          <br><b>Perhatikan:</b> ini uji "lebih baik dari lempar koin", <b>bukan</b> uji untung.
          CSV tidak menyimpan odds Odd/Even, jadi untung-rugi tetap bergantung pada harga
          yang belum kita punya — lihat kolom odds minimal.
        <?php elseif ($h['ambang'] !== null && $h['insample']): ?>
          <br><b>Asal ambang:</b> sd in-sample <?= number_format($h['insample']['sd'], 3, ',', '.') ?>
          ÷ √<?= $k['target'] ?> × 1,645 = <?= signed($h['ambang']) ?>.
        <?php endif; ?>
      </p>

      <?php if (!$h['log']): ?>
        <div class="empty">Belum ada match yang memenuhi syarat sejak <?= e($mulaiTeks) ?>.
          Halaman ini akan terisi sendiri begitu data baru masuk.</div>
      <?php else: ?>
        <details class="acc-history" style="margin-top:12px">
          <summary style="cursor:pointer;padding:8px 12px;background:#10161e;border:1px solid var(--border);border-radius:8px;font-weight:600;color:var(--text);user-select:none">
            📋 Riwayat Taruhan (<?= count($h['log']) ?> match) — Klik untuk membuka/menutup
          </summary>
          <?php if ($h['paritas'] && $h['fixed_odds'] === null): ?>
            <div class="tablebox" style="margin-top:8px"><table>
              <thead><tr>
                <th>Waktu</th><th>Match</th><th>HT</th><th class="num">Total HT</th>
                <th>Tebakan</th><th class="num">FT</th><th>Nyata</th><th>Hasil</th>
              </tr></thead>
              <tbody>
              <?php foreach ($h['log'] as $b): ?>
                <tr>
                  <td><?= e($b['waktu']) ?></td>
                  <td><?= e($b['match']) ?></td>
                  <td><?= e($b['ht']) ?></td>
                  <td class="num"><?= $b['ht_total'] ?></td>
                  <td><?= $b['tebak'] === 'even' ? 'genap' : 'ganjil' ?></td>
                  <td class="num"><?= $b['ft'] ?></td>
                  <td><?= $b['nyata'] === 'even' ? 'genap' : 'ganjil' ?></td>
                  <td class="<?= $b['benar'] ? 'pos' : 'neg' ?>"><?= $b['benar'] ? 'benar' : 'salah' ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
          <?php elseif ($h['parity_bet']): ?>
            <div class="tablebox" style="margin-top:8px"><table>
              <thead><tr>
                <th>Waktu</th><th>Match</th><th>HT</th><th class="num">Total HT</th>
                <th>Tebakan</th><th class="num">Odds</th><th class="num">Stake</th>
                <th class="num">Total (stake x odd)</th><th class="num">FT</th><th>Nyata</th><th class="num">P/L (Rp)</th>
              </tr></thead>
              <tbody>
              <?php foreach ($h['log'] as $b): ?>
                <tr>
                  <td><?= e($b['waktu']) ?></td>
                  <td><?= e($b['match']) ?></td>
                  <td><?= e($b['ht']) ?></td>
                  <td class="num"><?= $b['ht_total'] ?></td>
                  <td><?= $b['tebak'] === 'even' ? 'genap' : 'ganjil' ?></td>
                  <td class="num"><?= number_format($b['odds'], 2, ',', '.') ?></td>
                  <td class="num">Rp<?= number_format($b['stake'], 0, ',', '.') ?></td>
                  <td class="num">Rp<?= number_format($b['total'], 0, ',', '.') ?></td>
                  <td class="num"><?= $b['ft'] ?></td>
                  <td><?= $b['nyata'] === 'even' ? 'genap' : 'ganjil' ?></td>
                  <td class="num <?= $b['pnl_nominal'] >= 0 ? 'pos' : 'neg' ?>"><?= $b['pnl_nominal'] >= 0 ? '+' : '-' ?>Rp<?= number_format(abs($b['pnl_nominal']), 0, ',', '.') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
          <?php else: ?>
            <div class="tablebox" style="margin-top:8px"><table>
              <thead><tr>
                <th>Waktu</th><th>Match</th><th>HT</th><th>Line</th><th class="num">Mid</th>
                <th>Sisi</th><th class="num">Odds</th><th class="num">FT</th><th class="num">P/L (unit)</th><th class="num">Stake</th><th class="num">Total (stake x odd)</th><th class="num">P/L (Rp)</th>
              </tr></thead>
              <tbody>
              <?php foreach ($h['log'] as $b): ?>
                <tr>
                  <td><?= e($b['waktu']) ?></td>
                  <td><?= e($b['match']) ?></td>
                  <td><?= e($b['ht']) ?></td>
                  <td><?= e($b['line']) ?></td>
                  <td class="num"><?= number_format($b['mid'], 2, ',', '.') ?></td>
                  <td><?= $b['sisi'] === 'over' ? 'Over' : 'Under' ?></td>
                  <td class="num"><?= number_format((float)$b['odds'], 2, ',', '.') ?></td>
                  <td class="num"><?= (int)$b['ft'] ?></td>
                  <td class="num <?= $b['pl'] > 0 ? 'pos' : ($b['pl'] < 0 ? 'neg' : 'muted') ?>">
                    <?= abs($b['pl']) < 1e-9
                          ? 'push'
                          : ($b['pl'] > 0 ? '+' : '−') . number_format(abs($b['pl']), 2, ',', '.') ?></td>
                  <td class="num">Rp<?= number_format($b['stake'], 0, ',', '.') ?></td>
                  <td class="num">Rp<?= number_format($b['total'], 0, ',', '.') ?></td>
                  <td class="num <?= $b['pnl_nominal'] > 0 ? 'pos' : ($b['pnl_nominal'] < 0 ? 'neg' : 'muted') ?>"><?= $b['pnl_nominal'] > 0 ? '+' : ($b['pnl_nominal'] < 0 ? '-' : '') ?>Rp<?= number_format(abs($b['pnl_nominal']), 0, ',', '.') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
          <?php endif; ?>
        </details>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <?php if ($gugur): ?>
  <section class="kand" style="border-color:#3a1f27">
    <h2>Sudah gugur <span class="muted" style="font-weight:400;font-size:13px">(<?= count($gugur) ?> kandidat)</span></h2>
    <p class="rule">Sudah mencapai target taruhan tetapi tidak menembus ambang yang dikunci di muka.
      Sengaja tidak dihapus: daftar yang hanya memuat kandidat bagus akan membuat seluruh halaman ini
      terlihat jauh lebih meyakinkan daripada kenyataannya.</p>
    <div class="tablebox"><table>
      <thead><tr>
        <th>Kode</th><th>Aturan</th><th class="num">n</th><th class="num">Hasil</th>
        <th class="num">Ambang</th><th class="num">Selisih</th>
      </tr></thead>
      <tbody>
      <?php foreach ($gugur as $h):
          $kurang = $h['capai'] !== null && $h['ambang'] !== null ? $h['capai'] - $h['ambang'] : null; ?>
        <tr>
          <td><b><?= e($h['k']['kode']) ?></b></td>
          <td><?= e($h['label']) ?></td>
          <td class="num"><?= $h['maju']['n'] ?? 0 ?> / <?= $h['k']['target'] ?></td>
          <td class="num"><?= $h['capai'] === null ? '–'
              : ($h['paritas'] ? pct($h['capai']) : signed($h['capai'])) ?></td>
          <td class="num"><?= $h['ambang'] === null ? '–'
              : ($h['paritas'] ? pct($h['ambang']) : signed($h['ambang'])) ?></td>
          <td class="num neg"><?= $kurang === null ? '–' : signed($kurang) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <?php endif; ?>

  <p class="foot">
    Baca-saja · sumber <code>goal_log_bpvm.csv</code> · definisi aturan, loader, dan settlement
    dipakai bersama <code>market-2h-monitor.php</code> lewat <code>market-lib.php</code>, jadi
    tidak mungkin melenceng satu sama lain · ganti awal periode dengan <code>?mulai=dd/mm/yyyy</code>.
  </p>
</main>
</body>
</html>
