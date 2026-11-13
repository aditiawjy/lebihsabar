# Backtest League 16 — 3 minggu terakhir

Rentang sesi: **2026-09-26 sampai 2026-10-16** (21 hari sesi). Sesi SABA memakai UTC+8 dan berganti pada 12:00; pertandingan dini hari termasuk sesi sebelumnya. Riwayat sebelumnya tetap dipakai untuk memeriksa pemicu.

Sumber: matches.csv dan evaluator patternsaba.js. Ini evaluasi historis pada data yang tersedia, bukan uji out-of-sample. Satu pertandingan bisa masuk beberapa pola; L16-31 dihitung per pasangan pertandingan dalam satu slot. Sampel kecil harus dibaca bersama jumlah totalnya.

| Kode | Pola | WIN | FAIL | Total | Akurasi |
|---|---|---:|---:|---:|---:|
| L16-02 | Norway Recovery Kandang — pertandingan Norway sebelumnya di kandang, tanpa gol 2H | 7 | 0 | 7 | 100.0% |
| L16-04 | Ukraine Previous FT ≥4 & HT 1 | 9 | 0 | 9 | 100.0% |
| L16-31 | Previous Session Both HT 0–0 — minimal satu gol 2H dalam dua laga berikutnya | 48 | 1 | 49 | 98.0% |
| L16-08 | Italy Tandang Previous FT ≥3 Gol | 23 | 1 | 24 | 95.8% |
| L16-13 | Hungary Tandang Previous Tanpa Gol Pribadi 2H | 15 | 1 | 16 | 93.8% |
| L16-14 | Hungary HT 2 + Sebelumnya Kandang | 13 | 1 | 14 | 92.9% |
| L16-01 | Norway Kandang FT Over 1.5 | 63 | 5 | 68 | 92.6% |
| L16-07 | Spain Previous Signal WIN — HT 2–3, sebelumnya ada gol 2H | 12 | 1 | 13 | 92.3% |
| L16-16 | Belgium Kandang HT ≥2 Gol | 18 | 2 | 20 | 90.0% |
| L16-05 | Mexico Previous FT ≤1 + HT 1–2 | 7 | 1 | 8 | 87.5% |
| L16-15 | Norway Home Previous HT ≥3 | 7 | 1 | 8 | 87.5% |
| L16-38 | Argentina HT ≥1 + Home Previous HT ≥2 | 42 | 6 | 48 | 87.5% |
| L16-35 | France Previous Session FT ≥5 Gol | 52 | 8 | 60 | 86.7% |
| L16-12 | Norway Kandang HT 2 Gol | 19 | 3 | 22 | 86.4% |
| L16-34 | Norway Kandang + Lawan Previous HT ≥1 | 31 | 5 | 36 | 86.1% |
| L16-30 | Previous Session 2H ≥3 + Current HT ≥3 | 124 | 21 | 145 | 85.5% |
| L16-03 | Hungary Previous FT ≥4 & HT ≤1 | 17 | 3 | 20 | 85.0% |
| L16-39 | France Previous Session HT ≥2 Gol | 51 | 9 | 60 | 85.0% |
| L16-10 | Italy Tertinggal HT | 39 | 7 | 46 | 84.8% |
| L16-27 | Previous Session FT ≥5 + Current HT ≥3, Slot :30 | 38 | 7 | 45 | 84.4% |
| L16-32 | USA HT ≥1 + Home Sebelumnya Tandang | 32 | 6 | 38 | 84.2% |
| L16-33 | Ukraine HT Ganjil + Previous Session 2H ≥2 | 37 | 7 | 44 | 84.1% |
| L16-20 | Croatia Kandang Tertinggal HT | 20 | 4 | 24 | 83.3% |
| L16-37 | Netherlands HT ≥1 + Home Previous 2H ≥2 | 39 | 8 | 47 | 83.0% |
| L16-36 | France + Tim Tandang Previous Gol 2H ≥1 | 57 | 12 | 69 | 82.6% |
| L16-06 | Spain Tandang Previous 2H ≥2 | 27 | 6 | 33 | 81.8% |
| L16-11 | Mexico Sebelumnya Kandang | 31 | 7 | 38 | 81.6% |
| L16-22 | Ghana Previous Kandang + Gol Pribadi 2H | 23 | 6 | 29 | 79.3% |

Cakupan CSV: 1.593 pertandingan pada 16 dari 21 hari sesi. Sesi 26–30 September 2026 tidak memiliki data; laporan tidak menganggap tanggal kosong sebagai kekalahan.
