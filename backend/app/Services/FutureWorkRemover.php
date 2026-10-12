<?php

namespace App\Services;

use App\Models\ShiftAssignment;
use App\Models\ShiftChangeRequest;
use App\Models\ShiftGroup;
use Illuminate\Database\Eloquent\Collection;

/**
 * Takes people off shifts the way a manager's unassign does, for the bulk removals: deactivating a
 * user with "remove future shifts" and archiving a shift group. Pending change requests on the
 * removed assignments are acknowledged (the requests stay, their assignment link nulled), then
 * the rest policies those shifts held in place are cleared. Run inside the caller's transaction.
 */
class FutureWorkRemover
{
    public function __construct(private RestPolicyJanitor $janitor) {}

    /**
     * @param  Collection<int, ShiftAssignment>  $assignments
     * @return int assignments removed
     */
    public function removeAssignments(Collection $assignments): int
    {
        if ($assignments->isEmpty()) {
            return 0;
        }

        $assignments->loadMissing('shift:id,shift_group_id');
        $ids = $assignments->pluck('id');

        // Rows locked (id order) before the requests are settled: a change request being filed for
        // one of them waits, then finds it gone, instead of landing pending after the settle.
        ShiftAssignment::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);

        ShiftChangeRequest::whereIn('shift_assignment_id', $ids)
            ->where('status', 'pending')
            ->update(['status' => 'acknowledged']);
        ShiftAssignment::whereIn('id', $ids)->delete();

        foreach ($assignments->groupBy(fn (ShiftAssignment $a) => $a->shift?->shift_group_id) as $groupId => $groupAssignments) {
            $group = $groupId ? ShiftGroup::find($groupId) : null;
            if ($group) {
                $this->janitor->clearUncovered($group, $groupAssignments->pluck('user_id')->all());
            }
        }

        return $assignments->count();
    }
}
