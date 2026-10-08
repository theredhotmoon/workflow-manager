<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftChangeRequest;
use App\Models\ShiftChecklist;
use App\Models\ShiftGroup;
use App\Models\User;
use App\Models\ChecklistTemplate;
use App\Services\ShiftTypeChanger;
use App\Services\StreakReplay;
use App\Services\StreakTimeline;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            // Fetch shifts from ALL groups with group info
            $shifts = Shift::with(['assignments.user:id,name,email', 'assignments.changeRequests' => function ($q) {
                $q->where('status', 'pending');
            }, 'shiftChecklists.template:id,name', 'shiftGroup:id,name,color'])
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->orderBy('date')
                ->orderBy('shift_group_id')
                ->orderBy('shift_number')
                ->get();
        } else {
            $groupId = $request->shift_group_id ?? ShiftGroup::where('is_default', true)->value('id');

            $shifts = Shift::with(['assignments.user:id,name,email', 'assignments.changeRequests' => function ($q) {
                $q->where('status', 'pending');
            }, 'shiftChecklists.template:id,name'])
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
            $shiftData = [
                'id' => $shift->id,
                'shift_number' => $shift->shift_number,
                'shift_type' => $shift->shift_type,
                'start_time' => $shift->start_time ? substr($shift->start_time, 0, 5) : null,
                'end_time' => $shift->end_time ? substr($shift->end_time, 0, 5) : null,
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
                'assignments' => $shift->assignments->map(function ($a) use ($resting) {
                    $pendingRequest = $a->changeRequests->first();
                    return [
                        'id' => $a->id,
                        'user_id' => $a->user_id,
                        'user_name' => $a->user->name,
                        'rest_policy' => $a->rest_policy,
                        'in_rest' => isset($resting[$a->id]) && $a->rest_policy === null,
                        'resting_until' => $resting[$a->id] ?? null,
                        'has_change_request' => $pendingRequest !== null,
                        'change_request_reason' => $pendingRequest?->reason,
                        'change_request_id' => $pendingRequest?->id,
                        'change_request_date' => $pendingRequest?->created_at?->toIso8601String(),
                    ];
                }),
            ];

            // Add group context for all-groups mode
            if ($isAllGroups) {
                $shiftData['shift_group_id'] = $shift->shift_group_id;
                $shiftData['shift_group_name'] = $shift->shiftGroup?->name;
                $shiftData['shift_group_color'] = $shift->shiftGroup?->color;
            }

            $schedule[$dateKey][] = $shiftData;
        }

        // Get all non-admin employees for the pool
        $employees = User::orderBy('name')
            ->get(['id', 'name', 'email', 'is_admin']);

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
            'shift_group_id' => ['sometimes', 'integer', 'exists:shift_groups,id'],
            'shifts_per_day' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'shift_times' => ['sometimes', 'array'],
            'shift_times.*.start_time' => ['required_with:shift_times', 'date_format:H:i'],
            'shift_times.*.end_time' => ['required_with:shift_times', 'date_format:H:i'],
            'shift_times.*.shift_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'shift_times.*.checklists' => ['sometimes', 'array'],
            'shift_times.*.checklists.*.checklist_template_id' => ['required', 'exists:checklist_templates,id'],
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
        $request->validate([
            'work_mode' => ['required', 'string', 'in:office,remote'],
        ]);

        $shift->update(['work_mode' => $request->work_mode]);

        return response()->json(['message' => 'Work mode updated.', 'work_mode' => $shift->work_mode]);
    }

    /**
     * Update the number of shift slots for a specific day.
     */
    public function updateDayShifts(Request $request)
    {
        $request->validate([
            'date' => ['required', 'date'],
            'count' => ['required', 'integer', 'min:1', 'max:10'],
            'shift_group_id' => ['sometimes', 'integer', 'exists:shift_groups,id'],
        ]);

        $date = $request->date;
        $desiredCount = $request->count;
        $groupId = $request->input('shift_group_id') ?? ShiftGroup::where('is_default', true)->value('id');
        $currentShifts = Shift::where('date', $date)->where('shift_group_id', $groupId)->orderBy('shift_number')->get();
        $currentCount = $currentShifts->count();

        if ($desiredCount > $currentCount) {
            // Add more shift slots with default times
            $defaults = $this->defaultShiftTimes($desiredCount);
            for ($i = $currentCount + 1; $i <= $desiredCount; $i++) {
                $times = $defaults[$i - 1] ?? [];
                Shift::create([
                    'date' => $date,
                    'shift_number' => $i,
                    'shift_group_id' => $groupId,
                    'start_time' => $times['start_time'] ?? null,
                    'end_time' => $times['end_time'] ?? null,
                ]);
            }
        } elseif ($desiredCount < $currentCount) {
            // Remove excess shift slots (from the end, unassigned first)
            $toRemove = $currentShifts->slice($desiredCount);
            foreach ($toRemove as $shift) {
                $shift->delete(); // cascades to assignments
            }
        }

        // Return updated shifts for that day
        $dayShifts = Shift::with(['assignments.user:id,name', 'shiftChecklists.template:id,name'])
            ->where('date', $date)
            ->where('shift_group_id', $groupId)
            ->orderBy('shift_number')
            ->get();
        $resting = $this->restingAssignmentMap($dayShifts, Carbon::parse($date)->addDay());
        $updatedShifts = $dayShifts
            ->map(fn($s) => [
                'id' => $s->id,
                'shift_number' => $s->shift_number,
                'shift_type' => $s->shift_type,
                'start_time' => $s->start_time ? substr($s->start_time, 0, 5) : null,
                'end_time' => $s->end_time ? substr($s->end_time, 0, 5) : null,
                'color' => $s->color,
                'color_label' => $s->color_label,
                'shift_checklists' => $s->shiftChecklists->map(fn($sc) => [
                    'id' => $sc->id,
                    'checklist_template_id' => $sc->checklist_template_id,
                    'checklist_template_name' => $sc->template?->name,
                    'is_required' => $sc->is_required,
                    'deadline_minutes' => $sc->deadline_minutes,
                    'deadline_reference' => $sc->deadline_reference,
                ]),
                'assignments' => $s->assignments->map(fn($a) => [
                    'id' => $a->id,
                    'user_id' => $a->user_id,
                    'user_name' => $a->user->name,
                    'rest_policy' => $a->rest_policy,
                    'in_rest' => isset($resting[$a->id]) && $a->rest_policy === null,
                    'resting_until' => $resting[$a->id] ?? null,
                ]),
            ]);

        return response()->json([
            'date' => $date,
            'shifts' => $updatedShifts,
        ]);
    }

    /**
     * Assign a user to a shift slot.
     */
    public function assign(Request $request, StreakReplay $streakReplay)
    {
        $request->validate([
            'shift_id' => ['required', 'exists:shifts,id'],
            'user_id' => ['required', 'exists:users,id'],
            'rest_policy' => ['nullable', 'in:'.implode(',', StreakTimeline::POLICIES)],
            'later_rest_policy' => ['nullable', 'in:'.implode(',', StreakTimeline::POLICIES)],
        ]);

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
        $userId = (int) $request->user_id;

        // A rest day needs the manager's decision on how it counts (StreakTimeline policies).
        $timeline = $shift->shiftGroup ? $streakReplay->forGroup($shift->shiftGroup, [$userId]) : null;
        $window = $timeline ? $timeline->activeWindow($userId, $shift->date) : null;
        if ($window !== null && !$request->filled('rest_policy')) {
            $rule = $window['rule'];
            $restingUntil = $window['end']->toDateString();

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
        if ($affected !== [] && !$request->filled('later_rest_policy')) {
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

        $affectedIds = array_map(fn($a) => $a['assignment_id'], $affected);
        $laterPolicy = $affectedIds !== [] ? $request->input('later_rest_policy') : null;

        $assignment = DB::transaction(function () use ($shift, $userId, $restPolicy, $affectedIds, $laterPolicy) {
            $created = ShiftAssignment::create([
                'shift_id' => $shift->id,
                'user_id' => $userId,
                'rest_policy' => $restPolicy,
            ]);
            if ($affectedIds !== []) {
                ShiftAssignment::whereIn('id', $affectedIds)->update(['rest_policy' => $laterPolicy]);
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
            'updated_assignment_ids' => $affectedIds,
            'updated_rest_policy' => $laterPolicy,
        ], 201);
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
    public function unassign(ShiftAssignment $assignment)
    {
        // Auto-acknowledge any pending change requests for this assignment
        ShiftChangeRequest::where('shift_assignment_id', $assignment->id)
            ->where('status', 'pending')
            ->update(['status' => 'acknowledged']);

        $assignment->delete();

        return response()->json([
            'message' => 'Assignment removed successfully.',
        ]);
    }

    /**
     * Update checklist settings for a specific shift.
     * Accepts an array of checklists to sync.
     */
    public function updateShiftChecklist(Request $request, Shift $shift)
    {
        $request->validate([
            'checklists' => ['present', 'array'],
            'checklists.*.checklist_template_id' => ['required', 'exists:checklist_templates,id'],
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
            'shift_group_id' => ['required', 'integer', 'exists:shift_groups,id'],
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
