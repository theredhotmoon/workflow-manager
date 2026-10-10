<?php

namespace App\Http\Controllers;

use App\Models\OnCallAssignment;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Services\FutureWorkRemover;
use App\Services\RestPolicyJanitor;
use App\Support\BusinessDate;
use App\Support\ShiftType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ShiftGroupController extends Controller
{
    /**
     * List the shift groups ordered by sort_order. Archived groups are left out (schedule tabs,
     * pickers) unless include_archived is set (the archived list, settlement reports of past periods).
     */
    public function index(Request $request)
    {
        $groups = ShiftGroup::with('streakRules')
            ->when(!$request->boolean('include_archived'), fn ($q) => $q->notArchived())
            ->orderBy('sort_order')->orderBy('id')->get();

        return response()->json($groups);
    }

    /**
     * Create a new shift group.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', 'string', 'max:7'],
            'settlement_mode' => ['sometimes', 'nullable', 'in:weekly,monthly,quarterly,custom'],
            'settlement_anchor_date' => ['sometimes', 'nullable', 'date'],
            'settlement_custom_length_days' => ['required_if:settlement_mode,custom', 'nullable', 'integer', 'min:1'],
            'weekly_hour_cap' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:168'],
        ]);

        $maxOrder = ShiftGroup::max('sort_order') ?? 0;

        $group = ShiftGroup::create([
            'name' => $request->name,
            'description' => $request->description,
            'color' => $request->color,
            'sort_order' => $maxOrder + 1,
            'settlement_mode' => $request->settlement_mode,
            'settlement_anchor_date' => $request->settlement_anchor_date,
            'settlement_custom_length_days' => $request->settlement_custom_length_days,
            'weekly_hour_cap' => $request->weekly_hour_cap,
        ]);

        return response()->json($group, 201, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Update a shift group.
     */
    public function update(Request $request, ShiftGroup $shiftGroup)
    {
        $effectiveMode = $request->input('settlement_mode', $shiftGroup->settlement_mode);

        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', 'string', 'max:7'],
            'settlement_mode' => ['sometimes', 'nullable', 'in:weekly,monthly,quarterly,custom'],
            'settlement_anchor_date' => ['sometimes', 'nullable', 'date'],
            'settlement_custom_length_days' => [
                Rule::requiredIf($effectiveMode === 'custom'),
                'nullable', 'integer', 'min:1',
            ],
            'weekly_hour_cap' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:168'],
        ]);

        $shiftGroup->update($request->only([
            'name', 'description', 'color',
            'settlement_mode', 'settlement_anchor_date', 'settlement_custom_length_days', 'weekly_hour_cap',
        ]));

        return response()->json($shiftGroup, 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * "Delete" archives the group (DELETE is kept for older clients): it leaves the schedule tabs,
     * pickers and auto-fill, while its shifts, assignments, hours, change requests, checklists,
     * on-call duties and callbacks stay and past settlement periods still count them.
     *
     * Work from tomorrow on (business date) is not left orphaned in a hidden group: while the group
     * has assignments or on-call duties from tomorrow on, the answer is 409 future_work listing
     * them; with remove_future_assignments they are removed as user deactivation removes them
     * (pending change requests acknowledged and kept, rest policies cleared) and the group is
     * archived. Today's and past work is never touched. The default group can't be archived.
     */
    public function destroy(Request $request, ShiftGroup $shiftGroup, FutureWorkRemover $remover)
    {
        $request->validate([
            'remove_future_assignments' => ['sometimes', 'boolean'],
        ]);

        if ($shiftGroup->is_default) {
            return response()->json(['message' => 'Cannot archive the default shift group.'], 422);
        }

        $result = DB::transaction(function () use ($request, $shiftGroup, $remover) {
            // Locked so a concurrent archive/restore of the same group waits for this one.
            $shiftGroup->newQuery()->whereKey($shiftGroup->id)->lockForUpdate()->first();
            $shiftGroup->refresh();
            if ($shiftGroup->isArchived()) {
                return response()->json(['message' => "Shift group \"{$shiftGroup->name}\" is already archived."], 422);
            }

            $assignments = ShiftAssignment::with(['shift:id,date,shift_number,shift_group_id', 'user:id,name'])
                ->whereHas('shift', fn ($q) => $q->where('shift_group_id', $shiftGroup->id)
                    ->where('date', '>', BusinessDate::todayString()))
                ->get()
                ->sortBy(fn (ShiftAssignment $a) => [$a->shift->date->toDateString(), $a->shift->shift_number, $a->user?->name])
                ->values();
            $onCall = OnCallAssignment::with('user:id,name')
                ->where('shift_group_id', $shiftGroup->id)
                ->where('starts_at', '>=', BusinessDate::today()->addDay()->format('Y-m-d H:i:s'))
                ->orderBy('starts_at')
                ->get();

            if (($assignments->isNotEmpty() || $onCall->isNotEmpty()) && !$request->boolean('remove_future_assignments')) {
                return $this->futureWorkRefusal($shiftGroup, $assignments, $onCall);
            }

            $removedAssignments = $remover->removeAssignments($assignments);
            $removedOnCall = OnCallAssignment::whereIn('id', $onCall->pluck('id'))->delete();
            $shiftGroup->forceFill(['archived_at' => now()])->save();

            return [$removedAssignments, $removedOnCall];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }
        [$removedAssignments, $removedOnCall] = $result;

        return response()->json([
            'message' => "Shift group \"{$shiftGroup->name}\" archived.",
            'removed_assignments' => $removedAssignments,
            'removed_on_call' => $removedOnCall,
            'group' => $shiftGroup,
        ]);
    }

    /**
     * Bring an archived group back into the schedule tabs and pickers, with all its history. Work
     * removed when it was archived is not restored.
     */
    public function restore(ShiftGroup $shiftGroup)
    {
        if (!$shiftGroup->isArchived()) {
            return response()->json(['message' => "Shift group \"{$shiftGroup->name}\" is not archived."], 422);
        }

        $shiftGroup->forceFill(['archived_at' => null])->save();
        $shiftGroup->load('streakRules');

        return response()->json([
            'message' => "Shift group \"{$shiftGroup->name}\" restored.",
            'group' => $shiftGroup,
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * The 409 asking the admin to confirm removing the group's work from tomorrow on.
     */
    private function futureWorkRefusal(ShiftGroup $group, Collection $assignments, Collection $onCall): JsonResponse
    {
        $parts = [];
        if ($assignments->isNotEmpty()) {
            $parts[] = $assignments->count() === 1 ? '1 shift assignment' : "{$assignments->count()} shift assignments";
        }
        if ($onCall->isNotEmpty()) {
            $parts[] = $onCall->count() === 1 ? '1 on-call duty' : "{$onCall->count()} on-call duties";
        }

        return response()->json([
            'code' => 'future_work',
            'message' => "\"{$group->name}\" has " . implode(' and ', $parts) . ' from tomorrow on. '
                . 'Confirm to remove them and archive the group; today\'s and past work stays.',
            'assignments' => $assignments->map(fn (ShiftAssignment $a) => [
                'id' => $a->id,
                'date' => $a->shift->date->toDateString(),
                'shift_number' => $a->shift->shift_number,
                'user_name' => $a->user?->name,
            ])->all(),
            'on_call' => $onCall->map(fn (OnCallAssignment $o) => [
                'id' => $o->id,
                'starts_at' => $o->starts_at->format('Y-m-d H:i'),
                'ends_at' => $o->ends_at->format('Y-m-d H:i'),
                'user_name' => $o->user?->name,
            ])->all(),
        ], 409);
    }

    /**
     * Reorder shift groups.
     */
    public function reorder(Request $request)
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['required', 'integer', 'exists:shift_groups,id'],
        ]);

        foreach ($request->ids as $index => $id) {
            ShiftGroup::where('id', $id)->update(['sort_order' => $index]);
        }

        return response()->json(['message' => 'Shift groups reordered.']);
    }

    /**
     * Replace a group's full set of shift-streak rules in one call.
     */
    public function syncStreakRules(Request $request, ShiftGroup $shiftGroup, RestPolicyJanitor $janitor)
    {
        $request->validate([
            'rules' => ['present', 'array'],
            'rules.*.shift_type' => ['required', 'string', 'max:50'],
            'rules.*.streak_length' => ['required', 'integer', 'min:1'],
            'rules.*.rest_days' => ['required', 'integer', 'min:1'],
        ]);

        $rules = array_map(
            fn (array $rule) => array_merge($rule, ['shift_type' => ShiftType::normalize($rule['shift_type'])]),
            $request->input('rules'),
        );

        $shiftTypes = array_column($rules, 'shift_type');
        if (count($shiftTypes) !== count(array_unique($shiftTypes))) {
            return response()->json([
                'message' => 'Each shift_type can only have one streak rule per group.',
            ], 422);
        }

        DB::transaction(function () use ($rules, $shiftGroup, $janitor) {
            $keepIds = [];
            foreach ($rules as $rule) {
                $model = ShiftStreakRule::updateOrCreate(
                    ['shift_group_id' => $shiftGroup->id, 'shift_type' => $rule['shift_type']],
                    ['streak_length' => $rule['streak_length'], 'rest_days' => $rule['rest_days']],
                );
                $keepIds[] = $model->id;
            }
            $shiftGroup->streakRules()->whereNotIn('id', $keepIds)->delete();

            // New rules move the rest windows: answers for shifts no longer on a rest day are dropped.
            $janitor->clearUncovered($shiftGroup, $janitor->usersWithPolicy($shiftGroup));
        });

        $shiftGroup->load('streakRules');

        return response()->json($shiftGroup);
    }
}
