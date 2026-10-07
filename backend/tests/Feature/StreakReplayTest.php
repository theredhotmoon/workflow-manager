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
