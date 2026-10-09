<?php

use App\Models\ChecklistSubmission;
use App\Models\ChecklistTemplate;
use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftChangeRequest;
use App\Models\ShiftChecklist;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
});

/** A non-default group with a past and a future shift, each staffed and carrying related rows. */
function sgdGroupWithHistory(User $worker): array
{
    $group = ShiftGroup::create(['name' => 'Night desk', 'is_default' => false, 'sort_order' => 1]);
    $template = ChecklistTemplate::create(['name' => 'Handover', 'is_active' => true]);
    $rule = ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);

    $shifts = [];
    $assignments = [];
    foreach (['past' => '2026-10-01', 'future' => '2026-10-20'] as $key => $date) {
        $shifts[$key] = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id]);
        $assignments[$key] = ShiftAssignment::create(['shift_id' => $shifts[$key]->id, 'user_id' => $worker->id]);
        ShiftChecklist::create(['shift_id' => $shifts[$key]->id, 'checklist_template_id' => $template->id, 'is_required' => true]);
    }
    $request = ShiftChangeRequest::create([
        'shift_assignment_id' => $assignments['future']->id, 'user_id' => $worker->id,
        'shift_id' => $shifts['future']->id, 'reason' => 'Doctor', 'status' => 'pending',
    ]);
    $handover = ChecklistSubmission::create([
        'checklist_template_id' => $template->id, 'shift_id' => $shifts['past']->id, 'date' => '2026-10-01',
        'outgoing_user_id' => $worker->id, 'status' => 'completed',
    ]);
    $onCall = OnCallAssignment::create([
        'shift_group_id' => $group->id, 'user_id' => $worker->id,
        'starts_at' => '2026-10-01 20:00:00', 'ends_at' => '2026-10-02 08:00:00',
    ]);
    $callback = OnCallCallback::create([
        'on_call_assignment_id' => $onCall->id, 'starts_at' => '2026-10-01 23:00:00', 'ends_at' => '2026-10-02 01:00:00',
    ]);

    return compact('group', 'template', 'rule', 'shifts', 'assignments', 'request', 'handover', 'onCall', 'callback');
}

it('refuses a shift group delete to anyone but an admin', function (string $role) {
    $actor = User::factory()->create(['role' => $role]);
    $h = sgdGroupWithHistory(User::factory()->create(['role' => 'employee']));

    $this->actingAs($actor)->deleteJson("/api/shift-groups/{$h['group']->id}")->assertForbidden();

    expect($h['group']->fresh())->not->toBeNull()
        ->and(Shift::where('shift_group_id', $h['group']->id)->count())->toBe(2);
})->with(['employee', 'schedule_maintainer']);

it('refuses to delete the default shift group', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $default = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    Shift::create(['date' => '2026-10-20', 'shift_number' => 1, 'shift_group_id' => $default->id]);

    $this->actingAs($admin)->deleteJson("/api/shift-groups/{$default->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot delete the default shift group.');

    expect($default->fresh())->not->toBeNull()
        ->and(Shift::where('shift_group_id', $default->id)->count())->toBe(1);
});

it('answers 404 for an unknown shift group', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->deleteJson('/api/shift-groups/999999')->assertNotFound();
});

it('deletes a group with all its shifts, staffing and on-call history, keeping handovers and other groups', function () {
    // Current behaviour: cascades; see follow-up. Deleting a group wipes its past work history
    // (shifts, assignments, change requests, shift checklists, streak rules, on-call duties and
    // their callbacks). Only handovers survive, detached from their shift.
    $admin = User::factory()->create(['role' => 'admin']);
    $worker = User::factory()->create(['role' => 'employee']);
    $h = sgdGroupWithHistory($worker);
    $main = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $otherShift = Shift::create(['date' => '2026-10-01', 'shift_number' => 1, 'shift_group_id' => $main->id]);
    $otherAssignment = ShiftAssignment::create(['shift_id' => $otherShift->id, 'user_id' => $worker->id]);

    $this->actingAs($admin)->deleteJson("/api/shift-groups/{$h['group']->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Shift group "Night desk" deleted with 2 shifts.');

    expect($h['group']->fresh())->toBeNull();
    // Gone with the group:
    expect(Shift::whereIn('id', collect($h['shifts'])->pluck('id'))->count())->toBe(0)
        ->and(ShiftAssignment::whereIn('id', collect($h['assignments'])->pluck('id'))->count())->toBe(0)
        ->and(ShiftChecklist::whereIn('shift_id', collect($h['shifts'])->pluck('id'))->count())->toBe(0)
        ->and($h['request']->fresh())->toBeNull()
        ->and($h['rule']->fresh())->toBeNull()
        ->and($h['onCall']->fresh())->toBeNull()
        ->and($h['callback']->fresh())->toBeNull();
    // Kept: the handover (its shift link nulled), the template, the worker, the other group's work.
    expect($h['handover']->fresh())->not->toBeNull()
        ->and($h['handover']->fresh()->shift_id)->toBeNull()
        ->and($h['template']->fresh())->not->toBeNull()
        ->and($worker->fresh())->not->toBeNull()
        ->and($otherShift->fresh())->not->toBeNull()
        ->and($otherAssignment->fresh())->not->toBeNull();
});
