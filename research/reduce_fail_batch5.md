# Backtest pengurangan FAIL L16-06, L16-16, L16-38, L16-44, L16-57

Rentang sesi 2026-09-18–2026-10-17; pemilihan sebelum 2026-10-11, evaluasi akhir 2026-10-11–2026-10-17. 73383 kombinasi; pilih maksimal 3 menurut batas bawah Wilson pada data awal, minimal 20 sampel latih dan 50% sinyal latih dipertahankan. Data akhir sudah pernah digunakan dalam riset lain, sehingga pemeriksaan ini eksploratif, bukan validasi prospektif. Penurunan FAIL karena membuang sinyal juga membuang WIN. Tanpa odds tidak dapat menentukan keuntungan. Pola 2H memakai kondisi HT target saat jeda. L16-57 hanya memakai hasil yang sudah tersedia sebelum kickoff.

## L16-06

Asli sebulan: {'win': 39, 'fail': 8, 'total': 47, 'accuracy': 82.98}. Data akhir asli: {'win': 14, 'fail': 1, 'total': 15, 'accuracy': 93.33}.

| Filter | Waktu masuk | Awal W/L | Akhir W/L | Akurasi akhir | Semua W/L | Akurasi semua | WIN dibuang | FAIL dibuang |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| slot -15m HT >=2 | HT | 18/3 | 8/1 | 88.89% | 26/4 | 86.67% | 13 | 4 |
| slot -15m FT >=5 | HT | 18/3 | 9/1 | 90.0% | 27/4 | 87.1% | 12 | 4 |
| away same venue 2H >=1 | HT | 18/3 | 11/1 | 91.67% | 29/4 | 87.88% | 10 | 4 |

## L16-16

Asli sebulan: {'win': 31, 'fail': 4, 'total': 35, 'accuracy': 88.57}. Data akhir asli: {'win': 4, 'fail': 1, 'total': 5, 'accuracy': 80.0}.

| Filter | Waktu masuk | Awal W/L | Akhir W/L | Akurasi akhir | Semua W/L | Akurasi semua | WIN dibuang | FAIL dibuang |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| slot -15m HT <=4 | HT | 22/1 | 4/1 | 80.0% | 26/2 | 92.86% | 5 | 2 |
| away prev HT >=0 + slot -15m HT <=6 | HT | 22/1 | 3/1 | 75.0% | 25/2 | 92.59% | 6 | 2 |
| home prev HT >=0 + away prev HT >=0 | HT | 21/1 | 2/1 | 66.67% | 23/2 | 92.0% | 8 | 2 |

## L16-38

Asli sebulan: {'win': 50, 'fail': 7, 'total': 57, 'accuracy': 87.72}. Data akhir asli: {'win': 16, 'fail': 2, 'total': 18, 'accuracy': 88.89}.

| Filter | Waktu masuk | Awal W/L | Akhir W/L | Akurasi akhir | Semua W/L | Akurasi semua | WIN dibuang | FAIL dibuang |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| home prev own 2H <=1 + slot -15m FT >=5 | HT | 23/0 | 11/0 | 100.0% | 34/0 | 100.0% | 16 | 7 |
| away prev own 2H <=3 + slot -15m FT >=5 | HT | 23/0 | 11/1 | 91.67% | 34/1 | 97.14% | 16 | 6 |
| home prev own 2H <=0 + away prev FT >=2 | HT | 21/0 | 8/0 | 100.0% | 29/0 | 100.0% | 21 | 7 |

## L16-44

Asli sebulan: {'win': 39, 'fail': 7, 'total': 46, 'accuracy': 84.78}. Data akhir asli: {'win': 9, 'fail': 2, 'total': 11, 'accuracy': 81.82}.

| Filter | Waktu masuk | Awal W/L | Akhir W/L | Akurasi akhir | Semua W/L | Akurasi semua | WIN dibuang | FAIL dibuang |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| home prev FT >=3 | HT | 22/1 | 7/2 | 77.78% | 29/3 | 90.62% | 10 | 4 |
| HT total <=3 + home prev FT >=3 | HT | 22/1 | 6/2 | 75.0% | 28/3 | 90.32% | 11 | 4 |
| slot -15m HT <=5 + home prev FT >=3 | HT | 21/1 | 6/2 | 75.0% | 27/3 | 90.0% | 12 | 4 |

## L16-57

Asli sebulan: {'win': 35, 'fail': 5, 'total': 40, 'accuracy': 87.5}. Data akhir asli: {'win': 8, 'fail': 2, 'total': 10, 'accuracy': 80.0}.

| Filter | Waktu masuk | Awal W/L | Akhir W/L | Akurasi akhir | Semua W/L | Akurasi semua | WIN dibuang | FAIL dibuang |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| home prev own HT <=1 + away prev own 2H <=1 | pre | 20/1 | 3/0 | 100.0% | 23/1 | 95.83% | 12 | 4 |
| away prev own 2H <=1 | pre | 25/2 | 7/1 | 87.5% | 32/3 | 91.43% | 3 | 2 |
| away prev FT <=2 | pre | 19/1 | 4/1 | 80.0% | 23/2 | 92.0% | 12 | 3 |
