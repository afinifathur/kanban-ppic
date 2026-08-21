@echo off
setlocal enabledelayedexpansion
cd /d "%~dp0"

echo ====================================================
echo KANBAN PPIC PRINT AGENT ENVIRONMENT DIAGNOSTICS
echo ====================================================
echo.

:: 1. Diagnostic Timestamp and Agent Location
echo Agent Location: %CD%
echo Current Time  : %DATE% %TIME%
echo.

:: 2. Windows Version
echo [DIAGNOSTIC] Checking Windows Version...
for /f "tokens=*" %%a in ('ver') do (
    echo   Windows Version: %%a
)
echo.

:: 3. Python Location, Version, and Architecture
echo [DIAGNOSTIC] Checking Python Installation...
where python >nul 2>&1
if %errorlevel% neq 0 (
    echo   [WARNING] python.exe was not found in the System PATH.
    echo.
) else (
    for /f "tokens=*" %%a in ('where python') do (
        echo   python.exe Path: %%a
    )
    for /f "tokens=*" %%a in ('python --version 2^>^&1') do (
        echo   Python Version  : %%a
    )
    for /f "tokens=*" %%a in ('python -c "import struct; print(8 * struct.calcsize('P'))"') do (
        echo   Architecture    : %%a-bit
    )
)
echo.

:: 4. Check pip Version
echo [DIAGNOSTIC] Checking pip Installation...
python -m pip --version >nul 2>&1
if %errorlevel% neq 0 (
    echo   [WARNING] pip is not available or not linked to python.
) else (
    for /f "tokens=*" %%a in ('python -m pip --version') do (
        echo   pip Version: %%a
    )
)
echo.

:: 5. Checking Wheels Folder
echo [DIAGNOSTIC] Checking Wheels Folder...
if exist "wheels\" (
    echo   wheels/ folder: FOUND
    
    dir /b wheels\pywin32*.whl >nul 2>&1
    if !errorlevel! eq 0 (
        echo     pywin32 wheel  : FOUND
    ) else (
        echo     pywin32 wheel  : MISSING
    )

    dir /b wheels\requests*.whl >nul 2>&1
    if !errorlevel! eq 0 (
        echo     requests wheel : FOUND
    ) else (
        echo     requests wheel : MISSING
    )
) else (
    echo   wheels/ folder: MISSING
)
echo.

:: 6. Check Import Status
echo [DIAGNOSTIC] Verifying Package Imports...
python -c "import requests" >nul 2>&1
if %errorlevel% eq 0 (
    echo   requests import    : SUCCESS
) else (
    echo   requests import    : FAILED
)

python -c "import win32print" >nul 2>&1
if %errorlevel% eq 0 (
    echo   win32print import  : SUCCESS
) else (
    echo   win32print import  : FAILED
)
echo.

:: 7. List Windows Printers
echo [DIAGNOSTIC] Listing Installed Windows Printers...
powershell -Command "Get-Printer | Select-Object -ExpandProperty Name" 2>nul
if %errorlevel% neq 0 (
    echo   [WARNING] PowerShell printer enumeration failed. Attempting alternative...
    wmic printer get name 2>nul
)
echo.

echo ====================================================
echo DIAGNOSTICS COMPLETE
echo ====================================================
pause
