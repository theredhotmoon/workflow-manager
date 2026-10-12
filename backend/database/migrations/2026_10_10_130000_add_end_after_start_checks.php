<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Date ranges can't end before they start (L11): CHECK constraints on every whole-day range.
 *
 * Rows that already violate it could only come from the partial-update bug (one date changed, the
 * other not compared). Their two dates are swapped rather than failing the deploy: it keeps both
 * dates the admin entered and yields a valid range they can correct; how many were swapped is
 * printed per table.
 */
return new class extends Migration
{
    /** table => [constraint, start column, end column] */
    private const RANGES = [
        'newbie_periods' => ['newbie_periods_end_after_start', 'start_date', 'end_date'],
        'user_availabilities' => ['user_availabilities_end_after_start', 'start_date', 'end_date'],
        'delegations' => ['delegations_end_after_start', 'start_datetime', 'end_datetime'],
        'archived_delegations' => ['archived_delegations_end_after_start', 'start_datetime', 'end_datetime'],
        'delegation_user' => ['delegation_user_custom_end_after_start', 'custom_start', 'custom_end'],
        'archived_delegation_user' => ['archived_delegation_user_custom_end_after_start', 'custom_start', 'custom_end'],
    ];

    public function up(): void
    {
        foreach (self::RANGES as $table => [$constraint, $start, $end]) {
            // A NULL date (a member without own travel dates) satisfies the check.
            $swapped = DB::update("UPDATE {$table} SET {$start} = {$end}, {$end} = {$start} WHERE {$end} < {$start}");
            if ($swapped > 0 && defined('STDERR')) {
                fwrite(STDERR, "  add_end_after_start_checks: swapped reversed dates on {$swapped} {$table} row(s).\n");
            }
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$end} >= {$start})");
        }
    }

    public function down(): void
    {
        foreach (self::RANGES as $table => [$constraint]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
        }
    }
};
