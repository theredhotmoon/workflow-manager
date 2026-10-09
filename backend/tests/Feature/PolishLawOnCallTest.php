<?php

use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\User;

it('blocks a regular shift that would start less than 11h after a logged callback ends', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01', 'weekly_hour_cap' => 168,
    ]);
    $employee = User::factory()->create(['role' => 'employee']);

    // An earlier shift the auto-assigner will fill first — proves the fix
    // survives the main loop overwriting $employeeState[...]['last_shift_end']
    // with this shift's end time before the callback-blocked shift below is
    // evaluated.
    Shift::create([
        'date' => '2026-09-01', 'shift_number' => 1, 'shift_group_id' => $group->id,
        'start_time' => '08:00', 'end_time' => '16:00',
    ]);

    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-01 20:00:00',
        'ends_at' => '2026-09-02 08:00:00',
    ]);
    OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-02 02:00:00',
        'ends_at' => '2026-09-02 04:00:00',
    ]);

    // Only 4h of rest between the callback ending at 04:00 and this shift
    // starting at 08:00 — below the 11h minimum.
    Shift::create([
        'date' => '2026-09-02', 'shift_number' => 1, 'shift_group_id' => $group->id,
        'start_time' => '08:00', 'end_time' => '16:00',
    ]);

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
    // The Sep 1 shift is assigned (total 1); the Sep 2 shift stays blocked
    // by the callback even though $employeeState[...]['last_shift_end'] was
    // overwritten by the Sep 1 assignment in between.
    expect($response->json('total_assigned'))->toBe(1);
});

it('assigns the same shift when there is no callback blocking rest', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01', 'weekly_hour_cap' => 168,
    ]);
    $employee = User::factory()->create(['role' => 'employee']);

    Shift::create([
        'date' => '2026-09-02', 'shift_number' => 1, 'shift_group_id' => $group->id,
        'start_time' => '08:00', 'end_time' => '16:00',
    ]);

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
    expect($response->json('total_assigned'))->toBe(1);
});

it('counts a callback hours toward the settlement-anchored weekly_hour_cap, including on a date with no shift record', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01', 'weekly_hour_cap' => 10,
    ]);
    $employee = User::factory()->create(['role' => 'employee']);

    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-01 20:00:00',
        'ends_at' => '2026-09-02 08:00:00',
    ]);
    // 6h callback, no shift exists on 2026-09-01 at all — this only lands in
    // weekKeyByDate/weekCapByDate if polishLaw() widens those maps to cover
    // callback dates, not just shift dates.
    OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-01 22:00:00',
        'ends_at' => '2026-09-02 04:00:00',
    ]);

    // A separate 6h shift 2 days later in the same anchored week (Sep 1-7).
    // 6h (callback) + 6h (shift) = 12h, over the 10h cap — the shift must be
    // blocked, proving the callback's hours were actually counted. Rest is
    // not the cause here: the callback ends 2026-09-02 04:00 and this shift
    // starts 2026-09-03 08:00, a 28h gap, well over the 11h minimum.
    Shift::create([
        'date' => '2026-09-03', 'shift_number' => 1, 'shift_group_id' => $group->id,
        'start_time' => '08:00', 'end_time' => '14:00',
    ]);

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
    expect($response->json('total_assigned'))->toBe(0);
});

it('does not block a shift when the employee is on call but has no callback', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01', 'weekly_hour_cap' => 168,
    ]);
    $employee = User::factory()->create(['role' => 'employee']);

    OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-01 00:00:00',
        'ends_at' => '2026-09-30 23:59:59',
    ]);

    Shift::create([
        'date' => '2026-09-15', 'shift_number' => 1, 'shift_group_id' => $group->id,
        'start_time' => '08:00', 'end_time' => '16:00',
    ]);

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
    expect($response->json('total_assigned'))->toBe(1);
});
