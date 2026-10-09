<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;

function failLogin($test, string $email, array $server = [])
{
    return $test->withServerVariables($server)
        ->postJson('/api/login', ['email' => $email, 'password' => 'wrong-password']);
}

it('answers the 6th wrong password for one email within a minute with 429', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        failLogin($this, $user->email)->assertStatus(422);
    }

    failLogin($this, $user->email)
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    // Locked means locked: the right password doesn't get through either until the minute is up.
    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(429);
    $this->assertGuest();
});

it('counts the email case-insensitively', function () {
    $user = User::factory()->create(['email' => 'casey@example.com']);

    foreach (['casey@example.com', 'CASEY@example.com', 'Casey@Example.com', 'casey@EXAMPLE.com', 'CASEY@EXAMPLE.COM'] as $email) {
        failLogin($this, $email)->assertStatus(422);
    }

    failLogin($this, 'casey@example.com')->assertStatus(429);
});

it('does not block another email from the same IP', function () {
    $victim = User::factory()->create();
    $other = User::factory()->create();

    foreach (range(1, 6) as $attempt) {
        failLogin($this, $victim->email);
    }
    failLogin($this, $victim->email)->assertStatus(429);

    failLogin($this, $other->email)->assertStatus(422);
    $this->postJson('/api/login', ['email' => $other->email, 'password' => 'password'])->assertOk();
});

it('resets the counter after a successful login', function () {
    $user = User::factory()->create();

    foreach (range(1, 4) as $attempt) {
        failLogin($this, $user->email)->assertStatus(422);
    }
    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    app('auth')->forgetGuards();

    foreach (range(1, 5) as $attempt) {
        failLogin($this, $user->email)->assertStatus(422);
    }
});

it('follows the configured LOGIN_RATE_LIMIT', function () {
    config(['app.login_rate_limit' => 2]);
    $user = User::factory()->create();

    failLogin($this, $user->email)->assertStatus(422);
    failLogin($this, $user->email)->assertStatus(422);
    failLogin($this, $user->email)->assertStatus(429);
});

it('defaults the login rate limit to 5 failures per minute', function () {
    expect(config('app.login_rate_limit'))->toBe(5);
});

it('keys the limit on the real client IP forwarded by the frontend proxy', function () {
    $user = User::factory()->create();
    // The frontend Nginx container's address on the compose network, forwarding two clients.
    $proxy = ['REMOTE_ADDR' => '172.18.0.4'];

    foreach (range(1, 5) as $attempt) {
        failLogin($this, $user->email, $proxy + ['HTTP_X_FORWARDED_FOR' => '203.0.113.10'])->assertStatus(422);
    }

    failLogin($this, $user->email, $proxy + ['HTTP_X_FORWARDED_FOR' => '203.0.113.10'])->assertStatus(429);
    failLogin($this, $user->email, $proxy + ['HTTP_X_FORWARDED_FOR' => '198.51.100.20'])->assertStatus(422);
});

it('ignores X-Forwarded-For from a client that is not a trusted proxy', function () {
    $user = User::factory()->create();
    $public = ['REMOTE_ADDR' => '203.0.113.50'];

    foreach (range(1, 5) as $attempt) {
        failLogin($this, $user->email, $public + ['HTTP_X_FORWARDED_FOR' => "198.51.100.{$attempt}"])->assertStatus(422);
    }

    // A forged header per attempt doesn't buy a fresh counter.
    failLogin($this, $user->email, $public + ['HTTP_X_FORWARDED_FOR' => '198.51.100.99'])->assertStatus(429);
});

it('logs a failed login as a warning without the password', function () {
    Log::spy();
    $user = User::factory()->create();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Secret-Guess-42'])->assertStatus(422);

    Log::shouldHaveReceived('warning')
        ->withArgs(function ($message, $context = []) use ($user) {
            return $message === 'Failed login'
                && $context['email'] === $user->email
                && $context['ip'] === '127.0.0.1'
                && !str_contains(json_encode([$message, $context]), 'Secret-Guess-42');
        })
        ->once();
});
