<?php

namespace App\Http\Controllers;

use App\Models\ShiftChangeRequest;
use App\Models\ShiftAssignment;
use App\Models\Shift;
use App\Models\Alarm;
use App\Services\AlarmService;
use App\Support\BusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\Month;

class ShiftChangeRequestController extends Controller
{
    /**
     * Employee: Submit a change request for an assignment.
     */
    public function store(Request $request)
    {
        $request->validate([
            'shift_assignment_id' => ['required', 'integer', 'exists:shift_assignments,id'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $result = DB::transaction(function () use ($request) {
            // The assignment row is locked: an unassign, move or bulk removal locks it too before it
            // settles requests and deletes it, so a request filed meanwhile either lands first (and
            // is settled with the rest) or waits and finds the assignment gone (404, not a 500 on
            // the foreign key or a request left pending on a removed assignment).
            // Shift first (FOR SHARE), then the assignment: the order a day-count reduction locks them
            // (its shifts FOR UPDATE, then their assignments), so the two can't deadlock.
            $shiftId = ShiftAssignment::whereKey($request->shift_assignment_id)->value('shift_id');
            $shiftLocked = $shiftId && Shift::whereKey($shiftId)->sharedLock()->first(['id']);
            $assignment = $shiftLocked
                ? ShiftAssignment::with('shift')->lockForUpdate()->find($request->shift_assignment_id)
                : null;
            if (!$assignment) {
                return response()->json(['message' => 'This assignment no longer exists.'], 404);
            }

            // Ensure the assignment belongs to the current user
            if ($assignment->user_id !== auth()->id()) {
                return response()->json(['message' => 'You can only request changes for your own assignments.'], 403);
            }

            // Ensure the shift is today or in the future (the business timezone's today)
            if ($assignment->shift->date->toDateString() < BusinessDate::todayString()) {
                return response()->json(['message' => 'Cannot request changes for past shifts.'], 422);
            }

            // Ensure no existing pending request
            $existing = ShiftChangeRequest::where('shift_assignment_id', $assignment->id)
                ->where('status', 'pending')
                ->exists();

            if ($existing) {
                return response()->json(['message' => 'You already have a pending request for this shift.'], 422);
            }

            $changeRequest = ShiftChangeRequest::create([
                'shift_assignment_id' => $assignment->id,
                'user_id' => auth()->id(),
                'shift_id' => $assignment->shift_id,
                'reason' => $request->reason,
                'status' => 'pending',
            ]);

            return [$assignment, $changeRequest];
        });
        if ($result instanceof JsonResponse) {
            return $result;
        }
        [$assignment, $changeRequest] = $result;

        // Dispatch alarm to admins and schedule maintainers
        $userName = auth()->user()->name;
        $userId = auth()->id();
        $shiftDate = $assignment->shift->date->format('Y-m-d');
        $shiftMonth = $assignment->shift->date->format('Y-m');
        (new AlarmService())->notifyAdminsAndMaintainers(
            Alarm::TYPE_EMPLOYEE_CANNOT_ATTEND_SHIFT,
            "{$userName} cannot attend shift #{$assignment->shift->shift_number} on {$shiftDate}",
            $request->reason,
            "/schedule/{$shiftMonth}?highlight_user={$userId}&scroll_date={$shiftDate}",
            'View Schedule'
        );

        return response()->json($changeRequest->load('user:id,name'), 201);
    }

    /**
     * Schedule managers: every request for a shift in the month. Requests whose assignment or shift
     * has since been removed are included (assignment_removed / shift_removed, with the shift's
     * date and slot kept on the request).
     */
    public function index(Request $request)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $start = Month::start($request->month);
        $end = $start->copy()->endOfMonth();

        $requests = ShiftChangeRequest::with(['user:id,name', 'shift:id,date,shift_number,shift_group_id', 'shift.shiftGroup:id,name', 'assignment'])
            ->whereBetween('shift_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json($requests);
    }

    /**
     * Employee: Get own requests (including ones whose assignment or shift was removed).
     */
    public function myRequests(Request $request)
    {
        $requests = ShiftChangeRequest::with(['shift:id,date,shift_number,start_time,end_time'])
            ->where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json($requests);
    }

    /**
     * Admin: Acknowledge a request.
     */
    public function acknowledge($id)
    {
        $changeRequest = ShiftChangeRequest::findOrFail($id);
        $changeRequest->update(['status' => 'acknowledged']);
        return response()->json($changeRequest);
    }
}
