# API Results langsung

Endpoint terverifikasi dari bundle JavaScript publik MSports pada 17 Oktober 2026:

`POST https://msports.m88m8idtop.com/api/v1/report/result/{opCode}/{ts}/match`

Header: `Authorization: Bearer {auth.acccessToken}` dan `Content-Type: application/json`.
Ejaan `acccessToken` mengikuti nama yang dipakai situs.

Payload yang dipakai halaman Results (soccer `sport_id: 10`):

```json
{
  "sport_id": 10,
  "language_id": "en-US",
  "league_id": 0,
  "from_date": "2026-10-17T00:00:00.000+08:00",
  "to_date": "2026-10-17T23:59:59.999+08:00"
}
```

Situs meminta semua league lalu memfilternya di antarmuka. Angka 16 pada nama
League 16 bukan bukti bahwa ID API-nya 16. Jangan mengganti `sport_id` menjadi 16.
Offset tanggal mengikuti browser pada situs asli; klien ini memakai UTC+8 sebagai
default agar sesuai data League 16 lokal. Opsi `--utc-offset` tersedia.

## Pemakaian tanpa extension

Konfigurasi dari cURL sudah dapat diimpor tanpa menjalankan perintah cURL tersebut:

```powershell
python msports-parser/results_api.py --import-curl "C:\path\request.txt" --date 2026-10-17
```

Sesi dan header disimpan dengan Windows DPAPI di
`%LOCALAPPDATA%/Sabaraja/msports-session.bin`, di luar folder web, terenkripsi
untuk akun Windows yang melakukan impor. Setelah impor, cukup jalankan perintah
pengambilan tanggal di bawah; tidak perlu environment variable. Header asli dari
request dipertahankan karena pengujian header minimal mendapat HTTP 403.

Alternatif tanpa konfigurasi tersimpan:
Isi environment variable `MSPORTS_OPCODE`, `MSPORTS_SESSION` (nilai `ts`), dan
`MSPORTS_BEARER` dari sesi login akun sendiri yang masih berlaku. Jangan menyimpan
token di repo, folder web, atau membagikannya di chat.

```powershell
python msports-parser/results_api.py --date 2026-10-17
```

Hasil JSON disimpan di `%LOCALAPPDATA%/Sabaraja/results/`, di luar folder web.
Klien berjalan langsung melalui HTTP dan tidak memerlukan extension atau browser
yang terus terbuka. Sesi yang kedaluwarsa tetap perlu diperbarui.

## Status verifikasi

Endpoint, header, dan payload ditemukan dari `main.80cec510.chunk.js` serta
`7.bebc623a.chunk.js` milik situs. Alur Results situs memeriksa sesi sebelum request.
Panggilan langsung terautentikasi berhasil diuji: HTTP 200, status 1, lalu berhasil
diulang menggunakan konfigurasi DPAPI tanpa browser atau extension. Respons
`data` adalah daftar pertandingan, bukan daftar league. League 16 memiliki
`events: "10031403"`; `match_date` pada respons memuat offset `+08:00`.
`fs_home` memuat pasangan HT_FT, misalnya `0:0_1:0`. Jangan menganggap `0:0_0:0`
sebagai hasil final tanpa memeriksa `st_fs: "Y"` dan `st_hs: "Y"`.
Klien menyimpan respons asli dan belum menulis `matches.csv`.
