<?php

// backend/tests/Unit/Support/BusinessDateTest.php

use App\Models\Shift;
use App\Support\BusinessDate;
use Carbon\Carbon;

beforeEach(function () {
    config(['app.business_timezone' => 'Europe/Warsaw']);
});

afterEach(function () {
    Carbon::setTestNow();
});

function ubdShift(string $date, ?string $start, ?string $end): Shift
{
    return new Shift(['date' => $date, 'start_time' => $start, 'end_time' => $end]);
}

// ---------------------------------------------------------------- timezone / today

it('reads the business timezone from config, defaulting to Warsaw', function () {
    expect(BusinessDate::timezone())->toBe('Europe/Warsaw');

    config(['app.business_timezone' => 'America/New_York']);
    expect(BusinessDate::timezone())->toBe('America/New_York');
});

it('gives today in the business timezone, a day ahead of UTC late in the evening', function () {
    // 23:30 UTC on the 15th is 01:30 CEST on the 16th.
    Carbon::setTestNow('2026-07-15 23:30:00');

    expect(BusinessDate::now()->tzName)->toBe('Europe/Warsaw');
    expect(BusinessDate::now()->format('Y-m-d H:i'))->toBe('2026-07-16 01:30');
    expect(BusinessDate::today()->format('Y-m-d H:i:s'))->toBe('2026-07-16 00:00:00');
    expect(BusinessDate::todayString())->toBe('2026-07-16');
    expect(Carbon::today()->toDateString())->toBe('2026-07-15');
});

// ---------------------------------------------------------------- endsNextDay

it('rolls the end over to the next day only when both times are set and the end is not after the start', function (?string $start, ?string $end, bool $expected) {
    expect(BusinessDate::endsNextDay($start, $end))->toBe($expected);
})->with([
    'night shift' => ['22:00', '06:00', true],
    'day shift' => ['06:00', '14:00', false],
    'equal times (24 h shift)' => ['00:00', '00:00', true],
    'equal times at noon' => ['12:00', '12:00', true],
    'one minute later' => ['12:00', '12:01', false],
    'one minute earlier' => ['12:01', '12:00', true],
    'with seconds' => ['22:00:00', '06:00:00', true],
    'day shift with seconds' => ['06:00:00', '14:00:00', false],
    // Only HH:MM is compared: the seconds never decide.
    'seconds ignored' => ['06:00:00', '06:00:30', true],
    'empty start' => ['', '06:00', false],
    'empty end' => ['22:00', '', false],
    'both empty' => ['', '', false],
    'null start' => [null, '06:00', false],
    'null end' => ['22:00', null, false],
    'both null' => [null, null, false],
]);

// ---------------------------------------------------------------- shiftStart / shiftEnd

it('places the start on the shift date at its start time in Warsaw', function () {
    expect(BusinessDate::shiftStart(ubdShift('2026-07-15', '06:00', '14:00'))->toIso8601String())->toBe('2026-07-15T06:00:00+02:00');
    expect(BusinessDate::shiftStart(ubdShift('2026-01-15', '06:00:00', '14:00:00'))->toIso8601String())->toBe('2026-01-15T06:00:00+01:00');
    expect(BusinessDate::shiftStart(ubdShift('2026-07-15', '06:00', '14:00'))->tzName)->toBe('Europe/Warsaw');
});

it('starts at midnight when the shift has no start time', function () {
    expect(BusinessDate::shiftStart(ubdShift('2026-07-15', null, '14:00'))->toIso8601String())->toBe('2026-07-15T00:00:00+02:00');
    expect(BusinessDate::shiftStart(ubdShift('2026-07-15', '', null))->toIso8601String())->toBe('2026-07-15T00:00:00+02:00');
});

it('ends a day shift on its own date and an overnight shift on the next', function () {
    expect(BusinessDate::shiftEnd(ubdShift('2026-07-15', '06:00', '14:00'))->toIso8601String())->toBe('2026-07-15T14:00:00+02:00');
    expect(BusinessDate::shiftEnd(ubdShift('2026-07-15', '22:00', '06:00'))->toIso8601String())->toBe('2026-07-16T06:00:00+02:00');
    expect(BusinessDate::shiftEnd(ubdShift('2026-07-15', '00:00', '00:00'))->toIso8601String())->toBe('2026-07-16T00:00:00+02:00');
    expect(BusinessDate::shiftEnd(ubdShift('2026-07-31', '22:00', '06:00'))->toIso8601String())->toBe('2026-08-01T06:00:00+02:00');
});

it('ends at the start of the next day when the shift has no end time', function () {
    expect(BusinessDate::shiftEnd(ubdShift('2026-07-15', '06:00', null))->toIso8601String())->toBe('2026-07-16T00:00:00+02:00');
    expect(BusinessDate::shiftEnd(ubdShift('2026-07-15', null, ''))->toIso8601String())->toBe('2026-07-16T00:00:00+02:00');
    expect(BusinessDate::shiftEnd(ubdShift('2026-12-31', null, null))->toIso8601String())->toBe('2027-01-01T00:00:00+01:00');
});

it('keeps 06:00 as 06:00 on the DST change days, so the night is 7 or 9 hours long', function () {
    $spring = ubdShift('2026-03-28', '22:00', '06:00');
    expect(BusinessDate::shiftStart($spring)->toIso8601String())->toBe('2026-03-28T22:00:00+01:00');
    expect(BusinessDate::shiftEnd($spring)->toIso8601String())->toBe('2026-03-29T06:00:00+02:00');
    expect(BusinessDate::shiftStart($spring)->diffInHours(BusinessDate::shiftEnd($spring)))->toEqual(7);

    $autumn = ubdShift('2026-10-24', '22:00', '06:00');
    expect(BusinessDate::shiftEnd($autumn)->toIso8601String())->toBe('2026-10-25T06:00:00+01:00');
    expect(BusinessDate::shiftStart($autumn)->diffInHours(BusinessDate::shiftEnd($autumn)))->toEqual(9);

    // A shift without an end time on the DST day still ends at the next midnight.
    expect(BusinessDate::shiftEnd(ubdShift('2026-03-29', null, null))->toIso8601String())->toBe('2026-03-30T00:00:00+02:00');
});

// ---------------------------------------------------------------- localInstant

it('builds a local date and time as an instant in the business timezone', function () {
    $summer = BusinessDate::localInstant('2026-07-15', '06:00');
    expect($summer->tzName)->toBe('Europe/Warsaw');
    expect($summer->toIso8601String())->toBe('2026-07-15T06:00:00+02:00');
    expect($summer->utc()->format('H:i'))->toBe('04:00');

    expect(BusinessDate::localInstant('2026-01-15', '06:00')->toIso8601String())->toBe('2026-01-15T06:00:00+01:00');
    expect(BusinessDate::localInstant('2026-01-15', '06:30:45')->toIso8601String())->toBe('2026-01-15T06:30:00+01:00');
    expect(BusinessDate::localInstant('2026-01-15')->toIso8601String())->toBe('2026-01-15T00:00:00+01:00');
    expect(BusinessDate::localInstant('2026-01-15', null)->toIso8601String())->toBe('2026-01-15T00:00:00+01:00');
});

it('resolves times that do not exist or repeat on the DST days deterministically', function () {
    // 02:30 does not exist on the spring-forward day: it rolls forward to 03:30 CEST.
    expect(BusinessDate::localInstant('2026-03-29', '02:30')->toIso8601String())->toBe('2026-03-29T03:30:00+02:00');
    // 02:30 happens twice on the fall-back day: it is taken as the second one, in CET.
    expect(BusinessDate::localInstant('2026-10-25', '02:30')->toIso8601String())->toBe('2026-10-25T02:30:00+01:00');
});

it('follows a different business timezone', function () {
    config(['app.business_timezone' => 'America/New_York']);

    expect(BusinessDate::localInstant('2026-07-15', '06:00')->toIso8601String())->toBe('2026-07-15T06:00:00-04:00');
    expect(BusinessDate::shiftEnd(ubdShift('2026-07-15', '22:00', '06:00'))->toIso8601String())->toBe('2026-07-16T06:00:00-04:00');
});
