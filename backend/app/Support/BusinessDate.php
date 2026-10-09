<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Calendar dates as the business sees them. Shift dates are local calendar days, while the app's
 * clock runs in UTC: between local midnight and the UTC midnight, Carbon::today() is still
 * yesterday. Compare shift dates against BusinessDate::today() instead.
 */
final class BusinessDate
{
    public static function timezone(): string
    {
        return config('app.business_timezone', 'Europe/Warsaw');
    }

    /** Today's date (00:00) in the business timezone. */
    public static function today(): Carbon
    {
        return Carbon::now(self::timezone())->startOfDay();
    }

    /** Today's date in the business timezone as Y-m-d, for comparing with shift dates. */
    public static function todayString(): string
    {
        return self::today()->toDateString();
    }
}
