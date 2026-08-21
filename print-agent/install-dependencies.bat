@echo off
setlocal enabledelayedexpansion
cd /d "%~dp0"

echo ====================================================
echo KANBAN PPIC PRINT AGENT DEPLOYMENT INSTALLER
echo ====================================================
echo.

:: 1. Check Python availability
echo [STEP 1/4] Checking Python availability...
python --version >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Python is not installed or not in PATH.
    echo Please install Python 3.13.x 64-bit and try again.
    echo.
    pause
    exit /b 1
)

for /f "tokens=*" %%i in ('python --version') do set PYTHON_VERSION=%%i
echo Python version detected: %PYTHON_VERSION%

:: Check python version is exactly 3.13.x
python -c "import sys; sys.exit(0 if sys.version_info[:2] == (3, 13) else 1)" >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Unsupported Python version detected.
    echo This bundle is standardized on Python 3.13.x.
    echo.
    pause
    exit /b 2
)

:: Check python architecture is 64-bit
python -c "import struct, sys; sys.exit(0 if struct.calcsize('P') * 8 == 64 else 1)" >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] 32-bit Python detected.
    echo This bundle requires 64-bit Python.
    echo.
    pause
    exit /b 3
)
echo Python architecture verified: 64-bit.
echo.

:: 2. Check wheels folder existence
echo [STEP 2/4] Verifying offline wheels directory...
if not exist "wheels\" (
    echo [ERROR] Offline wheels folder not found.
    echo This installer requires a local wheels directory for full offline installation.
    echo.
    pause
    exit /b 4
)
echo Found wheels directory containing local packages.
echo.

:: 3. Install dependencies from local wheels directory
echo [STEP 3/4] Installing dependencies from local wheels only...
python -m pip install --no-index --find-links=wheels -r requirements.txt
if %errorlevel% neq 0 (
    echo.
    echo [ERROR] Failed to install dependencies from local wheels.
    echo.
    pause
    exit /b 5
)
echo.

:: 4. Verify imports
echo [STEP 4/4] Verifying installed packages...
python -c "import requests" >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Import verification failed: requests library could not be imported.
    echo.
    pause
    exit /b 6
)

python -c "import win32print" >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Import verification failed: win32print library could not be imported.
    echo.
    pause
    exit /b 7
)

echo.
echo ====================================================
echo STATUS: SUCCESS
echo All dependencies installed and verified successfully.
echo ====================================================
echo.
pause
exit /b 0
