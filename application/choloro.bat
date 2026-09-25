@echo off
:loop
cls
echo Updating...
curl http://localhost/chloro/
timeout /t 5 /nobreak >nul
goto loop