# Backtest pengurangan FAIL L16-37, L16-60

Rentang sesi 2026-09-18–2026-10-17; pemilihan sebelum 2026-10-11, evaluasi akhir 2026-10-11–2026-10-17. 28655 kombinasi; pilih maksimal 3 menurut batas bawah Wilson pada data awal, minimal 20 sampel latih dan 50% sinyal latih dipertahankan. Data akhir sudah pernah digunakan dalam riset lain, sehingga pemeriksaan ini eksploratif, bukan validasi prospektif. Penurunan FAIL karena membuang sinyal juga membuang WIN. Tanpa odds tidak dapat menentukan keuntungan. L16-37 memakai kondisi HT target saat jeda. L16-60 hanya memakai hasil yang sudah tersedia sebelum kickoff.

## L16-37

Asli sebulan: {'win': 52, 'fail': 9, 'total': 61, 'accuracy': 85.25}. Data akhir asli: {'win': 14, 'fail': 4, 'total': 18, 'accuracy': 77.78}.

| Filter | Waktu masuk | Awal W/L | Akhir W/L | Akurasi akhir | Semua W/L | Akurasi semua | WIN dibuang | FAIL dibuang |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| away prev 2H <=2 + home prev FT >=3 | HT | 28/0 | 9/2 | 81.82% | 37/2 | 94.87% | 15 | 7 |
| away prev 2H <=2 + home prev HT >=1 | HT | 25/0 | 6/2 | 75.0% | 31/2 | 93.94% | 21 | 7 |
| away prev 2H <=2 + home prev FT >=4 | HT | 23/0 | 7/1 | 87.5% | 30/1 | 96.77% | 22 | 8 |

## L16-60

Asli sebulan: {'win': 48, 'fail': 7, 'total': 55, 'accuracy': 87.27}. Data akhir asli: {'win': 9, 'fail': 1, 'total': 10, 'accuracy': 90.0}.

| Filter | Waktu masuk | Awal W/L | Akhir W/L | Akurasi akhir | Semua W/L | Akurasi semua | WIN dibuang | FAIL dibuang |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| home prev FT >=3 | pre | 25/2 | 7/0 | 100.0% | 32/2 | 94.12% | 16 | 5 |
| away prev own HT <=2 | pre | 39/5 | 9/1 | 90.0% | 48/6 | 88.89% | 0 | 1 |
| home prev FT <=4 + away prev own HT <=2 | pre | 34/4 | 5/1 | 83.33% | 39/5 | 88.64% | 9 | 2 |
