@echo off
setlocal
cd /d "%~dp0"
title UltiTech ERP Desktop Dev (ultitech.io)

echo.
echo  UltiTech ERP - Desktop DEV mode
echo  Loading: https://ultitech.io
echo.
echo  - ERP changes: refresh with Ctrl+R in the app window
echo  - Electron changes: close app, run this script again
echo  - Close this window to quit
echo.

if not exist "node_modules\" (
  echo Installing dependencies...
  call npm install
  if errorlevel 1 goto :fail
)

call npm start
if errorlevel 1 goto :fail
exit /b 0

:fail
echo.
echo Dev app exited with an error.
pause
exit /b 1
