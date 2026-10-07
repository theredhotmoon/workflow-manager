<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ChecklistTemplateController;
use App\Http\Controllers\ChecklistSubmissionController;
use App\Http\Controllers\ChecklistAlertController;
use App\Http\Controllers\CurrentShiftController;
use App\Http\Controllers\UserAvailabilityController;
use App\Http\Controllers\ShiftAutoAssignController;
use App\Http\Controllers\DelegationController;
use App\Http\Controllers\DelegationArchiveController;
use App\Http\Controllers\OfficeClosureController;
use App\Http\Controllers\NewbiePeriodController;
use App\Http\Controllers\ShiftColorController;
use App\Http\Controllers\ShiftChangeRequestController;
use App\Http\Controllers\ShiftGroupController;
use App\Http\Controllers\ProcedureTagController;
use App\Http\Controllers\ProcedureController;
use App\Http\Controllers\ProcedureFaqController;
use App\Http\Controllers\ProcedureResponseTemplateController;
use App\Http\Controllers\ProcedureFeedbackController;
use App\Http\Controllers\ProceduresToLearnController;
use App\Http\Controllers\AlarmController;
use App\Http\Controllers\UserGroupController;
use App\Http\Controllers\SettlementPeriodController;
use App\Http\Controllers\OnCallController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Checklist submissions (any authenticated user)
    Route::get('/checklist-submissions', [ChecklistSubmissionController::class, 'index']);
    Route::post('/checklist-submissions', [ChecklistSubmissionController::class, 'store']);
    Route::get('/checklist-submissions/{checklistSubmission}', [ChecklistSubmissionController::class, 'show']);
    Route::put('/checklist-submissions/{checklistSubmission}/confirm', [ChecklistSubmissionController::class, 'confirm']);

    // Active templates (for filling forms)
    Route::get('/checklist-templates/active', [ChecklistTemplateController::class, 'active']);

    // Employee list (for any authenticated user)
    Route::get('/employees', function () {
        return response()->json(\App\Models\User::orderBy('name')->get(['id', 'name', 'email']));
    });

    // Checklist alerts for current user
    Route::get('/my-checklist-alerts', ChecklistAlertController::class);

    // Current active shift
    Route::get('/current-shift', CurrentShiftController::class);

    // Shift details (for checklist context)
    Route::get('/shifts/{shift}', function (\App\Models\Shift $shift) {
        $shift->load(['assignments.user:id,name', 'shiftChecklists.template:id,name']);
        return response()->json($shift);
    });

    // Shifts for a specific date (for checklist setup)
    Route::get('/shifts-by-date', function (Request $request) {
        $request->validate(['date' => ['required', 'date']]);
        $query = \App\Models\Shift::where('date', $request->date)
            ->with(['assignments.user:id,name', 'shiftChecklists.template:id,name'])
            ->orderBy('shift_number');

        if ($request->has('shift_group_id')) {
            $query->where('shift_group_id', $request->shift_group_id);
        }

        return response()->json($query->get());
    });

    // User availability (own entries)
    Route::get('/user-availabilities', [UserAvailabilityController::class, 'index']);
    Route::post('/user-availabilities', [UserAvailabilityController::class, 'store']);
    Route::delete('/user-availabilities/{userAvailability}', [UserAvailabilityController::class, 'destroy']);
    Route::get('/user-availabilities/{userId}/month', [UserAvailabilityController::class, 'forMonth']);

    // My shifts (any authenticated user)
    Route::get('/my-shifts', function (Request $request) {
        $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $user = $request->user();
        $start = \App\Support\Month::start($request->month);
        $end = $start->copy()->endOfMonth();

        $assignments = \App\Models\ShiftAssignment::where('user_id', $user->id)
            ->whereHas('shift', fn($q) => $q->whereBetween('date', [$start->toDateString(), $end->toDateString()]))
            ->with([
                'shift' => fn($q) => $q->with([
                    'shiftChecklists.template:id,name',
                    'submissions' => fn($sq) => $sq->where(function ($w) use ($user) {
                        $w->where('outgoing_user_id', $user->id)->orWhere('incoming_user_id', $user->id);
                    })->with('responses')
                ]),
                'changeRequests' => fn($q) => $q->where('status', 'pending'),
            ])
            ->get();

        $grouped = [];
        foreach ($assignments as $a) {
            $s = $a->shift;
            $dateStr = $s->date->format('Y-m-d');

            // Build per-checklist data
            $checklistsData = $s->shiftChecklists->map(function ($sc) use ($s, $dateStr) {
                $submission = $s->submissions->first(fn($sub) => $sub->checklist_template_id === $sc->checklist_template_id);
                $deadline = $sc->getDeadlineDatetime(
                    $dateStr,
                    $s->start_time ? substr($s->start_time, 0, 5) : null,
                    $s->end_time ? substr($s->end_time, 0, 5) : null
                );
                return [
                    'id' => $sc->id,
                    'checklist_template_id' => $sc->checklist_template_id,
                    'checklist_template_name' => $sc->template?->name,
                    'is_required' => $sc->is_required,
                    'deadline_minutes' => $sc->deadline_minutes,
                    'deadline_reference' => $sc->deadline_reference,
                    'deadline_at' => $deadline?->toIso8601String(),
                    'submission' => $submission ? [
                        'id' => $submission->id,
                        'status' => $submission->status,
                        'submitted_at' => $submission->submitted_at?->toIso8601String(),
                        'confirmed_at' => $submission->confirmed_at?->toIso8601String(),
                        'submitted_on_time' => $submission->submitted_on_time,
                        'responses_count' => $submission->responses->count(),
                    ] : null,
                ];
            })->values();

            $pendingRequest = $a->changeRequests->first();

            $grouped[$dateStr][] = [
                'assignment_id' => $a->id,
                'shift_id' => $s->id,
                'shift_number' => $s->shift_number,
                'shift_group_id' => $s->shift_group_id,
                'shift_group_name' => $s->shiftGroup?->name,
                'start_time' => $s->start_time ? substr($s->start_time, 0, 5) : null,
                'end_time' => $s->end_time ? substr($s->end_time, 0, 5) : null,
                'work_mode' => $s->work_mode ?? 'office',
                'shift_checklists' => $checklistsData,
                'has_change_request' => $pendingRequest !== null,
                'change_request_reason' => $pendingRequest?->reason,
            ];
        }

        return response()->json($grouped);
    });

    // Change own password
    Route::put('/change-my-password', function (Request $request) {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (!\Illuminate\Support\Facades\Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update(['password' => \Illuminate\Support\Facades\Hash::make($request->password)]);

        return response()->json(['message' => 'Password changed successfully.']);
    });

    // Delegations (any authenticated user)
    Route::get('/my-delegations', [DelegationController::class, 'myDelegations']);
    Route::get('/delegations/open', [DelegationController::class, 'openDelegations']);
    Route::post('/delegations/{delegation}/self-register', [DelegationController::class, 'selfRegister']);
    Route::delete('/delegations/{delegation}/self-unregister', [DelegationController::class, 'selfUnregister']);

    // Procedures (read-only for any authenticated user)
    Route::get('/procedures', [ProcedureController::class, 'index']);
    Route::get('/procedures/{procedure}', [ProcedureController::class, 'show']);
    Route::get('/procedures/{procedure}/access-log', [ProcedureController::class, 'accessLog']);
    Route::get('/procedures/{procedure}/versions', [ProcedureController::class, 'versionsList']);
    Route::get('/procedures/{procedure}/versions/{version}', [ProcedureController::class, 'getVersion']);
    Route::post('/procedures/{procedure}/mark-read', [ProcedureController::class, 'markAsRead']);
    Route::get('/procedures-tree', [ProcedureController::class, 'tree']);
    Route::put('/procedures/{procedure}/move', [ProcedureController::class, 'move']);
    Route::put('/procedures/{procedure}/change-owner', [ProcedureController::class, 'changeOwner']);
    Route::get('/procedures-to-learn', [ProceduresToLearnController::class, 'index']);
    Route::get('/procedure-tags', [ProcedureTagController::class, 'index']);
    Route::post('/procedures/check-read-status', [ProcedureController::class, 'checkReadStatus']);

    // Procedure FAQ (any authenticated user can ask/reply)
    Route::get('/procedures/{procedure}/faq', [ProcedureFaqController::class, 'index']);
    Route::post('/procedures/{procedure}/faq', [ProcedureFaqController::class, 'storeItem']);
    Route::post('/procedure-faq/{item}/replies', [ProcedureFaqController::class, 'storeReply']);
    Route::delete('/procedure-faq/{item}', [ProcedureFaqController::class, 'destroyItem']);
    Route::delete('/procedure-faq-replies/{reply}', [ProcedureFaqController::class, 'destroyReply']);
    Route::put('/procedure-faq/{item}/toggle-outdated', [ProcedureFaqController::class, 'toggleOutdatedItem']);
    Route::put('/procedure-faq-replies/{reply}/toggle-outdated', [ProcedureFaqController::class, 'toggleOutdatedReply']);

    // Procedure response templates (read-only for any user)
    Route::get('/procedures/{procedure}/templates', [ProcedureResponseTemplateController::class, 'index']);

    // Procedure feedback (any user can submit)
    Route::post('/procedures/{procedure}/feedback', [ProcedureFeedbackController::class, 'store']);

    // Shift change requests (employee)
    Route::post('/shift-change-requests', [ShiftChangeRequestController::class, 'store']);
    Route::get('/shift-change-requests/mine', [ShiftChangeRequestController::class, 'myRequests']);

    // Schedule (read-only for all authenticated users)
    Route::get('/schedule', [ScheduleController::class, 'index']);

    // User groups (read-only — needed by schedule maintainers for player filtering)
    Route::get('/user-groups', [UserGroupController::class, 'index']);

    // Alarms
    Route::get('/alarms', [AlarmController::class, 'index']);
    Route::get('/alarms/latest', [AlarmController::class, 'latest']);
    Route::get('/alarms/unread-count', [AlarmController::class, 'unreadCount']);
    Route::put('/alarms/{id}/read', [AlarmController::class, 'markRead']);
    Route::put('/alarms/read-all', [AlarmController::class, 'markAllRead']);

    // Admin-only routes
    Route::middleware('admin')->group(function () {
        // User management
        Route::apiResource('users', UserController::class);
        Route::put('/users/{user}/password', [UserController::class, 'changePassword']);

        // User groups (write operations)
        Route::apiResource('user-groups', UserGroupController::class)
            ->except(['show', 'index'])
            ->parameters(['user-groups' => 'userGroup']);
        Route::put('/user-groups/{userGroup}/users', [UserGroupController::class, 'syncUsers']);

        // Shift group management
        Route::post('/shift-groups', [ShiftGroupController::class, 'store']);
        Route::put('/shift-groups/{shiftGroup}', [ShiftGroupController::class, 'update']);
        Route::delete('/shift-groups/{shiftGroup}', [ShiftGroupController::class, 'destroy']);
        Route::post('/shift-groups/reorder', [ShiftGroupController::class, 'reorder']);
        Route::put('/shift-groups/{shiftGroup}/streak-rules', [ShiftGroupController::class, 'syncStreakRules']);

        // Shift color definitions
        Route::get('/shift-colors', [ShiftColorController::class, 'index']);
        Route::post('/shift-colors', [ShiftColorController::class, 'store']);
        Route::put('/shift-colors/{shiftColor}', [ShiftColorController::class, 'update']);
        Route::delete('/shift-colors/{shiftColor}', [ShiftColorController::class, 'destroy']);
        Route::post('/shift-colors/reorder', [ShiftColorController::class, 'reorder']);

        // Checklist template management
        Route::apiResource('checklist-templates', ChecklistTemplateController::class);

        // Delegations
        Route::get('/delegations', [DelegationController::class, 'index']);
        Route::post('/delegations', [DelegationController::class, 'store']);
        Route::put('/delegations/{delegation}', [DelegationController::class, 'update']);
        Route::delete('/delegations/{delegation}', [DelegationController::class, 'destroy']);
        Route::post('/delegations/{delegation}/add-user', [DelegationController::class, 'addUser']);
        Route::put('/delegations/{delegation}/user-dates', [DelegationController::class, 'updateUserDates']);
        Route::post('/delegations/{delegation}/approve-user', [DelegationController::class, 'approveUser']);
        Route::post('/delegations/{delegation}/reject-user', [DelegationController::class, 'rejectUser']);
        Route::post('/delegations/{delegation}/remove-user', [DelegationController::class, 'removeUser']);
        Route::get('/delegations/{delegation}/logs', [DelegationController::class, 'logs']);
        Route::get('/delegations/month', [DelegationController::class, 'forMonth']);

        // Delegation Archive
        Route::get('/delegation-archive', [DelegationArchiveController::class, 'index']);
        Route::post('/delegations/{delegation}/archive', [DelegationArchiveController::class, 'archive']);
        Route::post('/delegation-archive/{archivedDelegation}/unarchive', [DelegationArchiveController::class, 'unarchive']);
        Route::get('/delegation-archive/{archivedDelegation}/logs', [DelegationArchiveController::class, 'logs']);
        Route::get('/delegation-archive/destinations', [DelegationArchiveController::class, 'destinations']);

        // Office closures
        Route::get('/office-closures', [OfficeClosureController::class, 'index']);
        Route::post('/office-closures', [OfficeClosureController::class, 'store']);
        Route::put('/office-closures/{officeClosure}', [OfficeClosureController::class, 'update']);
        Route::delete('/office-closures/{officeClosure}', [OfficeClosureController::class, 'destroy']);
        Route::get('/office-closures/conflicts', [OfficeClosureController::class, 'checkConflicts']);

        // Newbie periods
        Route::get('/newbie-periods/all', [NewbiePeriodController::class, 'all']);
        Route::get('/newbie-periods/user/{userId}', [NewbiePeriodController::class, 'forUser']);
        Route::get('/newbie-periods/month', [NewbiePeriodController::class, 'forMonth']);
        Route::post('/newbie-periods', [NewbiePeriodController::class, 'store']);
        Route::put('/newbie-periods/{newbiePeriod}', [NewbiePeriodController::class, 'update']);
        Route::delete('/newbie-periods/{newbiePeriod}', [NewbiePeriodController::class, 'destroy']);

        // Procedure tags (write ops)
        Route::post('/procedure-tags', [ProcedureTagController::class, 'store']);
        Route::put('/procedure-tags/{procedureTag}', [ProcedureTagController::class, 'update']);
        Route::delete('/procedure-tags/{procedureTag}', [ProcedureTagController::class, 'destroy']);

        // Procedures (write operations — owner check inside controller)
        Route::post('/procedures', [ProcedureController::class, 'store']);
        Route::put('/procedures/{procedure}', [ProcedureController::class, 'update']);
        Route::delete('/procedures/{procedure}', [ProcedureController::class, 'destroy']);
        Route::put('/procedures/{procedure}/tags', [ProcedureController::class, 'syncTags']);
        Route::put('/procedures/{procedure}/draft', [ProcedureController::class, 'saveDraft']);
        Route::post('/procedures/{procedure}/publish', [ProcedureController::class, 'publishDraft']);
        Route::delete('/procedures/{procedure}/draft', [ProcedureController::class, 'discardDraft']);

        // Procedure response templates (write)
        Route::post('/procedures/{procedure}/templates', [ProcedureResponseTemplateController::class, 'store']);
        Route::put('/procedure-templates/{template}', [ProcedureResponseTemplateController::class, 'update']);
        Route::delete('/procedure-templates/{template}', [ProcedureResponseTemplateController::class, 'destroy']);

        // Procedure feedback (admin operations)
        Route::get('/procedure-feedbacks', [ProcedureFeedbackController::class, 'index']);
        Route::put('/procedure-feedbacks/{feedback}', [ProcedureFeedbackController::class, 'update']);
    });

    // Schedule management (admin or schedule_maintainer)
    Route::middleware('admin:schedule')->group(function () {
        Route::get('/shift-groups', [ShiftGroupController::class, 'index']);
        Route::post('/schedule/generate', [ScheduleController::class, 'generate']);
        Route::put('/schedule/day-shifts', [ScheduleController::class, 'updateDayShifts']);
        Route::post('/schedule/assign', [ScheduleController::class, 'assign']);
        Route::delete('/schedule/unassign/{assignment}', [ScheduleController::class, 'unassign']);
        Route::put('/schedule/shifts/{shift}/checklist', [ScheduleController::class, 'updateShiftChecklist']);
        Route::put('/schedule/shifts/{shift}/times', [ScheduleController::class, 'updateShiftTimes']);
        Route::put('/schedule/shifts/{shift}/color', [ScheduleController::class, 'updateShiftColor']);
        Route::put('/schedule/shifts/{shift}/type', [ScheduleController::class, 'updateShiftType']);
        Route::put('/schedule/shift-types', [ScheduleController::class, 'updateSlotShiftTypes']);
        Route::put('/schedule/shifts/{shift}/work-mode', [ScheduleController::class, 'updateShiftWorkMode']);
        Route::get('/shift-change-requests', [ShiftChangeRequestController::class, 'index']);
        Route::put('/shift-change-requests/{id}/acknowledge', [ShiftChangeRequestController::class, 'acknowledge']);
        Route::post('/schedule/auto-assign/random', [ShiftAutoAssignController::class, 'random']);
        Route::post('/schedule/auto-assign/rotating', [ShiftAutoAssignController::class, 'rotating']);
        Route::post('/schedule/auto-assign/polish-law', [ShiftAutoAssignController::class, 'polishLaw']);
        Route::delete('/schedule/clear-month', [ShiftAutoAssignController::class, 'clearMonth']);
        Route::get('/settlement-periods', [SettlementPeriodController::class, 'show']);
        Route::get('/on-call-assignments', [OnCallController::class, 'index']);
        Route::post('/on-call-assignments', [OnCallController::class, 'store']);
        Route::delete('/on-call-assignments/{onCallAssignment}', [OnCallController::class, 'destroy']);
        Route::post('/on-call-assignments/{onCallAssignment}/callbacks', [OnCallController::class, 'storeCallback']);
        Route::delete('/on-call-callbacks/{onCallCallback}', [OnCallController::class, 'destroyCallback']);
    });
});
