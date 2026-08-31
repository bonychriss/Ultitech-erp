@echo off
REM Start Auto-Deploy Watcher for InfinityFree
REM This will watch for file changes and automatically deploy to InfinityFree

echo Starting Auto-Deploy Watcher...
echo.

REM Check if PowerShell is available
powershell.exe -ExecutionPolicy Bypass -File "%~dp0auto-deploy.ps1"

pause

