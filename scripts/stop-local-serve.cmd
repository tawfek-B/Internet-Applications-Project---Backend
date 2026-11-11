@echo off
REM Stop local PHP artisan serve so port 8000 is only Docker.
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$p = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue | ForEach-Object { Get-Process -Id $_.OwningProcess -EA SilentlyContinue } | Where-Object { $_.ProcessName -eq 'php' }; foreach ($x in $p) { Write-Host ('Stopped php PID ' + $x.Id); Stop-Process -Id $x.Id -Force }"
echo Use Postman: http://127.0.0.1:8000 (not localhost if php was running)
