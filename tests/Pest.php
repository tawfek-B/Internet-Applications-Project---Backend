<?php

use App\Models\User;
use App\Services\Inventory\InventoryClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

function apiToken(User $user): string
{
    return auth('api')->login($user);
}

function withBearerToken(User $user): array
{
    return ['Authorization' => 'Bearer '.apiToken($user)];
}

function seedInventory(int $productId, int $quantity): void
{
    app(InventoryClientInterface::class)->setQuantity($productId, $quantity);
}
