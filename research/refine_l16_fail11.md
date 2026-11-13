# Backtest khusus pola 11 FAIL

Sumber matches.csv, 4.824 laga League 16 sampai sesi 18 Oktober 2026. Pemilihan filter memakai data sebelum 12 Oktober; pemeriksaan akhir 12–18 Oktober. Satu atau dua filter, minimal 20 sampel latihan dan retensi ≥40%. Syarat HT sekarang hanya untuk market 2H; histori tim sebelumnya berjeda 30 menit–3 jam. Tidak memakai skor FT laga target sebagai pemicu.

Data akhir pernah dipakai riset terdahulu, jadi bukan holdout independen. Banyak kombinasi dicoba; hasil rentan overfitting. Kandidat utama dipilih dari latihan saja. Angka keseluruhan bukan prediksi akurasi masa depan. Belum ada perubahan produksi.

|Pola|Tambahan syarat kandidat utama|Sebelum W/F|Sesudah W/F|Akurasi sebelum → sesudah|Uji akhir sebelum|Uji akhir sesudah|
|---|---|---:|---:|---:|---:|---:|
|L16-08|Away previous 2H ≤2 + HT tidak imbang|58/11|30/2|84.06% → 93.75%|10/3 (76.92%)|5/1 (83.33%)|
|L16-16|Home previous 2H ≤3|71/11|38/3|86.59% → 92.68%|8/1 (88.89%)|6/1 (85.71%)|
|L16-27|Home previous 2H ≤1|61/11|30/2|84.72% → 93.75%|17/3 (85%)|7/2 (77.78%)|
|L16-32|Home previous HT ≤1 + Away previous 2H ≤2|62/11|28/2|84.93% → 93.33%|12/4 (75%)|5/2 (71.43%)|
|L16-34|Total HT ≤2 + Home previous FT ≤4|72/11|33/1|86.75% → 97.06%|11/3 (78.57%)|4/1 (80%)|
|L16-39|Home previous 2H ≥2 + Away previous 2H ≤3|91/11|33/2|89.22% → 94.29%|19/6 (76%)|2/2 (50%)|
|L16-57|Away previous HT ≤1 + Away previous 2H ≥1|58/11|37/7|84.06% → 84.09%|8/3 (72.73%)|4/3 (57.14%)|

Seluruh lima finalis setiap pola dan jumlah kombinasi tersedia di refine_l16_fail11.json. Memilih finalis berdasarkan hasil uji akhir menjadikan data akhir bagian dari pemilihan; perlu data baru untuk validasi.
