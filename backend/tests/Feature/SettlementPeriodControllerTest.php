<?php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\User;

it('reports per-employee hours for the current settlement period, flagging over-cap weeks', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $employee = User::factory()->create(['name' => 'Jamie Employee']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'weekly', 'settlement_anchor_date' => now()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString(),
        'weekly_hour_cap' => 20,
    ]);

    $shift = Shift::create([
        'date' => now()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString(),
        'shift_number' => 1,
        'shift_group_id' => $group->id,
        'start_time' => '06:00',
        'end_time' => '18:00', // 12h — over a 20h... no, under; use two shifts to exceed
    ]);
    ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);

    $shift2 = Shift::create([
        'date' => now()->startOfWeek(\Carbon\Carbon::MONDAY)->addDay()->toDateString(),
        'shift_number' => 1,
        'shift_group_id' => $group->id,
        'start_time' => '06:00',
        'end_time' => '18:00',
    ]);
    ShiftAssignment::create(['shift_id' => $shift2->id, 'user_id' => $employee->id]);

    $response = $this->actingAs($maintainer)->getJson("/api/settlement-periods?shift_group_id={$group->id}&offset=0");

    $response->assertOk();
    expect($response->json('employees.0.name'))->toBe('Jamie Employee');
    expect($response->json('employees.0.total_hours'))->toBe(24.0);
    expect($response->json('employees.0.over_cap_weeks'))->toBe([0]);
});

it('rejects a plain employee', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    $this->actingAs($employee)
        ->getJson("/api/settlement-periods?shift_group_id={$group->id}")
        ->assertStatus(403);
});

it('returns an empty employees list for a period with no assignments yet', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    $response = $this->actingAs($maintainer)->getJson("/api/settlement-periods?shift_group_id={$group->id}");

    $response->assertOk();
    expect($response->json('employees'))->toBe([]);
});
