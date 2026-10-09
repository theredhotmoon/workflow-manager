<?php

use App\Models\ChecklistSubmission;
use App\Models\ChecklistTemplate;
use App\Models\Delegation;
use App\Models\DelegationLog;
use App\Models\NewbiePeriod;
use App\Models\OfficeClosure;
use App\Models\Procedure;
use App\Models\ProcedureAccessLog;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

function cdlUserAdmin(): User
{
    return User::factory()->create(['is_admin' => true, 'role' => 'admin']);
}

function cdlEmployee(array $fields = []): User
{
    return User::factory()->create(array_merge(['is_admin' => false, 'role' => 'employee'], $fields));
}

/** Gives $user authored history of every kind the brief lists, plus a past and two future assignments. */
function cdlUserWithHistory(User $user): array
{
    $group = ShiftGroup::firstOrCreate(['name' => 'Main'], ['is_default' => true, 'sort_order' => 0]);
    $assignments = [];
    foreach (['2026-10-01' => 'past', '2026-10-09' => 'today', '2026-10-20' => 'future'] as $date => $key) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id]);
        $assignments[$key] = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id]);
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

    return compact('group', 'assignments', 'procedure', 'delegation', 'log', 'closure', 'newbie', 'handover', 'access');
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
    // remove_future_assignments=false keeps every assignment.
    expect(ShiftAssignment::where('user_id', $user->id)->count())->toBe(3);
});

it('removes only assignments from today on when asked to', function () {
    $admin = cdlUserAdmin();
    $user = cdlEmployee();
    $h = cdlUserWithHistory($user);

    $this->actingAs($admin)->getJson("/api/users/{$user->id}")
        ->assertOk()
        ->assertJsonPath('future_assignments_count', 2);

    $this->actingAs($admin)
        ->postJson("/api/users/{$user->id}/deactivate", ['remove_future_assignments' => true])
        ->assertOk()
        ->assertJsonPath('removed_assignments', 2);

    expect(ShiftAssignment::where('user_id', $user->id)->pluck('id')->all())->toBe([$h['assignments']['past']->id]);
    expect($h['procedure']->fresh())->not->toBeNull();
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
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer', 'is_admin' => true]);
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
