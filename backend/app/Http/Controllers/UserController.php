<?php

namespace App\Http\Controllers;

use App\Models\OnCallAssignment;
use App\Models\ShiftAssignment;
use App\Models\ShiftChangeRequest;
use App\Models\ShiftGroup;
use App\Models\User;
use App\Services\RestPolicyJanitor;
use App\Support\BusinessDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const LAST_ADMIN_MESSAGE = 'There must be at least one active admin. Make someone else an admin first.';

    private const ROLE_MESSAGES = [
        'role.in' => 'The role must be admin, schedule_maintainer or employee.',
        'is_admin.prohibited' => 'Admin status is set with role now (admin, schedule_maintainer or employee).',
    ];

    /**
     * List users. Deactivated ones are left out unless include_deactivated is set (Users screen).
     */
    public function index(Request $request)
    {
        $users = User::with('groups:id,name,enum')
            ->when(!$request->boolean('include_deactivated'), fn ($q) => $q->active())
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'deactivated_at', 'created_at', 'updated_at']);

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
            'role' => ['sometimes', Rule::in(User::ROLES)],
            'is_admin' => ['prohibited'],
        ], self::ROLE_MESSAGES);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'] ?? User::ROLE_EMPLOYEE,
        ]);

        return response()->json($user, 201);
    }

    /**
     * Get a single user, with how many shifts and on-call duties they have from tomorrow on (the
     * deactivate dialog offers to remove those).
     */
    public function show(User $user)
    {
        return response()->json(array_merge(
            $user->only(['id', 'name', 'email', 'role', 'is_admin', 'deactivated_at', 'created_at', 'updated_at']),
            [
                'future_assignments_count' => $this->futureAssignments($user)->count(),
                'future_on_call_count' => $this->futureOnCall($user)->count(),
            ],
        ));
    }

    /**
     * Update an existing user. Their role may change, except an admin's own and never so that no
     * active admin is left.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role' => ['sometimes', Rule::in(User::ROLES)],
            'is_admin' => ['prohibited'],
        ], self::ROLE_MESSAGES);

        $roleChanges = isset($validated['role']) && $validated['role'] !== $user->role;

        $refusal = DB::transaction(function () use ($request, $user, $validated, $roleChanges) {
            if ($roleChanges) {
                if ($user->isAdmin() && $this->wouldLeaveNoActiveAdmin($user)) {
                    return self::LAST_ADMIN_MESSAGE;
                }
                if ($user->id === $request->user()->id) {
                    return 'You cannot change your own role.';
                }
            }

            $user->update($validated);

            return null;
        });

        if ($refusal) {
            return response()->json(['message' => $refusal], 422);
        }

        return response()->json($user);
    }

    /**
     * Deactivate a user instead of deleting them: they can't log in and drop out of pickers and new
     * schedules, while everything they created or own stays. Optionally takes them off their shifts
     * and on-call duties from tomorrow on (today's may already be worked). Delegation memberships
     * and newbie periods are left as they are.
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

        $result = DB::transaction(function () use ($request, $user, $janitor) {
            if ($user->isAdmin() && $this->wouldLeaveNoActiveAdmin($user)) {
                return null;
            }

            $user->forceFill(['deactivated_at' => $user->deactivated_at ?? now()])->save();
            $user->tokens()->delete();

            if (!$request->boolean('remove_future_assignments')) {
                return [0, 0];
            }

            $removedOnCall = $this->futureOnCall($user)->delete();

            $assignments = $this->futureAssignments($user)->with('shift:id,shift_group_id')->get();
            if ($assignments->isNotEmpty()) {
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
            }

            return [$assignments->count(), $removedOnCall];
        });

        if ($result === null) {
            return response()->json(['message' => self::LAST_ADMIN_MESSAGE], 422);
        }
        [$removedAssignments, $removedOnCall] = $result;

        return response()->json([
            'message' => "User \"{$user->name}\" deactivated.",
            'removed_assignments' => $removedAssignments,
            'removed_on_call' => $removedOnCall,
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

    /**
     * Whether demoting or deactivating $admin leaves no other active admin. Locks the active admins'
     * rows (inside the caller's transaction) so two admins can't demote each other at once.
     */
    private function wouldLeaveNoActiveAdmin(User $admin): bool
    {
        $activeAdminIds = User::active()->admins()->lockForUpdate()->pluck('id');

        return $activeAdminIds->reject(fn ($id) => $id === $admin->id)->isEmpty();
    }

    /** The user's assignments on shifts from tomorrow on (business timezone). */
    private function futureAssignments(User $user)
    {
        return $user->assignments()
            ->whereHas('shift', fn ($q) => $q->where('date', '>', BusinessDate::todayString()));
    }

    /**
     * The user's on-call duties starting from tomorrow on. starts_at holds local wall-clock time
     * (like shift dates), so it is compared with the business date's midnight as is.
     */
    private function futureOnCall(User $user)
    {
        return OnCallAssignment::where('user_id', $user->id)
            ->where('starts_at', '>=', BusinessDate::today()->addDay()->format('Y-m-d H:i:s'));
    }
}
