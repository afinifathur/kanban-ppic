@echo off
cd /d "%~dp0"

echo ====================================================
echo KANBAN PPIC PRINT AGENT STARTING...
echo ====================================================
echo.

python agent.py

echo.
echo ====================================================
echo Print Agent stopped.
echo ====================================================
pause
