<?php

namespace App\Http\Controllers;

use App\Models\Alarm;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftChangeRequest;
use App\Models\User;
use App\Services\AlarmService;
use App\Support\BusinessDate;
use App\Support\Month;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        try {
            $result = $this->fileRequest($request);
        } catch (UniqueConstraintViolationException) {
            // Only one pending request per assignment (partial unique index): a concurrent one won.
            return response()->json(['message' => self::ALREADY_PENDING_MESSAGE], 422);
        }
        if ($result instanceof JsonResponse) {
            return $result;
        }
        [$assignment, $changeRequest] = $result;

        // Alarm to the admins and schedule maintainers (not the filer, when a manager files their
        // own): it opens the Change Requests screen on the shift's group and month, where the
        // request can be acknowledged and its row links on to the Schedule.
        $userName = auth()->user()->name;
        $shiftDate = $assignment->shift->date->format('Y-m-d');
        $query = http_build_query([
            'group' => $assignment->shift->shift_group_id,
            'month' => $assignment->shift->date->format('Y-m'),
        ]);
        (new AlarmService)->notifyAdminsAndMaintainers(
            Alarm::TYPE_EMPLOYEE_CANNOT_ATTEND_SHIFT,
            "{$userName} cannot attend shift #{$assignment->shift->shift_number} on {$shiftDate}",
            $request->reason,
            "/change-requests?{$query}",
            'View request',
            exceptUserId: auth()->id(),
        );

        return response()->json($changeRequest->load('user:id,name'), 201);
    }

    private const ALREADY_PENDING_MESSAGE = 'You already have a pending request for this shift.';

    /**
     * The checks and the insert of store(), in one transaction.
     *
     * @return JsonResponse|array{0: ShiftAssignment, 1: ShiftChangeRequest}
     */
    private function fileRequest(Request $request): JsonResponse|array
    {
        return DB::transaction(function () use ($request) {
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
            if (! $assignment) {
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
                return response()->json(['message' => self::ALREADY_PENDING_MESSAGE], 422);
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
    }

    /** What the manager screen shows of a request: names only (no emails), the shift and its group. */
    private const INDEX_RELATIONS = [
        'user:id,name',
        'acknowledgedBy:id,name',
        'shift:id,date,shift_number,shift_group_id,start_time,end_time',
        'shift.shiftGroup:id,name,archived_at',
        'assignment:id,shift_id,user_id',
    ];

    /**
     * Schedule managers: the change requests, one page at a time (per_page 1..100, default 20),
     * pending first, then newest filed first (highest id breaks ties).
     *
     * Filters: status (pending, the default; acknowledged; all), shift_group_id, and month (Y-m, on
     * the shift's date as copied onto the request). Requests whose assignment or shift has since
     * been removed are included (assignment_removed / shift_removed, with the shift's date and slot
     * kept on the request); a group filter can't match a request whose shift is gone, since its
     * group went with the shift. Requests in archived groups are listed, not dropped: the group
     * comes with its archived_at and the screen flags them. Archiving a group removes (and so
     * settles) only work on future days; a request for a shift of today or earlier stays pending,
     * flagged, and counts towards pending-count like any other.
     */
    public function index(Request $request)
    {
        $request->validate([
            'status' => ['sometimes', 'nullable', 'in:pending,acknowledged,all'],
            'shift_group_id' => ['sometimes', 'nullable', 'integer', 'exists:shift_groups,id'],
            'month' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'per_page' => ['sometimes', 'nullable', 'integer'],
        ]);
        $status = $request->input('status') ?: 'pending';
        $perPage = max(1, min((int) $request->input('per_page', 20), 100));

        $query = ShiftChangeRequest::with(self::INDEX_RELATIONS)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->filled('shift_group_id'), fn ($q) => $q->whereHas(
                'shift', fn ($s) => $s->where('shift_group_id', (int) $request->shift_group_id)
            ))
            ->when($request->filled('month'), function ($q) use ($request) {
                $start = Month::start($request->month);
                $q->whereBetween('shift_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()]);
            })
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return response()->json($query->paginate($perPage));
    }

    /** Schedule managers: how many requests are waiting (the nav badge). */
    public function pendingCount()
    {
        return response()->json(['count' => ShiftChangeRequest::where('status', 'pending')->count()]);
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
     * Schedule managers: acknowledge a request. Idempotent: acknowledging one already acknowledged
     * (by a colleague, a double click, or a removal of its work that settled it) changes nothing,
     * keeps the first acknowledger and time, and doesn't notify the requester again. The first
     * acknowledgement records who and when, and tells the requester.
     */
    public function acknowledge(Request $request, $id)
    {
        [$changeRequest, $acknowledgedNow] = DB::transaction(function () use ($request, $id) {
            // Locked: two managers acknowledging at once can't both record themselves and notify.
            $changeRequest = ShiftChangeRequest::lockForUpdate()->findOrFail($id);
            // Anything but pending is left as it is (today the only other status is acknowledged).
            if ($changeRequest->status !== 'pending') {
                return [$changeRequest, false];
            }
            $changeRequest->forceFill([
                'status' => 'acknowledged',
                'acknowledged_by_id' => $request->user()->id,
                'acknowledged_at' => now(),
            ])->save();

            return [$changeRequest, true];
        });

        if ($acknowledgedNow) {
            $this->notifyRequesterOfAcknowledgement($changeRequest, $request->user());
        }

        return response()->json($changeRequest->load(self::INDEX_RELATIONS));
    }

    /** The requester learns their request was seen (not when they acknowledged it themselves, or are deactivated). */
    private function notifyRequesterOfAcknowledgement(ShiftChangeRequest $changeRequest, User $manager): void
    {
        $requester = User::find($changeRequest->user_id);
        if (! $requester || $requester->isDeactivated() || (int) $requester->id === (int) $manager->id) {
            return;
        }
        $date = $changeRequest->shift_date?->format('Y-m-d');
        $slot = $changeRequest->shift_number ? " (shift #{$changeRequest->shift_number})" : '';
        (new AlarmService)->notifyUser(
            Alarm::TYPE_SHIFT_CHANGE_REQUEST_ACKNOWLEDGED,
            $requester->id,
            "{$manager->name} acknowledged your change request for {$date}{$slot}",
            null,
            '/my-shifts',
            'View My Shifts'
        );
    }
}
