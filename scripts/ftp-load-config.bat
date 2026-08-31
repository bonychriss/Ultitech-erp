@echo off
REM Shared FTP settings loader for scripts\ftp-*.bat
REM Password with ( ) < > # etc. goes in ftp-password.txt (one line), NOT in CMD.

set "FTP_HOST=ftp.us.ultitech.io"
set "FTP_USER=ultitech.io"
set "FTP_REMOTE=/public_html/ultimate"

if exist "%~dp0ftp-deploy.local.cmd" (
    call "%~dp0ftp-deploy.local.cmd"
)

if exist "%~dp0ftp-password.txt" (
    for /f "usebackq delims=" %%P in (`powershell -NoProfile -Command "$p = Get-Content -LiteralPath '%~dp0ftp-password.txt' -Raw; if ($p) { $p.Trim() }"`) do set "FTP_PASS=%%P"
)

where curl.exe >nul 2>&1
if errorlevel 1 (
    echo ERROR: curl.exe not found. Windows 10+ includes it in System32.
    exit /b 1
)

exit /b 0
