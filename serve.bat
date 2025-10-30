@echo off
powershell -WindowStyle Hidden -Command "Start-Process php -ArgumentList '-S 0.0.0.0:8000' -WindowStyle Hidden"
