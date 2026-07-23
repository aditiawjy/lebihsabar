@echo off
REM Jalankan V-Soccer Goal Logger headless (tidak perlu buka Chrome manual).
REM Pastikan Apache (XAMPP) sudah jalan dulu.
cd /d "%~dp0"
echo Menjalankan V-Soccer Headless Runner... (Ctrl+C untuk berhenti)
python vsoccer_headless.py
pause
