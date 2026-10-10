<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserAvailability;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Support\Month;

class UserAvailabilityController extends Controller
{
    /**
     * List availability entries for the current user.
     */
    public function index(Request $request)
    {
        $entries = UserAvailability::where('user_id', $request->user()->id)
            ->orderBy('start_date')
            ->get()
            ->map(fn($e) => $this->formatEntry($e));

        return response()->json($entries);
    }

    /**
     * Create a new availability entry for the current user.
     */
    public function store(Request $request)
    {
        $request->validate([
            'type' => ['required', 'in:unavailable,preferred'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $entry = UserAvailability::create([
            'user_id' => $request->user()->id,
            'type' => $request->type,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'note' => $request->note,
        ]);

        return response()->json($this->formatEntry($entry), 201);
    }

    /**
     * Delete an availability entry (only own entries).
     */
    public function destroy(Request $request, UserAvailability $userAvailability)
    {
        if ($userAvailability->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $userAvailability->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Get availability entries for a specific user in a given month.
     * Used by the schedule view to show overlays. Any user may read the type and dates; the note
     * is only for that user and schedule managers (formatEntry).
     */
    public function forMonth(Request $request, $userId)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $start = Month::start($request->month);
        $end = $start->copy()->endOfMonth();

        $entries = UserAvailability::where('user_id', $userId)
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->orderBy('start_date')
            ->get()
            ->map(fn($e) => $this->formatEntry($e));

        return response()->json($entries);
    }

    private function formatEntry($entry)
    {
        return [
            'id' => $entry->id,
            'user_id' => $entry->user_id,
            'type' => $entry->type,
            'start_date' => $entry->start_date->format('Y-m-d'),
            'end_date' => $entry->end_date->format('Y-m-d'),
            'start_time' => $entry->start_time ? substr($entry->start_time, 0, 5) : null,
            'end_time' => $entry->end_time ? substr($entry->end_time, 0, 5) : null,
            // Private to the entry's user and schedule managers (L1): colleagues get null.
            'note' => $entry->noteVisibleTo(User::viewer()) ? $entry->note : null,
        ];
    }
}
