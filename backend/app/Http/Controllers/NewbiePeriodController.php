<?php

namespace App\Http\Controllers;

use App\Models\NewbiePeriod;
use App\Models\User;
use App\Support\Month;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NewbiePeriodController extends Controller
{
    /**
     * List all newbie periods.
     */
    public function all()
    {
        return response()->json(
            NewbiePeriod::with('user:id,name')->orderBy('start_date', 'desc')->get()
        );
    }

    /**
     * List newbie periods for a user.
     */
    public function forUser($userId)
    {
        $periods = NewbiePeriod::where('user_id', $userId)
            ->orderBy('start_date', 'desc')
            ->get();

        return response()->json($periods);
    }

    /**
     * Get all newbie periods overlapping a given month (for schedule overlay).
     */
    public function forMonth(Request $request)
    {
        $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $start = Month::start($request->month);
        $end = $start->copy()->endOfMonth();

        $periods = NewbiePeriod::with('user:id,name')
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->get();

        return response()->json($periods);
    }

    /**
     * Create a newbie period.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['required', User::activeExistsRule()],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $period = NewbiePeriod::create([
            'user_id' => $validated['user_id'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'created_by' => $request->user()->id,
        ]);

        return response()->json($period, 201);
    }

    /**
     * Update a newbie period. Either date may change alone, so the range is checked as it will be
     * stored: the new value(s) merged with the current ones.
     */
    public function update(Request $request, NewbiePeriod $newbiePeriod)
    {
        $validated = $request->validate([
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
        ]);
        $validated = array_map(fn ($date) => Carbon::parse($date)->toDateString(), $validated);

        $start = $validated['start_date'] ?? $newbiePeriod->start_date->toDateString();
        $end = $validated['end_date'] ?? $newbiePeriod->end_date->toDateString();
        if ($end < $start) {
            throw ValidationException::withMessages([
                'end_date' => "The end date must be on or after the start date ({$start}).",
            ]);
        }

        $newbiePeriod->update($validated);

        return response()->json($newbiePeriod);
    }

    /**
     * Delete a newbie period.
     */
    public function destroy(NewbiePeriod $newbiePeriod)
    {
        $newbiePeriod->delete();

        return response()->json(['message' => 'Newbie period removed.']);
    }
}
