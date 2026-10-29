<?php

use App\Models\Order;
use App\Models\User;

it('rejects access to another users order', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $owner->id]);

    $this->getJson("/api/v1/orders/{$order->id}", withBearerToken($intruder))
        ->assertForbidden();
});

it('rejects order status updates from customers', function () {
    $customer = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $customer->id]);

    $this->patchJson("/api/v1/orders/{$order->id}/status", [
        'status' => 'delivering',
    ], withBearerToken($customer))->assertForbidden();
});

it('adds security headers to api responses', function () {
    $response = $this->getJson('/api/v1/products');

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});
