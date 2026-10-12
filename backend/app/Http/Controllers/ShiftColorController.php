<?php

namespace App\Http\Controllers;

use App\Models\ShiftColor;
use Illuminate\Http\Request;

class ShiftColorController extends Controller
{
    public function index()
    {
        return response()->json(
            ShiftColor::orderBy('sort_order')->orderBy('id')->get()
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'hex' => ['required', 'string', 'max:20'],
        ]);

        $maxSort = ShiftColor::max('sort_order') ?? 0;

        $color = ShiftColor::create([
            'name' => $request->name,
            'hex' => $request->hex,
            'sort_order' => $maxSort + 1,
        ]);

        return response()->json($color, 201);
    }

    public function update(Request $request, ShiftColor $shiftColor)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'hex' => ['required', 'string', 'max:20'],
        ]);

        $shiftColor->update([
            'name' => $request->name,
            'hex' => $request->hex,
        ]);

        return response()->json($shiftColor);
    }

    public function destroy(ShiftColor $shiftColor)
    {
        $shiftColor->delete();

        return response()->json(['message' => 'Color deleted.']);
    }

    public function reorder(Request $request)
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['required', 'integer', 'exists:shift_colors,id'],
        ]);

        foreach ($request->ids as $index => $id) {
            ShiftColor::where('id', $id)->update(['sort_order' => $index + 1]);
        }

        return response()->json(['message' => 'Order updated.']);
    }
}
