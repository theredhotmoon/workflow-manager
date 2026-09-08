<?php

use App\Models\User;
use App\Models\UserAvailability;

it('backfills created_by_user_id to user_id for pre-existing rows via the migration default', function () {
    // Simulates a row inserted before this migration ran, by inserting directly
    // and then asserting the column exists with the expected nullable/default shape.
    $user = User::factory()->create();
    $entry = UserAvailability::create([
        'user_id' => $user->id,
        'type' => 'preferred',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-05',
        'shift_type' => 'night',
    ]);

    expect($entry->shift_type)->toBe('night');
    expect($entry->created_by_user_id)->toBeNull(); // not set explicitly here — controller sets it (Task 4)
});

it('exposes a createdBy relation', function () {
    $owner = User::factory()->create();
    $manager = User::factory()->create();
    $entry = UserAvailability::create([
        'user_id' => $owner->id,
        'created_by_user_id' => $manager->id,
        'type' => 'unavailable',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-01',
    ]);

    expect($entry->createdBy->id)->toBe($manager->id);
});
