<?php

use App\Models\Delegation;
use App\Models\DelegationLog;
use App\Models\User;

// L15: the delegation log only records what actually happened: acting on someone who isn't on the
// delegation is a 404 with nothing logged, and an edit logs only the fields whose value changed.

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->member = User::factory()->create(['name' => 'Ada Member']);
    $this->stranger = User::factory()->create(['name' => 'Sam Stranger']);
    $this->delegation = Delegation::create([
        'name' => 'Fair', 'destination' => 'Berlin', 'description' => null,
        'start_datetime' => '2026-11-02', 'end_datetime' => '2026-11-06', 'registration_open' => true,
        'created_by' => $this->admin->id,
    ]);
    $this->delegation->users()->attach($this->member->id, ['status' => 'pending']);
});

it('answers 404 and logs nothing when acting on a user who is not on the delegation', function (string $method, string $action, array $extra) {
    $this->actingAs($this->admin)
        ->{$method}("/api/delegations/{$this->delegation->id}/{$action}", ['user_id' => $this->stranger->id] + $extra)
        ->assertNotFound()
        ->assertJsonPath('message', 'Sam Stranger is not on this delegation.');

    expect(DelegationLog::count())->toBe(0)
        ->and($this->delegation->users()->count())->toBe(1);
})->with([
    'approve' => ['postJson', 'approve-user', []],
    'reject' => ['postJson', 'reject-user', []],
    'remove' => ['postJson', 'remove-user', []],
    'dates' => ['putJson', 'user-dates', ['custom_start' => '2026-11-03']],
]);

it('still logs acting on a member', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/delegations/{$this->delegation->id}/approve-user", ['user_id' => $this->member->id])
        ->assertOk();

    expect(DelegationLog::pluck('details')->all())->toBe(['Approved Ada Member']);
});

it('logs nothing when an edit sends back the current values', function () {
    $this->actingAs($this->admin)->putJson("/api/delegations/{$this->delegation->id}", [
        'name' => 'Fair', 'destination' => 'Berlin', 'description' => null,
        'start_datetime' => '2026-11-02', 'end_datetime' => '2026-11-06', 'registration_open' => true,
    ])->assertOk();
    // The edit form may send empty text for an empty description, and the dates with a time.
    $this->actingAs($this->admin)->putJson("/api/delegations/{$this->delegation->id}", [
        'description' => '', 'start_datetime' => '2026-11-02T00:00:00', 'end_datetime' => '2026-11-06 00:00:00',
    ])->assertOk();

    expect(DelegationLog::count())->toBe(0);
});

it('logs only the fields that changed', function () {
    $this->actingAs($this->admin)->putJson("/api/delegations/{$this->delegation->id}", [
        'name' => 'Fair', 'destination' => 'Munich',
        'start_datetime' => '2026-11-03', 'end_datetime' => '2026-11-06', 'registration_open' => false,
    ])->assertOk();

    expect(DelegationLog::sole()->details)
        ->toBe('Destination changed; Start date changed (2026-11-02 → 2026-11-03); Registration closed');
});
