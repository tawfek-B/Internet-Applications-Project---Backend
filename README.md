# Internet Applications Project - Backend

Laravel REST API (`/api/v1`) with JWT auth, four modules (Users, Products, Orders, Notifications), Docker/Sail, and a Node.js gRPC inventory microservice.

## PHP version (Windows / XAMPP)

This project needs **PHP 8.4.1+** (Laravel 13 + PHPUnit 13). XAMPP 8.3 will fail on `composer install` and `php artisan`.

Before each terminal session for this project:

```powershell
. .\scripts\use-php84.ps1
php -v   # must show 8.4.x
```

Install PHP 8.4 if missing: `winget install PHP.PHP.8.4 --source winget`

## Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate --seed
php artisan storage:link
cd inventory-service && npm ci && npm start
php artisan serve
```

With Docker Sail:

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail artisan storage:link
```

## Inventory demos

```bash
cd inventory-service && npm start
node scripts/demo-race-condition.js
# Stop inventory service, then:
node scripts/demo-service-down.js
```

## Tests

```bash
php artisan test --compact
```

Documentation: `docs/openapi.yaml`, `docs/postman_collection.json`.
