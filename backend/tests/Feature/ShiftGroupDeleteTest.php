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

it('refuses a shift group archive to anyone but an admin', function (string $role) {
    $actor = User::factory()->create(['role' => $role]);
    $h = sgdGroupWithHistory(User::factory()->create(['role' => 'employee']));

    $this->actingAs($actor)->deleteJson("/api/shift-groups/{$h['group']->id}")->assertForbidden();

    expect($h['group']->fresh()->archived_at)->toBeNull()
        ->and(Shift::where('shift_group_id', $h['group']->id)->count())->toBe(2);
})->with(['employee', 'schedule_maintainer']);

it('refuses to archive the default shift group', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $default = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    Shift::create(['date' => '2026-10-20', 'shift_number' => 1, 'shift_group_id' => $default->id]);

    $this->actingAs($admin)->deleteJson("/api/shift-groups/{$default->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot archive the default shift group.');

    expect($default->fresh()->archived_at)->toBeNull()
        ->and(Shift::where('shift_group_id', $default->id)->count())->toBe(1);
});

it('answers 404 for an unknown shift group', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->deleteJson('/api/shift-groups/999999')->assertNotFound();
    $this->actingAs($admin)->postJson('/api/shift-groups/999999/restore')->assertNotFound();
});

it('refuses to archive while the group has work from tomorrow on, listing it, and changes nothing', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $worker = User::factory()->create(['role' => 'employee', 'name' => 'Wanda Worker']);
    $h = sgdGroupWithHistory($worker);
    $futureOnCall = OnCallAssignment::create([
        'shift_group_id' => $h['group']->id, 'user_id' => $worker->id,
        'starts_at' => '2026-10-21 20:00:00', 'ends_at' => '2026-10-22 08:00:00',
    ]);

    $this->actingAs($admin)->deleteJson("/api/shift-groups/{$h['group']->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'future_work')
        ->assertJsonPath('message', '"Night desk" has 1 shift assignment and 1 on-call duty from tomorrow on. Confirm to remove them and archive the group; today\'s and past work stays.')
        ->assertJsonPath('assignments.0.id', $h['assignments']['future']->id)
        ->assertJsonPath('assignments.0.date', '2026-10-20')
        ->assertJsonPath('assignments.0.user_name', 'Wanda Worker')
        ->assertJsonPath('on_call.0.id', $futureOnCall->id)
        ->assertJsonCount(1, 'assignments')
        ->assertJsonCount(1, 'on_call');

    expect($h['group']->fresh()->archived_at)->toBeNull()
        ->and($h['assignments']['future']->fresh())->not->toBeNull()
        ->and($futureOnCall->fresh())->not->toBeNull()
        ->and($h['request']->fresh()->status)->toBe('pending');
});

it('archives a group on confirm: future work is removed, all past history stays', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $worker = User::factory()->create(['role' => 'employee']);
    $h = sgdGroupWithHistory($worker);
    $today = Shift::create(['date' => '2026-10-09', 'shift_number' => 1, 'shift_group_id' => $h['group']->id]);
    $todayAssignment = ShiftAssignment::create(['shift_id' => $today->id, 'user_id' => $worker->id]);
    $futureOnCall = OnCallAssignment::create([
        'shift_group_id' => $h['group']->id, 'user_id' => $worker->id,
        'starts_at' => '2026-10-21 20:00:00', 'ends_at' => '2026-10-22 08:00:00',
    ]);
    $main = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $otherShift = Shift::create(['date' => '2026-10-20', 'shift_number' => 1, 'shift_group_id' => $main->id]);
    $otherAssignment = ShiftAssignment::create(['shift_id' => $otherShift->id, 'user_id' => $worker->id]);

    $this->actingAs($admin)->deleteJson("/api/shift-groups/{$h['group']->id}", ['remove_future_assignments' => true])
        ->assertOk()
        ->assertJsonPath('message', 'Shift group "Night desk" archived.')
        ->assertJsonPath('removed_assignments', 1)
        ->assertJsonPath('removed_on_call', 1);

    expect($h['group']->fresh()->archived_at)->not->toBeNull();
    // Kept: every shift (past and the now-empty future one), past and today's staffing,
    // checklists, streak rules, the past on-call duty and its callback, the handover.
    expect(Shift::whereIn('id', collect($h['shifts'])->pluck('id'))->count())->toBe(2)
        ->and($h['assignments']['past']->fresh())->not->toBeNull()
        ->and($todayAssignment->fresh())->not->toBeNull()
        ->and(ShiftChecklist::whereIn('shift_id', collect($h['shifts'])->pluck('id'))->count())->toBe(2)
        ->and($h['rule']->fresh())->not->toBeNull()
        ->and($h['onCall']->fresh())->not->toBeNull()
        ->and($h['callback']->fresh())->not->toBeNull()
        ->and($h['handover']->fresh()->shift_id)->toBe($h['shifts']['past']->id);
    // Removed: only this group's work from tomorrow on.
    expect($h['assignments']['future']->fresh())->toBeNull()
        ->and($futureOnCall->fresh())->toBeNull();
    // The change request on the removed assignment stays, settled, still naming its shift.
    $request = $h['request']->fresh();
    expect($request)->not->toBeNull()
        ->and($request->status)->toBe('acknowledged')
        ->and($request->shift_assignment_id)->toBeNull()
        ->and($request->shift_id)->toBe($h['shifts']['future']->id);
    // Other groups are untouched.
    expect($otherAssignment->fresh())->not->toBeNull();
});

it('archives a group with only past work without asking, once', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Old desk', 'is_default' => false, 'sort_order' => 1]);
    $shift = Shift::create(['date' => '2026-09-01', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => User::factory()->create()->id]);

    $this->actingAs($admin)->deleteJson("/api/shift-groups/{$group->id}")->assertOk()
        ->assertJsonPath('removed_assignments', 0);

    expect($group->fresh()->archived_at)->not->toBeNull()
        ->and($assignment->fresh())->not->toBeNull();
    $this->actingAs($admin)->deleteJson("/api/shift-groups/{$group->id}")->assertStatus(422)
        ->assertJsonPath('message', 'Shift group "Old desk" is already archived.');
});

it('hides an archived group from the schedule tabs and the all-groups view, but lists it on request', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $main = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $old = ShiftGroup::create(['name' => 'Old desk', 'is_default' => false, 'sort_order' => 1]);
    Shift::create(['date' => '2026-10-05', 'shift_number' => 1, 'shift_group_id' => $main->id]);
    $oldShift = Shift::create(['date' => '2026-10-05', 'shift_number' => 1, 'shift_group_id' => $old->id]);
    $this->actingAs($admin)->deleteJson("/api/shift-groups/{$old->id}")->assertOk();

    $listed = collect($this->actingAs($maintainer)->getJson('/api/shift-groups')->assertOk()->json())->pluck('id')->all();
    expect($listed)->toContain($main->id)->not->toContain($old->id);

    $all = collect($this->actingAs($admin)->getJson('/api/shift-groups?include_archived=1')->assertOk()->json());
    expect($all->pluck('id')->all())->toContain($main->id, $old->id)
        ->and($all->firstWhere('id', $old->id)['archived_at'])->not->toBeNull();

    $allGroups = $this->actingAs($maintainer)->getJson('/api/schedule?month=2026-10&shift_group_id=all')->assertOk()->json('schedule');
    expect(collect($allGroups['2026-10-05'])->pluck('shift_group_id')->all())->toBe([$main->id]);

    // Its own history stays readable by id.
    $own = $this->actingAs($maintainer)->getJson("/api/schedule?month=2026-10&shift_group_id={$old->id}")->assertOk()->json('schedule');
    expect(collect($own['2026-10-05'])->pluck('id')->all())->toBe([$oldShift->id]);
});

it('refuses planning in an archived group', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $worker = User::factory()->create(['role' => 'employee']);
    ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $old = ShiftGroup::create(['name' => 'Old desk', 'is_default' => false, 'sort_order' => 1]);
    $shift = Shift::create(['date' => '2026-10-05', 'shift_number' => 1, 'shift_group_id' => $old->id]);
    $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $worker->id]);
    $this->actingAs($admin)->deleteJson("/api/shift-groups/{$old->id}")->assertOk();
    $groupError = 'The selected shift group does not exist or is archived.';
    $shiftError = 'This shift belongs to the archived shift group "Old desk". Restore the group to change its schedule.';

    $this->actingAs($admin)->postJson('/api/schedule/generate', ['month' => '2026-11', 'shift_group_id' => $old->id])
        ->assertStatus(422)->assertJsonPath('errors.shift_group_id.0', $groupError);
    $this->actingAs($admin)->putJson('/api/schedule/day-shifts', ['date' => '2026-11-02', 'count' => 2, 'shift_group_id' => $old->id])
        ->assertStatus(422)->assertJsonPath('errors.shift_group_id.0', $groupError);
    $this->actingAs($admin)->postJson('/api/schedule/auto-assign/random', [
        'month' => '2026-11', 'shift_group_id' => $old->id, 'users_per_shift_weekday' => 1, 'users_per_shift_weekend' => 1,
    ])->assertStatus(422)->assertJsonPath('errors.shift_group_id.0', $groupError);
    $this->actingAs($admin)->postJson('/api/on-call-assignments', [
        'shift_group_id' => $old->id, 'user_id' => $worker->id,
        'starts_at' => '2026-11-02 20:00:00', 'ends_at' => '2026-11-03 08:00:00',
    ])->assertStatus(422)->assertJsonPath('errors.shift_group_id.0', $groupError);
    $this->actingAs($admin)->postJson('/api/schedule/assign', ['shift_id' => $shift->id, 'user_id' => $admin->id])
        ->assertStatus(422)->assertJsonPath('message', $shiftError);
    $this->actingAs($admin)->deleteJson("/api/schedule/unassign/{$assignment->id}")
        ->assertStatus(422)->assertJsonPath('message', $shiftError);
    $this->actingAs($admin)->putJson("/api/schedule/shifts/{$shift->id}/times", ['start_time' => '07:00', 'end_time' => '15:00'])
        ->assertStatus(422)->assertJsonPath('message', $shiftError);

    expect(Shift::where('shift_group_id', $old->id)->count())->toBe(1)
        ->and($assignment->fresh())->not->toBeNull()
        ->and(OnCallAssignment::count())->toBe(0);
});

it('restores an archived group with its history', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $old = ShiftGroup::create(['name' => 'Old desk', 'is_default' => false, 'sort_order' => 1]);
    $shift = Shift::create(['date' => '2026-09-01', 'shift_number' => 1, 'shift_group_id' => $old->id]);
    $this->actingAs($admin)->deleteJson("/api/shift-groups/{$old->id}")->assertOk();

    $this->actingAs($admin)->postJson("/api/shift-groups/{$old->id}/restore")
        ->assertOk()
        ->assertJsonPath('message', 'Shift group "Old desk" restored.')
        ->assertJsonPath('group.archived_at', null);

    expect($old->fresh()->archived_at)->toBeNull()
        ->and($shift->fresh())->not->toBeNull();
    expect(collect($this->actingAs($admin)->getJson('/api/shift-groups')->json())->pluck('id')->all())->toContain($old->id);
    $this->actingAs($admin)->postJson("/api/shift-groups/{$old->id}/restore")->assertStatus(422)
        ->assertJsonPath('message', 'Shift group "Old desk" is not archived.');
});

it('refuses a restore to anyone but an admin', function (string $role) {
    $old = ShiftGroup::create(['name' => 'Old desk', 'is_default' => false, 'sort_order' => 1]);
    $old->forceFill(['archived_at' => now()])->save();

    $this->actingAs(User::factory()->create(['role' => $role]))
        ->postJson("/api/shift-groups/{$old->id}/restore")->assertForbidden();

    expect($old->fresh()->archived_at)->not->toBeNull();
})->with(['employee', 'schedule_maintainer']);

it('still counts an archived group\'s past hours in its settlement period', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $admin = User::factory()->create(['role' => 'admin']);
    $worker = User::factory()->create(['role' => 'employee', 'name' => 'Pat Past']);
    $old = ShiftGroup::create([
        'name' => 'Old desk', 'is_default' => false, 'sort_order' => 1,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-01-01',
    ]);
    foreach (['2026-10-01', '2026-10-02'] as $date) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $old->id, 'start_time' => '08:00', 'end_time' => '16:00']);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $worker->id]);
    }
    $this->actingAs($admin)->deleteJson("/api/shift-groups/{$old->id}")->assertOk();

    $response = $this->actingAs($maintainer)->getJson("/api/settlement-periods?shift_group_id={$old->id}&offset=0")->assertOk();

    expect($response->json('employees.0.name'))->toBe('Pat Past')
        ->and($response->json('employees.0.total_hours'))->toBe(16.0);
});

it('lets a new group take the name of an archived one', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $old = ShiftGroup::create(['name' => 'Night desk', 'is_default' => false, 'sort_order' => 1]);
    $old->forceFill(['archived_at' => now()])->save();

    $this->actingAs($admin)->postJson('/api/shift-groups', ['name' => 'Night desk'])->assertCreated();

    expect(ShiftGroup::where('name', 'Night desk')->count())->toBe(2);
});

it('refuses every other schedule change in an archived group, including moves in and out', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $worker = User::factory()->create(['role' => 'employee']);
    $main = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $old = ShiftGroup::create(['name' => 'Old desk', 'is_default' => false, 'sort_order' => 1]);
    $shift = Shift::create(['date' => '2026-10-05', 'shift_number' => 1, 'shift_group_id' => $old->id, 'start_time' => '08:00', 'end_time' => '16:00']);
    $oldAssignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $worker->id]);
    $mainShift = Shift::create(['date' => '2026-10-20', 'shift_number' => 1, 'shift_group_id' => $main->id]);
    $mainAssignment = ShiftAssignment::create(['shift_id' => $mainShift->id, 'user_id' => $worker->id]);
    $template = ChecklistTemplate::create(['name' => 'Handover', 'is_active' => true]);
    $this->actingAs($admin)->deleteJson("/api/shift-groups/{$old->id}")->assertOk();
    $groupError = 'The selected shift group does not exist or is archived.';
    $shiftError = 'This shift belongs to the archived shift group "Old desk". Restore the group to change its schedule.';

    foreach ([
        ['putJson', "/api/schedule/shifts/{$shift->id}/checklist", ['checklists' => [['checklist_template_id' => $template->id, 'is_required' => true]]]],
        ['putJson', "/api/schedule/shifts/{$shift->id}/color", ['color' => '#ff0000']],
        ['putJson', "/api/schedule/shifts/{$shift->id}/type", ['shift_type' => 'night']],
        ['putJson', "/api/schedule/shifts/{$shift->id}/work-mode", ['work_mode' => 'remote']],
        // Moving out of it (its assignment as the source) and into it (its shift as the target).
        ['postJson', '/api/schedule/assign', ['shift_id' => $mainShift->id, 'user_id' => $worker->id, 'move_from_assignment_id' => $oldAssignment->id]],
        ['postJson', '/api/schedule/assign', ['shift_id' => $shift->id, 'user_id' => $worker->id, 'move_from_assignment_id' => $mainAssignment->id]],
    ] as [$method, $url, $body]) {
        $this->actingAs($admin)->{$method}($url, $body)->assertStatus(422)->assertJsonPath('message', $shiftError);
    }
    foreach ([
        ['putJson', '/api/schedule/shift-types', ['shift_group_id' => $old->id, 'month' => '2026-10', 'shift_number' => 1, 'shift_type' => 'night']],
        ['deleteJson', '/api/schedule/clear-month', ['month' => '2026-10', 'shift_group_id' => $old->id]],
        ['postJson', '/api/schedule/auto-assign/rotating', ['month' => '2026-10', 'shift_group_id' => $old->id, 'users_per_shift_weekday' => 1, 'users_per_shift_weekend' => 1, 'rotation_days' => 2]],
        ['postJson', '/api/schedule/auto-assign/polish-law', ['month' => '2026-10', 'shift_group_id' => $old->id, 'users_per_shift_weekday' => 1, 'users_per_shift_weekend' => 1, 'rotation_days' => 2]],
    ] as [$method, $url, $body]) {
        $this->actingAs($admin)->{$method}($url, $body)->assertStatus(422)->assertJsonPath('errors.shift_group_id.0', $groupError);
    }

    $fresh = $shift->fresh();
    expect($fresh->color)->toBeNull()
        ->and($fresh->shift_type)->toBeNull()
        ->and($fresh->work_mode ?? 'office')->toBe('office')
        ->and(ShiftChecklist::where('shift_id', $shift->id)->count())->toBe(0)
        ->and($oldAssignment->fresh())->not->toBeNull()
        ->and($mainAssignment->fresh())->not->toBeNull()
        ->and(ShiftAssignment::count())->toBe(2);
});

it('counts a shift tomorrow as future work and leaves today\'s alone', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $worker = User::factory()->create(['role' => 'employee']);
    $group = ShiftGroup::create(['name' => 'Edge desk', 'is_default' => false, 'sort_order' => 1]);
    $today = ShiftAssignment::create([
        'shift_id' => Shift::create(['date' => '2026-10-09', 'shift_number' => 1, 'shift_group_id' => $group->id])->id,
        'user_id' => $worker->id,
    ]);
    $tomorrow = ShiftAssignment::create([
        'shift_id' => Shift::create(['date' => '2026-10-10', 'shift_number' => 1, 'shift_group_id' => $group->id])->id,
        'user_id' => $worker->id,
    ]);

    $this->actingAs($admin)->deleteJson("/api/shift-groups/{$group->id}")
        ->assertStatus(409)
        ->assertJsonCount(1, 'assignments')
        ->assertJsonPath('assignments.0.id', $tomorrow->id);

    $this->actingAs($admin)->deleteJson("/api/shift-groups/{$group->id}", ['remove_future_assignments' => true])
        ->assertOk()->assertJsonPath('removed_assignments', 1);
    expect($tomorrow->fresh())->toBeNull()
        ->and($today->fresh())->not->toBeNull();
});

it('keeps a restored group\'s place in the tab order', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Ordered desk', 'is_default' => false, 'sort_order' => 5]);
    $this->actingAs($admin)->deleteJson("/api/shift-groups/{$group->id}")->assertOk();
    $this->actingAs($admin)->postJson("/api/shift-groups/{$group->id}/restore")->assertOk()
        ->assertJsonPath('group.sort_order', 5);

    expect($group->fresh()->sort_order)->toBe(5);
});

it('says "or is archived" only where archived groups are refused', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $old = ShiftGroup::create(['name' => 'Old desk', 'is_default' => false, 'sort_order' => 1]);
    $old->forceFill(['archived_at' => now()])->save();

    // Reads accept an archived group; an unknown id gets the plain message.
    $this->actingAs($maintainer)->getJson("/api/on-call-assignments?shift_group_id={$old->id}&month=2026-10")->assertOk();
    $this->actingAs($maintainer)->getJson("/api/settlement-periods?shift_group_id={$old->id}")->assertOk();
    $this->actingAs($maintainer)->getJson('/api/settlement-periods?shift_group_id=999999')
        ->assertStatus(422)
        ->assertJsonPath('errors.shift_group_id.0', 'The selected shift group id is invalid.');
    $this->actingAs($maintainer)->getJson('/api/on-call-assignments?shift_group_id=999999&month=2026-10')
        ->assertStatus(422)
        ->assertJsonPath('errors.shift_group_id.0', 'The selected shift group id is invalid.');
    // Planning refuses it with the archived wording.
    $this->actingAs($maintainer)->postJson('/api/schedule/generate', ['month' => '2026-11', 'shift_group_id' => $old->id])
        ->assertStatus(422)
        ->assertJsonPath('errors.shift_group_id.0', 'The selected shift group does not exist or is archived.');
});
