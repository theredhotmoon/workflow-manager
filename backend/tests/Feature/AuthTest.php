<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('logs in with valid credentials and establishes a session', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'Login successful'])
        ->assertJsonPath('user.email', $user->email);

    $this->assertAuthenticatedAs($user);
});

it('rejects login with invalid credentials', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422)
        ->assertJson(['message' => 'The provided credentials do not match our records.']);

    $this->assertGuest();
});

it('requires email and password to log in', function () {
    $response = $this->postJson('/api/login', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

it('returns the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('email', $user->email);
});

it('rejects unauthenticated access to the current user endpoint', function () {
    $this->getJson('/api/user')
        ->assertStatus(401)
        ->assertJson(['message' => 'Unauthenticated.']);
});

it('logs the user out and invalidates the session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeader('Referer', 'http://localhost')
        ->postJson('/api/logout')
        ->assertOk()
        ->assertJson(['message' => 'Logged out successfully']);

    // Check that logout cleared the session for the web guard
    $this->assertGuest('web');
});
