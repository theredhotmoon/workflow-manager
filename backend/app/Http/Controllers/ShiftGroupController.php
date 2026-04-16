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
        ]);

        $maxOrder = ShiftGroup::max('sort_order') ?? 0;

        $group = ShiftGroup::create([
            'name' => $request->name,
            'description' => $request->description,
            'color' => $request->color,
            'sort_order' => $maxOrder + 1,
        ]);

        return response()->json($group, 201);
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
        ]);

        $shiftGroup->update($request->only(['name', 'description', 'color']));

        return response()->json($shiftGroup);
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
