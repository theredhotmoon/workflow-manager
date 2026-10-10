<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/** PostgreSQL error classes the app answers as the caller's mistake rather than a 500. */
final class SqlState
{
    /** A value PostgreSQL can't read as the column's type: 22P02, 22007, 22008. */
    private const INVALID_INPUT = ['22P02', '22007', '22008'];

    private const UNIQUE_VIOLATION = '23505';

    public static function isInvalidInput(QueryException $e): bool
    {
        return in_array(self::of($e), self::INVALID_INPUT, true);
    }

    /** A unique constraint refused the row: usually two identical requests racing each other. */
    public static function isUniqueViolation(QueryException $e): bool
    {
        return self::of($e) === self::UNIQUE_VIOLATION;
    }

    private static function of(QueryException $e): string
    {
        return (string) ($e->errorInfo[0] ?? $e->getCode());
    }
}
