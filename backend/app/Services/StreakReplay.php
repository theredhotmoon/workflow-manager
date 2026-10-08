<?php
// backend/app/Services/StreakReplay.php

namespace App\Services;

use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use Illuminate\Support\Carbon;

/** Loads the assignments a StreakTimeline replays — one query per group. */
class StreakReplay
{
    /** @param  list<int>  $userIds */
    public function forGroup(ShiftGroup $group, array $userIds, ?\Carbon\Carbon $until = null): StreakTimeline
    {
        $rules = $group->streakRules()->get()->keyBy('shift_type');
        if ($rules->isEmpty() || $userIds === []) {
            return new StreakTimeline($rules, []);
        }

        $rows = ShiftAssignment::query()
            ->join('shifts', 'shifts.id', '=', 'shift_assignments.shift_id')
            ->where('shifts.shift_group_id', $group->id)
            ->whereIn('shift_assignments.user_id', $userIds)
            ->when($until !== null, fn ($query) => $query->where('shifts.date', '<', $until->toDateString()))
            // Plain rows (no models): only scalar columns are read; the date comes back as a string.
            ->toBase()
            ->get(['shift_assignments.id as assignment_id', 'shift_assignments.user_id', 'shifts.date', 'shifts.shift_number', 'shifts.shift_type', 'shift_assignments.rest_policy']);

        $events = [];
        foreach ($rows as $row) {
            $events[(int) $row->user_id][] = [
                'assignment_id' => (int) $row->assignment_id,
                'date' => substr((string) $row->date, 0, 10),
                'shift_number' => (int) $row->shift_number,
                'shift_type' => $row->shift_type,
                'rest_policy' => $row->rest_policy,
            ];
        }

        return new StreakTimeline($rules, $events);
    }
}
