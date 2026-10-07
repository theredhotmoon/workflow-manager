<?php

namespace App\Http\Controllers;

use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Support\ShiftType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ShiftGroupController extends Controller
{
    /**
     * List all shift groups ordered by sort_order.
     */
    public function index()
    {
        $groups = ShiftGroup::with('streakRules')->orderBy('sort_order')->orderBy('id')->get();

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
     * Delete a shift group and all associated shifts (cascade).
     * Cannot delete the default group.
     */
    public function destroy(ShiftGroup $shiftGroup)
    {
        if ($shiftGroup->is_default) {
            return response()->json(['message' => 'Cannot delete the default shift group.'], 422);
        }

        $shiftCount = $shiftGroup->shifts()->count();
        $shiftGroup->delete(); // FK cascade deletes shifts + assignments

        return response()->json([
            'message' => "Shift group \"{$shiftGroup->name}\" deleted with {$shiftCount} shifts.",
        ]);
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
    public function syncStreakRules(Request $request, ShiftGroup $shiftGroup)
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

        DB::transaction(function () use ($rules, $shiftGroup) {
            $keepIds = [];
            foreach ($rules as $rule) {
                $model = ShiftStreakRule::updateOrCreate(
                    ['shift_group_id' => $shiftGroup->id, 'shift_type' => $rule['shift_type']],
                    ['streak_length' => $rule['streak_length'], 'rest_days' => $rule['rest_days']],
                );
                $keepIds[] = $model->id;
            }
            $shiftGroup->streakRules()->whereNotIn('id', $keepIds)->delete();
        });

        $shiftGroup->load('streakRules');

        return response()->json($shiftGroup);
    }
}
