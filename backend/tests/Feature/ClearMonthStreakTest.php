<?php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\ShiftStreakState;
use App\Models\User;

it('reverses streak bookkeeping for assignments deleted by clear-month', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    $shift1 = Shift::create([
        'date' => '2026-09-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
    ]);
    $shift2 = Shift::create([
        'date' => '2026-09-02', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
    ]);

    $this->actingAs($maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $shift1->id,
        'user_id' => $employee->id,
    ])->assertCreated();

    $this->actingAs($maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $shift2->id,
        'user_id' => $employee->id,
    ])->assertCreated();

    $state = ShiftStreakState::where('user_id', $employee->id)->where('shift_streak_rule_id', $rule->id)->first();
    expect($state->current_count)->toBe(2);

    $response = $this->actingAs($maintainer)->deleteJson('/api/schedule/clear-month', [
        'month' => '2026-09',
        'shift_group_id' => $group->id,
    ]);

    $response->assertOk();

    expect(Shift::where('shift_group_id', $group->id)->count())->toBe(0);

    $state->refresh();
    expect($state->current_count)->toBe(0);
});

it('is a no-op for streak bookkeeping when the group has no streak rules configured', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    $shift = Shift::create([
        'date' => '2026-09-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
    ]);

    $this->actingAs($maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $shift->id,
        'user_id' => $employee->id,
    ])->assertCreated();

    $response = $this->actingAs($maintainer)->deleteJson('/api/schedule/clear-month', [
        'month' => '2026-09',
        'shift_group_id' => $group->id,
    ]);

    $response->assertOk();
    expect(Shift::where('shift_group_id', $group->id)->count())->toBe(0);
    expect(ShiftStreakState::count())->toBe(0);
});
