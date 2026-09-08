<?php

use App\Models\User;
use App\Models\UserAvailability;
use App\Models\UserGroup;

function makeEntry(User $owner): UserAvailability
{
    return UserAvailability::create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'type' => 'unavailable',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-01',
    ]);
}

it('lets an admin manage anyone', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $entry = makeEntry(User::factory()->create());

    expect($admin->can('manage', $entry))->toBeTrue();
    expect($admin->can('manageFor', [UserAvailability::class, $entry->user_id]))->toBeTrue();
});

it('lets a schedule maintainer manage anyone', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $entry = makeEntry(User::factory()->create());

    expect($maintainer->can('manage', $entry))->toBeTrue();
});

it('lets an owner manage their own entry', function () {
    $owner = User::factory()->create();
    $entry = makeEntry($owner);

    expect($owner->can('manage', $entry))->toBeTrue();
});

it('lets a manager of the right group manage a team member entry', function () {
    $group = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $manager = User::factory()->create();
    $member = User::factory()->create();
    $group->managers()->attach($manager->id);
    $group->users()->attach($member->id);
    $entry = makeEntry($member);

    expect($manager->can('manage', $entry))->toBeTrue();
    expect($manager->can('manageFor', [UserAvailability::class, $member->id]))->toBeTrue();
});

it('denies a manager of an unrelated group', function () {
    $groupA = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $groupB = UserGroup::create(['name' => 'Team B', 'enum' => 'team_b']);
    $manager = User::factory()->create();
    $member = User::factory()->create();
    $groupA->managers()->attach($manager->id);
    $groupB->users()->attach($member->id);
    $entry = makeEntry($member);

    expect($manager->can('manage', $entry))->toBeFalse();
});

it('denies a plain peer', function () {
    $peer = User::factory()->create();
    $entry = makeEntry(User::factory()->create());

    expect($peer->can('manage', $entry))->toBeFalse();
});
