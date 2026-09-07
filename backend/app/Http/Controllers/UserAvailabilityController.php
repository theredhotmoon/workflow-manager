<?php

namespace App\Http\Controllers;

use App\Models\UserAvailability;
use Carbon\Carbon;
use Illuminate\Http\Request;

class UserAvailabilityController extends Controller
{
    /**
     * List availability entries for the current user.
     */
    public function index(Request $request)
    {
        $entries = UserAvailability::where('user_id', $request->user()->id)
            ->with('createdBy:id,name')
            ->orderBy('start_date')
            ->get()
            ->map(fn($e) => $this->formatEntry($e));

        return response()->json($entries);
    }

    /**
     * Create a new availability entry. Defaults to the acting user; a manager
     * may pass a different user_id for someone on a team they manage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'type' => ['required', 'in:unavailable,preferred'],
            'shift_type' => ['nullable', 'string', 'max:50', 'prohibited_if:type,unavailable'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $targetUserId = $request->input('user_id', $request->user()->id);

        if (!$request->user()->can('manageFor', [UserAvailability::class, $targetUserId])) {
            return response()->json(['message' => "You do not manage this employee's team."], 403);
        }

        $entry = UserAvailability::create([
            'user_id' => $targetUserId,
            'created_by_user_id' => $request->user()->id,
            'type' => $request->type,
            'shift_type' => $request->shift_type,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'note' => $request->note,
        ]);
        $entry->load('createdBy:id,name');

        return response()->json($this->formatEntry($entry), 201);
    }

    /**
     * Delete an availability entry (own entries, or a team member's entry
     * for a manager of their group — see UserAvailabilityPolicy).
     */
    public function destroy(Request $request, UserAvailability $userAvailability)
    {
        if (!$request->user()->can('manage', $userAvailability)) {
            return response()->json(['message' => "You do not manage this employee's team."], 403);
        }

        $userAvailability->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Get availability entries for a specific user in a given month.
     * Used by the schedule view (and the availability editor) to show overlays.
     */
    public function forMonth(Request $request, $userId)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $start = Carbon::createFromFormat('Y-m', $request->month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $entries = UserAvailability::where('user_id', $userId)
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->with('createdBy:id,name')
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
            'created_by_user_id' => $entry->created_by_user_id,
            'created_by_name' => $entry->createdBy?->name,
            'type' => $entry->type,
            'shift_type' => $entry->shift_type,
            'start_date' => $entry->start_date->format('Y-m-d'),
            'end_date' => $entry->end_date->format('Y-m-d'),
            'start_time' => $entry->start_time ? substr($entry->start_time, 0, 5) : null,
            'end_time' => $entry->end_time ? substr($entry->end_time, 0, 5) : null,
            'note' => $entry->note,
        ];
    }
}
