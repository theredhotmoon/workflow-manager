<?php

use App\Models\Delegation;
use App\Models\Procedure;
use App\Models\ProcedureAccessLog;
use App\Models\ProcedureVersion;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftChangeRequest;
use App\Models\ShiftGroup;
use App\Models\User;
use App\Models\UserAvailability;
use App\Models\UserGroup;
use Illuminate\Support\Carbon;

// L1-L4, "need-to-know": colleagues' availability notes, change-request reasons, procedure access
// logs and drafts, emails and delegation applicant status are shown only to the people who need
// them (the subject, the owner, admins and, where it's their screen, schedule maintainers).

function ntkUser(string $role = 'employee'): User
{
    return User::factory()->create(['role' => $role]);
}

/** Every role's viewer plus a subject (the colleague whose data it is). */
function ntkCast(): array
{
    return [
        'subject' => ntkUser(),
        'employee' => ntkUser(),
        'maintainer' => ntkUser(User::ROLE_SCHEDULE_MAINTAINER),
        'admin' => ntkUser(User::ROLE_ADMIN),
    ];
}

function ntkShift(string $date = '2026-10-09', ?User $assignee = null): Shift
{
    $group = ShiftGroup::firstOrCreate(['name' => 'Main'], ['is_default' => true, 'sort_order' => 0]);
    $shift = Shift::create([
        'date' => $date, 'shift_number' => Shift::where('date', $date)->count() + 1, 'shift_group_id' => $group->id,
        'start_time' => '06:00', 'end_time' => '18:00',
    ]);
    if ($assignee) {
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $assignee->id]);
    }

    return $shift;
}

function ntkProcedure(User $owner): Procedure
{
    $procedure = Procedure::create([
        'identifier' => 'NTK-'.Procedure::count(), 'title' => 'Published', 'content' => '<p>v1</p>',
        'owner_id' => $owner->id, 'version' => 1,
        'has_draft' => true, 'draft_title' => 'Secret draft title', 'draft_purpose' => 'Draft purpose',
        'draft_requirements' => 'Draft requirements', 'draft_content' => '<p>Secret draft</p>',
    ]);
    ProcedureVersion::create([
        'procedure_id' => $procedure->id, 'version' => 1, 'title' => 'Published', 'content' => '<p>v1</p>',
        'change_reason' => 'Initial version', 'published_by' => $owner->id, 'published_at' => now(),
    ]);

    return $procedure;
}

/** Every "email" key anywhere in a decoded JSON payload, with the "id" next to it. */
function ntkEmailOwners(mixed $data): array
{
    $found = [];
    if (is_array($data)) {
        if (array_key_exists('email', $data)) {
            $found[] = $data['id'] ?? null;
        }
        foreach ($data as $value) {
            $found = [...$found, ...ntkEmailOwners($value)];
        }
    }

    return $found;
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

// ── L1: availability notes ────────────────────────────────────────────────────

it('shows an availability note only to its user and schedule managers; status stays visible', function () {
    $cast = ntkCast();
    UserAvailability::create([
        'user_id' => $cast['subject']->id, 'type' => 'unavailable',
        'start_date' => '2026-10-12', 'end_date' => '2026-10-12', 'note' => 'Hospital appointment',
    ]);

    $expected = ['subject' => 'Hospital appointment', 'employee' => null, 'maintainer' => 'Hospital appointment', 'admin' => 'Hospital appointment'];
    foreach ($expected as $who => $note) {
        $this->actingAs($cast[$who])
            ->getJson("/api/user-availabilities/{$cast['subject']->id}/month?month=2026-10")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.type', 'unavailable')
            ->assertJsonPath('0.start_date', '2026-10-12')
            ->assertJsonPath('0.note', $note);
    }

    // Serialising the model directly (any future endpoint) hides it too.
    $this->actingAs($cast['employee']);
    expect(UserAvailability::first()->toArray())->not->toHaveKey('note');
    $this->actingAs($cast['subject']);
    expect(UserAvailability::first()->toArray())->toHaveKey('note');
});

// ── L2: change-request reasons ────────────────────────────────────────────────

it('shows a change-request reason in the schedule only to the requester and schedule managers', function () {
    $cast = ntkCast();
    $shift = ntkShift('2026-10-12', $cast['subject']);
    $assignment = $shift->assignments()->first();
    ShiftChangeRequest::create([
        'shift_assignment_id' => $assignment->id, 'user_id' => $cast['subject']->id, 'shift_id' => $shift->id,
        'reason' => 'Family emergency', 'status' => 'pending',
    ]);

    $expected = ['subject' => 'Family emergency', 'employee' => null, 'maintainer' => 'Family emergency', 'admin' => 'Family emergency'];
    foreach ($expected as $who => $reason) {
        $res = $this->actingAs($cast[$who])->getJson("/api/schedule?month=2026-10&shift_group_id={$shift->shift_group_id}")->assertOk();
        $chip = $res->json('schedule.2026-10-12.0.assignments.0');
        expect($chip['has_change_request'])->toBeTrue("{$who} still sees that a request exists")
            ->and($chip['change_request_reason'])->toBe($reason, "{$who} reason");
    }

    $this->actingAs($cast['employee']);
    expect(ShiftChangeRequest::first()->toArray())->not->toHaveKey('reason');
    $this->actingAs($cast['subject']);
    expect(ShiftChangeRequest::first()->toArray())->toHaveKey('reason');
});

// ── L3: procedure access log and drafts ───────────────────────────────────────

it('lets only admins and the owner read a procedure access log', function () {
    $cast = ntkCast();
    $procedure = ntkProcedure($cast['subject']);
    ProcedureAccessLog::create(['procedure_id' => $procedure->id, 'user_id' => $cast['employee']->id, 'version_read' => 1, 'accessed_at' => now()]);

    $expected = ['subject' => 200, 'employee' => 403, 'maintainer' => 403, 'admin' => 200];
    foreach ($expected as $who => $status) {
        $this->actingAs($cast[$who])->getJson("/api/procedures/{$procedure->id}/access-log")->assertStatus($status);
        $this->actingAs($cast[$who])->getJson("/api/procedures/{$procedure->id}")
            ->assertOk()->assertJsonPath('can_view_access_log', $status === 200);
    }
});

it('hides a procedure draft from everyone but admins and the owner, in every procedure payload', function () {
    $cast = ntkCast();
    $procedure = ntkProcedure($cast['subject']);

    foreach (['subject' => true, 'employee' => false, 'maintainer' => false, 'admin' => true] as $who => $sees) {
        $payloads = [
            'show' => $this->actingAs($cast[$who])->getJson("/api/procedures/{$procedure->id}")->assertOk()->json(),
            'index' => $this->actingAs($cast[$who])->getJson('/api/procedures')->assertOk()->json('data.0'),
            'search' => $this->actingAs($cast[$who])->getJson('/api/procedures?search=Published')->assertOk()->json('data.0'),
        ];
        foreach ($payloads as $name => $payload) {
            foreach (Procedure::DRAFT_FIELDS as $field) {
                expect(array_key_exists($field, $payload))->toBe($sees, "{$who} {$name} {$field}");
            }
        }
        // Tree, versions and "to learn" never carry the draft at all.
        foreach (['/api/procedures-tree', "/api/procedures/{$procedure->id}/versions", "/api/procedures/{$procedure->id}/versions/1", '/api/procedures-to-learn'] as $url) {
            $body = $this->actingAs($cast[$who])->getJson($url)->assertOk()->getContent();
            expect($body)->not->toContain('Secret draft');
        }
    }
});

// ── L4: emails ────────────────────────────────────────────────────────────────

it('returns a colleague email only to admins, and your own email to you', function () {
    $cast = ntkCast();

    $emp = $this->actingAs($cast['employee'])->getJson('/api/employees')->assertOk()->json();
    foreach ($emp as $row) {
        expect(array_key_exists('email', $row))->toBe($row['id'] === $cast['employee']->id, "employee sees {$row['id']}");
        expect($row)->toHaveKey('name');
    }
    $mnt = $this->actingAs($cast['maintainer'])->getJson('/api/employees')->assertOk()->json();
    foreach ($mnt as $row) {
        expect(array_key_exists('email', $row))->toBe($row['id'] === $cast['maintainer']->id, "maintainer sees {$row['id']}");
    }
    $adm = $this->actingAs($cast['admin'])->getJson('/api/employees')->assertOk()->json();
    expect(collect($adm)->every(fn ($row) => isset($row['email'])))->toBeTrue();
    $this->actingAs($cast['admin'])->getJson('/api/users')->assertOk()
        ->assertJsonPath('0.email', fn ($email) => is_string($email) && $email !== '');

    $this->actingAs($cast['employee'])->getJson('/api/user')->assertOk()->assertJsonPath('email', $cast['employee']->email);
});

it('keeps the current-shift crew to id, name and (for admins and yourself) email', function () {
    $cast = ntkCast();
    $shift = ntkShift('2026-10-09', $cast['subject']);
    ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $cast['employee']->id]);

    $crew = collect($this->actingAs($cast['employee'])->getJson('/api/current-shift')->assertOk()->json('active_shift.crew'));
    expect($crew)->toHaveCount(2);
    foreach ($crew as $member) {
        $keys = array_keys($member);
        sort($keys);
        expect($keys)->toBe($member['id'] === $cast['employee']->id ? ['email', 'id', 'name'] : ['id', 'name']);
    }

    $crew = $this->actingAs($cast['admin'])->getJson('/api/current-shift')->assertOk()->json('active_shift.crew');
    foreach ($crew as $member) {
        $keys = array_keys($member);
        sort($keys);
        expect($keys)->toBe(['email', 'id', 'name']);
    }
});

it('sweeps the user-serialising endpoints: an employee sees no email but their own', function () {
    $cast = ntkCast();
    $me = $cast['employee'];
    $shift = ntkShift('2026-10-09', $cast['subject']);
    ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $me->id]);
    $group = UserGroup::create(['name' => 'Ops', 'enum' => 'ops']);
    $group->users()->sync([$cast['subject']->id, $me->id, $cast['admin']->id]);
    $procedure = ntkProcedure($cast['subject']);
    $delegation = Delegation::create([
        'name' => 'Trip', 'destination' => 'Berlin', 'created_by' => $cast['admin']->id, 'registration_open' => true,
        'start_datetime' => '2026-11-01', 'end_datetime' => '2026-11-02',
    ]);
    $delegation->users()->attach($cast['subject']->id, ['status' => 'approved']);

    $urls = [
        '/api/employees',
        '/api/employees?include_deactivated=1',
        '/api/user-groups',
        "/api/schedule?month=2026-10&shift_group_id={$shift->shift_group_id}",
        '/api/schedule?month=2026-10&shift_group_id=all',
        "/api/shifts/{$shift->id}",
        '/api/shifts-by-date?date=2026-10-09',
        '/api/current-shift',
        '/api/delegations/open',
        '/api/my-delegations',
        '/api/procedures',
        "/api/procedures/{$procedure->id}",
        '/api/procedures-to-learn',
        "/api/procedures/{$procedure->id}/versions",
        '/api/checklist-submissions',
        '/api/alarms',
    ];
    foreach ($urls as $url) {
        $owners = ntkEmailOwners($this->actingAs($me)->getJson($url)->assertOk()->json());
        expect(array_values(array_diff($owners, [$me->id])))->toBe([], "{$url} leaks emails");
    }

    // Model level: any User serialised for this viewer.
    expect($cast['subject']->toArray())->not->toHaveKey('email')
        ->and($me->toArray())->toHaveKey('email');
});

it('matches the subject or owner when an id arrives as a string', function () {
    $viewer = ntkUser();
    $other = ntkUser();

    // Ids as a raw query or pivot can hand them over: strings, not ints.
    $self = (new User)->forceFill(['id' => (string) $viewer->id, 'name' => 'Me', 'email' => 'me@example.test']);
    $availability = (new UserAvailability)->forceFill(['user_id' => (string) $viewer->id, 'note' => 'n']);
    $request = (new ShiftChangeRequest)->forceFill(['user_id' => (string) $viewer->id, 'reason' => 'r']);
    $procedure = (new Procedure)->forceFill(['owner_id' => (string) $viewer->id]);

    expect($self->emailVisibleTo($viewer))->toBeTrue()
        ->and($availability->noteVisibleTo($viewer))->toBeTrue()
        ->and($request->reasonVisibleTo($viewer))->toBeTrue()
        ->and($procedure->isAdminOrOwner($viewer))->toBeTrue()
        ->and($self->emailVisibleTo($other))->toBeFalse()
        ->and($availability->noteVisibleTo($other))->toBeFalse()
        ->and($request->reasonVisibleTo($other))->toBeFalse()
        ->and($procedure->isAdminOrOwner($other))->toBeFalse();
});

// ── L4: delegation applicants ─────────────────────────────────────────────────

it('shows employees only approved participants and their own application on open delegations', function () {
    $cast = ntkCast();
    $approved = ntkUser();
    $pending = ntkUser();
    $rejected = ntkUser();
    $delegation = Delegation::create([
        'name' => 'Trip', 'destination' => 'Berlin', 'created_by' => $cast['admin']->id, 'registration_open' => true,
        'start_datetime' => '2026-10-20', 'end_datetime' => '2026-10-21',
    ]);
    $delegation->users()->attach($approved->id, ['status' => 'approved']);
    $delegation->users()->attach($pending->id, ['status' => 'pending']);
    $delegation->users()->attach($rejected->id, ['status' => 'rejected']);

    // A rejected applicant sees their own status, the approved participant, nobody else.
    $users = collect($this->actingAs($rejected)->getJson('/api/delegations/open')->assertOk()->json('0.users'));
    expect($users->pluck('id')->sort()->values()->all())->toBe(collect([$approved->id, $rejected->id])->sort()->values()->all());
    $this->actingAs($rejected)->getJson('/api/delegations/open')->assertJsonPath('0.my_status', 'rejected');

    // Applying returns the delegation in the same shape: own pending row plus approved ones.
    $users = collect($this->actingAs($cast['employee'])->postJson("/api/delegations/{$delegation->id}/self-register")->assertOk()->json('users'));
    expect($users->pluck('id')->sort()->values()->all())->toBe(collect([$approved->id, $cast['employee']->id])->sort()->values()->all());
    $users = collect($this->actingAs($cast['employee'])->deleteJson("/api/delegations/{$delegation->id}/self-unregister")->assertOk()->json('users'));
    expect($users->pluck('id')->all())->toBe([$approved->id]);

    // Schedule overlay: a maintainer gets approved participants; admins every applicant.
    $mnt = collect($this->actingAs($cast['maintainer'])->getJson('/api/delegations/month?month=2026-10')->assertOk()->json('0.users'));
    expect($mnt->pluck('id')->all())->toBe([$approved->id]);
    $adm = collect($this->actingAs($cast['admin'])->getJson('/api/delegations/month?month=2026-10')->assertOk()->json('0.users'));
    expect($adm)->toHaveCount(3);
    $all = collect($this->actingAs($cast['admin'])->getJson('/api/delegations')->assertOk()->json('0.users'));
    expect($all->pluck('pivot.status')->sort()->values()->all())->toBe(['approved', 'pending', 'rejected']);
});
