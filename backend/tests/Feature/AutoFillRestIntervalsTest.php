<?php

use App\Models\Delegation;
use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\User;
use App\Services\RestPolicyJanitor;

// Auto-fill sees every busy interval of an employee (all groups, the neighbouring months, the
// picks of the same run) for the 11 h daily rest, the weekly caps and the 35 h weekly rest;
// approved delegations make an employee unavailable; a failed run leaves no assignments.

function afrGroup(string $name = 'Main', array $attrs = []): ShiftGroup
{
    return ShiftGroup::create(array_merge([
        'name' => $name, 'is_default' => $name === 'Main', 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-01-01', 'weekly_hour_cap' => 168,
    ], $attrs));
}

function afrShift(ShiftGroup $group, string $date, string $start, string $end, int $number = 1): Shift
{
    return Shift::create([
        'date' => $date, 'shift_number' => $number, 'shift_group_id' => $group->id,
        'start_time' => $start, 'end_time' => $end,
    ]);
}

function afrAssign(Shift $shift, User $user): void
{
    ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id]);
}

function afrFill($test, string $endpoint, ShiftGroup $group, array $users, string $month = '2026-10', array $extra = [])
{
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);

    return $test->actingAs($maintainer)->postJson($endpoint, array_merge([
        'month' => $month,
        'shift_group_id' => $group->id,
        'users_per_shift_weekday' => 1,
        'users_per_shift_weekend' => 1,
        'rotation_days' => 7,
        'player_mode' => 'include',
        'player_ids' => array_map(fn (User $u) => $u->id, $users),
    ], $extra));
}

/** The employee's assignments in the group as "Y-m-d HH:MM". */
function afrAssigned(ShiftGroup $group, User $user): array
{
    return Shift::join('shift_assignments', 'shift_assignments.shift_id', '=', 'shifts.id')
        ->where('shift_assignments.user_id', $user->id)
        ->where('shifts.shift_group_id', $group->id)
        ->orderBy('shifts.date')
        ->get(['shifts.date', 'shifts.start_time'])
        ->map(fn ($s) => $s->date->format('Y-m-d').' '.substr($s->start_time, 0, 5))
        ->all();
}

function afrEmployee(): User
{
    return User::factory()->create(['role' => 'employee']);
}

dataset('afrModes', [
    'random' => ['/api/schedule/auto-assign/random'],
    'rotating' => ['/api/schedule/auto-assign/rotating'],
    'polish-law' => ['/api/schedule/auto-assign/polish-law'],
]);

// ─── H8: 11 h daily rest against every busy interval ─────────────────────────

it('does not give a shift that ends right before the employee\'s existing later shift', function (string $endpoint) {
    $group = afrGroup();
    $a = afrEmployee();
    afrShift($group, '2026-10-09', '22:00', '06:00', 3);
    afrAssign(afrShift($group, '2026-10-10', '06:00', '14:00', 1), $a);

    afrFill($this, $endpoint, $group, [$a])->assertOk();

    expect(afrAssigned($group, $a))->toBe(['2026-10-10 06:00']);
})->with('afrModes');

it('sees the previous month\'s night shift at the month boundary', function (string $endpoint) {
    $group = afrGroup();
    $a = afrEmployee();
    afrAssign(afrShift($group, '2026-09-30', '22:00', '06:00', 3), $a);
    afrShift($group, '2026-10-01', '06:00', '14:00', 1);

    afrFill($this, $endpoint, $group, [$a])->assertOk();

    expect(afrAssigned($group, $a))->toBe(['2026-09-30 22:00']);
})->with('afrModes');

it('sees the employee\'s shift in another group', function (string $endpoint, string $start, string $end) {
    $g1 = afrGroup();
    $g2 = afrGroup('Second');
    $a = afrEmployee();
    afrAssign(afrShift($g2, '2026-10-05', '06:00', '14:00'), $a);
    afrShift($g1, '2026-10-05', $start, $end);

    afrFill($this, $endpoint, $g1, [$a])->assertOk();

    expect(afrAssigned($g1, $a))->toBe([]);
})->with('afrModes')->with([
    'overlapping' => ['10:00', '18:00'],
    '8 h after it' => ['22:00', '06:00'],
]);

it('allows exactly 11 h of rest and refuses 10 h 59 min, before and after', function (string $endpoint, array $existing, array $candidate, bool $allowed) {
    $group = afrGroup();
    $a = afrEmployee();
    afrAssign(afrShift($group, ...$existing), $a);
    afrShift($group, ...$candidate);

    afrFill($this, $endpoint, $group, [$a])->assertOk();

    $candidateKey = $candidate[0].' '.$candidate[1];
    expect(in_array($candidateKey, afrAssigned($group, $a), true))->toBe($allowed);
})->with('afrModes')->with([
    'rest before, 11 h' => [['2026-10-05', '06:00', '14:00'], ['2026-10-06', '01:00', '05:00'], true],
    'rest before, 10:59' => [['2026-10-05', '06:00', '14:00'], ['2026-10-06', '00:59', '04:59'], false],
    'rest after, 11 h' => [['2026-10-06', '01:00', '05:00'], ['2026-10-05', '06:00', '14:00'], true],
    'rest after, 10:59' => [['2026-10-06', '01:00', '05:00'], ['2026-10-05', '06:00', '14:01'], false],
]);

it('keeps picks of the same run apart by 11 h', function (string $endpoint) {
    $group = afrGroup();
    $a = afrEmployee();
    afrShift($group, '2026-10-05', '22:00', '06:00');
    afrShift($group, '2026-10-06', '08:00', '16:00');

    afrFill($this, $endpoint, $group, [$a])->assertOk();

    expect(afrAssigned($group, $a))->toBe(['2026-10-05 22:00']);
})->with('afrModes');

it('measures the 11 h in elapsed time across the autumn DST change', function (string $endpoint, string $start, string $end, bool $allowed) {
    // Clocks go back at 03:00 on 2026-10-25: 22:00 CEST to 08:00 CET is 11 h, to 07:00 CET 10 h.
    $group = afrGroup();
    $a = afrEmployee();
    afrAssign(afrShift($group, '2026-10-24', '14:00', '22:00'), $a);
    afrShift($group, '2026-10-25', $start, $end);

    afrFill($this, $endpoint, $group, [$a])->assertOk();

    expect(in_array("2026-10-25 {$start}", afrAssigned($group, $a), true))->toBe($allowed);
})->with('afrModes')->with([
    '08:00 (11 h)' => ['08:00', '16:00', true],
    '07:00 (10 h)' => ['07:00', '15:00', false],
]);

// ─── Polish law: weekly caps count every group and the neighbouring month ────

it('counts the employee\'s hours in another group towards the weekly cap', function () {
    $g1 = afrGroup('Main', ['settlement_anchor_date' => '2026-10-01', 'weekly_hour_cap' => 10]);
    $g2 = afrGroup('Second');
    $a = afrEmployee();
    afrAssign(afrShift($g2, '2026-10-05', '06:00', '12:00'), $a);
    afrShift($g1, '2026-10-07', '06:00', '12:00');

    afrFill($this, '/api/schedule/auto-assign/polish-law', $g1, [$a])->assertOk();

    expect(afrAssigned($g1, $a))->toBe([]);
});

it('counts the previous month\'s hours in a settlement week that crosses the month boundary', function () {
    $group = afrGroup('Main', ['settlement_mode' => 'weekly', 'settlement_anchor_date' => '2026-09-28', 'weekly_hour_cap' => 10]);
    $a = afrEmployee();
    afrAssign(afrShift($group, '2026-09-29', '06:00', '12:00'), $a);
    afrShift($group, '2026-10-02', '06:00', '12:00');

    afrFill($this, '/api/schedule/auto-assign/polish-law', $group, [$a])->assertOk();

    expect(afrAssigned($group, $a))->toBe(['2026-09-29 06:00']);
});

// ─── Polish law: 35 h uninterrupted weekly rest ──────────────────────────────

it('refuses a pick that leaves less than 35 h of weekly rest and allows exactly 35 h', function (string $start, string $end, bool $allowed) {
    // Week Mon 2026-10-05 00:00 .. Mon 10-12 00:00. Mon-Thu 06:00-14:00 leave one free stretch,
    // Thu 14:00 .. Mon 00:00 (82 h). Sat 01:00-13:00 splits it into 35 h + 35 h.
    $group = afrGroup('Main', ['settlement_mode' => 'weekly', 'settlement_anchor_date' => '2026-10-05']);
    $a = afrEmployee();
    foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'] as $date) {
        afrAssign(afrShift($group, $date, '06:00', '14:00'), $a);
    }
    afrShift($group, '2026-10-10', $start, $end);

    afrFill($this, '/api/schedule/auto-assign/polish-law', $group, [$a])->assertOk();

    expect(in_array("2026-10-10 {$start}", afrAssigned($group, $a), true))->toBe($allowed);
})->with([
    'exactly 35 h left' => ['01:00', '13:00', true],
    '34 h 59 min left' => ['00:59', '13:01', false],
]);

it('counts other groups and the previous month in the 35 h weekly rest', function () {
    // Week Mon 2026-09-28 .. Mon 10-05. Sep 28-30 are in another group and the previous month.
    $g1 = afrGroup('Main', ['settlement_mode' => 'weekly', 'settlement_anchor_date' => '2026-09-28']);
    $g2 = afrGroup('Second');
    $a = afrEmployee();
    foreach (['2026-09-28', '2026-09-29', '2026-09-30'] as $date) {
        afrAssign(afrShift($g2, $date, '06:00', '14:00'), $a);
    }
    afrAssign(afrShift($g1, '2026-10-01', '06:00', '14:00'), $a);
    afrShift($g1, '2026-10-03', '00:59', '13:01');

    afrFill($this, '/api/schedule/auto-assign/polish-law', $g1, [$a])->assertOk();

    expect(afrAssigned($g1, $a))->toBe(['2026-10-01 06:00']);
});

it('checks the 35 h window that the previous month\'s last, shortened week spills into', function () {
    // Monthly periods: October's last week is Oct 29-31, its 35 h window Oct 29 00:00 .. Nov 5 00:00.
    // With Oct 29-31 booked, Nov 1 and 2 still leave 58 h, Nov 3 would leave 34 h, Nov 4 leaves 40 h.
    $group = afrGroup();
    $a = afrEmployee();
    foreach (['2026-10-29', '2026-10-30', '2026-10-31'] as $date) {
        afrAssign(afrShift($group, $date, '06:00', '14:00'), $a);
    }
    foreach (['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04'] as $date) {
        afrShift($group, $date, '06:00', '14:00');
    }

    afrFill($this, '/api/schedule/auto-assign/polish-law', $group, [$a], '2026-11')->assertOk();

    expect(afrAssigned($group, $a))->toBe([
        '2026-10-29 06:00', '2026-10-30 06:00', '2026-10-31 06:00',
        '2026-11-01 06:00', '2026-11-02 06:00', '2026-11-04 06:00',
    ]);
});

it('checks the 35 h window of a shortened week when a custom period ends mid-month', function () {
    // 10-day periods from Oct 1: the week Oct 8-10 ends its period, its window is Oct 8 .. Oct 15.
    $group = afrGroup('Main', ['settlement_mode' => 'custom', 'settlement_custom_length_days' => 10, 'settlement_anchor_date' => '2026-10-01']);
    $a = afrEmployee();
    foreach (['2026-10-08', '2026-10-09', '2026-10-10'] as $date) {
        afrAssign(afrShift($group, $date, '06:00', '14:00'), $a);
    }
    foreach (['2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14'] as $date) {
        afrShift($group, $date, '06:00', '14:00');
    }

    afrFill($this, '/api/schedule/auto-assign/polish-law', $group, [$a])->assertOk();

    expect(afrAssigned($group, $a))->toBe([
        '2026-10-08 06:00', '2026-10-09 06:00', '2026-10-10 06:00',
        '2026-10-11 06:00', '2026-10-12 06:00', '2026-10-14 06:00',
    ]);
});

it('counts an on-call callback from another group as busy time', function () {
    $g1 = afrGroup();
    $g2 = afrGroup('Second');
    $a = afrEmployee();
    $onCall = OnCallAssignment::create([
        'shift_group_id' => $g2->id, 'user_id' => $a->id,
        'starts_at' => '2026-10-04 20:00:00', 'ends_at' => '2026-10-05 08:00:00',
    ]);
    // Ends 04:00 Warsaw; the 08:00 shift would leave 4 h of rest.
    OnCallCallback::create(['on_call_assignment_id' => $onCall->id, 'starts_at' => '2026-10-05 02:00:00', 'ends_at' => '2026-10-05 04:00:00']);
    afrShift($g1, '2026-10-05', '08:00', '16:00');

    afrFill($this, '/api/schedule/auto-assign/polish-law', $g1, [$a])->assertOk();

    expect(afrAssigned($g1, $a))->toBe([]);
});

// ─── Approved delegations make an employee unavailable ───────────────────────

function afrDelegation(User $user, string $status, ?string $customStart = null, ?string $customEnd = null): Delegation
{
    $delegation = Delegation::create([
        'name' => 'Expo', 'destination' => 'Berlin',
        'start_datetime' => '2026-10-05', 'end_datetime' => '2026-10-07',
        'registration_open' => true, 'created_by' => $user->id,
    ]);
    $delegation->users()->attach($user->id, ['status' => $status, 'custom_start' => $customStart, 'custom_end' => $customEnd]);

    return $delegation;
}

it('skips an employee on an approved delegation and not on a pending or rejected one', function (string $endpoint, string $status, bool $skipped) {
    $group = afrGroup();
    $a = afrEmployee();
    afrDelegation($a, $status);
    afrShift($group, '2026-10-06', '06:00', '14:00');

    afrFill($this, $endpoint, $group, [$a])->assertOk();

    expect(afrAssigned($group, $a))->toBe($skipped ? [] : ['2026-10-06 06:00']);
})->with('afrModes')->with([
    'approved' => ['approved', true],
    'pending' => ['pending', false],
    'rejected' => ['rejected', false],
]);

it('uses the employee\'s own delegation dates when set', function (string $endpoint) {
    $group = afrGroup();
    $a = afrEmployee();
    // The delegation runs 10-05..10-07, but this employee only goes 10-07..10-08.
    afrDelegation($a, 'approved', '2026-10-07', '2026-10-08');
    afrShift($group, '2026-10-05', '06:00', '14:00');
    afrShift($group, '2026-10-08', '06:00', '14:00');

    // One-day rotation blocks: rotating leaves a whole block empty when its subteam is.
    afrFill($this, $endpoint, $group, [$a], '2026-10', ['rotation_days' => 1])->assertOk();

    expect(afrAssigned($group, $a))->toBe(['2026-10-05 06:00']);
})->with('afrModes');

// ─── A failed run leaves nothing behind ──────────────────────────────────────

it('rolls back every assignment when the run fails part-way', function (string $endpoint) {
    $group = afrGroup();
    $a = afrEmployee();
    afrShift($group, '2026-10-05', '06:00', '14:00');
    afrShift($group, '2026-10-07', '06:00', '14:00');
    afrShift($group, '2026-10-09', '06:00', '14:00');

    $created = 0;
    ShiftAssignment::creating(function () use (&$created) {
        if (++$created === 2) {
            throw new RuntimeException('simulated failure mid-run');
        }
    });

    afrFill($this, $endpoint, $group, [$a])->assertStatus(500);

    expect($created)->toBe(2);
    expect(ShiftAssignment::count())->toBe(0);
})->with('afrModes');

it('rolls back every assignment when the rest-policy clean-up fails', function (string $endpoint) {
    $group = afrGroup();
    $a = afrEmployee();
    afrShift($group, '2026-10-05', '06:00', '14:00');
    afrShift($group, '2026-10-07', '06:00', '14:00');

    $this->mock(RestPolicyJanitor::class, function ($mock) {
        $mock->shouldReceive('clearUncovered')->once()->andThrow(new RuntimeException('janitor failed'));
    });

    afrFill($this, $endpoint, $group, [$a])->assertStatus(500);

    expect(ShiftAssignment::count())->toBe(0);
})->with('afrModes');

it('stores delegation dates sent with a time and offset as the dates written, and blocks those days', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = afrGroup();
    $a = afrEmployee();
    afrShift($group, '2026-10-05', '06:00', '14:00');
    afrShift($group, '2026-10-08', '06:00', '14:00');

    $id = $this->actingAs($admin)->postJson('/api/delegations', [
        'name' => 'Expo', 'destination' => 'Berlin',
        'start_datetime' => '2026-10-05T00:30:00+05:00',
        'end_datetime' => '2026-10-07T23:30:00-05:00',
        'user_ids' => [$a->id],
    ])->assertCreated()->json('id');

    $delegation = Delegation::find($id);
    expect($delegation->start_datetime->format('Y-m-d H:i:s'))->toBe('2026-10-05 00:00:00');
    expect($delegation->end_datetime->format('Y-m-d H:i:s'))->toBe('2026-10-07 00:00:00');

    $this->actingAs($admin)->putJson("/api/delegations/{$id}", ['end_datetime' => '2026-10-07T23:30:00-05:00'])->assertOk();
    expect(Delegation::find($id)->end_datetime->format('Y-m-d H:i:s'))->toBe('2026-10-07 00:00:00');

    afrFill($this, '/api/schedule/auto-assign/random', $group, [$a])->assertOk();

    expect(afrAssigned($group, $a))->toBe(['2026-10-08 06:00']);
});
