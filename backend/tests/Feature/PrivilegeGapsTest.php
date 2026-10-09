<?php

use App\Models\Alarm;
use App\Models\ChecklistGroup;
use App\Models\ChecklistItem;
use App\Models\ChecklistSubmission;
use App\Models\ChecklistTemplate;
use App\Models\Procedure;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function prvUser(string $role = 'employee', array $fields = []): User
{
    return User::factory()->create(array_merge(['role' => $role], $fields));
}

/**
 * $outgoing submits a handover naming $incoming, on a template with an item needing confirmation,
 * through the API. Returns the pending submission.
 */
/** A template whose one item needs the incoming user's confirmation. */
function prvConfirmTemplate(): ChecklistItem
{
    $template = ChecklistTemplate::create(['name' => 'Handover', 'is_active' => true]);
    $group = ChecklistGroup::create(['checklist_template_id' => $template->id, 'name' => 'Doors', 'sort_order' => 0]);

    return ChecklistItem::create([
        'checklist_group_id' => $group->id, 'label' => 'Front door locked', 'type' => 'checkbox',
        'requires_confirmation' => true, 'sort_order' => 0,
    ]);
}

/** $filer POSTs a handover from $outgoingId to $incomingId (ids as given, so strings can be sent). */
function prvFile(User $filer, $outgoingId, $incomingId, ?ChecklistItem $item = null)
{
    $item = $item ?? prvConfirmTemplate();

    return test()->actingAs($filer)->postJson('/api/checklist-submissions', [
        'checklist_template_id' => $item->group->checklist_template_id,
        'date' => '2026-10-09',
        'outgoing_user_id' => $outgoingId,
        'incoming_user_id' => $incomingId,
        'responses' => [['checklist_item_id' => $item->id, 'value' => true]],
    ]);
}

function prvPendingHandover(User $outgoing, User $incoming): ChecklistSubmission
{
    $id = prvFile($outgoing, $outgoing->id, $incoming->id)->assertCreated()->json('id');

    $submission = ChecklistSubmission::findOrFail($id);
    expect($submission->status)->toBe('pending_confirmation');

    return $submission;
}

function prvConfirm(User $as, ChecklistSubmission $submission)
{
    return test()->actingAs($as)->putJson("/api/checklist-submissions/{$submission->id}/confirm", [
        'confirmed_item_ids' => $submission->responses()->pluck('id')->all(),
    ]);
}

// ── H1: only the incoming user confirms a handover ────────────────────────────

it('refuses a handover confirmation from anyone but the incoming user', function () {
    $outgoing = prvUser();
    $incoming = prvUser();
    $submission = prvPendingHandover($outgoing, $incoming);

    foreach ([$outgoing, prvUser(), prvUser('schedule_maintainer'), prvUser('admin')] as $other) {
        prvConfirm($other, $submission)->assertForbidden();
    }

    $submission->refresh();
    expect($submission->status)->toBe('pending_confirmation');
    expect($submission->confirmed_at)->toBeNull();
    expect($submission->confirmed_by_id)->toBeNull();
    expect($submission->responses()->where('confirmed', true)->count())->toBe(0);
});

it('lets the incoming user confirm and records who confirmed', function () {
    $outgoing = prvUser();
    $incoming = prvUser();
    $submission = prvPendingHandover($outgoing, $incoming);

    prvConfirm($incoming, $submission)->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('confirmed_by_id', $incoming->id);

    $submission->refresh();
    expect($submission->status)->toBe('completed');
    expect($submission->confirmed_by_id)->toBe($incoming->id);
    expect($submission->responses()->where('confirmed', true)->count())->toBe(1);
});

it('lists pending confirmations only to the incoming user', function () {
    $a = prvUser();
    $b = prvUser();
    $submission = prvPendingHandover($a, $b);

    $forA = test()->actingAs($a)->getJson('/api/checklist-submissions/pending-confirmations')->assertOk()->json();
    expect(collect($forA)->pluck('id')->all())->not->toContain($submission->id);

    $forB = test()->actingAs($b)->getJson('/api/checklist-submissions/pending-confirmations')->assertOk()->json();
    expect(collect($forB)->pluck('id')->all())->toBe([$submission->id]);

    // Once confirmed it leaves the list; history still finds it under both users.
    prvConfirm($b, $submission)->assertOk();
    expect(test()->actingAs($b)->getJson('/api/checklist-submissions/pending-confirmations')->json())->toBe([]);
    foreach ([$a, $b] as $user) {
        $history = test()->actingAs($user)->getJson("/api/checklist-submissions?user_id={$user->id}")->assertOk()->json('data');
        expect(collect($history)->pluck('id')->all())->toContain($submission->id);
    }
});

it('files a handover only as yourself unless you are an admin', function () {
    $a = prvUser();
    $b = prvUser();
    $c = prvUser();

    // The reviewer's forgery: A files "from" colleague B naming themselves as incoming, then confirms alone.
    prvFile($a, $b->id, $a->id)->assertStatus(422)->assertJsonValidationErrors('outgoing_user_id');
    prvFile($a, $b->id, $c->id)->assertStatus(422)->assertJsonValidationErrors('outgoing_user_id');
    // Naming yourself as both ends (also with ids sent as strings).
    prvFile($a, $a->id, $a->id)->assertStatus(422)->assertJsonValidationErrors('incoming_user_id');
    prvFile($a, (string) $a->id, $a->id)->assertStatus(422)->assertJsonValidationErrors('incoming_user_id');
    prvFile($a, $a->id, (string) $a->id)->assertStatus(422)->assertJsonValidationErrors('incoming_user_id');
    // Ids sent as strings don't slip past any of the checks.
    prvFile($a, (string) $b->id, (string) $a->id)->assertStatus(422)->assertJsonValidationErrors('outgoing_user_id');
    // Form-encoded (every value a string) too.
    $item = prvConfirmTemplate();
    test()->actingAs($a)->post('/api/checklist-submissions', [
        'checklist_template_id' => (string) $item->group->checklist_template_id, 'date' => '2026-10-09',
        'outgoing_user_id' => (string) $a->id, 'incoming_user_id' => (string) $a->id,
        'responses' => [['checklist_item_id' => (string) $item->id, 'value' => '1']],
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('incoming_user_id');
    expect(ChecklistSubmission::count())->toBe(0);

    prvFile($a, $a->id, $b->id)->assertCreated()->assertJsonPath('status', 'pending_confirmation');
    prvFile($a, (string) $a->id, (string) $b->id)->assertCreated();
    prvFile($a, $a->id, null)->assertCreated()->assertJsonPath('status', 'completed');
});

it('lets an admin file on behalf of someone, but not to themselves', function () {
    $admin = prvUser('admin');
    $b = prvUser();
    $c = prvUser();

    prvFile($admin, $b->id, $admin->id)->assertStatus(422)->assertJsonValidationErrors('incoming_user_id');
    prvFile($admin, $b->id, $b->id)->assertStatus(422)->assertJsonValidationErrors('incoming_user_id');
    prvFile($admin, (string) $b->id, $b->id)->assertStatus(422)->assertJsonValidationErrors('incoming_user_id');
    prvFile($admin, (string) $b->id, (string) $admin->id)->assertStatus(422)->assertJsonValidationErrors('incoming_user_id');
    prvFile($admin, $b->id, (string) $admin->id)->assertStatus(422)->assertJsonValidationErrors('incoming_user_id');
    prvFile($admin, $admin->id, $admin->id)->assertStatus(422)->assertJsonValidationErrors('incoming_user_id');
    expect(ChecklistSubmission::count())->toBe(0);

    prvFile($admin, $b->id, $c->id)->assertCreated()
        ->assertJsonPath('outgoing_user_id', $b->id)->assertJsonPath('incoming_user_id', $c->id);
    prvFile($admin, $admin->id, $c->id)->assertCreated();
});

// ── H2: procedure move and change-owner are admin-only ────────────────────────

it('keeps procedure move and change-owner to admins', function () {
    $owner = prvUser();
    $parent = Procedure::create(['identifier' => 'PRV-1', 'title' => 'Parent', 'owner_id' => $owner->id]);
    $child = Procedure::create(['identifier' => 'PRV-2', 'title' => 'Child', 'owner_id' => $owner->id]);

    foreach ([prvUser(), prvUser('schedule_maintainer')] as $user) {
        test()->actingAs($user)->putJson("/api/procedures/{$child->id}/move", ['parent_id' => $parent->id])
            ->assertForbidden();
        test()->actingAs($user)->putJson("/api/procedures/{$child->id}/change-owner", [
            'new_owner_id' => $user->id, 'reason' => 'Mine now',
        ])->assertForbidden();
    }
    $child->refresh();
    expect($child->parent_id)->toBeNull();
    expect($child->owner_id)->toBe($owner->id);
    expect(DB::table('procedure_owner_changes')->count())->toBe(0);

    $admin = prvUser('admin');
    $newOwner = prvUser();
    test()->actingAs($admin)->putJson("/api/procedures/{$child->id}/move", ['parent_id' => $parent->id])->assertOk();
    test()->actingAs($admin)->putJson("/api/procedures/{$child->id}/change-owner", [
        'new_owner_id' => $newOwner->id, 'reason' => 'Handing over',
    ])->assertOk();
    $child->refresh();
    expect($child->parent_id)->toBe($parent->id);
    expect($child->owner_id)->toBe($newOwner->id);
});

// ── Middleware groups: one representative route per group per role ───────────

it('enforces the admin and admin:schedule route groups by role', function (string $role, int $adminRoute, int $scheduleRoute) {
    $user = prvUser($role);
    test()->actingAs($user)->getJson('/api/users')->assertStatus($adminRoute);
    test()->actingAs($user)->getJson('/api/shift-groups')->assertStatus($scheduleRoute);

    // One write route per group. Allowed callers get past the middleware (and fail validation on the
    // empty body), so only the 403 matters here.
    $write = test()->actingAs($user)->postJson('/api/procedures', []);
    $adminRoute === 403 ? $write->assertForbidden() : $write->assertStatus(422);
    $write = test()->actingAs($user)->postJson('/api/schedule/assign', []);
    $scheduleRoute === 403 ? $write->assertForbidden() : $write->assertStatus(422);
})->with([
    'employee' => ['employee', 403, 403],
    'schedule maintainer' => ['schedule_maintainer', 403, 200],
    'admin' => ['admin', 200, 200],
]);

// ── H3: role is the single source of truth for admin status ───────────────────

it('converts is_admin into role and drops the column', function () {
    $migration = require database_path('migrations/2026_10_09_110000_make_role_the_only_admin_flag.php');

    $migration->down();
    expect(Schema::hasColumn('users', 'is_admin'))->toBeTrue();
    $rows = [
        'flag-only' => [true, 'employee', 'admin'],
        'role-only' => [false, 'admin', 'admin'],
        'both' => [true, 'admin', 'admin'],
        'flag-and-maintainer' => [true, 'schedule_maintainer', 'admin'],
        'maintainer' => [false, 'schedule_maintainer', 'schedule_maintainer'],
        'employee' => [false, 'employee', 'employee'],
    ];
    foreach ($rows as $name => [$isAdmin, $role]) {
        DB::table('users')->insert([
            'name' => $name, 'email' => "{$name}@prv.test", 'password' => 'x',
            'is_admin' => $isAdmin, 'role' => $role, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $migration->up();
    expect(Schema::hasColumn('users', 'is_admin'))->toBeFalse();
    foreach ($rows as $name => [, , $expected]) {
        expect(DB::table('users')->where('name', $name)->value('role'))->toBe($expected);
    }

    // down() rebuilds the flag from role.
    $migration->down();
    expect(DB::table('users')->where('is_admin', true)->whereIn('name', array_keys($rows))->pluck('name')->sort()->values()->all())
        ->toBe(['both', 'flag-and-maintainer', 'flag-only', 'role-only']);
    $migration->up();
});

it('takes admin access away when an admin is demoted', function () {
    $admin = prvUser('admin');
    $colleague = prvUser('admin');
    test()->actingAs($colleague)->getJson('/api/users')->assertOk();

    test()->actingAs($admin)->putJson("/api/users/{$colleague->id}", [
        'name' => $colleague->name, 'email' => $colleague->email, 'role' => 'employee',
    ])->assertOk()->assertJsonPath('role', 'employee')->assertJsonPath('is_admin', false);

    $colleague->refresh();
    expect($colleague->isAdmin())->toBeFalse();
    test()->actingAs($colleague)->getJson('/api/users')->assertForbidden();
});

it('creates users with a role and exposes is_admin read-only', function () {
    $admin = prvUser('admin');

    $created = test()->actingAs($admin)->postJson('/api/users', [
        'name' => 'New Admin', 'email' => 'new-admin@prv.test',
        'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'admin',
    ])->assertCreated()->assertJsonPath('role', 'admin')->assertJsonPath('is_admin', true);
    expect(User::find($created->json('id'))->isAdmin())->toBeTrue();

    test()->actingAs($admin)->postJson('/api/users', [
        'name' => 'Plain', 'email' => 'plain@prv.test',
        'password' => 'password123', 'password_confirmation' => 'password123',
    ])->assertCreated()->assertJsonPath('role', 'employee');

    test()->actingAs($admin)->postJson('/api/users', [
        'name' => 'Bad', 'email' => 'bad@prv.test',
        'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'superuser',
    ])->assertStatus(422)->assertJsonValidationErrors('role');

    $row = collect(test()->actingAs($admin)->getJson('/api/users')->assertOk()->json())->firstWhere('email', 'new-admin@prv.test');
    expect($row['role'])->toBe('admin');
    expect($row['is_admin'])->toBeTrue();
});

it('rejects is_admin input on user create and update', function () {
    $admin = prvUser('admin');
    $employee = prvUser();

    test()->actingAs($admin)->postJson('/api/users', [
        'name' => 'Sneaky', 'email' => 'sneaky@prv.test',
        'password' => 'password123', 'password_confirmation' => 'password123', 'is_admin' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('is_admin');
    expect(User::where('email', 'sneaky@prv.test')->exists())->toBeFalse();

    test()->actingAs($admin)->putJson("/api/users/{$employee->id}", [
        'name' => $employee->name, 'email' => $employee->email, 'is_admin' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('is_admin');
    expect($employee->fresh()->isAdmin())->toBeFalse();
});

it('refuses an admin changing their own role', function () {
    $admin = prvUser('admin');
    prvUser('admin');

    test()->actingAs($admin)->putJson("/api/users/{$admin->id}", [
        'name' => $admin->name, 'email' => $admin->email, 'role' => 'employee',
    ])->assertStatus(422)->assertJsonPath('message', 'You cannot change your own role.');
    expect($admin->fresh()->role)->toBe('admin');

    // Saving their own profile with the role unchanged still works.
    test()->actingAs($admin)->putJson("/api/users/{$admin->id}", [
        'name' => 'Renamed', 'email' => $admin->email, 'role' => 'admin',
    ])->assertOk();
    expect($admin->fresh()->name)->toBe('Renamed');
});

it('refuses demoting the last active admin', function () {
    $admin = prvUser('admin');
    // A deactivated admin doesn't count.
    prvUser('admin', ['deactivated_at' => now()]);

    test()->actingAs($admin)->putJson("/api/users/{$admin->id}", [
        'name' => $admin->name, 'email' => $admin->email, 'role' => 'schedule_maintainer',
    ])->assertStatus(422)->assertJsonPath('message', 'There must be at least one active admin. Make someone else an admin first.');
    expect($admin->fresh()->role)->toBe('admin');
});

it('sends change-request alarms to admins created on the Users screen', function () {
    $admin = prvUser('admin');
    $uiAdminId = test()->actingAs($admin)->postJson('/api/users', [
        'name' => 'UI Admin', 'email' => 'ui-admin@prv.test',
        'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'admin',
    ])->assertCreated()->json('id');
    $maintainer = prvUser('schedule_maintainer');
    $bystander = prvUser();

    $employee = prvUser();
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $shift = Shift::create(['date' => '2030-01-10', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);

    test()->actingAs($employee)->postJson('/api/shift-change-requests', [
        'shift_assignment_id' => $assignment->id, 'reason' => 'Doctor appointment',
    ])->assertCreated();

    $notified = Alarm::where('type', Alarm::TYPE_EMPLOYEE_CANNOT_ATTEND_SHIFT)->pluck('user_id')->all();
    expect($notified)->toContain($uiAdminId, $admin->id, $maintainer->id);
    expect($notified)->not->toContain($bystander->id, $employee->id);
});

it('leaves admins out of auto-fill pools', function (string $endpoint, array $extra) {
    $maintainer = prvUser('schedule_maintainer');
    $admin = prvUser('admin');
    $employee = prvUser();
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    foreach (['2029-01-01', '2029-01-02', '2029-01-03', '2029-01-04'] as $date) {
        Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day',
            'start_time' => '08:00', 'end_time' => '16:00']);
    }

    test()->actingAs($maintainer)->postJson($endpoint, array_merge([
        'month' => '2029-01',
        'shift_group_id' => $group->id,
        'users_per_shift_weekday' => 3,
        'users_per_shift_weekend' => 3,
        'player_mode' => 'all',
    ], $extra))->assertOk();

    expect(ShiftAssignment::where('user_id', $admin->id)->count())->toBe(0);
    expect(ShiftAssignment::where('user_id', $employee->id)->count())->toBeGreaterThan(0);
})->with([
    'random' => ['/api/schedule/auto-assign/random', []],
    'rotating' => ['/api/schedule/auto-assign/rotating', ['rotation_days' => 7]],
    'polish-law' => ['/api/schedule/auto-assign/polish-law', ['rotation_days' => 7]],
]);
