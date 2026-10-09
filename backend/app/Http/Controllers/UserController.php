<?php

namespace App\Http\Controllers;

use App\Models\ShiftAssignment;
use App\Models\ShiftChangeRequest;
use App\Models\ShiftGroup;
use App\Models\User;
use App\Services\RestPolicyJanitor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * List users. Deactivated ones are left out unless include_deactivated is set (Users screen).
     */
    public function index(Request $request)
    {
        $users = User::with('groups:id,name,enum')
            ->when(!$request->boolean('include_deactivated'), fn ($q) => $q->active())
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_admin', 'deactivated_at', 'created_at', 'updated_at']);

        return response()->json($users);
    }

    /**
     * Create a new user.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_admin' => $validated['is_admin'] ?? false,
        ]);

        return response()->json($user, 201);
    }

    /**
     * Get a single user, with how many shifts they are assigned to from today on (the deactivate
     * dialog offers to remove those).
     */
    public function show(User $user)
    {
        return response()->json(array_merge(
            $user->only(['id', 'name', 'email', 'is_admin', 'deactivated_at', 'created_at', 'updated_at']),
            ['future_assignments_count' => $this->futureAssignments($user)->count()],
        ));
    }

    /**
     * Update an existing user.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        $user->update($validated);

        return response()->json($user);
    }

    /**
     * Deactivate a user instead of deleting them: they can't log in and drop out of pickers and new
     * schedules, while everything they created or own stays. Optionally takes them off their shifts
     * from today on.
     */
    public function deactivate(Request $request, User $user, RestPolicyJanitor $janitor)
    {
        $request->validate([
            'remove_future_assignments' => ['sometimes', 'boolean'],
        ]);

        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'You cannot deactivate your own account.',
            ], 422);
        }

        $removed = DB::transaction(function () use ($request, $user, $janitor) {
            $user->forceFill(['deactivated_at' => $user->deactivated_at ?? now()])->save();
            $user->tokens()->delete();

            if (!$request->boolean('remove_future_assignments')) {
                return 0;
            }

            $assignments = $this->futureAssignments($user)->with('shift:id,shift_group_id')->get();
            if ($assignments->isEmpty()) {
                return 0;
            }
            // As when a manager unassigns: pending change requests are settled, then the rest
            // policies this user's removed shifts held in place are cleared.
            ShiftChangeRequest::whereIn('shift_assignment_id', $assignments->pluck('id'))
                ->where('status', 'pending')
                ->update(['status' => 'acknowledged']);
            ShiftAssignment::whereIn('id', $assignments->pluck('id'))->delete();
            $groupIds = $assignments->pluck('shift.shift_group_id')->filter()->unique();
            foreach (ShiftGroup::whereIn('id', $groupIds)->get() as $group) {
                $janitor->clearUncovered($group, [$user->id]);
            }

            return $assignments->count();
        });

        return response()->json([
            'message' => "User \"{$user->name}\" deactivated.",
            'removed_assignments' => $removed,
        ]);
    }

    /**
     * Let a deactivated user log in and be picked again.
     */
    public function reactivate(User $user)
    {
        $user->forceFill(['deactivated_at' => null])->save();

        return response()->json(['message' => "User \"{$user->name}\" reactivated."]);
    }

    /**
     * Change a user's password.
     */
    public function changePassword(Request $request, User $user)
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    /** The user's assignments on shifts from today on. */
    private function futureAssignments(User $user)
    {
        return $user->assignments()
            ->whereHas('shift', fn ($q) => $q->where('date', '>=', Carbon::today()->toDateString()));
    }
}
