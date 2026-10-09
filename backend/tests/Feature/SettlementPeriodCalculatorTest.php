<?php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Services\SettlementPeriodCalculator;
use Carbon\Carbon;

function makeGroup(string $mode, string $anchor, float $cap = 40.0, ?int $customLength = null): ShiftGroup
{
    return ShiftGroup::create([
        'name' => 'Test Group',
        'sort_order' => 0,
        'settlement_mode' => $mode,
        'settlement_anchor_date' => $anchor,
        'settlement_custom_length_days' => $customLength,
        'weekly_hour_cap' => $cap,
    ]);
}

it('computes a 7-day weekly period containing a date, with one full-cap week', function () {
    $group = makeGroup('weekly', '2026-09-01'); // a Tuesday
    $calc = new SettlementPeriodCalculator();

    $period = $calc->periodContaining($group, Carbon::parse('2026-09-05'));

    expect($period['start']->toDateString())->toBe('2026-09-01');
    expect($period['end']->toDateString())->toBe('2026-09-07');
    expect($period['weeks'])->toHaveCount(1);
    expect($period['weeks'][0]['cap_hours'])->toBe(40.0);
});

it('computes weekly periods before and after the anchor via offset and by-date lookup consistently', function () {
    // periodByOffset is defined relative to the REAL current date, so freeze
    // "today" to a known date inside the anchor's first week (period 0) —
    // otherwise this test's expected dates would only hold on one specific
    // real-world day. Reset afterward so it doesn't leak into other tests.
    Carbon::setTestNow(Carbon::parse('2026-09-03'));

    $group = makeGroup('weekly', '2026-09-01');
    $calc = new SettlementPeriodCalculator();

    $viaOffset = $calc->periodByOffset($group, -1);
    $viaDate = $calc->periodContaining($group, Carbon::parse('2026-08-27'));

    expect($viaOffset['start']->toDateString())->toBe($viaDate['start']->toDateString());
    expect($viaOffset['end']->toDateString())->toBe($viaDate['end']->toDateString());
    expect($viaOffset['start']->toDateString())->toBe('2026-08-25');
    expect($viaOffset['end']->toDateString())->toBe('2026-08-31');

    Carbon::setTestNow();
});

it('anchors monthly periods to the anchor date, not calendar month boundaries', function () {
    $group = makeGroup('monthly', '2026-02-15');
    $calc = new SettlementPeriodCalculator();

    $period = $calc->periodContaining($group, Carbon::parse('2026-03-01'));

    expect($period['start']->toDateString())->toBe('2026-02-15');
    expect($period['end']->toDateString())->toBe('2026-03-14');
});

it('computes quarterly periods spanning 3 months from the anchor', function () {
    // Same freezing rationale as the weekly offset test above: periodByOffset
    // reads the real current date, so pin it inside the anchor's first quarter.
    Carbon::setTestNow(Carbon::parse('2026-03-15'));

    $group = makeGroup('quarterly', '2026-02-01');
    $calc = new SettlementPeriodCalculator();

    $current = $calc->periodContaining($group, Carbon::parse('2026-03-15'));
    $next = $calc->periodByOffset($group, 1);

    expect($current['start']->toDateString())->toBe('2026-02-01');
    expect($current['end']->toDateString())->toBe('2026-04-30');
    expect($next['start']->toDateString())->toBe('2026-05-01');
    expect($next['end']->toDateString())->toBe('2026-07-31');

    Carbon::setTestNow();
});

it('computes a custom-length period', function () {
    $group = makeGroup('custom', '2026-01-01', 40.0, 45);
    $calc = new SettlementPeriodCalculator();

    $period = $calc->periodContaining($group, Carbon::parse('2026-01-01'));

    expect($period['start']->toDateString())->toBe('2026-01-01');
    expect($period['end']->toDateString())->toBe('2026-02-14'); // 45 days inclusive
});

it('prorates the trailing partial week of a period not divisible by 7', function () {
    // Quarterly from 2026-02-01 to 2026-04-30 is 89 days = 12 full weeks + 5 leftover days.
    $group = makeGroup('quarterly', '2026-02-01', 40.0);
    $calc = new SettlementPeriodCalculator();

    $period = $calc->periodContaining($group, Carbon::parse('2026-02-01'));

    expect($period['weeks'])->toHaveCount(13);
    foreach (array_slice($period['weeks'], 0, 12) as $week) {
        expect($week['cap_hours'])->toBe(40.0);
    }
    $lastWeek = $period['weeks'][12];
    expect((int) $lastWeek['start']->diffInDays($lastWeek['end'], true) + 1)->toBe(5);
    expect($lastWeek['cap_hours'])->toBe(round(40.0 * 5 / 7, 2));
});

it('finds which week a date falls into within a period', function () {
    $group = makeGroup('quarterly', '2026-02-01', 40.0);
    $calc = new SettlementPeriodCalculator();
    $period = $calc->periodContaining($group, Carbon::parse('2026-02-01'));

    $match = $calc->weekFor($period, Carbon::parse('2026-02-10'));

    expect($match['index'])->toBe(1); // second week: Feb 8-14
    expect($match['week']['start']->toDateString())->toBe('2026-02-08');
});

it('falls back to monthly/48h defaults when a group has no settlement config at all', function () {
    // The migration's backfill only ever ran once against rows that existed at
    // that moment — a freshly created group with no settlement fields supplied
    // naturally has them null (the columns carry no DB-level default), exercising
    // the calculator's own fallback rather than the migration's backfill.
    $group = ShiftGroup::create(['name' => 'Unconfigured', 'sort_order' => 0]);

    $calc = new SettlementPeriodCalculator();
    $period = $calc->periodContaining($group, Carbon::today());

    expect($period['weeks'][0]['cap_hours'])->toBeGreaterThan(0.0);
    expect($period['weeks'][0]['cap_hours'])->toBeLessThanOrEqual(48.0);
});

it('keeps the half hour of a shift that does not last whole hours', function (string $start, string $end, float $hours) {
    $shift = new Shift(['date' => '2026-09-01', 'start_time' => $start, 'end_time' => $end]);

    // toEqual: an exact division (480 / 60) is the int 8, as before the upgrade.
    expect((new SettlementPeriodCalculator())->shiftTiming($shift)['duration_hours'])->toEqual($hours);
})->with([
    'day shift 06:00-13:30' => ['06:00', '13:30', 7.5],
    'overnight 22:00-05:30' => ['22:00', '05:30', 7.5],
    'whole hours 06:00-14:00' => ['06:00', '14:00', 8.0],
]);
