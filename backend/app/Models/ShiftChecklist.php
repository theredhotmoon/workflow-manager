<?php

namespace App\Models;

use App\Support\BusinessDate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

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
     * The handover's absolute deadline, in the business timezone: deadline_minutes after the shift
     * starts, or before it ends (an overnight shift ends the next day). A shift without the
     * reference time counts from its start, or from 00:00 of its date.
     *
     * The minutes are elapsed time, so they are counted in UTC: on a DST night 300 minutes after
     * 22:00 is 02:00 or 04:00 on the local clock, not 03:00.
     */
    public function getDeadlineDatetime(Shift $shift): ?Carbon
    {
        if (!$this->deadline_minutes) {
            return null;
        }

        if ($this->deadline_reference === 'shift_end' && $shift->end_time) {
            return BusinessDate::shiftEnd($shift)->utc()->subMinutes($this->deadline_minutes)->setTimezone(BusinessDate::timezone());
        }

        return BusinessDate::shiftStart($shift)->utc()->addMinutes($this->deadline_minutes)->setTimezone(BusinessDate::timezone());
    }
}
