<?php

use App\Models\Delegation;
use App\Models\User;

// The admin delegation list was unbounded: it is paginated now (per_page 1..100, default 20), newest
// start first with the id as tie-break, so pages never overlap or skip a delegation.

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
});

function dipCreate(int $count, string $start = '2026-11-01'): void
{
    for ($i = 0; $i < $count; $i++) {
        Delegation::create([
            'name' => "Trip {$i}", 'destination' => 'Gdynia',
            'start_datetime' => $start, 'end_datetime' => $start,
            'registration_open' => true, 'created_by' => User::factory()->create()->id,
        ]);
    }
}

it('pages the list, 20 by default', function () {
    dipCreate(25);

    $this->actingAs($this->admin)->getJson('/api/delegations')
        ->assertOk()
        ->assertJsonPath('total', 25)
        ->assertJsonPath('per_page', 20)
        ->assertJsonPath('last_page', 2)
        ->assertJsonCount(20, 'data')
        ->assertJsonStructure(['data' => [['id', 'name', 'users', 'creator']]]);
    $this->actingAs($this->admin)->getJson('/api/delegations?page=2')
        ->assertOk()->assertJsonCount(5, 'data');
});

it('clamps per_page to 1..100', function () {
    dipCreate(3);

    $this->actingAs($this->admin)->getJson('/api/delegations?per_page=1000')->assertOk()->assertJsonPath('per_page', 100);
    $this->actingAs($this->admin)->getJson('/api/delegations?per_page=0')->assertOk()->assertJsonPath('per_page', 1);
    $this->actingAs($this->admin)->getJson('/api/delegations?per_page=abc')->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

it('orders newest start first, with equal starts in a stable order across pages', function () {
    dipCreate(5, '2026-11-01');
    dipCreate(5, '2026-12-01');

    $ids = collect(range(1, 4))->flatMap(fn ($page) => $this->actingAs($this->admin)
        ->getJson("/api/delegations?per_page=3&page={$page}")->json('data.*.id'))->all();

    $expected = Delegation::orderByDesc('start_datetime')->orderByDesc('id')->pluck('id')->all();
    expect($ids)->toBe($expected)->and(array_unique($ids))->toHaveCount(10);
});
