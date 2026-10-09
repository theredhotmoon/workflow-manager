<?php

use App\Models\Alarm;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftChangeRequest;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * The change-request flow as built: an employee says they cannot attend one of their own shifts
 * (store), schedule managers see the month's requests (index) and acknowledge them; the employee
 * sees their own (mine). There is no approve/reject/cancel step and no swap between two people:
 * a manager acts on a request by editing the schedule, and removing the assignment settles it.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
    $this->group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $this->employee = User::factory()->create(['role' => 'employee']);
});

function scrAssign(User $user, string $date, int $number = 1): ShiftAssignment
{
    $shift = Shift::firstOrCreate(['date' => $date, 'shift_number' => $number, 'shift_group_id' => test()->group->id]);

    return ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id]);
}

function scrRequest(ShiftAssignment $assignment, string $status = 'pending'): ShiftChangeRequest
{
    return ShiftChangeRequest::create([
        'shift_assignment_id' => $assignment->id, 'user_id' => $assignment->user_id,
        'shift_id' => $assignment->shift_id, 'reason' => 'Doctor', 'status' => $status,
    ]);
}

it('files a pending request for your own upcoming shift and alerts the schedule managers', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $assignment = scrAssign($this->employee, '2026-10-20');

    $this->actingAs($this->employee)->postJson('/api/shift-change-requests', [
        'shift_assignment_id' => $assignment->id, 'reason' => 'Doctor appointment',
    ])->assertCreated()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('user.id', $this->employee->id);

    $request = ShiftChangeRequest::sole();
    expect($request->shift_assignment_id)->toBe($assignment->id)
        ->and($request->shift_id)->toBe($assignment->shift_id)
        ->and($request->user_id)->toBe($this->employee->id);
    // Filing changes nobody's assignment.
    expect($assignment->fresh()->user_id)->toBe($this->employee->id);
    expect(Alarm::where('user_id', $admin->id)->where('type', Alarm::TYPE_EMPLOYEE_CANNOT_ATTEND_SHIFT)->count())->toBe(1);
});

it('refuses a request for someone else\'s assignment, whatever your role', function (string $role) {
    $actor = User::factory()->create(['role' => $role]);
    $assignment = scrAssign($this->employee, '2026-10-20');

    $this->actingAs($actor)->postJson('/api/shift-change-requests', [
        'shift_assignment_id' => $assignment->id, 'reason' => 'On their behalf',
    ])->assertForbidden();

    expect(ShiftChangeRequest::count())->toBe(0);
})->with(['employee', 'schedule_maintainer', 'admin']);

it('refuses a second pending request for the same shift but allows one after acknowledgement', function () {
    $assignment = scrAssign($this->employee, '2026-10-20');
    $request = scrRequest($assignment);
    $payload = ['shift_assignment_id' => $assignment->id, 'reason' => 'Still ill'];

    $this->actingAs($this->employee)->postJson('/api/shift-change-requests', $payload)->assertStatus(422);

    $request->update(['status' => 'acknowledged']);
    $this->actingAs($this->employee)->postJson('/api/shift-change-requests', $payload)->assertCreated();
    expect(ShiftChangeRequest::where('shift_assignment_id', $assignment->id)->count())->toBe(2);
});

it('validates the request body', function (array $payload, string $field) {
    $payload['shift_assignment_id'] ??= scrAssign($this->employee, '2026-10-20')->id;

    $this->actingAs($this->employee)->postJson('/api/shift-change-requests', array_filter($payload))
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
    expect(ShiftChangeRequest::count())->toBe(0);
})->with([
    'missing assignment' => [['shift_assignment_id' => false, 'reason' => 'Doctor'], 'shift_assignment_id'],
    'unknown assignment' => [['shift_assignment_id' => 999999, 'reason' => 'Doctor'], 'shift_assignment_id'],
    'reason too short' => [['reason' => 'no'], 'reason'],
]);

it('lists only your own requests under mine', function () {
    $other = User::factory()->create(['role' => 'employee']);
    $own = scrRequest(scrAssign($this->employee, '2026-10-20'));
    scrRequest(scrAssign($other, '2026-10-21'));

    $ids = collect($this->actingAs($this->employee)->getJson('/api/shift-change-requests/mine')->assertOk()->json())->pluck('id')->all();

    expect($ids)->toBe([$own->id]);
});

it('lists the month\'s requests to schedule managers only', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $october = scrRequest(scrAssign($this->employee, '2026-10-20'));
    scrRequest(scrAssign($this->employee, '2026-11-02'));

    $this->actingAs($this->employee)->getJson('/api/shift-change-requests?month=2026-10')->assertForbidden();
    $this->actingAs($maintainer)->getJson('/api/shift-change-requests')->assertStatus(422);
    $ids = collect($this->actingAs($maintainer)->getJson('/api/shift-change-requests?month=2026-10')->assertOk()->json())->pluck('id')->all();

    expect($ids)->toBe([$october->id]);
});

it('lets a schedule manager acknowledge a request without touching the assignment', function (string $role) {
    $manager = User::factory()->create(['role' => $role]);
    $assignment = scrAssign($this->employee, '2026-10-20');
    $request = scrRequest($assignment);

    $this->actingAs($manager)->putJson("/api/shift-change-requests/{$request->id}/acknowledge")
        ->assertOk()
        ->assertJsonPath('status', 'acknowledged');

    expect($request->fresh()->status)->toBe('acknowledged')
        ->and($assignment->fresh()->user_id)->toBe($this->employee->id);
})->with(['admin', 'schedule_maintainer']);

it('does not let an employee acknowledge a request, even their own', function () {
    $request = scrRequest(scrAssign($this->employee, '2026-10-20'));

    $this->actingAs($this->employee)->putJson("/api/shift-change-requests/{$request->id}/acknowledge")->assertForbidden();

    expect($request->fresh()->status)->toBe('pending');
});

it('answers 404 when acknowledging an unknown request', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->putJson('/api/shift-change-requests/999999/acknowledge')->assertNotFound();
});

it('deletes the change requests with the assignment when the shift is reassigned (cascade)', function () {
    // Current behaviour: cascades; see follow-up. Unassigning acknowledges the pending request and
    // then the shift_assignments FK cascade deletes it, so no record of the request remains.
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $replacement = User::factory()->create(['role' => 'employee']);
    $assignment = scrAssign($this->employee, '2026-10-20');
    $request = scrRequest($assignment);
    $acknowledged = scrRequest($assignment, 'acknowledged');

    $this->actingAs($maintainer)->deleteJson("/api/schedule/unassign/{$assignment->id}")->assertOk();
    $this->actingAs($maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $assignment->shift_id, 'user_id' => $replacement->id,
    ])->assertCreated();

    expect($request->fresh())->toBeNull()
        ->and($acknowledged->fresh())->toBeNull();
    expect(ShiftAssignment::where('shift_id', $assignment->shift_id)->pluck('user_id')->all())->toBe([$replacement->id]);
    $this->actingAs($this->employee)->getJson('/api/shift-change-requests/mine')->assertOk()->assertJsonCount(0);
    $this->actingAs($replacement)->getJson('/api/my-shifts?month=2026-10')
        ->assertOk()
        ->assertJsonPath('2026-10-20.0.has_change_request', false);
});

it('drops the request with its shift when the shift itself is deleted', function () {
    // Current behaviour: cascades; see follow-up. shift_change_requests.shift_id cascades.
    $assignment = scrAssign($this->employee, '2026-10-20');
    $request = scrRequest($assignment);

    $assignment->shift->delete();

    expect($request->fresh())->toBeNull();
    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->getJson('/api/shift-change-requests?month=2026-10')->assertOk()->assertJsonCount(0);
});
