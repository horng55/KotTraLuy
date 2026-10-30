@echo off
title KotTraLuy System Starter
set "PROJECT_ROOT=%~dp0"

echo Starting Laravel Backend...
start "KotTraLuy Backend" /D "%PROJECT_ROOT%backend" cmd /k "php -S 127.0.0.1:8000 -t public public\router.php"

echo Starting Vite Frontend...
start "KotTraLuy Frontend" /D "%PROJECT_ROOT%frontend" cmd /k "npm run dev"

echo All services are running!
echo Frontend: http://localhost:5173
echo Backend:  http://localhost:8000
