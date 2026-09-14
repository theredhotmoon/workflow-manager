<?php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\ShiftStreakState;
use App\Models\User;

it('increments the streak counter on a manual assign without being blocked by an active rest window', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    $shift = Shift::create([
        'date' => '2026-09-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
    ]);

    $response = $this->actingAs($maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $shift->id,
        'user_id' => $employee->id,
    ]);

    $response->assertCreated();
    $state = ShiftStreakState::where('user_id', $employee->id)->where('shift_streak_rule_id', $rule->id)->first();
    expect($state->current_count)->toBe(1);
});

it('decrements the streak counter on a manual unassign', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    $shift = Shift::create([
        'date' => '2026-09-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
    ]);

    $this->actingAs($maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $shift->id,
        'user_id' => $employee->id,
    ])->assertCreated();

    $assignment = ShiftAssignment::where('shift_id', $shift->id)->where('user_id', $employee->id)->first();

    $this->actingAs($maintainer)->deleteJson("/api/schedule/unassign/{$assignment->id}")->assertOk();

    $state = ShiftStreakState::where('user_id', $employee->id)->where('shift_streak_rule_id', $rule->id)->first();
    expect($state->current_count)->toBe(0);
});
