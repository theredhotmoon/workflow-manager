<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

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
        'archived_at' => 'datetime',
    ];

    /**
     * Groups new work can be planned in. An archived group keeps its past shifts, hours and on-call
     * duties (reports still count them) but is left out of the schedule tabs, pickers and auto-fill.
     */
    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Validation rule for a shift_group_id that new work is planned in (not archived). */
    public static function activeExistsRule(): Exists
    {
        return Rule::exists('shift_groups', 'id')->whereNull('archived_at');
    }

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
