<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftChangeRequest;
use App\Models\ShiftGroup;
use App\Models\User;
use App\Models\UserAvailability;
use App\Services\BusyIntervals;
use App\Services\RestPolicyJanitor;
use App\Services\StreakReplay;
use App\Services\StreakTimeline;
use App\Support\AdvisoryLock;
use App\Support\BusinessDate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\Month;

class ShiftAutoAssignController extends Controller
{
    /**
     * Auto-fill runs and clear-month for one group's month take turns (F4): each runs in a
     * transaction that first takes the group+month advisory lock, before it reads the month, so a
     * second run waits for the first to commit and then plans on top of its assignments instead of
     * on the same starting state. (The group row lock is FOR SHARE: it keeps an archive out, but
     * two runs both get it.) Other months and groups don't wait.
     */
    public function clearMonth(Request $request, RestPolicyJanitor $janitor)
    {
        return $this->oneRunPerMonth($request, fn () => $this->clearMonthRun($request, $janitor));
    }

    public function random(Request $request, RestPolicyJanitor $janitor)
    {
        return $this->oneRunPerMonth($request, fn () => $this->randomRun($request, $janitor));
    }

    public function rotating(Request $request, RestPolicyJanitor $janitor)
    {
        return $this->oneRunPerMonth($request, fn () => $this->rotatingRun($request, $janitor));
    }

    public function polishLaw(Request $request, RestPolicyJanitor $janitor)
    {
        return $this->oneRunPerMonth($request, fn () => $this->polishLawRun($request, $janitor));
    }

    /** The advisory lock name for one group's month (see oneRunPerMonth()). */
    public static function monthLockName(int|string|null $groupId, string $month): string
    {
        return "schedule.month-run:{$groupId}:{$month}";
    }

    private function oneRunPerMonth(Request $request, callable $run)
    {
        // The same group the run will use: the one sent, or the default. An invalid value only
        // names an odd lock; the run's own validation then refuses it.
        $groupId = $request->input('shift_group_id') ?? ShiftGroup::where('is_default', true)->value('id');
        $month = (string) $request->input('month');

        return DB::transaction(function () use ($groupId, $month, $run) {
            AdvisoryLock::forTransaction(self::monthLockName(is_scalar($groupId) ? $groupId : null, $month));

            return $run();
        });
    }

    /**
     * Delete all shifts + assignments for a month.
     */
    private function clearMonthRun(Request $request, RestPolicyJanitor $janitor)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'shift_group_id' => ['sometimes', 'integer', ShiftGroup::activeExistsRule()],
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

        DB::transaction(function () use ($shifts, $groupId, $janitor, &$totalAssignments) {
            // The group first (ShiftGroup's lock order): an archive since validation is refused.
            ShiftGroup::lockActiveOrFail($groupId);
            // Then the assignment rows, so a change request being filed for one waits and then
            // finds it gone instead of being left pending.
            ShiftAssignment::whereIn('shift_id', $shifts->pluck('id'))->orderBy('id')->lockForUpdate()->get(['id']);
            // Clearing settles the month's pending change requests; they stay on record (with their
            // shift and assignment links nulled, date and slot kept).
            ShiftChangeRequest::whereIn('shift_id', $shifts->pluck('id'))
                ->where('status', 'pending')
                ->update(['status' => 'acknowledged']);
            $userIds = [];
            foreach ($shifts as $shift) {
                $totalAssignments += $shift->assignments->count();
                array_push($userIds, ...$shift->assignments->pluck('user_id')->all());
                $shift->assignments()->delete();
                $shift->delete();
            }

            // Shifts after the month that its streaks kept in rest lose their policy.
            $group = ShiftGroup::find($groupId);
            if ($group) {
                $janitor->clearUncovered($group, $userIds);
            }
        });

        return response()->json([
            'message' => "Deleted {$totalShifts} shifts and {$totalAssignments} assignments for {$request->month}.",
        ]);
    }

    /**
     * Random Coverage Mode
     * Assigns random users to each shift, respecting availability, approved delegations, the
     * streak rest days and 11 h of rest around every other shift of the employee (in any group).
     */
    private function randomRun(Request $request, RestPolicyJanitor $janitor)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'shift_group_id' => ['sometimes', 'integer', ShiftGroup::activeExistsRule()],
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

        $employees = User::active()->notAdmins()->orderBy('name')->get();
        $employees = $this->applyPlayerFilter($employees, $request);

        if ($employees->isEmpty()) {
            return response()->json(['message' => 'No employees found to assign.'], 422);
        }

        $blockedMap = $this->buildBlockedMap($employees->pluck('id')->toArray(), $request->month);

        $streakTimeline = ($group = ShiftGroup::find($groupId))
            ? app(StreakReplay::class)->forGroup($group, $employees->pluck('id')->all())
            : null;

        [$busy, $spans] = $this->dailyRestState($employees->pluck('id')->all(), $shifts, $start, $end);

        $totalAssigned = 0;
        $runUserIds = [];
        $warnings = [];

        // Track assignments as we go so the same-date check sees newly created assignments
        $assignmentTracker = []; // dateStr:shiftNumber => [user_ids]
        foreach ($shifts as $shift) {
            $dateStr = $shift->date->format('Y-m-d');
            $key = $dateStr . ':' . $shift->shift_number;
            $assignmentTracker[$key] = $shift->assignments->pluck('user_id')->toArray();
        }

        // One transaction: a failure part-way leaves no partial month behind.
        DB::transaction(function () use (
            $request, $shifts, $employees, $blockedMap, $assignmentTracker, $streakTimeline, $busy, $spans, $group, $janitor, $runUserIds, &$totalAssigned, &$warnings
        ) {
            // The group first (ShiftGroup's lock order): an archive since validation is refused here
            // instead of a whole month landing in an archived group.
            ShiftGroup::lockActiveOrFail($group?->id);
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
                    $alreadyAssigned,
                    $streakTimeline,
                    $busy,
                    $spans[$shift->id]
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
                    $runUserIds[] = $userId;
                    $busy->add($userId, ...$spans[$shift->id]);
                    if ($streakTimeline !== null)
                        $streakTimeline->append($userId, $shift->shift_type, $shift->date, $shift->shift_number);
                    $assigned++;
                    $totalAssigned++;
                }

                if ($assigned < $spotsToFill) {
                    $warnings[] = "Could only assign {$assigned}/{$spotsToFill} users to {$dateStr} Shift {$shift->shift_number}";
                }
            }

            // A new shift can complete a streak earlier and move a later window off a shift that had a policy.
            if ($group) {
                $janitor->clearUncovered($group, $runUserIds);
            }
        });

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
    private function rotatingRun(Request $request, RestPolicyJanitor $janitor)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'shift_group_id' => ['sometimes', 'integer', ShiftGroup::activeExistsRule()],
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

        $employees = User::active()->notAdmins()->orderBy('name')->get();
        $employees = $this->applyPlayerFilter($employees, $request);

        if ($employees->isEmpty()) {
            return response()->json(['message' => 'No employees found to assign.'], 422);
        }

        $blockedMap = $this->buildBlockedMap($employees->pluck('id')->toArray(), $request->month);

        $streakTimeline = ($group = ShiftGroup::find($groupId))
            ? app(StreakReplay::class)->forGroup($group, $employees->pluck('id')->all())
            : null;

        [$busy, $spans] = $this->dailyRestState($employees->pluck('id')->all(), $shifts, $start, $end);

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
        $runUserIds = [];
        $warnings = [];

        // Pool of employee IDs we cycle through for building subteams
        $employeeIds = $employees->pluck('id')->toArray();
        $poolIndex = 0; // tracks which employees have been "used" for subteam building

        // Process dates in rotation blocks
        $rotationDays = $request->rotation_days;
        $dateChunks = array_chunk($dates, $rotationDays);

        // One transaction: a failure part-way leaves no partial month behind.
        DB::transaction(function () use (
            $request, $shifts, $shiftsByDate, $dateChunks, $employees, $employeeIds, $poolIndex, $blockedMap, $assignmentTracker, $streakTimeline, $busy, $spans, $group, $janitor, $runUserIds, &$totalAssigned, &$warnings
        ) {
            // The group first (ShiftGroup's lock order): an archive since validation is refused here
            // instead of a whole month landing in an archived group.
            ShiftGroup::lockActiveOrFail($group?->id);
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
                            if ($streakTimeline !== null && $streakTimeline->wouldConflict($userId, $shift->shift_type, $shift->date, $shift->shift_number))
                                continue;
                            if (!$busy->allowsDailyRest($userId, ...$spans[$shift->id]))
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
                            $runUserIds[] = $userId;
                            $busy->add($userId, ...$spans[$shift->id]);
                            if ($streakTimeline !== null)
                                $streakTimeline->append($userId, $shift->shift_type, $shift->date, $shift->shift_number);
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
                                $assignmentTracker[$currentKey],
                                $streakTimeline,
                                $busy,
                                $spans[$shift->id]
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
                                $runUserIds[] = $userId;
                                $busy->add($userId, ...$spans[$shift->id]);
                                if ($streakTimeline !== null)
                                    $streakTimeline->append($userId, $shift->shift_type, $shift->date, $shift->shift_number);
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

            // A new shift can complete a streak earlier and move a later window off a shift that had a policy.
            if ($group) {
                $janitor->clearUncovered($group, $runUserIds);
            }
        });

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

        // An approved delegation makes the employee unavailable on every day it covers: their own
        // dates when set, else the delegation's. Delegation dates are business dates (no time).
        $delegations = DB::table('delegation_user')
            ->join('delegations', 'delegations.id', '=', 'delegation_user.delegation_id')
            ->whereIn('delegation_user.user_id', $userIds)
            ->where('delegation_user.status', 'approved')
            ->whereRaw('COALESCE(delegation_user.custom_start, CAST(delegations.start_datetime AS DATE)) <= ?', [$end->toDateString()])
            ->whereRaw('COALESCE(delegation_user.custom_end, CAST(delegations.end_datetime AS DATE)) >= ?', [$start->toDateString()])
            ->get(['delegation_user.user_id', 'delegation_user.custom_start', 'delegation_user.custom_end',
                'delegations.start_datetime', 'delegations.end_datetime']);
        $monthFrom = $start->toDateString();
        $monthTo = $end->toDateString();
        foreach ($delegations as $d) {
            $from = max(substr($d->custom_start ?? $d->start_datetime, 0, 10), $monthFrom);
            $to = min(substr($d->custom_end ?? $d->end_datetime, 0, 10), $monthTo);
            for ($cursor = Carbon::parse($from); $cursor->toDateString() <= $to; $cursor->addDay()) {
                $map[$d->user_id][] = $cursor->toDateString();
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
     * The employees' busy intervals for the 11 h daily rest (their assignments in every group, from
     * two days before the month to two days after it) and each shift's [start, end) span.
     */
    private function dailyRestState(array $userIds, $shifts, Carbon $start, Carbon $end): array
    {
        $busy = new BusyIntervals();
        $from = $start->copy()->subDays(2)->toDateString();
        $to = $end->copy()->addDays(2)->toDateString();
        foreach (BusyIntervals::loadAssignedShifts($userIds, $from, $to) as $assigned) {
            $busy->add((int) $assigned->busy_user_id, ...BusyIntervals::shiftSpan($assigned));
        }

        $spans = [];
        foreach ($shifts as $shift) {
            $spans[$shift->id] = BusyIntervals::shiftSpan($shift);
        }

        return [$busy, $spans];
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
        array $excludeUserIds,
        ?StreakTimeline $streakTimeline,
        BusyIntervals $busy,
        array $span
    ): array {
        $available = [];

        foreach ($employees as $emp) {
            if (in_array($emp->id, $excludeUserIds))
                continue;
            if ($this->isUserBlockedOnDate($emp->id, $dateStr, $blockedMap))
                continue;
            if (!$busy->allowsDailyRest($emp->id, ...$span))
                continue;
            if ($this->isAlreadyAssignedOnDate($emp->id, $dateStr, $shift->shift_number, $assignmentTracker))
                continue;
            if ($streakTimeline !== null && $streakTimeline->wouldConflict($emp->id, $shift->shift_type, $shift->date, $shift->shift_number))
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
     * - 11h uninterrupted daily rest before and after each shift, against the employee's shifts in
     *   every group (and the neighbouring months), on-call callbacks and the run's own picks
     * - 35h uninterrupted weekly rest in every settlement-anchored week a shift touches
     * - Max weekly hour cap per settlement period (configurable via ShiftGroup.weekly_hour_cap)
     * - Max 13h work per day
     * - Max 5 working days per week average
     * - No same-day double shifts
     * - Respects user unavailability and approved delegations
     */
    private function polishLawRun(Request $request, RestPolicyJanitor $janitor)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'shift_group_id' => ['sometimes', 'integer', ShiftGroup::activeExistsRule()],
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

        $employees = User::active()->notAdmins()->orderBy('name')->get();
        $employees = $this->applyPlayerFilter($employees, $request);

        if ($employees->isEmpty()) {
            return response()->json(['message' => 'No employees found to assign.'], 422);
        }

        $blockedMap = $this->buildBlockedMap($employees->pluck('id')->toArray(), $request->month);

        $userIds = $employees->pluck('id')->all();
        $monthFrom = $start->toDateString();
        $monthTo = $end->toDateString();

        // Settlement-anchored weeks for every date whose 35 h window can reach into the month, and
        // the dates those windows cover: from the day before the week holding the day before the
        // month's first week (and the week holding the 6th day before the month: a shortened week's
        // window still runs seven days, into the next period) to eight days after the month. So the
        // caps and the 35 h rest see the neighbouring months too. Each date gets a period-qualified
        // week key (weeks of different periods never collide), the week's hour cap and the date its
        // week starts on.
        $weekKeyByDate = [];
        $weekCapByDate = [];
        $weekStartByDate = [];
        try {
            $weekStartOf = fn (Carbon $day) => $calculator->weekFor($calculator->periodContaining($group, $day), $day)['week']['start'];
            $firstWeekStart = $weekStartOf($start);
            $from = min(
                $weekStartOf($firstWeekStart->copy()->subDay())->toDateString(),
                $weekStartOf($start->copy()->subDays(6))->toDateString(),
            );
            $from = Carbon::parse($from)->subDay()->toDateString();
            $to = $end->copy()->addDays(8)->toDateString();

            for ($day = Carbon::parse($from); $day->toDateString() <= $to; $day->addDay()) {
                $dateStr = $day->toDateString();
                $period = $calculator->periodContaining($group, $day);
                $match = $calculator->weekFor($period, $day);
                $weekKeyByDate[$dateStr] = "{$period['period_number']}:{$match['index']}";
                $weekCapByDate[$dateStr] = $match['week']['cap_hours'];
                $weekStartByDate[$dateStr] = $match['week']['start']->toDateString();
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => "Settlement period configuration error for group \"{$group->name}\": {$e->getMessage()}",
            ], 422);
        }

        // The replay holds every assignment in the group, including ones after this month, so
        // wouldConflict() can see later shifts a new one would turn into rest days. Days are processed
        // in date order below and every new assignment is appended, so eligibility stays exact.
        $streakTimeline = app(StreakReplay::class)->forGroup($group, $userIds);

        // Shift instants in the business timezone; durations and gaps are elapsed time.
        $shiftTimes = [];
        foreach ($shifts as $shift) {
            $shiftStart = BusinessDate::shiftStart($shift);
            $shiftEnd = BusinessDate::shiftEnd($shift);
            $shiftTimes[$shift->id] = [
                'span' => [$shiftStart->getTimestamp(), $shiftEnd->getTimestamp()],
                'duration_hours' => ($shiftEnd->getTimestamp() - $shiftStart->getTimestamp()) / 3600,
                'is_night' => $this->isNightShift($shiftStart, $shiftEnd),
            ];
        }

        // Each 35 h weekly-rest window: the seven days from its week's start, local midnight to
        // local midnight. A week cut short by the end of a settlement period still gets seven days,
        // or a one-day week could never hold 35 h.
        $restWindows = [];
        foreach (array_unique($weekStartByDate) as $weekStart) {
            $windowStart = Carbon::parse($weekStart, BusinessDate::timezone());
            $restWindows[$weekStart] = [$windowStart->getTimestamp(), $windowStart->copy()->addDays(7)->getTimestamp()];
        }

        // The 35 h windows each shift overlaps: those of every week starting from six days before its
        // date through its end date (a shortened week's window reaches into the next week, period or
        // month).
        $windowsByShift = [];
        foreach ($shifts as $shift) {
            [$shiftStartTs, $shiftEndTs] = $shiftTimes[$shift->id]['span'];
            $endDateStr = Carbon::createFromTimestamp($shiftEndTs - 1, BusinessDate::timezone())->toDateString();
            $windows = [];
            for ($day = $shift->date->copy()->subDays(6); $day->toDateString() <= $endDateStr; $day->addDay()) {
                $weekStart = $weekStartByDate[$day->toDateString()];
                [$windowStart, $windowEnd] = $restWindows[$weekStart];
                if ($windowStart < $shiftEndTs && $windowEnd > $shiftStartTs) {
                    $windows[$weekStart] = $restWindows[$weekStart];
                }
            }
            $windowsByShift[$shift->id] = array_values($windows);
        }

        // Per-employee state: weekly hours and worked dates (for the caps and the 5-day rule) and
        // the month's hours (for fairness).
        $employeeState = [];
        foreach ($userIds as $userId) {
            $employeeState[$userId] = ['weekly_hours' => [], 'weekly_days' => [], 'total_hours' => 0];
        }

        // Busy time the run must respect: the employees' assignments in every group, and their
        // on-call callbacks (read only; polishLaw never creates or changes on-call data). A callback
        // counts as worked time on its start date, the convention Shift.date uses for overnight
        // shifts, and may fall on a date with no shift at all.
        $busy = new BusyIntervals();
        $busyEntries = [];
        foreach (BusyIntervals::loadAssignedShifts($userIds, $from, $to) as $assigned) {
            $busyEntries[] = [(int) $assigned->busy_user_id, $assigned->date->format('Y-m-d'), BusyIntervals::shiftSpan($assigned)];
        }
        foreach (BusyIntervals::loadCallbacks($userIds, $from, $to) as $callback) {
            $busyEntries[] = [(int) $callback->onCallAssignment->user_id, BusyIntervals::callbackDate($callback), BusyIntervals::callbackSpan($callback)];
        }
        foreach ($busyEntries as [$userId, $dateStr, $span]) {
            $busy->add($userId, ...$span);
            $hours = ($span[1] - $span[0]) / 3600;
            if (isset($weekKeyByDate[$dateStr])) {
                $weekNum = $weekKeyByDate[$dateStr];
                $employeeState[$userId]['weekly_hours'][$weekNum] = ($employeeState[$userId]['weekly_hours'][$weekNum] ?? 0) + $hours;
                $employeeState[$userId]['weekly_days'][$weekNum][$dateStr] = true;
            }
            if ($dateStr >= $monthFrom && $dateStr <= $monthTo) {
                $employeeState[$userId]['total_hours'] += $hours;
            }
        }

        // Track assignments
        $assignmentTracker = [];
        foreach ($shifts as $shift) {
            $key = $shift->date->format('Y-m-d') . ':' . $shift->shift_number;
            $assignmentTracker[$key] = $shift->assignments->pluck('user_id')->toArray();
        }

        $totalAssigned = 0;
        $runUserIds = [];
        $warnings = [];

        // One transaction: a failure part-way leaves no partial month behind.
        DB::transaction(function () use (
            $request, $shifts, $employees, $blockedMap, $assignmentTracker, $weekKeyByDate, $weekCapByDate, $shiftTimes, $windowsByShift, $employeeState, $busy, $streakTimeline, $group, $janitor, $runUserIds, &$totalAssigned, &$warnings
        ) {
            // The group first (ShiftGroup's lock order): an archive since validation is refused here
            // instead of a whole month landing in an archived group.
            ShiftGroup::lockActiveOrFail($group?->id);
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

                $st = $shiftTimes[$shift->id];
                [$shiftStartTs, $shiftEndTs] = $st['span'];

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

                    // Rule: 11h rest before and after the shift, against every busy interval
                    // (shifts in any group, callbacks, earlier picks of this run).
                    if (!$busy->allowsDailyRest($emp->id, $shiftStartTs, $shiftEndTs))
                        continue;

                    // Rule: max weekly_hour_cap this settlement-anchored week
                    $weekHours = $state['weekly_hours'][$weekNum] ?? 0;
                    if ($weekHours + $st['duration_hours'] > $weekCap)
                        continue;

                    // Rule: max 5 working days this week
                    $weekDays = $state['weekly_days'][$weekNum] ?? [];
                    if (!isset($weekDays[$dateStr]) && count($weekDays) >= 5)
                        continue;

                    // Rule: 35h uninterrupted weekly rest is still left in every week the shift touches
                    foreach ($windowsByShift[$shift->id] as [$windowStart, $windowEnd]) {
                        if ($busy->longestRestWith($emp->id, $windowStart, $windowEnd, $shiftStartTs, $shiftEndTs) < BusyIntervals::MIN_WEEKLY_REST_HOURS * 3600)
                            continue 2;
                    }

                    // Rule: shift-streak mandatory rest (see StreakTimeline)
                    if ($streakTimeline->wouldConflict($emp->id, $shift->shift_type, $shift->date, $shift->shift_number)) {
                        continue;
                    }

                    // Scoring: prefer employees with fewer hours (fairness)
                    $candidates[] = ['id' => $emp->id, 'score' => $state['total_hours']];
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
                    $runUserIds[] = $userId;
                    $busy->add($userId, $shiftStartTs, $shiftEndTs);
                    $employeeState[$userId]['total_hours'] += $st['duration_hours'];
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

            // A new shift can complete a streak earlier and move a later window off a shift that had a policy.
            $janitor->clearUncovered($group, $runUserIds);
        });

        // Count night shifts
        $nightShiftCount = 0;
        foreach ($shiftTimes as $times) {
            if ($times['is_night']) {
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
