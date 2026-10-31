# Kandidat pola baru League 16

Sesi 26 September–16 Oktober 2026 (UTC+8, cutoff 12:00). Pemilihan: sampai 11 Oktober; uji: 12–16 Oktober. Cakupan mengikuti snapshot CSV saat skrip dijalankan. Riwayat tim dibatasi 3 jam, untuk pasar 2H slot sebelumnya tepat 15 menit dan dua laga lengkap. Untuk pasar HT, hasil tim sebelumnya berjarak minimal 16 menit dan slot rujukan 30 menit sebelum target agar hasil sudah selesai saat kickoff (asumsi durasi 16 menit).

Pencarian 45927 kombinasi. Shortlist dibekukan dari data latih (minimal 20 sampel, 4 sesi, akurasi melebihi baseline market ≥4 poin), maksimal 3 per market, sebelum melihat data uji. Banyak percobaan meningkatkan risiko overfit. Karena data uji pernah dianalisis sebelumnya dalam sesi kerja ini, ini holdout retrospektif, bukan validasi independen/prospektif. Data tidak mencatat kapan skor tersedia; tidak menjamin sinyal dapat diambil tepat waktu. Tidak ada odds: akurasi bukan ukuran profit. HT pasar babak pertama tidak memakai HT laga target. Kandidat 2H memakai HT, sehingga baru tersedia saat jeda. Pola HT/2H Over 1.5 atau 2.5 hanya menang bila total babak tersebut minimal 2 atau 3 gol; bukan total FT.

| Market | Kandidat | Latih W/L | Uji W/L | Akurasi uji | Semua W/L |
|---|---|---:|---:|---:|---:|
| HT Over 0.5 | belgium kandang + slot 30 menit sebelumnya total HT ≥1 | 40/2 | 10/4 | 71.4% | 50/6 |
| HT Over 0.5 | belgium kandang + slot 30 menit sebelumnya total 2H ≥2 | 38/2 | 9/3 | 75.0% | 47/5 |
| HT Over 0.5 | belgium kandang + slot 30 menit sebelumnya total FT ≥4 | 35/2 | 10/2 | 83.3% | 45/4 |
| HT Over 1.5 | italy kandang + laga italy sebelumnya HT ≤1 | 18/6 | 5/3 | 62.5% | 23/9 |
| HT Over 1.5 | norway kandang + slot 30 menit sebelumnya total 2H ≥2 | 30/15 | 7/7 | 50.0% | 37/22 |
| HT Over 1.5 | italy semua + laga italy sebelumnya HT ≤2 | 36/22 | 11/8 | 57.9% | 47/30 |
| HT Over 2.5 | argentina tandang + laga argentina sebelumnya 2H ≤1 | 12/17 | 2/10 | 16.7% | 14/27 |
| HT Over 2.5 | argentina tandang + laga argentina sebelumnya FT ≤3 | 12/20 | 1/12 | 7.7% | 13/32 |
| HT Over 2.5 | mexico tandang + laga mexico sebelumnya 2H ≥1 | 17/35 | 4/17 | 19.0% | 21/52 |
| 2H Over 0.5 | ukraine semua + slot sebelumnya total FT ≥4 + HT tidak imbang | 55/1 | 15/7 | 68.2% | 70/8 |
| 2H Over 0.5 | ukraine semua + slot sebelumnya total 2H ≥2 + HT tidak imbang | 52/1 | 13/7 | 65.0% | 65/8 |
| 2H Over 0.5 | france semua + slot sebelumnya total 2H ≥3 | 51/1 | 11/4 | 73.3% | 62/5 |
| 2H Over 1.5 | ukraine semua + slot sebelumnya total 2H ≥4 + HT tidak imbang | 20/4 | 2/5 | 28.6% | 22/9 |
| 2H Over 1.5 | france tandang + laga france sebelumnya FT ≤3 | 23/6 | 2/4 | 33.3% | 25/10 |
| 2H Over 1.5 | sweden semua + slot sebelumnya total 2H ≥2 + HT 2–3 | 27/8 | 4/7 | 36.4% | 31/15 |
| 2H Over 2.5 | mexico tandang + slot sebelumnya total FT ≥4 + HT 2–3 | 13/11 | 1/7 | 12.5% | 14/18 |
| 2H Over 2.5 | mexico semua + slot sebelumnya total FT ≥4 + HT 2–3 | 16/20 | 2/9 | 18.2% | 18/29 |
| 2H Over 2.5 | mexico tandang + slot sebelumnya total HT ≥1 + HT 2–3 | 13/15 | 2/9 | 18.2% | 15/24 |
