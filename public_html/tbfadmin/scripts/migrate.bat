@echo off
REM tbfadmin\scripts\migrate.bat

set ROOT=%~dp0..
set CREATE_SQL=%ROOT%\db\migrations\20250903_create_verify_sessions.sql
set DROP_SQL=%ROOT%\db\migrations\20250903_drop_verify_sessions.sql

IF "%1"=="apply" (
  php "%ROOT%\db\run_migration.php" "%CREATE_SQL%"
  GOTO :eof
)

IF "%1"=="rollback" (
  php "%ROOT%\db\run_migration.php" "%DROP_SQL%"
  GOTO :eof
)

IF "%1"=="file" (
  IF "%2"=="" (
    echo Usage: scripts\migrate.bat file path\to\file.sql
    GOTO :eof
  )
  php "%ROOT%\db\run_migration.php" "%2%"
  GOTO :eof
)

echo Usage:
echo   scripts\migrate.bat apply
echo   scripts\migrate.bat rollback
echo   scripts\migrate.bat file path\to\file.sql
