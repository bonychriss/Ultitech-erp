@echo off
setlocal
cd /d "%~dp0.."

call "%~dp0ftp-load-config.bat"
if errorlevel 1 exit /b 1

if not defined FTP_PASS (
    echo Create scripts\ftp-password.txt with your password on one line.
    exit /b 1
)

set "CURL_SSL="
if defined FTP_SSL set "CURL_SSL=--ssl-reqd"

echo Remote folder: ftp://%FTP_HOST%%FTP_REMOTE%/
echo.

curl.exe -sS %CURL_SSL% --list-only -u "%FTP_USER%:%FTP_PASS%" "ftp://%FTP_HOST%%FTP_REMOTE%/"
if errorlevel 1 (
    echo.
    echo List failed. Run: scripts\ftp-test-connection.bat
    echo If upload works but list fails, that is OK - use ftp-upload.bat for deploys.
    exit /b 1
)

exit /b 0
