<?php

// backend/tests/Unit/SettlementPeriodCalculatorTest.php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Services\SettlementPeriodCalculator;
use Carbon\Carbon;

beforeEach(function () {
    config(['app.business_timezone' => 'Europe/Warsaw']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** An unsaved group: the calculator only reads its settlement columns. */
function uspGroup(?string $mode, ?string $anchor, ?float $cap = 40.0, ?int $customLength = null): ShiftGroup
{
    return new ShiftGroup([
        'name' => 'Unit',
        'sort_order' => 0,
        'settlement_mode' => $mode,
        'settlement_anchor_date' => $anchor,
        'settlement_custom_length_days' => $customLength,
        'weekly_hour_cap' => $cap,
    ]);
}

function uspDates(array $period): array
{
    return [$period['period_number'], $period['start']->toDateString(), $period['end']->toDateString()];
}

function uspWeeks(array $period): array
{
    return array_map(fn ($w) => [$w['start']->toDateString(), $w['end']->toDateString(), $w['cap_hours']], $period['weeks']);
}

// ---------------------------------------------------------------- periodContaining

it('anchors monthly periods to the anchor day, before and after the anchor', function (string $date, array $expected) {
    $period = (new SettlementPeriodCalculator)->periodContaining(uspGroup('monthly', '2026-02-15'), Carbon::parse($date));

    expect(uspDates($period))->toBe($expected);
})->with([
    'anchor day' => ['2026-02-15', [0, '2026-02-15', '2026-03-14']],
    'inside period 0' => ['2026-03-01 13:45', [0, '2026-02-15', '2026-03-14']],
    'last day of period 0' => ['2026-03-14', [0, '2026-02-15', '2026-03-14']],
    'first day of period 1' => ['2026-03-15', [1, '2026-03-15', '2026-04-14']],
    'day before the anchor' => ['2026-02-14', [-1, '2026-01-15', '2026-02-14']],
    'a year earlier' => ['2025-02-20', [-12, '2025-02-15', '2025-03-14']],
    'far later' => ['2027-01-01', [10, '2026-12-15', '2027-01-14']],
]);

it('clamps a 31st anchor to the last day of shorter months without leaving gaps', function () {
    // Months are added without overflow: period 0 runs 01-31..02-27 and period 1 starts on 02-28,
    // so Feb 28 belongs to the next period and no day is skipped or counted twice.
    $calc = new SettlementPeriodCalculator;
    $group = uspGroup('monthly', '2026-01-31');

    expect(uspDates($calc->periodContaining($group, Carbon::parse('2026-02-10'))))->toBe([0, '2026-01-31', '2026-02-27']);
    expect(uspDates($calc->periodContaining($group, Carbon::parse('2026-02-28'))))->toBe([1, '2026-02-28', '2026-03-30']);
    expect(uspDates($calc->periodContaining($group, Carbon::parse('2026-03-31'))))->toBe([2, '2026-03-31', '2026-04-29']);
});

it('builds quarterly periods of three months, with negative numbers before the anchor', function (string $date, array $expected) {
    $period = (new SettlementPeriodCalculator)->periodContaining(uspGroup('quarterly', '2026-01-01'), Carbon::parse($date));

    expect(uspDates($period))->toBe($expected);
})->with([
    'first quarter' => ['2026-02-10', [0, '2026-01-01', '2026-03-31']],
    'second quarter' => ['2026-05-10', [1, '2026-04-01', '2026-06-30']],
    'start of the second quarter' => ['2026-04-01', [1, '2026-04-01', '2026-06-30']],
    'day before the anchor' => ['2025-12-31', [-1, '2025-10-01', '2025-12-31']],
    'start of the previous quarter' => ['2025-10-01', [-1, '2025-10-01', '2025-12-31']],
    'two quarters back' => ['2025-09-30', [-2, '2025-07-01', '2025-09-30']],
]);

it('builds 7-day weekly periods from the anchor, before and after it', function (string $date, array $expected) {
    $period = (new SettlementPeriodCalculator)->periodContaining(uspGroup('weekly', '2026-09-01'), Carbon::parse($date));

    expect(uspDates($period))->toBe($expected);
})->with([
    'anchor week' => ['2026-09-05', [0, '2026-09-01', '2026-09-07']],
    'last day of anchor week' => ['2026-09-07', [0, '2026-09-01', '2026-09-07']],
    'next week' => ['2026-09-08', [1, '2026-09-08', '2026-09-14']],
    'day before the anchor' => ['2026-08-31', [-1, '2026-08-25', '2026-08-31']],
    'first day of the week before' => ['2026-08-25', [-1, '2026-08-25', '2026-08-31']],
    'two weeks before' => ['2026-08-24', [-2, '2026-08-18', '2026-08-24']],
]);

it('builds custom periods of the configured length, before and after the anchor', function (string $date, array $expected) {
    $period = (new SettlementPeriodCalculator)->periodContaining(uspGroup('custom', '2026-01-01', 40.0, 10), Carbon::parse($date));

    expect(uspDates($period))->toBe($expected);
})->with([
    'first day' => ['2026-01-01', [0, '2026-01-01', '2026-01-10']],
    'last day of period 0' => ['2026-01-10', [0, '2026-01-01', '2026-01-10']],
    'first day of period 1' => ['2026-01-11', [1, '2026-01-11', '2026-01-20']],
    'day before the anchor' => ['2025-12-31', [-1, '2025-12-22', '2025-12-31']],
    'first day of period -1' => ['2025-12-22', [-1, '2025-12-22', '2025-12-31']],
    'last day of period -2' => ['2025-12-21', [-2, '2025-12-12', '2025-12-21']],
]);

it('refuses custom mode without a period length', function () {
    expect(fn () => (new SettlementPeriodCalculator)->periodContaining(uspGroup('custom', '2026-01-01'), Carbon::parse('2026-01-05')))
        ->toThrow(InvalidArgumentException::class, 'Custom settlement mode requires settlement_custom_length_days.');
});

it('defaults to monthly mode and a 48 h weekly cap when the group has none configured', function () {
    $period = (new SettlementPeriodCalculator)->periodContaining(uspGroup(null, '2026-03-01', null), Carbon::parse('2026-03-20'));

    expect(uspDates($period))->toBe([0, '2026-03-01', '2026-03-31']);
    expect($period['weeks'][0]['cap_hours'])->toBe(48.0);
    // 31 days: three days left over in the fifth week.
    expect(end($period['weeks'])['cap_hours'])->toBe(round(48 * 3 / 7, 2));
});

it('falls back to the group creation date as the anchor', function () {
    $group = uspGroup('weekly', null);
    $group->created_at = Carbon::parse('2026-03-10 15:20:00');

    $period = (new SettlementPeriodCalculator)->periodContaining($group, Carbon::parse('2026-03-12'));

    expect(uspDates($period))->toBe([0, '2026-03-10', '2026-03-16']);
});

it('falls back to today\'s business date as the anchor when the group has no dates at all', function () {
    // 23:30 UTC on the 15th is already 01:30 on the 16th in Warsaw: the anchor is the 16th.
    Carbon::setTestNow('2026-07-15 23:30:00');

    $period = (new SettlementPeriodCalculator)->periodContaining(uspGroup('weekly', null), Carbon::parse('2026-07-16'));

    expect(uspDates($period))->toBe([0, '2026-07-16', '2026-07-22']);
});

// ---------------------------------------------------------------- weeks

it('splits a period into weeks and prorates the cap of a partial last week', function () {
    // March 2026 has 31 days: four full weeks and a 3-day tail at 3/7 of the cap.
    $period = (new SettlementPeriodCalculator)->periodContaining(uspGroup('monthly', '2026-03-01', 40.0), Carbon::parse('2026-03-10'));

    expect(uspWeeks($period))->toBe([
        ['2026-03-01', '2026-03-07', 40.0],
        ['2026-03-08', '2026-03-14', 40.0],
        ['2026-03-15', '2026-03-21', 40.0],
        ['2026-03-22', '2026-03-28', 40.0],
        ['2026-03-29', '2026-03-31', 17.14],
    ]);
});

it('gives exactly four full weeks for a 28-day period', function () {
    $period = (new SettlementPeriodCalculator)->periodContaining(uspGroup('monthly', '2026-02-01', 42.0), Carbon::parse('2026-02-14'));

    expect(uspWeeks($period))->toHaveCount(4);
    expect(array_unique(array_column($period['weeks'], 'cap_hours')))->toBe([42.0]);
    expect($period['weeks'][3]['end']->toDateString())->toBe('2026-02-28');
});

it('prorates a custom period shorter than a week into a single partial week', function () {
    $period = (new SettlementPeriodCalculator)->periodContaining(uspGroup('custom', '2026-01-01', 35.0, 3), Carbon::parse('2026-01-02'));

    expect(uspWeeks($period))->toBe([['2026-01-01', '2026-01-03', 15.0]]);
});

it('keeps week lengths right across the DST change days', function () {
    // The spring-forward Sunday (2026-03-29) sits inside the week: still 7 calendar days, full cap.
    $period = (new SettlementPeriodCalculator)->periodContaining(uspGroup('weekly', '2026-03-24', 40.0), Carbon::parse('2026-03-29'));

    expect(uspWeeks($period))->toBe([['2026-03-24', '2026-03-30', 40.0]]);
});

// ---------------------------------------------------------------- weekFor

it('finds the week of a date inside the period, with its index', function () {
    $calc = new SettlementPeriodCalculator;
    $period = $calc->periodContaining(uspGroup('monthly', '2026-03-01'), Carbon::parse('2026-03-10'));

    $hit = $calc->weekFor($period, Carbon::parse('2026-03-16 09:00'));
    expect($hit['index'])->toBe(2);
    expect($hit['week']['start']->toDateString())->toBe('2026-03-15');
    expect($hit['week']['end']->toDateString())->toBe('2026-03-21');

    expect($calc->weekFor($period, Carbon::parse('2026-03-01'))['index'])->toBe(0);
    expect($calc->weekFor($period, Carbon::parse('2026-03-31 23:59:59'))['index'])->toBe(4);
});

it('refuses a date outside the period', function () {
    $calc = new SettlementPeriodCalculator;
    $period = $calc->periodContaining(uspGroup('monthly', '2026-03-01'), Carbon::parse('2026-03-10'));

    expect(fn () => $calc->weekFor($period, Carbon::parse('2026-04-01')))
        ->toThrow(InvalidArgumentException::class, 'Date does not fall within the given period.');
    expect(fn () => $calc->weekFor($period, Carbon::parse('2026-02-28')))
        ->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------- shiftTiming

it('measures a shift in Warsaw wall-clock time', function (?string $start, ?string $end, string $expectedStart, string $expectedEnd, float $hours) {
    $shift = new Shift(['date' => '2026-09-01', 'start_time' => $start, 'end_time' => $end]);

    $timing = (new SettlementPeriodCalculator)->shiftTiming($shift);

    expect($timing['start']->toIso8601String())->toBe($expectedStart);
    expect($timing['end']->toIso8601String())->toBe($expectedEnd);
    expect($timing['start']->tzName)->toBe('Europe/Warsaw');
    expect($timing['duration_hours'])->toEqual($hours);
})->with([
    'day shift' => ['06:00', '14:00', '2026-09-01T06:00:00+02:00', '2026-09-01T14:00:00+02:00', 8.0],
    'overnight 22:00-06:00' => ['22:00', '06:00', '2026-09-01T22:00:00+02:00', '2026-09-02T06:00:00+02:00', 8.0],
    '24 h shift 00:00-00:00' => ['00:00', '00:00', '2026-09-01T00:00:00+02:00', '2026-09-02T00:00:00+02:00', 24.0],
    'half hours kept' => ['06:00', '13:30', '2026-09-01T06:00:00+02:00', '2026-09-01T13:30:00+02:00', 7.5],
    'times with seconds' => ['06:00:00', '14:00:00', '2026-09-01T06:00:00+02:00', '2026-09-01T14:00:00+02:00', 8.0],
    'no times: 06:00-14:00' => [null, null, '2026-09-01T06:00:00+02:00', '2026-09-01T14:00:00+02:00', 8.0],
    'no end time: until 14:00' => ['08:00', null, '2026-09-01T08:00:00+02:00', '2026-09-01T14:00:00+02:00', 6.0],
    'no start time: from 06:00' => [null, '10:00', '2026-09-01T06:00:00+02:00', '2026-09-01T10:00:00+02:00', 4.0],
    'empty strings fall back too' => ['', '', '2026-09-01T06:00:00+02:00', '2026-09-01T14:00:00+02:00', 8.0],
]);

it('measures elapsed time across the DST changes', function (string $date, string $start, string $end, float $hours) {
    $shift = new Shift(['date' => $date, 'start_time' => $start, 'end_time' => $end]);

    expect((new SettlementPeriodCalculator)->shiftTiming($shift)['duration_hours'])->toEqual($hours);
})->with([
    // Clocks go forward at 02:00 on 2026-03-29 and back at 03:00 on 2026-10-25.
    'spring-forward night is 7 h' => ['2026-03-28', '22:00', '06:00', 7.0],
    'fall-back night is 9 h' => ['2026-10-24', '22:00', '06:00', 9.0],
    'spring-forward 24 h shift is 23 h' => ['2026-03-29', '00:00', '00:00', 23.0],
    'fall-back 24 h shift is 25 h' => ['2026-10-25', '00:00', '00:00', 25.0],
    'ordinary day shift on the DST day' => ['2026-03-29', '06:00', '14:00', 8.0],
]);

it('accepts a Carbon date on the shift', function () {
    $shift = new Shift(['date' => Carbon::parse('2026-09-01'), 'start_time' => '22:00', 'end_time' => '02:00']);

    $timing = (new SettlementPeriodCalculator)->shiftTiming($shift);

    expect($timing['end']->format('Y-m-d H:i'))->toBe('2026-09-02 02:00');
    expect($timing['duration_hours'])->toEqual(4.0);
});
