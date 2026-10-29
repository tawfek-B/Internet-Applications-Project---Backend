<?php

use App\Enums\OrderStatus;
use App\Models\Product;
use App\Models\User;

it('rejects add to cart when quantity exceeds product stock', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['stock' => 3]);

    $this->postJson("/api/v1/addToCart/{$product->id}", [
        'quantity' => 2,
    ], withBearerToken($user))->assertOk();

    $this->postJson("/api/v1/addToCart/{$product->id}", [
        'quantity' => 2,
    ], withBearerToken($user))
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect($user->cart()->first()->quantity)->toBe(2);
});

it('adds a product to the cart', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['price' => 10]);

    $response = $this->postJson("/api/v1/addToCart/{$product->id}", [
        'quantity' => 2,
    ], withBearerToken($user));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('cart.0.product_id', $product->id)
        ->assertJsonPath('cart.0.quantity', 2)
        ->assertJsonPath('totalItems', 2)
        ->assertJsonPath('totalPrice', 20);
});

it('returns the cart with string totals', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['price' => 15]);
    $user->cart()->create(['product_id' => $product->id, 'quantity' => 3]);

    $response = $this->getJson('/api/v1/cart', withBearerToken($user));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('totalItems', '3')
        ->assertJsonPath('totalPrice', '45');
});

it('checks out the cart without inventory via simple checkout', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['price' => 20, 'stock' => 10]);
    $user->cart()->create(['product_id' => $product->id, 'quantity' => 2]);

    $response = $this->postJson('/api/v1/checkout2', [], withBearerToken($user));

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('order.order_items.0.quantity', 2);

    expect($product->fresh()->stock)->toBe(8);
    expect($user->cart()->count())->toBe(0);
});

it('checks out the cart and reserves inventory', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['price' => 20]);
    seedInventory($product->id, 5);
    $user->cart()->create(['product_id' => $product->id, 'quantity' => 2]);

    $response = $this->postJson('/api/v1/checkout', [], withBearerToken($user));

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'order' => [
                'user_id',
                'id',
                'order_items' => [['id', 'order_id', 'product_id', 'quantity']],
            ],
        ]);

    expect($user->cart()->count())->toBe(0);
});

it('lists orders in tawfek format', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['price' => 10]);
    seedInventory($product->id, 10);

    $user->cart()->create(['product_id' => $product->id, 'quantity' => 1]);
    $this->postJson('/api/v1/checkout', [], withBearerToken($user));

    $user->cart()->create(['product_id' => $product->id, 'quantity' => 2]);
    $this->postJson('/api/v1/checkout', [], withBearerToken($user));

    $response = $this->getJson('/api/v1/orders', withBearerToken($user));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'orders')
        ->assertJsonStructure([
            'orders' => [[
                'user_id',
                'id',
                'status',
                'total_price',
                'total_items',
                'order_items' => [[
                    'id',
                    'order_id',
                    'product_id',
                    'quantity',
                    'product' => ['id', 'name', 'description', 'price', 'stock', 'image_url'],
                ]],
            ]],
        ]);
});

it('shows a single order for the owner', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    seedInventory($product->id, 3);
    $user->cart()->create(['product_id' => $product->id, 'quantity' => 1]);

    $checkout = $this->postJson('/api/v1/checkout', [], withBearerToken($user))->json('order.id');

    $response = $this->getJson("/api/v1/orders/{$checkout}", withBearerToken($user));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('order.id', $checkout);
});

it('updates order status with put for admin only', function () {
    $admin = User::factory()->admin()->create();
    $customer = User::factory()->create();
    $product = Product::factory()->create();
    seedInventory($product->id, 2);
    $customer->cart()->create(['product_id' => $product->id, 'quantity' => 1]);

    $orderId = $this->postJson('/api/v1/checkout', [], withBearerToken($customer))->json('order.id');

    $response = $this->putJson("/api/v1/orders/{$orderId}/status", [
        'status' => OrderStatus::Delivering->value,
    ], withBearerToken($admin));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Order status updated successfully');
});
