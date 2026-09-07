<?php

use App\Models\User;
use App\Models\UserGroup;

it('lets an admin sync a group\'s managers', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $managerOne = User::factory()->create();
    $managerTwo = User::factory()->create();

    $response = $this->actingAs($admin)->putJson("/api/user-groups/{$group->id}/managers", [
        'user_ids' => [$managerOne->id, $managerTwo->id],
    ]);

    $response->assertOk();
    expect(collect($response->json('managers'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$managerOne->id, $managerTwo->id])->sort()->values()->all());
});

it('includes managers in the group index for admins', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $manager = User::factory()->create();
    $group->managers()->attach($manager->id);

    $response = $this->actingAs($admin)->getJson('/api/user-groups');

    $response->assertOk();
    expect(collect($response->json()[0]['managers'])->pluck('id')->all())->toBe([$manager->id]);
});

it('returns groups managed by the current user with their users populated', function () {
    $manager = User::factory()->create();
    $member = User::factory()->create();
    $group = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $group->managers()->attach($manager->id);
    $group->users()->attach($member->id);

    $response = $this->actingAs($manager)->getJson('/api/my-managed-teams');

    $response->assertOk();
    $groups = $response->json();

    expect($groups)->toHaveCount(1);
    expect($groups[0]['id'])->toBe($group->id);
    expect($groups[0]['name'])->toBe('Team A');
    expect($groups[0]['enum'])->toBe('team_a');
    expect($groups[0]['users'])->toHaveCount(1);
    expect($groups[0]['users'][0]['id'])->toBe($member->id);
});
