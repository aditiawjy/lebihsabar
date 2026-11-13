# Uji filter tambahan League 16

Rentang sesi 26 September–16 Oktober 2026. Pemilihan filter: sampai 11 Oktober. Data uji dipisahkan: 12–16 Oktober. Tujuh filter HT diuji per pola; pemilihan hanya memakai data latih, minimal 10 sampel dan mempertahankan 40% pemicu. Data uji minimal 5 sampel. Ini eksplorasi pada data historis yang sudah tersedia, bukan bukti prospektif; sampel kecil dan pencarian banyak filter meningkatkan risiko overfit. L16-31 memakai dua pertandingan per slot dan tidak dioptimalkan dengan HT satu pertandingan.

| Pola | Filter terpilih | Latih awal → filter | Uji awal → filter | Sampel uji tersisa | Hasil |
|---|---|---|---|---:|---|
| L16-01 | HT ≥2 | — | — | — | Dikeluarkan: target FT O1.5 sudah terpenuhi saat HT ≥2 |
| L16-02 | — | 100.0% → — | 100.0% → — | — | Tidak ada kandidat dengan sampel latih cukup |
| L16-03 | — | 91.7% → — | 75.0% → — | — | Tidak ada kandidat dengan sampel latih cukup |
| L16-04 | — | 100.0% → — | 100.0% → — | — | Tidak ada kandidat dengan sampel latih cukup |
| L16-05 | — | 75.0% → — | 100.0% → — | — | Tidak ada kandidat dengan sampel latih cukup |
| L16-06 | HT ≤3 | 73.7% → 75.0% | 92.9% → 92.9% | 14 | Belum terbukti meningkat |
| L16-07 | — | 100.0% → — | 66.7% → — | — | Tidak ada kandidat dengan sampel latih cukup |
| L16-08 | HT tidak imbang | 94.1% → 100.0% | 100.0% → 100.0% | 5 | Belum terbukti meningkat |
| L16-10 | HT 0–1 | 81.8% → 85.7% | 92.3% → 100.0% | 5 | Meningkat pada data uji |
| L16-11 | HT tidak imbang | 80.0% → 87.5% | 84.6% → 88.9% | 9 | Meningkat pada data uji |
| L16-12 | — | 82.4% → — | 100.0% → — | — | Tidak ada kandidat dengan sampel latih cukup |
| L16-13 | — | 87.5% → — | 100.0% → — | — | Tidak ada kandidat dengan sampel latih cukup |
| L16-14 | — | 90.0% → — | 100.0% → — | — | Tidak ada kandidat dengan sampel latih cukup |
| L16-15 | — | 100.0% → — | 80.0% → — | — | Tidak ada kandidat dengan sampel latih cukup |
| L16-16 | HT tidak imbang | 93.3% → 100.0% | 80.0% → 66.7% | 3 | Belum terbukti meningkat |
| L16-20 | — | 82.4% → — | 85.7% → — | — | Tidak ada kandidat dengan sampel latih cukup |
| L16-22 | HT 1–2 | 80.0% → 91.7% | 77.8% → 60.0% | 5 | Belum terbukti meningkat |
| L16-27 | HT 2–3 | 80.0% → 84.2% | 93.3% → 92.3% | 13 | Belum terbukti meningkat |
| L16-30 | HT 2–3 | 82.8% → 85.5% | 91.3% → 93.5% | 31 | Meningkat pada data uji |
| L16-31 | — | — | — | — | Tidak diuji: memerlukan filter gabungan dua laga |
| L16-32 | HT tidak imbang | 88.0% → 90.9% | 76.9% → 72.7% | 11 | Belum terbukti meningkat |
| L16-33 | — | 96.4% → — | 62.5% → — | — | Tidak ada kandidat dengan sampel latih cukup |
| L16-34 | HT 0–1 | 87.5% → 91.7% | 83.3% → 80.0% | 5 | Belum terbukti meningkat |
| L16-35 | HT 0–1 | 93.0% → 96.7% | 70.6% → 81.8% | 11 | Meningkat pada data uji |
| L16-36 | HT imbang | 88.7% → 92.0% | 62.5% → 70.0% | 10 | Meningkat pada data uji |
| L16-37 | HT 1–2 | 86.7% → 87.0% | 76.5% → 73.3% | 15 | Belum terbukti meningkat |
| L16-38 | HT 1–2 | 84.8% → 88.5% | 93.3% → 90.9% | 11 | Belum terbukti meningkat |
| L16-39 | HT tidak imbang | 90.5% → 94.7% | 72.2% → 71.4% | 7 | Belum terbukti meningkat |
