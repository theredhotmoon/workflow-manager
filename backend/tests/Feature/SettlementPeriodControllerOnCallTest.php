<?php

use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\ShiftGroup;
use App\Models\User;

it('includes callback hours in the settlement periods report', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01',
    ]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-01 20:00:00',
        'ends_at' => '2026-09-02 08:00:00',
    ]);
    OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-01 22:00:00',
        'ends_at' => '2026-09-02 01:00:00',
    ]);

    $response = $this->actingAs($maintainer)->getJson('/api/settlement-periods?' . http_build_query([
        'shift_group_id' => $group->id,
        'offset' => 0,
    ]));

    $response->assertOk();
    expect($response->json('employees.0.name'))->toBe($employee->name);
    expect($response->json('employees.0.weekly_hours.0'))->toBe(3.0);
});
