@echo off
setlocal

set "SCRIPT_DIR=%~dp0"

start "Live Scraper API" cmd /k "cd /d ""%SCRIPT_DIR%"" && python api_server.py"
