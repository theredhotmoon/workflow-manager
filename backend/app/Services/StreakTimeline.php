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
 * Auto-fill asks wouldConflict() once per (employee, shift), so the replay works on integer day
 * numbers (days since 1970-01-01) and only scans still-open windows; Carbon is built only for
 * returned values. The full replay of each user's current events (the "baseline") is cached, with
 * a checkpoint of the replay state every CHECKPOINT_EVERY events, and cleared by append().
 * isResting() resumes from the checkpoint before the day; newlyResting() resumes from the one
 * before the candidate and stops as soon as its state matches the baseline again (from there on
 * both replays are identical).
 */
final class StreakTimeline
{
    public const NOT_COUNTED = 'not_counted';
    public const COUNTS = 'counts';
    public const EXTENDS_REST = 'extends_rest';
    public const POLICIES = [self::NOT_COUNTED, self::COUNTS, self::EXTENDS_REST];

    /** @var array<int, list<array{day: int, shift_number: int, shift_type: ?string, rest_policy: ?string, assignment_id: ?int}>> */
    private array $eventsByUser = [];

    private const CHECKPOINT_EVERY = 16;

    /**
     * userId => full replay of the user's current events, with checkpoints (cleared by append).
     *
     * @var array<int, array{counts: array<string, int>, windows: array<int, array{rule: ShiftStreakRule, type: string, start: int, end: int}>, covered: array<int, int>, checkpoints: array<int, array{0: array<string, int>, 1: array<int, int>}>}>
     */
    private array $baseline = [];

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
        unset($this->baseline[$userId]);
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
     * The window covering $day with the latest FINAL end, over the full replay (later extends_rest
     * included). Windows opened on or after $day start after it and extensions only lengthen, so the
     * covering set is the one activeWindow() sees; only the reported end can be later.
     *
     * @return array{rule: ShiftStreakRule, start: Carbon, end: Carbon}|null
     */
    public function finalWindowAt(int $userId, \Carbon\Carbon $day): ?array
    {
        $window = self::longestCovering($this->baseline($userId)['windows'], self::dayNumber($day->toDateString()));

        return $window === null ? null : [
            'rule' => $window['rule'],
            'start' => self::toCarbon($window['start']),
            'end' => self::toCarbon($window['end']),
        ];
    }

    /**
     * Assignments of the user that carry a rest_policy but are not covered by any rest window. The
     * replay reads a policy only for a covered event, so clearing these changes no window and no
     * coverage: after one clearing pass this list is empty.
     *
     * @return list<int>
     */
    public function uncoveredWithPolicy(int $userId): array
    {
        $events = $this->eventsByUser[$userId] ?? [];
        $covered = $this->baseline($userId)['covered'];
        $ids = [];
        foreach ($events as $pos => $event) {
            if ($event['rest_policy'] !== null && $event['assignment_id'] !== null && !isset($covered[$pos])) {
                $ids[] = $event['assignment_id'];
            }
        }

        return $ids;
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
        [$events, $newly, $windows] = $this->newlyCovered($userId, $shiftType, $date, $shiftNumber, $restPolicy, false);

        $affected = [];
        foreach ($newly as $pos) {
            $window = self::longestCovering($windows, $events[$pos]['day']);
            $affected[] = [
                'assignment_id' => $events[$pos]['assignment_id'],
                'date' => gmdate('Y-m-d', $events[$pos]['day'] * 86400),
                'shift_number' => $events[$pos]['shift_number'],
                'shift_type' => $events[$pos]['shift_type'],
                'resting_until' => gmdate('Y-m-d', $window['end'] * 86400),
                'rule' => $window['rule'],
            ];
        }

        return $affected;
    }

    /**
     * Replays the user's events with the candidate added. Returns the events, the positions (in the
     * events) the candidate newly puts into rest, in order, and the windows with their final ends.
     * With $firstOnly it stops at the first such position (the windows are then not final).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<int>, 2: array<int, array{rule: ShiftStreakRule, type: string, start: int, end: int}>}
     */
    private function newlyCovered(int $userId, ?string $shiftType, \Carbon\Carbon $date, int $shiftNumber, ?string $restPolicy, bool $firstOnly): array
    {
        $events = $this->eventsByUser[$userId] ?? [];
        $day = self::dayNumber($date->toDateString());
        if ($this->rules->isEmpty() || $events === [] || end($events)['day'] <= $day) {
            return [$events, [], []];
        }

        $base = $this->baseline($userId);
        $count = count($events);
        $candidate = ['day' => $day, 'shift_number' => $shiftNumber, 'shift_type' => $shiftType,
            'rest_policy' => $restPolicy, 'assignment_id' => null];
        // The candidate goes after every event that sorts before or equal to it (stable-sort order).
        $insertAt = self::upperBound($events, $day, $shiftNumber);

        // Events before the candidate replay as in the baseline: resume from the checkpoint before it.
        [$counts, $windows, $open, $nextKey, $from] = $this->resume($base, $insertAt);
        for ($pos = $from; $pos < $insertAt; $pos++) {
            $this->step($events[$pos], $counts, $windows, $open, $nextKey);
        }
        $this->step($candidate, $counts, $windows, $open, $nextKey);

        $newly = [];
        for ($pos = $insertAt; $pos < $count; $pos++) {
            // At a checkpoint, a state equal to the baseline's means every later event replays
            // exactly as in the baseline: nothing more is newly covered. The windows covering the
            // shifts found so far already have their final end: one still open here would equal a
            // baseline window, which then covered those shifts too (ends only grow), so they wouldn't be new.
            if ($pos % self::CHECKPOINT_EVERY === 0 && $this->sameAsBaseline($base, $pos, $events[$pos]['day'], $counts, $windows, $open)) {
                break;
            }
            if ($this->step($events[$pos], $counts, $windows, $open, $nextKey) !== null && !isset($base['covered'][$pos])) {
                $newly[] = $pos;
                if ($firstOnly) {
                    break;
                }
            }
        }

        return [$events, $newly, $windows];
    }

    /**
     * Every assignment the chosen later-shift policy ends up applying to once the candidate is added:
     * the shifts newlyResting() reports, then, round by round, the shifts that stamping $policy on
     * the previous round pushes into rest (counts can complete a new streak, extends_rest lengthens
     * a window). Each round's shifts are those covered now but not in the previous round's state
     * (the first round compares with the history without the candidate). From the second round on, a
     * shift that was already on a rest day before the assign with its own policy keeps that answer:
     * the manager was never shown it. Only shifts still covered at the end are returned (a later
     * round can move a window off an earlier one). Ids in stamping order.
     *
     * @return list<int>
     */
    public function cascadePolicy(int $userId, ?string $shiftType, \Carbon\Carbon $date, int $shiftNumber, ?string $restPolicy, string $policy): array
    {
        $events = $this->eventsByUser[$userId] ?? [];
        $day = self::dayNumber($date->toDateString());
        if ($this->rules->isEmpty() || $events === [] || end($events)['day'] <= $day) {
            return [];
        }

        $before = $this->coveredIds($events, $this->baseline($userId)['covered']);
        $answered = [];
        foreach ($before as $id => $pos) {
            if ($events[$pos]['rest_policy'] !== null) {
                $answered[$id] = true;
            }
        }
        $previous = $before;
        [$with] = self::withCandidate($events, $day, $shiftType, $shiftNumber, $restPolicy);
        $stamped = [];
        // Every round stamps at least one new id, so the loop ends on its own; the bound is a safeguard.
        for ($round = 0; $round <= count($events); $round++) {
            $covered = $this->coveredIds($with, $this->replayEvents($with, PHP_INT_MAX)['covered']);
            $new = [];
            foreach ($covered as $id => $pos) {
                if (!isset($previous[$id]) && !isset($stamped[$id]) && !isset($answered[$id])) {
                    $new[$id] = $pos;
                }
            }
            if ($new === []) {
                break;
            }
            foreach ($new as $id => $pos) {
                $with[$pos]['rest_policy'] = $policy;
                $stamped[$id] = true;
            }
            $previous = $covered;
        }

        // A policy only matters on a rest day, so dropping the ones a later round uncovered changes nothing.
        $final = $this->coveredIds($with, $this->replayEvents($with, PHP_INT_MAX)['covered']);

        return array_values(array_filter(array_keys($stamped), fn ($id) => isset($final[$id])));
    }

    public function wouldConflict(int $userId, ?string $shiftType, \Carbon\Carbon $date, int $shiftNumber): bool
    {
        // Only whether anything is newly put into rest matters: stop at the first such shift.
        return $this->isResting($userId, $date)
            || $this->newlyCovered($userId, $shiftType, $date, $shiftNumber, null, true)[1] !== [];
    }

    /** @return array<int, string> assignment_id => resting_until (Y-m-d) for every covered assignment */
    public function restingAssignments(int $userId): array
    {
        $events = $this->eventsByUser[$userId] ?? [];
        $state = $this->baseline($userId);
        $resting = [];
        foreach ($state['covered'] as $pos => $_) {
            if ($events[$pos]['assignment_id'] !== null) {
                $window = self::longestCovering($state['windows'], $events[$pos]['day']);
                $resting[$events[$pos]['assignment_id']] = gmdate('Y-m-d', $window['end'] * 86400);
            }
        }

        return $resting;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array<int, int>  $covered  replayEvents()['covered'] for $events
     * @return array<int, int> assignment_id => position, for covered events that are stored assignments
     */
    private function coveredIds(array $events, array $covered): array
    {
        $ids = [];
        foreach ($covered as $pos => $_) {
            if ($events[$pos]['assignment_id'] !== null) {
                $ids[$events[$pos]['assignment_id']] = $pos;
            }
        }

        return $ids;
    }

    /**
     * The sorted events with a candidate inserted after every event that sorts before or equal to it
     * (what a stable sort would do with it appended last).
     *
     * @param  list<array<string, mixed>>  $events
     * @return array{0: list<array<string, mixed>>, 1: int} the new list and the candidate's position
     */
    private static function withCandidate(array $events, int $day, ?string $shiftType, int $shiftNumber, ?string $restPolicy): array
    {
        $candidate = ['day' => $day, 'shift_number' => $shiftNumber, 'shift_type' => $shiftType,
            'rest_policy' => $restPolicy, 'assignment_id' => null];
        $insertAt = count($events);
        foreach ($events as $i => $event) {
            if (self::compareEvents($event, $candidate) > 0) {
                $insertAt = $i;
                break;
            }
        }
        array_splice($events, $insertAt, 0, [$candidate]);

        return [$events, $insertAt];
    }

    /** @return array{rule: ShiftStreakRule, type: string, start: int, end: int}|null */
    private function latestCovering(int $userId, int $day): ?array
    {
        if ($this->rules->isEmpty()) {
            return null;
        }

        // The state after every event before $day: resume from the checkpoint before it.
        $events = $this->eventsByUser[$userId] ?? [];
        $until = self::upperBound($events, $day - 1, PHP_INT_MAX);
        [$counts, $windows, $open, $nextKey, $from] = $this->resume($this->baseline($userId), $until);
        for ($pos = $from; $pos < $until; $pos++) {
            $this->step($events[$pos], $counts, $windows, $open, $nextKey);
        }

        // A window that is no longer open ended before an earlier event's day, so it can't cover $day.
        $latest = null;
        foreach ($open as $key) {
            $window = $windows[$key];
            if ($day >= $window['start'] && $day <= $window['end']
                && ($latest === null || $window['end'] > $latest['end'])) {
                $latest = $window;
            }
        }

        return $latest;
    }

    /** The full replay of the user's current events, with checkpoints; cached until append(). */
    private function baseline(int $userId): array
    {
        return $this->baseline[$userId] ??= $this->replayEvents($this->eventsByUser[$userId] ?? [], PHP_INT_MAX, true);
    }

    /**
     * The baseline's replay state at the last checkpoint at or before event $pos: counts, the windows
     * open there (keyed as in the baseline, in creation order, with their end at that point), their
     * keys, the next free window key, and the checkpoint's position.
     *
     * @return array{0: array<string, int>, 1: array<int, array<string, mixed>>, 2: list<int>, 3: int, 4: int}
     */
    private function resume(array $base, int $pos): array
    {
        $checkpoint = intdiv($pos, self::CHECKPOINT_EVERY) * self::CHECKPOINT_EVERY;
        [$counts, $ends] = $base['checkpoints'][$checkpoint];
        $windows = [];
        foreach ($ends as $i => $end) {
            $windows[$i] = $base['windows'][$i];
            $windows[$i]['end'] = $end;
        }

        return [$counts, $windows, array_keys($windows), count($base['windows']), $checkpoint];
    }

    /**
     * Whether this replay state equals the baseline's before event $pos (a checkpoint): the same
     * counts and the same windows that can still cover an event on or after $day (that event's day).
     */
    private function sameAsBaseline(array $base, int $pos, int $day, array $counts, array $windows, array $open): bool
    {
        [$baseCounts, $baseEnds] = $base['checkpoints'][$pos];
        foreach ($this->streakLength as $type => $_) {
            if (($counts[$type] ?? 0) !== ($baseCounts[$type] ?? 0)) {
                return false;
            }
        }

        $theirs = [];
        foreach ($baseEnds as $i => $end) {
            if ($end >= $day) {
                $theirs[] = $base['windows'][$i]['type'].'|'.$base['windows'][$i]['start'].'|'.$end;
            }
        }
        $mine = [];
        foreach ($open as $key) {
            if ($windows[$key]['end'] >= $day) {
                $mine[] = $windows[$key]['type'].'|'.$windows[$key]['start'].'|'.$windows[$key]['end'];
            }
        }
        sort($theirs);
        sort($mine);

        return $mine === $theirs;
    }

    /** The first position whose (day, shift_number) sorts after ($day, $shiftNumber). */
    private static function upperBound(array $events, int $day, int $shiftNumber): int
    {
        $low = 0;
        $high = count($events);
        while ($low < $high) {
            $mid = ($low + $high) >> 1;
            if ($events[$mid]['day'] > $day || ($events[$mid]['day'] === $day && $events[$mid]['shift_number'] > $shiftNumber)) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }

        return $low;
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
     * inside an open window to the index of the covering window ending last. With $checkpoints,
     * 'checkpoints' holds the state before every CHECKPOINT_EVERY-th event and where the replay
     * stopped (after the last event, for a full replay), as [counts, open window index => its end then].
     *
     * @param  list<array<string, mixed>>  $events
     * @return array{counts: array<string, int>, windows: list<array{rule: ShiftStreakRule, type: string, start: int, end: int}>, covered: array<int, int>, checkpoints: array<int, array{0: array<string, int>, 1: array<int, int>}>}
     */
    private function replayEvents(array $events, int $until, bool $checkpoints = false): array
    {
        $counts = [];
        $windows = [];
        $covered = [];
        $saved = [];
        if ($this->rules->isEmpty()) {
            return ['counts' => $counts, 'windows' => $windows, 'covered' => $covered, 'checkpoints' => $saved];
        }

        // Indexes into $windows that can still cover the current event. Events come in day order,
        // so a window ending before the current day can never cover (or be extended by) a later one.
        $open = [];
        $nextKey = 0;
        $count = count($events);
        for ($pos = 0; $pos < $count; $pos++) {
            if ($events[$pos]['day'] >= $until) {
                break;
            }
            if ($checkpoints && $pos % self::CHECKPOINT_EVERY === 0) {
                $saved[$pos] = self::checkpoint($counts, $windows, $open);
            }
            $latest = $this->step($events[$pos], $counts, $windows, $open, $nextKey);
            if ($latest !== null) {
                $covered[$pos] = $latest;
            }
        }
        if ($checkpoints) {
            $saved[$pos] = self::checkpoint($counts, $windows, $open);
        }

        return ['counts' => $counts, 'windows' => $windows, 'covered' => $covered, 'checkpoints' => $saved];
    }

    /** @return array{0: array<string, int>, 1: array<int, int>} counts, open window index => end */
    private static function checkpoint(array $counts, array $windows, array $open): array
    {
        $ends = [];
        foreach ($open as $i) {
            $ends[$i] = $windows[$i]['end'];
        }

        return [$counts, $ends];
    }

    /**
     * Replays one event. Returns the key of the covering window ending last, or null if no window
     * covers it. A new window is stored under $nextKey (then incremented) and added to $open.
     */
    private function step(array $event, array &$counts, array &$windows, array &$open, int &$nextKey): ?int
    {
        $day = $event['day'];
        $covering = [];
        foreach ($open as $k => $i) {
            if ($windows[$i]['end'] < $day) {
                unset($open[$k]);
            } elseif ($day >= $windows[$i]['start']) {
                $covering[] = $i;
            }
        }

        $latest = null;
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
            if ($policy !== self::COUNTS) {
                return $latest;
            }
        }

        $type = $event['shift_type'];
        if ($type === null || !isset($this->streakLength[$type])) {
            return $latest;
        }

        $counts[$type] = ($counts[$type] ?? 0) + 1;
        if ($counts[$type] >= $this->streakLength[$type]) {
            $windows[$nextKey] = [
                'rule' => $this->rules->get($type),
                'type' => $type,
                'start' => $day + 1,
                'end' => $day + $this->restDays[$type],
            ];
            $open[] = $nextKey;
            $nextKey++;
            $counts[$type] = 0;
        }

        return $latest;
    }

    /**
     * The window containing $day with the latest FINAL end. Later extensions can lengthen a window
     * other than the one that was longest when the event was replayed, so this is read afterwards.
     *
     * @param  list<array{rule: ShiftStreakRule, type: string, start: int, end: int}>  $windows
     * @return array{rule: ShiftStreakRule, type: string, start: int, end: int}|null
     */
    private static function longestCovering(array $windows, int $day): ?array
    {
        $best = null;
        foreach ($windows as $window) {
            if ($day >= $window['start'] && $day <= $window['end'] && ($best === null || $window['end'] > $best['end'])) {
                $best = $window;
            }
        }

        return $best;
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
