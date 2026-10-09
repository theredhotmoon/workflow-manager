<?php

namespace App\Http\Controllers;

use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Support\Month;

class OnCallController extends Controller
{
    /**
     * List on-call assignments (with their callbacks) for a group/month.
     */
    public function index(Request $request)
    {
        $request->validate([
            'shift_group_id' => ['required', 'integer', 'exists:shift_groups,id'],
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $start = Month::start($request->month);
        $end = $start->copy()->endOfMonth();

        $assignments = OnCallAssignment::with(['user:id,name', 'callbacks'])
            ->where('shift_group_id', $request->shift_group_id)
            ->where('starts_at', '<=', $end)
            ->where('ends_at', '>=', $start)
            ->orderBy('starts_at')
            ->get();

        return response()->json($assignments);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'shift_group_id' => ['required', 'integer', 'exists:shift_groups,id'],
            'user_id' => ['required', 'integer', User::activeExistsRule()],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $assignment = OnCallAssignment::create($validated);
        $assignment->load('user:id,name');

        return response()->json($assignment, 201);
    }

    public function destroy(OnCallAssignment $onCallAssignment)
    {
        $onCallAssignment->delete();

        return response()->json(['message' => 'On-call assignment removed.']);
    }

    public function storeCallback(Request $request, OnCallAssignment $onCallAssignment)
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $startsAt = Carbon::parse($validated['starts_at']);
        if ($startsAt->lt($onCallAssignment->starts_at) || $startsAt->gt($onCallAssignment->ends_at)) {
            return response()->json([
                'message' => 'A callback must start within its on-call assignment\'s window.',
            ], 422);
        }

        $callback = $onCallAssignment->callbacks()->create($validated);

        return response()->json($callback, 201);
    }

    public function destroyCallback(OnCallCallback $onCallCallback)
    {
        $onCallCallback->delete();

        return response()->json(['message' => 'Callback removed.']);
    }
}
