# Panduan Instalasi Sabar Msports Parser

1. Buka Google Chrome.
2. Ketik `chrome://extensions` di address bar dan tekan Enter.
3. Aktifkan **Developer mode** di pojok kanan atas.
4. Klik tombol **Load unpacked** di pojok kiri atas.
5. Pilih folder:
   `c:\xampp\htdocs\sabar\msports-parser`
6. Ekstensi akan muncul di daftar.
7. Buka halaman target: `https://msports.m88m8idtop.com/app/v2/sports.html`
8. Tombol **🚀 PARSE DATA** akan muncul di pojok kanan atas halaman.

---
**Catatan:**
Jika tombol tidak muncul, coba refresh halaman target.

Sinkronisasi otomatis League 16 (v2.3):
- Reload extension setelah update, lalu refresh halaman Msports.
- Buka menu Results di sesi akun Anda dan tampilkan liga
  SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16 Mins Play.
- Biarkan Results terbuka. Setiap 15 detik script memeriksa data yang berubah.
- Hanya status COMPLETED/FT dengan tanggal sumber dan skor HT/FT lengkap yang
  dikirim ke http://127.0.0.1/lebihsabar/save_matches_csv.php.
- Waktu League 16 dipertahankan sebagai waktu sumber UTC+8; tidak digeser ke WIB.
- Halaman Pattern memeriksa versi CSV tiap 30 detik dan memuat ulang statistik,
  radar, serta riwayat sinyal live saat CSV berubah.
- Sinkronisasi belum dapat berjalan dari halaman odds saja atau saat Results
  belum terbuka/tidak dapat diakses. Script tidak melewati login situs.
Untuk update script, edit file di folder ini lalu klik tombol reload (ikon panah melingkar) di halaman `chrome://extensions`.
