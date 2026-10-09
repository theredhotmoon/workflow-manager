<?php

use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\ShiftGroup;
use App\Models\User;

it('lists on-call assignments with their callbacks for a group and month', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee']);
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);
    OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-15 22:00:00',
        'ends_at' => '2026-09-15 23:30:00',
    ]);

    $response = $this->actingAs($maintainer)->getJson('/api/on-call-assignments?' . http_build_query([
        'shift_group_id' => $group->id,
        'month' => '2026-09',
    ]));

    $response->assertOk();
    expect($response->json('0.user.name'))->toBe($employee->name);
    expect($response->json('0.callbacks'))->toHaveCount(1);
});

it('creates an on-call assignment', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee']);

    $response = $this->actingAs($maintainer)->postJson('/api/on-call-assignments', [
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);

    $response->assertCreated();
    expect(OnCallAssignment::where('user_id', $employee->id)->count())->toBe(1);
});

it('rejects an on-call assignment where ends_at is not after starts_at', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee']);

    $this->actingAs($maintainer)->postJson('/api/on-call-assignments', [
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-15 08:00:00',
    ])->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
});

it('deletes an on-call assignment and cascades its callbacks', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee']);
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);
    $callback = OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-15 22:00:00',
        'ends_at' => '2026-09-15 23:30:00',
    ]);

    $this->actingAs($maintainer)->deleteJson("/api/on-call-assignments/{$assignment->id}")->assertOk();

    expect(OnCallAssignment::find($assignment->id))->toBeNull();
    expect(OnCallCallback::find($callback->id))->toBeNull();
});

it('records a callback that starts within the on-call window', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee']);
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);

    $response = $this->actingAs($maintainer)->postJson("/api/on-call-assignments/{$assignment->id}/callbacks", [
        'starts_at' => '2026-09-15 22:00:00',
        'ends_at' => '2026-09-15 23:30:00',
    ]);

    $response->assertCreated();
    expect(OnCallCallback::where('on_call_assignment_id', $assignment->id)->count())->toBe(1);
});

it('allows a callback to extend past the on-call assignment end time', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee']);
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);

    $response = $this->actingAs($maintainer)->postJson("/api/on-call-assignments/{$assignment->id}/callbacks", [
        'starts_at' => '2026-09-16 07:30:00',
        'ends_at' => '2026-09-16 09:00:00',
    ]);

    $response->assertCreated();
});

it('rejects a callback whose starts_at falls outside the on-call assignment window', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee']);
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);

    $response = $this->actingAs($maintainer)->postJson("/api/on-call-assignments/{$assignment->id}/callbacks", [
        'starts_at' => '2026-09-17 01:00:00',
        'ends_at' => '2026-09-17 02:00:00',
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('must start within');
});

it('deletes a callback', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee']);
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);
    $callback = OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-15 22:00:00',
        'ends_at' => '2026-09-15 23:30:00',
    ]);

    $this->actingAs($maintainer)->deleteJson("/api/on-call-callbacks/{$callback->id}")->assertOk();

    expect(OnCallCallback::find($callback->id))->toBeNull();
});

it('rejects a plain employee from creating an on-call assignment', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $target = User::factory()->create(['role' => 'employee']);

    $this->actingAs($employee)->postJson('/api/on-call-assignments', [
        'shift_group_id' => $group->id,
        'user_id' => $target->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ])->assertStatus(403);
});
