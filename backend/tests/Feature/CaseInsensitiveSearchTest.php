<?php

use App\Models\ArchivedDelegation;
use App\Models\Procedure;
use App\Models\User;

// L12: text searches ignore case (ILIKE) and match % and _ literally.

it('finds procedures whatever the case of the search', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Procedure::create(['identifier' => 'PROC-01', 'title' => 'Server Escalation', 'owner_id' => $admin->id]);
    Procedure::create(['identifier' => 'PROC-02', 'title' => 'Backups', 'purpose' => 'Nightly DATABASE dumps', 'owner_id' => $admin->id]);

    $titles = fn (string $search) => collect($this->actingAs($admin)->getJson('/api/procedures?search='.urlencode($search))->assertOk()->json('data'))
        ->pluck('title')->sort()->values()->all();

    expect($titles('escalation'))->toBe(['Server Escalation'])
        ->and($titles('proc-02'))->toBe(['Backups'])
        ->and($titles('database'))->toBe(['Backups']);
});

it('matches % and _ in a procedure search literally', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Procedure::create(['identifier' => 'PROC-01', 'title' => '100% uptime', 'owner_id' => $admin->id]);
    Procedure::create(['identifier' => 'PROC-02', 'title' => '1000 users', 'owner_id' => $admin->id]);
    Procedure::create(['identifier' => 'PROC-03', 'title' => 'snake_case names', 'owner_id' => $admin->id]);
    Procedure::create(['identifier' => 'PROC-04', 'title' => 'snakeXcase names', 'owner_id' => $admin->id]);

    $titles = fn (string $search) => collect($this->actingAs($admin)->getJson('/api/procedures?search='.urlencode($search))->assertOk()->json('data'))
        ->pluck('title')->all();

    expect($titles('100%'))->toBe(['100% uptime'])
        ->and($titles('snake_case'))->toBe(['snake_case names']);
});

it('filters the delegation archive by destination whatever the case', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    foreach (['Berlin', 'Paris'] as $destination) {
        ArchivedDelegation::forceCreate([
            'original_id' => 1, 'name' => "Trip to {$destination}", 'destination' => $destination,
            'start_datetime' => '2026-01-10', 'end_datetime' => '2026-01-12', 'registration_open' => false,
            'created_by' => $admin->id, 'archived_by' => $admin->id, 'archived_at' => now(),
        ]);
    }

    $response = $this->actingAs($admin)->getJson('/api/delegation-archive?destination=berLIN')->assertOk();

    expect(collect($response->json('data') ?? $response->json())->pluck('destination')->all())->toBe(['Berlin']);
});
