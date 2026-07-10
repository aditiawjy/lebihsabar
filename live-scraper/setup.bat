@echo off
REM ============================================================
REM  Setup sekali per komputer (rumah / kantor / PC baru).
REM  Jalankan setelah copy folder dari flash disk atau clone GitHub.
REM  Syarat: Python 3.10+ sudah terinstal dan ada di PATH.
REM ============================================================
setlocal
set "SCRIPT_DIR=%~dp0"
cd /d "%SCRIPT_DIR%"

REM Simpan Chromium DI DALAM folder ini supaya portable (ikut pindah).
set "PLAYWRIGHT_BROWSERS_PATH=%SCRIPT_DIR%.playwright"

echo [1/3] Mengecek Python...
python --version || (echo Python tidak ditemukan. Install Python 3.10+ dulu. & pause & exit /b 1)

echo [2/3] Install dependency Python...
python -m pip install --upgrade pip
python -m pip install -r requirements.txt || (echo Gagal install requirements. & pause & exit /b 1)

echo [3/3] Download Chromium untuk Playwright (sekali saja, ~150MB)...
python -m playwright install chromium || (echo Gagal download Chromium. & pause & exit /b 1)

echo.
echo ============================================================
echo  Setup selesai. Jalankan start_headless.bat untuk memulai.
echo ============================================================
pause
