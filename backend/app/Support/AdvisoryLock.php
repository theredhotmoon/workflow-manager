<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Named PostgreSQL advisory locks, for work that must not run twice at once but has no single row
 * to lock (the next procedure identifier, an auto-fill of one group's month).
 */
final class AdvisoryLock
{
    /**
     * Waits for, then holds, the exclusive lock called $name until the current transaction ends
     * (commit or rollback releases it). Outside a transaction (a seeder, say) the lock ends with the
     * statement, so it only serialises anything when called inside the writing transaction.
     */
    public static function forTransaction(string $name): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [self::key($name)]);
    }

    /** The lock's bigint key: a CRC32 of the name (a rare collision only serialises a little more). */
    public static function key(string $name): int
    {
        return crc32($name);
    }
}
