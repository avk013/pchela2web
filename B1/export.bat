@echo off
chcp 1251 > nul

:: === НАСТРОЙКИ ПУТЕЙ ===
set FB_BIN=C:\Program Files (x86)\firebird\bin
set EXPORT_DIR=C:\4export
set OUTPUT_FILE=%EXPORT_DIR%\fb_last_month.csv

:: === НАСТРОЙКИ ПОДКЛЮЧЕНИЯ ===
set DB_USER=SYSDBA
set DB_PASS=masterkey

:: Путь к живой базе
set LIVE_DB=127.0.0.1:"C:\Program Files\Пчела5.6\Base\bars.fdb"

:: === ГЕНЕРАЦИЯ УНИКАЛЬНОГО ИМЕНИ ФАЙЛА ===
set TIMESTAMP=%TIME::=%
set TIMESTAMP=%TIMESTAMP: =0%
set TIMESTAMP=%DATE%%TIMESTAMP:~0,4%

set BACKUP_FBK=%EXPORT_DIR%\temp_bk%TIMESTAMP%.fbk
set COPY_FDB=%EXPORT_DIR%\temp_db_%TIMESTAMP%.fdb
set COPY_FDB2=127.0.0.1:%COPY_FDB%

:: =========================================================================
if not exist "%EXPORT_DIR%" mkdir "%EXPORT_DIR%"

echo 1. Создание снимка живой базы...
"%FB_BIN%\gbak.exe" -b -user %DB_USER% -password %DB_PASS% %LIVE_DB% "%BACKUP_FBK%"
if errorlevel 1 goto :err_backup
timeout /t 10

echo 2. Разворачивание временной копии...
"%FB_BIN%\gbak.exe" -c -user %DB_USER% -password %DB_PASS% "%BACKUP_FBK%" %COPY_FDB2%
if errorlevel 1 goto :err_restore

if exist "%BACKUP_FBK%" del /f /q "%BACKUP_FBK%"

echo 3. Экспорт данных в CSV...
set SQL_QUERY="SELECT * FROM ARCH_HOURS"
fbexport.exe -Sc -H 127.0.0.1 -U %DB_USER% -P %DB_PASS% -D %COPY_FDB% -Q %SQL_QUERY% -B ";" -F "%OUTPUT_FILE%" -A WIN1251
if errorlevel 1 goto :err_export

echo [УСПЕХ] Экспорт завершен!
goto :end

:err_backup
echo [ОШИБКА] Не удалось сделать снимок базы.
pause
goto :end

:err_restore
echo [ОШИБКА] Не удалось развернуть снимок базы.
pause
goto :end

:err_export
echo [ОШИБКА] Ошибка при выгрузке данных.
pause

:end
echo 5. Очистка временных файлов...
if exist "%BACKUP_FBK%" del /f /q "%BACKUP_FBK%" 2>nul
if exist "%COPY_FDB%"   del /f /q "%COPY_FDB%"   2>nul
for %%f in ("%EXPORT_DIR%\temp_db_*.fdb") do del /f /q "%%f" 2>nul
for %%f in ("%EXPORT_DIR%\temp_bk*.fbk") do del /f /q "%%f" 2>nul

echo Работа скрипта завершена.
pause