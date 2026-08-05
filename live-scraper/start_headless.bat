@echo off
REM ============================================================
REM  Jalankan scraper tanpa browser manual.
REM  Membuka 2 jendela: API server (Flask) + headless runner.
REM  Pastikan sudah pernah menjalankan setup.bat di PC ini.
REM ============================================================
setlocal
set "SCRIPT_DIR=%~dp0"

REM Chromium portable di dalam folder ini -- TAPI hanya kalau foldernya memang ada.
REM Kalau diset tanpa syarat sementara .playwright belum pernah dibuat (setup.bat
REM belum dijalankan di PC ini), Playwright berhenti mencari di lokasi bawaannya
REM dan gagal, padahal Chromium-nya sudah terpasang di
REM %LOCALAPPDATA%\ms-playwright. Pola ini sama dengan headless_runner.py.
if exist "%SCRIPT_DIR%.playwright" set "PLAYWRIGHT_BROWSERS_PATH=%SCRIPT_DIR%.playwright"

REM URL live yang di-scrape (SABA Football). Ganti di sini atau dari dashboard.
set "BPVM_URL=https://g943gp.bpvmr7u6.com/en-US/live/997"

echo Menjalankan API server + scraper (port 5000)...
echo API server akan auto-start headless scraper. Kontrol Start/Stop juga tersedia
echo di dashboard: menu "Live Monitor".
echo.
REM Jangan set ulang PLAYWRIGHT_BROWSERS_PATH di sini. Bentuk
REM   set VAR=nilai && perintah
REM memasukkan spasi sebelum && ke dalam nilainya, sehingga Playwright mencari
REM browser di ".playwright " (berspasi) dan gagal. Baris 11 sudah menetapkan
REM variabelnya dan proses anak mewarisi environment induknya.
start "Live Scraper API" cmd /k "cd /d ""%SCRIPT_DIR%"" && python api_server.py"

echo Selesai. Buka dashboard: http://localhost/lebihsabar/index.php?page=live
echo Tutup jendela "Live Scraper API" untuk menghentikan semuanya.
