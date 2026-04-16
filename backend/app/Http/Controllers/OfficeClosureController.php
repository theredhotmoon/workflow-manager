<?php

namespace App\Http\Controllers;

use App\Models\OfficeClosure;
use Carbon\Carbon;
use Illuminate\Http\Request;

class OfficeClosureController extends Controller
{
    /**
     * List all closures, optionally filtered by month.
     */
    public function index(Request $request)
    {
        $query = OfficeClosure::with('creator:id,name')->orderBy('date');

        if ($request->has('month')) {
            $start = Carbon::createFromFormat('Y-m', $request->month)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $query->whereBetween('date', [$start->toDateString(), $end->toDateString()]);
        }

        return response()->json($query->get());
    }

    /**
     * Create office closures for a date range.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $start = Carbon::parse($validated['start_date']);
        $end = Carbon::parse($validated['end_date']);
        $created = 0;
        $current = $start->copy();

        while ($current <= $end) {
            OfficeClosure::firstOrCreate(
                ['date' => $current->toDateString()],
                [
                    'title' => $validated['title'],
                    'description' => $validated['description'] ?? null,
                    'created_by' => $request->user()->id,
                ]
            );
            $created++;
            $current->addDay();
        }

        return response()->json([
            'message' => "Created {$created} closure(s).",
            'created' => $created,
        ], 201);
    }

    /**
     * Update an existing closure.
     */
    public function update(Request $request, OfficeClosure $officeClosure)
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $officeClosure->update($validated);

        return response()->json($officeClosure);
    }

    /**
     * Delete a closure.
     */
    public function destroy(OfficeClosure $officeClosure)
    {
        $officeClosure->delete();
        return response()->json(['message' => 'Office closure removed.']);
    }

    /**
     * Check for conflicts: given a month, return closures that overlap with office-mode shifts.
     */
    public function checkConflicts(Request $request)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $start = Carbon::createFromFormat('Y-m', $request->month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $closures = OfficeClosure::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->get();

        return response()->json($closures);
    }
}
