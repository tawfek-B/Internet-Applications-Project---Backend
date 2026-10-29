<?php

use App\Models\Notification;
use App\Models\User;

it('lists authenticated user notifications', function () {
    $user = User::factory()->create();
    Notification::factory()->count(2)->create(['user_id' => $user->id]);
    Notification::factory()->create();

    $response = $this->getJson('/api/v1/notifications', withBearerToken($user));

    $response->assertOk()
        ->assertJsonCount(2, 'data.notifications');
});

it('marks a notification as read for its owner', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->create(['user_id' => $user->id, 'is_read' => false]);

    $response = $this->patchJson(
        "/api/v1/notifications/{$notification->id}/read",
        [],
        withBearerToken($user),
    );

    $response->assertOk()
        ->assertJsonPath('data.notification.is_read', true);
});
