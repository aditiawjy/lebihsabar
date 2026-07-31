/**
 * streak100.js — logika bersama tabel "Peluang 100%".
 *
 * SATU sumber kebenaran: dipakai oleh streak-analysis.php (tabel penuh) dan
 * vsoccer-live.php (badge per match). Jangan menyalin ulang aturan di tempat
 * lain — kalau ambang berubah, cukup ubah di file ini.
 *
 * Isi: tabel mode/hasil + helper (pick, curOf, wilsonLB, dst) yang dipindah
 * apa adanya dari streak-analysis.php, plus compute100() yang memuat aturan
 * penyaringan baris 100% (dulu berada di dalam render100()).
 */
(function (global) {
    'use strict';

        function wilsonLB(rate, n) {
            if (rate === null || rate === undefined || !n || n <= 0) return null;
            const z = 1.96, p = rate / 100;
            const lb = (p + z*z/(2*n) - z*Math.sqrt(p*(1-p)/n + z*z/(4*n*n))) / (1 + z*z/n);
            return Math.round(lb * 1000) / 10;
        }
        // --- Kontrol overfitting / multiple-testing --------------------------
        // Puluhan mode/kombinasi diuji terhadap data yang sama, jadi rate mentah
        // "100%" pada sampel kecil sangat mungkin muncul kebetulan (p-hacking).
        // Hanya 3 mode cm_ yang lolos uji out-of-sample; sisanya + kombinasi
        // orde-tinggi (c4_/c5_) diperlakukan sebagai EKSPERIMENTAL dan dikenai
        // syarat sampel & keandalan (Wilson lower bound) yang jauh lebih ketat.
        const VALIDATED_MODES = new Set(['cm_u05evfts']);
        function isMinedMode(mk) { return mk.indexOf('cm_') === 0 && !VALIDATED_MODES.has(mk); }
        function isHiOrder(mk) { return mk.indexOf('c4_') === 0 || mk.indexOf('c5_') === 0; }
        function isExperimental(mk) { return isMinedMode(mk) || isHiOrder(mk); }
        // Ambang untuk mode eksperimental: n minimal + Wilson LB minimal.
        const EXP_MIN_N = 30, EXP_MIN_LB = 75;
        // Mode yang butuh data babak-1 (FHG/SHG/HT). V-Soccer tak punya skor babak-1 → invalid.
        const FH_MODES = new Set(['nfhg2', 'nfhg3', 'nshg2', 'nshg3', 'htodd3', 'hteven3', 'shg3', 'fhg3', 'nfo3', 'dry2o']);
        function modeUsesFH(mk) {
            if (FH_MODES.has(mk)) return true;
            const c = COMBO_DEFS[mk];
            return c ? c.some(x => FH_MODES.has(x)) : false;
        }
        // Hasil (outcome) yang butuh data babak-1: SHG O0.5.
        const FH_OUTS = new Set(['shg', 'fhg']);
        function isVsoccer(lg) { return lg.indexOf('V-Soccer') !== -1; }
        // ambil data sesuai mode + hasil (o15/o05): m[mode] = [over15, over05, sampel]
        function pick(r, mode, out) {
            const a = (r.m && r.m[mode]) ? r.m[mode] : [null, null, 0, null, null, null, null, null];
            // Paket Under/Over turunan: komplemen dari slot yang sudah ada (null-safe).
            const comp = (v) => v === null || v === undefined ? null : Math.round((100 - v) * 10) / 10;
            // Double chance turunan: DC 1X = HW% + Draw%, DC X2 = AW% + Draw% (sampel sama, clamp 100).
            if (out === 'dc1x' || out === 'dcx2') {
                const w = out === 'dc1x' ? a[22] : a[23];
                const dc = (w === null || w === undefined || a[10] === null || a[10] === undefined) ? null : Math.min(100, Math.round((w + a[10]) * 10) / 10);
                return { over: dc, samp: a[2] };
            }
            if (out === 'o45') return { over: a[26] ?? null, samp: a[2] }; // Over 4.5 (tot>=5)
            if (out === 'o55') return { over: a[27] ?? null, samp: a[2] }; // Over 5.5 (tot>=6)
            if (out === 'o65') return { over: a[17] ?? null, samp: a[2] }; // Over 6.5 (tot>=7) = slot tg7
            if (out === 'o75') return { over: a[28] ?? null, samp: a[2] }; // Over 7.5 (tot>=8)
            const over = out === 'o05' ? a[1] : (out === 'shg' ? (a[3] ?? null) : (out === 'fhg' ? (a[4] ?? null) : (out === 'u25' ? (a[5] ?? null) : (out === 'o25' ? (a[6] ?? null) : (out === 'u35' ? (a[7] ?? null) : (out === 'btts' ? (a[8] ?? null) : (out === 'nbtts' ? (a[9] ?? null) : (out === 'draw' ? (a[10] ?? null) : (out === 'nodraw' ? (a[11] ?? null) : (out === 'hg05' ? (a[12] ?? null) : (out === 'ag05' ? (a[13] ?? null) : (out === 'tg01' ? (a[14] ?? null) : (out === 'tg23' ? (a[15] ?? null) : (out === 'tg46' ? (a[16] ?? null) : (out === 'tg7' ? (a[17] ?? null) : (out === 'eg1' ? (a[18] ?? null) : (out === 'eg2' ? (a[19] ?? null) : (out === 'eg3' ? (a[20] ?? null) : (out === 'eg4' ? (a[21] ?? null) : (out === 'hw' ? (a[22] ?? null) : (out === 'aw' ? (a[23] ?? null) : (out === 'ftodd' ? (a[24] ?? null) : (out === 'fteven' ? (a[25] ?? null) : (out === 'u15' ? comp(a[0]) : (out === 'u05' ? comp(a[1]) : (out === 'o35' ? comp(a[7] ?? null) : a[0]))))))))))))))))))))))))));
            return { over: over, samp: a[2] };
        }
        const MIN_SAMP = { '3': 8, '4': 5, '05_3': 5, '05_1': 20, '05_2': 8, 'kl_1': 40, 'kl_2': 20, 'kl_3': 10, 'mn_2': 20, 'mn_3': 10, 'dr_2': 12, 'dr_3': 8, 'od_4': 10, 'ev_4': 10, 'od_5': 8, 'ev_5': 8, 'o25s2': 20, 'o15s3': 20, 'o25s3': 15, 'nbtts3': 15, 'nfhg2': 15, 'nshg2': 15, 'nfhg3': 10, 'nshg3': 10, 'od4u': 10, 'ev4u': 10, 'btts2': 20, 'btts3': 15, 'nbtts2': 15, 'cs2': 10, 'fts2': 10, 'htodd3': 15, 'hteven3': 15, 'u15oe3': 8, 'dry2o': 5, 'btso3': 10, 'kncs3': 10, 'nfo3': 10, 'u15_5': 4, 'u15_6': 3, '05_4': 3, 'kl_4': 6, 'kl_5': 4, 'mn_4': 6, 'mn_5': 4, 'dr_4': 4, 'o15s4': 12, 'o15s5': 8, 'o25s4': 8, 'o25s5': 5, 'btts4': 8, 'nbtts4': 8, 'od_6': 5, 'ev_6': 5, 'shg3': 15, 'fhg3': 15, 'u35_4': 10, 'c_mn3op80': 8, 'c_mn3op80h': 6, 'c_both80': 10, 'c_both85': 8, 'c_b2560': 10, 'c_b2565': 8 };
        const MODE_TEXT = { '3': 'U1.5 3x', '4': 'U1.5 4x', '05_3': 'U0.5 3x', '05_1': 'U0.5 1x', '05_2': 'U0.5 2x', 'kl_1': 'Kalah 1x', 'kl_2': 'Kalah 2x', 'kl_3': 'Kalah 3x', 'mn_2': 'Menang 2x', 'mn_3': 'Menang 3x', 'dr_2': 'Draw 3x', 'dr_3': 'Draw 3x', 'od_4': 'Odd 4x', 'ev_4': 'Even 4x', 'od_5': 'Odd 5x', 'ev_5': 'Even 5x', 'ev_4': 'Even 4x', 'o25s2': 'O2.5 2x', 'o15s3': 'O1.5 3x', 'o25s3': 'O2.5 3x', 'nbtts3': 'NoBTTS 3x', 'nfhg2': 'NoFHG 2x', 'nshg2': 'NoSHG 2x', 'nfhg3': 'NoFHG 3x', 'nshg3': 'NoSHG 3x', 'od4u': 'Odd5x+2U1.5', 'ev4u': 'Even5x+2U1.5', 'u15oe3': 'U1.5 3x+1O2E', 'dry2o': 'Kering 3x', 'btso3': 'BTTS+O1.5 3x', 'kncs3': 'Kalah+Bobol 3x', 'nfo3': 'NoFHG+O0.5 3x', 'btts2': 'BTTS 2x', 'btts3': 'BTTS 3x', 'nbtts2': 'NoBTTS 2x', 'cs2': 'Cleansheet 3x', 'fts2': 'Gagal cetak 3x', 'htodd3': 'HT-Odd 3x', 'hteven3': 'HT-Even 3x', 'u15_5': 'U1.5 5x', 'u15_6': 'U1.5 6x', '05_4': 'U0.5 4x', 'kl_4': 'Kalah 4x', 'kl_5': 'Kalah 5x', 'mn_4': 'Menang 4x', 'mn_5': 'Menang 5x', 'dr_4': 'Draw 4x', 'o15s4': 'O1.5 4x', 'o15s5': 'O1.5 5x', 'o25s4': 'O2.5 4x', 'o25s5': 'O2.5 5x', 'btts4': 'BTTS 4x', 'nbtts4': 'NoBTTS 4x', 'od_6': 'Odd 6x', 'ev_6': 'Even 6x', 'shg3': 'SHG 3x', 'fhg3': 'FHG 3x', 'u35_4': 'U3.5 4x', 'c_mn3op80': 'Menang 3x + Lawan O1.5≥80%', 'c_mn3op80h': 'Menang 3x + Lawan O1.5≥80% + Kandang', 'c_both80': 'Kedua tim O1.5≥80%', 'c_both85': 'Kedua tim O1.5≥85%', 'c_b2560': 'Kedua tim O2.5≥60%', 'c_b2565': 'Kedua tim O2.5≥65%',
            // v62 — dimensi baru (gol tinggi & bucket total gol)
            'o45s3': 'O4.5 3x', 'o45s4': 'O4.5 4x', 'o55s3': 'O5.5 3x', 'o75s2': 'O7.5 2x',
            'tg46s3': 'Total 4-6 gol 3x', 'tg7s2': 'Total 7+ gol 2x', 'tg23s3': 'Total 2-3 gol 3x', 'tg01s3': 'Total 0-1 gol 3x',
            'eg3s2': 'Total pas 3 gol 2x', 'eg4s2': 'Total pas 4 gol 2x',
            'c_o45mn': 'O4.5 3x+Menang 2x', 'c_o45bt': 'O4.5 3x+BTTS 2x', 'c_o45cs': 'O4.5 3x+CS 2x',
            'c_o45kl': 'O4.5 3x+Kalah 2x', 'c_o55mn': 'O5.5 2x+Menang 2x',
            'c_tg46o25': 'Total 4-6 3x+O2.5 3x', 'c_tg7bt': 'Total 7+ 2x+BTTS 2x', 'c_dingin2': 'Total 0-1 2x+Gagal cetak 2x',
            // v63 — mining tervalidasi out-of-sample
            'tg7s3': 'Total 7+ gol 3x', 'c_tg7o55': 'Total 7+ 2x+O5.5 3x', 'c_klo55': 'Kalah 2x+O5.5 3x', 'c_bttg7': 'BTTS 3x+Total 7+ 3x' };
        const LOSS_MODE = { 'kl_1': 1, 'kl_2': 1, 'kl_3': 1, 'kl_4': 1, 'kl_5': 1 };
        // Mode kombinasi preset: key -> [mode dasar A, mode dasar B] (untuk curOf & label).
        const COMBO_DEFS = {
            'c_u15kl': ['3', 'kl_2'], 'c_u15nf': ['3', 'nfhg3'], 'c_klfts': ['kl_2', 'fts2'],
            'c_mno25': ['mn_2', 'o25s2'], 'c_mnbt': ['mn_2', 'btts2'], 'c_o15bt': ['o15s3', 'btts2'],
            'c_u05ns': ['05_2', 'nshg3'], 'c_dru15': ['dr_3', '3'], 'c_evu15': ['ev_4', '3'],
            'c_odo15': ['od_4', 'o15s3'], 'c_csmn': ['cs2', 'mn_2'], 'c_ftsnf': ['fts2', 'nfhg3'],
            // Kombinasi 2 kondisi — batch 2
            'c_klnb': ['kl_2', 'nbtts3'], 'c_mnnf': ['mn_2', 'nfhg3'],
            'c_drnb': ['dr_3', 'nbtts3'], 'c_dro25': ['dr_3', 'o25s2'],
            'c_u15ns': ['3', 'nshg3'], 'c_o25bt3': ['o25s3', 'btts3'],
            'c_csu15': ['cs2', '3'], 'c_kl3fts': ['kl_3', 'fts2'],
            'c_htou15': ['htodd3', '3'], 'c_hteo15': ['hteven3', 'o15s3'],
            // Kombinasi 3 kondisi
            'c3_u15klnf': ['3', 'kl_2', 'nfhg3'], 'c3_klftsnf': ['kl_2', 'fts2', 'nfhg3'],
            'c3_mnbto25': ['mn_2', 'btts2', 'o25s2'], 'c3_mncs': ['mn_2', 'cs2', 'o15s3'],
            'c3_u05krg': ['05_2', 'nshg3', 'nfhg3'], 'c3_o15bto25': ['o15s3', 'btts2', 'o25s2'],
            'c3_dru15nb': ['dr_3', '3', 'nbtts3'], 'c3_klbto25': ['kl_2', 'btts2', 'o25s2'],
            // Kombinasi 2 kondisi — batch 3
            'c_klo25': ['kl_2', 'o25s2'], 'c_mnu15': ['mn_2', '3'],
            'c_csnf': ['cs2', 'nfhg3'], 'c_kl3nb': ['kl_3', 'nbtts3'],
            'c_mn3o25': ['mn_3', 'o25s3'], 'c_odbt': ['od_4', 'btts2'],
            'c_evnb': ['ev_4', 'nbtts3'], 'c_dr3u15': ['dr_3', '3'],
            'c_nfns': ['nfhg3', 'nshg3'],
            // Kombinasi khusus U1.5 3x/4x
            'c_u15fts': ['3', 'fts2'], 'c_u15nb': ['3', 'nbtts3'], 'c_u15u35': ['3', 'u35_4'],
            'c_u154kl': ['4', 'kl_2'], 'c_u154nf': ['4', 'nfhg3'], 'c_u154ns': ['4', 'nshg3'],
            'c_u154nb': ['4', 'nbtts3'], 'c_u154fts': ['4', 'fts2'], 'c_u154cs': ['4', 'cs2'],
            'c3_u154klnf': ['4', 'kl_2', 'nfhg3'], 'c3_u154nbns': ['4', 'nbtts3', 'nshg3'],
            'c3_u154csnf': ['4', 'cs2', 'nfhg3'],
            'c4_u154gembok': ['4', 'cs2', 'nfhg3', 'nbtts3'],
            'c4_u154krisis': ['4', 'kl_2', 'fts2', 'nshg3'],            // Kombinasi 3 kondisi — batch 3
            'c3_csu15nf': ['cs2', '3', 'nfhg3'], 'c3_mno25bt3': ['mn_2', 'o25s3', 'btts3'],
            'c3_dru15ns': ['dr_3', '3', 'nshg3'], 'c3_klu15fts': ['kl_2', '3', 'fts2'],
            // Kombinasi 4 kondisi
            'c4_krisis': ['kl_2', 'fts2', 'nfhg3', '3'], 'c4_panas': ['mn_2', 'btts2', 'o25s2', 'o15s3'],
            'c4_gembok': ['cs2', '3', 'nfhg3', 'nbtts3'], 'c4_terpuruk': ['kl_3', 'fts2', 'nbtts3', '3'],
            'c4_badai': ['mn_3', 'o25s3', 'btts2', 'o15s3'],
            // Kombinasi 5 kondisi
            'c5_krisis': ['kl_2', 'fts2', 'nfhg3', 'nshg3', '3'],
            'c5_gembok': ['cs2', '3', 'nfhg3', 'nshg3', 'nbtts3'],
            'c5_badai': ['mn_2', 'btts2', 'o25s2', 'o15s3', 'od_4'],
            // Kombinasi hasil mining matches.csv (win rate tertinggi)
            'cm_shfhcs': ['shg3', 'fhg3', 'cs2'], 'cm_drnfbt': ['dr_3', 'nfhg3', 'btts2'],
            'cm_o15cshto': ['o15s3', 'cs2', 'htodd3'], 'cm_nsbthto': ['nshg3', 'btts2', 'htodd3'],
            'cm_odftshto': ['od_4', 'fts2', 'htodd3'], 'cm_o15o25cs': ['o15s3', 'o25s2', 'cs2'],
            'cm_nsftshto': ['nshg3', 'fts2', 'htodd3'], 'cm_u05evfts': ['05_2', 'ev_4', 'fts2'],
            'cm_o25ftsu35': ['o25s2', 'fts2', 'u35_4'], 'cm_o25nsu35': ['o25s2', 'nshg3', 'u35_4'],
            'cm_o25fhcs': ['o25s2', 'fhg3', 'cs2'], 'cm_o15cshte': ['o15s3', 'cs2', 'hteven3'],
            'cm_klodns': ['kl_3', 'od_4', 'nshg3'],
        };
        const STREAK_LEN = { '3': 3, '4': 4, '05_3': 3, '05_1': 1, '05_2': 2, 'kl_1': 1, 'kl_2': 2, 'kl_3': 3, 'mn_2': 2, 'mn_3': 3, 'dr_2': 2, 'dr_3': 3, 'od_4': 4, 'ev_4': 4, 'od_5': 5, 'ev_5': 5, 'o25s2': 2, 'o15s3': 3, 'o25s3': 3, 'nbtts3': 3, 'nfhg2': 2, 'nshg2': 2, 'nfhg3': 3, 'nshg3': 3, 'od4u': 5, 'ev4u': 5, 'btts2': 2, 'btts3': 3, 'nbtts2': 2, 'cs2': 3, 'fts2': 3, 'htodd3': 3, 'hteven3': 3, 'u15oe3': 3, 'dry2o': 3, 'btso3': 3, 'kncs3': 3, 'nfo3': 3, 'u15_5': 5, 'u15_6': 6, '05_4': 4, 'kl_4': 4, 'kl_5': 5, 'mn_4': 4, 'mn_5': 5, 'dr_4': 4, 'o15s4': 4, 'o15s5': 5, 'o25s4': 4, 'o25s5': 5, 'btts4': 4, 'nbtts4': 4, 'od_6': 6, 'ev_6': 6, 'shg3': 3, 'fhg3': 3, 'u35_4': 4, 'c_mn3op80': 1, 'c_mn3op80h': 1, 'c_both80': 1, 'c_both85': 1, 'c_b2560': 1, 'c_b2565': 1,
            // v62 — dimensi baru
            'o45s3': 3, 'o45s4': 4, 'o55s3': 3, 'o75s2': 2,
            'tg46s3': 3, 'tg7s2': 2, 'tg23s3': 3, 'tg01s3': 3, 'eg3s2': 2, 'eg4s2': 2,
            'c_o45mn': 1, 'c_o45bt': 1, 'c_o45cs': 1, 'c_o45kl': 1, 'c_o55mn': 1,
            'c_tg46o25': 1, 'c_tg7bt': 1, 'c_dingin2': 1,
            'tg7s3': 3, 'c_tg7o55': 1, 'c_klo55': 1, 'c_bttg7': 1 };
        // Registrasi metadata mode kombinasi — HARUS setelah deklarasi STREAK_LEN di atas.
        Object.keys(COMBO_DEFS).forEach(k => {
            MODE_TEXT[k] = COMBO_DEFS[k].map(x => MODE_TEXT[x]).join('+');
            MIN_SAMP[k] = COMBO_DEFS[k].length >= 4 ? 3 : (COMBO_DEFS[k].length >= 3 ? 4 : 5); // makin banyak kondisi makin langka
            STREAK_LEN[k] = 1; // curOf mode kombinasi = 1 bila SEMUA streak berjalan cukup
        });
        // streak "saat ini" yang relevan per mode
        function curOf(r, mode) {
            if (COMBO_DEFS[mode]) { // kombinasi: SEMUA streak berjalan harus sudah cukup panjang
                return COMBO_DEFS[mode].every(c => curOf(r, c) >= (STREAK_LEN[c] || 1)) ? 1 : 0;
            }
            // Menang 3x + syarat lawan Over1.5>=80% (dan kandang) di match berikutnya
            if (mode === 'c_mn3op80') return (r.curW >= 3 && r.oppOver !== null && r.oppOver >= 80) ? 1 : 0;
            if (mode === 'c_mn3op80h') return (r.curW >= 3 && r.oppOver !== null && r.oppOver >= 80 && r.nextHome === 1) ? 1 : 0;
            // Kedua tim subur: rate musim tim sendiri (100-base) & lawan berikutnya sama-sama tinggi
            if (mode === 'c_both80') return (r.oppOver !== null && r.oppOver >= 80 && r.base !== null && (100 - r.base) >= 80) ? 1 : 0;
            if (mode === 'c_both85') return (r.oppOver !== null && r.oppOver >= 85 && r.base !== null && (100 - r.base) >= 85) ? 1 : 0;
            // Kedua tim subur Over 2.5: rate musim O2.5 tim sendiri & lawan berikutnya sama-sama tinggi
            if (mode === 'c_b2560') return (r.oppO25 !== null && r.oppO25 >= 60 && r.selfO25 !== null && r.selfO25 >= 60) ? 1 : 0;
            if (mode === 'c_b2565') return (r.oppO25 !== null && r.oppO25 >= 65 && r.selfO25 !== null && r.selfO25 >= 65) ? 1 : 0;
            if (mode.indexOf('mn_') === 0) return r.curW; // streak menang berjalan (2x/3x)
            if (mode.indexOf('dr_') === 0) return r.curD; // streak draw berjalan (2x/3x)
            if (mode === 'od_4' || mode === 'od_5' || mode === 'od_6') return r.curO;           // streak odd berjalan
            if (mode === 'ev_4' || mode === 'ev_5' || mode === 'ev_6') return r.curE;           // streak even berjalan
            if (mode === 'o25s2' || mode === 'o25s3' || mode === 'o25s4' || mode === 'o25s5') return r.curO25; // streak Over 2.5 berjalan
            if (mode === 'o15s3' || mode === 'o15s4' || mode === 'o15s5') return r.curO15;        // streak Over 1.5 berjalan
            if (mode === 'btts4') return r.curBTTS;       // streak BTTS berjalan
            if (mode === 'nbtts4') return r.curNB;        // streak No BTTS berjalan
            if (mode === 'nbtts3') return r.curNB;        // streak No BTTS berjalan
            if (mode === 'nfhg2' || mode === 'nfhg3') return r.curNFHG; // streak No FHG berjalan
            if (mode === 'nshg2' || mode === 'nshg3') return r.curNSHG; // streak No SHG berjalan
            if (mode === 'u15oe3') return r.cur;          // current streak U1.5 (komponen utama kombinasi)
            if (mode === 'dry2o') return r.cur;           // kering total → pakai streak U1.5 berjalan
            if (mode === 'btso3') return r.curBTTS;       // BTTS berjalan
            if (mode === 'kncs3') return r.curL;          // kalah berjalan
            if (mode === 'nfo3') return r.curNFHG;        // No FHG berjalan
            if (mode === 'od4u') return r.curO;           // streak odd berjalan (4x + U1.5)
            if (mode === 'ev4u') return r.curE;           // streak even berjalan (4x + U1.5)
            if (mode === 'btts2' || mode === 'btts3') return r.curBTTS;
            if (mode === 'nbtts2') return r.curNB;
            if (mode === 'cs2') return r.curCS;
            if (mode === 'fts2') return r.curFTS;
            if (mode === 'shg3') return r.curSHG;         // streak SHG berjalan
            if (mode === 'fhg3') return r.curFHG;         // streak FHG berjalan
            if (mode === 'u35_4') return r.curU35;        // streak U3.5 berjalan
            // dimensi BARU (v62): gol tinggi & bucket total gol
            if (mode === 'o45s3' || mode === 'o45s4') return r.curO45;
            if (mode === 'o55s3') return r.curO55;
            if (mode === 'o75s2') return r.curO75;
            if (mode === 'tg46s3') return r.curTG46;
            if (mode === 'tg7s2') return r.curTG7;
            if (mode === 'tg23s3') return r.curTG23;
            if (mode === 'tg01s3') return r.curTG01;
            if (mode === 'eg3s2') return r.curEG3;
            if (mode === 'eg4s2') return r.curEG4;
            // kombinasi v62: semua komponen streak berjalan harus sudah cukup panjang
            if (mode === 'c_o45mn')   return (r.curO45 >= 3 && r.curW >= 2) ? 1 : 0;
            if (mode === 'c_o45bt')   return (r.curO45 >= 3 && r.curBTTS >= 2) ? 1 : 0;
            if (mode === 'c_o45cs')   return (r.curO45 >= 3 && r.curCS >= 2) ? 1 : 0;
            if (mode === 'c_o45kl')   return (r.curO45 >= 3 && r.curL >= 2) ? 1 : 0;
            if (mode === 'c_o55mn')   return (r.curO55 >= 2 && r.curW >= 2) ? 1 : 0;
            if (mode === 'c_tg46o25') return (r.curTG46 >= 3 && r.curO25 >= 3) ? 1 : 0;
            if (mode === 'c_tg7bt')   return (r.curTG7 >= 2 && r.curBTTS >= 2) ? 1 : 0;
            if (mode === 'c_dingin2') return (r.curTG01 >= 2 && r.curFTS >= 2) ? 1 : 0;
            // v63 — hasil mining tervalidasi out-of-sample
            if (mode === 'tg7s3')    return r.curTG7;
            if (mode === 'c_tg7o55') return (r.curTG7 >= 2 && r.curO55 >= 3) ? 1 : 0;
            if (mode === 'c_klo55')  return (r.curL >= 2 && r.curO55 >= 3) ? 1 : 0;
            if (mode === 'c_bttg7')  return (r.curBTTS >= 3 && r.curTG7 >= 3) ? 1 : 0;
            if (mode === 'htodd3') return r.curHTO;
            if (mode === 'hteven3') return r.curHTE;
            if (mode.indexOf('05') === 0) return r.curU;  // streak U0.5 berjalan
            if (LOSS_MODE[mode]) return r.curL;           // streak kalah berjalan
            return r.cur;                                  // streak U1.5 berjalan
        }
        const ALL_MODES_100 = ['3','4','05_2','05_3','kl_2','kl_3','mn_2','mn_3','dr_3','od_4','ev_4','od_5','ev_5','o25s2','o15s3','o25s3','nbtts3','nfhg3','nshg3','od4u','ev4u','u15oe3','dry2o','btso3','kncs3','nfo3','btts2','btts3','cs2','fts2','htodd3','hteven3','u15_5','u15_6','05_4','kl_4','kl_5','mn_4','mn_5','dr_4','o15s4','o15s5','o25s4','o25s5','btts4','nbtts4','od_6','ev_6','c_u15kl','c_u15nf','c_klfts','c_mno25','c_mnbt','c_o15bt','c_u05ns','c_dru15','c_evu15','c_odo15','c_csmn','c_ftsnf','c_klnb','c_mnnf','c_drnb','c_dro25','c_u15ns','c_o25bt3','c_csu15','c_kl3fts','c_htou15','c_hteo15','c3_u15klnf','c3_klftsnf','c3_mnbto25','c3_mncs','c3_u05krg','c3_o15bto25','c3_dru15nb','c3_klbto25','c_klo25','c_mnu15','c_csnf','c_kl3nb','c_mn3o25','c_odbt','c_evnb','c_dr3u15','c_nfns','c_u15fts','c_u15nb','c_u15u35','c_u154kl','c_u154nf','c_u154ns','c_u154nb','c_u154fts','c_u154cs','c3_u154klnf','c3_u154nbns','c3_u154csnf','c4_u154gembok','c4_u154krisis','c3_csu15nf','c3_mno25bt3','c3_dru15ns','c3_klu15fts','c4_krisis','c4_panas','c4_gembok','c4_terpuruk','c4_badai','c5_krisis','c5_gembok','c5_badai','shg3','fhg3','u35_4','cm_shfhcs','cm_drnfbt','cm_o15cshto','cm_nsbthto','cm_odftshto','cm_o15o25cs','cm_nsftshto','cm_u05evfts','cm_o25ftsu35','cm_o25nsu35','cm_o25fhcs','cm_o15cshte','cm_klodns','c_mn3op80','c_mn3op80h','c_both80','c_both85','c_b2560','c_b2565',
            // v62 — dimensi baru (gol tinggi & bucket total gol)
            'o45s3','o45s4','o55s3','o75s2','tg46s3','tg7s2','tg23s3','tg01s3','eg3s2','eg4s2',
            'c_o45mn','c_o45bt','c_o45cs','c_o45kl','c_o55mn','c_tg46o25','c_tg7bt','c_dingin2',
            'tg7s3','c_tg7o55','c_klo55','c_bttg7'];
        const OUTS_100 = [
            { k: 'o15', t: 'Over 1.5' }, { k: 'o05', t: 'Over 0.5' },
            { k: 'shg', t: 'SHG O0.5' },
            { k: 'o25', t: 'Over 2.5' }, { k: 'btts', t: 'BTTS' }, { k: 'nbtts', t: 'No BTTS' }, { k: 'draw', t: 'Draw' }, { k: 'nodraw', t: 'No Draw' },
            { k: 'hg05', t: 'Home O0.5' }, { k: 'ag05', t: 'Away O0.5' },
            { k: 'tg01', t: 'TG 0-1' }, { k: 'tg23', t: 'TG 2-3' }, { k: 'tg46', t: 'TG 4-6' },
            { k: 'eg1', t: 'Exact 1' }, { k: 'eg2', t: 'Exact 2' }, { k: 'eg3', t: 'Exact 3' }, { k: 'eg4', t: 'Exact 4' },
            { k: 'hw', t: 'Home Win' }, { k: 'aw', t: 'Away Win' },
            { k: 'dc1x', t: 'DC 1X' }, { k: 'dcx2', t: 'DC X2' },
            { k: 'ftodd', t: 'FT Ganjil' }, { k: 'fteven', t: 'FT Genap' },
            { k: 'u15', t: 'Under 1.5' }, { k: 'u05', t: 'Under 0.5' }, { k: 'o35', t: 'Over 3.5' },
            { k: 'o45', t: 'Over 4.5' }, { k: 'o55', t: 'Over 5.5' }, { k: 'o65', t: 'Over 6.5' }, { k: 'o75', t: 'Over 7.5' },
        ];

    // Baseline pembanding: liga dulu, baru global (baseline global mencampur SABA
    // & V-Soccer sehingga "± Base" pada baris V-Soccer melambung palsu).
    function baseFor(baseOut, baseOutLg, league, outKey) {
        const lg = baseOutLg && baseOutLg[league];
        if (lg && lg[outKey] !== undefined && lg[outKey] !== null) return lg[outKey];
        return (baseOut && baseOut[outKey] !== undefined) ? baseOut[outKey] : null;
    }

    /**
     * Saring baris "Peluang 100% / meleset maks 3x" dari payload streak.
     *
     * rows  : array baris tim (payload['rows'])
     * opts  : { baseOut, baseOutLg, requireCur, minN, outKeys, teams }
     *         requireCur -> streak berjalan harus sudah cukup panjang (default true)
     *         outKeys    -> batasi hasil yang dipakai, mis. ['o35','o45'] (default semua)
     *         teams      -> batasi ke nama tim tertentu (default semua)
     * Kembalikan array baris siap render, belum diurutkan.
     */
    function compute100(rows, opts) {
        const o = opts || {};
        const baseOut = o.baseOut || {};
        const baseOutLg = o.baseOutLg || {};
        const requireCur = o.requireCur !== false;
        const minN = o.minN || 0;
        const outAllow = o.outKeys ? new Set(o.outKeys) : null;
        const teamAllow = o.teams ? new Set(o.teams) : null;
        const outs = outAllow ? OUTS_100.filter(x => outAllow.has(x.k)) : OUTS_100;
        const hasil = [];

        (rows || []).forEach(r => {
            if (teamAllow && !teamAllow.has(r.t)) return;
            if (o.filterRow && !o.filterRow(r)) return;
            const vsoc = isVsoccer(r.l);
            ALL_MODES_100.forEach(mk => {
                // V-Soccer: mode berbasis babak-1 (FHG/SHG/HT) tak valid.
                if (vsoc && modeUsesFH(mk)) return;
                const cur = curOf(r, mk);
                const need = STREAK_LEN[mk] || 1;
                if (requireCur && cur < need) return;
                outs.forEach(out => {
                    // Liga V-Soccer: sembunyikan hasil yang tak berlaku di sana.
                    if (vsoc && (out.k === 'hg05' || out.k === 'ag05' || out.k === 'btts' || out.k === 'nbtts'
                        || out.k === 'o05' || out.k === 'o15' || out.k === 'o25' || out.k === 'nodraw'
                        || FH_OUTS.has(out.k))) return;
                    // Over 4.5 / 5.5 / 6.5 / 7.5 hanya untuk V-Soccer.
                    if (!vsoc && (out.k === 'o45' || out.k === 'o55' || out.k === 'o65' || out.k === 'o75')) return;
                    const p = pick(r, mk, out.k);
                    if (p.over === null) return;
                    if (minN > 0 && !(p.samp > minN)) return;
                    const hits = Math.round(p.over * p.samp / 100);
                    const miss = p.samp - hits;
                    const exp = isExperimental(mk);
                    // Mode eksperimental: sampel & Wilson LB minimal.
                    if (exp) {
                        if (p.samp < EXP_MIN_N) return;
                        const lbExp = wilsonLB(p.over, p.samp);
                        if (lbExp === null || lbExp < EXP_MIN_LB) return;
                    }
                    // Syarat mutlak: meleset 0x butuh sampel > 6; 1x > 100; 2x > 200; 3x > 300.
                    if (miss === 0 && p.samp < 6) return;
                    if (miss === 1 && p.samp <= 100) return;
                    if (miss === 2 && p.samp <= 200) return;
                    if (miss === 3 && p.samp <= 300) return;
                    if (miss > 3) return;
                    const base = baseFor(baseOut, baseOutLg, r.l, out.k);
                    hasil.push({
                        t: r.t, mk: MODE_TEXT[mk] || mk, mkKey: mk, exp: exp,
                        outT: out.t, outK: out.k, l: r.l, cur: cur,
                        over: p.over, samp: p.samp, hits: hits, miss: miss, base: base,
                        lift: base === null || p.over === null ? null : Math.round((p.over - base) * 10) / 10,
                        lb: wilsonLB(p.over, p.samp), next: r.next, nextMin: r.nextMin,
                    });
                });
            });
        });
        return hasil;
    }

    global.Streak100 = {
        compute100: compute100, baseFor: baseFor,
        pick: pick, curOf: curOf, wilsonLB: wilsonLB,
        isExperimental: isExperimental, modeUsesFH: modeUsesFH, isVsoccer: isVsoccer,
        MODE_TEXT: MODE_TEXT, STREAK_LEN: STREAK_LEN, MIN_SAMP: MIN_SAMP,
        COMBO_DEFS: COMBO_DEFS, LOSS_MODE: LOSS_MODE,
        ALL_MODES_100: ALL_MODES_100, OUTS_100: OUTS_100,
        FH_MODES: FH_MODES, FH_OUTS: FH_OUTS,
        EXP_MIN_N: EXP_MIN_N, EXP_MIN_LB: EXP_MIN_LB, VALIDATED_MODES: VALIDATED_MODES,
    };
})(typeof window !== 'undefined' ? window : globalThis);
