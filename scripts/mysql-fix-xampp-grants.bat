@echo off
setlocal
echo.
echo XAMPP MariaDB grant repair (fixes error 1130 Host localhost not allowed)
echo Run this script as Administrator if stop/start fails.
echo.

set "MYSQL_BIN=c:\xampp\mysql\bin"
set "MY_INI=c:\xampp\mysql\bin\my.ini"
set "SQL_FILE=%~dp0mysql-fix-xampp-grants.sql"

echo [1/5] Stopping mysqld...
taskkill /F /IM mysqld.exe >nul 2>&1
ping 127.0.0.1 -n 4 >nul

echo [2/5] Starting mysqld with --skip-grant-tables...
start "" /B "%MYSQL_BIN%\mysqld.exe" --defaults-file="%MY_INI%" --skip-grant-tables --skip-networking
timeout /t 6 /nobreak >nul

echo [3/5] Applying grants...
"%MYSQL_BIN%\mysql.exe" -u root < "%SQL_FILE%"
if errorlevel 1 (
    echo Grant SQL failed. Trying without skip-networking...
    taskkill /F /IM mysqld.exe >nul 2>&1
    timeout /t 2 /nobreak >nul
    start "" /B "%MYSQL_BIN%\mysqld.exe" --defaults-file="%MY_INI%" --skip-grant-tables
    timeout /t 6 /nobreak >nul
    "%MYSQL_BIN%\mysql.exe" -u root < "%SQL_FILE%"
)

echo [4/5] Restarting mysqld normally...
taskkill /F /IM mysqld.exe >nul 2>&1
ping 127.0.0.1 -n 4 >nul
start "" /B "%MYSQL_BIN%\mysqld.exe" --defaults-file="%MY_INI%"
timeout /t 5 /nobreak >nul

echo [5/5] Testing connection...
"%MYSQL_BIN%\mysql.exe" -h 127.0.0.1 -u root -e "SELECT 'OK' AS status, USER() AS connected_as;"
if errorlevel 1 (
    echo.
    echo Still failing. Open XAMPP Control Panel, stop MySQL, start MySQL, then run this script again as Admin.
    exit /b 1
)

echo.
echo SUCCESS. Reload http://localhost/public_html/select-module.php
exit /b 0
