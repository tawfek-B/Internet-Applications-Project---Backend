<?php

use App\Models\Product;
use App\Models\User;

it('lists products with pagination and category filter', function () {
    Product::factory()->create(['category' => 'books']);
    Product::factory()->count(2)->create(['category' => 'electronics']);

    $response = $this->getJson('/api/v1/products?category=books&per_page=1');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonCount(1, 'products');
});

it('shows product details with available quantity from inventory', function () {
    $product = Product::factory()->create();
    seedInventory($product->id, 7);

    $response = $this->getJson("/api/v1/products/{$product->id}");

    $response->assertOk()
        ->assertJsonPath('product.available_quantity', 7);
});

it('allows admin to create a product and seed inventory quantity', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Phone',
        'description' => 'Smart phone',
        'price' => 499.99,
        'category' => 'electronics',
        'initial_quantity' => 12,
    ], withBearerToken($admin));

    $response->assertCreated()
        ->assertJsonPath('product.name', 'Phone')
        ->assertJsonPath('product.available_quantity', 12);
});

it('rejects product creation for non admin users', function () {
    $customer = User::factory()->create();

    $this->postJson('/api/v1/products', [
        'name' => 'Blocked',
        'description' => 'Nope',
        'price' => 10,
        'category' => 'general',
        'initial_quantity' => 1,
    ], withBearerToken($customer))->assertForbidden();
});
