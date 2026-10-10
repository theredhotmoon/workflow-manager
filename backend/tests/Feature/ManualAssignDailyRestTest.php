<?php

use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Support\Carbon;

// F3: a manual assign (or move) that leaves the employee less than 11 h of rest next to another
// of their shifts (any group) or on-call callbacks answers 409 short_daily_rest listing them;
// a manager confirms with confirm_short_rest. Same rule as auto-fill (BusyIntervals).

beforeEach(function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    $this->manager = User::factory()->create(['role' => 'admin']);
    $this->employee = User::factory()->create();
    $this->group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
});

function madShift(ShiftGroup $group, string $date, ?string $start, ?string $end, int $number = 1): Shift
{
    return Shift::create([
        'date' => $date, 'shift_number' => $number, 'shift_group_id' => $group->id,
        'start_time' => $start, 'end_time' => $end,
    ]);
}

function madAssign(array $payload)
{
    return test()->actingAs(test()->manager)->postJson('/api/schedule/assign', $payload);
}

it('asks before an assign that leaves less than 11 h of rest, and assigns on confirm', function () {
    $night = madShift($this->group, '2026-10-20', '22:00', '06:00');
    $morning = madShift($this->group, '2026-10-21', '14:00', '22:00');
    ShiftAssignment::create(['shift_id' => $night->id, 'user_id' => $this->employee->id]);

    madAssign(['shift_id' => $morning->id, 'user_id' => $this->employee->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'short_daily_rest')
        ->assertJsonPath('conflicts.0.shift_id', $night->id)
        ->assertJsonPath('conflicts.0.rest_hours', 8)
        ->assertJsonPath('message', 'This leaves the employee less than 11 hours of rest: 8 h after shift 1 on 2026-10-20 (22:00–06:00, Main).');
    expect(ShiftAssignment::where('shift_id', $morning->id)->exists())->toBeFalse();

    madAssign(['shift_id' => $morning->id, 'user_id' => $this->employee->id, 'confirm_short_rest' => true])
        ->assertCreated();
});

it('allows exactly 11 h of rest', function () {
    $early = madShift($this->group, '2026-10-20', '06:00', '14:00');
    $next = madShift($this->group, '2026-10-21', '01:00', '09:00');
    ShiftAssignment::create(['shift_id' => $early->id, 'user_id' => $this->employee->id]);

    madAssign(['shift_id' => $next->id, 'user_id' => $this->employee->id])->assertCreated();
});

it('counts shifts in other groups and on-call callbacks', function () {
    $other = ShiftGroup::create(['name' => 'Support', 'sort_order' => 1]);
    $otherShift = madShift($other, '2026-10-20', '14:00', '22:00');
    ShiftAssignment::create(['shift_id' => $otherShift->id, 'user_id' => $this->employee->id]);
    $target = madShift($this->group, '2026-10-21', '06:00', '14:00');

    madAssign(['shift_id' => $target->id, 'user_id' => $this->employee->id])
        ->assertStatus(409)
        ->assertJsonPath('conflicts.0.shift_group_name', 'Support');

    $callbackDay = madShift($this->group, '2026-10-25', '08:00', '16:00');
    $onCall = OnCallAssignment::create([
        'shift_group_id' => $this->group->id, 'user_id' => $this->employee->id,
        'starts_at' => '2026-10-24 16:00:00', 'ends_at' => '2026-10-25 08:00:00',
    ]);
    OnCallCallback::create(['on_call_assignment_id' => $onCall->id, 'starts_at' => '2026-10-25 01:00:00', 'ends_at' => '2026-10-25 03:00:00']);

    madAssign(['shift_id' => $callbackDay->id, 'user_id' => $this->employee->id])
        ->assertStatus(409)
        ->assertJsonPath('conflicts.0.type', 'on_call_callback');
});

it('does not count the assignment being moved', function () {
    $early = madShift($this->group, '2026-10-20', '06:00', '14:00', 1);
    $late = madShift($this->group, '2026-10-20', '14:00', '22:00', 2);
    $source = ShiftAssignment::create(['shift_id' => $early->id, 'user_id' => $this->employee->id]);

    madAssign(['shift_id' => $late->id, 'user_id' => $this->employee->id, 'move_from_assignment_id' => $source->id])
        ->assertCreated();
});

it('refuses a move into short rest without touching the source', function () {
    $night = madShift($this->group, '2026-10-20', '22:00', '06:00');
    $morning = madShift($this->group, '2026-10-21', '14:00', '22:00');
    $elsewhere = madShift($this->group, '2026-10-28', '14:00', '22:00');
    ShiftAssignment::create(['shift_id' => $night->id, 'user_id' => $this->employee->id]);
    $source = ShiftAssignment::create(['shift_id' => $elsewhere->id, 'user_id' => $this->employee->id]);

    madAssign(['shift_id' => $morning->id, 'user_id' => $this->employee->id, 'move_from_assignment_id' => $source->id])
        ->assertStatus(409)->assertJsonPath('code', 'short_daily_rest');

    expect($source->fresh())->not->toBeNull();
});

it('leaves shifts without times out of the check', function () {
    $untimed = madShift($this->group, '2026-10-20', null, null);
    $next = madShift($this->group, '2026-10-21', null, null);
    ShiftAssignment::create(['shift_id' => $untimed->id, 'user_id' => $this->employee->id]);

    madAssign(['shift_id' => $next->id, 'user_id' => $this->employee->id])->assertCreated();
});
