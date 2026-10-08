<?php

namespace App\Services;

use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;

/**
 * A rest_policy only means something while its assignment sits inside a rest window. After a change
 * that can take assignments out of rest (unassign, retype, rule edit, ...), this clears the stale
 * answers so that, if a later change puts the shift back on a rest day, the manager is asked again.
 */
class RestPolicyJanitor
{
    public function __construct(private StreakReplay $streakReplay)
    {
    }

    /**
     * Nulls rest_policy on the users' assignments in $group that no rest window covers. One pass is
     * enough: the replay ignores the policy of an uncovered assignment, so clearing it moves nothing.
     *
     * @param  list<int>  $userIds
     * @return int assignments cleared
     */
    public function clearUncovered(ShiftGroup $group, array $userIds): int
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return 0;
        }

        $timeline = $this->streakReplay->forGroup($group, $userIds);
        if (!$timeline->hasRules()) {
            // No rules → no rest windows → no policy applies.
            return ShiftAssignment::whereIn('user_id', $userIds)
                ->whereNotNull('rest_policy')
                ->whereHas('shift', fn ($query) => $query->where('shift_group_id', $group->id))
                ->update(['rest_policy' => null]);
        }

        $ids = [];
        foreach ($userIds as $userId) {
            foreach ($timeline->uncoveredWithPolicy($userId) as $id) {
                $ids[] = $id;
            }
        }

        $cleared = 0;
        foreach (array_chunk($ids, 1000) as $chunk) {
            $cleared += ShiftAssignment::whereIn('id', $chunk)->update(['rest_policy' => null]);
        }

        return $cleared;
    }

    /**
     * Every user with a rest_policy set somewhere in the group.
     *
     * @return list<int>
     */
    public function usersWithPolicy(ShiftGroup $group): array
    {
        return ShiftAssignment::whereNotNull('rest_policy')
            ->whereHas('shift', fn ($query) => $query->where('shift_group_id', $group->id))
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
