<?php

// backend/tests/Unit/StreakTimelineTest.php

use App\Models\ShiftStreakRule;
use App\Services\StreakTimeline;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** @param list<array{0: string, 1: int, 2: int}> $defs [shift_type, streak_length, rest_days] */
function utlRules(array $defs): Collection
{
    return collect($defs)
        ->map(fn ($d) => new ShiftStreakRule(['shift_type' => $d[0], 'streak_length' => $d[1], 'rest_days' => $d[2]]))
        ->keyBy('shift_type');
}

function utlEvent(string $date, ?string $type, ?string $policy = null, int $slot = 1, ?int $id = null): array
{
    $event = ['date' => $date, 'shift_number' => $slot, 'shift_type' => $type, 'rest_policy' => $policy];
    if ($id !== null) {
        $event['assignment_id'] = $id;
    }

    return $event;
}

function utlTimeline(array $rules, array $events, int $userId = 1): StreakTimeline
{
    return new StreakTimeline(utlRules($rules), [$userId => $events]);
}

function utlDay(string $date): Carbon
{
    return Carbon::parse($date);
}

// ---------------------------------------------------------------- rules and basic replay

it('reports whether it has rules', function () {
    expect(utlTimeline([['night', 3, 2]], [])->hasRules())->toBeTrue();
    expect(utlTimeline([], [utlEvent('2029-01-01', 'night')])->hasRules())->toBeFalse();
});

it('is never resting, reports no status and no conflicts without rules', function () {
    $t = utlTimeline([], [utlEvent('2029-01-01', 'night'), utlEvent('2029-01-05', 'night', StreakTimeline::COUNTS, 1, 7)]);

    expect($t->isResting(1, utlDay('2029-01-02')))->toBeFalse();
    expect($t->activeWindow(1, utlDay('2029-01-02')))->toBeNull();
    expect($t->finalWindowAt(1, utlDay('2029-01-02')))->toBeNull();
    expect($t->statusAsOf(1, utlDay('2029-01-02')))->toBe([]);
    expect($t->newlyResting(1, 'night', utlDay('2029-01-02'), 1))->toBe([]);
    expect($t->cascadePolicy(1, 'night', utlDay('2029-01-02'), 1, null, StreakTimeline::COUNTS))->toBe([]);
    expect($t->wouldConflict(1, 'night', utlDay('2029-01-02'), 1))->toBeFalse();
    expect($t->restingAssignments(1))->toBe([]);
    // Without rules nothing is covered, so every stored policy is stale.
    expect($t->uncoveredWithPolicy(1))->toBe([7]);
});

it('opens a rest window [D+1, D+R] after the Nth shift of the type and not before', function () {
    $t = utlTimeline([['night', 3, 2]], [
        utlEvent('2029-01-01', 'night'), utlEvent('2029-01-02', 'night'), utlEvent('2029-01-03', 'night'),
    ]);

    expect($t->isResting(1, utlDay('2029-01-03')))->toBeFalse();
    expect($t->isResting(1, utlDay('2029-01-04')))->toBeTrue();
    expect($t->isResting(1, utlDay('2029-01-05')))->toBeTrue();
    expect($t->isResting(1, utlDay('2029-01-06')))->toBeFalse();
});

it('describes the active window with its rule and both edges at midnight', function () {
    $t = utlTimeline([['night', 3, 2]], [
        utlEvent('2029-01-01', 'night'), utlEvent('2029-01-02', 'night'), utlEvent('2029-01-03', 'night'),
    ]);

    $window = $t->activeWindow(1, utlDay('2029-01-04 15:30'));

    expect($window['rule'])->toBeInstanceOf(ShiftStreakRule::class);
    expect($window['rule']->shift_type)->toBe('night');
    expect($window['rule']->rest_days)->toBe(2);
    expect($window['start']->toDateTimeString())->toBe('2029-01-04 00:00:00');
    expect($window['end']->toDateTimeString())->toBe('2029-01-05 00:00:00');
    expect($t->activeWindow(1, utlDay('2029-01-03')))->toBeNull();
    expect($t->activeWindow(1, utlDay('2029-01-06')))->toBeNull();
});

it('pauses (never resets) on days off and on shift types without a rule', function () {
    $t = utlTimeline([['night', 3, 2]], [
        utlEvent('2029-01-01', 'night'), utlEvent('2029-01-02', 'night'),
        utlEvent('2029-01-04', 'day'), utlEvent('2029-01-05', null), utlEvent('2029-01-09', 'night'),
    ]);

    expect($t->isResting(1, utlDay('2029-01-09')))->toBeFalse();
    expect($t->isResting(1, utlDay('2029-01-10')))->toBeTrue();
    expect($t->isResting(1, utlDay('2029-01-11')))->toBeTrue();
    expect($t->isResting(1, utlDay('2029-01-12')))->toBeFalse();
});

it('keeps users apart and knows nothing about an unknown user', function () {
    $t = new StreakTimeline(utlRules([['night', 2, 1]]), [
        1 => [utlEvent('2029-01-01', 'night'), utlEvent('2029-01-02', 'night')],
        2 => [utlEvent('2029-01-01', 'night')],
    ]);

    expect($t->isResting(1, utlDay('2029-01-03')))->toBeTrue();
    expect($t->isResting(2, utlDay('2029-01-03')))->toBeFalse();
    expect($t->isResting(99, utlDay('2029-01-03')))->toBeFalse();
    expect($t->statusAsOf(99, utlDay('2029-01-03')))->toBe([
        ['shift_type' => 'night', 'current_count' => 0, 'streak_length' => 2, 'resting_until' => null],
    ]);
    expect($t->restingAssignments(99))->toBe([]);
    expect($t->uncoveredWithPolicy(99))->toBe([]);
});

it('unions rest windows from different rules and reports the one ending last', function () {
    $t = utlTimeline([['night', 2, 1], ['day', 2, 3]], [
        utlEvent('2029-01-01', 'night'), utlEvent('2029-01-02', 'night'), // window 01-03
        utlEvent('2029-01-05', 'day'), utlEvent('2029-01-06', 'day'),     // window 01-07..01-09
    ]);

    expect($t->isResting(1, utlDay('2029-01-03')))->toBeTrue();
    expect($t->isResting(1, utlDay('2029-01-04')))->toBeFalse();
    expect($t->isResting(1, utlDay('2029-01-07')))->toBeTrue();
    expect($t->isResting(1, utlDay('2029-01-09')))->toBeTrue();
    expect($t->activeWindow(1, utlDay('2029-01-08'))['rule']->shift_type)->toBe('day');
});

// ---------------------------------------------------------------- statusAsOf

it('counts assignments strictly before the as-of day and reports resting_until', function () {
    $t = utlTimeline([['night', 3, 2]], [
        utlEvent('2029-01-01', 'night'), utlEvent('2029-01-02', 'night'), utlEvent('2029-01-03', 'night'),
    ]);

    expect($t->statusAsOf(1, utlDay('2029-01-01')))->toBe([
        ['shift_type' => 'night', 'current_count' => 0, 'streak_length' => 3, 'resting_until' => null],
    ]);
    expect($t->statusAsOf(1, utlDay('2029-01-03'))[0]['current_count'])->toBe(2);
    // The third night completes the streak: the count resets and the window 01-04..01-05 is reported.
    expect($t->statusAsOf(1, utlDay('2029-01-04')))->toBe([
        ['shift_type' => 'night', 'current_count' => 0, 'streak_length' => 3, 'resting_until' => '2029-01-05'],
    ]);
    expect($t->statusAsOf(1, utlDay('2029-01-05'))[0]['resting_until'])->toBe('2029-01-05');
    // A window that ended before the as-of day is not "resting until" anything.
    expect($t->statusAsOf(1, utlDay('2029-01-06'))[0]['resting_until'])->toBeNull();
});

it('reports resting_until for windows ending on or after windowsFrom, the latest per rule', function () {
    $t = utlTimeline([['night', 2, 1]], [
        utlEvent('2029-01-01', 'night'), utlEvent('2029-01-02', 'night'), // window 01-03
        utlEvent('2029-01-10', 'night'), utlEvent('2029-01-11', 'night'), // window 01-12
    ]);

    expect($t->statusAsOf(1, utlDay('2029-02-01'))[0]['resting_until'])->toBeNull();
    expect($t->statusAsOf(1, utlDay('2029-02-01'), utlDay('2029-01-01'))[0]['resting_until'])->toBe('2029-01-12');
    expect($t->statusAsOf(1, utlDay('2029-01-05'), utlDay('2029-01-03'))[0]['resting_until'])->toBe('2029-01-03');
    expect($t->statusAsOf(1, utlDay('2029-01-05'), utlDay('2029-01-04'))[0]['resting_until'])->toBeNull();
});

it('lists one status row per rule in rule order, including rules the user never worked', function () {
    $t = utlTimeline([['night', 3, 2], ['day', 5, 1]], [utlEvent('2029-01-01', 'night')]);

    expect($t->statusAsOf(1, utlDay('2029-01-02')))->toBe([
        ['shift_type' => 'night', 'current_count' => 1, 'streak_length' => 3, 'resting_until' => null],
        ['shift_type' => 'day', 'current_count' => 0, 'streak_length' => 5, 'resting_until' => null],
    ]);
});

// ---------------------------------------------------------------- rest policies

it('ignores a rest-day assignment by default (null or not_counted)', function (?string $policy) {
    $t = utlTimeline([['night', 3, 2]], [
        utlEvent('2029-01-01', 'night'), utlEvent('2029-01-02', 'night'), utlEvent('2029-01-03', 'night'),
        utlEvent('2029-01-04', 'night', $policy),
        utlEvent('2029-01-06', 'night'), utlEvent('2029-01-07', 'night'),
    ]);

    expect($t->statusAsOf(1, utlDay('2029-01-08'))[0]['current_count'])->toBe(2);
    expect($t->isResting(1, utlDay('2029-01-08')))->toBeFalse();
    expect($t->activeWindow(1, utlDay('2029-01-05'))['end']->toDateString())->toBe('2029-01-05');
})->with(['null' => [null], 'not_counted' => [StreakTimeline::NOT_COUNTED]]);

it('counts a rest-day assignment toward the next streak when the policy is counts', function () {
    $t = utlTimeline([['night', 3, 2]], [
        utlEvent('2029-01-01', 'night'), utlEvent('2029-01-02', 'night'), utlEvent('2029-01-03', 'night'),
        utlEvent('2029-01-04', 'night', StreakTimeline::COUNTS),
        utlEvent('2029-01-06', 'night'), utlEvent('2029-01-07', 'night'),
    ]);

    expect($t->statusAsOf(1, utlDay('2029-01-05'))[0]['current_count'])->toBe(1);
    // 04 (counts), 06, 07: the third counted night is on 07, so a new window 08..09 opens.
    expect($t->isResting(1, utlDay('2029-01-08')))->toBeTrue();
    expect($t->isResting(1, utlDay('2029-01-09')))->toBeTrue();
    expect($t->isResting(1, utlDay('2029-01-10')))->toBeFalse();
});

it('counts only shifts with a rule even when the policy is counts', function () {
    $t = utlTimeline([['night', 3, 2]], [
        utlEvent('2029-01-01', 'night'), utlEvent('2029-01-02', 'night'), utlEvent('2029-01-03', 'night'),
        utlEvent('2029-01-04', 'day', StreakTimeline::COUNTS),
    ]);

    expect($t->statusAsOf(1, utlDay('2029-01-05'))[0]['current_count'])->toBe(0);
});

it('extends every covering window to D + rest_days when the policy is extends_rest, never shortening it', function () {
    $t = utlTimeline([['night', 3, 2]], [
        utlEvent('2029-01-01', 'night'), utlEvent('2029-01-02', 'night'), utlEvent('2029-01-03', 'night'), // window 04..05
        utlEvent('2029-01-04', 'day', StreakTimeline::EXTENDS_REST),                                       // -> 04..06
    ]);

    expect($t->activeWindow(1, utlDay('2029-01-05'))['end']->toDateString())->toBe('2029-01-06');
    expect($t->isResting(1, utlDay('2029-01-06')))->toBeTrue();
    expect($t->isResting(1, utlDay('2029-01-07')))->toBeFalse();
    // The extension never counts the shift, even if its type has a rule.
    expect($t->statusAsOf(1, utlDay('2029-01-07'))[0]['current_count'])->toBe(0);
});

it('extends by the window rule\'s rest_days and keeps extending from each later rest-day shift', function () {
    $t = utlTimeline([['night', 2, 5]], [
        utlEvent('2029-01-01', 'night'), utlEvent('2029-01-02', 'night'), // window 03..07
        utlEvent('2029-01-03', 'day', StreakTimeline::EXTENDS_REST),      // 03 + 5 = 08 > 07: extends
        utlEvent('2029-01-04', 'day', StreakTimeline::EXTENDS_REST),      // 04 + 5 = 09: extends again
    ]);
    $short = utlTimeline([['night', 2, 5], ['day', 1, 1]], [
        utlEvent('2029-01-01', 'night'), utlEvent('2029-01-02', 'night'), // window 03..07
        utlEvent('2029-01-03', 'day', StreakTimeline::EXTENDS_REST),      // the window's rule is night: 03 + 5 = 08
    ]);

    expect($t->finalWindowAt(1, utlDay('2029-01-03'))['end']->toDateString())->toBe('2029-01-09');
    // The extension uses the window's own rule's rest_days, not the rest-day shift's rule.
    expect($short->finalWindowAt(1, utlDay('2029-01-03'))['end']->toDateString())->toBe('2029-01-08');
});

it('extends each covering window by its own rule when several overlap', function () {
    $t = utlTimeline([['night', 1, 3], ['late', 1, 1]], [
        utlEvent('2029-01-01', 'night'),                                        // night window 02..04
        utlEvent('2029-01-02', 'late', StreakTimeline::COUNTS),                 // late window 03..03
        utlEvent('2029-01-03', 'day', StreakTimeline::EXTENDS_REST),            // night -> 06, late -> 04
    ]);

    $status = $t->statusAsOf(1, utlDay('2029-01-04'));

    expect($status[0])->toMatchArray(['shift_type' => 'night', 'resting_until' => '2029-01-06']);
    expect($status[1])->toMatchArray(['shift_type' => 'late', 'resting_until' => '2029-01-04']);
});

it('treats a later slot on the day the streak completes as a normal shift, not a rest-day event', function () {
    $t = utlTimeline([['night', 2, 2]], [
        utlEvent('2029-01-01', 'night'), utlEvent('2029-01-02', 'night', null, 1), // window 03..04
        utlEvent('2029-01-02', 'night', StreakTimeline::EXTENDS_REST, 2),           // same day: not covered, counts
    ]);

    expect($t->statusAsOf(1, utlDay('2029-01-03'))[0])->toMatchArray(['current_count' => 1, 'resting_until' => '2029-01-04']);
});

// ---------------------------------------------------------------- ordering and append

it('replays in (date, slot) order regardless of the order events were given', function () {
    $t = utlTimeline([['night', 2, 1]], [
        utlEvent('2029-01-02', 'night', null, 2), utlEvent('2029-01-01', 'night'), utlEvent('2029-01-02', 'day', null, 1),
    ]);

    // Second night is on 01-02 slot 2: window 01-03..01-03.
    expect($t->isResting(1, utlDay('2029-01-03')))->toBeTrue();
    expect($t->statusAsOf(1, utlDay('2029-01-02'))[0]['current_count'])->toBe(1);
});

it('append() extends the replay for later days', function () {
    $t = utlTimeline([['night', 2, 1]], [utlEvent('2029-01-01', 'night')]);
    expect($t->isResting(1, utlDay('2029-01-03')))->toBeFalse();

    $t->append(1, 'night', utlDay('2029-01-02'), 1);

    expect($t->isResting(1, utlDay('2029-01-03')))->toBeTrue();
    expect($t->statusAsOf(1, utlDay('2029-01-03'))[0]['resting_until'])->toBe('2029-01-03');
});

it('append() re-sorts an out-of-order event and drops the cached baseline', function () {
    $t = utlTimeline([['night', 3, 2]], [utlEvent('2029-01-02', 'night'), utlEvent('2029-01-03', 'night')]);
    // Warm every cache: the baseline and the status before the appended day.
    expect($t->isResting(1, utlDay('2029-01-04')))->toBeFalse();
    expect($t->restingAssignments(1))->toBe([]);
    expect($t->statusAsOf(1, utlDay('2029-01-02'))[0]['current_count'])->toBe(0);

    $t->append(1, 'night', utlDay('2029-01-01'), 1, null, 77);

    // 01-01 now replays first: the streak completes on 01-03 and 01-04..01-05 is rest.
    expect($t->statusAsOf(1, utlDay('2029-01-02'))[0]['current_count'])->toBe(1);
    expect($t->isResting(1, utlDay('2029-01-04')))->toBeTrue();
    expect($t->isResting(1, utlDay('2029-01-05')))->toBeTrue();
    expect($t->finalWindowAt(1, utlDay('2029-01-05'))['start']->toDateString())->toBe('2029-01-04');
});

it('append() re-sorts by slot within a day', function () {
    $t = utlTimeline([['night', 2, 1]], [utlEvent('2029-01-01', 'night', null, 2)]);
    $t->append(1, 'night', utlDay('2029-01-01'), 1);

    // Slot 1 then slot 2 on 01-01 complete the streak: 01-02 is rest.
    expect($t->statusAsOf(1, utlDay('2029-01-02')))->toBe([
        ['shift_type' => 'night', 'current_count' => 0, 'streak_length' => 2, 'resting_until' => '2029-01-02'],
    ]);
});

it('append() builds a history for a user that had none, carrying policy and assignment id', function () {
    $t = utlTimeline([['night', 2, 2]], []);
    $t->append(5, 'night', utlDay('2029-01-01'), 1, null, 1);
    $t->append(5, 'night', utlDay('2029-01-02'), 1, null, 2);
    $t->append(5, 'day', utlDay('2029-01-03'), 1, StreakTimeline::EXTENDS_REST, 3);
    $t->append(5, 'day', utlDay('2029-01-04'), 1, StreakTimeline::COUNTS, 4);
    $t->append(5, 'day', utlDay('2029-01-09'), 1, StreakTimeline::COUNTS, 5);

    expect($t->restingAssignments(5))->toBe([3 => '2029-01-05', 4 => '2029-01-05']);
    expect($t->uncoveredWithPolicy(5))->toBe([5]);
});

// ---------------------------------------------------------------- finalWindowAt vs activeWindow

it('reports the as-of end in activeWindow and the final end in finalWindowAt', function () {
    $t = utlTimeline([['night', 2, 2]], [
        utlEvent('2029-01-01', 'night'), utlEvent('2029-01-02', 'night'), // window 03..04
        utlEvent('2029-01-04', 'day', StreakTimeline::EXTENDS_REST),      // -> 03..06
    ]);

    // On 01-03 the extension has not happened yet, so the window still ends on 01-04...
    expect($t->activeWindow(1, utlDay('2029-01-03'))['end']->toDateString())->toBe('2029-01-04');
    // ...but the final end, once every event is in, is 01-06.
    expect($t->finalWindowAt(1, utlDay('2029-01-03'))['end']->toDateString())->toBe('2029-01-06');
    expect($t->finalWindowAt(1, utlDay('2029-01-03'))['start']->toDateString())->toBe('2029-01-03');
    expect($t->finalWindowAt(1, utlDay('2029-01-03'))['rule']->shift_type)->toBe('night');
    // After the extension both agree.
    expect($t->activeWindow(1, utlDay('2029-01-05'))['end']->toDateString())->toBe('2029-01-06');
    expect($t->finalWindowAt(1, utlDay('2029-01-06'))['end']->toDateString())->toBe('2029-01-06');
    expect($t->finalWindowAt(1, utlDay('2029-01-07')))->toBeNull();
    expect($t->finalWindowAt(1, utlDay('2029-01-02')))->toBeNull();
});

it('picks the window with the latest final end even when a later extension lengthens another one', function () {
    $t = utlTimeline([['night', 1, 3], ['late', 1, 2]], [
        utlEvent('2029-01-01', 'night', null, 1, 1),                          // night window 02..04
        utlEvent('2029-01-03', 'late', StreakTimeline::COUNTS, 1, 2),         // late window 04..05
        utlEvent('2029-01-04', 'day', null, 1, 3),                            // covered by both; late ends last so far
        utlEvent('2029-01-04', 'day', StreakTimeline::EXTENDS_REST, 2, 4),    // night -> 07, late -> 06
    ]);

    expect($t->activeWindow(1, utlDay('2029-01-04'))['rule']->shift_type)->toBe('late');
    expect($t->activeWindow(1, utlDay('2029-01-04'))['end']->toDateString())->toBe('2029-01-05');
    expect($t->finalWindowAt(1, utlDay('2029-01-04'))['rule']->shift_type)->toBe('night');
    expect($t->finalWindowAt(1, utlDay('2029-01-04'))['end']->toDateString())->toBe('2029-01-07');
    // The late shift on 01-03 rests inside the night window too (it was counted anyway).
    expect($t->restingAssignments(1))->toBe([2 => '2029-01-07', 3 => '2029-01-07', 4 => '2029-01-07']);
});

// ---------------------------------------------------------------- restingAssignments / uncoveredWithPolicy

it('lists the assignments currently covered by a rest window with the final end of the longest one', function () {
    $t = utlTimeline([['night', 2, 2]], [
        utlEvent('2029-01-01', 'night', null, 1, 50), utlEvent('2029-01-02', 'night', null, 1, 51), // window 03..04
        utlEvent('2029-01-03', 'day', null, 1, 52), utlEvent('2029-01-04', 'day', null, 1),         // the second has no id
        utlEvent('2029-01-05', 'night', null, 1, 53),
    ]);

    expect($t->restingAssignments(1))->toBe([52 => '2029-01-04']);
});

it('lists stored assignments that carry a policy outside every rest window', function () {
    $t = utlTimeline([['night', 2, 2]], [
        utlEvent('2029-01-01', 'night', null, 1, 1), utlEvent('2029-01-02', 'night', null, 1, 2),  // window 03..04
        utlEvent('2029-01-03', 'day', StreakTimeline::NOT_COUNTED, 1, 3),                           // covered: keeps it
        utlEvent('2029-01-06', 'day', StreakTimeline::COUNTS, 1, 4),                                // uncovered: stale
        utlEvent('2029-01-07', 'day', null, 1, 5),                                                  // no policy
        utlEvent('2029-01-08', 'day', StreakTimeline::EXTENDS_REST),                                // not a stored assignment
        utlEvent('2029-01-09', 'night', StreakTimeline::EXTENDS_REST, 1, 6),                        // uncovered: stale
    ]);

    expect($t->uncoveredWithPolicy(1))->toBe([4, 6]);
});

// ---------------------------------------------------------------- newlyResting / wouldConflict

it('reports an existing later shift that a new earlier shift would put into rest', function () {
    // night 2 in a row -> 2 days off. Existing: night 01-02, night 01-05.
    $t = utlTimeline([['night', 2, 2]], [
        utlEvent('2029-01-02', 'night', null, 1, 10), utlEvent('2029-01-05', 'night', null, 2, 11),
    ]);

    // Adding night 01-03: second night on 01-03, rest 01-04..01-05 covers 01-05.
    $affected = $t->newlyResting(1, 'night', utlDay('2029-01-03'), 1);

    expect($affected)->toHaveCount(1);
    expect($affected[0])->toMatchArray([
        'assignment_id' => 11, 'date' => '2029-01-05', 'shift_number' => 2, 'shift_type' => 'night', 'resting_until' => '2029-01-05',
    ]);
    expect($affected[0]['rule'])->toBeInstanceOf(ShiftStreakRule::class);
    expect($affected[0]['rule']->shift_type)->toBe('night');
    expect($t->wouldConflict(1, 'night', utlDay('2029-01-03'), 1))->toBeTrue();
    // The candidate itself was not added.
    expect($t->isResting(1, utlDay('2029-01-04')))->toBeFalse();
});

it('reports nothing when the candidate completes no streak or the covered shifts already rest', function () {
    $t = utlTimeline([['night', 3, 2]], [
        utlEvent('2029-01-01', 'night', null, 1, 1), utlEvent('2029-01-02', 'night', null, 1, 2),
        utlEvent('2029-01-03', 'night', null, 1, 3), utlEvent('2029-01-04', 'day', null, 1, 4), // 04 already rests
        utlEvent('2029-01-10', 'day', null, 1, 5),
    ]);

    expect($t->newlyResting(1, 'day', utlDay('2029-01-02'), 2))->toBe([]);
    expect($t->wouldConflict(1, 'day', utlDay('2029-01-02'), 2))->toBeFalse();
    expect($t->wouldConflict(1, 'night', utlDay('2029-01-06'), 1))->toBeFalse();
});

it('follows chain effects far beyond the candidate day', function () {
    // Rule night 3 -> 2. Existing nights 01-10, 01-24, 01-25, 01-26.
    //   without: 01-10 c1, 01-24 c2, 01-25 c3 -> rest 01-26..01-27 -> 01-26 covered.
    //   with night 01-09: 01-09 c1, 01-10 c2, 01-24 c3 -> rest 01-25..01-26 -> 01-25 and 01-26 covered.
    // Only 01-25 is NEWLY covered; 01-26 already was.
    $t = utlTimeline([['night', 3, 2]], [
        utlEvent('2029-01-10', 'night', null, 1, 20), utlEvent('2029-01-24', 'night', null, 1, 21),
        utlEvent('2029-01-25', 'night', null, 1, 22), utlEvent('2029-01-26', 'night', null, 1, 23),
    ]);

    $affected = $t->newlyResting(1, 'night', utlDay('2029-01-09'), 1);

    expect(array_column($affected, 'assignment_id'))->toBe([22]);
    expect($affected[0]['resting_until'])->toBe('2029-01-26');
});

it('never reports assignments on or before the candidate day, and none after the last event', function () {
    $t = utlTimeline([['night', 1, 1]], [
        utlEvent('2029-01-03', 'night', null, 2, 30), utlEvent('2029-01-02', 'night', null, 1, 31),
    ]);

    // A night on 01-03 slot 1 opens 01-04; slot 2 on the same day and 01-02 are untouched.
    expect($t->newlyResting(1, 'night', utlDay('2029-01-03'), 1))->toBe([]);
    expect($t->wouldConflict(1, 'night', utlDay('2029-01-03'), 1))->toBeTrue(); // 01-03 itself rests after 01-02
    expect($t->newlyResting(1, 'night', utlDay('2029-01-04'), 1))->toBe([]);
    expect($t->newlyResting(1, 'night', utlDay('2029-02-01'), 1))->toBe([]);
});

it('takes the candidate rest_policy into account', function () {
    // night 2 -> 2. Existing nights 01-01, 01-02 (rest 01-03..01-04), 01-06.
    $t = utlTimeline([['night', 2, 2]], [
        utlEvent('2029-01-01', 'night', null, 1, 40), utlEvent('2029-01-02', 'night', null, 1, 41), utlEvent('2029-01-06', 'night', null, 1, 42),
    ]);

    // Candidate on rest day 01-04: not_counted changes nothing; extends_rest -> end 01-06 -> covers 01-06.
    expect($t->newlyResting(1, 'night', utlDay('2029-01-04'), 1, StreakTimeline::NOT_COUNTED))->toBe([]);
    expect(array_column($t->newlyResting(1, 'night', utlDay('2029-01-04'), 1, StreakTimeline::EXTENDS_REST), 'assignment_id'))->toBe([42]);
    // counts: 01-04 c1, 01-06 c2 -> rest 01-07..01-08, but no stored shift is there.
    expect($t->newlyResting(1, 'night', utlDay('2029-01-04'), 1, StreakTimeline::COUNTS))->toBe([]);
    // wouldConflict is true on a rest day whatever the policy would be.
    expect($t->wouldConflict(1, 'night', utlDay('2029-01-04'), 1))->toBeTrue();
});

it('reports affected shifts in (date, slot) order with the final end of their window', function () {
    $t = utlTimeline([['night', 2, 3]], [
        utlEvent('2029-01-01', 'night', null, 1, 1),
        utlEvent('2029-01-03', 'day', null, 2, 2), utlEvent('2029-01-03', 'day', null, 1, 3),
        utlEvent('2029-01-04', 'day', StreakTimeline::EXTENDS_REST, 1, 4), // once covered, lengthens the window to 01-07
        utlEvent('2029-01-06', 'day', null, 1, 5),
    ]);

    $affected = $t->newlyResting(1, 'night', utlDay('2029-01-02'), 1); // rest 01-03..01-05

    expect(array_column($affected, 'assignment_id'))->toBe([3, 2, 4, 5]);
    expect(array_unique(array_column($affected, 'resting_until')))->toBe(['2029-01-07']);
});

// ---------------------------------------------------------------- cascadePolicy

it('stamps round by round and drops shifts a later round moved out of rest', function () {
    // Rule night 2 -> 1. Existing nights 01-01, 01-03, 01-04, 01-05, 01-06; candidate night 01-02.
    //   before: 01-01 c1, 01-03 c2 -> rest 01-04 (id 3 covered); 01-05 c1, 01-06 c2 -> rest 01-07.
    //   round 1: 01-01, 01-02 -> rest 01-03 (id 2); 01-04 c1, 01-05 c2 -> rest 01-06 (id 5). Stamp 2 and 5.
    //   round 2: 01-03 counts c1, 01-04 c2 -> rest 01-05 (id 4). Stamp 4. 01-06 is no longer covered.
    //   round 3: 01-05 counts c1, 01-06 c2 -> rest 01-07. Nothing new.
    $t = utlTimeline([['night', 2, 1]], [
        utlEvent('2029-01-01', 'night', null, 1, 1), utlEvent('2029-01-03', 'night', null, 1, 2),
        utlEvent('2029-01-04', 'night', null, 1, 3), utlEvent('2029-01-05', 'night', null, 1, 4),
        utlEvent('2029-01-06', 'night', null, 1, 5),
    ]);

    $ids = $t->cascadePolicy(1, 'night', utlDay('2029-01-02'), 1, null, StreakTimeline::COUNTS);

    // Stamping order, minus id 5 which ends up uncovered.
    expect($ids)->toBe([2, 4]);
    expect(array_column($t->newlyResting(1, 'night', utlDay('2029-01-02'), 1), 'assignment_id'))->toBe([2, 5]);
});

it('lets extends_rest push the window over one later shift after another', function () {
    // Rule night 2 -> 1. Candidate night 01-02 covers 01-03; stamping extends_rest there reaches 01-04,
    // stamping 01-04 reaches 01-05, and so on until no stored shift follows.
    $t = utlTimeline([['night', 2, 1]], [
        utlEvent('2029-01-01', 'night', null, 1, 1), utlEvent('2029-01-03', 'night', null, 1, 2),
        utlEvent('2029-01-04', 'day', null, 1, 3), utlEvent('2029-01-05', 'day', null, 1, 4),
        utlEvent('2029-01-07', 'day', null, 1, 5),
    ]);

    expect($t->cascadePolicy(1, 'night', utlDay('2029-01-02'), 1, null, StreakTimeline::EXTENDS_REST))->toBe([2, 3, 4]);
    expect($t->cascadePolicy(1, 'night', utlDay('2029-01-02'), 1, null, StreakTimeline::NOT_COUNTED))->toBe([2]);
});

it('keeps the answer of a shift that already rested with its own policy before the assign', function () {
    // Rule night 1 -> 1: every night rests the next day.
    //   before: 01-02 (id 2) and 01-04 (id 4, not_counted: answered) are covered.
    //   round 1 (candidate counts on 01-02 slot 2 -> rest 01-03): id 3 newly covered; 01-04 is free. Stamp 3.
    //   round 2: 01-03 counts -> rest 01-04 covers id 4 again: it keeps not_counted and is not stamped.
    $t = utlTimeline([['night', 1, 1]], [
        utlEvent('2029-01-01', 'night', null, 1, 1), utlEvent('2029-01-02', 'night', null, 1, 2),
        utlEvent('2029-01-03', 'night', null, 1, 3), utlEvent('2029-01-04', 'night', StreakTimeline::NOT_COUNTED, 1, 4),
    ]);

    expect($t->cascadePolicy(1, 'night', utlDay('2029-01-02'), 2, StreakTimeline::COUNTS, StreakTimeline::COUNTS))->toBe([3]);
});

it('cascades nothing when the candidate comes after every stored shift', function () {
    $t = utlTimeline([['night', 1, 1]], [utlEvent('2029-01-01', 'night', null, 1, 1)]);

    expect($t->cascadePolicy(1, 'night', utlDay('2029-01-01'), 2, null, StreakTimeline::COUNTS))->toBe([]);
    expect($t->cascadePolicy(1, 'night', utlDay('2029-01-05'), 1, null, StreakTimeline::COUNTS))->toBe([]);
    expect($t->cascadePolicy(2, 'night', utlDay('2028-01-01'), 1, null, StreakTimeline::COUNTS))->toBe([]);
});

// ---------------------------------------------------------------- long histories: the checkpoint path

/**
 * A straightforward implementation of the spec's "Semantics" table, used as the oracle for histories
 * longer than CHECKPOINT_EVERY (16) events. Events are sorted lists with integer 'day's.
 *
 * @param  array<string, array{0: int, 1: int}>  $rules  type => [streak_length, rest_days]
 * @return array{0: array<string, int>, 1: list<array{type: string, start: int, end: int}>, 2: array<int, true>} counts, windows, covered positions
 */
function utlNaive(array $rules, array $events, int $until = PHP_INT_MAX): array
{
    $counts = [];
    $windows = [];
    $covered = [];
    foreach ($events as $pos => $e) {
        if ($e['day'] >= $until) {
            break;
        }
        $covering = [];
        foreach ($windows as $i => $w) {
            if ($e['day'] >= $w['start'] && $e['day'] <= $w['end']) {
                $covering[] = $i;
            }
        }
        $policy = $e['rest_policy'] ?? StreakTimeline::NOT_COUNTED;
        if ($covering !== []) {
            $covered[$pos] = true;
            if ($policy === StreakTimeline::EXTENDS_REST) {
                foreach ($covering as $i) {
                    $windows[$i]['end'] = max($windows[$i]['end'], $e['day'] + $rules[$windows[$i]['type']][1]);
                }
            }
            if ($policy !== StreakTimeline::COUNTS) {
                continue;
            }
        }
        $type = $e['shift_type'];
        if ($type === null || ! isset($rules[$type])) {
            continue;
        }
        $counts[$type] = ($counts[$type] ?? 0) + 1;
        if ($counts[$type] >= $rules[$type][0]) {
            $windows[] = ['type' => $type, 'start' => $e['day'] + 1, 'end' => $e['day'] + $rules[$type][1]];
            $counts[$type] = 0;
        }
    }

    return [$counts, $windows, $covered];
}

function utlNaiveLongestEnd(array $windows, int $day): ?int
{
    $end = null;
    foreach ($windows as $w) {
        if ($day >= $w['start'] && $day <= $w['end'] && ($end === null || $w['end'] > $end)) {
            $end = $w['end'];
        }
    }

    return $end;
}

function utlDate(int $day): string
{
    return gmdate('Y-m-d', $day * 86400);
}

/** @return list<array{day: int, shift_number: int, shift_type: ?string, rest_policy: ?string, assignment_id: int}> sorted */
function utlRandomHistory(int $seed, int $count): array
{
    mt_srand($seed);
    $types = ['night', 'night', 'day', 'day', 'other', null];
    $byKey = [];
    $day = 21550; // 2029-01-01
    while (count($byKey) < $count) {
        $day += mt_rand(0, 2);
        $slot = mt_rand(1, 2);
        $byKey[$day * 10 + $slot] = [
            'day' => $day,
            'shift_number' => $slot,
            'shift_type' => $types[mt_rand(0, 5)],
            'rest_policy' => mt_rand(0, 9) < 3 ? StreakTimeline::POLICIES[mt_rand(0, 2)] : null,
            'assignment_id' => 0,
        ];
    }
    ksort($byKey);
    $events = array_values($byKey);
    foreach ($events as $i => $_) {
        $events[$i]['assignment_id'] = $i + 1;
    }

    return $events;
}

function utlToInput(array $events): array
{
    return array_map(fn ($e) => [
        'date' => utlDate($e['day']), 'shift_number' => $e['shift_number'], 'shift_type' => $e['shift_type'],
        'rest_policy' => $e['rest_policy'], 'assignment_id' => $e['assignment_id'],
    ], $events);
}

function utlEndOf(?array $window): ?string
{
    return $window === null ? null : $window['end']->toDateString();
}

it('matches a naive replay over a history long enough to cross several checkpoints', function (int $seed) {
    $rules = ['night' => [3, 2], 'day' => [4, 1]];
    $events = utlRandomHistory($seed, 70);
    expect(count($events))->toBeGreaterThan(16 * 4);
    $t = new StreakTimeline(utlRules([['night', 3, 2], ['day', 4, 1]]), [1 => utlToInput($events)]);

    [, $finalWindows, $covered] = utlNaive($rules, $events);
    expect($covered)->not->toBe([]);
    $first = $events[0]['day'];
    $last = end($events)['day'];

    // isResting / activeWindow use the state after every event before the day.
    for ($day = $first - 1; $day <= $last + 3; $day++) {
        [, $windows] = utlNaive($rules, $events, $day);
        $end = utlNaiveLongestEnd($windows, $day);
        expect($t->isResting(1, utlDay(utlDate($day))))->toBe($end !== null, "isResting on day $day");
        expect(utlEndOf($t->activeWindow(1, utlDay(utlDate($day)))))->toBe($end === null ? null : utlDate($end), "activeWindow on day $day");
        $finalEnd = utlNaiveLongestEnd($finalWindows, $day);
        expect(utlEndOf($t->finalWindowAt(1, utlDay(utlDate($day)))))->toBe($finalEnd === null ? null : utlDate($finalEnd), "finalWindowAt on day $day");
    }

    // restingAssignments / uncoveredWithPolicy read the full replay.
    $resting = [];
    $stale = [];
    foreach ($events as $pos => $e) {
        if (isset($covered[$pos])) {
            $resting[$e['assignment_id']] = utlDate(utlNaiveLongestEnd($finalWindows, $e['day']));
        } elseif ($e['rest_policy'] !== null) {
            $stale[] = $e['assignment_id'];
        }
    }
    expect($t->restingAssignments(1))->toBe($resting);
    expect($t->uncoveredWithPolicy(1))->toBe($stale);

    // statusAsOf at a few days, with windowsFrom defaulting to the day.
    foreach ([$first + 5, $first + 20, $first + 40, $last + 1] as $day) {
        [$counts, $windows] = utlNaive($rules, $events, $day);
        $status = $t->statusAsOf(1, utlDay(utlDate($day)));
        foreach (['night', 'day'] as $i => $type) {
            $until = null;
            foreach ($windows as $w) {
                if ($w['type'] === $type && $w['end'] >= $day && ($until === null || $w['end'] > $until)) {
                    $until = $w['end'];
                }
            }
            expect($status[$i])->toBe([
                'shift_type' => $type, 'current_count' => $counts[$type] ?? 0, 'streak_length' => $rules[$type][0],
                'resting_until' => $until === null ? null : utlDate($until),
            ], "status for $type as of day $day");
        }
    }
})->with(['seed 1' => [20261020], 'seed 2' => [7], 'seed 3' => [99]]);

it('matches a naive replay for newlyResting and wouldConflict over a long history', function (int $seed) {
    $rules = ['night' => [3, 2], 'day' => [4, 1]];
    $events = utlRandomHistory($seed, 70);
    $t = new StreakTimeline(utlRules([['night', 3, 2], ['day', 4, 1]]), [1 => utlToInput($events)]);
    [, , $baseCovered] = utlNaive($rules, $events);

    mt_srand($seed + 1);
    $first = $events[0]['day'];
    $last = end($events)['day'];
    $reported = 0;
    for ($n = 0; $n < 40; $n++) {
        $day = mt_rand($first - 2, $last + 1);
        $slot = mt_rand(1, 3);
        $type = ['night', 'day', 'other'][mt_rand(0, 2)];
        $policy = mt_rand(0, 1) ? StreakTimeline::POLICIES[mt_rand(0, 2)] : null;
        $label = "candidate $type on day $day slot $slot policy ".($policy ?? 'null');

        // Insert the candidate after every event sorting before or equal to it and replay everything.
        $with = $events;
        $insertAt = count($with);
        foreach ($with as $i => $e) {
            if (([$e['day'], $e['shift_number']] <=> [$day, $slot]) > 0) {
                $insertAt = $i;
                break;
            }
        }
        array_splice($with, $insertAt, 0, [['day' => $day, 'shift_number' => $slot, 'shift_type' => $type, 'rest_policy' => $policy, 'assignment_id' => null]]);
        [, $windows, $covered] = utlNaive($rules, $with);
        $expected = [];
        foreach ($covered as $pos => $_) {
            if ($pos === $insertAt) {
                continue;
            }
            $orig = $pos < $insertAt ? $pos : $pos - 1;
            if (! isset($baseCovered[$orig])) {
                $expected[] = [$events[$orig]['assignment_id'], utlDate(utlNaiveLongestEnd($windows, $events[$orig]['day']))];
            }
        }
        $reported += count($expected);

        $actual = array_map(fn ($a) => [$a['assignment_id'], $a['resting_until']], $t->newlyResting(1, $type, utlDay(utlDate($day)), $slot, $policy));
        expect($actual)->toBe($expected, $label);

        [, $asOf] = utlNaive($rules, $events, $day);
        $conflict = utlNaiveLongestEnd($asOf, $day) !== null || $t->newlyResting(1, $type, utlDay(utlDate($day)), $slot) !== [];
        expect($t->wouldConflict(1, $type, utlDay(utlDate($day)), $slot))->toBe($conflict, $label);
    }
    expect($reported)->toBeGreaterThan(0);
})->with(['seed 1' => [20261020], 'seed 2' => [7], 'seed 3' => [99]]);

it('matches a naive replay after appending into the middle of a long cached history', function () {
    $rules = ['night' => [3, 2], 'day' => [4, 1]];
    $events = utlRandomHistory(20261020, 50);
    $t = new StreakTimeline(utlRules([['night', 3, 2], ['day', 4, 1]]), [1 => utlToInput($events)]);
    expect($t->restingAssignments(1))->not->toBe([]); // caches the baseline

    $day = $events[20]['day'];
    $t->append(1, 'night', utlDay(utlDate($day)), 3, StreakTimeline::COUNTS, 999);
    $events[] = ['day' => $day, 'shift_number' => 3, 'shift_type' => 'night', 'rest_policy' => StreakTimeline::COUNTS, 'assignment_id' => 999];
    usort($events, fn ($a, $b) => [$a['day'], $a['shift_number']] <=> [$b['day'], $b['shift_number']]);

    [, $windows, $covered] = utlNaive($rules, $events);
    $resting = [];
    foreach ($covered as $pos => $_) {
        $resting[$events[$pos]['assignment_id']] = utlDate(utlNaiveLongestEnd($windows, $events[$pos]['day']));
    }
    expect($t->restingAssignments(1))->toBe($resting);
    for ($d = $events[0]['day']; $d <= end($events)['day'] + 2; $d++) {
        [, $asOf] = utlNaive($rules, $events, $d);
        expect($t->isResting(1, utlDay(utlDate($d))))->toBe(utlNaiveLongestEnd($asOf, $d) !== null, "isResting on day $d");
    }
});
