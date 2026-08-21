@echo off
cd /d "%~dp0"

echo ====================================================
echo KANBAN PPIC PRINT AGENT STARTING...
echo ====================================================
echo.

python --version >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Python is not installed or not in PATH.
    echo Please run install-dependencies.bat first.
    echo.
    pause
    exit /b 1
)

python agent.py

echo.
echo ====================================================
echo Print Agent stopped.
echo ====================================================
pause
