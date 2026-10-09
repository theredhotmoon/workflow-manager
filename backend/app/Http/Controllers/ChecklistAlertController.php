<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftChecklist;
use App\Models\ShiftAssignment;
use App\Models\ChecklistSubmission;
use App\Support\BusinessDate;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ChecklistAlertController extends Controller
{
    /**
     * How far back (in business days before today) missed checklists are still reported.
     * A checklist missed earlier than this no longer raises an alert. The window also
     * covers the overnight logic, which only needs yesterday's shift.
     */
    public const ALERT_LOOKBACK_DAYS = 7;

    /**
     * Get checklist alerts for the currently logged-in user.
     *
     * Returns per-checklist alerts with deadline info.
     * - current_shift_alerts: checklists of today's shifts, and of an overnight shift from an earlier
     *   date that is still on, where the user is assigned and nothing was filled yet
     * - overdue_alerts: mandatory checklists of shifts that have ended on an earlier date, never filled
     *
     * "Today" and "now" are the business timezone's: shift dates and times are local wall-clock.
     * Only shifts dated from ALERT_LOOKBACK_DAYS before today through today are scanned.
     */
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $now = BusinessDate::now();
        $today = $now->toDateString();
        $windowStart = $now->copy()->subDays(self::ALERT_LOOKBACK_DAYS)->toDateString();

        // Shifts in the alert window where this user is assigned
        $assignedShiftIds = ShiftAssignment::where('user_id', $user->id)
            ->whereIn('shift_id', Shift::select('id')->whereBetween('date', [$windowStart, $today]))
            ->pluck('shift_id');

        if ($assignedShiftIds->isEmpty()) {
            return response()->json([
                'current_shift_alerts' => [],
                'overdue_alerts' => [],
            ]);
        }

        // Get shift checklists for assigned shifts
        // An archived template can't be filled any more, so it raises no alerts (past shifts keep the row).
        $shiftChecklists = ShiftChecklist::with('template:id,name', 'shift')
            ->whereIn('shift_id', $assignedShiftIds)
            ->whereHas('template', fn ($q) => $q->notArchived())
            ->get();

        if ($shiftChecklists->isEmpty()) {
            return response()->json([
                'current_shift_alerts' => [],
                'overdue_alerts' => [],
            ]);
        }

        // Find which shift+template combos already have a submission (by anyone)
        // Match by shift_id when available, OR by date+template+user as fallback
        $submissionsByShift = ChecklistSubmission::whereIn('shift_id', $assignedShiftIds)
            ->get(['shift_id', 'checklist_template_id']);

        $submittedKeys = [];
        foreach ($submissionsByShift as $s) {
            $submittedKeys[$s->shift_id . '-' . $s->checklist_template_id] = true;
        }

        // Also find submissions by date+template (for when shift_id was null)
        $submissionsByDate = ChecklistSubmission::whereNull('shift_id')
            ->where(function ($q) use ($user) {
                $q->where('outgoing_user_id', $user->id)
                    ->orWhere('incoming_user_id', $user->id);
            })
            ->whereBetween('date', [$windowStart, $today])
            ->get(['checklist_template_id', 'date']);

        $submittedByDate = [];
        foreach ($submissionsByDate as $s) {
            $submittedByDate[Carbon::parse($s->date)->format('Y-m-d') . '-' . $s->checklist_template_id] = true;
        }

        $currentAlerts = [];
        $overdueAlerts = [];

        foreach ($shiftChecklists as $sc) {
            $shift = $sc->shift;
            if (!$shift)
                continue;

            // Skip if already submitted (by shift_id match)
            $key = $shift->id . '-' . $sc->checklist_template_id;
            if (isset($submittedKeys[$key]))
                continue;

            // Skip if already submitted (by date+template fallback)
            $dateStr = $shift->date->format('Y-m-d');
            $dateKey = $dateStr . '-' . $sc->checklist_template_id;
            if (isset($submittedByDate[$dateKey]))
                continue;
            $deadline = $sc->getDeadlineDatetime($shift);

            $alert = [
                'shift_id' => $shift->id,
                'date' => $dateStr,
                'shift_number' => $shift->shift_number,
                'start_time' => $shift->start_time ? substr($shift->start_time, 0, 5) : null,
                'end_time' => $shift->end_time ? substr($shift->end_time, 0, 5) : null,
                'checklist_template_id' => $sc->checklist_template_id,
                'checklist_template_name' => $sc->template?->name,
                'is_required' => $sc->is_required,
                'deadline_minutes' => $sc->deadline_minutes,
                'deadline_reference' => $sc->deadline_reference,
                'deadline_at' => $deadline?->toIso8601String(),
                'minutes_remaining' => $deadline ? (int) $now->diffInMinutes($deadline, false) : null,
            ];

            // An overnight shift from yesterday is still current until it ends the next morning.
            if ($dateStr === $today || ($dateStr < $today && $now->lt(BusinessDate::shiftEnd($shift)))) {
                $currentAlerts[] = $alert;
            } elseif ($dateStr < $today && $sc->is_required) {
                $overdueAlerts[] = $alert;
            }
        }

        return response()->json([
            'current_shift_alerts' => $currentAlerts,
            'overdue_alerts' => $overdueAlerts,
        ]);
    }
}
