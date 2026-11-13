# Eksplorasi diperluas pola League 16

Rentang sesi 26 September–16 Oktober 2026; pemilihan sampai 11 Oktober, pemeriksaan 12–16 Oktober. 1994 laga. 850500 kombinasi ditelusuri, 131 kandidat dipilih berdasarkan data latih; 23 memiliki akurasi uji dan keseluruhan ≥80%, minimal 8 sampel uji dan 25 keseluruhan.

Ini eksplorasi retrospektif lanjutan pada data uji yang sudah pernah dilihat. Angka bukan estimasi tak bias, dan bukan validasi baru. Banyak percobaan serta pola saling tumpang tindih meningkatkan risiko overfit. Perlu data masa depan. Tidak ada odds atau timestamp kapan hasil benar-benar tersedia. Durasi pertandingan diasumsikan 16 menit. Market HT hanya memakai hasil laga yang sudah selesai sebelum kickoff; market 2H dapat memakai HT target.

Home/away berarti tim kandang/tandang pada pertandingan target. Prev adalah laga terakhir tim itu, same venue adalah laga terakhir pada posisi kandang/tandang yang sama, maksimal 3 jam. Own HT/2H berarti gol tim itu sendiri. Slot sebelumnya: dua laga tepat 15 menit sebelumnya untuk 2H; untuk prediksi HT memakai slot 30 menit sebelumnya. Semua kondisi HT target dievaluasi saat jeda, bukan sebelum kickoff.

| Market | Kandidat | Latih W/L | Uji W/L | Akurasi uji | Semua W/L | Akurasi semua |
|---|---|---:|---:|---:|---:|---:|
| 2H Over 0.5 | norway semua + away prev own 2H ≤1 + slot sebelumnya total 2H ≥4 | 23/0 | 10/0 | 100.0% | 33/0 | 100.0% |
| 2H Over 0.5 | morocco semua + away prev own HT ≥2 | 24/0 | 9/1 | 90.0% | 33/1 | 97.1% |
| 2H Over 0.5 | scotland semua + away unggul HT + home prev HT ≤3 | 23/2 | 9/1 | 90.0% | 32/3 | 91.4% |
| 2H Over 0.5 | portugal semua + away prev own HT ≥1 + HT 0–1 | 25/0 | 8/2 | 80.0% | 33/2 | 94.3% |
| 2H Over 0.5 | hungary semua + home prev own 2H ≥1 + HT tidak imbang | 28/3 | 8/2 | 80.0% | 36/5 | 87.8% |
| 2H Over 0.5 | romania kandang + home prev FT ≤3 + slot sebelumnya total 2H ≥3 | 24/2 | 9/0 | 100.0% | 33/2 | 94.3% |
| 2H Over 0.5 | scotland semua + away unggul HT + home prev HT ≤1 | 18/1 | 8/1 | 88.9% | 26/2 | 92.9% |
| 2H Over 0.5 | portugal tandang + home prev HT ≤1 + HT 0–1 | 22/0 | 8/0 | 100.0% | 30/0 | 100.0% |
| 2H Over 0.5 | norway semua + away prev 2H ≤1 + slot sebelumnya total 2H ≥3 | 22/0 | 8/0 | 100.0% | 30/0 | 100.0% |
| 2H Over 0.5 | new zealand semua + away prev own HT ≤1 + slot sebelumnya total HT ≥3 | 22/1 | 7/1 | 87.5% | 29/2 | 93.5% |
| 2H Over 0.5 | germany semua + home same venue FT ≤2 + slot sebelumnya total HT ≥3 | 19/1 | 7/1 | 87.5% | 26/2 | 92.9% |
| HT Over 0.5 | norway semua + home prev HT ≥1 | 69/7 | 19/2 | 90.5% | 88/9 | 90.7% |
| HT Over 0.5 | ghana semua + away same venue 2H ≤2 | 36/1 | 16/1 | 94.1% | 52/2 | 96.3% |
| HT Over 0.5 | ghana semua + away same venue FT ≤4 | 36/1 | 15/1 | 93.8% | 51/2 | 96.2% |
| HT Over 0.5 | morocco semua + away same venue FT ≤4 + slot 30 menit sebelumnya total HT ≥3 | 28/2 | 12/1 | 92.3% | 40/3 | 93.0% |
| HT Over 0.5 | argentina semua + away same venue own HT ≤0 | 27/0 | 11/2 | 84.6% | 38/2 | 95.0% |
| HT Over 0.5 | ukraine semua + home prev FT ≥3 + slot 30 menit sebelumnya total HT ≥2 | 28/1 | 9/2 | 81.8% | 37/3 | 92.5% |
| HT Over 0.5 | usa kandang + away prev FT ≤3 | 25/0 | 8/2 | 80.0% | 33/2 | 94.3% |
| HT Over 0.5 | argentina semua + away same venue own HT ≤0 + slot 30 menit sebelumnya total HT ≥1 | 24/0 | 8/2 | 80.0% | 32/2 | 94.1% |
| HT Over 0.5 | croatia kandang + home prev 2H ≥2 | 21/2 | 9/0 | 100.0% | 30/2 | 93.8% |
| HT Over 0.5 | spain semua + away prev own 2H ≤0 | 36/5 | 8/1 | 88.9% | 44/6 | 88.0% |
| HT Over 0.5 | morocco semua + home prev own 2H ≤0 + slot 30 menit sebelumnya total HT ≥3 | 22/1 | 7/1 | 87.5% | 29/2 | 93.5% |
| HT Over 0.5 | wales semua + home prev FT ≥4 + slot 30 menit sebelumnya total 2H ≥2 | 17/3 | 7/1 | 87.5% | 24/4 | 85.7% |
