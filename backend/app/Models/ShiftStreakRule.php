<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function states(): HasMany
    {
        return $this->hasMany(ShiftStreakState::class);
    }
}
