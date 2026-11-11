# Start Laravel + inventory + MySQL in Docker (.env.docker overrides DB/inventory inside containers).
Set-Location (Join-Path $PSScriptRoot '..')

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error 'Docker Desktop is not installed or not on PATH.'
    exit 1
}

# Local `php artisan serve` on 127.0.0.1:8000 wins over Docker — stop it so Postman hits the container.
$localPhp8000 = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue |
    Where-Object { $_.OwningProcess -ne 0 } |
    ForEach-Object { Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue } |
    Where-Object { $_.ProcessName -eq 'php' }
foreach ($proc in $localPhp8000) {
    Write-Host "Stopping local php.exe (PID $($proc.Id)) on port 8000..." -ForegroundColor Yellow
    Stop-Process -Id $proc.Id -Force
}

docker compose up -d --build
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

Write-Host ''
Write-Host 'Waiting for Laravel container...' -ForegroundColor Cyan
Start-Sleep -Seconds 5

docker compose exec -T laravel.test php artisan migrate --force
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

docker compose exec -T laravel.test php artisan db:seed --force 2>&1 | Out-Host
if ($LASTEXITCODE -ne 0) {
    Write-Host ''
    Write-Host 'Seed skipped: users/data already exist (this is OK).' -ForegroundColor Yellow
    Write-Host 'For a clean DB run: .\scripts\artisan-docker.cmd migrate:fresh --seed' -ForegroundColor Cyan
}

Write-Host ''
Write-Host 'API:  http://127.0.0.1:8000/api/v1/products' -ForegroundColor Green
Write-Host 'Health: http://127.0.0.1:8000/up' -ForegroundColor Green
Write-Host 'Stop: docker compose down' -ForegroundColor Yellow
