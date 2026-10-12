<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/** PostgreSQL error classes the app answers as the caller's mistake rather than a 500. */
final class SqlState
{
    /**
     * A value PostgreSQL can't read as the column's type: 22P02 (text), 22007 / 22008 (dates) and
     * 22003 (a number out of the column's range, e.g. 3000000000 against a 4-byte integer).
     */
    private const INVALID_INPUT = ['22P02', '22007', '22008', '22003'];

    public static function isInvalidInput(QueryException $e): bool
    {
        return in_array(self::of($e), self::INVALID_INPUT, true);
    }

    private static function of(QueryException $e): string
    {
        return (string) ($e->errorInfo[0] ?? $e->getCode());
    }
}
