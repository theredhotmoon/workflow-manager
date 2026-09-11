<?php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\ShiftStreakState;
use App\Models\User;

function makeStreakShift(int $groupId, string $date, int $shiftNumber, string $shiftType, string $start, string $end): Shift
{
    return Shift::create([
        'date' => $date,
        'shift_number' => $shiftNumber,
        'shift_group_id' => $groupId,
        'shift_type' => $shiftType,
        'start_time' => $start,
        'end_time' => $end,
    ]);
}

it('blocks the 4th night shift after a 3-shift streak and blocks a day shift too during the same rest window', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01', 'weekly_hour_cap' => 168,
    ]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    makeStreakShift($group->id, '2026-09-01', 1, 'night', '22:00', '06:00');
    makeStreakShift($group->id, '2026-09-02', 1, 'night', '22:00', '06:00');
    makeStreakShift($group->id, '2026-09-03', 1, 'night', '22:00', '06:00');
    // Would be the 4th night shift in a row — must be blocked by the rest window the 3rd triggered.
    makeStreakShift($group->id, '2026-09-04', 1, 'night', '22:00', '06:00');
    // A day shift during the same rest window — must also be blocked ("fully off", not just off nights).
    makeStreakShift($group->id, '2026-09-05', 1, 'day', '06:00', '14:00');

    $response = $this->actingAs($maintainer)->postJson('/api/schedule/auto-assign/polish-law', [
        'month' => '2026-09',
        'shift_group_id' => $group->id,
        'users_per_shift_weekday' => 1,
        'users_per_shift_weekend' => 1,
        'rotation_days' => 7,
        'player_mode' => 'include',
        'player_ids' => [$employee->id],
    ]);

    $response->assertOk();
    expect($response->json('total_assigned'))->toBe(3);
});

it('carries streak state across separate generation requests for different months', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-08-01', 'weekly_hour_cap' => 168,
    ]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    // 2 night shifts in August — one shy of the streak, no rest window yet.
    makeStreakShift($group->id, '2026-08-30', 1, 'night', '22:00', '06:00');
    makeStreakShift($group->id, '2026-08-31', 1, 'night', '22:00', '06:00');

    $this->actingAs($maintainer)->postJson('/api/schedule/auto-assign/polish-law', [
        'month' => '2026-08',
        'shift_group_id' => $group->id,
        'users_per_shift_weekday' => 1,
        'users_per_shift_weekend' => 1,
        'rotation_days' => 7,
        'player_mode' => 'include',
        'player_ids' => [$employee->id],
    ])->assertOk();

    // September's 1st night shift should complete the streak carried over from
    // August (proving the count is not reset just because a new month started).
    makeStreakShift($group->id, '2026-09-01', 1, 'night', '22:00', '06:00');
    makeStreakShift($group->id, '2026-09-02', 1, 'night', '22:00', '06:00');

    $response = $this->actingAs($maintainer)->postJson('/api/schedule/auto-assign/polish-law', [
        'month' => '2026-09',
        'shift_group_id' => $group->id,
        'users_per_shift_weekday' => 1,
        'users_per_shift_weekend' => 1,
        'rotation_days' => 7,
        'player_mode' => 'include',
        'player_ids' => [$employee->id],
    ]);

    $response->assertOk();
    // Sep 1 completes the streak (rest triggered); Sep 2 is blocked by it.
    expect($response->json('total_assigned'))->toBe(1);
});

it('is idempotent: running polishLaw twice over the same already-generated month leaves streak state unchanged', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01', 'weekly_hour_cap' => 168,
    ]);
    $rule = ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    makeStreakShift($group->id, '2026-09-01', 1, 'night', '22:00', '06:00');
    makeStreakShift($group->id, '2026-09-02', 1, 'night', '22:00', '06:00');

    $payload = [
        'month' => '2026-09',
        'shift_group_id' => $group->id,
        'users_per_shift_weekday' => 1,
        'users_per_shift_weekend' => 1,
        'rotation_days' => 7,
        'player_mode' => 'include',
        'player_ids' => [$employee->id],
    ];

    $this->actingAs($maintainer)->postJson('/api/schedule/auto-assign/polish-law', $payload)->assertOk();

    $state = ShiftStreakState::where('user_id', $employee->id)->where('shift_streak_rule_id', $rule->id)->first();
    expect($state->current_count)->toBe(2);

    // Without the last_processed_date watermark, this re-scan would double-count to 4.
    $this->actingAs($maintainer)->postJson('/api/schedule/auto-assign/polish-law', $payload)->assertOk();

    $state->refresh();
    expect($state->current_count)->toBe(2);
});
