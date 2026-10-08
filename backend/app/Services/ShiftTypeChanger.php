<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Support\ShiftType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Changes shifts' types. Streak state is replayed from assignments (StreakTimeline), so there is no
 * bookkeeping; `rebooked` reports how many assignments' streaks the change affects, for the UI.
 */
class ShiftTypeChanger
{
    public function __construct(private RestPolicyJanitor $janitor)
    {
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @return array{updated: int, rebooked: int}
     */
    public function change(Collection $shifts, ?string $rawType): array
    {
        $newType = ShiftType::normalize($rawType);
        $changed = $shifts->filter(fn (Shift $shift) => $shift->shift_type !== $newType)->values();
        if ($changed->isEmpty()) {
            return ['updated' => 0, 'rebooked' => 0];
        }

        return DB::transaction(function () use ($changed, $newType) {
            $rebooked = 0;
            foreach ($changed->groupBy('shift_group_id') as $groupShifts) {
                $group = $groupShifts->first()->shiftGroup;
                $ruleTypes = $group ? $group->streakRules()->pluck('shift_type')->all() : [];
                $assignmentCounts = ShiftAssignment::whereIn('shift_id', $groupShifts->pluck('id'))
                    ->selectRaw('shift_id, count(*) as n')
                    ->groupBy('shift_id')
                    ->pluck('n', 'shift_id');

                foreach ($groupShifts as $shift) {
                    $touchesRule = in_array($shift->shift_type, $ruleTypes, true) || in_array($newType, $ruleTypes, true);
                    $shift->update(['shift_type' => $newType]);
                    if ($touchesRule) {
                        $rebooked += (int) ($assignmentCounts[$shift->id] ?? 0);
                    }
                }

                // A retype can take later shifts of the people on these shifts out of rest.
                if ($group) {
                    $userIds = ShiftAssignment::whereIn('shift_id', $groupShifts->pluck('id'))->distinct()->pluck('user_id')->all();
                    $this->janitor->clearUncovered($group, $userIds);
                }
            }

            return ['updated' => $changed->count(), 'rebooked' => $rebooked];
        });
    }
}
