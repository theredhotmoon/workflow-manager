<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftGroup extends Model
{
    protected $fillable = [
        'name',
        'description',
        'color',
        'is_default',
        'sort_order',
        'settlement_mode',
        'settlement_anchor_date',
        'settlement_custom_length_days',
        'weekly_hour_cap',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
        'settlement_anchor_date' => 'date:Y-m-d',
        'settlement_custom_length_days' => 'integer',
        'weekly_hour_cap' => 'float',
    ];

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function streakRules()
    {
        return $this->hasMany(ShiftStreakRule::class);
    }

    public function onCallAssignments()
    {
        return $this->hasMany(OnCallAssignment::class);
    }
}
