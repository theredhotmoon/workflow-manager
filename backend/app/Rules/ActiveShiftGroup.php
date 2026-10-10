<?php

namespace App\Rules;

use App\Models\ShiftGroup;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A shift_group_id that new work may be planned in: it exists and isn't archived. Only planning
 * endpoints use it, so only they say "or is archived"; reads that accept archived groups keep
 * the plain exists rule and its default message.
 */
class ActiveShiftGroup implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_numeric($value) || !ShiftGroup::whereKey((int) $value)->whereNull('archived_at')->exists()) {
            $fail(ShiftGroup::ARCHIVED_OR_MISSING_MESSAGE);
        }
    }
}
