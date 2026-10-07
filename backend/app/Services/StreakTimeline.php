<?php
// backend/app/Services/StreakTimeline.php

namespace App\Services;

use App\Models\ShiftStreakRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Streak state derived by replaying an employee's assignments, in (date, slot) order, through a
 * group's streak rules. Nothing is stored, so it can't drift and needs no locking. Rules:
 * docs/superpowers/specs/2026-10-07-streak-replay-design.md ("Semantics").
 */
final class StreakTimeline
{
    public const NOT_COUNTED = 'not_counted';
    public const COUNTS = 'counts';
    public const EXTENDS_REST = 'extends_rest';
    public const POLICIES = [self::NOT_COUNTED, self::COUNTS, self::EXTENDS_REST];

    /** @var array<int, list<array{date: string, shift_number: int, shift_type: ?string, rest_policy: ?string}>> */
    private array $eventsByUser = [];

    /**
     * @param  Collection<string, ShiftStreakRule>  $rules  keyed by shift_type
     * @param  array<int, list<array{date: string, shift_number: int, shift_type: ?string, rest_policy: ?string}>>  $eventsByUser
     */
    public function __construct(private Collection $rules, array $eventsByUser)
    {
        foreach ($eventsByUser as $userId => $events) {
            usort($events, [self::class, 'compareEvents']);
            $this->eventsByUser[(int) $userId] = $events;
        }
    }

    public function hasRules(): bool
    {
        return $this->rules->isNotEmpty();
    }

    public function append(int $userId, ?string $shiftType, Carbon $date, int $shiftNumber, ?string $restPolicy = null): void
    {
        $this->eventsByUser[$userId][] = [
            'date' => $date->toDateString(),
            'shift_number' => $shiftNumber,
            'shift_type' => $shiftType,
            'rest_policy' => $restPolicy,
        ];
        usort($this->eventsByUser[$userId], [self::class, 'compareEvents']);
    }

    public function isResting(int $userId, Carbon $day): bool
    {
        return $this->activeWindow($userId, $day) !== null;
    }

    /** @return array{rule: ShiftStreakRule, start: Carbon, end: Carbon}|null the covering window that ends last */
    public function activeWindow(int $userId, Carbon $day): ?array
    {
        $day = $day->copy()->startOfDay();
        $latest = null;
        foreach ($this->replay($userId, $day)['windows'] as $window) {
            if ($day->betweenIncluded($window['start'], $window['end'])
                && ($latest === null || $window['end']->gt($latest['end']))) {
                $latest = $window;
            }
        }

        return $latest;
    }

    /**
     * Per-rule status after every assignment before $until. resting_until is the end of the rule's
     * latest window ending on or after $windowsFrom (default $until), or null.
     *
     * @return list<array{shift_type: string, current_count: int, streak_length: int, resting_until: ?string}>
     */
    public function statusAsOf(int $userId, Carbon $until, ?Carbon $windowsFrom = null): array
    {
        $state = $this->replay($userId, $until->copy()->startOfDay());
        $from = ($windowsFrom ?? $until)->copy()->startOfDay();

        $status = [];
        foreach ($this->rules as $type => $rule) {
            $end = null;
            foreach ($state['windows'] as $window) {
                if ($window['rule']->shift_type === $type && $window['end']->gte($from)
                    && ($end === null || $window['end']->gt($end))) {
                    $end = $window['end'];
                }
            }
            $status[] = [
                'shift_type' => $type,
                'current_count' => $state['counts'][$type] ?? 0,
                'streak_length' => (int) $rule->streak_length,
                'resting_until' => $end === null ? null : $end->toDateString(),
            ];
        }

        return $status;
    }

    /** @return array{counts: array<string, int>, windows: list<array{rule: ShiftStreakRule, start: Carbon, end: Carbon}>} */
    private function replay(int $userId, Carbon $until): array
    {
        $counts = [];
        $windows = [];
        if ($this->rules->isEmpty()) {
            return ['counts' => $counts, 'windows' => $windows];
        }

        $untilStr = $until->toDateString();
        foreach ($this->eventsByUser[$userId] ?? [] as $event) {
            if ($event['date'] >= $untilStr) {
                break;
            }
            $day = Carbon::parse($event['date'])->startOfDay();

            $covering = [];
            foreach ($windows as $i => $window) {
                if ($day->betweenIncluded($window['start'], $window['end'])) {
                    $covering[] = $i;
                }
            }

            if ($covering !== []) {
                $policy = $event['rest_policy'] ?? self::NOT_COUNTED;
                if ($policy === self::EXTENDS_REST) {
                    foreach ($covering as $i) {
                        $candidate = $day->copy()->addDays((int) $windows[$i]['rule']->rest_days);
                        if ($candidate->gt($windows[$i]['end'])) {
                            $windows[$i]['end'] = $candidate;
                        }
                    }
                }
                if ($policy !== self::COUNTS) {
                    continue;
                }
            }

            $rule = $event['shift_type'] !== null ? $this->rules->get($event['shift_type']) : null;
            if ($rule === null) {
                continue;
            }

            $type = $rule->shift_type;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
            if ($counts[$type] >= (int) $rule->streak_length) {
                $windows[] = [
                    'rule' => $rule,
                    'start' => $day->copy()->addDay(),
                    'end' => $day->copy()->addDays((int) $rule->rest_days),
                ];
                $counts[$type] = 0;
            }
        }

        return ['counts' => $counts, 'windows' => $windows];
    }

    private static function compareEvents(array $a, array $b): int
    {
        return [$a['date'], $a['shift_number']] <=> [$b['date'], $b['shift_number']];
    }
}
