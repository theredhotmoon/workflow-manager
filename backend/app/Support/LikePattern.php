<?php

namespace App\Support;

/** Search terms for LIKE / ILIKE: the user's text matched as written, anywhere in the value. */
final class LikePattern
{
    /** "%term%" with \, % and _ escaped (PostgreSQL's default LIKE escape character is \). */
    public static function contains(string $term): string
    {
        return '%'.addcslashes($term, '\\%_').'%';
    }
}
