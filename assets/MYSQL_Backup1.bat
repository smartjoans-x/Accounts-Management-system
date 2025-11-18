@echo off
"C:\xampp\mysql\bin\mysqldump.exe" -u root -p"1234567" sld_accounts > "D:\MYSQL Backup\sld_accounts_backup_%date:~10,4%-%date:~7,2%-%date:~4,2%.sql"