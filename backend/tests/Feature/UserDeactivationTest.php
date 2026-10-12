<?php

use App\Models\ChecklistGroup;
use App\Models\ChecklistItem;
use App\Models\ChecklistSubmission;
use App\Models\ChecklistTemplate;
use App\Models\Delegation;
use App\Models\DelegationLog;
use App\Models\NewbiePeriod;
use App\Models\OfficeClosure;
use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\Procedure;
use App\Models\ProcedureAccessLog;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftChangeRequest;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

function cdlUserAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function cdlEmployee(array $fields = []): User
{
    return User::factory()->create(array_merge(['role' => 'employee'], $fields));
}

/**
 * Gives $user authored history of every kind the brief lists, plus a past, a today's and a future
 * shift assignment and on-call duty (now is 2026-10-09).
 */
function cdlUserWithHistory(User $user): array
{
    $group = ShiftGroup::firstOrCreate(['name' => 'Main'], ['is_default' => true, 'sort_order' => 0]);
    $assignments = [];
    $onCall = [];
    foreach (['2026-10-01' => 'past', '2026-10-09' => 'today', '2026-10-20' => 'future'] as $date => $key) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id]);
        $assignments[$key] = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id]);
        $onCall[$key] = OnCallAssignment::create([
            'shift_group_id' => $group->id, 'user_id' => $user->id,
            'starts_at' => "{$date} 20:00:00", 'ends_at' => Carbon::parse($date)->addDay()->format('Y-m-d 08:00:00'),
        ]);
    }

    $procedure = Procedure::create(['identifier' => 'CDL-1', 'title' => 'Owned', 'owner_id' => $user->id]);
    $delegation = Delegation::create([
        'name' => 'Trip', 'destination' => 'Berlin', 'created_by' => $user->id,
        'start_datetime' => '2026-11-01 08:00:00', 'end_datetime' => '2026-11-02 18:00:00',
    ]);
    $log = DelegationLog::create(['delegation_id' => $delegation->id, 'performed_by' => $user->id, 'action' => 'created']);
    $closure = OfficeClosure::create(['date' => '2026-12-24', 'title' => 'Eve', 'created_by' => $user->id]);
    $newbie = NewbiePeriod::create(['user_id' => $user->id, 'start_date' => '2026-01-01', 'end_date' => '2026-02-01', 'created_by' => $user->id]);
    $template = ChecklistTemplate::create(['name' => 'Handover', 'is_active' => true]);
    $handover = ChecklistSubmission::create([
        'checklist_template_id' => $template->id, 'date' => '2026-10-01',
        'outgoing_user_id' => $user->id, 'status' => 'completed',
    ]);
    $access = ProcedureAccessLog::create(['procedure_id' => $procedure->id, 'user_id' => $user->id, 'accessed_at' => now()]);

    return compact('group', 'assignments', 'onCall', 'procedure', 'delegation', 'log', 'closure', 'newbie', 'handover', 'access');
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
});

it('deactivates instead of deleting, keeping everything the user created', function () {
    $admin = cdlUserAdmin();
    $user = cdlEmployee();
    $h = cdlUserWithHistory($user);

    $this->actingAs($admin)
        ->postJson("/api/users/{$user->id}/deactivate", ['remove_future_assignments' => false])
        ->assertOk();

    expect($user->fresh()->deactivated_at)->not->toBeNull();
    foreach (['procedure', 'delegation', 'log', 'closure', 'newbie', 'handover', 'access'] as $key) {
        expect($h[$key]->fresh())->not->toBeNull("{$key} must survive deactivation");
    }
    // remove_future_assignments=false keeps every assignment and on-call duty.
    expect(ShiftAssignment::where('user_id', $user->id)->count())->toBe(3);
    expect(OnCallAssignment::where('user_id', $user->id)->count())->toBe(3);
});

it('removes only shifts and on-call duties from tomorrow on when asked to', function () {
    $admin = cdlUserAdmin();
    $user = cdlEmployee();
    $h = cdlUserWithHistory($user);

    $this->actingAs($admin)->getJson("/api/users/{$user->id}")
        ->assertOk()
        ->assertJsonPath('future_assignments_count', 1)
        ->assertJsonPath('future_on_call_count', 1);

    $this->actingAs($admin)
        ->postJson("/api/users/{$user->id}/deactivate", ['remove_future_assignments' => true])
        ->assertOk()
        ->assertJsonPath('removed_assignments', 1)
        ->assertJsonPath('removed_on_call', 1);

    // Today's shift may already be worked, so it stays with the past one.
    expect(ShiftAssignment::where('user_id', $user->id)->orderBy('id')->pluck('id')->all())
        ->toBe([$h['assignments']['past']->id, $h['assignments']['today']->id]);
    expect(OnCallAssignment::where('user_id', $user->id)->orderBy('id')->pluck('id')->all())
        ->toBe([$h['onCall']['past']->id, $h['onCall']['today']->id]);
    expect($h['procedure']->fresh())->not->toBeNull();
    // Delegation memberships and newbie periods are not touched.
    expect($h['newbie']->fresh())->not->toBeNull();
});

it('takes "tomorrow" in Warsaw time, not UTC, when removing future work', function () {
    // 23:30 UTC on the 5th is 00:30 on the 6th in Warsaw: the 6th is today, only the 7th is future.
    Carbon::setTestNow('2029-01-05 23:30:00');
    $admin = cdlUserAdmin();
    $user = cdlEmployee();
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $ids = [];
    $onCallIds = [];
    foreach (['2029-01-05', '2029-01-06', '2029-01-07'] as $date) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id]);
        $ids[$date] = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id])->id;
        $onCallIds[$date] = OnCallAssignment::create([
            'shift_group_id' => $group->id, 'user_id' => $user->id,
            'starts_at' => "{$date} 08:00:00", 'ends_at' => "{$date} 20:00:00",
        ])->id;
    }

    $this->actingAs($admin)->getJson("/api/users/{$user->id}")
        ->assertJsonPath('future_assignments_count', 1)
        ->assertJsonPath('future_on_call_count', 1);
    $this->actingAs($admin)
        ->postJson("/api/users/{$user->id}/deactivate", ['remove_future_assignments' => true])
        ->assertOk();

    expect(ShiftAssignment::where('user_id', $user->id)->orderBy('id')->pluck('id')->all())
        ->toBe([$ids['2029-01-05'], $ids['2029-01-06']]);
    expect(OnCallAssignment::where('user_id', $user->id)->orderBy('id')->pluck('id')->all())
        ->toBe([$onCallIds['2029-01-05'], $onCallIds['2029-01-06']]);
});

it('no longer allows a user to be hard-deleted through the API', function () {
    $admin = cdlUserAdmin();
    $user = cdlEmployee();

    $this->actingAs($admin)->deleteJson("/api/users/{$user->id}")->assertStatus(405);

    expect($user->fresh())->not->toBeNull();
});

it('does not let an admin deactivate themselves', function () {
    $admin = cdlUserAdmin();

    $this->actingAs($admin)->postJson("/api/users/{$admin->id}/deactivate")->assertStatus(422);

    expect($admin->fresh()->deactivated_at)->toBeNull();
});

it('refuses login for a deactivated user', function () {
    $user = cdlEmployee();
    $user->forceFill(['deactivated_at' => now()])->save();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
        ->assertStatus(403)
        ->assertJson(['message' => 'This account is deactivated.']);

    $this->assertGuest();
});

it('rejects an existing session of a deactivated user on the next request', function () {
    $admin = cdlUserAdmin();
    $user = cdlEmployee();
    $this->actingAs($user)->getJson('/api/user')->assertOk();

    $this->actingAs($admin)->postJson("/api/users/{$user->id}/deactivate")->assertOk();

    // The same (already authenticated) user comes back with their next request.
    $this->actingAs($user->fresh())->getJson('/api/schedule?month=2026-10')
        ->assertStatus(401)
        ->assertJson(['message' => 'This account is deactivated.']);
    $this->assertGuest('web');
});

it('hides a deactivated user from the schedule pool, pickers and user lists', function () {
    $admin = cdlUserAdmin();
    $active = cdlEmployee(['name' => 'Active Annie']);
    $gone = cdlEmployee(['name' => 'Gone Gary']);
    ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    $this->actingAs($admin)->postJson("/api/users/{$gone->id}/deactivate")->assertOk();

    $pool = $this->actingAs($admin)->getJson('/api/schedule?month=2026-10')->assertOk()->json('employees');
    expect(collect($pool)->pluck('id')->all())->toContain($active->id)->not->toContain($gone->id);

    $employees = $this->actingAs($admin)->getJson('/api/employees')->assertOk()->json();
    expect(collect($employees)->pluck('id')->all())->toContain($active->id)->not->toContain($gone->id);

    $users = $this->actingAs($admin)->getJson('/api/users')->assertOk()->json();
    expect(collect($users)->pluck('id')->all())->not->toContain($gone->id);

    $all = $this->actingAs($admin)->getJson('/api/users?include_deactivated=1')->assertOk()->json();
    $row = collect($all)->firstWhere('id', $gone->id);
    expect($row)->not->toBeNull();
    expect($row['deactivated_at'])->not->toBeNull();

    // New work cannot be given to them.
    $shift = Shift::create(['date' => '2026-10-15', 'shift_number' => 1, 'shift_group_id' => ShiftGroup::value('id')]);
    $this->actingAs($admin)->postJson('/api/schedule/assign', ['shift_id' => $shift->id, 'user_id' => $gone->id])
        ->assertStatus(422);
});

it('leaves deactivated users out of auto-fill', function (string $endpoint, array $extra) {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $active = cdlEmployee();
    $gone = cdlEmployee();
    $gone->forceFill(['deactivated_at' => now()])->save();
    foreach (['2029-01-01', '2029-01-02', '2029-01-03', '2029-01-04'] as $date) {
        Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day',
            'start_time' => '08:00', 'end_time' => '16:00']);
    }

    $this->actingAs($maintainer)->postJson($endpoint, array_merge([
        'month' => '2029-01',
        'shift_group_id' => $group->id,
        'users_per_shift_weekday' => 2,
        'users_per_shift_weekend' => 2,
        'player_mode' => 'all',
    ], $extra))->assertOk();

    expect(ShiftAssignment::where('user_id', $gone->id)->count())->toBe(0);
    expect(ShiftAssignment::where('user_id', $active->id)->count())->toBeGreaterThan(0);
})->with([
    'random' => ['/api/schedule/auto-assign/random', []],
    'rotating' => ['/api/schedule/auto-assign/rotating', ['rotation_days' => 7]],
    'polish-law' => ['/api/schedule/auto-assign/polish-law', ['rotation_days' => 7]],
]);

it('restores login and pickers on reactivation', function () {
    $admin = cdlUserAdmin();
    $user = cdlEmployee();
    $this->actingAs($admin)->postJson("/api/users/{$user->id}/deactivate")->assertOk();

    $this->actingAs($admin)->postJson("/api/users/{$user->id}/reactivate")->assertOk();

    expect($user->fresh()->deactivated_at)->toBeNull();
    $employees = $this->actingAs($admin)->getJson('/api/employees')->json();
    expect(collect($employees)->pluck('id')->all())->toContain($user->id);

    // Start the login as a fresh guest (the admin's requests left the sanctum guard as default).
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
});

it('makes a stray hard delete of a user with history fail instead of cascading', function () {
    $user = cdlEmployee();
    Procedure::create(['identifier' => 'CDL-2', 'title' => 'Owned', 'owner_id' => $user->id]);

    expect(fn () => $user->delete())->toThrow(QueryException::class);
});

it('refuses a new handover to a deactivated incoming user with a clear message', function () {
    $admin = cdlUserAdmin();
    $gone = cdlEmployee();
    $gone->forceFill(['deactivated_at' => now()])->save();
    $template = ChecklistTemplate::create(['name' => 'Handover', 'is_active' => true]);
    $group = ChecklistGroup::create(['checklist_template_id' => $template->id, 'name' => 'G', 'sort_order' => 0]);
    $item = ChecklistItem::create(['checklist_group_id' => $group->id, 'label' => 'Done', 'type' => 'checkbox', 'sort_order' => 0]);

    $this->actingAs($admin)->postJson('/api/checklist-submissions', [
        'checklist_template_id' => $template->id,
        'date' => '2026-10-09',
        'outgoing_user_id' => $admin->id,
        'incoming_user_id' => $gone->id,
        'responses' => [['checklist_item_id' => $item->id, 'value' => true]],
    ])->assertStatus(422)
        ->assertJsonPath('errors.incoming_user_id.0', 'The incoming user has been deactivated. Pick someone else.');
});

it('refuses deactivation and reactivation to anyone but an admin', function (string $role) {
    $actor = User::factory()->create(['role' => $role]);
    $user = cdlEmployee();

    $this->actingAs($actor)->postJson("/api/users/{$user->id}/deactivate")->assertForbidden();
    expect($user->fresh()->deactivated_at)->toBeNull();

    $user->forceFill(['deactivated_at' => now()])->save();
    $this->actingAs($actor)->postJson("/api/users/{$user->id}/reactivate")->assertForbidden();
    expect($user->fresh()->deactivated_at)->not->toBeNull();
})->with(['employee', 'schedule_maintainer']);

it('keeps delegation memberships and the original deactivation time, and revokes API tokens', function () {
    $admin = cdlUserAdmin();
    $user = cdlEmployee();
    $h = cdlUserWithHistory($user);
    $h['delegation']->users()->attach($user->id, ['status' => 'approved']);
    $user->createToken('cli');

    $this->actingAs($admin)->postJson("/api/users/{$user->id}/deactivate")->assertOk();
    $first = $user->fresh()->deactivated_at;
    Carbon::setTestNow('2026-10-10 10:00:00');
    $this->actingAs($admin)->postJson("/api/users/{$user->id}/deactivate")->assertOk();

    expect($user->fresh()->deactivated_at->equalTo($first))->toBeTrue();
    expect($user->tokens()->count())->toBe(0);
    expect($h['delegation']->users()->whereKey($user->id)->exists())->toBeTrue();
});

it('settles and keeps change requests of removed future shifts, and leaves the ones on kept shifts pending', function () {
    // The request on the removed future assignment is acknowledged and stays with its assignment
    // link nulled; callbacks of a removed future on-call duty go with the duty.
    $admin = cdlUserAdmin();
    $user = cdlEmployee();
    $h = cdlUserWithHistory($user);
    $requests = [];
    foreach (['today', 'future'] as $key) {
        $requests[$key] = ShiftChangeRequest::create([
            'shift_assignment_id' => $h['assignments'][$key]->id, 'user_id' => $user->id,
            'shift_id' => $h['assignments'][$key]->shift_id, 'reason' => 'Leaving', 'status' => 'pending',
        ]);
    }
    $callback = OnCallCallback::create([
        'on_call_assignment_id' => $h['onCall']['future']->id,
        'starts_at' => '2026-10-20 22:00:00', 'ends_at' => '2026-10-20 23:00:00',
    ]);

    $this->actingAs($admin)
        ->postJson("/api/users/{$user->id}/deactivate", ['remove_future_assignments' => true])
        ->assertOk();

    $future = $requests['future']->fresh();
    expect($future)->not->toBeNull()
        ->and($future->status)->toBe('acknowledged')
        ->and($future->shift_assignment_id)->toBeNull()
        ->and($future->shift_id)->toBe($h['assignments']['future']->shift_id)
        ->and($callback->fresh())->toBeNull()
        ->and($requests['today']->fresh()->status)->toBe('pending')
        ->and($requests['today']->fresh()->shift_assignment_id)->toBe($h['assignments']['today']->id);
});
