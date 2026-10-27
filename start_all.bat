@echo off
title KotTraLuy System Starter

echo Starting Laravel Backend...
start cmd /k "cd /d D:\laragon\www\KotTraLuy\backend && php -S 127.0.0.1:8000 -t public public\router.php"

echo Starting Vite Frontend...
start cmd /k "cd /d D:\laragon\www\KotTraLuy\frontend && npm run dev"

echo Starting Ngrok Static Tunnel...
start cmd /k "ngrok http 8000 --url=superior-claim-guiding.ngrok-free.dev"

echo All services are running!
