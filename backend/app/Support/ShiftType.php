<?php

namespace App\Support;

/**
 * Shift types are free-form labels (e.g. "night") that Shift Streak rules match on exactly.
 * Normalising every write keeps "Night " and "night" from silently failing to match.
 */
final class ShiftType
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_strtolower($trimmed);
    }
}
