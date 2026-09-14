<?php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;
use App\Services\ShiftStreakTracker;
use Illuminate\Support\Carbon;

it('includes streak_status per employee in the settlement periods report', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01',
    ]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    foreach (['2026-09-01', '2026-09-02'] as $date) {
        $shift = Shift::create([
            'date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
            'start_time' => '22:00', 'end_time' => '06:00',
        ]);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);
    }

    // The report reads persisted streak state, not the raw assignments — populate
    // it the same way a real polishLaw()/manual-assign call would.
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$employee->id]);
    $tracker->recordAssignment($states, $rules, $employee->id, 'night', Carbon::parse('2026-09-01'));
    $tracker->recordAssignment($states, $rules, $employee->id, 'night', Carbon::parse('2026-09-02'));
    $tracker->persist($states);

    $response = $this->actingAs($maintainer)->getJson('/api/settlement-periods?' . http_build_query([
        'shift_group_id' => $group->id,
        'offset' => 0,
    ]));

    $response->assertOk();
    $status = $response->json('employees.0.streak_status');
    expect($status)->toHaveCount(1);
    expect($status[0])->toBe([
        'shift_type' => 'night',
        'current_count' => 2,
        'streak_length' => 3,
        'resting_until' => null,
    ]);
});
