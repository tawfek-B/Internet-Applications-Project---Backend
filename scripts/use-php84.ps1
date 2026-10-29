# Must be DOT-sourced so PATH changes apply to YOUR window:
#   . .\scripts\use-php84.ps1
$php84 = "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe"

if (-not (Test-Path "$php84\php.exe")) {
    Write-Host ""
    Write-Host "PHP 8.4 is not installed. Run as Administrator:" -ForegroundColor Red
    Write-Host "  winget install PHP.PHP.8.4 --source winget" -ForegroundColor Yellow
    Write-Host ""
    return
}

$withoutXampp = ($env:Path -split ';' | Where-Object {
    $_ -and ($_ -notmatch '\\xampp\\php\\?')
}) -join ';'

$env:Path = "$php84;$withoutXampp"

$version = & "$php84\php.exe" -v 2>&1 | Select-Object -First 1
Write-Host ""
Write-Host "OK — active PHP for this window:" -ForegroundColor Green
Write-Host "  $version"
Write-Host ""
Write-Host "Now run:  php -v" -ForegroundColor Cyan
Write-Host "(must show 8.4, not 8.3)" -ForegroundColor Cyan
Write-Host ""
