<?php

namespace App\Services;

use App\Models\OnCallCallback;
use App\Models\Shift;
use App\Support\BusinessDate;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Each employee's busy time as sorted [start, end) intervals of Unix timestamps: their shift
 * assignments in every group, optionally their on-call callbacks, and whatever an auto-fill run
 * adds. Shift instants come from BusinessDate (Europe/Warsaw, overnight shifts end the next day),
 * so gaps are elapsed time and stay right across DST changes.
 */
class BusyIntervals
{
    /** Minimum uninterrupted daily rest between two busy intervals (Polish Labour Code art. 132). */
    public const MIN_DAILY_REST_HOURS = 11;

    /** Minimum uninterrupted rest in each settlement week (Polish Labour Code art. 133). */
    public const MIN_WEEKLY_REST_HOURS = 35;

    /** @var array<int, array<int, array{0: int, 1: int}>> userId => intervals sorted by start */
    private array $byUser = [];

    /** @var array<int, int> userId => longest interval in seconds, bounds the backward scans */
    private array $maxLength = [];

    /**
     * Every assignment of the given users to a shift dated $fromDate..$toDate, in any group, in one
     * query. Returns the shifts with the assignee in `busy_user_id`, so callers can also count them.
     *
     * @return Collection<int, Shift>
     */
    public static function loadAssignedShifts(array $userIds, string $fromDate, string $toDate)
    {
        if ($userIds === []) {
            return collect();
        }

        return Shift::query()
            ->join('shift_assignments', 'shift_assignments.shift_id', '=', 'shifts.id')
            ->whereIn('shift_assignments.user_id', $userIds)
            ->whereBetween('shifts.date', [$fromDate, $toDate])
            ->orderBy('shifts.date')
            ->get(['shifts.*', 'shift_assignments.user_id as busy_user_id']);
    }

    /**
     * The users' on-call callbacks overlapping $fromDate..$toDate (inclusive), in any group.
     *
     * @return Collection<int, OnCallCallback>
     */
    public static function loadCallbacks(array $userIds, string $fromDate, string $toDate)
    {
        if ($userIds === []) {
            return collect();
        }

        $toExclusive = Carbon::parse($toDate)->addDay()->toDateString();

        return OnCallCallback::whereHas('onCallAssignment', fn ($q) => $q->whereIn('user_id', $userIds))
            ->where('starts_at', '<', $toExclusive)
            ->where('ends_at', '>', $fromDate)
            ->with('onCallAssignment:id,user_id')
            ->orderBy('starts_at')
            ->get();
    }

    /** A shift's [start, end) as timestamps. */
    public static function shiftSpan(Shift $shift): array
    {
        return [BusinessDate::shiftStart($shift)->getTimestamp(), BusinessDate::shiftEnd($shift)->getTimestamp()];
    }

    /**
     * A callback's [start, end) as timestamps. On-call times are stored as Warsaw wall-clock values
     * in UTC-typed columns, so they are read back as wall clock in the business timezone.
     */
    public static function callbackSpan(OnCallCallback $callback): array
    {
        return [self::wallClock($callback->starts_at)->getTimestamp(), self::wallClock($callback->ends_at)->getTimestamp()];
    }

    /** The business date (Y-m-d) a callback belongs to: its local start date. */
    public static function callbackDate(OnCallCallback $callback): string
    {
        return $callback->starts_at->format('Y-m-d');
    }

    /**
     * A stored on-call time (Warsaw wall clock in a UTC-typed column) as the instant it names, so
     * differences between two of them are elapsed time across DST changes.
     */
    public static function wallClock(Carbon $stored): Carbon
    {
        return Carbon::parse($stored->format('Y-m-d H:i:s'), BusinessDate::timezone());
    }

    public function add(int $userId, int $start, int $end): void
    {
        $list = $this->byUser[$userId] ?? [];
        array_splice($list, self::lowerBound($list, $start), 0, [[$start, $end]]);
        $this->byUser[$userId] = $list;
        $this->maxLength[$userId] = max($this->maxLength[$userId] ?? 0, $end - $start);
    }

    /**
     * Whether [start, end) keeps at least MIN_DAILY_REST_HOURS from every other busy interval of
     * the user, before and after it. Overlap is always refused. Exactly 11 h is allowed.
     */
    public function allowsDailyRest(int $userId, int $start, int $end): bool
    {
        $list = $this->byUser[$userId] ?? [];
        if ($list === []) {
            return true;
        }

        $rest = self::MIN_DAILY_REST_HOURS * 3600;
        $maxLength = $this->maxLength[$userId];

        // Intervals starting at or after end + rest leave enough rest after the candidate; each one
        // starting earlier must end at least `rest` before the candidate starts.
        for ($i = self::lowerBound($list, $end + $rest) - 1; $i >= 0; $i--) {
            [$a, $b] = $list[$i];
            if ($b > $start - $rest) {
                return false;
            }
            if ($a + $maxLength <= $start - $rest) {
                break;
            }
        }

        return true;
    }

    /**
     * The longest stretch without busy time inside [windowStart, windowEnd), counting the
     * candidate [start, end) as busy too. Both window edges bound a stretch.
     */
    public function longestRestWith(int $userId, int $windowStart, int $windowEnd, int $start, int $end): int
    {
        $list = $this->byUser[$userId] ?? [];
        $maxLength = $this->maxLength[$userId] ?? 0;

        $inWindow = [];
        for ($i = self::lowerBound($list, $windowStart - $maxLength), $n = count($list); $i < $n; $i++) {
            [$a, $b] = $list[$i];
            if ($a >= $windowEnd) {
                break;
            }
            if ($b > $windowStart) {
                $inWindow[] = [$a, $b];
            }
        }
        $inWindow[] = [$start, $end];
        usort($inWindow, fn ($x, $y) => $x[0] <=> $y[0]);

        $longest = 0;
        $cursor = $windowStart;
        foreach ($inWindow as [$a, $b]) {
            if ($a > $cursor) {
                $longest = max($longest, min($a, $windowEnd) - $cursor);
            }
            $cursor = max($cursor, $b);
            if ($cursor >= $windowEnd) {
                break;
            }
        }
        if ($cursor < $windowEnd) {
            $longest = max($longest, $windowEnd - $cursor);
        }

        return $longest;
    }

    /** First index whose interval starts at or after $value. */
    private static function lowerBound(array $list, int $value): int
    {
        $lo = 0;
        $hi = count($list);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($list[$mid][0] < $value) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }
}
