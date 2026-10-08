<?php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;
use App\Services\StreakReplay;

function aasSetup(): array
{
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    // Two worked nights → resting 2029-01-03 and 2029-01-04.
    foreach (['2029-01-01', '2029-01-02'] as $date) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
            'start_time' => '22:00', 'end_time' => '06:00']);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);
    }
    foreach (['2029-01-03', '2029-01-04', '2029-01-05'] as $date) {
        Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day',
            'start_time' => '08:00', 'end_time' => '16:00']);
    }

    return [$maintainer, $group, $employee];
}

function aasAssignedDates(ShiftGroup $group, User $employee): array
{
    return ShiftAssignment::where('user_id', $employee->id)
        ->join('shifts', 'shifts.id', '=', 'shift_assignments.shift_id')
        ->where('shifts.shift_group_id', $group->id)
        ->orderBy('shifts.date')
        ->pluck('shifts.date')
        ->map(fn ($d) => substr((string) $d, 0, 10))
        ->all();
}

function aasPayload(ShiftGroup $group, User $employee, array $extra): array
{
    return array_merge([
        'month' => '2029-01',
        'shift_group_id' => $group->id,
        'users_per_shift_weekday' => 1,
        'users_per_shift_weekend' => 1,
        'player_mode' => 'include',
        'player_ids' => [$employee->id],
    ], $extra);
}

it('skips a resting employee', function (string $endpoint, array $extra) {
    [$maintainer, $group, $employee] = aasSetup();

    $this->actingAs($maintainer)->postJson($endpoint, aasPayload($group, $employee, $extra))->assertOk();

    // 01-03 is already excluded by the modes' back-to-back rule; only 01-04 exercises the rest-window skip.
    expect(aasAssignedDates($group, $employee))->toBe(['2029-01-01', '2029-01-02', '2029-01-05']);
})->with([
    'random' => ['/api/schedule/auto-assign/random', []],
    'rotating' => ['/api/schedule/auto-assign/rotating', ['rotation_days' => 7]],
]);

it('feeds its own assignments into the replay so later days see the rest', function (string $endpoint, array $extra) {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 1, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    foreach (['2029-01-01', '2029-01-02', '2029-01-03', '2029-01-04'] as $date) {
        Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
            'start_time' => '22:00', 'end_time' => '06:00']);
    }

    $this->actingAs($maintainer)->postJson($endpoint, aasPayload($group, $employee, $extra))->assertOk();

    expect(aasAssignedDates($group, $employee))->toBe(['2029-01-01', '2029-01-04']);
})->with([
    'random' => ['/api/schedule/auto-assign/random', []],
    'rotating' => ['/api/schedule/auto-assign/rotating', ['rotation_days' => 7]],
]);

it('skips rest days that the back-to-back rule cannot explain', function (string $endpoint, array $extra) {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 3]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    // Two worked nights → resting 2029-01-03..2029-01-05. No shift on 01-03, so 01-04 has no previous-day
    // neighbour and 01-05's neighbour (01-04) stays unassigned: only the rest window can exclude them.
    foreach (['2029-01-01', '2029-01-02'] as $date) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
            'start_time' => '22:00', 'end_time' => '06:00']);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);
    }
    foreach (['2029-01-04', '2029-01-05', '2029-01-06'] as $date) {
        Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day',
            'start_time' => '08:00', 'end_time' => '16:00']);
    }

    $this->actingAs($maintainer)->postJson($endpoint, aasPayload($group, $employee, $extra))->assertOk();

    expect(aasAssignedDates($group, $employee))->toBe(['2029-01-01', '2029-01-02', '2029-01-06']);
})->with([
    'random' => ['/api/schedule/auto-assign/random', []],
    'rotating' => ['/api/schedule/auto-assign/rotating', ['rotation_days' => 7]],
]);

it('does not assign a shift that would turn the employee\'s existing later shift into a rest day', function (string $endpoint, array $extra) {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2029-01-01', 'weekly_hour_cap' => 168,
    ]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 3]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    // Empty nights on 01-01 and 01-03 (not adjacent, so the back-to-back rules allow both); the employee already
    // works 01-05. Nights 01-01 + 01-03 would complete the streak and rest 01-04..01-06, covering 01-05.
    foreach (['2029-01-01', '2029-01-03', '2029-01-05'] as $date) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
            'start_time' => '22:00', 'end_time' => '06:00']);
        if ($date === '2029-01-05') {
            ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);
        }
    }

    $this->actingAs($maintainer)->postJson($endpoint, aasPayload($group, $employee, $extra))->assertOk();

    expect(aasAssignedDates($group, $employee))->toBe(['2029-01-01', '2029-01-05']);
    expect(app(StreakReplay::class)->forGroup($group, [$employee->id])->restingAssignments($employee->id))->toBe([]);
})->with([
    'random' => ['/api/schedule/auto-assign/random', []],
    'rotating' => ['/api/schedule/auto-assign/rotating', ['rotation_days' => 7]],
    'polish-law' => ['/api/schedule/auto-assign/polish-law', ['rotation_days' => 7]],
]);

it('sees the employee\'s existing shift in the next month when skipping a shift that would rest it', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 3]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    // Empty nights 01-27 and 01-29 would complete the streak and rest 01-30..02-01, covering the
    // employee's existing night on 02-01 — outside the month being filled.
    foreach (['2029-01-27', '2029-01-29', '2029-02-01'] as $date) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
            'start_time' => '22:00', 'end_time' => '06:00']);
        if ($date === '2029-02-01') {
            ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);
        }
    }

    $this->actingAs($maintainer)->postJson('/api/schedule/auto-assign/random', aasPayload($group, $employee, []))->assertOk();

    expect(aasAssignedDates($group, $employee))->toBe(['2029-01-27', '2029-02-01']);
});
