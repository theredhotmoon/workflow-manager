<?php

namespace App\Services;

use App\Models\ShiftGroup;
use App\Models\ShiftStreakState;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShiftStreakTracker
{
    public function rulesForGroup(ShiftGroup $group): Collection
    {
        return $group->streakRules()->get()->keyBy('shift_type');
    }

    public function loadStates(Collection $rules, array $userIds): array
    {
        $states = [];
        foreach ($userIds as $userId) {
            foreach ($rules as $rule) {
                $states["{$userId}:{$rule->id}"] = [
                    'current_count' => 0,
                    'resting_until' => null,
                    'last_processed_date' => null,
                    'dirty' => false,
                ];
            }
        }

        if ($rules->isEmpty() || empty($userIds)) {
            return $states;
        }

        $existing = ShiftStreakState::whereIn('user_id', $userIds)
            ->whereIn('shift_streak_rule_id', $rules->pluck('id'))
            ->get();

        foreach ($existing as $row) {
            $key = "{$row->user_id}:{$row->shift_streak_rule_id}";
            $states[$key] = [
                'current_count' => $row->current_count,
                'resting_until' => $row->resting_until,
                'last_processed_date' => $row->last_processed_date,
                'dirty' => false,
            ];
        }

        return $states;
    }

    public function isBlocked(array &$states, Collection $rules, int $userId, Carbon $date): bool
    {
        $day = $date->copy()->startOfDay();

        foreach ($rules as $rule) {
            $restingUntil = $states["{$userId}:{$rule->id}"]['resting_until'] ?? null;
            if ($restingUntil !== null && $day->lte($restingUntil)) {
                return true;
            }
        }

        return false;
    }

    public function recordAssignment(array &$states, Collection $rules, int $userId, ?string $shiftType, Carbon $date, bool $advanceWatermark = false): void
    {
        $rule = $shiftType !== null ? $rules->get($shiftType) : null;
        if ($rule === null) {
            return;
        }

        $key = "{$userId}:{$rule->id}";
        $day = $date->copy()->startOfDay();

        $states[$key]['current_count']++;
        if ($states[$key]['current_count'] >= $rule->streak_length) {
            $states[$key]['resting_until'] = $day->copy()->addDays($rule->rest_days);
            $states[$key]['current_count'] = 0;
        }

        if ($advanceWatermark) {
            $existingWatermark = $states[$key]['last_processed_date'];
            if ($existingWatermark === null || $day->gt($existingWatermark)) {
                $states[$key]['last_processed_date'] = $day;
            }
        }

        $states[$key]['dirty'] = true;
    }

    public function wasAlreadyProcessed(array $states, Collection $rules, int $userId, ?string $shiftType, Carbon $date): bool
    {
        $rule = $shiftType !== null ? $rules->get($shiftType) : null;
        if ($rule === null) {
            return false;
        }

        $watermark = $states["{$userId}:{$rule->id}"]['last_processed_date'] ?? null;

        return $watermark !== null && $date->copy()->startOfDay()->lte($watermark);
    }

    public function recordUnassignment(array &$states, Collection $rules, int $userId, ?string $shiftType): void
    {
        $rule = $shiftType !== null ? $rules->get($shiftType) : null;
        if ($rule === null) {
            return;
        }

        $key = "{$userId}:{$rule->id}";
        $states[$key]['current_count'] = max(0, $states[$key]['current_count'] - 1);
        $states[$key]['dirty'] = true;
    }

    public function persist(array $states): void
    {
        DB::transaction(function () use ($states) {
            foreach ($states as $key => $state) {
                if (!$state['dirty']) {
                    continue;
                }

                [$userId, $ruleId] = array_map('intval', explode(':', $key));

                ShiftStreakState::updateOrCreate(
                    ['user_id' => $userId, 'shift_streak_rule_id' => $ruleId],
                    [
                        'current_count' => $state['current_count'],
                        'resting_until' => $state['resting_until'],
                        'last_processed_date' => $state['last_processed_date'],
                    ]
                );
            }
        });
    }
}
