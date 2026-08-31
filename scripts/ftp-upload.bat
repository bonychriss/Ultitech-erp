@echo off
setlocal EnableDelayedExpansion
cd /d "%~dp0.."

call "%~dp0ftp-load-config.bat"
if errorlevel 1 exit /b 1

if not defined FTP_PASS (
    echo Create scripts\ftp-password.txt with your password on one line.
    echo   notepad scripts\ftp-password.txt
    exit /b 1
)

if "%~1"=="" (
    echo.
    echo Usage: scripts\ftp-upload.bat file1.php path\to\file2.php ...
    echo.
    echo Host:   %FTP_HOST%
    echo User:   %FTP_USER%
    echo Remote: %FTP_REMOTE%
    echo.
    exit /b 1
)

set "CURL_SSL="
if defined FTP_SSL set "CURL_SSL=--ssl-reqd"

echo Uploading to ftp://%FTP_HOST%%FTP_REMOTE%/ ...
echo.

:next_file
if "%~1"=="" goto done

set "FILE=%~1"
if not exist "!FILE!" (
    echo SKIP ^(not found^): !FILE!
    shift
    goto next_file
)

set "REL=!FILE:\=/!"
set "URL=ftp://%FTP_HOST%%FTP_REMOTE%/!REL!"

curl.exe --ftp-create-dirs -sS -f %CURL_SSL% -T "!FILE!" -u "%FTP_USER%:%FTP_PASS%" "!URL!"
if errorlevel 1 (
    echo FAILED: !FILE!
    set "FAIL=1"
) else (
    echo OK:     !FILE!
)

shift
goto next_file

:done
echo.
if defined FAIL (
    echo Some uploads failed. Run: scripts\ftp-test-connection.bat
    exit /b 1
)
echo All uploads finished.
exit /b 0
