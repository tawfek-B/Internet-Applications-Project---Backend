<?php

use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\FakeInventoryClient;
use App\Services\Inventory\GrpcInventoryClient;
use App\Services\Inventory\InventoryClientInterface;

it('allows only one successful reservation when stock is limited', function () {
    $product = Product::factory()->create();
    seedInventory($product->id, 1);

    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    $this->postJson('/api/v1/orders', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ], withBearerToken($firstUser))->assertCreated();

    $this->postJson('/api/v1/orders', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ], withBearerToken($secondUser))->assertStatus(422);

    expect(app(InventoryClientInterface::class)->getQuantity($product->id))->toBe(0);
});

it('returns inventory unavailable response when grpc client cannot connect', function () {
    config([
        'services.inventory.driver' => 'grpc',
        'services.inventory.host' => '127.0.0.1:59999',
        'services.inventory.timeout_seconds' => 1,
    ]);

    app()->forgetInstance(InventoryClientInterface::class);
    app()->singleton(InventoryClientInterface::class, fn () => new GrpcInventoryClient(
        nodeBinary: config('services.inventory.node_binary'),
        clientScript: config('services.inventory.client_script'),
        grpcHost: config('services.inventory.host'),
        timeoutSeconds: 1,
    ));

    $product = Product::factory()->create();
    $user = User::factory()->create();

    $this->getJson("/api/v1/products/{$product->id}")
        ->assertStatus(503)
        ->assertJsonPath('success', false);

    $this->postJson('/api/v1/orders', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ], withBearerToken($user))
        ->assertStatus(503)
        ->assertJsonPath('errors.code', 'INVENTORY_UNAVAILABLE');

    app()->forgetInstance(InventoryClientInterface::class);
    app()->singleton(InventoryClientInterface::class, fn () => new FakeInventoryClient);
});
