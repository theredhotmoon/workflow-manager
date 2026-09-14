<?php

use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\ShiftStreakState;
use App\Models\User;

it('creates a streak rule scoped to a shift group and shift type', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    $rule = ShiftStreakRule::create([
        'shift_group_id' => $group->id,
        'shift_type' => 'night',
        'streak_length' => 3,
        'rest_days' => 2,
    ]);

    expect($rule->shift_group_id)->toBe($group->id);
    expect($group->streakRules()->pluck('shift_type')->all())->toBe(['night']);
});

it('rejects a second rule for the same shift group and shift type', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);

    expect(fn () => ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 4, 'rest_days' => 1]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('creates a streak state keyed by user and rule, and cascades on group deletion', function () {
    $group = ShiftGroup::create(['name' => 'Temp', 'sort_order' => 1]);
    $rule = ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $user = User::factory()->create();

    $state = ShiftStreakState::create([
        'user_id' => $user->id,
        'shift_streak_rule_id' => $rule->id,
        'current_count' => 1,
        'resting_until' => null,
        'last_processed_date' => '2026-09-01',
    ]);

    expect($state->current_count)->toBe(1);

    $group->delete();

    expect(ShiftStreakRule::find($rule->id))->toBeNull();
    expect(ShiftStreakState::find($state->id))->toBeNull();
});
