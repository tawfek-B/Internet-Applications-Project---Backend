<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\InventoryClientInterface;

it('creates an order when inventory reservation succeeds', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['price' => 25.50]);
    seedInventory($product->id, 5);

    $response = $this->postJson('/api/v1/orders', [
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2],
        ],
    ], withBearerToken($user));

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('order.status', OrderStatus::Pending->value);

    $this->assertDatabaseHas('orders', ['user_id' => $user->id]);
    expect(app(InventoryClientInterface::class)->getQuantity($product->id))->toBe(3);
});

it('rejects order when inventory is insufficient', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    seedInventory($product->id, 1);

    $response = $this->postJson('/api/v1/orders', [
        'items' => [
            ['product_id' => $product->id, 'quantity' => 3],
        ],
    ], withBearerToken($user));

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $this->assertDatabaseCount('orders', 0);
    expect(app(InventoryClientInterface::class)->getQuantity($product->id))->toBe(1);
});

it('allows admin to update order status', function () {
    $admin = User::factory()->admin()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    $response = $this->putJson("/api/v1/orders/{$order->id}/status", [
        'status' => OrderStatus::Delivering->value,
    ], withBearerToken($admin));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Order status updated successfully');
});
