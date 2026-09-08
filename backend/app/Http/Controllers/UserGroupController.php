<?php

namespace App\Http\Controllers;

use App\Models\UserGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserGroupController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            UserGroup::with(['users:id,name,email', 'managers:id,name,email'])->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'enum' => ['required', 'string', 'max:100', 'unique:user_groups', 'regex:/^[a-z0-9_-]+$/'],
        ]);

        $group = UserGroup::create($validated);
        $group->load(['users:id,name,email', 'managers:id,name,email']);

        return response()->json($group, 201);
    }

    public function update(Request $request, UserGroup $userGroup): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'enum' => ['required', 'string', 'max:100', Rule::unique('user_groups')->ignore($userGroup->id), 'regex:/^[a-z0-9_-]+$/'],
        ]);

        $userGroup->update($validated);
        $userGroup->load(['users:id,name,email', 'managers:id,name,email']);

        return response()->json($userGroup);
    }

    public function destroy(UserGroup $userGroup): JsonResponse
    {
        $userGroup->delete();
        return response()->json(null, 204);
    }

    public function syncUsers(Request $request, UserGroup $userGroup): JsonResponse
    {
        $validated = $request->validate([
            'user_ids' => ['present', 'array', 'max:500'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $userGroup->users()->sync($validated['user_ids']);
        $userGroup->load(['users:id,name,email', 'managers:id,name,email']);

        return response()->json($userGroup);
    }

    public function syncManagers(Request $request, UserGroup $userGroup): JsonResponse
    {
        $validated = $request->validate([
            'user_ids' => ['present', 'array', 'max:500'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $userGroup->managers()->sync($validated['user_ids']);
        $userGroup->load(['users:id,name,email', 'managers:id,name,email']);

        return response()->json($userGroup);
    }

    public function managedByMe(Request $request): JsonResponse
    {
        $groups = $request->user()
            ->managedGroups()
            ->with('users:id,name,email')
            ->orderBy('name')
            ->get(['user_groups.id', 'user_groups.name', 'user_groups.enum']);

        return response()->json($groups);
    }
}
