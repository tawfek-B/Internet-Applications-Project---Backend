<?php

use App\Models\Order;
use App\Models\User;

it('allows a customer to view their own order by id', function () {
    $customer = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $customer->id]);

    $this->getJson("/api/v1/orders/{$order->id}", withBearerToken($customer))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('order.id', $order->id);
});
