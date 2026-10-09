<?php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;
use Illuminate\Support\Carbon;

function spsReport($test, User $maintainer, ShiftGroup $group, int $offset = 0)
{
    return $test->actingAs($maintainer)->getJson('/api/settlement-periods?'.http_build_query([
        'shift_group_id' => $group->id, 'offset' => $offset,
    ]));
}

function spsNight(ShiftGroup $group, User $employee, string $date): void
{
    $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
        'start_time' => '22:00', 'end_time' => '06:00']);
    ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-15');
    $this->maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $this->group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01',
    ]);
    ShiftStreakRule::create(['shift_group_id' => $this->group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $this->employee = User::factory()->create(['role' => 'employee']);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('reports a mid-streak count as of the end of the displayed period', function () {
    spsNight($this->group, $this->employee, '2026-09-01');
    spsNight($this->group, $this->employee, '2026-09-02');

    expect(spsReport($this, $this->maintainer, $this->group)->json('employees.0.streak_status'))->toBe([
        ['shift_type' => 'night', 'current_count' => 2, 'streak_length' => 3, 'resting_until' => null],
    ]);
});

it('reports a rest window that ends inside the displayed period', function () {
    foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
        spsNight($this->group, $this->employee, $date);
    }

    expect(spsReport($this, $this->maintainer, $this->group)->json('employees.0.streak_status.0.resting_until'))->toBe('2026-09-05');
});

it('does not report a rest window that ended before the displayed period', function () {
    foreach (['2026-08-01', '2026-08-02', '2026-08-03'] as $date) {
        spsNight($this->group, $this->employee, $date);
    }
    spsNight($this->group, $this->employee, '2026-09-10'); // so the employee appears in September's report

    expect(spsReport($this, $this->maintainer, $this->group)->json('employees.0.streak_status.0'))->toBe(
        ['shift_type' => 'night', 'current_count' => 1, 'streak_length' => 3, 'resting_until' => null],
    );
});
