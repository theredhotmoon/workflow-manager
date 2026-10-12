<?php

use App\Models\Delegation;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftChangeRequest;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// L8: when two identical requests race, the loser's insert hits a unique constraint. That answers
// 422 with the same message the up-front check gives, never a 500; and one pending change request
// per assignment is enforced by the database, not only by the controller's check.
// A race is staged by inserting the competing row right after the controller's check has run.

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
    $this->group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
});

/** Runs $insert once, right after the first query matching $pattern has run. */
function urAfterQuery(string $pattern, callable $insert): void
{
    $done = false;
    DB::listen(function ($query) use ($pattern, $insert, &$done) {
        if (!$done && preg_match($pattern, $query->sql)) {
            $done = true;
            $insert();
        }
    });
}

it('answers 422, not 500, when a concurrent assign of the same person wins the race', function () {
    $manager = User::factory()->create(['role' => 'admin']);
    $employee = User::factory()->create();
    $shift = Shift::create(['date' => '2026-10-20', 'shift_number' => 1, 'shift_group_id' => $this->group->id]);

    // The competing request inserts its assignment just after this one checked there was none.
    urAfterQuery('/from "shift_assignments" where "shift_id" = \? and "user_id" = \?/', fn () => DB::table('shift_assignments')->insert([
        'shift_id' => $shift->id, 'user_id' => $employee->id, 'created_at' => now(), 'updated_at' => now(),
    ]));

    $this->actingAs($manager)
        ->postJson('/api/schedule/assign', ['shift_id' => $shift->id, 'user_id' => $employee->id])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'User is already assigned to this shift.');

    expect(ShiftAssignment::where('shift_id', $shift->id)->count())->toBe(1);
});

it('answers 422, not 500, when a concurrent self-registration wins the race', function () {
    $employee = User::factory()->create();
    $delegation = Delegation::create([
        'name' => 'Fair', 'destination' => 'Berlin', 'start_datetime' => '2026-11-02', 'end_datetime' => '2026-11-04',
        'registration_open' => true, 'created_by' => User::factory()->create(['role' => 'admin'])->id,
    ]);

    urAfterQuery('/from "users" inner join "delegation_user"/', fn () => DB::table('delegation_user')->insert([
        'delegation_id' => $delegation->id, 'user_id' => $employee->id, 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $this->actingAs($employee)
        ->postJson("/api/delegations/{$delegation->id}/self-register")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You have already applied for this delegation.');

    expect(DB::table('delegation_user')->where('delegation_id', $delegation->id)->count())->toBe(1);
});

it('lets the database refuse a second pending change request for one assignment', function () {
    $employee = User::factory()->create();
    $shift = Shift::create(['date' => '2026-10-20', 'shift_number' => 1, 'shift_group_id' => $this->group->id]);
    $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);
    $row = fn (string $status) => [
        'shift_assignment_id' => $assignment->id, 'user_id' => $employee->id, 'shift_id' => $shift->id,
        'reason' => 'Doctor', 'status' => $status,
    ];

    ShiftChangeRequest::create($row('pending'));
    // Settled ones don't count: any number of acknowledged requests may sit next to one pending.
    ShiftChangeRequest::create($row('acknowledged'));
    ShiftChangeRequest::create($row('acknowledged'));

    expect(fn () => DB::transaction(fn () => ShiftChangeRequest::create($row('pending'))))
        ->toThrow(QueryException::class, 'shift_change_requests_one_pending_per_assignment');
});

it('settles older duplicate pending requests instead of failing the migration', function () {
    $migration = require database_path('migrations/2026_10_10_120000_one_pending_change_request_per_assignment.php');
    $migration->down();

    $employee = User::factory()->create();
    $shift = Shift::create(['date' => '2026-10-20', 'shift_number' => 1, 'shift_group_id' => $this->group->id]);
    $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);
    $other = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => User::factory()->create()->id]);
    $file = fn (ShiftAssignment $a, string $reason) => ShiftChangeRequest::create([
        'shift_assignment_id' => $a->id, 'user_id' => $a->user_id, 'shift_id' => $shift->id,
        'reason' => $reason, 'status' => 'pending',
    ]);
    $oldest = $file($assignment, 'First');
    $middle = $file($assignment, 'Second');
    $newest = $file($assignment, 'Third');
    $alone = $file($other, 'Only one');

    $migration->up();

    expect($oldest->fresh()->status)->toBe('acknowledged')
        ->and($middle->fresh()->status)->toBe('acknowledged')
        ->and($newest->fresh()->status)->toBe('pending')
        ->and($alone->fresh()->status)->toBe('pending')
        ->and(ShiftChangeRequest::count())->toBe(4);
    expect(fn () => DB::transaction(fn () => $file($assignment, 'Fourth')))
        ->toThrow(QueryException::class, 'shift_change_requests_one_pending_per_assignment');
});

it('answers 422 when a concurrent pending change request wins the race', function () {
    $employee = User::factory()->create();
    $shift = Shift::create(['date' => '2026-10-20', 'shift_number' => 1, 'shift_group_id' => $this->group->id]);
    $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);

    urAfterQuery('/from "shift_change_requests" where "shift_assignment_id" = \? and "status" = \?/', fn () => DB::table('shift_change_requests')->insert([
        'shift_assignment_id' => $assignment->id, 'user_id' => $employee->id, 'shift_id' => $shift->id,
        'shift_date' => '2026-10-20', 'shift_number' => 1, 'reason' => 'Other tab', 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $this->actingAs($employee)
        ->postJson('/api/shift-change-requests', ['shift_assignment_id' => $assignment->id, 'reason' => 'Doctor'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You already have a pending request for this shift.');

    // This request's own row was not kept. (The staged competitor ran inside the same transaction,
    // so it was rolled back with it; in production it commits on its own.)
    expect(ShiftChangeRequest::where('reason', 'Doctor')->count())->toBe(0);
});

it('counts only the slots generate created itself when a concurrent run created one first', function () {
    $manager = User::factory()->create(['role' => 'admin']);

    // The other run creates the 1st's first slot after this run counted the 1st's slots.
    urAfterQuery('/select count\(\*\) as "aggregate" from "shifts" where "date" = \?/', fn () => DB::table('shifts')->insert([
        'date' => '2026-11-01', 'shift_number' => 1, 'shift_group_id' => $this->group->id,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $this->actingAs($manager)
        ->postJson('/api/schedule/generate', ['month' => '2026-11', 'shift_group_id' => $this->group->id, 'shifts_per_day' => 1])
        ->assertOk()
        ->assertJsonPath('created', 29);

    expect(Shift::count())->toBe(30);
});

it('answers 422, not 500, when a user with the same email is created at the same moment', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    urAfterQuery('/select count\(\*\) as "aggregate" from "users" where "email"/', fn () => DB::table('users')->insert([
        'name' => 'Other tab', 'email' => 'new.person@example.com', 'password' => 'x', 'role' => 'employee',
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $this->actingAs($admin)->postJson('/api/users', [
        'name' => 'New Person', 'email' => 'new.person@example.com', 'password' => 'secret-password',
        'password_confirmation' => 'secret-password', 'role' => 'employee',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});
