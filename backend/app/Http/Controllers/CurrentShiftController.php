<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Support\BusinessDate;
use Illuminate\Http\Request;

class CurrentShiftController extends Controller
{
    /**
     * The shifts on right now (business date and time), with their crews: one list over every
     * shift group that isn't archived, ordered by group then slot, or only one group's with
     * shift_group_id. A group can have more than one (overlapping slots).
     *
     * `active_shift` is kept for older clients: the first of `active_shifts`, or null.
     */
    public function __invoke(Request $request)
    {
        $request->validate(['shift_group_id' => ['sometimes', 'nullable', 'integer']]);

        // Shift dates and times are business-timezone wall-clock: compare instants built from them.
        $now = BusinessDate::now();
        $today = $now->toDateString();
        $yesterday = $now->copy()->startOfDay()->subDay()->toDateString();

        $candidates = Shift::with(['assignments.user:id,name,email', 'shiftGroup:id,name,sort_order'])
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            // Today's shifts, and yesterday's overnight ones (end at or before start: they end today).
            ->where(fn ($q) => $q->where('date', $today)
                ->orWhere(fn ($y) => $y->where('date', $yesterday)->whereRaw('end_time <= start_time')))
            ->when(
                $request->filled('shift_group_id'),
                fn ($q) => $q->where('shift_group_id', (int) $request->shift_group_id),
            )
            ->where(fn ($q) => $q->whereNull('shift_group_id')
                ->orWhereIn('shift_group_id', ShiftGroup::notArchived()->select('id')))
            ->get();

        // Active from its start (inclusive) to its end (exclusive).
        $active = $candidates
            ->filter(fn (Shift $shift) => $now->gte(BusinessDate::shiftStart($shift)) && $now->lt(BusinessDate::shiftEnd($shift)))
            ->sortBy([
                fn (Shift $a, Shift $b) => [$a->shiftGroup?->sort_order ?? PHP_INT_MAX, $a->shift_group_id ?? 0]
                    <=> [$b->shiftGroup?->sort_order ?? PHP_INT_MAX, $b->shift_group_id ?? 0],
                fn (Shift $a, Shift $b) => BusinessDate::shiftStart($a) <=> BusinessDate::shiftStart($b),
                fn (Shift $a, Shift $b) => $a->shift_number <=> $b->shift_number,
            ])
            ->values()
            ->map(fn (Shift $shift) => $this->payload($shift));

        return response()->json([
            'active_shift' => $active->first(),
            'active_shifts' => $active->all(),
        ]);
    }

    private function payload(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'date' => $shift->date->format('Y-m-d'),
            'shift_number' => $shift->shift_number,
            'shift_group_id' => $shift->shift_group_id,
            'shift_group_name' => $shift->shiftGroup?->name,
            'start_time' => substr($shift->start_time, 0, 5),
            'end_time' => substr($shift->end_time, 0, 5),
            'is_overnight' => BusinessDate::endsNextDay($shift->start_time, $shift->end_time),
            // id and name; email only where User's $hidden lets the viewer see it (admins,
            // themselves). Filtered from toArray(), not $user->only(), which ignores $hidden.
            'crew' => $shift->assignments->map(
                fn ($a) => collect($a->user->toArray())->only(['id', 'name', 'email'])->all()
            )->values()->all(),
        ];
    }
}
