<?php

use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\ShiftGroup;
use App\Models\User;

it('creates an on-call assignment scoped to a shift group and user', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $user = User::factory()->create();

    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $user->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);

    expect($assignment->shift_group_id)->toBe($group->id);
    expect($group->onCallAssignments()->pluck('user_id')->all())->toBe([$user->id]);
});

it('creates a callback linked to its on-call assignment', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $user = User::factory()->create();
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $user->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);

    $callback = OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-15 22:00:00',
        'ends_at' => '2026-09-15 23:30:00',
    ]);

    expect($assignment->callbacks()->pluck('id')->all())->toBe([$callback->id]);
});

it('cascades callback deletion when the on-call assignment is deleted', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $user = User::factory()->create();
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $user->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);
    $callback = OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-15 22:00:00',
        'ends_at' => '2026-09-15 23:30:00',
    ]);

    $assignment->delete();

    expect(OnCallCallback::find($callback->id))->toBeNull();
});

it('cascades on-call assignment deletion when the shift group is deleted', function () {
    $group = ShiftGroup::create(['name' => 'Temp', 'sort_order' => 1]);
    $user = User::factory()->create();
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $user->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);

    $group->delete();

    expect(OnCallAssignment::find($assignment->id))->toBeNull();
});
