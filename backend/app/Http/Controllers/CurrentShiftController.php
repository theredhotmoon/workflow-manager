<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CurrentShiftController extends Controller
{
    /**
     * Get the currently active shift (based on today's date and current time).
     * Returns the shift with its assigned users, or null if no shift is active.
     */
    public function __invoke(Request $request)
    {
        $now = Carbon::now();
        $today = $now->toDateString();
        $yesterday = $now->copy()->subDay()->toDateString();
        $currentTime = $now->format('H:i');

        // Get today's shifts that have times set
        $todayShifts = Shift::with(['assignments.user:id,name,email'])
            ->where('date', $today)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->orderBy('shift_number')
            ->get();

        // Also check yesterday's overnight shifts
        $yesterdayShifts = Shift::with(['assignments.user:id,name,email'])
            ->where('date', $yesterday)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->whereRaw('end_time < start_time') // only overnight shifts
            ->orderBy('shift_number')
            ->get();

        $activeShift = null;

        // Check today's shifts
        foreach ($todayShifts as $shift) {
            $start = substr($shift->start_time, 0, 5);
            $end = substr($shift->end_time, 0, 5);

            if ($end > $start) {
                // Normal shift: active if current time is between start and end
                if ($currentTime >= $start && $currentTime < $end) {
                    $activeShift = $shift;
                    break;
                }
            } else {
                // Overnight shift starting today: active if current time >= start
                if ($currentTime >= $start) {
                    $activeShift = $shift;
                    break;
                }
            }
        }

        // If no match yet, check yesterday's overnight shifts
        if (!$activeShift) {
            foreach ($yesterdayShifts as $shift) {
                $end = substr($shift->end_time, 0, 5);
                // Overnight shift that started yesterday: active if current time < end
                if ($currentTime < $end) {
                    $activeShift = $shift;
                    break;
                }
            }
        }

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
                'is_overnight' => substr($activeShift->end_time, 0, 5) < substr($activeShift->start_time, 0, 5),
                'crew' => $activeShift->assignments->map(fn($a) => [
                    'id' => $a->user->id,
                    'name' => $a->user->name,
                    'email' => $a->user->email,
                ]),
            ],
        ]);
    }
}
