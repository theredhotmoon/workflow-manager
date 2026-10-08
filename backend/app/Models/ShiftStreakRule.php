<?php

namespace App\Models;

use App\Support\ShiftType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftStreakRule extends Model
{
    protected $fillable = ['shift_group_id', 'shift_type', 'streak_length', 'rest_days'];

    protected $casts = [
        'streak_length' => 'integer',
        'rest_days' => 'integer',
    ];

    public function shiftGroup(): BelongsTo
    {
        return $this->belongsTo(ShiftGroup::class);
    }

    protected function shiftType(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => ShiftType::normalize($value));
    }
}
