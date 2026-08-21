@echo off
cd /d "%~dp0"
echo ====================================================
echo KAIZEN TRACKER PRINT AGENT DEPENDENCY INSTALLER
echo ====================================================
echo.

if exist "wheels" (
    echo Local wheels directory found. Installing offline...
    pip install --no-index --find-links=wheels -r requirements.txt
) else (
    echo Offline wheels directory not found. Installing from internet...
    pip install -r requirements.txt
)

echo.
echo ====================================================
echo Dependency installation complete.
echo ====================================================
pause
