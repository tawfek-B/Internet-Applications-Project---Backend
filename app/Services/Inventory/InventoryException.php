<?php

namespace App\Services\Inventory;

use Exception;

class InventoryException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 503,
        public readonly string $errorCode = 'INVENTORY_ERROR',
    ) {
        parent::__construct($message);
    }

    public static function unavailable(string $message = 'Inventory service is unavailable. Please try again later.'): self
    {
        return new self($message, 503, 'INVENTORY_UNAVAILABLE');
    }

    public static function insufficientStock(string $message): self
    {
        return new self($message, 422, 'INSUFFICIENT_STOCK');
    }

    public static function rejected(string $message): self
    {
        return new self($message, 422, 'INVENTORY_REJECTED');
    }
}
