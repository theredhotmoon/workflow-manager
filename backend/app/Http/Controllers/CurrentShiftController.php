<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Support\BusinessDate;
use Illuminate\Http\Request;

class CurrentShiftController extends Controller
{
    /**
     * Get the currently active shift (based on today's date and current time).
     * Returns the shift with its assigned users, or null if no shift is active.
     */
    public function __invoke(Request $request)
    {
        // Shift dates and times are business-timezone wall-clock: compare instants built from them.
        $now = BusinessDate::now();
        $today = $now->toDateString();
        $yesterday = BusinessDate::today()->subDay()->toDateString();

        // Get today's shifts that have times set
        $todayShifts = Shift::with(['assignments.user:id,name,email'])
            ->where('date', $today)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->orderBy('shift_number')
            ->get();

        // Also check yesterday's overnight shifts (end at or before start: they end today)
        $yesterdayShifts = Shift::with(['assignments.user:id,name,email'])
            ->where('date', $yesterday)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->whereRaw('end_time <= start_time')
            ->orderBy('shift_number')
            ->get();

        // Active from its start (inclusive) to its end (exclusive); today's shifts take precedence.
        $isActive = fn (Shift $shift) => $now->gte(BusinessDate::shiftStart($shift)) && $now->lt(BusinessDate::shiftEnd($shift));
        $activeShift = $todayShifts->first($isActive) ?? $yesterdayShifts->first($isActive);

        if (!$activeShift) {
            return response()->json(['active_shift' => null]);
        }

        return response()->json([
            'active_shift' => [
                'id' => $activeShift->id,
                'date' => $activeShift->date->format('Y-m-d'),
                'shift_number' => $activeShift->shift_number,
                'shift_group_id' => $activeShift->shift_group_id,
                'shift_group_name' => $activeShift->shiftGroup?->name,
                'start_time' => substr($activeShift->start_time, 0, 5),
                'end_time' => substr($activeShift->end_time, 0, 5),
                'is_overnight' => substr($activeShift->end_time, 0, 5) <= substr($activeShift->start_time, 0, 5),
                'crew' => $activeShift->assignments->map(fn($a) => [
                    'id' => $a->user->id,
                    'name' => $a->user->name,
                    'email' => $a->user->email,
                ]),
            ],
        ]);
    }
}
