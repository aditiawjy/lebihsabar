@echo off
REM ============================================================
REM  Daftarkan pemeriksa pengumpul odds ke Windows Task Scheduler.
REM  Tiap 15 menit: cek API server scraper (port 5000) dan apakah
REM  goal_log_bpvm.csv masih bertambah. Telegram dikirim setelah 2 pemeriksaan
REM  gagal berturut-turut, dan sekali lagi saat pulih.
REM  Hapus task: schtasks /delete /tn "Sabaraja Collector Watch" /f
REM ============================================================
setlocal
set "SCRIPT=%~dp0..\msports-parser\telegram_notify.py"
for /f "delims=" %%P in ('where pythonw 2^>nul') do if not defined PYW set "PYW=%%P"
if not defined PYW for /f "delims=" %%P in ('where python 2^>nul') do if not defined PYW set "PYW=%%P"
if not defined PYW (echo Python tidak ditemukan di PATH. & exit /b 1)
schtasks /create /f /sc minute /mo 15 /tn "Sabaraja Collector Watch" /tr "\"%PYW%\" \"%SCRIPT%\" --mode watch"
if errorlevel 1 exit /b 1
echo Task "Sabaraja Collector Watch" terdaftar (tiap 15 menit).
