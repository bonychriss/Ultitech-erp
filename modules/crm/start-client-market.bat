@echo off
REM Start Client Market for CRM (production mode = much faster page loads).
cd /d "%~dp0client Market\frontend"
if not exist "node_modules\" (
  echo Installing dependencies...
  call npm install
)
if not exist ".next\BUILD_ID" (
  echo Building Client Market (first time only / after code changes)...
  call npm run build
)
echo.
echo Client Market: http://127.0.0.1:3000 (bound on all interfaces)
echo Keep this window open while using CRM Market.
echo.
call npm run start
