@echo off
setlocal EnableDelayedExpansion
cd /d "%~dp0.."
call "%~dp0ftp-load-config.bat"
if errorlevel 1 exit /b 1
set "FTP_REMOTE=/public_html"
set "LOCAL=%~1"
set "REMOTE=%~2"
if "%LOCAL%"=="" (
  echo Usage: %~nx0 local\file remote/path
  exit /b 1
)
if "%REMOTE%"=="" (
  echo Usage: %~nx0 local\file remote/path
  exit /b 1
)
if not exist "%LOCAL%" (
  echo Missing local file: %LOCAL%
  exit /b 1
)
set "URL=ftp://%FTP_HOST%%FTP_REMOTE%/%REMOTE%"
echo PUT %LOCAL% -^> %URL%
curl.exe --ftp-create-dirs -sS -f -T "%LOCAL%" -u "%FTP_USER%:%FTP_PASS%" "%URL%"
if errorlevel 1 (
  echo FAILED
  exit /b 1
)
echo OK
exit /b 0
