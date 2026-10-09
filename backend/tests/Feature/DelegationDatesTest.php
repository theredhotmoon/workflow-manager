<?php

use App\Models\Delegation;
use App\Models\User;

// M15: delegations are whole days and the last day is included, so a one-day delegation
// (start == end) is valid; an end before the start is still rejected.

function ddAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function ddPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Client visit',
        'destination' => 'Kraków',
        'start_datetime' => '2026-10-20',
        'end_datetime' => '2026-10-20',
    ], $overrides);
}

it('creates a single-day delegation', function () {
    $this->actingAs(ddAdmin())
        ->postJson('/api/delegations', ddPayload())
        ->assertCreated()
        ->assertJsonPath('name', 'Client visit');

    $delegation = Delegation::sole();
    expect($delegation->start_datetime->format('Y-m-d'))->toBe('2026-10-20')
        ->and($delegation->end_datetime->format('Y-m-d'))->toBe('2026-10-20');
});

it('rejects a delegation that ends before it starts', function () {
    $this->actingAs(ddAdmin())
        ->postJson('/api/delegations', ddPayload(['end_datetime' => '2026-10-19']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end_datetime');

    expect(Delegation::count())->toBe(0);
});

it('lets an edit make a delegation a single day', function () {
    $admin = ddAdmin();
    $this->actingAs($admin)->postJson('/api/delegations', ddPayload(['end_datetime' => '2026-10-22']))->assertCreated();
    $delegation = Delegation::sole();

    $this->actingAs($admin)
        ->putJson("/api/delegations/{$delegation->id}", ['end_datetime' => '2026-10-20'])
        ->assertOk();

    expect($delegation->fresh()->end_datetime->format('Y-m-d'))->toBe('2026-10-20');
});

it('rejects an edit that moves the end before the start', function () {
    $admin = ddAdmin();
    $this->actingAs($admin)->postJson('/api/delegations', ddPayload())->assertCreated();
    $delegation = Delegation::sole();

    $this->actingAs($admin)
        ->putJson("/api/delegations/{$delegation->id}", ['end_datetime' => '2026-10-19'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end_datetime');

    $this->actingAs($admin)
        ->putJson("/api/delegations/{$delegation->id}", ['start_datetime' => '2026-10-21'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end_datetime');

    expect($delegation->fresh()->end_datetime->format('Y-m-d'))->toBe('2026-10-20');
});
