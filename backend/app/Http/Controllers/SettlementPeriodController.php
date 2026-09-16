<?php

namespace App\Http\Controllers;

use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Services\SettlementPeriodCalculator;
use App\Services\ShiftStreakTracker;
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

        foreach ($callbacks as $callback) {
            $match = $calculator->weekFor($period, $callback->starts_at->copy()->startOfDay());
            $durationHours = $callback->starts_at->diffInMinutes($callback->ends_at) / 60;

            $userId = $callback->onCallAssignment->user_id;
            $employeeNames[$userId] = $callback->onCallAssignment->user->name;
            $employeeWeekHours[$userId][$match['index']] =
                ($employeeWeekHours[$userId][$match['index']] ?? 0) + $durationHours;
        }

        $weeks = array_map(fn($w) => [
            'start' => $w['start']->toDateString(),
            'end' => $w['end']->toDateString(),
            'cap_hours' => $w['cap_hours'],
        ], $period['weeks']);

        $streakTracker = new ShiftStreakTracker();
        $streakRules = $streakTracker->rulesForGroup($group);
        $streakStates = $streakTracker->loadStates($streakRules, array_keys($employeeNames));

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

            $streakStatus = [];
            foreach ($streakRules as $rule) {
                $state = $streakStates["{$userId}:{$rule->id}"];
                $streakStatus[] = [
                    'shift_type' => $rule->shift_type,
                    'current_count' => $state['current_count'],
                    'streak_length' => $rule->streak_length,
                    'resting_until' => $state['resting_until']?->toDateString(),
                ];
            }

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
}
