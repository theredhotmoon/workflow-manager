<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftStreakState extends Model
{
    protected $fillable = ['user_id', 'shift_streak_rule_id', 'current_count', 'resting_until', 'last_processed_date'];

    protected $casts = [
        'current_count' => 'integer',
        'resting_until' => 'date:Y-m-d',
        'last_processed_date' => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ShiftStreakRule::class, 'shift_streak_rule_id');
    }
}
