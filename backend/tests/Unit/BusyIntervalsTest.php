<?php

// backend/tests/Unit/BusyIntervalsTest.php

use App\Models\Shift;
use App\Services\BusyIntervals;
use Carbon\Carbon;

const UBI_H = 3600;

beforeEach(function () {
    config(['app.business_timezone' => 'Europe/Warsaw']);
});

/** @param list<array{0: int, 1: int}> $intervals in hours */
function ubiWith(array $intervals, int $userId = 1): BusyIntervals
{
    $busy = new BusyIntervals;
    foreach ($intervals as [$start, $end]) {
        $busy->add($userId, $start * UBI_H, $end * UBI_H);
    }

    return $busy;
}

// ---------------------------------------------------------------- allowsDailyRest

it('allows anything for a user with no busy time', function () {
    $busy = ubiWith([[0, 8]], 2);

    expect($busy->allowsDailyRest(1, 0, 8 * UBI_H))->toBeTrue();
});

it('requires exactly 11 hours after the previous interval', function () {
    $busy = ubiWith([[0, 8]]);

    expect(BusyIntervals::MIN_DAILY_REST_HOURS)->toBe(11);
    expect($busy->allowsDailyRest(1, 19 * UBI_H, 27 * UBI_H))->toBeTrue();           // 11 h 00
    expect($busy->allowsDailyRest(1, 19 * UBI_H - 60, 27 * UBI_H))->toBeFalse();     // 10 h 59
    expect($busy->allowsDailyRest(1, 19 * UBI_H - 1, 27 * UBI_H))->toBeFalse();      // one second short
    expect($busy->allowsDailyRest(1, 30 * UBI_H, 38 * UBI_H))->toBeTrue();
});

it('requires exactly 11 hours before the next interval', function () {
    $busy = ubiWith([[100, 108]]);

    expect($busy->allowsDailyRest(1, 81 * UBI_H, 89 * UBI_H))->toBeTrue();           // ends 11 h before
    expect($busy->allowsDailyRest(1, 81 * UBI_H, 89 * UBI_H + 60))->toBeFalse();     // 10 h 59
    expect($busy->allowsDailyRest(1, 70 * UBI_H, 78 * UBI_H))->toBeTrue();
});

it('refuses an overlap or a touching interval', function () {
    $busy = ubiWith([[10, 18]]);

    expect($busy->allowsDailyRest(1, 14 * UBI_H, 22 * UBI_H))->toBeFalse();   // overlaps the end
    expect($busy->allowsDailyRest(1, 6 * UBI_H, 12 * UBI_H))->toBeFalse();    // overlaps the start
    expect($busy->allowsDailyRest(1, 11 * UBI_H, 13 * UBI_H))->toBeFalse();   // inside
    expect($busy->allowsDailyRest(1, 0, 30 * UBI_H))->toBeFalse();            // contains it
    expect($busy->allowsDailyRest(1, 18 * UBI_H, 20 * UBI_H))->toBeFalse();   // back to back
    expect($busy->allowsDailyRest(1, 2 * UBI_H, 10 * UBI_H))->toBeFalse();    // back to back, before
});

it('checks both neighbours when the candidate sits between two intervals', function () {
    $busy = ubiWith([[0, 8], [38, 46]]);

    expect($busy->allowsDailyRest(1, 19 * UBI_H, 27 * UBI_H))->toBeTrue();            // 11 h on both sides
    expect($busy->allowsDailyRest(1, 19 * UBI_H, 27 * UBI_H + 1))->toBeFalse();       // too close to the next
    expect($busy->allowsDailyRest(1, 19 * UBI_H - 1, 27 * UBI_H))->toBeFalse();       // too close to the previous
});

it('keeps intervals sorted whatever the insertion order', function () {
    $busy = new BusyIntervals;
    $busy->add(1, 48 * UBI_H, 56 * UBI_H);
    $busy->add(1, 0, 8 * UBI_H);
    $busy->add(1, 24 * UBI_H, 32 * UBI_H);

    expect($busy->allowsDailyRest(1, 43 * UBI_H, 45 * UBI_H))->toBeFalse();  // 11 h after 32 but only 3 h before 48
    expect($busy->allowsDailyRest(1, 12 * UBI_H, 13 * UBI_H))->toBeFalse();  // 4 h after 8
    expect($busy->longestRestWith(1, 0, 72 * UBI_H, 60 * UBI_H, 61 * UBI_H))->toBe(16 * UBI_H);
});

it('finds a conflict with an earlier long interval hidden behind a shorter one', function () {
    // Sorted by start: [0, 100 h] then [10 h, 12 h]. Only the long interval overlaps the candidate,
    // and the scan reaches it because the longest interval seen bounds how far back to look.
    $busy = ubiWith([[0, 100], [10, 12]]);

    expect($busy->allowsDailyRest(1, 30 * UBI_H, 38 * UBI_H))->toBeFalse();
    expect($busy->allowsDailyRest(1, 111 * UBI_H, 119 * UBI_H))->toBeTrue();
    expect($busy->allowsDailyRest(1, 110 * UBI_H, 119 * UBI_H))->toBeFalse();
});

it('answers right over many intervals, where the binary search and the early exit matter', function () {
    // 8 h shifts every 48 h for a year, plus one 30 h stretch early on.
    $busy = new BusyIntervals;
    for ($i = 0; $i < 200; $i++) {
        $busy->add(1, $i * 48 * UBI_H, ($i * 48 + 8) * UBI_H);
    }
    $busy->add(1, 500 * UBI_H, 530 * UBI_H);

    for ($k = 20; $k < 200; $k += 37) {
        $base = $k * 48;
        expect($busy->allowsDailyRest(1, ($base + 19) * UBI_H, ($base + 27) * UBI_H))->toBeTrue();       // 11 h after, 21 h before
        expect($busy->allowsDailyRest(1, ($base + 19) * UBI_H, ($base + 37) * UBI_H + 1))->toBeFalse();  // 10 h 59 m 59 s before the next
        expect($busy->allowsDailyRest(1, ($base + 18) * UBI_H, ($base + 27) * UBI_H))->toBeFalse();      // 10 h after
        expect($busy->allowsDailyRest(1, ($base + 19) * UBI_H, ($base + 37) * UBI_H))->toBeTrue();       // exactly 11 h before
    }
    // Around the long stretch (500 h..530 h, overlapping the 528 h..536 h shift; the next one starts at 576 h).
    expect($busy->allowsDailyRest(1, 510 * UBI_H, 512 * UBI_H))->toBeFalse();   // inside the stretch
    expect($busy->allowsDailyRest(1, 546 * UBI_H, 565 * UBI_H))->toBeFalse();   // 10 h after 536
    expect($busy->allowsDailyRest(1, 547 * UBI_H, 565 * UBI_H))->toBeTrue();    // 11 h after 536, 11 h before 576
    expect($busy->allowsDailyRest(1, 547 * UBI_H, 566 * UBI_H))->toBeFalse();   // 10 h before 576
});

// ---------------------------------------------------------------- longestRestWith

it('measures the longest gap counting the candidate as busy', function () {
    $busy = ubiWith([[24, 32]]);

    // Gaps: 0..24 (24 h), 32..100 (68 h), 108..168 (60 h).
    expect($busy->longestRestWith(1, 0, 168 * UBI_H, 100 * UBI_H, 108 * UBI_H))->toBe(68 * UBI_H);
    // Without other busy time only the candidate splits the window.
    expect($busy->longestRestWith(2, 0, 168 * UBI_H, 100 * UBI_H, 108 * UBI_H))->toBe(100 * UBI_H);
});

it('lets both window edges bound a stretch', function () {
    $busy = new BusyIntervals;

    expect($busy->longestRestWith(1, 0, 168 * UBI_H, 0, 8 * UBI_H))->toBe(160 * UBI_H);
    expect($busy->longestRestWith(1, 0, 168 * UBI_H, 160 * UBI_H, 168 * UBI_H))->toBe(160 * UBI_H);
    // A candidate straddling the window start trims from the window start, not from the candidate's.
    expect($busy->longestRestWith(1, 0, 168 * UBI_H, -5 * UBI_H, 3 * UBI_H))->toBe(165 * UBI_H);
    // One straddling the window end leaves the gap before it.
    expect($busy->longestRestWith(1, 0, 168 * UBI_H, 165 * UBI_H, 180 * UBI_H))->toBe(165 * UBI_H);
    // A candidate entirely outside the window leaves the whole window free.
    expect($busy->longestRestWith(1, 0, 168 * UBI_H, 170 * UBI_H, 178 * UBI_H))->toBe(168 * UBI_H);
    expect($busy->longestRestWith(1, 0, 168 * UBI_H, -20 * UBI_H, -10 * UBI_H))->toBe(168 * UBI_H);
});

it('counts an interval that starts before the window but ends inside it', function () {
    $busy = ubiWith([[-10, 5], [200, 208]]);

    // The 200 h interval is outside the window; the -10..5 one eats the first 5 hours.
    expect($busy->longestRestWith(1, 0, 168 * UBI_H, 100 * UBI_H, 101 * UBI_H))->toBe(95 * UBI_H);
});

it('merges overlapping busy intervals and reports zero rest for a fully busy window', function () {
    $busy = ubiWith([[10, 20], [15, 30]]);

    expect($busy->longestRestWith(1, 0, 40 * UBI_H, 35 * UBI_H, 36 * UBI_H))->toBe(10 * UBI_H); // 0..10
    expect($busy->longestRestWith(1, 10 * UBI_H, 30 * UBI_H, 35 * UBI_H, 36 * UBI_H))->toBe(0);
    expect($busy->longestRestWith(1, 0, 40 * UBI_H, -1, 41 * UBI_H))->toBe(0);
});

it('tells a week with and without the 35 h weekly rest apart', function () {
    // Mon-Fri 06:00-14:00; the window is Monday 00:00 .. next Monday 00:00 (hours from the window start).
    $busy = ubiWith([[6, 14], [30, 38], [54, 62], [78, 86], [102, 110]]);
    $min = BusyIntervals::MIN_WEEKLY_REST_HOURS * UBI_H;

    // A Saturday 06:00-14:00 shift leaves Sat 14:00 .. Mon 00:00 = 34 h: one hour short.
    expect($busy->longestRestWith(1, 0, 168 * UBI_H, 126 * UBI_H, 134 * UBI_H))->toBe(34 * UBI_H)->toBeLessThan($min);
    // A Sunday night 22:00-06:00 instead leaves Fri 14:00 .. Sun 22:00 = 56 h.
    expect($busy->longestRestWith(1, 0, 168 * UBI_H, 166 * UBI_H, 174 * UBI_H))->toBe(56 * UBI_H)->toBeGreaterThanOrEqual($min);
});

// ---------------------------------------------------------------- shiftSpan / wallClock

it('spans a shift from its Warsaw start to its Warsaw end as timestamps', function () {
    $shift = new Shift(['date' => '2026-09-01', 'start_time' => '22:00', 'end_time' => '06:00']);

    [$start, $end] = BusyIntervals::shiftSpan($shift);

    expect($start)->toBe(Carbon::parse('2026-09-01 22:00', 'Europe/Warsaw')->getTimestamp());
    expect($end)->toBe(Carbon::parse('2026-09-02 06:00', 'Europe/Warsaw')->getTimestamp());
    expect($end - $start)->toBe(8 * UBI_H);
    // That is 20:00 UTC, not 22:00 UTC.
    expect(gmdate('H:i', $start))->toBe('20:00');
});

it('spans a shift without times over its whole business day', function () {
    [$start, $end] = BusyIntervals::shiftSpan(new Shift(['date' => '2026-09-01']));

    expect($start)->toBe(Carbon::parse('2026-09-01 00:00', 'Europe/Warsaw')->getTimestamp());
    expect($end - $start)->toBe(24 * UBI_H);
});

it('spans a night across the DST change in elapsed time', function () {
    [$start, $end] = BusyIntervals::shiftSpan(new Shift(['date' => '2026-03-28', 'start_time' => '22:00', 'end_time' => '06:00']));
    expect($end - $start)->toBe(7 * UBI_H);

    [$start, $end] = BusyIntervals::shiftSpan(new Shift(['date' => '2026-10-24', 'start_time' => '22:00', 'end_time' => '06:00']));
    expect($end - $start)->toBe(9 * UBI_H);
});

it('reads a stored on-call time as Warsaw wall clock', function () {
    // Stored "08:00" in a UTC-typed column means 08:00 in Warsaw, i.e. 06:00 UTC in summer.
    $stored = Carbon::parse('2026-07-15 08:00:00', 'UTC');

    $local = BusyIntervals::wallClock($stored);

    expect($local->tzName)->toBe('Europe/Warsaw');
    expect($local->format('Y-m-d H:i:s'))->toBe('2026-07-15 08:00:00');
    expect($local->getTimestamp())->toBe(Carbon::parse('2026-07-15 06:00:00', 'UTC')->getTimestamp());
    // In winter the same wall clock is 07:00 UTC.
    expect(BusyIntervals::wallClock(Carbon::parse('2026-01-15 08:00:00', 'UTC'))->utc()->format('H:i'))->toBe('07:00');
});
