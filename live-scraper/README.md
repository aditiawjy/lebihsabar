# Live Scraper (Portable, tanpa browser manual)

Scraper data live BPVM yang berjalan di **Chromium headless** (tanpa jendela browser
yang harus dibuka manual). Me-reuse Chrome extension yang sudah ada di `chrome_extension/`.
Bisa dipindah lewat flash disk atau di-update lewat GitHub.

## Komponen
- `headless_runner.py` — menjalankan extension di Chromium headless via Playwright.
- `api_server.py` — Flask (port 5000), menerima data + kirim Telegram.
- `chrome_extension/` — logika scraping/sinyal (tidak diubah).
- Dashboard PHP ada di project utama (`lebihsabar`), diakses lewat browser.

## Cara pakai di PC baru (rumah / kantor)
Syarat: **XAMPP** (untuk dashboard PHP) + **Python 3.10+** terpasang.

1. Salin/clone folder project ke `C:\xampp\htdocs\lebihsabar` (path harus sama supaya
   endpoint `localhost/lebihsabar/...` tetap cocok).
2. Masuk ke `live-scraper`, jalankan **`setup.bat`** (sekali saja — install dependency
   + download Chromium ke `.playwright/`).
3. Jalankan **`start_headless.bat`** — terbuka 2 jendela: API server + headless scraper.
4. Buka dashboard PHP di browser (mis. `http://localhost/lebihsabar/`).

## Portabilitas
- **Flash disk:** copy seluruh folder (termasuk `.playwright/`) → di PC tujuan langsung
  `start_headless.bat`. Jika Python beda versi, jalankan `setup.bat` lagi.
- **GitHub:** `.playwright/`, `.chrome-profile/`, log, dan `__pycache__/` di-ignore
  (lihat `.gitignore`). Setelah `git pull` di PC baru, cukup `setup.bat` sekali.

## Override URL live (opsional)
Set environment variable sebelum menjalankan:
```
set BPVM_URL=https://g943gp.bpvmr7u6.com/live
```

## Cek jalan / tidak
- `http://127.0.0.1:5000/api/status` → `{"status":"online", ...}`
- `http://127.0.0.1:5000/api/live-data` → `count` > 0 dengan `timestamp` baru.
- Log runner: `headless_runner.log`.
