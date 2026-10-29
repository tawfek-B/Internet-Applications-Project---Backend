<?php

namespace App\Services\Inventory;

class FakeInventoryClient implements InventoryClientInterface
{
    /** @var array<int, int> */
    private array $stock = [];

    /** @var array<string, list<array{product_id: int, quantity: int}>> */
    private array $reservations = [];

    public function getQuantity(int $productId): int
    {
        return $this->stock[$productId] ?? 0;
    }

    public function reserve(string $reservationId, array $items): bool
    {
        if (isset($this->reservations[$reservationId])) {
            throw InventoryException::rejected("reservation already exists: {$reservationId}");
        }

        foreach ($items as $item) {
            $available = $this->getQuantity($item['product_id']);
            if ($available < $item['quantity']) {
                throw InventoryException::insufficientStock(
                    "insufficient stock for product_id {$item['product_id']}: requested {$item['quantity']}, available {$available}"
                );
            }
        }

        foreach ($items as $item) {
            $this->stock[$item['product_id']] -= $item['quantity'];
        }

        $this->reservations[$reservationId] = $items;

        return true;
    }

    public function release(string $reservationId): void
    {
        if (! isset($this->reservations[$reservationId])) {
            return;
        }

        foreach ($this->reservations[$reservationId] as $item) {
            $this->stock[$item['product_id']] = ($this->stock[$item['product_id']] ?? 0) + $item['quantity'];
        }

        unset($this->reservations[$reservationId]);
    }

    public function setQuantity(int $productId, int $quantity): void
    {
        $this->stock[$productId] = $quantity;
    }
}
