<?php

use App\Models\User;
use App\Models\UserGroup;

it('lets a group have multiple managers and a manager oversee multiple groups', function () {
    $groupA = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $groupB = UserGroup::create(['name' => 'Team B', 'enum' => 'team_b']);
    $managerOne = User::factory()->create();
    $managerTwo = User::factory()->create();

    $groupA->managers()->attach([$managerOne->id, $managerTwo->id]);
    $groupB->managers()->attach($managerOne->id);

    expect($groupA->managers()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$managerOne->id, $managerTwo->id])->sort()->values()->all());
    expect($managerOne->managedGroups()->pluck('user_groups.id')->sort()->values()->all())
        ->toBe(collect([$groupA->id, $groupB->id])->sort()->values()->all());
});

it('reports managesUser true only for members of a managed group', function () {
    $group = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $manager = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();

    $group->managers()->attach($manager->id);
    $group->users()->attach($member->id);

    expect($manager->managesUser($member))->toBeTrue();
    expect($manager->managesUser($outsider))->toBeFalse();
    expect($manager->managesUser($manager))->toBeFalse(); // managesUser is about others, not self
});
