<?php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;

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
