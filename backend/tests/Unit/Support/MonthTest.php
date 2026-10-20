<?php

// backend/tests/Unit/Support/MonthTest.php

use App\Support\Month;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

it('gives the first day of the month at midnight', function () {
    $start = Month::start('2027-02');

    expect($start->format('Y-m-d H:i:s'))->toBe('2027-02-01 00:00:00');
    expect(Month::start('2026-12')->format('Y-m-d H:i:s'))->toBe('2026-12-01 00:00:00');
    expect(Month::start('2024-02')->format('Y-m-d'))->toBe('2024-02-01');
});

it('does not overflow a short month when today is the 31st', function () {
    // createFromFormat('Y-m') would fill in today's day: on Oct 31, "2027-02" would become March 3.
    Carbon::setTestNow('2026-10-31 15:45:12');

    expect(Month::start('2027-02')->format('Y-m-d H:i:s'))->toBe('2027-02-01 00:00:00');
    expect(Month::start('2026-11')->format('Y-m-d H:i:s'))->toBe('2026-11-01 00:00:00');
    expect(Month::start('2028-02')->format('Y-m-d'))->toBe('2028-02-01');
});

it('drops the current time of day', function () {
    Carbon::setTestNow('2026-10-20 23:59:59');

    expect(Month::start('2026-10')->format('H:i:s'))->toBe('00:00:00');
    expect(Month::start('2026-10')->isSameDay(Carbon::parse('2026-10-01')))->toBeTrue();
});
