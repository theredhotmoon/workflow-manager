<?php

use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Support\Carbon;

it('includes callback hours in the settlement periods report', function () {
    // offset=0 means "the period containing now" — pin now inside the seeded
    // September period so the test doesn't break once the month rolls over.
    Carbon::setTestNow(Carbon::parse('2026-09-15'));

    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01',
    ]);
    $employee = User::factory()->create(['role' => 'employee']);

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

    Carbon::setTestNow();
});

it('keeps the half hour of a callback that does not last whole hours', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-15'));

    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01',
    ]);
    $employee = User::factory()->create(['role' => 'employee']);

    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-01 20:00:00',
        'ends_at' => '2026-09-02 08:00:00',
    ]);
    OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-01 22:00:00',
        'ends_at' => '2026-09-01 23:30:00',
    ]);

    $response = $this->actingAs($maintainer)->getJson('/api/settlement-periods?' . http_build_query([
        'shift_group_id' => $group->id,
        'offset' => 0,
    ]));

    $response->assertOk();
    expect($response->json('employees.0.weekly_hours.0'))->toBe(1.5);

    Carbon::setTestNow();
});
