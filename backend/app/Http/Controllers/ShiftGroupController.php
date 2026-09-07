<?php

namespace App\Http\Controllers;

use App\Models\ShiftGroup;
use Illuminate\Http\Request;

class ShiftGroupController extends Controller
{
    /**
     * List all shift groups ordered by sort_order.
     */
    public function index()
    {
        $groups = ShiftGroup::orderBy('sort_order')->orderBy('id')->get();

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
            'weekly_hour_cap' => ['sometimes', 'nullable', 'numeric', 'min:0'],
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

        $group = $group->fresh();
        $data = $group->toArray();

        $json = json_encode($data);
        // Ensure whole number floats include .0 in JSON for proper type preservation
        $json = preg_replace('/"weekly_hour_cap":(\d+)([,}])/', '"weekly_hour_cap":$1.0$2', $json);

        return response($json, 201, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Update a shift group.
     */
    public function update(Request $request, ShiftGroup $shiftGroup)
    {
        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', 'string', 'max:7'],
            'settlement_mode' => ['sometimes', 'nullable', 'in:weekly,monthly,quarterly,custom'],
            'settlement_anchor_date' => ['sometimes', 'nullable', 'date'],
            'settlement_custom_length_days' => ['required_if:settlement_mode,custom', 'nullable', 'integer', 'min:1'],
            'weekly_hour_cap' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $shiftGroup->update($request->only([
            'name', 'description', 'color',
            'settlement_mode', 'settlement_anchor_date', 'settlement_custom_length_days', 'weekly_hour_cap',
        ]));

        $shiftGroup = $shiftGroup->fresh();
        $data = $shiftGroup->toArray();

        $json = json_encode($data);
        // Ensure whole number floats include .0 in JSON for proper type preservation
        $json = preg_replace('/"weekly_hour_cap":(\d+)([,}])/', '"weekly_hour_cap":$1.0$2', $json);

        return response($json, 200, [
            'Content-Type' => 'application/json',
        ]);
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
}
