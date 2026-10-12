<?php

namespace App\Http\Controllers;

use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\ShiftGroup;
use App\Models\User;
use App\Support\Month;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            // New duties only in groups that aren't archived; an archived group's history stays listable.
            'shift_group_id' => ['required', 'integer', ShiftGroup::activeExistsRule()],
            'user_id' => ['required', 'integer', User::activeExistsRule()],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $assignment = DB::transaction(function () use ($validated) {
            // The group first (ShiftGroup's lock order): an archive since validation is refused.
            ShiftGroup::lockActiveOrFail($validated['shift_group_id']);
            // The user's row is locked so two overlapping requests can't both pass the check.
            $user = User::lockForUpdate()->findOrFail($validated['user_id']);
            $startsAt = Carbon::parse($validated['starts_at']);
            $endsAt = Carbon::parse($validated['ends_at']);

            // One person is on call once at a time (in any group). Touching periods are fine.
            $clash = OnCallAssignment::where('user_id', $user->id)
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->orderBy('starts_at')
                ->first();
            if ($clash) {
                throw ValidationException::withMessages([
                    'starts_at' => "{$user->name} is already on call from {$this->formatInstant($clash->starts_at)} to {$this->formatInstant($clash->ends_at)}.",
                ]);
            }

            return OnCallAssignment::create($validated);
        });
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

        $callback = DB::transaction(function () use ($validated, $onCallAssignment, $startsAt) {
            // Callbacks are worked time and are summed per user: they must not overlap each other,
            // whichever of the user's on-call periods they were logged on.
            User::lockForUpdate()->find($onCallAssignment->user_id);
            $endsAt = Carbon::parse($validated['ends_at']);
            $clash = OnCallCallback::whereHas('onCallAssignment', fn ($q) => $q->where('user_id', $onCallAssignment->user_id))
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->orderBy('starts_at')
                ->first();
            if ($clash) {
                throw ValidationException::withMessages([
                    'starts_at' => "This overlaps a callback already logged from {$this->formatInstant($clash->starts_at)} to {$this->formatInstant($clash->ends_at)}.",
                ]);
            }

            return $onCallAssignment->callbacks()->create($validated);
        });

        return response()->json($callback, 201);
    }

    private function formatInstant(Carbon $at): string
    {
        return $at->format('Y-m-d H:i');
    }

    public function destroyCallback(OnCallCallback $onCallCallback)
    {
        $onCallCallback->delete();

        return response()->json(['message' => 'Callback removed.']);
    }
}
