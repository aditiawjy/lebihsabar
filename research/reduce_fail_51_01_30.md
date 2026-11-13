# Backtest pengurangan FAIL L16-51, L16-01, L16-30

Rentang sesi 2026-09-18–2026-10-17; pemilihan sebelum 2026-10-11, evaluasi akhir 2026-10-11–2026-10-17. 43008 kombinasi; pilih maksimal 3 menurut batas bawah Wilson pada data awal, minimal 20 sampel latih dan 50% sinyal latih dipertahankan. Data akhir sudah pernah digunakan dalam riset lain, sehingga pemeriksaan ini eksploratif, bukan validasi prospektif. Penurunan FAIL karena membuang sinyal juga membuang WIN. Tanpa odds tidak dapat menentukan keuntungan. Filter HT untuk FT01 mengubah waktu masuk ke jeda; target yang sudah menang pada HT>=2 tidak digunakan. HT51 memakai hanya hasil yang tersedia sebelum kickoff.

## L16-51

Asli sebulan: {'win': 97, 'fail': 11, 'total': 108, 'accuracy': 89.81}. Data akhir asli: {'win': 23, 'fail': 2, 'total': 25, 'accuracy': 92.0}.

| Filter | Waktu masuk | Awal W/L | Akhir W/L | Akurasi akhir | Semua W/L | Akurasi semua | WIN dibuang | FAIL dibuang |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| home prev own 2H <=2 + away prev FT >=2 | pre | 50/3 | 14/0 | 100.0% | 64/3 | 95.52% | 33 | 8 |
| away prev FT >=3 | pre | 41/2 | 9/0 | 100.0% | 50/2 | 96.15% | 47 | 9 |
| home prev own HT <=2 + away prev FT >=3 | pre | 41/2 | 8/0 | 100.0% | 49/2 | 96.08% | 48 | 9 |

## L16-01

Asli sebulan: {'win': 83, 'fail': 7, 'total': 90, 'accuracy': 92.22}. Data akhir asli: {'win': 23, 'fail': 2, 'total': 25, 'accuracy': 92.0}.

| Filter | Waktu masuk | Awal W/L | Akhir W/L | Akurasi akhir | Semua W/L | Akurasi semua | WIN dibuang | FAIL dibuang |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| away prev 2H <=3 | pre | 49/0 | 16/2 | 88.89% | 65/2 | 97.01% | 18 | 5 |
| away prev 2H <=3 + slot -30m HT <=6 | pre | 48/0 | 16/2 | 88.89% | 64/2 | 96.97% | 19 | 5 |
| away prev 2H <=3 + slot -30m FT >=1 | pre | 48/0 | 16/2 | 88.89% | 64/2 | 96.97% | 19 | 5 |

## L16-30

Asli sebulan: {'win': 126, 'fail': 17, 'total': 143, 'accuracy': 88.11}. Data akhir asli: {'win': 35, 'fail': 3, 'total': 38, 'accuracy': 92.11}.

| Filter | Waktu masuk | Awal W/L | Akhir W/L | Akurasi akhir | Semua W/L | Akurasi semua | WIN dibuang | FAIL dibuang |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| slot -15m 2H <=6 + away same venue 2H <=3 | HT (aturan dasar) | 54/3 | 20/2 | 90.91% | 74/5 | 93.67% | 52 | 12 |
| slot -15m FT >=5 + away prev 2H <=2 | HT (aturan dasar) | 62/4 | 22/3 | 88.0% | 84/7 | 92.31% | 42 | 10 |
| slot -15m 2H <=5 + away same venue 2H <=3 | HT (aturan dasar) | 51/3 | 20/1 | 95.24% | 71/4 | 94.67% | 55 | 13 |

Verifikasi tambahan: seluruh riwayat L16-51 = 136 WIN / 22 FAIL; L16-01 = 157 WIN / 18 FAIL; L16-30 = 126 WIN / 17 FAIL. Filter terpilih pertama masing-masing pola diperiksa ulang dengan replay predikat langsung, dan WIN/FAIL dibuang cocok dengan selisih baseline. Aturan aplikasi tidak diubah.
