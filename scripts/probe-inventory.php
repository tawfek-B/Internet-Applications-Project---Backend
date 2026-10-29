<?php

use App\Services\Inventory\InventoryClientInterface;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $qty = app(InventoryClientInterface::class)->getQuantity(1);
    echo "OK — inventory reachable (product 1 available: {$qty})\n";
} catch (Throwable $e) {
    echo 'FAIL — '.$e->getMessage()."\n";
    exit(1);
}
