<?php

namespace App\Services\Inventory;

interface InventoryClientInterface
{
    public function getQuantity(int $productId): int;

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     */
    public function reserve(string $reservationId, array $items): bool;

    public function release(string $reservationId): void;

    public function setQuantity(int $productId, int $quantity): void;
}
