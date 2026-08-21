@echo off
cd /d "%~dp0"

echo ====================================================
echo KAIZEN TRACKER PRINT AGENT STARTING...
echo ====================================================
echo.

python agent.py

echo.
echo ====================================================
echo Print Agent stopped.
echo ====================================================
pause
