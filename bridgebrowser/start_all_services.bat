@echo off
echo ========================================
echo    LIVE MATCH & TELEGRAM MONITOR
echo ========================================
echo.
echo [INFO] Starting all services...
echo [INFO] This will start:
echo   - API Server (Port 5000)
echo   - Telegram Alert Monitor
echo.
echo [INFO] Press Ctrl+C to stop all services
echo.
echo ========================================
echo.

REM Install dependencies
echo [INFO] Installing Python dependencies...
pip install requests selenium beautifulsoup4 flask
echo.

REM Start API Server in background
echo [INFO] Starting API Server...
start "API Server" /min cmd /c "python api_server.py"

REM Wait a moment for API server to start
echo [INFO] Waiting for API server to initialize...
timeout /t 5 /nobreak >nul

REM Start Telegram Monitor
echo [INFO] Starting Telegram Monitor...
echo [INFO] Monitor will check for 1H 40' 0-0 conditions
echo.
python telegram_alert_monitor.py

echo.
echo [INFO] All services stopped.
pause