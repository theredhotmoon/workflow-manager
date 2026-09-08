<?php

use App\Models\User;
use App\Models\UserAvailability;
use App\Models\UserGroup;

it('lets an employee create their own entry and sets created_by to themselves', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/user-availabilities', [
        'type' => 'unavailable',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-01',
    ]);

    $response->assertCreated();
    expect($response->json('created_by_user_id'))->toBe($user->id);
    $this->assertDatabaseHas('user_availabilities', [
        'user_id' => $user->id,
        'created_by_user_id' => $user->id,
    ]);
});

it('lets a manager create an entry for a team member and records themselves as creator', function () {
    $group = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $manager = User::factory()->create();
    $member = User::factory()->create();
    $group->managers()->attach($manager->id);
    $group->users()->attach($member->id);

    $response = $this->actingAs($manager)->postJson('/api/user-availabilities', [
        'user_id' => $member->id,
        'type' => 'preferred',
        'shift_type' => 'night',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-05',
    ]);

    $response->assertCreated();
    expect($response->json('user_id'))->toBe($member->id);
    expect($response->json('created_by_user_id'))->toBe($manager->id);
    expect($response->json('shift_type'))->toBe('night');
});

it('rejects a manager creating an entry for someone outside their team', function () {
    $manager = User::factory()->create();
    $outsider = User::factory()->create();

    $this->actingAs($manager)->postJson('/api/user-availabilities', [
        'user_id' => $outsider->id,
        'type' => 'unavailable',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-01',
    ])->assertStatus(403)->assertJson(['message' => "You do not manage this employee's team."]);

    $this->assertDatabaseCount('user_availabilities', 0);
});

it('rejects shift_type when type is unavailable', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/user-availabilities', [
        'type' => 'unavailable',
        'shift_type' => 'night',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-01',
    ])->assertStatus(422)->assertJsonValidationErrors(['shift_type']);
});

it('lets a manager delete a team member entry and denies an unrelated manager', function () {
    $group = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $manager = User::factory()->create();
    $member = User::factory()->create();
    $group->managers()->attach($manager->id);
    $group->users()->attach($member->id);
    $entry = UserAvailability::create([
        'user_id' => $member->id,
        'created_by_user_id' => $member->id,
        'type' => 'unavailable',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-01',
    ]);

    $unrelatedManager = User::factory()->create();
    $this->actingAs($unrelatedManager)
        ->deleteJson("/api/user-availabilities/{$entry->id}")
        ->assertStatus(403)
        ->assertJson(['message' => "You do not manage this employee's team."]);

    $this->actingAs($manager)
        ->deleteJson("/api/user-availabilities/{$entry->id}")
        ->assertOk();
    $this->assertDatabaseMissing('user_availabilities', ['id' => $entry->id]);
});
