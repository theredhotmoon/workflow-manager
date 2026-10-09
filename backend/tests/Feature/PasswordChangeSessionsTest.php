<?php

use App\Models\User;

/**
 * A request in one of $user's SPA sessions: logged in, Referer from the SPA (so Sanctum runs its
 * stateful middleware), and the password hash that session stored when it last saw the user.
 */
function inSpaSession($test, User $user, string $storedHash)
{
    app('auth')->forgetGuards();

    return $test->actingAs($user->fresh(), 'web')
        ->withSession(['password_hash_web' => $storedHash])
        ->withHeader('Referer', 'http://localhost');
}

it('ends the other sessions when users change their own password, and keeps this one', function () {
    $user = User::factory()->create();
    $oldHash = $user->password;

    // A second session (another browser) works before the change.
    inSpaSession($this, $user, $oldHash)->getJson('/api/user')->assertOk();

    inSpaSession($this, $user, $oldHash)->putJson('/api/change-my-password', [
        'current_password' => 'password',
        'password' => 'a-new-password',
        'password_confirmation' => 'a-new-password',
    ])->assertOk();

    $newHash = $user->fresh()->password;
    expect($newHash)->not->toBe($oldHash);
    // The session that made the change now holds the new hash, so it stays logged in.
    expect(session('password_hash_web'))->toBe($newHash);
    inSpaSession($this, $user, $newHash)->getJson('/api/user')->assertOk();

    // The other session still holds the old hash: 401.
    inSpaSession($this, $user, $oldHash)->getJson('/api/user')->assertStatus(401);
});

it("ends the user's sessions when an admin resets their password", function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create();
    $oldHash = $user->password;

    inSpaSession($this, $user, $oldHash)->getJson('/api/user')->assertOk();

    inSpaSession($this, $admin, $admin->password)->putJson("/api/users/{$user->id}/password", [
        'password' => 'reset-by-admin',
        'password_confirmation' => 'reset-by-admin',
    ])->assertOk();

    inSpaSession($this, $user, $oldHash)->getJson('/api/user')->assertStatus(401);
    // The admin's own session is untouched.
    inSpaSession($this, $admin, $admin->password)->getJson('/api/user')->assertOk();
    // A fresh login with the new password works.
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'reset-by-admin'])->assertOk();
});

it('rotates the remember token on a password change', function () {
    $user = User::factory()->create(['remember_token' => 'old-remember-token']);

    inSpaSession($this, $user, $user->password)->putJson('/api/change-my-password', [
        'current_password' => 'password',
        'password' => 'a-new-password',
        'password_confirmation' => 'a-new-password',
    ])->assertOk();

    expect($user->fresh()->remember_token)->not->toBe('old-remember-token');
});
