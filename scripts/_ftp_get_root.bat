@echo off
setlocal EnableDelayedExpansion
cd /d "%~dp0.."
call "%~dp0ftp-load-config.bat"
if errorlevel 1 exit /b 1
set "FTP_REMOTE=/public_html"
set "REMOTE=%~1"
set "LOCAL=%~2"
if "%REMOTE%"=="" (
  echo Usage: %~nx0 remote/path local\path
  exit /b 1
)
if "%LOCAL%"=="" set "LOCAL=%REMOTE:/=_\%"
curl.exe -sS -f -u "%FTP_USER%:%FTP_PASS%" "ftp://%FTP_HOST%%FTP_REMOTE%/%REMOTE%" -o "%LOCAL%"
if errorlevel 1 (
  echo FAILED get %REMOTE%
  exit /b 1
)
echo OK: %REMOTE% -^> %LOCAL%
exit /b 0
