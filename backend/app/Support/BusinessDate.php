<?php

namespace App\Support;

use App\Models\Shift;
use Carbon\Carbon;

/**
 * Calendar dates and shift times as the business sees them. Shift dates and start/end times are
 * local wall-clock values, while the app's clock and stored timestamps run in UTC: between local
 * midnight and the UTC midnight, Carbon::today() is still yesterday, and "06:00" read in UTC is
 * two hours late in a Warsaw summer. Compare shift dates against BusinessDate::today() and shift
 * times against shiftStart()/shiftEnd() instead.
 */
final class BusinessDate
{
    public static function timezone(): string
    {
        return config('app.business_timezone', 'Europe/Warsaw');
    }

    /** The current instant, in the business timezone. */
    public static function now(): Carbon
    {
        return Carbon::now(self::timezone());
    }

    /** Today's date (00:00) in the business timezone. */
    public static function today(): Carbon
    {
        return self::now()->startOfDay();
    }

    /** Today's date in the business timezone as Y-m-d, for comparing with shift dates. */
    public static function todayString(): string
    {
        return self::today()->toDateString();
    }

    /**
     * When the shift starts, in the business timezone: its date at its start time, or at 00:00
     * when it has no start time.
     */
    public static function shiftStart(Shift $shift): Carbon
    {
        return self::at($shift->date->format('Y-m-d'), $shift->start_time);
    }

    /**
     * When the shift ends, in the business timezone. An overnight shift (end time at or before the
     * start time) ends on the next day; a shift with no end time ends at the end of its date.
     */
    public static function shiftEnd(Shift $shift): Carbon
    {
        // The next day is taken on the calendar date, not by adding 24 hours to an instant: across a
        // DST change the night is 7 or 9 hours long, and 06:00 must stay 06:00.
        $date = $shift->date->format('Y-m-d');
        $nextDate = $shift->date->copy()->addDay()->format('Y-m-d');
        if (!$shift->end_time) {
            return self::at($nextDate);
        }

        $overnight = $shift->start_time && substr($shift->end_time, 0, 5) <= substr($shift->start_time, 0, 5);

        return self::at($overnight ? $nextDate : $date, $shift->end_time);
    }

    /**
     * A local date and wall-clock time ("06:00" or "06:00:00") as an instant in the business
     * timezone. Building it there, rather than in UTC, keeps the hour right across DST changes.
     * A time that doesn't exist on the spring-forward day (02:30) rolls forward to 03:30 CEST; one
     * that happens twice on the fall-back day (02:30) is taken as the second, in CET (+01:00).
     */
    private static function at(string $date, ?string $time = null): Carbon
    {
        return Carbon::parse($date . ' ' . substr($time ?? '00:00', 0, 5), self::timezone());
    }
}
