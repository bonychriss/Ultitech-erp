@echo off
setlocal

echo UltiTech ERP - Client setup
echo.

where node >nul 2>&1
if errorlevel 1 (
  echo Node.js is required. Install from https://nodejs.org/
  exit /b 1
)

pushd "%~dp0desktop"
echo [1/2] Installing desktop dependencies...
call npm install
if errorlevel 1 goto :fail
popd

pushd "%~dp0android"
echo [2/2] Installing Android dependencies...
call npm install
if errorlevel 1 goto :fail
call npm run sync
if errorlevel 1 goto :fail
popd

echo.
echo Setup complete.
echo   Desktop:  cd client-apps\desktop ^&^& npm start
echo   Android:  cd client-apps\android ^&^& npx cap open android
goto :eof

:fail
echo Setup failed.
exit /b 1
