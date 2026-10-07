<?php

use App\Models\User;

it('rejects API requests over the configured per-user limit with 429', function () {
    config(['app.api_rate_limit' => 3]);
    $user = User::factory()->create();

    foreach (range(1, 3) as $attempt) {
        $this->actingAs($user)->getJson('/api/user')->assertOk();
    }

    $this->actingAs($user)->getJson('/api/user')
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('defaults the API rate limit to 60 requests per minute', function () {
    expect(config('app.api_rate_limit'))->toBe(60);
});
