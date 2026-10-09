<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftChecklist;
use App\Models\ShiftAssignment;
use App\Models\ChecklistSubmission;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ChecklistAlertController extends Controller
{
    /**
     * Get checklist alerts for the currently logged-in user.
     *
     * Returns per-checklist alerts with deadline info.
     * - current_shift_alerts: checklists due today where user is assigned and not yet filled
     * - overdue_alerts: past checklists that were mandatory but never filled
     */
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $today = Carbon::today()->toDateString();
        $now = Carbon::now();

        // Find all shifts where this user is assigned
        $assignedShiftIds = ShiftAssignment::where('user_id', $user->id)
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

        $submittedKeys = $submissionsByShift
            ->map(fn($s) => $s->shift_id . '-' . $s->checklist_template_id)
            ->toArray();

        // Also find submissions by date+template (for when shift_id was null)
        $submissionsByDate = ChecklistSubmission::whereNull('shift_id')
            ->where(function ($q) use ($user) {
                $q->where('outgoing_user_id', $user->id)
                    ->orWhere('incoming_user_id', $user->id);
            })
            ->get(['checklist_template_id', 'date']);

        $submittedByDate = [];
        foreach ($submissionsByDate as $s) {
            $submittedByDate[] = Carbon::parse($s->date)->format('Y-m-d') . '-' . $s->checklist_template_id;
        }

        $currentAlerts = [];
        $overdueAlerts = [];

        foreach ($shiftChecklists as $sc) {
            $shift = $sc->shift;
            if (!$shift)
                continue;

            // Skip if already submitted (by shift_id match)
            $key = $shift->id . '-' . $sc->checklist_template_id;
            if (in_array($key, $submittedKeys))
                continue;

            // Skip if already submitted (by date+template fallback)
            $dateStr = $shift->date->format('Y-m-d');
            $dateKey = $dateStr . '-' . $sc->checklist_template_id;
            if (in_array($dateKey, $submittedByDate))
                continue;
            $deadline = $sc->getDeadlineDatetime(
                $dateStr,
                $shift->start_time ? substr($shift->start_time, 0, 5) : null,
                $shift->end_time ? substr($shift->end_time, 0, 5) : null
            );

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

            if ($dateStr === $today) {
                $currentAlerts[] = $alert;
            } elseif ($shift->date->lt(Carbon::today()) && $sc->is_required) {
                $overdueAlerts[] = $alert;
            }
        }

        return response()->json([
            'current_shift_alerts' => $currentAlerts,
            'overdue_alerts' => $overdueAlerts,
        ]);
    }
}
