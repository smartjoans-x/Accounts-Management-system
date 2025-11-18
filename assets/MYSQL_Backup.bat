@echo off

:: Get date in a reliable YYYY-MM-DD format
for /f "tokens=2 delims==" %%I in ('wmic os get localdatetime /format:list') do set datetime=%%I
set "backup_date=%datetime:~0,4%-%datetime:~4,2%-%datetime:~6,2%"

:: Run the backup command
"C:\xampp\mysql\bin\mysqldump.exe" -u root -p1234567 sld_accounts > "D:\MYSQL Backup\sld_accounts_backup_%backup_date%.sql"

echo Backup complete: sld_accounts_backup_%backup_date%.sql