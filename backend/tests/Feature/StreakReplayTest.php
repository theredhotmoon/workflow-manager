<?php
// backend/tests/Feature/StreakReplayTest.php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;
use App\Services\StreakReplay;
use App\Services\StreakTimeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** @param list<array{0: string, 1: int, 2: int}> $defs [shift_type, streak_length, rest_days] */
function srlRules(array $defs): Collection
{
    return collect($defs)
        ->map(fn ($d) => new ShiftStreakRule(['shift_type' => $d[0], 'streak_length' => $d[1], 'rest_days' => $d[2]]))
        ->keyBy('shift_type');
}

function srlEvent(string $date, ?string $type, ?string $policy = null, int $slot = 1): array
{
    return ['date' => $date, 'shift_number' => $slot, 'shift_type' => $type, 'rest_policy' => $policy];
}

function srlTimeline(array $rules, array $events): StreakTimeline
{
    return new StreakTimeline(srlRules($rules), [1 => $events]);
}

it('opens a rest window [D+1, D+R] after the Nth shift and not before', function () {
    $t = srlTimeline([['night', 3, 2]], [
        srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night'), srlEvent('2029-01-03', 'night'),
    ]);

    expect($t->isResting(1, Carbon::parse('2029-01-03')))->toBeFalse();
    expect($t->isResting(1, Carbon::parse('2029-01-04')))->toBeTrue();
    expect($t->isResting(1, Carbon::parse('2029-01-05')))->toBeTrue();
    expect($t->isResting(1, Carbon::parse('2029-01-06')))->toBeFalse();
    expect($t->activeWindow(1, Carbon::parse('2029-01-05'))['end']->toDateString())->toBe('2029-01-05');
});

it('pauses (does not reset) on days off and on other shift types', function () {
    $t = srlTimeline([['night', 3, 2]], [
        srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night'),
        srlEvent('2029-01-04', 'day'), srlEvent('2029-01-06', 'night'),
    ]);

    expect($t->isResting(1, Carbon::parse('2029-01-07')))->toBeTrue();
});

it('replays in (date, slot) order regardless of the order events were given', function () {
    $t = srlTimeline([['night', 2, 1]], [
        srlEvent('2029-01-02', 'night', null, 2), srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'day', null, 1),
    ]);

    // Second night is on 01-02 → window 01-03..01-03.
    expect($t->isResting(1, Carbon::parse('2029-01-03')))->toBeTrue();
    expect($t->statusAsOf(1, Carbon::parse('2029-01-02'))[0]['current_count'])->toBe(1);
});

it('as-of excludes assignments on or after the requested day', function () {
    $t = srlTimeline([['night', 3, 2]], [
        srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night'), srlEvent('2029-01-03', 'night'),
    ]);

    expect($t->statusAsOf(1, Carbon::parse('2029-01-02')))->toBe([
        ['shift_type' => 'night', 'current_count' => 1, 'streak_length' => 3, 'resting_until' => null],
    ]);
});

it('ignores a rest-day assignment by default (null or not_counted)', function () {
    foreach ([null, StreakTimeline::NOT_COUNTED] as $policy) {
        $t = srlTimeline([['night', 3, 2]], [
            srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night'), srlEvent('2029-01-03', 'night'),
            srlEvent('2029-01-04', 'night', $policy),
            srlEvent('2029-01-06', 'night'), srlEvent('2029-01-07', 'night'),
        ]);

        expect($t->statusAsOf(1, Carbon::parse('2029-01-08'))[0]['current_count'])->toBe(2);
        expect($t->isResting(1, Carbon::parse('2029-01-08')))->toBeFalse();
    }
});

it('counts a rest-day assignment toward the next streak when the policy is counts', function () {
    $t = srlTimeline([['night', 3, 2]], [
        srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night'), srlEvent('2029-01-03', 'night'),
        srlEvent('2029-01-04', 'night', StreakTimeline::COUNTS),
        srlEvent('2029-01-06', 'night'), srlEvent('2029-01-07', 'night'),
    ]);

    // 04 (counts), 06, 07 → third counted night on 07 → window 08..09.
    expect($t->isResting(1, Carbon::parse('2029-01-08')))->toBeTrue();
    expect($t->isResting(1, Carbon::parse('2029-01-09')))->toBeTrue();
});

it('extends every covering window to D + rest_days when the policy is extends_rest, never shortening it', function () {
    $t = srlTimeline([['night', 3, 2]], [
        srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night'), srlEvent('2029-01-03', 'night'),
        srlEvent('2029-01-04', 'day', StreakTimeline::EXTENDS_REST),
    ]);

    expect($t->activeWindow(1, Carbon::parse('2029-01-05'))['end']->toDateString())->toBe('2029-01-06');
    expect($t->isResting(1, Carbon::parse('2029-01-06')))->toBeTrue();
    expect($t->isResting(1, Carbon::parse('2029-01-07')))->toBeFalse();
});

it('unions rest windows from different rules', function () {
    $t = srlTimeline([['night', 2, 1], ['day', 2, 3]], [
        srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night'), // window 01-03
        srlEvent('2029-01-05', 'day'), srlEvent('2029-01-06', 'day'),     // window 01-07..01-09
    ]);

    expect($t->isResting(1, Carbon::parse('2029-01-03')))->toBeTrue();
    expect($t->isResting(1, Carbon::parse('2029-01-04')))->toBeFalse();
    expect($t->isResting(1, Carbon::parse('2029-01-09')))->toBeTrue();
});

it('re-evaluates history when a rule changes (state is never stored)', function () {
    $events = [srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night'), srlEvent('2029-01-03', 'night')];

    expect(srlTimeline([['night', 3, 2]], $events)->isResting(1, Carbon::parse('2029-01-04')))->toBeTrue();
    expect(srlTimeline([['night', 4, 2]], $events)->isResting(1, Carbon::parse('2029-01-04')))->toBeFalse();
});

it('reports resting_until only for windows ending on or after windowsFrom', function () {
    $t = srlTimeline([['night', 2, 1]], [srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night')]); // window 01-03

    expect($t->statusAsOf(1, Carbon::parse('2029-02-01'))[0]['resting_until'])->toBeNull();
    expect($t->statusAsOf(1, Carbon::parse('2029-02-01'), Carbon::parse('2029-01-01'))[0]['resting_until'])->toBe('2029-01-03');
});

it('is never resting and reports no status without rules', function () {
    $t = srlTimeline([], [srlEvent('2029-01-01', 'night')]);

    expect($t->hasRules())->toBeFalse();
    expect($t->isResting(1, Carbon::parse('2029-01-02')))->toBeFalse();
    expect($t->statusAsOf(1, Carbon::parse('2029-01-02')))->toBe([]);
});

it('append() extends the replay for later days', function () {
    $t = srlTimeline([['night', 2, 1]], [srlEvent('2029-01-01', 'night')]);
    $t->append(1, 'night', Carbon::parse('2029-01-02'), 1);

    expect($t->isResting(1, Carbon::parse('2029-01-03')))->toBeTrue();
});

it('loads only the group\'s assignments before $until, with their rest_policy', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $other = ShiftGroup::create(['name' => 'Other', 'sort_order' => 1]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    foreach (['2029-01-01', '2029-01-02'] as $date) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);
    }
    $elsewhere = Shift::create(['date' => '2029-01-01', 'shift_number' => 1, 'shift_group_id' => $other->id, 'shift_type' => 'night']);
    ShiftAssignment::create(['shift_id' => $elsewhere->id, 'user_id' => $employee->id]);
    $restDay = Shift::create(['date' => '2029-01-03', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    ShiftAssignment::create(['shift_id' => $restDay->id, 'user_id' => $employee->id, 'rest_policy' => StreakTimeline::EXTENDS_REST]);

    $timeline = app(StreakReplay::class)->forGroup($group, [$employee->id], Carbon::parse('2029-02-01'));

    // Window 01-03..01-04, extended by the 01-03 rest-day shift to 01-05.
    expect($timeline->activeWindow($employee->id, Carbon::parse('2029-01-04'))['end']->toDateString())->toBe('2029-01-05');

    $unassigned = ShiftAssignment::where('shift_id', $restDay->id)->first();
    $unassigned->delete();
    ShiftAssignment::where('user_id', $employee->id)->whereHas('shift', fn ($q) => $q->where('date', '2029-01-02'))->delete();
    $timeline = app(StreakReplay::class)->forGroup($group, [$employee->id], Carbon::parse('2029-02-01'));

    // Unassigning the window-starting night clears the window (old tracker kept it forever).
    expect($timeline->isResting($employee->id, Carbon::parse('2029-01-03')))->toBeFalse();
});

it('treats a later slot on the day the window opens as a normal shift, not a rest-day event', function () {
    $t = srlTimeline([['night', 2, 1]], [
        srlEvent('2029-01-01', 'night'),
        srlEvent('2029-01-02', 'night', null, 1),
        srlEvent('2029-01-02', 'night', null, 2),
    ]);

    expect($t->statusAsOf(1, Carbon::parse('2029-01-03'))[0]['current_count'])->toBe(1);
    expect($t->isResting(1, Carbon::parse('2029-01-03')))->toBeTrue();
});

it('answers a month of auto-fill isResting calls quickly over years of history', function () {
    // 30 users x 3 years, ~75 events per user per year, alternating day/night.
    $events = [];
    for ($u = 1; $u <= 30; $u++) {
        $d = Carbon::parse('2026-01-01');
        for ($i = 0; $i < 225; $i++) {
            $events[$u][] = srlEvent($d->toDateString(), $i % 3 === 2 ? 'night' : 'day', null, 1 + $i % 3);
            $d->addDays(4);
        }
    }
    $t = new StreakTimeline(srlRules([['night', 3, 2], ['day', 5, 1]]), $events);

    $day = Carbon::parse('2029-01-01');
    $calls = 0;
    $started = microtime(true);
    for ($d = 0; $d < 30; $d++) {
        for ($slot = 1; $slot <= 3; $slot++) {
            for ($u = 1; $u <= 30; $u++) {
                $t->isResting($u, $day);
                $calls++;
            }
            $t->append(($d * 3 + $slot) % 30 + 1, 'night', $day, $slot);
        }
        $day->addDay();
    }
    $elapsed = microtime(true) - $started;

    expect($calls)->toBe(2700);
    expect($elapsed)->toBeLessThan(3.0);
});

function rrcEvent(int $id, string $date, ?string $type, ?string $policy = null, int $slot = 1): array
{
    return ['assignment_id' => $id, 'date' => $date, 'shift_number' => $slot, 'shift_type' => $type, 'rest_policy' => $policy];
}

it('reports an existing later shift that a new earlier shift would put into rest', function () {
    // night 2 in a row → 2 days off. Existing: night 01-02, night 01-05.
    $t = new StreakTimeline(srlRules([['night', 2, 2]]), [1 => [
        rrcEvent(10, '2029-01-02', 'night'), rrcEvent(11, '2029-01-05', 'night'),
    ]]);

    // Adding night 01-03 → second night on 01-03 → rest 01-04..01-05 → covers 01-05.
    $affected = $t->newlyResting(1, 'night', Carbon::parse('2029-01-03'), 1);

    expect($affected)->toHaveCount(1);
    expect($affected[0])->toMatchArray(['assignment_id' => 11, 'date' => '2029-01-05', 'resting_until' => '2029-01-05']);
    expect($affected[0]['rule']->shift_type)->toBe('night');
    expect($t->wouldConflict(1, 'night', Carbon::parse('2029-01-03'), 1))->toBeTrue();
});

it('follows chain effects far beyond the candidate day', function () {
    // Rule night 3 → 2. Existing nights 01-10, 01-24, 01-25, 01-26.
    //   without: 01-10 c1, 01-24 c2, 01-25 c3 → rest 01-26..01-27 → 01-26 covered.
    //   with night 01-09: 01-09 c1, 01-10 c2, 01-24 c3 → rest 01-25..01-26 → 01-25 and 01-26 covered.
    // Only 01-25 is NEWLY covered (two weeks after the candidate); 01-26 already was.
    $t = new StreakTimeline(srlRules([['night', 3, 2]]), [1 => [
        rrcEvent(20, '2029-01-10', 'night'), rrcEvent(21, '2029-01-24', 'night'),
        rrcEvent(22, '2029-01-25', 'night'), rrcEvent(23, '2029-01-26', 'night'),
    ]]);

    $affected = $t->newlyResting(1, 'night', Carbon::parse('2029-01-09'), 1);

    expect(array_column($affected, 'assignment_id'))->toBe([22]);
    expect($affected[0]['resting_until'])->toBe('2029-01-26');
});

it('never reports assignments on or before the candidate day', function () {
    $t = new StreakTimeline(srlRules([['night', 1, 1]]), [1 => [
        rrcEvent(30, '2029-01-03', 'night', null, 2), rrcEvent(31, '2029-01-02', 'night'),
    ]]);

    // A night on 01-03 slot 1 opens 01-04; slot 2 on the same day and 01-02 are untouched.
    expect($t->newlyResting(1, 'night', Carbon::parse('2029-01-03'), 1))->toBe([]);
});

it('takes the candidate rest_policy into account', function () {
    // night 2 → 2. Existing nights 01-01, 01-02 (rest 01-03..01-04), 01-06.
    $events = [rrcEvent(40, '2029-01-01', 'night'), rrcEvent(41, '2029-01-02', 'night'), rrcEvent(42, '2029-01-06', 'night')];
    $t = new StreakTimeline(srlRules([['night', 2, 2]]), [1 => $events]);

    // Candidate on rest day 01-04: not_counted changes nothing; extends_rest → end 01-06 → covers 01-06.
    expect($t->newlyResting(1, 'night', Carbon::parse('2029-01-04'), 1, StreakTimeline::NOT_COUNTED))->toBe([]);
    expect(array_column($t->newlyResting(1, 'night', Carbon::parse('2029-01-04'), 1, StreakTimeline::EXTENDS_REST), 'assignment_id'))->toBe([42]);
});

it('lists the assignments currently covered by a rest window', function () {
    $t = new StreakTimeline(srlRules([['night', 2, 2]]), [1 => [
        rrcEvent(50, '2029-01-01', 'night'), rrcEvent(51, '2029-01-02', 'night'),
        rrcEvent(52, '2029-01-03', 'day'), rrcEvent(53, '2029-01-05', 'night'),
    ]]);

    expect($t->restingAssignments(1))->toBe([52 => '2029-01-04']);
    expect($t->restingAssignments(2))->toBe([]);
});

it('loads every date when until is null, with assignment ids', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    $ids = [];
    foreach (['2029-01-01', '2029-01-02', '2031-06-01', '2031-06-02'] as $date) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
        $ids[$date] = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id])->id;
    }
    $rest = Shift::create(['date' => '2029-01-03', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day']);
    $restId = ShiftAssignment::create(['shift_id' => $rest->id, 'user_id' => $employee->id])->id;

    $timeline = app(StreakReplay::class)->forGroup($group, [$employee->id]);

    expect($timeline->restingAssignments($employee->id))->toBe([$restId => '2029-01-04']);
    // Far-future assignments are loaded: without the candidate, 2031-06-01 c1, 06-02 c2 (nothing covered);
    // with a night on 2031-05-31: 05-31 c1, 06-01 c2 → rest 06-02..06-03 → 06-02 newly covered.
    expect(array_column($timeline->newlyResting($employee->id, 'night', Carbon::parse('2031-05-31'), 1), 'assignment_id'))
        ->toBe([$ids['2031-06-02']]);
    expect($timeline->wouldConflict($employee->id, 'day', Carbon::parse('2029-01-04'), 1))->toBeTrue();
});

it('reports the final end of the longest covering window even when a later extension lengthens another one', function () {
    $t = new StreakTimeline(srlRules([['night', 1, 3], ['late', 1, 2]]), [1 => [
        rrcEvent(1, '2029-01-01', 'night'),
        rrcEvent(2, '2029-01-03', 'late', StreakTimeline::COUNTS),
        rrcEvent(3, '2029-01-04', 'day'),
        rrcEvent(4, '2029-01-04', 'day', StreakTimeline::EXTENDS_REST, 2),
    ]]);

    expect($t->restingAssignments(1)[3])->toBe('2029-01-07');
});
