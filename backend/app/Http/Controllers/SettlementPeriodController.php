<?php

namespace App\Http\Controllers;

use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Services\BusyIntervals;
use App\Services\SettlementPeriodCalculator;
use App\Services\StreakReplay;
use Illuminate\Http\Request;

class SettlementPeriodController extends Controller
{
    public function show(Request $request)
    {
        $request->validate([
            'shift_group_id' => ['required', 'integer', 'exists:shift_groups,id'],
            'offset' => ['sometimes', 'integer'],
        ]);

        $group = ShiftGroup::findOrFail($request->shift_group_id);
        $offset = (int) $request->input('offset', 0);
        $calculator = new SettlementPeriodCalculator();

        try {
            $period = $calculator->periodByOffset($group, $offset);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => "Settlement period configuration error for this group: {$e->getMessage()}",
            ], 422);
        }

        $assignments = ShiftAssignment::with(['shift', 'user:id,name'])
            ->whereHas('shift', function ($q) use ($group, $period) {
                $q->where('shift_group_id', $group->id)
                    ->whereBetween('date', [$period['start']->toDateString(), $period['end']->toDateString()]);
            })
            ->get();

        $employeeWeekHours = [];
        $employeeNames = [];

        foreach ($assignments as $assignment) {
            $shift = $assignment->shift;
            $timing = $calculator->shiftTiming($shift);
            $match = $calculator->weekFor($period, $shift->date->copy()->startOfDay());

            $userId = $assignment->user_id;
            $employeeNames[$userId] = $assignment->user->name;
            $employeeWeekHours[$userId][$match['index']] =
                ($employeeWeekHours[$userId][$match['index']] ?? 0) + $timing['duration_hours'];
        }

        // On-call callback hours count toward the same weekly totals as
        // shifts (see docs/superpowers/specs/2026-09-15-on-call-duty-design.md)
        // — filtered directly on the callback's own starts_at date, so
        // weekFor() is only ever called with a date guaranteed to fall
        // inside $period, matching how the shift loop above stays safe.
        $callbacks = \App\Models\OnCallCallback::whereHas('onCallAssignment', function ($q) use ($group) {
            $q->where('shift_group_id', $group->id);
        })
            ->whereBetween('starts_at', [$period['start'], $period['end']->copy()->endOfDay()])
            ->with('onCallAssignment.user:id,name')
            ->get();

        // Overlapping callbacks of one user (logged before overlaps were refused, or on two
        // overlapping on-call periods) are merged first, so each hour counts once. A merged block
        // counts in the week of its first start, as a single callback does.
        foreach ($callbacks->groupBy(fn ($c) => $c->onCallAssignment->user_id) as $userId => $userCallbacks) {
            $employeeNames[$userId] = $userCallbacks->first()->onCallAssignment->user->name;
            foreach (self::mergeIntervals($userCallbacks) as [$start, $end]) {
                $match = $calculator->weekFor($period, $start->copy()->startOfDay());
                // Elapsed time, as for shifts: the stored wall-clock times are read in the business
                // timezone, so a callback across a DST change counts the hour gained or lost.
                // Whole minutes (Carbon 3 returns a float), then hours with the fraction kept: 1:30 is 1.5.
                $durationHours = ((int) BusyIntervals::wallClock($start)->diffInMinutes(BusyIntervals::wallClock($end), true)) / 60;
                $employeeWeekHours[$userId][$match['index']] =
                    ($employeeWeekHours[$userId][$match['index']] ?? 0) + $durationHours;
            }
        }

        $weeks = array_map(fn($w) => [
            'start' => $w['start']->toDateString(),
            'end' => $w['end']->toDateString(),
            'cap_hours' => $w['cap_hours'],
        ], $period['weeks']);

        // Status as of the end of the displayed period; windows ending inside or after it are shown.
        $streakUntil = $period['end']->copy()->addDay();
        $streakTimeline = app(StreakReplay::class)->forGroup($group, array_keys($employeeNames), $streakUntil);

        $employees = [];
        foreach ($employeeNames as $userId => $name) {
            $weeklyHours = [];
            $overCapWeeks = [];
            foreach ($period['weeks'] as $index => $week) {
                $hours = round($employeeWeekHours[$userId][$index] ?? 0, 2);
                $weeklyHours[] = $hours;
                if ($hours > $week['cap_hours']) {
                    $overCapWeeks[] = $index;
                }
            }

            $streakStatus = $streakTimeline->statusAsOf($userId, $streakUntil, $period['start']);

            $employees[] = [
                'id' => $userId,
                'name' => $name,
                'weekly_hours' => $weeklyHours,
                'total_hours' => round(array_sum($weeklyHours), 2),
                'over_cap_weeks' => $overCapWeeks,
                'streak_status' => $streakStatus,
            ];
        }

        usort($employees, fn($a, $b) => strcmp($a['name'], $b['name']));

        return response()->json([
            'period' => [
                'period_number' => $period['period_number'],
                'start' => $period['start']->toDateString(),
                'end' => $period['end']->toDateString(),
            ],
            'weeks' => $weeks,
            'employees' => array_values($employees),
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Union of [starts_at, ends_at) intervals: overlapping ones are merged, touching ones
     * (one ends when the next starts) stay apart, which sums the same.
     *
     * @param  iterable<\App\Models\OnCallCallback>  $items
     * @return list<array{0: \Carbon\Carbon, 1: \Carbon\Carbon}>
     */
    private static function mergeIntervals(iterable $items): array
    {
        $sorted = collect($items)->sortBy(fn ($i) => $i->starts_at->getTimestamp())->values();
        $merged = [];
        foreach ($sorted as $item) {
            $last = count($merged) - 1;
            if ($last >= 0 && $item->starts_at->lt($merged[$last][1])) {
                if ($item->ends_at->gt($merged[$last][1])) {
                    $merged[$last][1] = $item->ends_at;
                }
                continue;
            }
            $merged[] = [$item->starts_at, $item->ends_at];
        }

        return $merged;
    }
}
