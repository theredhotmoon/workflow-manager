<?php

namespace App\Http\Controllers;

use App\Models\UserGroup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserGroupController extends Controller
{
    public function index()
    {
        return response()->json(
            UserGroup::with('users:id,name,email')->orderBy('name')->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'enum' => ['required', 'string', 'max:100', 'unique:user_groups', 'regex:/^[a-z0-9_-]+$/'],
        ]);

        $group = UserGroup::create($validated);
        $group->load('users:id,name,email');

        return response()->json($group, 201);
    }

    public function update(Request $request, UserGroup $userGroup)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'enum' => ['required', 'string', 'max:100', Rule::unique('user_groups')->ignore($userGroup->id), 'regex:/^[a-z0-9_-]+$/'],
        ]);

        $userGroup->update($validated);
        $userGroup->load('users:id,name,email');

        return response()->json($userGroup);
    }

    public function destroy(UserGroup $userGroup)
    {
        $userGroup->delete();
        return response()->json(['message' => 'Group deleted.']);
    }

    public function syncUsers(Request $request, UserGroup $userGroup)
    {
        $validated = $request->validate([
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $userGroup->users()->sync($validated['user_ids']);
        $userGroup->load('users:id,name,email');

        return response()->json($userGroup);
    }
}
