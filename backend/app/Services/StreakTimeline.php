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
 *
 * Auto-fill asks isResting() once per (employee, shift), so the replay works on integer day
 * numbers (days since 1970-01-01) and only scans still-open windows; Carbon is built only for
 * returned values.
 */
final class StreakTimeline
{
    public const NOT_COUNTED = 'not_counted';
    public const COUNTS = 'counts';
    public const EXTENDS_REST = 'extends_rest';
    public const POLICIES = [self::NOT_COUNTED, self::COUNTS, self::EXTENDS_REST];

    /** @var array<int, list<array{day: int, shift_number: int, shift_type: ?string, rest_policy: ?string, assignment_id: ?int}>> */
    private array $eventsByUser = [];

    /** @var array<string, int> */
    private array $streakLength = [];

    /** @var array<string, int> */
    private array $restDays = [];

    /**
     * @param  Collection<string, ShiftStreakRule>  $rules  keyed by shift_type
     * @param  array<int, list<array{date: string, shift_number: int, shift_type: ?string, rest_policy: ?string}>>  $eventsByUser
     */
    public function __construct(private Collection $rules, array $eventsByUser)
    {
        foreach ($rules as $type => $rule) {
            $this->streakLength[$type] = (int) $rule->streak_length;
            $this->restDays[$type] = (int) $rule->rest_days;
        }
        foreach ($eventsByUser as $userId => $events) {
            $mapped = [];
            foreach ($events as $event) {
                $mapped[] = [
                    'day' => self::dayNumber($event['date']),
                    'shift_number' => (int) $event['shift_number'],
                    'shift_type' => $event['shift_type'],
                    'rest_policy' => $event['rest_policy'] ?? null,
                    'assignment_id' => isset($event['assignment_id']) ? (int) $event['assignment_id'] : null,
                ];
            }
            usort($mapped, [self::class, 'compareEvents']);
            $this->eventsByUser[(int) $userId] = $mapped;
        }
    }

    public function hasRules(): bool
    {
        return $this->rules->isNotEmpty();
    }

    public function append(int $userId, ?string $shiftType, \Carbon\Carbon $date, int $shiftNumber, ?string $restPolicy = null, ?int $assignmentId = null): void
    {
        $event = [
            'day' => self::dayNumber($date->toDateString()),
            'shift_number' => $shiftNumber,
            'shift_type' => $shiftType,
            'rest_policy' => $restPolicy,
            'assignment_id' => $assignmentId,
        ];
        $events = $this->eventsByUser[$userId] ?? [];
        $last = end($events);
        $events[] = $event;
        if ($last !== false && self::compareEvents($last, $event) > 0) {
            usort($events, [self::class, 'compareEvents']);
        }
        $this->eventsByUser[$userId] = $events;
    }

    public function isResting(int $userId, \Carbon\Carbon $day): bool
    {
        return $this->latestCovering($userId, self::dayNumber($day->toDateString())) !== null;
    }

    /** @return array{rule: ShiftStreakRule, start: Carbon, end: Carbon}|null the covering window that ends last */
    public function activeWindow(int $userId, \Carbon\Carbon $day): ?array
    {
        $window = $this->latestCovering($userId, self::dayNumber($day->toDateString()));

        return $window === null ? null : [
            'rule' => $window['rule'],
            'start' => self::toCarbon($window['start']),
            'end' => self::toCarbon($window['end']),
        ];
    }

    /**
     * Per-rule status after every assignment before $until. resting_until is the end of the rule's
     * latest window ending on or after $windowsFrom (default $until), or null.
     *
     * @return list<array{shift_type: string, current_count: int, streak_length: int, resting_until: ?string}>
     */
    public function statusAsOf(int $userId, \Carbon\Carbon $until, ?\Carbon\Carbon $windowsFrom = null): array
    {
        $state = $this->replay($userId, self::dayNumber($until->toDateString()));
        $from = self::dayNumber(($windowsFrom ?? $until)->toDateString());

        $status = [];
        foreach ($this->rules as $type => $rule) {
            $end = null;
            foreach ($state['windows'] as $window) {
                if ($window['type'] === $type && $window['end'] >= $from
                    && ($end === null || $window['end'] > $end)) {
                    $end = $window['end'];
                }
            }
            $status[] = [
                'shift_type' => $type,
                'current_count' => $state['counts'][$type] ?? 0,
                'streak_length' => $this->streakLength[$type],
                'resting_until' => $end === null ? null : gmdate('Y-m-d', $end * 86400),
            ];
        }

        return $status;
    }

    /**
     * Existing assignments of the user that would newly fall inside a rest window if this candidate
     * assignment were added (a candidate on day D only affects days > D). Chain effects are included:
     * the whole history is replayed with and without the candidate.
     *
     * @return list<array{assignment_id: ?int, date: string, shift_number: int, shift_type: ?string, resting_until: string, rule: ShiftStreakRule}>
     */
    public function newlyResting(int $userId, ?string $shiftType, \Carbon\Carbon $date, int $shiftNumber, ?string $restPolicy = null): array
    {
        $events = $this->eventsByUser[$userId] ?? [];
        $day = self::dayNumber($date->toDateString());
        if ($this->rules->isEmpty() || $events === [] || end($events)['day'] <= $day) {
            return [];
        }

        $before = $this->replayEvents($events, PHP_INT_MAX)['covered'];

        $with = [];
        foreach ($events as $i => $event) {
            $with[] = $event + ['origin' => $i];
        }
        $with[] = ['day' => $day, 'shift_number' => $shiftNumber, 'shift_type' => $shiftType,
            'rest_policy' => $restPolicy, 'assignment_id' => null, 'origin' => -1];
        usort($with, [self::class, 'compareEvents']);
        $after = $this->replayEvents($with, PHP_INT_MAX);

        $affected = [];
        foreach ($after['covered'] as $pos => $windowIndex) {
            $origin = $with[$pos]['origin'];
            if ($origin < 0 || isset($before[$origin])) {
                continue;
            }
            $window = $after['windows'][$windowIndex];
            $affected[] = [
                'assignment_id' => $with[$pos]['assignment_id'],
                'date' => gmdate('Y-m-d', $with[$pos]['day'] * 86400),
                'shift_number' => $with[$pos]['shift_number'],
                'shift_type' => $with[$pos]['shift_type'],
                'resting_until' => gmdate('Y-m-d', $window['end'] * 86400),
                'rule' => $window['rule'],
            ];
        }

        return $affected;
    }

    public function wouldConflict(int $userId, ?string $shiftType, \Carbon\Carbon $date, int $shiftNumber): bool
    {
        return $this->isResting($userId, $date)
            || $this->newlyResting($userId, $shiftType, $date, $shiftNumber) !== [];
    }

    /** @return array<int, string> assignment_id => resting_until (Y-m-d) for every covered assignment */
    public function restingAssignments(int $userId): array
    {
        $events = $this->eventsByUser[$userId] ?? [];
        $state = $this->replayEvents($events, PHP_INT_MAX);
        $resting = [];
        foreach ($state['covered'] as $pos => $windowIndex) {
            if ($events[$pos]['assignment_id'] !== null) {
                $resting[$events[$pos]['assignment_id']] = gmdate('Y-m-d', $state['windows'][$windowIndex]['end'] * 86400);
            }
        }

        return $resting;
    }

    /** @return array{rule: ShiftStreakRule, type: string, start: int, end: int}|null */
    private function latestCovering(int $userId, int $day): ?array
    {
        $latest = null;
        foreach ($this->replay($userId, $day)['windows'] as $window) {
            if ($day >= $window['start'] && $day <= $window['end']
                && ($latest === null || $window['end'] > $latest['end'])) {
                $latest = $window;
            }
        }

        return $latest;
    }

    /**
     * Replays the user's events with day < $until. Windows come back in creation order.
     *
     * @return array{counts: array<string, int>, windows: list<array{rule: ShiftStreakRule, type: string, start: int, end: int}>, covered: array<int, int>}
     */
    private function replay(int $userId, int $until): array
    {
        return $this->replayEvents($this->eventsByUser[$userId] ?? [], $until);
    }

    /**
     * Replays a sorted event list (0-based). 'covered' maps the position of every event that fell
     * inside an open window to the index of the covering window ending last.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array{counts: array<string, int>, windows: list<array{rule: ShiftStreakRule, type: string, start: int, end: int}>, covered: array<int, int>}
     */
    private function replayEvents(array $events, int $until): array
    {
        $counts = [];
        $windows = [];
        $covered = [];
        if ($this->rules->isEmpty()) {
            return ['counts' => $counts, 'windows' => $windows, 'covered' => $covered];
        }

        // Indexes into $windows that can still cover the current event. Events come in day order,
        // so a window ending before the current day can never cover (or be extended by) a later one.
        $open = [];
        foreach ($events as $pos => $event) {
            $day = $event['day'];
            if ($day >= $until) {
                break;
            }

            $covering = [];
            foreach ($open as $k => $i) {
                if ($windows[$i]['end'] < $day) {
                    unset($open[$k]);
                } elseif ($day >= $windows[$i]['start']) {
                    $covering[] = $i;
                }
            }

            if ($covering !== []) {
                $policy = $event['rest_policy'] ?? self::NOT_COUNTED;
                if ($policy === self::EXTENDS_REST) {
                    foreach ($covering as $i) {
                        $candidate = $day + $this->restDays[$windows[$i]['type']];
                        if ($candidate > $windows[$i]['end']) {
                            $windows[$i]['end'] = $candidate;
                        }
                    }
                }
                $latest = $covering[0];
                foreach ($covering as $i) {
                    if ($windows[$i]['end'] > $windows[$latest]['end']) {
                        $latest = $i;
                    }
                }
                $covered[$pos] = $latest;
                if ($policy !== self::COUNTS) {
                    continue;
                }
            }

            $type = $event['shift_type'];
            if ($type === null || !isset($this->streakLength[$type])) {
                continue;
            }

            $counts[$type] = ($counts[$type] ?? 0) + 1;
            if ($counts[$type] >= $this->streakLength[$type]) {
                $windows[] = [
                    'rule' => $this->rules->get($type),
                    'type' => $type,
                    'start' => $day + 1,
                    'end' => $day + $this->restDays[$type],
                ];
                $open[] = count($windows) - 1;
                $counts[$type] = 0;
            }
        }

        return ['counts' => $counts, 'windows' => $windows, 'covered' => $covered];
    }

    /** Days since 1970-01-01 for a 'Y-m-d' date. */
    private static function dayNumber(string $date): int
    {
        [$y, $m, $d] = explode('-', substr($date, 0, 10));

        return intdiv(gmmktime(0, 0, 0, (int) $m, (int) $d, (int) $y), 86400);
    }

    private static function toCarbon(int $day): Carbon
    {
        return Carbon::parse(gmdate('Y-m-d', $day * 86400))->startOfDay();
    }

    private static function compareEvents(array $a, array $b): int
    {
        return [$a['day'], $a['shift_number']] <=> [$b['day'], $b['shift_number']];
    }
}
