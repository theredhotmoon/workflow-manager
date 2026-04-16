<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class ShiftChecklist extends Model
{
    protected $fillable = [
        'shift_id',
        'checklist_template_id',
        'is_required',
        'deadline_minutes',
        'deadline_reference',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'deadline_minutes' => 'integer',
        'sort_order' => 'integer',
    ];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function template()
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    /**
     * Calculate the absolute deadline datetime based on shift date/times.
     *
     * @param string $shiftDate  e.g. "2026-03-04"
     * @param string|null $startTime e.g. "06:00"
     * @param string|null $endTime   e.g. "14:00"
     * @return Carbon|null
     */
    public function getDeadlineDatetime($shiftDate, $startTime, $endTime)
    {
        if (!$this->deadline_minutes) {
            return null;
        }

        if ($this->deadline_reference === 'shift_end' && $endTime) {
            $base = Carbon::parse($shiftDate . ' ' . $endTime);
            // If end_time is before start_time, it crosses midnight
            if ($startTime && $endTime < $startTime) {
                $base->addDay();
            }
            return $base->subMinutes($this->deadline_minutes);
        }

        // Default: from shift_start
        if ($startTime) {
            return Carbon::parse($shiftDate . ' ' . $startTime)
                ->addMinutes($this->deadline_minutes);
        }

        // If no times, use start of day + offset
        return Carbon::parse($shiftDate)->addMinutes($this->deadline_minutes);
    }
}
