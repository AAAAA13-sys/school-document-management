@echo off
setlocal

REM ===================================================================
REM  PLP Document Management System (DMS) 1-BAT Kickstarter
REM  Starts MySQL, Database, Migrations, Background Worker & Web Server
REM ===================================================================

title DMS - Document Management System

REM Ensure current working directory is the repository root
cd /d "%~dp0"

REM Check if PowerShell is available
where powershell.exe >nul 2>&1
if errorlevel 1 (
    echo [ERROR] PowerShell is required to run the DMS Kickstarter.
    echo Please ensure powershell.exe is installed and present in your PATH.
    pause
    exit /b 1
)

REM Execute the kickstarter script with execution policy bypass
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\kickstart.ps1" %*
set "EXIT_CODE=%ERRORLEVEL%"

if not "%EXIT_CODE%"=="0" (
    echo:
    echo ===================================================================
    echo [DMS] Kickstarter encountered an issue - Exit Code: %EXIT_CODE%
    echo ===================================================================
    pause
)

exit /b %EXIT_CODE%
