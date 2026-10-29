<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

it('registers a customer and returns jwt token', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['access_token', 'user']);

    $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'is_admin' => false]);
});

it('logs in with valid credentials', function () {
    User::factory()->create([
        'email' => 'login@example.com',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'login@example.com',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['access_token']);
});

it('rate limits repeated failed login attempts', function () {
    RateLimiter::clear('login');

    User::factory()->create(['email' => 'limited@example.com']);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'limited@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'limited@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(429);
});
