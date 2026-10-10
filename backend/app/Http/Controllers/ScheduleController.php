<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftChangeRequest;
use App\Models\ShiftChecklist;
use App\Models\ShiftGroup;
use App\Models\User;
use App\Models\ChecklistTemplate;
use App\Services\RestPolicyJanitor;
use App\Services\ShiftTypeChanger;
use App\Services\StreakReplay;
use App\Services\StreakTimeline;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Month;

class ScheduleController extends Controller
{
    /**
     * Get all shifts and assignments for a given month.
     */
    public function index(Request $request)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'shift_group_id' => ['sometimes'],
        ]);

        $start = Month::start($request->month);
        $end = $start->copy()->endOfMonth();

        $isAllGroups = $request->input('shift_group_id') === 'all';

        if ($isAllGroups) {
            // Fetch shifts from all groups that aren't archived, with group info
            $shifts = Shift::with(array_merge($this->shiftPayloadRelations(), ['shiftGroup:id,name,color']))
                ->where(fn ($q) => $q->whereNull('shift_group_id')
                    ->orWhereIn('shift_group_id', ShiftGroup::notArchived()->select('id')))
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->orderBy('date')
                ->orderBy('shift_group_id')
                ->orderBy('shift_number')
                ->get();
        } else {
            $groupId = $request->shift_group_id ?? ShiftGroup::where('is_default', true)->value('id');

            $shifts = Shift::with($this->shiftPayloadRelations())
                ->where('shift_group_id', $groupId)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->orderBy('date')
                ->orderBy('shift_number')
                ->get();
        }

        $resting = $this->restingAssignmentMap($shifts, $end->copy()->addDay());

        // Group shifts by date
        $schedule = [];
        foreach ($shifts as $shift) {
            $dateKey = $shift->date->format('Y-m-d');
            if (!isset($schedule[$dateKey])) {
                $schedule[$dateKey] = [];
            }
            $shiftData = $this->shiftPayload($shift, $resting);

            // Add group context for all-groups mode
            if ($isAllGroups) {
                $shiftData['shift_group_id'] = $shift->shift_group_id;
                $shiftData['shift_group_name'] = $shift->shiftGroup?->name;
                $shiftData['shift_group_color'] = $shift->shiftGroup?->color;
            }

            $schedule[$dateKey][] = $shiftData;
        }

        // Employees for the pool (deactivated users still show by name on their past assignments)
        $employees = User::active()->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        return response()->json([
            'schedule' => $schedule,
            'employees' => $employees,
            'month' => $request->month,
            'shift_group_id' => $isAllGroups ? 'all' : (int) ($groupId ?? null),
        ]);
    }

    /**
     * Generate shift slots for an entire month.
     */
    public function generate(Request $request)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'shift_group_id' => ['sometimes', 'integer', ShiftGroup::activeExistsRule()],
            'shifts_per_day' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'shift_times' => ['sometimes', 'array'],
            'shift_times.*.start_time' => ['required_with:shift_times', 'date_format:H:i'],
            'shift_times.*.end_time' => ['required_with:shift_times', 'date_format:H:i'],
            'shift_times.*.shift_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'shift_times.*.checklists' => ['sometimes', 'array'],
            'shift_times.*.checklists.*.checklist_template_id' => ['required', Rule::exists('checklist_templates', 'id')->whereNull('archived_at')],
            'shift_times.*.checklists.*.is_required' => ['required', 'boolean'],
            'shift_times.*.checklists.*.deadline_minutes' => ['nullable', 'integer', 'min:1'],
            'shift_times.*.checklists.*.deadline_reference' => ['nullable', 'in:shift_start,shift_end'],
            'shift_times.*.color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'shift_times.*.color_label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'work_mode_weekday' => ['sometimes', 'string', 'in:office,remote'],
            'work_mode_weekend' => ['sometimes', 'string', 'in:office,remote'],
        ]);

        $groupId = $request->input('shift_group_id') ?? ShiftGroup::where('is_default', true)->value('id');
        $shiftsPerDay = $request->input('shifts_per_day', 3);
        $shiftTimes = $request->input('shift_times', $this->defaultShiftTimes($shiftsPerDay));
        $workModeWeekday = $request->input('work_mode_weekday', 'office');
        $workModeWeekend = $request->input('work_mode_weekend', 'office');
        $start = Month::start($request->month);
        $end = $start->copy()->endOfMonth();

        $created = 0;
        // One transaction, with the group locked first (see ShiftGroup's lock order): an archive
        // that lands after validation is seen here and refused, and the month is created whole or not at all.
        DB::transaction(function () use ($start, $end, $groupId, $shiftsPerDay, $shiftTimes, $workModeWeekday, $workModeWeekend, &$created) {
            ShiftGroup::lockActiveOrFail($groupId);
            $current = $start->copy();

            while ($current <= $end) {
                $dateStr = $current->toDateString();
                $existing = Shift::where('date', $dateStr)->where('shift_group_id', $groupId)->count();

                // Only create slots that don't exist yet
                for ($i = $existing + 1; $i <= $shiftsPerDay; $i++) {
                    $times = $shiftTimes[$i - 1] ?? $this->defaultShiftTimes($shiftsPerDay)[$i - 1] ?? [];
                    $isWeekend = $current->isWeekend();
                    $shift = Shift::firstOrCreate(
                        ['date' => $dateStr, 'shift_number' => $i, 'shift_group_id' => $groupId],
                        [
                            'start_time' => $times['start_time'] ?? null,
                            'end_time' => $times['end_time'] ?? null,
                            'work_mode' => $isWeekend ? $workModeWeekend : $workModeWeekday,
                            'color' => $times['color'] ?? null,
                            'color_label' => $times['color_label'] ?? null,
                            'shift_type' => $times['shift_type'] ?? null,
                        ]
                    );

                    // Create checklist assignments from template
                    if ($shift->wasRecentlyCreated && !empty($times['checklists'])) {
                        foreach ($times['checklists'] as $order => $cl) {
                            ShiftChecklist::create([
                                'shift_id' => $shift->id,
                                'checklist_template_id' => $cl['checklist_template_id'],
                                'is_required' => $cl['is_required'] ?? false,
                                'deadline_minutes' => $cl['deadline_minutes'] ?? null,
                                'deadline_reference' => $cl['deadline_reference'] ?? 'shift_start',
                                'sort_order' => $order,
                            ]);
                        }
                    }

                    $created++;
                }

                $current->addDay();
            }
        });

        return response()->json([
            'message' => "Generated {$created} shift slots for {$request->month}.",
            'created' => $created,
        ]);
    }

    /**
     * Toggle a shift's work mode (office/remote).
     */
    public function updateShiftWorkMode(Request $request, Shift $shift)
    {
        $this->refuseArchivedGroup($shift);
        $request->validate([
            'work_mode' => ['required', 'string', 'in:office,remote'],
        ]);

        $shift->update(['work_mode' => $request->work_mode]);

        return response()->json(['message' => 'Work mode updated.', 'work_mode' => $shift->work_mode]);
    }

    /**
     * Update the number of shift slots for a specific day.
     *
     * Growing adds slots with default times. Shrinking removes empty slots first (latest first);
     * only when there are not enough empty slots are staffed ones removed, and then only with
     * confirm_remove_staffed: otherwise the answer is 409 staffed_shifts_removal listing them
     * (with expected_remove_ids from that list, a changed list asks again). The remaining slots
     * are renumbered 1..n in their order. Removing a slot deletes its assignments and the slot's
     * checklist settings; change requests stay (pending ones acknowledged, as an unassign does),
     * with their shift and assignment links nulled but the date and slot kept; handover
     * submissions keep their date and template (shift_id is set null). Everything runs in one
     * transaction.
     */
    public function updateDayShifts(Request $request, RestPolicyJanitor $janitor)
    {
        $request->validate([
            'date' => ['required', 'date'],
            'count' => ['required', 'integer', 'min:1', 'max:10'],
            'shift_group_id' => ['sometimes', 'integer', ShiftGroup::activeExistsRule()],
            'confirm_remove_staffed' => ['sometimes', 'boolean'],
            'expected_remove_ids' => ['sometimes', 'array'],
            'expected_remove_ids.*' => ['integer'],
        ]);

        $date = Carbon::parse($request->date)->toDateString();
        $desiredCount = (int) $request->count;
        $groupId = $request->input('shift_group_id') ?? ShiftGroup::where('is_default', true)->value('id');

        $refusal = DB::transaction(function () use ($request, $date, $desiredCount, $groupId, $janitor) {
            // The group first (ShiftGroup's lock order): an archive after validation is refused here.
            ShiftGroup::lockActiveOrFail($groupId);
            // Locked so a concurrent assign or count change waits for this one.
            $currentShifts = Shift::where('date', $date)->where('shift_group_id', $groupId)
                ->orderBy('shift_number')->lockForUpdate()->get();
            $currentCount = $currentShifts->count();

            if ($desiredCount > $currentCount) {
                // Add more shift slots with default times: each new slot takes its positional entry
                // of the target preset, unless the day already has those times (after an
                // empty-first removal, e.g. a second night); then the first preset entry the day
                // doesn't have (the removed afternoon). Failing both, the positional entry.
                $defaults = $this->defaultShiftTimes($desiredCount);
                $key = fn (?string $start, ?string $end) => substr((string) $start, 0, 5) . '-' . substr((string) $end, 0, 5);
                $used = $currentShifts->map(fn (Shift $s) => $key($s->start_time, $s->end_time))->flip()->all();
                for ($i = $currentCount + 1; $i <= $desiredCount; $i++) {
                    $times = $defaults[$i - 1] ?? [];
                    if ($times !== [] && isset($used[$key($times['start_time'], $times['end_time'])])) {
                        foreach ($defaults as $candidate) {
                            if (!isset($used[$key($candidate['start_time'], $candidate['end_time'])])) {
                                $times = $candidate;
                                break;
                            }
                        }
                    }
                    if ($times !== []) {
                        $used[$key($times['start_time'], $times['end_time'])] = true;
                    }
                    Shift::create([
                        'date' => $date,
                        'shift_number' => $i,
                        'shift_group_id' => $groupId,
                        'start_time' => $times['start_time'] ?? null,
                        'end_time' => $times['end_time'] ?? null,
                    ]);
                }

                return null;
            }

            if ($desiredCount === $currentCount) {
                return null;
            }

            $toRemove = $this->slotsToRemove($currentShifts, $currentCount - $desiredCount);
            $staffed = $toRemove->filter(fn (Shift $shift) => $shift->assignments->isNotEmpty())->values();

            if ($staffed->isNotEmpty()) {
                $staffedIds = $staffed->pluck('id')->sort()->values()->all();
                $expected = $request->has('expected_remove_ids')
                    ? collect($request->input('expected_remove_ids'))->map(fn ($id) => (int) $id)->sort()->values()->all()
                    : null;
                if (!$request->boolean('confirm_remove_staffed') || ($expected !== null && $expected !== $staffedIds)) {
                    return $this->staffedRemovalRefusal($staffed);
                }
            }

            $userIds = $toRemove->flatMap(fn (Shift $shift) => $shift->assignments->pluck('user_id'))->unique()->values()->all();
            // Their assignment rows are locked first, so a change request being filed for one waits and
            // then finds it gone (instead of being left pending on a removed assignment).
            ShiftAssignment::whereIn('shift_id', $toRemove->pluck('id'))->orderBy('id')->lockForUpdate()->get(['id']);
            // Removing the slot settles its pending change requests, which stay on record.
            ShiftChangeRequest::whereIn('shift_id', $toRemove->pluck('id'))
                ->where('status', 'pending')
                ->update(['status' => 'acknowledged']);
            Shift::whereIn('id', $toRemove->pluck('id'))->delete(); // cascades to assignments

            // Close the gaps: the kept slots become 1..n in their order (moving down only, so
            // every target number is already free).
            $kept = $currentShifts->reject(fn (Shift $shift) => $toRemove->contains('id', $shift->id))->values();
            foreach ($kept as $index => $shift) {
                if ((int) $shift->shift_number !== $index + 1) {
                    $shift->update(['shift_number' => $index + 1]);
                    // The requests' slot snapshot follows the shift, so if the shift is removed
                    // later the request names the slot the schedule last showed it as.
                    ShiftChangeRequest::where('shift_id', $shift->id)->update(['shift_number' => $index + 1]);
                }
            }

            // Later shifts the removed assignments kept in rest lose their policy.
            $group = ShiftGroup::find($groupId);
            if ($group && $userIds !== []) {
                $janitor->clearUncovered($group, $userIds);
            }

            return null;
        });

        if ($refusal !== null) {
            return $refusal;
        }

        // Return updated shifts for that day, in the same shape as index()
        $dayShifts = Shift::with($this->shiftPayloadRelations())
            ->where('date', $date)
            ->where('shift_group_id', $groupId)
            ->orderBy('shift_number')
            ->get();
        $resting = $this->restingAssignmentMap($dayShifts, Carbon::parse($date)->addDay());
        $updatedShifts = $dayShifts->map(fn($s) => $this->shiftPayload($s, $resting));

        return response()->json([
            'date' => $date,
            'shifts' => $updatedShifts,
        ]);
    }

    /**
     * Assign a user to a shift slot. With move_from_assignment_id (a chip dragged from another shift)
     * the old assignment is removed and the new one created in one transaction: if the assign is
     * refused (409 rest-day question, 422), nothing changes and the employee stays where they were.
     */
    public function assign(Request $request, StreakReplay $streakReplay, RestPolicyJanitor $janitor)
    {
        $request->validate([
            'shift_id' => ['required', 'exists:shifts,id'],
            'user_id' => ['required', User::activeExistsRule()],
            'rest_policy' => ['nullable', 'in:'.implode(',', StreakTimeline::POLICIES)],
            'later_rest_policy' => ['nullable', 'in:'.implode(',', StreakTimeline::POLICIES)],
            'expected_affected_ids' => ['nullable', 'array'],
            'expected_affected_ids.*' => ['integer'],
            // Only the assigned user's own assignment can be the source of their move (re-checked below).
            'move_from_assignment_id' => ['nullable', 'integer', Rule::exists('shift_assignments', 'id')
                ->where('user_id', (int) $request->input('user_id'))],
        ]);

        if (!$request->filled('move_from_assignment_id')) {
            return $this->placeAssignment($request, $streakReplay, $janitor);
        }

        $sourceId = (int) $request->input('move_from_assignment_id');

        return DB::transaction(function () use ($request, $streakReplay, $janitor, $sourceId) {
            // Both groups first, in id order (ShiftGroup's lock order), then the source row: an
            // archive of either group that landed after the checks above is seen and refused.
            $sourceShift = ShiftAssignment::with('shift:id,shift_group_id')->find($sourceId)?->shift;
            $targetShift = Shift::find($request->input('shift_id'), ['id', 'shift_group_id']);
            $this->lockGroupsOrRefuse(array_filter([$sourceShift, $targetShift]));
            // Locked so two concurrent moves of the same chip can't both succeed.
            $source = ShiftAssignment::lockForUpdate()->find($sourceId);
            if (!$source) {
                throw ValidationException::withMessages(['move_from_assignment_id' => 'The assignment to move no longer exists.']);
            }
            // Re-checked on the locked row (the exists rule above ran before the lock).
            if ((int) $source->user_id !== (int) $request->input('user_id')) {
                throw ValidationException::withMessages(['move_from_assignment_id' => 'The assignment to move belongs to another employee.']);
            }
            // Group access is not per group: whoever may manage the schedule manages every group.
            // Checked here too so the move path doesn't rely on the route middleware alone.
            // Defence in depth: the route's admin:schedule middleware already answers 403 first.
            if (!$request->user()?->canManageSchedule()) {
                abort(403, 'You may not move assignments in this group.');
            }
            if ((int) $source->shift_id === (int) $request->input('shift_id')) {
                throw ValidationException::withMessages(['move_from_assignment_id' => 'The assignment is already on this shift.']);
            }

            $this->removeAssignment($source, $janitor);
            $response = $this->placeAssignment($request, $streakReplay, $janitor);
            if ($response->getStatusCode() >= 400) {
                // Rolls the removal back; the handler renders the 409/422 unchanged.
                throw new HttpResponseException($response);
            }

            return $response->setData(array_merge($response->getData(true), [
                'moved_from_assignment_id' => $sourceId,
            ]));
        });
    }

    /**
     * The assign itself, against the current DB state (for a move: with the source already gone).
     */
    private function placeAssignment(Request $request, StreakReplay $streakReplay, RestPolicyJanitor $janitor): JsonResponse
    {
        // Check if user is already assigned to this shift
        $existing = ShiftAssignment::where('shift_id', $request->shift_id)
            ->where('user_id', $request->user_id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'User is already assigned to this shift.',
                'assignment' => $existing,
            ], 422);
        }

        $shift = Shift::with('shiftGroup')->findOrFail($request->shift_id);
        $this->refuseArchivedGroup($shift);
        $userId = (int) $request->user_id;

        // A rest day needs the manager's decision on how it counts (StreakTimeline policies).
        $timeline = $shift->shiftGroup ? $streakReplay->forGroup($shift->shiftGroup, [$userId]) : null;
        $window = $timeline ? $timeline->activeWindow($userId, $shift->date) : null;
        if ($window !== null && !$request->filled('rest_policy')) {
            // Report the window's final end (later extends_rest included), as the ⚠ chip does.
            $final = $timeline->finalWindowAt($userId, $shift->date);
            $rule = $final['rule'];
            $restingUntil = $final['end']->toDateString();

            return response()->json([
                'code' => 'rest_window_conflict',
                'message' => "This employee is resting until {$restingUntil} ({$rule->shift_type}: {$rule->streak_length} in a row, then {$rule->rest_days} days off).",
                'resting_until' => $restingUntil,
                'rule' => [
                    'shift_type' => $rule->shift_type,
                    'streak_length' => (int) $rule->streak_length,
                    'rest_days' => (int) $rule->rest_days,
                ],
            ], 409);
        }

        $restPolicy = $window !== null ? $request->input('rest_policy') : null;

        // Would this shift put the employee's existing later shifts into rest?
        $affected = $timeline
            ? $timeline->newlyResting($userId, $shift->shift_type, $shift->date, $shift->shift_number, $restPolicy)
            : [];
        // The confirm carries the shifts the manager was shown; if that list is stale, ask again.
        $affectedIds = array_map(fn($a) => $a['assignment_id'], $affected);
        $expectedIds = $request->has('expected_affected_ids')
            ? array_map('intval', (array) $request->input('expected_affected_ids'))
            : null;
        $stale = false;
        if ($expectedIds !== null) {
            $fresh = $affectedIds;
            sort($fresh);
            sort($expectedIds);
            $stale = $fresh !== $expectedIds;
        }
        if ($affected !== [] && (!$request->filled('later_rest_policy') || $stale)) {
            $rule = $affected[0]['rule'];
            $dateList = array_values(array_unique(array_map(fn($a) => $a['date'], $affected)));
            $last = array_pop($dateList);
            $dates = $dateList === [] ? $last : implode(', ', $dateList).' and '.$last;
            $dayNoun = $dateList === [] ? 'a rest day' : 'rest days';

            return response()->json([
                'code' => 'creates_rest_conflict',
                'message' => "Assigning this shift makes {$dates} {$dayNoun} for this employee ({$rule->shift_type}: {$rule->streak_length} in a row, then {$rule->rest_days} days off).",
                'affected' => array_map(fn($a) => [
                    'assignment_id' => $a['assignment_id'],
                    'date' => $a['date'],
                    'shift_type' => $a['shift_type'],
                    'resting_until' => $a['resting_until'],
                ], $affected),
                'rule' => [
                    'shift_type' => $rule->shift_type,
                    'streak_length' => (int) $rule->streak_length,
                    'rest_days' => (int) $rule->rest_days,
                ],
            ], 409);
        }

        // The chosen policy applies to the whole chain: stamping it can push further shifts into rest.
        $laterPolicy = $affectedIds !== [] ? $request->input('later_rest_policy') : null;
        $updatedIds = $laterPolicy !== null
            ? $timeline->cascadePolicy($userId, $shift->shift_type, $shift->date, $shift->shift_number, $restPolicy, $laterPolicy)
            : [];

        $assignment = DB::transaction(function () use ($shift, $userId, $restPolicy, $updatedIds, $laterPolicy, $janitor) {
            // The group first (ShiftGroup's lock order), re-checked: an archive since the check
            // above is refused here instead of leaving future work in an archived group.
            $this->lockGroupsOrRefuse([$shift]);
            // Re-checked under a row lock: a concurrent day-count reduction may have deleted the
            // slot since it was looked up (that removal holds the lock until it commits). Without
            // this the insert fails on the foreign key with a 500.
            if (!Shift::whereKey($shift->id)->lockForUpdate()->first(['id'])) {
                throw new HttpResponseException(response()->json(['message' => 'This shift no longer exists.'], 404));
            }
            $created = ShiftAssignment::create([
                'shift_id' => $shift->id,
                'user_id' => $userId,
                'rest_policy' => $restPolicy,
            ]);
            if ($updatedIds !== []) {
                ShiftAssignment::whereIn('id', $updatedIds)->update(['rest_policy' => $laterPolicy]);
            }
            // A new shift can also move a window off a later shift that had a policy.
            if ($shift->shiftGroup) {
                $janitor->clearUncovered($shift->shiftGroup, [$userId]);
            }

            return $created;
        });
        $assignment->load('user:id,name');

        return response()->json([
            'id' => $assignment->id,
            'shift_id' => $assignment->shift_id,
            'user_id' => $assignment->user_id,
            'user_name' => $assignment->user->name,
            'rest_policy' => $assignment->rest_policy,
            'updated_assignment_ids' => $updatedIds,
            'updated_rest_policy' => $laterPolicy,
        ], 201);
    }

    /**
     * Relations shiftPayload() reads; eager-load them on every query whose shifts it serialises.
     */
    private function shiftPayloadRelations(): array
    {
        return [
            'assignments.user:id,name',
            'assignments.changeRequests' => fn ($q) => $q->where('status', 'pending'),
            'shiftChecklists.template:id,name',
        ];
    }

    /**
     * One shift as the Schedule screen reads it. Every endpoint that returns schedule shifts uses
     * this, so a re-rendered day keeps the same keys (work mode, day-off request chip, ...).
     *
     * @param  array<int, string>  $resting  restingAssignmentMap() for these shifts
     */
    private function shiftPayload(Shift $shift, array $resting): array
    {
        $viewer = User::viewer();

        return [
            'id' => $shift->id,
            'shift_number' => $shift->shift_number,
            'shift_type' => $shift->shift_type,
            'start_time' => $shift->start_time ? substr($shift->start_time, 0, 5) : null,
            'end_time' => $shift->end_time ? substr($shift->end_time, 0, 5) : null,
            'work_mode' => $shift->work_mode ?? 'office',
            'color' => $shift->color,
            'color_label' => $shift->color_label,
            'shift_checklists' => $shift->shiftChecklists->map(fn($sc) => [
                'id' => $sc->id,
                'checklist_template_id' => $sc->checklist_template_id,
                'checklist_template_name' => $sc->template?->name,
                'is_required' => $sc->is_required,
                'deadline_minutes' => $sc->deadline_minutes,
                'deadline_reference' => $sc->deadline_reference,
            ]),
            'assignments' => $shift->assignments->map(function ($a) use ($resting, $viewer) {
                $pendingRequest = $a->changeRequests->first();
                return [
                    'id' => $a->id,
                    'user_id' => $a->user_id,
                    'user_name' => $a->user->name,
                    'rest_policy' => $a->rest_policy,
                    'in_rest' => isset($resting[$a->id]) && $a->rest_policy === null,
                    'resting_until' => $resting[$a->id] ?? null,
                    'has_change_request' => $pendingRequest !== null,
                    // That a request exists is shown to everyone; why, only to the requester and
                    // schedule managers (L2).
                    'change_request_reason' => $pendingRequest?->reasonVisibleTo($viewer) ? $pendingRequest->reason : null,
                    'change_request_id' => $pendingRequest?->id,
                    'change_request_date' => $pendingRequest?->created_at?->toIso8601String(),
                ];
            }),
        ];
    }

    /**
     * assignment_id => resting_until for every assignment in $shifts that sits inside a streak rest window.
     * Coverage of a day depends only on earlier assignments, so replaying up to $until is enough.
     *
     * @return array<int, string>
     */
    private function restingAssignmentMap(Collection $shifts, Carbon $until): array
    {
        $map = [];
        foreach ($shifts->whereNotNull('shift_group_id')->groupBy('shift_group_id') as $groupId => $groupShifts) {
            $group = ShiftGroup::find($groupId);
            if (!$group) {
                continue;
            }
            $userIds = $groupShifts->flatMap(fn($s) => $s->assignments->pluck('user_id'))->unique()->values()->all();
            if ($userIds === []) {
                continue;
            }
            $timeline = app(StreakReplay::class)->forGroup($group, $userIds, $until);
            foreach ($userIds as $userId) {
                $map += $timeline->restingAssignments((int) $userId);
            }
        }

        return $map;
    }

    /**
     * Remove a user from a shift slot.
     */
    public function unassign(ShiftAssignment $assignment, RestPolicyJanitor $janitor)
    {
        $this->refuseArchivedGroup($assignment->shift);
        $this->removeAssignment($assignment, $janitor);

        return response()->json([
            'message' => 'Assignment removed successfully.',
        ]);
    }

    /**
     * An archived group's schedule is history: nobody is assigned, unassigned or re-timed in it
     * until the group is restored (422).
     */
    private function refuseArchivedGroup(?Shift $shift): void
    {
        if ($shift?->shiftGroup?->isArchived()) {
            $this->throwArchivedGroup($shift->shiftGroup);
        }
    }

    /**
     * Inside a write's transaction: locks the shifts' groups FOR SHARE (ShiftGroup's lock order:
     * first, in id order) and refuses as refuseArchivedGroup() does if one was archived since the
     * request checked it.
     *
     * @param  array<Shift>  $shifts
     */
    private function lockGroupsOrRefuse(array $shifts): void
    {
        $groups = ShiftGroup::lockShared(array_map(fn (Shift $shift) => $shift->shift_group_id, $shifts));
        foreach ($groups as $group) {
            if ($group->isArchived()) {
                $this->throwArchivedGroup($group);
            }
        }
    }

    private function throwArchivedGroup(ShiftGroup $group): never
    {
        throw new HttpResponseException(response()->json([
            'message' => "This shift belongs to the archived shift group \"{$group->name}\". Restore the group to change its schedule.",
        ], 422));
    }

    /**
     * Deletes an assignment with its side effects (unassign, and the source of a move).
     */
    private function removeAssignment(ShiftAssignment $assignment, RestPolicyJanitor $janitor): void
    {
        $group = $assignment->shift?->shiftGroup;
        DB::transaction(function () use ($assignment, $group, $janitor) {
            // Group, then the assignment row (ShiftGroup's lock order). The row lock makes a change
            // request being filed for it wait, and then find it gone, instead of being left pending.
            $this->lockGroupsOrRefuse(array_filter([$assignment->shift]));
            if (!ShiftAssignment::whereKey($assignment->id)->lockForUpdate()->first(['id'])) {
                return;
            }
            // Pending change requests on this assignment are settled; they stay on record with
            // their assignment link nulled (FK nullOnDelete).
            ShiftChangeRequest::where('shift_assignment_id', $assignment->id)
                ->where('status', 'pending')
                ->update(['status' => 'acknowledged']);

            $assignment->delete();
            // Later shifts this one kept in rest lose their policy.
            if ($group) {
                $janitor->clearUncovered($group, [(int) $assignment->user_id]);
            }
        });
    }

    /**
     * Update checklist settings for a specific shift.
     * Accepts an array of checklists to sync.
     */
    public function updateShiftChecklist(Request $request, Shift $shift)
    {
        $this->refuseArchivedGroup($shift);
        // An archived template can't be newly required; one already on this shift may stay when it's re-saved.
        $attachedIds = $shift->shiftChecklists()->pluck('checklist_template_id')->all();
        $request->validate([
            'checklists' => ['present', 'array'],
            'checklists.*.checklist_template_id' => ['required', Rule::exists('checklist_templates', 'id')
                ->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', $attachedIds))],
            'checklists.*.is_required' => ['required', 'boolean'],
            'checklists.*.deadline_minutes' => ['nullable', 'integer', 'min:1'],
            'checklists.*.deadline_reference' => ['nullable', 'in:shift_start,shift_end'],
        ]);

        // Sync: delete existing, insert new
        $shift->shiftChecklists()->delete();

        foreach ($request->checklists as $i => $item) {
            ShiftChecklist::create([
                'shift_id' => $shift->id,
                'checklist_template_id' => $item['checklist_template_id'],
                'is_required' => $item['is_required'],
                'deadline_minutes' => $item['deadline_minutes'] ?? null,
                'deadline_reference' => $item['deadline_reference'] ?? 'shift_start',
                'sort_order' => $i,
            ]);
        }

        $shift->load('shiftChecklists.template:id,name');

        return response()->json([
            'id' => $shift->id,
            'shift_checklists' => $shift->shiftChecklists->map(fn($sc) => [
                'id' => $sc->id,
                'checklist_template_id' => $sc->checklist_template_id,
                'checklist_template_name' => $sc->template?->name,
                'is_required' => $sc->is_required,
                'deadline_minutes' => $sc->deadline_minutes,
                'deadline_reference' => $sc->deadline_reference,
                'sort_order' => $sc->sort_order,
            ]),
        ]);
    }

    /**
     * Update start/end times for a specific shift.
     */
    public function updateShiftTimes(Request $request, Shift $shift)
    {
        $this->refuseArchivedGroup($shift);
        $request->validate([
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
        ]);

        $shift->update([
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
        ]);

        return response()->json([
            'id' => $shift->id,
            'start_time' => substr($shift->start_time, 0, 5),
            'end_time' => substr($shift->end_time, 0, 5),
        ]);
    }

    /**
     * Update color and color_label for a specific shift.
     */
    public function updateShiftColor(Request $request, Shift $shift)
    {
        $this->refuseArchivedGroup($shift);
        $request->validate([
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'color_label' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $shift->update([
            'color' => $request->input('color'),
            'color_label' => $request->input('color_label'),
        ]);

        return response()->json([
            'id' => $shift->id,
            'color' => $shift->color,
            'color_label' => $shift->color_label,
        ]);
    }

    /**
     * Set one shift's type, re-booking streak counters of anyone assigned to it.
     */
    public function updateShiftType(Request $request, Shift $shift, ShiftTypeChanger $changer)
    {
        $this->refuseArchivedGroup($shift);
        $request->validate([
            'shift_type' => ['present', 'nullable', 'string', 'max:50'],
        ]);

        $result = $changer->change(collect([$shift]), $request->input('shift_type'));
        $shift->refresh();

        return response()->json([
            'id' => $shift->id,
            'shift_type' => $shift->shift_type,
            'rebooked' => $result['rebooked'],
        ]);
    }

    /**
     * Set the type of one slot (shift_number) across a group's month, re-booking streak counters.
     */
    public function updateSlotShiftTypes(Request $request, ShiftTypeChanger $changer)
    {
        $request->validate([
            'shift_group_id' => ['required', 'integer', ShiftGroup::activeExistsRule()],
            'month' => ['required', 'date_format:Y-m'],
            'shift_number' => ['required', 'integer', 'min:1'],
            'shift_type' => ['present', 'nullable', 'string', 'max:50'],
        ]);

        $start = Month::start($request->month);
        $end = $start->copy()->endOfMonth();

        $shifts = Shift::where('shift_group_id', $request->shift_group_id)
            ->where('shift_number', $request->shift_number)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();

        return response()->json($changer->change($shifts, $request->input('shift_type')));
    }

    /**
     * Which slots a reduction by $count removes: empty slots first, then staffed ones, each
     * group from the end of the day. Loads assignments (with users and pending change requests).
     *
     * @return Collection<int, Shift>
     */
    private function slotsToRemove(Collection $shifts, int $count): Collection
    {
        $shifts->load([
            'assignments.user:id,name',
            'assignments.changeRequests' => fn ($q) => $q->where('status', 'pending'),
        ]);
        [$staffed, $empty] = $shifts->partition(fn (Shift $shift) => $shift->assignments->isNotEmpty());

        return $empty->sortByDesc('shift_number')
            ->concat($staffed->sortByDesc('shift_number'))
            ->take($count)
            ->values();
    }

    /**
     * The 409 asking the manager to confirm removing staffed slots.
     *
     * @param  Collection<int, Shift>  $staffed
     */
    private function staffedRemovalRefusal(Collection $staffed): JsonResponse
    {
        $shifts = $staffed->sortBy('shift_number')->values()->map(fn (Shift $shift) => [
            'id' => $shift->id,
            'date' => $shift->date->format('Y-m-d'),
            'shift_number' => $shift->shift_number,
            'start_time' => $shift->start_time ? substr($shift->start_time, 0, 5) : null,
            'end_time' => $shift->end_time ? substr($shift->end_time, 0, 5) : null,
            'assigned_users' => $shift->assignments->map(fn ($a) => $a->user?->name)->filter()->values()->all(),
            'pending_change_requests' => $shift->assignments->sum(fn ($a) => $a->changeRequests->count()),
        ])->all();

        $n = count($shifts);

        return response()->json([
            'code' => 'staffed_shifts_removal',
            'message' => $n === 1
                ? 'Removing this slot would delete a staffed shift. Confirm to remove it with its assignments. The remaining slots are renumbered 1..n.'
                : "Removing these slots would delete {$n} staffed shifts. Confirm to remove them with their assignments. The remaining slots are renumbered 1..n.",
            'shifts' => $shifts,
        ], 409);
    }

    /**
     * Generate default shift times by splitting 24h evenly.
     */
    private function defaultShiftTimes(int $count): array
    {
        // Common presets
        $presets = [
            1 => [['start_time' => '00:00', 'end_time' => '00:00']], // 24h
            2 => [
                ['start_time' => '06:00', 'end_time' => '18:00'],
                ['start_time' => '18:00', 'end_time' => '06:00'],
            ],
            3 => [
                ['start_time' => '06:00', 'end_time' => '14:00'],
                ['start_time' => '14:00', 'end_time' => '22:00'],
                ['start_time' => '22:00', 'end_time' => '06:00'],
            ],
        ];

        if (isset($presets[$count])) {
            return $presets[$count];
        }

        // For other counts, split 24h evenly starting at 06:00
        $hoursPerShift = intdiv(24, $count);
        $times = [];
        $startMinutes = 6 * 60; // Start at 06:00
        for ($i = 0; $i < $count; $i++) {
            $endMinutes = $startMinutes + $hoursPerShift * 60;
            $times[] = [
                'start_time' => sprintf('%02d:%02d', ($startMinutes / 60) % 24, $startMinutes % 60),
                'end_time' => sprintf('%02d:%02d', ($endMinutes / 60) % 24, $endMinutes % 60),
            ];
            $startMinutes = $endMinutes;
        }
        return $times;
    }
}
