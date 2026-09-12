<?php

use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;

it('replaces the full streak-rule set for a group in one call', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'old', 'streak_length' => 1, 'rest_days' => 1]);

    $response = $this->actingAs($admin)->putJson("/api/shift-groups/{$group->id}/streak-rules", [
        'rules' => [
            ['shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2],
            ['shift_type' => 'day', 'streak_length' => 5, 'rest_days' => 1],
        ],
    ]);

    $response->assertOk();
    expect(ShiftStreakRule::where('shift_group_id', $group->id)->pluck('shift_type')->sort()->values()->all())
        ->toBe(['day', 'night']);
    expect(ShiftStreakRule::where('shift_type', 'old')->count())->toBe(0);
});

it('replacing the rule set with an empty array removes all existing rules', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);

    $this->actingAs($admin)->putJson("/api/shift-groups/{$group->id}/streak-rules", ['rules' => []])
        ->assertOk();

    expect(ShiftStreakRule::where('shift_group_id', $group->id)->count())->toBe(0);
});

it('rejects a streak_length of 0', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    $this->actingAs($admin)->putJson("/api/shift-groups/{$group->id}/streak-rules", [
        'rules' => [['shift_type' => 'night', 'streak_length' => 0, 'rest_days' => 2]],
    ])->assertStatus(422)->assertJsonValidationErrors(['rules.0.streak_length']);
});

it('rejects two rules for the same shift_type in the same payload', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    $response = $this->actingAs($admin)->putJson("/api/shift-groups/{$group->id}/streak-rules", [
        'rules' => [
            ['shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2],
            ['shift_type' => 'night', 'streak_length' => 4, 'rest_days' => 1],
        ],
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('one streak rule per group');
});

it('includes streak_rules on the shift-groups index response', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Streak Group', 'sort_order' => 1]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);

    $response = $this->actingAs($admin)->getJson('/api/shift-groups');

    $response->assertOk();
    $groups = collect($response->json());
    $ourGroup = $groups->firstWhere('id', $group->id);
    expect($ourGroup)->not->toBeNull();
    expect($ourGroup['streak_rules'][0]['shift_type'])->toBe('night');
});
