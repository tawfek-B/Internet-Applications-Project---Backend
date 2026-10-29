@echo off
set "PHP84=%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe"
if not exist "%PHP84%\php.exe" (
    echo PHP 8.4 not found. Install: winget install PHP.PHP.8.4 --source winget
    exit /b 1
)
set "PATH=%PHP84%;%PATH%"
echo.
"%PHP84%\php.exe" -v
echo.
echo This window now uses PHP 8.4 first. Try: php -v
echo.
