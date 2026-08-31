@echo off
setlocal
cd /d "%~dp0.."

call "%~dp0ftp-load-config.bat"
if errorlevel 1 exit /b 1

if not defined FTP_PASS (
    echo.
    echo Create scripts\ftp-password.txt with your FTP password on ONE line.
    echo   copy scripts\ftp-password.txt.example scripts\ftp-password.txt
    echo   notepad scripts\ftp-password.txt
    echo.
    exit /b 1
)

set "CURL_SSL="
if defined FTP_SSL set "CURL_SSL=--ssl-reqd"

echo Testing FTP upload of ftp-test.txt ...
echo   Host:   %FTP_HOST%
echo   User:   %FTP_USER%
echo   Remote: %FTP_REMOTE%
echo.

if not exist "ftp-test.txt" (
    echo Hello from Cursor FTP test> ftp-test.txt
)

set "URL=ftp://%FTP_HOST%%FTP_REMOTE%/ftp-test.txt"
curl.exe --ftp-create-dirs -sS -f %CURL_SSL% -T "ftp-test.txt" -u "%FTP_USER%:%FTP_PASS%" "%URL%"
if errorlevel 1 (
    echo.
    echo UPLOAD FAILED.
    echo Try 1: set FTP_SSL=1 in scripts\ftp-deploy.local.cmd
    echo Try 2: change FTP_REMOTE to /public_html if files are not under /ultimate
    echo Try 3: reset FTP password in cPanel and update ftp-password.txt
    exit /b 1
)

echo.
echo SUCCESS. Check cPanel File Manager for ftp-test.txt under %FTP_REMOTE%
exit /b 0
