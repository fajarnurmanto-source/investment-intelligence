@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0open-ii.ps1" %*
if errorlevel 1 pause
