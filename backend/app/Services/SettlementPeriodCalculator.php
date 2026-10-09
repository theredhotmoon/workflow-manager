<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Support\BusinessDate;
use Carbon\Carbon;
use InvalidArgumentException;

class SettlementPeriodCalculator
{
    private const DEFAULT_MODE = 'monthly';
    private const DEFAULT_WEEKLY_HOUR_CAP = 48.0;

    public function periodContaining(ShiftGroup $group, Carbon $date): array
    {
        [$mode, $anchor, $customLength, $weeklyCap] = $this->resolveConfig($group);
        $date = $date->copy()->startOfDay();
        $periodNumber = $this->periodNumberForDate($mode, $anchor, $customLength, $date);

        return $this->buildPeriod($mode, $anchor, $customLength, $weeklyCap, $periodNumber);
    }

    public function periodByOffset(ShiftGroup $group, int $offset): array
    {
        [$mode, $anchor, $customLength, $weeklyCap] = $this->resolveConfig($group);
        // Today's business-timezone date, at 00:00 like the anchor and the dates it is compared with.
        $today = Carbon::parse(BusinessDate::todayString());
        $currentPeriodNumber = $this->periodNumberForDate($mode, $anchor, $customLength, $today);

        return $this->buildPeriod($mode, $anchor, $customLength, $weeklyCap, $currentPeriodNumber + $offset);
    }

    public function weekFor(array $period, Carbon $date): array
    {
        $date = $date->copy()->startOfDay();
        foreach ($period['weeks'] as $index => $week) {
            if ($date->between($week['start'], $week['end'])) {
                return ['index' => $index, 'week' => $week];
            }
        }

        throw new InvalidArgumentException('Date does not fall within the given period.');
    }

    public function shiftTiming(Shift $shift): array
    {
        // Wall-clock times in the business timezone, rolled over by the same rule as
        // BusinessDate::shiftEnd(), so the duration is elapsed time: a 00:00-00:00 shift is 24 h,
        // or 25 h / 23 h on a DST change day. Missing times fall back to a 06:00-14:00 day.
        $dateStr = $shift->date->format('Y-m-d');
        $start = $shift->start_time ?: '06:00';
        $end = $shift->end_time ?: '14:00';
        $endDate = BusinessDate::endsNextDay($start, $end) ? $shift->date->copy()->addDay()->format('Y-m-d') : $dateStr;

        $startTime = BusinessDate::localInstant($dateStr, $start);
        $endTime = BusinessDate::localInstant($endDate, $end);

        // Whole minutes (Carbon 3 returns a float), then hours with the fraction kept: 7:30 is 7.5.
        $durationMinutes = (int) $startTime->diffInMinutes($endTime, true);

        return [
            'start' => $startTime,
            'end' => $endTime,
            'duration_hours' => $durationMinutes / 60,
        ];
    }

    private function resolveConfig(ShiftGroup $group): array
    {
        $mode = $group->settlement_mode ?? self::DEFAULT_MODE;
        $anchor = $group->settlement_anchor_date
            ? $group->settlement_anchor_date->copy()->startOfDay()
            : ($group->created_at ? $group->created_at->copy()->startOfDay() : Carbon::today()->startOfDay());
        $customLength = $group->settlement_custom_length_days;
        $weeklyCap = $group->weekly_hour_cap ?? self::DEFAULT_WEEKLY_HOUR_CAP;

        if ($mode === 'custom' && !$customLength) {
            throw new InvalidArgumentException('Custom settlement mode requires settlement_custom_length_days.');
        }

        return [$mode, $anchor, $customLength, (float) $weeklyCap];
    }

    private function fixedLengthDays(string $mode, ?int $customLength): ?int
    {
        return match ($mode) {
            'weekly' => 7,
            'custom' => $customLength,
            default => null,
        };
    }

    private function periodNumberForDate(string $mode, Carbon $anchor, ?int $customLength, Carbon $date): int
    {
        if (in_array($mode, ['weekly', 'custom'], true)) {
            $lengthDays = $this->fixedLengthDays($mode, $customLength);
            $diffDays = (int) $anchor->diffInDays($date, false);

            return intdiv($diffDays >= 0 ? $diffDays : $diffDays - $lengthDays + 1, $lengthDays);
        }

        $monthsPerPeriod = $mode === 'quarterly' ? 3 : 1;
        $diffMonths = (int) $anchor->diffInMonths($date, false);
        $periodNumber = intdiv($diffMonths >= 0 ? $diffMonths : $diffMonths - $monthsPerPeriod + 1, $monthsPerPeriod);

        while ($anchor->copy()->addMonthsNoOverflow($periodNumber * $monthsPerPeriod)->gt($date)) {
            $periodNumber--;
        }
        while ($anchor->copy()->addMonthsNoOverflow(($periodNumber + 1) * $monthsPerPeriod)->lte($date)) {
            $periodNumber++;
        }

        return $periodNumber;
    }

    private function buildPeriod(string $mode, Carbon $anchor, ?int $customLength, float $weeklyCap, int $periodNumber): array
    {
        if (in_array($mode, ['weekly', 'custom'], true)) {
            $lengthDays = $this->fixedLengthDays($mode, $customLength);
            $start = $anchor->copy()->addDays($periodNumber * $lengthDays);
            $end = $start->copy()->addDays($lengthDays - 1);
        } else {
            $monthsPerPeriod = $mode === 'quarterly' ? 3 : 1;
            $start = $anchor->copy()->addMonthsNoOverflow($periodNumber * $monthsPerPeriod);
            $end = $anchor->copy()->addMonthsNoOverflow(($periodNumber + 1) * $monthsPerPeriod)->subDay();
        }

        return [
            'period_number' => $periodNumber,
            'start' => $start,
            'end' => $end,
            'weeks' => $this->buildWeeks($start, $end, $weeklyCap),
        ];
    }

    private function buildWeeks(Carbon $start, Carbon $end, float $weeklyCap): array
    {
        $weeks = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $weekEnd = $cursor->copy()->addDays(6);
            if ($weekEnd->gt($end)) {
                $weekEnd = $end->copy();
            }
            $daysInWeek = (int) $cursor->diffInDays($weekEnd, true) + 1;
            $weeks[] = [
                'start' => $cursor->copy(),
                'end' => $weekEnd->copy(),
                'cap_hours' => round($weeklyCap * $daysInWeek / 7, 2),
            ];
            $cursor = $weekEnd->copy()->addDay();
        }

        return $weeks;
    }
}
