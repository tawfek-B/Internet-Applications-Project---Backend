@echo off
cd /d "%~dp0.."
docker compose exec laravel.test php artisan %*
