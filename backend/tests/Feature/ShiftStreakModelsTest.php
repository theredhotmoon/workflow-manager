<?php

use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use Illuminate\Support\Facades\Schema;

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

it('no longer has a shift_streak_states table', function () {
    expect(Schema::hasTable('shift_streak_states'))->toBeFalse();
});

it('keeps the rest_policy column on shift_assignments', function () {
    expect(Schema::hasColumn('shift_assignments', 'rest_policy'))->toBeTrue();
});
