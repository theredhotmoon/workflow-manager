<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Support\ShiftType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Changes shifts' types and keeps streak counters consistent: every assignment on a shift whose
 * type changes is re-booked as "unassigned from the old type, assigned to the new type" — the same
 * tracker calls manual unassign/assign make. Type update and counters commit or roll back together.
 */
class ShiftTypeChanger
{
    public function __construct(private ShiftStreakTracker $tracker)
    {
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @return array{updated: int, rebooked: int}
     */
    public function change(Collection $shifts, ?string $rawType): array
    {
        $newType = ShiftType::normalize($rawType);

        // Chronological order, so a rest window triggered by re-booking lands on the right date.
        $changed = $shifts
            ->filter(fn (Shift $shift) => $shift->shift_type !== $newType)
            ->sortBy(fn (Shift $shift) => $shift->date->toDateString().'#'.str_pad((string) $shift->shift_number, 3, '0', STR_PAD_LEFT))
            ->values();

        if ($changed->isEmpty()) {
            return ['updated' => 0, 'rebooked' => 0];
        }

        return DB::transaction(function () use ($changed, $newType) {
            $rebooked = 0;

            foreach ($changed->groupBy('shift_group_id') as $groupShifts) {
                $group = $groupShifts->first()->shiftGroup;
                $rules = $group ? $this->tracker->rulesForGroup($group) : collect();
                $assignmentsByShift = ShiftAssignment::whereIn('shift_id', $groupShifts->pluck('id'))
                    ->get(['shift_id', 'user_id'])
                    ->groupBy('shift_id');
                $userIds = $assignmentsByShift->flatten()->pluck('user_id')->unique()->values()->all();
                $states = $rules->isEmpty() || empty($userIds) ? [] : $this->tracker->loadStates($rules, $userIds);

                foreach ($groupShifts as $shift) {
                    $oldType = $shift->shift_type;
                    $shift->update(['shift_type' => $newType]);

                    $touchesRule = ($oldType !== null && $rules->has($oldType)) || ($newType !== null && $rules->has($newType));
                    if (!$touchesRule) {
                        continue;
                    }

                    foreach ($assignmentsByShift->get($shift->id, collect()) as $assignment) {
                        $userId = (int) $assignment->user_id;
                        $this->tracker->recordUnassignment($states, $rules, $userId, $oldType);
                        $this->tracker->recordAssignment($states, $rules, $userId, $newType, $shift->date, advanceWatermark: true);
                        $rebooked++;
                    }
                }

                if (!empty($states)) {
                    $this->tracker->persist($states);
                }
            }

            return ['updated' => $changed->count(), 'rebooked' => $rebooked];
        });
    }
}
