<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\User;
use App\Models\UserAvailability;
use App\Services\StreakReplay;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Support\Month;

class ShiftAutoAssignController extends Controller
{
    /**
     * Delete all shifts + assignments for a month.
     */
    public function clearMonth(Request $request)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'shift_group_id' => ['sometimes', 'integer', 'exists:shift_groups,id'],
        ]);

        $start = Month::start($request->month);
        $end = $start->copy()->endOfMonth();
        $groupId = $request->input('shift_group_id') ?? ShiftGroup::where('is_default', true)->value('id');

        $shifts = Shift::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('shift_group_id', $groupId)
            ->with('assignments')
            ->get();

        $totalShifts = $shifts->count();
        $totalAssignments = 0;

        foreach ($shifts as $shift) {
            $totalAssignments += $shift->assignments->count();
            $shift->assignments()->delete();
            $shift->delete();
        }

        return response()->json([
            'message' => "Deleted {$totalShifts} shifts and {$totalAssignments} assignments for {$request->month}.",
        ]);
    }

    /**
     * Random Coverage Mode
     * Assigns random users to each shift, respecting availability and no back-to-back rule.
     */
    public function random(Request $request)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'shift_group_id' => ['sometimes', 'integer', 'exists:shift_groups,id'],
            'users_per_shift_weekday' => ['required', 'integer', 'min:1', 'max:20'],
            'users_per_shift_weekend' => ['required', 'integer', 'min:1', 'max:20'],
            'player_mode' => ['sometimes', 'in:all,include,exclude'],
            'player_ids' => ['sometimes', 'array'],
            'player_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $start = Month::start($request->month);
        $end = $start->copy()->endOfMonth();
        $groupId = $request->input('shift_group_id') ?? ShiftGroup::where('is_default', true)->value('id');

        // Load shifts ordered by date and shift_number
        $shifts = Shift::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('shift_group_id', $groupId)
            ->orderBy('date')
            ->orderBy('shift_number')
            ->with('assignments')
            ->get();

        if ($shifts->isEmpty()) {
            return response()->json(['message' => 'No shifts found for this month. Generate shifts first.'], 422);
        }

        $employees = User::where('is_admin', false)->orderBy('name')->get();
        $employees = $this->applyPlayerFilter($employees, $request);

        if ($employees->isEmpty()) {
            return response()->json(['message' => 'No employees found to assign.'], 422);
        }

        $blockedMap = $this->buildBlockedMap($employees->pluck('id')->toArray(), $request->month);

        $totalAssigned = 0;
        $warnings = [];
        $shiftsArray = $shifts->toArray();
        $shiftsById = [];
        foreach ($shifts as $shift) {
            $shiftsById[$shift->id] = $shift;
        }

        // Track assignments as we go so adjacent checks work for newly created assignments
        $assignmentTracker = []; // dateStr:shiftNumber => [user_ids]
        foreach ($shifts as $shift) {
            $dateStr = $shift->date->format('Y-m-d');
            $key = $dateStr . ':' . $shift->shift_number;
            $assignmentTracker[$key] = $shift->assignments->pluck('user_id')->toArray();
        }

        foreach ($shifts as $shift) {
            $dateStr = $shift->date->format('Y-m-d');
            $dayOfWeek = $shift->date->dayOfWeekIso; // 1=Mon, 7=Sun
            $isWeekend = $dayOfWeek >= 6;
            $needed = $isWeekend ? $request->users_per_shift_weekend : $request->users_per_shift_weekday;

            $currentKey = $dateStr . ':' . $shift->shift_number;
            $alreadyAssigned = $assignmentTracker[$currentKey] ?? [];
            $spotsToFill = $needed - count($alreadyAssigned);

            if ($spotsToFill <= 0)
                continue;

            // Get available users for this shift
            $available = $this->getAvailableUsers(
                $dateStr,
                $shift,
                $employees,
                $blockedMap,
                $assignmentTracker,
                $alreadyAssigned
            );

            // Shuffle for randomness
            $available = collect($available)->shuffle()->values()->toArray();

            $assigned = 0;
            foreach ($available as $userId) {
                if ($assigned >= $spotsToFill)
                    break;

                ShiftAssignment::create([
                    'shift_id' => $shift->id,
                    'user_id' => $userId,
                ]);

                $assignmentTracker[$currentKey][] = $userId;
                $assigned++;
                $totalAssigned++;
            }

            if ($assigned < $spotsToFill) {
                $warnings[] = "Could only assign {$assigned}/{$spotsToFill} users to {$dateStr} Shift {$shift->shift_number}";
            }
        }

        $message = "Auto-assigned {$totalAssigned} slots.";
        if (count($warnings) > 0) {
            $message .= ' Warnings: ' . count($warnings) . ' shifts could not be fully filled.';
        }

        return response()->json([
            'message' => $message,
            'total_assigned' => $totalAssigned,
            'warnings' => $warnings,
        ]);
    }

    /**
     * Rotating Subteam Mode
     * Builds subteams that rotate every N days.
     */
    public function rotating(Request $request)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'shift_group_id' => ['sometimes', 'integer', 'exists:shift_groups,id'],
            'users_per_shift_weekday' => ['required', 'integer', 'min:1', 'max:20'],
            'users_per_shift_weekend' => ['required', 'integer', 'min:1', 'max:20'],
            'rotation_days' => ['required', 'integer', 'min:1', 'max:31'],
            'player_mode' => ['sometimes', 'in:all,include,exclude'],
            'player_ids' => ['sometimes', 'array'],
            'player_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $start = Month::start($request->month);
        $end = $start->copy()->endOfMonth();
        $groupId = $request->input('shift_group_id') ?? ShiftGroup::where('is_default', true)->value('id');

        $shifts = Shift::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('shift_group_id', $groupId)
            ->orderBy('date')
            ->orderBy('shift_number')
            ->with('assignments')
            ->get();

        if ($shifts->isEmpty()) {
            return response()->json(['message' => 'No shifts found for this month. Generate shifts first.'], 422);
        }

        $employees = User::where('is_admin', false)->orderBy('name')->get();
        $employees = $this->applyPlayerFilter($employees, $request);

        if ($employees->isEmpty()) {
            return response()->json(['message' => 'No employees found to assign.'], 422);
        }

        $blockedMap = $this->buildBlockedMap($employees->pluck('id')->toArray(), $request->month);

        // Group shifts by date
        $shiftsByDate = [];
        foreach ($shifts as $shift) {
            $dateStr = $shift->date->format('Y-m-d');
            $shiftsByDate[$dateStr][] = $shift;
        }

        $dates = array_keys($shiftsByDate);
        sort($dates);

        // Track assignments
        $assignmentTracker = [];
        foreach ($shifts as $shift) {
            $dateStr = $shift->date->format('Y-m-d');
            $key = $dateStr . ':' . $shift->shift_number;
            $assignmentTracker[$key] = $shift->assignments->pluck('user_id')->toArray();
        }

        $totalAssigned = 0;
        $warnings = [];

        // Pool of employee IDs we cycle through for building subteams
        $employeeIds = $employees->pluck('id')->toArray();
        $poolIndex = 0; // tracks which employees have been "used" for subteam building

        // Process dates in rotation blocks
        $rotationDays = $request->rotation_days;
        $dateChunks = array_chunk($dates, $rotationDays);

        foreach ($dateChunks as $dateBlock) {
            // Determine how many unique users this block needs:
            // max users needed for any single shift × shifts per day
            $shiftsPerDay = isset($shiftsByDate[$dateBlock[0]]) ? count($shiftsByDate[$dateBlock[0]]) : 1;
            $maxPerShift = max($request->users_per_shift_weekday, $request->users_per_shift_weekend);
            $teamSize = $maxPerShift * $shiftsPerDay;

            // Build a subteam: pick users starting from poolIndex, wrapping around
            $subteam = [];
            $attempts = 0;
            $idx = $poolIndex;

            while (count($subteam) < $teamSize && $attempts < count($employeeIds) * 2) {
                $candidateId = $employeeIds[$idx % count($employeeIds)];

                // Check if this user is blocked for ANY day in this block
                $blockedForBlock = false;
                foreach ($dateBlock as $dateStr) {
                    if ($this->isUserBlockedOnDate($candidateId, $dateStr, $blockedMap)) {
                        $blockedForBlock = true;
                        break;
                    }
                }

                if (!$blockedForBlock && !in_array($candidateId, $subteam)) {
                    $subteam[] = $candidateId;
                }

                $idx++;
                $attempts++;
            }

            // Advance pool index for next block
            $poolIndex = ($poolIndex + count($subteam)) % count($employeeIds);

            if (empty($subteam)) {
                foreach ($dateBlock as $dateStr) {
                    $warnings[] = "No available users for block starting {$dateStr}";
                }
                continue;
            }

            // Now assign the subteam across shifts in this date block
            foreach ($dateBlock as $dateStr) {
                if (!isset($shiftsByDate[$dateStr]))
                    continue;

                $dayOfWeek = Carbon::parse($dateStr)->dayOfWeekIso;
                $isWeekend = $dayOfWeek >= 6;
                $needed = $isWeekend ? $request->users_per_shift_weekend : $request->users_per_shift_weekday;

                foreach ($shiftsByDate[$dateStr] as $shift) {
                    $currentKey = $dateStr . ':' . $shift->shift_number;
                    $alreadyAssigned = $assignmentTracker[$currentKey] ?? [];
                    $spotsToFill = $needed - count($alreadyAssigned);

                    if ($spotsToFill <= 0)
                        continue;

                    // Filter subteam to those available for this specific shift
                    $availableFromTeam = [];
                    foreach ($subteam as $userId) {
                        if (in_array($userId, $alreadyAssigned))
                            continue;
                        if ($this->isUserBlockedOnDate($userId, $dateStr, $blockedMap))
                            continue;
                        if ($this->isAdjacentAssigned($userId, $dateStr, $shift->shift_number, $assignmentTracker))
                            continue;
                        if ($this->isAlreadyAssignedOnDate($userId, $dateStr, $shift->shift_number, $assignmentTracker))
                            continue;
                        $availableFromTeam[] = $userId;
                    }

                    // Shuffle for variety within the subteam
                    shuffle($availableFromTeam);

                    $assigned = 0;
                    foreach ($availableFromTeam as $userId) {
                        if ($assigned >= $spotsToFill)
                            break;

                        ShiftAssignment::create([
                            'shift_id' => $shift->id,
                            'user_id' => $userId,
                        ]);

                        $assignmentTracker[$currentKey][] = $userId;
                        $assigned++;
                        $totalAssigned++;
                    }

                    if ($assigned < $spotsToFill) {
                        // Try filling from ANY available employee (fallback)
                        $fallbackAvailable = $this->getAvailableUsers(
                            $dateStr,
                            $shift,
                            $employees,
                            $blockedMap,
                            $assignmentTracker,
                            $assignmentTracker[$currentKey]
                        );
                        shuffle($fallbackAvailable);

                        foreach ($fallbackAvailable as $userId) {
                            if ($assigned >= $spotsToFill)
                                break;

                            ShiftAssignment::create([
                                'shift_id' => $shift->id,
                                'user_id' => $userId,
                            ]);

                            $assignmentTracker[$currentKey][] = $userId;
                            $assigned++;
                            $totalAssigned++;
                        }

                        if ($assigned < $spotsToFill) {
                            $warnings[] = "Could only assign {$assigned}/{$spotsToFill} users to {$dateStr} Shift {$shift->shift_number}";
                        }
                    }
                }
            }
        }

        $message = "Auto-assigned {$totalAssigned} slots using {$rotationDays}-day rotation.";
        if (count($warnings) > 0) {
            $message .= ' Warnings: ' . count($warnings) . ' shifts could not be fully filled.';
        }

        return response()->json([
            'message' => $message,
            'total_assigned' => $totalAssigned,
            'warnings' => $warnings,
        ]);
    }

    // ─── Helper Methods ───────────────────────────────────────────

    /**
     * Build a map of userId => [blocked date strings] for the month.
     */
    private function buildBlockedMap(array $userIds, string $month): array
    {
        $start = Month::start($month);
        $end = $start->copy()->endOfMonth();

        $availabilities = UserAvailability::whereIn('user_id', $userIds)
            ->where('type', 'unavailable')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_date', [$start, $end])
                    ->orWhereBetween('end_date', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->where('start_date', '<=', $start)->where('end_date', '>=', $end);
                    });
            })
            ->get();

        $map = [];
        foreach ($availabilities as $av) {
            $from = $av->start_date->copy()->max($start);
            $to = $av->end_date->copy()->min($end);

            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                $map[$av->user_id][] = $cursor->format('Y-m-d');
                $cursor->addDay();
            }
        }

        return $map;
    }

    /**
     * Check if user is blocked on a specific date.
     */
    private function isUserBlockedOnDate(int $userId, string $dateStr, array $blockedMap): bool
    {
        return isset($blockedMap[$userId]) && in_array($dateStr, $blockedMap[$userId]);
    }

    /**
     * Check if user is assigned to an adjacent shift (back-to-back).
     */
    private function isAdjacentAssigned(int $userId, string $dateStr, int $shiftNumber, array $assignmentTracker): bool
    {
        // Check previous shift on same day
        $prevKey = $dateStr . ':' . ($shiftNumber - 1);
        if (isset($assignmentTracker[$prevKey]) && in_array($userId, $assignmentTracker[$prevKey])) {
            return true;
        }

        // Check next shift on same day
        $nextKey = $dateStr . ':' . ($shiftNumber + 1);
        if (isset($assignmentTracker[$nextKey]) && in_array($userId, $assignmentTracker[$nextKey])) {
            return true;
        }

        // Check last shift of previous day
        if ($shiftNumber === 1) {
            $prevDate = Carbon::parse($dateStr)->subDay()->format('Y-m-d');
            // Find highest shift number on prev day
            for ($sn = 10; $sn >= 1; $sn--) {
                $key = $prevDate . ':' . $sn;
                if (isset($assignmentTracker[$key])) {
                    if (in_array($userId, $assignmentTracker[$key])) {
                        return true;
                    }
                    break;
                }
            }
        }

        // Check first shift of next day (for last shift of current day)
        // We need to know max shift number, so check if next shift doesn't exist
        $nextShiftKey = $dateStr . ':' . ($shiftNumber + 1);
        if (!isset($assignmentTracker[$nextShiftKey])) {
            // This might be the last shift of the day
            $nextDate = Carbon::parse($dateStr)->addDay()->format('Y-m-d');
            $nextDayFirstKey = $nextDate . ':1';
            if (isset($assignmentTracker[$nextDayFirstKey]) && in_array($userId, $assignmentTracker[$nextDayFirstKey])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get list of user IDs available for a specific shift.
     */
    private function getAvailableUsers(
        string $dateStr,
        Shift $shift,
        $employees,
        array $blockedMap,
        array $assignmentTracker,
        array $excludeUserIds = []
    ): array {
        $available = [];

        foreach ($employees as $emp) {
            if (in_array($emp->id, $excludeUserIds))
                continue;
            if ($this->isUserBlockedOnDate($emp->id, $dateStr, $blockedMap))
                continue;
            if ($this->isAdjacentAssigned($emp->id, $dateStr, $shift->shift_number, $assignmentTracker))
                continue;
            if ($this->isAlreadyAssignedOnDate($emp->id, $dateStr, $shift->shift_number, $assignmentTracker))
                continue;

            $available[] = $emp->id;
        }

        return $available;
    }

    /**
     * Check if user is already assigned to another shift on the same date.
     */
    private function isAlreadyAssignedOnDate(int $userId, string $dateStr, int $currentShiftNumber, array $assignmentTracker): bool
    {
        for ($sn = 1; $sn <= 10; $sn++) {
            if ($sn === $currentShiftNumber)
                continue;
            $key = $dateStr . ':' . $sn;
            if (isset($assignmentTracker[$key]) && in_array($userId, $assignmentTracker[$key])) {
                return true;
            }
        }
        return false;
    }

    // ─── Polish Law Mode ────────────────────────────────────────────

    /**
     * Polish Labor Code compliant assignment.
     *
     * Rules enforced:
     * - 11h uninterrupted daily rest after each shift
     * - 35h uninterrupted weekly rest (last day of settlement-period-anchored week preferred)
     * - Max weekly hour cap per settlement period (configurable via ShiftGroup.weekly_hour_cap)
     * - Max 13h work per day
     * - Max 5 working days per week average
     * - No same-day double shifts
     * - Respects user unavailability
     */
    public function polishLaw(Request $request)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'shift_group_id' => ['sometimes', 'integer', 'exists:shift_groups,id'],
            'users_per_shift_weekday' => ['required', 'integer', 'min:1', 'max:20'],
            'users_per_shift_weekend' => ['required', 'integer', 'min:1', 'max:20'],
            'rotation_days' => ['required', 'integer', 'min:1', 'max:31'],
            'player_mode' => ['sometimes', 'in:all,include,exclude'],
            'player_ids' => ['sometimes', 'array'],
            'player_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $start = Month::start($request->month);
        $end = $start->copy()->endOfMonth();
        $groupId = $request->input('shift_group_id') ?? ShiftGroup::where('is_default', true)->value('id');
        $group = ShiftGroup::findOrFail($groupId);
        $calculator = new \App\Services\SettlementPeriodCalculator();

        $shifts = Shift::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('shift_group_id', $groupId)
            ->orderBy('date')
            ->orderBy('shift_number')
            ->with('assignments')
            ->get();

        if ($shifts->isEmpty()) {
            return response()->json(['message' => 'No shifts found for this month. Generate shifts first.'], 422);
        }

        $employees = User::where('is_admin', false)->orderBy('name')->get();
        $employees = $this->applyPlayerFilter($employees, $request);

        if ($employees->isEmpty()) {
            return response()->json(['message' => 'No employees found to assign.'], 422);
        }

        $blockedMap = $this->buildBlockedMap($employees->pluck('id')->toArray(), $request->month);

        // On-call callbacks for this group/month — read-only input to the
        // state walk below (see docs/superpowers/specs/2026-09-15-on-call-duty-design.md).
        // polishLaw() never creates or mutates on-call data, only reads it.
        $onCallCallbacks = \App\Models\OnCallCallback::whereHas('onCallAssignment', function ($q) use ($groupId, $employees) {
            $q->where('shift_group_id', $groupId)
                ->whereIn('user_id', $employees->pluck('id'));
        })
            ->whereBetween('starts_at', [$start, $end])
            ->with('onCallAssignment:id,user_id')
            ->orderBy('starts_at')
            ->get();

        // Pre-compute each distinct date's settlement-anchored week: a period-qualified
        // key (so weeks from different periods never collide), the week's hour cap,
        // and whether the date is the last day of its anchored week (used below in
        // place of the old "is it Sunday" heuristic).
        $weekKeyByDate = [];
        $weekCapByDate = [];
        $weekEndByDate = [];
        try {
            foreach ($shifts as $shift) {
                $dateStr = $shift->date->format('Y-m-d');
                if (isset($weekKeyByDate[$dateStr])) {
                    continue;
                }
                $shiftDate = $shift->date->copy()->startOfDay();
                $period = $calculator->periodContaining($group, $shiftDate);
                $match = $calculator->weekFor($period, $shiftDate);
                $weekKeyByDate[$dateStr] = "{$period['period_number']}:{$match['index']}";
                $weekCapByDate[$dateStr] = $match['week']['cap_hours'];
                $weekEndByDate[$dateStr] = $dateStr === $match['week']['end']->format('Y-m-d');
            }
            // A callback can land on a date with no shift record at all
            // (called in on an otherwise-scheduled day off) — a callback
            // crossing midnight is attributed entirely to its starts_at
            // date, the same convention Shift.date uses for overnight shifts.
            foreach ($onCallCallbacks as $callback) {
                $dateStr = $callback->starts_at->format('Y-m-d');
                if (isset($weekKeyByDate[$dateStr])) {
                    continue;
                }
                $callbackDate = $callback->starts_at->copy()->startOfDay();
                $period = $calculator->periodContaining($group, $callbackDate);
                $match = $calculator->weekFor($period, $callbackDate);
                $weekKeyByDate[$dateStr] = "{$period['period_number']}:{$match['index']}";
                $weekCapByDate[$dateStr] = $match['week']['cap_hours'];
                $weekEndByDate[$dateStr] = $dateStr === $match['week']['end']->format('Y-m-d');
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => "Settlement period configuration error for group \"{$group->name}\": {$e->getMessage()}",
            ], 422);
        }

        // Everything already assigned before the month's end is part of the replay; days are processed in
        // date order below and every new assignment is appended, so eligibility stays exact.
        $streakTimeline = app(StreakReplay::class)->forGroup($group, $employees->pluck('id')->all(), \Illuminate\Support\Carbon::instance($end)->addDay());

        // Build shift time info
        $shiftTimes = [];
        foreach ($shifts as $shift) {
            $timing = $calculator->shiftTiming($shift);

            $shiftTimes[$shift->id] = [
                'start' => $timing['start'],
                'end' => $timing['end'],
                'duration_minutes' => $timing['duration_hours'] * 60,
                'duration_hours' => $timing['duration_hours'],
                'is_night' => $this->isNightShift($timing['start'], $timing['end']),
            ];
        }

        // Track per-employee state
        $employeeState = [];
        foreach ($employees as $emp) {
            $employeeState[$emp->id] = [
                'last_shift_end' => null,        // Carbon of last shift end
                'weekly_hours' => [],             // weekKey => hours
                'weekly_days' => [],              // weekKey => [dates worked]
                'total_hours' => 0,
                'total_shifts' => 0,
                'last_rest_start' => null,        // When last rest period began (for 35h weekly check)
            ];
        }

        // Track assignments
        $assignmentTracker = [];
        foreach ($shifts as $shift) {
            $dateStr = $shift->date->format('Y-m-d');
            $key = $dateStr . ':' . $shift->shift_number;
            $assignmentTracker[$key] = $shift->assignments->pluck('user_id')->toArray();

            // Pre-populate state for existing assignments
            foreach ($shift->assignments as $a) {
                if (isset($employeeState[$a->user_id]) && isset($shiftTimes[$shift->id])) {
                    $st = $shiftTimes[$shift->id];
                    $weekNum = $weekKeyByDate[$dateStr];
                    $employeeState[$a->user_id]['last_shift_end'] = $st['end'];
                    $employeeState[$a->user_id]['total_hours'] += $st['duration_hours'];
                    $employeeState[$a->user_id]['total_shifts']++;
                    $employeeState[$a->user_id]['weekly_hours'][$weekNum] = ($employeeState[$a->user_id]['weekly_hours'][$weekNum] ?? 0) + $st['duration_hours'];
                    $employeeState[$a->user_id]['weekly_days'][$weekNum][$dateStr] = true;
                }
            }
        }

        // Fold on-call callbacks into the same per-employee state used for
        // shifts: a callback counts as worked time for weekly_hour_cap/5-day
        // purposes, added here since those totals are additive and
        // order-independent. last_shift_end is NOT updated here — the main
        // assignment loop below blindly overwrites last_shift_end on every
        // shift it assigns (line ~800), which would silently erase a
        // callback's effect on the rest clock the moment any earlier shift
        // in the month gets assigned. Instead, $callbackEndsByUser is
        // consulted fresh inside the 11h-rest eligibility check itself,
        // where it cannot be stale regardless of assignment order.
        $callbackEndsByUser = [];
        foreach ($onCallCallbacks as $callback) {
            $userId = $callback->onCallAssignment->user_id;
            if (!isset($employeeState[$userId])) {
                continue;
            }

            $dateStr = $callback->starts_at->format('Y-m-d');
            $weekNum = $weekKeyByDate[$dateStr];
            $durationHours = $callback->starts_at->diffInMinutes($callback->ends_at) / 60;

            $employeeState[$userId]['total_hours'] += $durationHours;
            $employeeState[$userId]['weekly_hours'][$weekNum] = ($employeeState[$userId]['weekly_hours'][$weekNum] ?? 0) + $durationHours;
            $employeeState[$userId]['weekly_days'][$weekNum][$dateStr] = true;
            $callbackEndsByUser[$userId][] = $callback->ends_at;
        }

        $totalAssigned = 0;
        $warnings = [];

        foreach ($shifts as $shift) {
            $dateStr = $shift->date->format('Y-m-d');
            $dayOfWeek = $shift->date->dayOfWeekIso;
            $isWeekend = $dayOfWeek >= 6;
            $needed = $isWeekend ? $request->users_per_shift_weekend : $request->users_per_shift_weekday;
            $weekNum = $weekKeyByDate[$dateStr];
            $weekCap = $weekCapByDate[$dateStr];

            $currentKey = $dateStr . ':' . $shift->shift_number;
            $alreadyAssigned = $assignmentTracker[$currentKey] ?? [];
            $spotsToFill = $needed - count($alreadyAssigned);

            if ($spotsToFill <= 0)
                continue;

            if (!isset($shiftTimes[$shift->id]))
                continue;
            $st = $shiftTimes[$shift->id];

            // Check shift doesn't exceed 13h
            if ($st['duration_hours'] > 13) {
                $warnings[] = "{$dateStr} S{$shift->shift_number}: shift exceeds 13h limit ({$st['duration_hours']}h)";
                continue;
            }

            // Build list of eligible employees for this shift
            $candidates = [];
            foreach ($employees as $emp) {
                if (in_array($emp->id, $alreadyAssigned))
                    continue;
                if ($this->isUserBlockedOnDate($emp->id, $dateStr, $blockedMap))
                    continue;
                if ($this->isAlreadyAssignedOnDate($emp->id, $dateStr, $shift->shift_number, $assignmentTracker))
                    continue;

                $state = $employeeState[$emp->id];

                // Rule: 11h rest since last shift or last on-call callback,
                // whichever is later and already occurred before this
                // candidate shift starts. Computed fresh here (not folded
                // into $state['last_shift_end']) because that field gets
                // overwritten by every shift this loop assigns — see the
                // callback pre-population comment above.
                $lastActivityEnd = $state['last_shift_end'];
                foreach ($callbackEndsByUser[$emp->id] ?? [] as $callbackEnd) {
                    if ($callbackEnd->lte($st['start']) && ($lastActivityEnd === null || $callbackEnd->gt($lastActivityEnd))) {
                        $lastActivityEnd = $callbackEnd;
                    }
                }
                if ($lastActivityEnd !== null) {
                    $restHours = $lastActivityEnd->diffInMinutes($st['start']) / 60;
                    if ($restHours < 11)
                        continue;
                }

                // Rule: max weekly_hour_cap this settlement-anchored week
                $weekHours = $state['weekly_hours'][$weekNum] ?? 0;
                if ($weekHours + $st['duration_hours'] > $weekCap)
                    continue;

                // Rule: max 5 working days this week
                $weekDays = $state['weekly_days'][$weekNum] ?? [];
                if (!isset($weekDays[$dateStr]) && count($weekDays) >= 5)
                    continue;

                // Rule: 35h weekly rest — check if employee had at least 35h continuous rest this week
                // (We enforce this by checking if the last day of the anchored week is free when
                //  possible; the check below prevents assigning every day of the week without a break.)
                if ($weekEndByDate[$dateStr]) {
                    if (count($weekDays) >= 5)
                        continue;
                }

                // Rule: shift-streak mandatory rest (see StreakTimeline)
                if ($streakTimeline->isResting($emp->id, $shift->date)) {
                    continue;
                }

                // Scoring: prefer employees with fewer hours (fairness)
                $score = $state['total_hours'];

                $candidates[] = ['id' => $emp->id, 'score' => $score];
            }

            // Sort by fewest hours first (fair distribution)
            usort($candidates, fn($a, $b) => $a['score'] <=> $b['score']);

            $assigned = 0;
            foreach ($candidates as $c) {
                if ($assigned >= $spotsToFill)
                    break;

                $userId = $c['id'];

                ShiftAssignment::create([
                    'shift_id' => $shift->id,
                    'user_id' => $userId,
                ]);

                $assignmentTracker[$currentKey][] = $userId;
                $employeeState[$userId]['last_shift_end'] = $st['end'];
                $employeeState[$userId]['total_hours'] += $st['duration_hours'];
                $employeeState[$userId]['total_shifts']++;
                $employeeState[$userId]['weekly_hours'][$weekNum] = ($employeeState[$userId]['weekly_hours'][$weekNum] ?? 0) + $st['duration_hours'];
                $employeeState[$userId]['weekly_days'][$weekNum][$dateStr] = true;

                $streakTimeline->append($userId, $shift->shift_type, $shift->date, $shift->shift_number);

                $assigned++;
                $totalAssigned++;
            }

            if ($assigned < $spotsToFill) {
                $warnings[] = "Could only assign {$assigned}/{$spotsToFill} to {$dateStr} S{$shift->shift_number} (labor code limits)";
            }
        }

        // Count night shifts
        $nightShiftCount = 0;
        foreach ($shifts as $shift) {
            if (isset($shiftTimes[$shift->id]) && $shiftTimes[$shift->id]['is_night']) {
                $nightShiftCount++;
            }
        }

        $message = "Auto-assigned {$totalAssigned} slots (Polish Labor Code mode). {$nightShiftCount} night shifts identified.";
        if (count($warnings) > 0) {
            $message .= ' ' . count($warnings) . ' shifts could not be fully filled.';
        }

        return response()->json([
            'message' => $message,
            'total_assigned' => $totalAssigned,
            'warnings' => $warnings,
        ]);
    }

    /**
     * Check if a shift falls in night hours (22:00-06:00).
     */
    private function isNightShift(Carbon $start, Carbon $end): bool
    {
        $startHour = (int) $start->format('H');
        $endHour = (int) $end->format('H');

        // Night shift: any part falls between 22:00 and 06:00
        if ($startHour >= 22 || $startHour < 6)
            return true;
        if ($endHour > 22 || ($endHour >= 0 && $endHour <= 6 && $end->day !== $start->day))
            return true;

        return false;
    }

    /**
     * Apply player selection filter to the employees collection.
     */
    private function applyPlayerFilter($employees, Request $request)
    {
        $mode = $request->input('player_mode', 'all');
        $ids = $request->input('player_ids', []);

        if ($mode === 'include' && !empty($ids)) {
            return $employees->whereIn('id', $ids)->values();
        }

        if ($mode === 'exclude' && !empty($ids)) {
            return $employees->whereNotIn('id', $ids)->values();
        }

        return $employees;
    }
}
