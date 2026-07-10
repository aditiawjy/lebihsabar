@echo off
REM ============================================================
REM  Jalankan scraper tanpa browser manual.
REM  Membuka 2 jendela: API server (Flask) + headless runner.
REM  Pastikan sudah pernah menjalankan setup.bat di PC ini.
REM ============================================================
setlocal
set "SCRIPT_DIR=%~dp0"

REM Chromium portable di dalam folder ini.
set "PLAYWRIGHT_BROWSERS_PATH=%SCRIPT_DIR%.playwright"

REM URL live yang di-scrape (SABA Football). Ganti di sini atau dari dashboard.
set "BPVM_URL=https://g943gp.bpvmr7u6.com/en-US/live/997"

echo Menjalankan API server + scraper (port 5000)...
echo API server akan auto-start headless scraper. Kontrol Start/Stop juga tersedia
echo di dashboard: menu "Live Monitor".
echo.
start "Live Scraper API" cmd /k "cd /d ""%SCRIPT_DIR%"" && set PLAYWRIGHT_BROWSERS_PATH=%SCRIPT_DIR%.playwright && python api_server.py"

echo Selesai. Buka dashboard: http://localhost/lebihsabar/index.php?page=live
echo Tutup jendela "Live Scraper API" untuk menghentikan semuanya.
