@echo off
setlocal
cd /d "%~dp0"
title UltiTech ERP Desktop Dev (localhost)

echo.
echo  UltiTech ERP - Desktop DEV mode (local XAMPP)
echo  Loading: http://localhost/public_html/login.php
echo.
echo  - Start XAMPP Apache first
echo  - ERP changes: refresh with Ctrl+R in the app window
echo  - Electron changes: close app, run this script again
echo  - Close this window to quit
echo.

if not exist "node_modules\" (
  echo Installing dependencies...
  call npm install
  if errorlevel 1 goto :fail
)

call npm run start:local
if errorlevel 1 goto :fail
exit /b 0

:fail
echo.
echo Dev app exited with an error.
pause
exit /b 1
