@echo off
REM Jalankan Chrome dengan throttling background DIMATIKAN supaya tab Virtual Soccer
REM tetap men-scan & mencatat ke CSV walau Chrome di-minimize / kamu pindah ke Word
REM atau browser lain. PENTING: tutup SEMUA jendela Chrome dulu sebelum klik ini,
REM karena flag hanya berlaku untuk proses Chrome yang baru.

set "URL=https://prod20191-101527338.1x2aaa.com/en/asian-view/today/Virtual-Soccer"

set "CHROME=C:\Program Files\Google\Chrome\Application\chrome.exe"
if not exist "%CHROME%" set "CHROME=C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"
if not exist "%CHROME%" set "CHROME=%LocalAppData%\Google\Chrome\Application\chrome.exe"

start "" "%CHROME%" ^
  --disable-background-timer-throttling ^
  --disable-backgrounding-occluded-windows ^
  --disable-renderer-backgrounding ^
  "%URL%"
