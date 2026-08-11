@echo off
cd /d "%~dp0"
if not exist logs mkdir logs
".venv\Scripts\python.exe" "scripts\daily_import.py" >> "logs\daily_import.log" 2>&1
