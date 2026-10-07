<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Parses a validated `Y-m` month key (e.g. "2027-02").
 *
 * Never parse a month key with createFromFormat and the bare "Y-m" format: it fills in *today's*
 * day of the month, so on the 29th-31st a shorter month overflows into the next one (on Oct 31,
 * "2027-02" becomes March). MonthParsingTest guards against that pattern reappearing.
 */
final class Month
{
    /** First day of the month, 00:00:00. */
    public static function start(string $yearMonth): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $yearMonth.'-01')->startOfDay();
    }
}
