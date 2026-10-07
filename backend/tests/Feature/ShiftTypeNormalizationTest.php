<?php
// backend/tests/Feature/ShiftTypeNormalizationTest.php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Support\ShiftType;
use Illuminate\Support\Facades\DB;

it('normalises shift types: trims, lower-cases, blank becomes null', function () {
    expect(ShiftType::normalize(' Night '))->toBe('night');
    expect(ShiftType::normalize('ÉVENING'))->toBe('évening');
    expect(ShiftType::normalize(''))->toBeNull();
    expect(ShiftType::normalize('   '))->toBeNull();
    expect(ShiftType::normalize(null))->toBeNull();
});

it('normalises shift_type when a Shift or ShiftStreakRule is written', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $shift = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => ' Night ']);
    $rule = ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'DAY', 'streak_length' => 3, 'rest_days' => 2]);

    expect($shift->fresh()->shift_type)->toBe('night');
    expect($rule->fresh()->shift_type)->toBe('day');

    $shift->update(['shift_type' => '  ']);
    expect($shift->fresh()->shift_type)->toBeNull();
});

it('migration normalises existing shift and streak-rule types', function () {
    $groupId = DB::table('shift_groups')->insertGetId(['name' => 'Main', 'is_default' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $ruleId = DB::table('shift_streak_rules')->insertGetId(['shift_group_id' => $groupId, 'shift_type' => ' Night ', 'streak_length' => 3, 'rest_days' => 2, 'created_at' => now(), 'updated_at' => now()]);
    $nightId = DB::table('shifts')->insertGetId(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $groupId, 'shift_type' => 'NIGHT ', 'created_at' => now(), 'updated_at' => now()]);
    $blankId = DB::table('shifts')->insertGetId(['date' => '2027-03-02', 'shift_number' => 1, 'shift_group_id' => $groupId, 'shift_type' => '   ', 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_06_120000_normalize_shift_types.php'))->up();

    expect(DB::table('shift_streak_rules')->where('id', $ruleId)->value('shift_type'))->toBe('night');
    expect(DB::table('shifts')->where('id', $nightId)->value('shift_type'))->toBe('night');
    expect(DB::table('shifts')->where('id', $blankId)->value('shift_type'))->toBeNull();
});

it('migration aborts without deleting when two rules in a group collide after normalising', function () {
    $groupId = DB::table('shift_groups')->insertGetId(['name' => 'Main', 'is_default' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('shift_streak_rules')->insert([
        ['shift_group_id' => $groupId, 'shift_type' => 'Night', 'streak_length' => 3, 'rest_days' => 2, 'created_at' => now(), 'updated_at' => now()],
        ['shift_group_id' => $groupId, 'shift_type' => 'night', 'streak_length' => 4, 'rest_days' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect(fn () => (require database_path('migrations/2026_10_06_120000_normalize_shift_types.php'))->up())
        ->toThrow(RuntimeException::class, "group {$groupId}");

    expect(DB::table('shift_streak_rules')->where('shift_group_id', $groupId)->count())->toBe(2);
});
