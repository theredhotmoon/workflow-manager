<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftChangeRequest extends Model
{
    protected $fillable = ['shift_assignment_id', 'user_id', 'shift_id', 'shift_date', 'shift_number', 'reason', 'status'];

    protected $casts = [
        'shift_date' => 'date:Y-m-d',
        'shift_number' => 'integer',
    ];

    /**
     * Removing the assignment (unassign, move, deactivation, group archive) or the shift (day-count
     * reduction, clear month) keeps the request with that link nulled: lists show it as
     * "assignment removed" / "shift removed", with the date and slot it was about.
     */
    protected $appends = ['assignment_removed', 'shift_removed'];

    protected static function booted(): void
    {
        // The shift's date and slot are copied onto the request, so it still names its shift
        // once the shift itself is gone.
        static::creating(function (self $request) {
            if ($request->shift_id && ($request->shift_date === null || $request->shift_number === null)) {
                $shift = Shift::find($request->shift_id, ['id', 'date', 'shift_number']);
                if ($shift) {
                    $request->shift_date ??= $shift->date->toDateString();
                    $request->shift_number ??= $shift->shift_number;
                }
            }
        });
    }

    /**
     * Need-to-know (L2): the free-text reason is private to the requester and to schedule managers
     * (admins, schedule maintainers). Others may still see that a request exists.
     */
    public function reasonVisibleTo(?User $viewer): bool
    {
        return $viewer !== null && ($viewer->canManageSchedule() || (int) $viewer->id === (int) $this->user_id);
    }

    public function getHidden()
    {
        $hidden = parent::getHidden();

        return $this->reasonVisibleTo(User::viewer()) ? $hidden : [...$hidden, 'reason'];
    }

    public function getAssignmentRemovedAttribute(): bool
    {
        return $this->shift_assignment_id === null;
    }

    public function getShiftRemovedAttribute(): bool
    {
        return $this->shift_id === null;
    }

    public function assignment()
    {
        return $this->belongsTo(ShiftAssignment::class, 'shift_assignment_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
}
