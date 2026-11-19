@echo off
setlocal EnableDelayedExpansion
cd /d "%~dp0.."
call "%~dp0ftp-load-config.bat"
if errorlevel 1 exit /b 1
if not defined FTP_PASS (
  echo Missing ftp-password.txt
  exit /b 1
)

REM Force app root (not /public_html/ultimate)
set "FTP_REMOTE=/public_html"
set "CURL_SSL="
if defined FTP_SSL set "CURL_SSL=--ssl-reqd"

if "%~1"=="" (
  echo Usage: %~nx0 relative\path\file.php ...
  exit /b 1
)

:next
if "%~1"=="" goto done
set "FILE=%~1"
if not exist "!FILE!" (
  echo SKIP: !FILE!
  shift
  goto next
)
set "REL=!FILE:\=/!"
set "URL=ftp://%FTP_HOST%%FTP_REMOTE%/!REL!"
echo PUT !REL! -^> !URL!
curl.exe --ftp-create-dirs -sS -f %CURL_SSL% -T "!FILE!" -u "%FTP_USER%:%FTP_PASS%" "!URL!"
if errorlevel 1 (
  echo FAILED: !FILE!
  set FAIL=1
) else (
  echo OK: !FILE!
)
shift
goto next

:done
if defined FAIL exit /b 1
echo All OK
exit /b 0
