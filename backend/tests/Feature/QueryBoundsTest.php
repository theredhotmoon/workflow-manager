<?php

use App\Models\ArchivedDelegation;
use App\Models\Delegation;
use App\Models\Procedure;
use App\Models\ProcedureAccessLog;
use App\Models\ProcedureFeedback;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// L13: list endpoints run a fixed number of queries however many rows they return, and the
// unbounded admin lists (procedure feedback, delegation archive) are paginated.

function qbCount(callable $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function qbProcedures(User $owner, int $count, int $from = 1): void
{
    foreach (range($from, $from + $count - 1) as $n) {
        $procedure = Procedure::create(['identifier' => sprintf('PROC-%02d', $n), 'title' => "Procedure {$n}", 'owner_id' => $owner->id]);
        ProcedureAccessLog::create(['procedure_id' => $procedure->id, 'user_id' => $owner->id, 'accessed_at' => now()->subDays($n)]);
    }
}

it('lists procedures in a fixed number of queries, with each one\'s last access', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    qbProcedures($admin, 2);
    $few = qbCount(fn () => $this->actingAs($admin)->getJson('/api/procedures')->assertOk());

    qbProcedures($admin, 10, 3);
    $many = qbCount(fn () => $this->actingAs($admin)->getJson('/api/procedures')->assertOk());

    expect($many)->toBe($few);

    // The newest of the viewer's accesses, as a timestamp; someone else's access doesn't count.
    $procedure = Procedure::where('identifier', 'PROC-01')->sole();
    ProcedureAccessLog::create(['procedure_id' => $procedure->id, 'user_id' => User::factory()->create()->id, 'accessed_at' => now()]);
    $row = collect($this->actingAs($admin)->getJson('/api/procedures?search=PROC-01')->json('data'))->sole();
    expect($row['last_accessed'])->toBe($procedure->accessLogs()->where('user_id', $admin->id)->value('accessed_at')->toJSON());
});

it('lists my delegations in a fixed number of queries', function () {
    $employee = User::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);
    $make = function (int $n) use ($employee, $admin) {
        foreach (range(1, $n) as $i) {
            $delegation = Delegation::create([
                'name' => "Trip {$i}", 'destination' => 'Berlin', 'start_datetime' => '2026-11-02', 'end_datetime' => '2026-11-04',
                'created_by' => $admin->id,
            ]);
            $delegation->users()->attach($employee->id, ['status' => 'approved', 'custom_start' => '2026-11-03']);
            $delegation->users()->attach(User::factory()->create()->id, ['status' => 'pending']);
        }
    };

    $make(1);
    $few = qbCount(fn () => $this->actingAs($employee)->getJson('/api/my-delegations')->assertOk());
    $make(6);
    $many = qbCount(fn () => $this->actingAs($employee)->getJson('/api/my-delegations')->assertOk());

    expect($many)->toBe($few);
    $row = $this->actingAs($employee)->getJson('/api/my-delegations')->json('0');
    expect($row['my_status'])->toBe('approved')
        ->and($row['my_custom_start'])->toBe('2026-11-03');
});

it('lists my shifts in a fixed number of queries', function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
    $employee = User::factory()->create();
    $make = function (int $from, int $to) use ($employee) {
        foreach (range($from, $to) as $day) {
            $group = ShiftGroup::create(['name' => "Group {$day}", 'sort_order' => $day]);
            $shift = Shift::create(['date' => sprintf('2026-10-%02d', $day), 'shift_number' => 1, 'shift_group_id' => $group->id]);
            ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);
        }
    };

    $make(1, 2);
    $few = qbCount(fn () => $this->actingAs($employee)->getJson('/api/my-shifts?month=2026-10')->assertOk());
    $make(3, 12);
    $many = qbCount(fn () => $this->actingAs($employee)->getJson('/api/my-shifts?month=2026-10')->assertOk());

    expect($many)->toBe($few);
    expect($this->actingAs($employee)->getJson('/api/my-shifts?month=2026-10')->json('2026-10-05.0.shift_group_name'))->toBe('Group 5');
});

it('lists procedures to learn without loading their content', function () {
    $owner = User::factory()->create();
    $reader = User::factory()->create();
    Procedure::create(['identifier' => 'PROC-01', 'title' => 'Big one', 'content' => str_repeat('<p>text</p>', 50), 'owner_id' => $owner->id, 'version' => 2]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $rows = $this->actingAs($reader)->getJson('/api/procedures-to-learn')->assertOk()->json();
    $sql = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => str_contains($q, 'procedure_user_reads'));
    DB::disableQueryLog();

    expect($sql)->not->toContain('"procedures".*')->and($sql)->not->toContain('"content"')
        ->and($rows[0])->toMatchArray(['identifier' => 'PROC-01', 'title' => 'Big one', 'version' => 2])
        ->and($rows[0]['owner']['id'])->toBe($owner->id);
});

it('paginates the procedure feedback list', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $procedure = Procedure::create(['identifier' => 'PROC-01', 'title' => 'P', 'owner_id' => $admin->id]);
    foreach (range(1, 5) as $i) {
        ProcedureFeedback::create(['procedure_id' => $procedure->id, 'user_id' => $admin->id, 'description' => "Note {$i}", 'status' => $i <= 3 ? 'open' : 'resolved']);
    }

    $this->actingAs($admin)->getJson('/api/procedure-feedbacks?per_page=2&status=open')->assertOk()
        ->assertJsonPath('total', 3)
        ->assertJsonPath('per_page', 2)
        ->assertJsonCount(2, 'data');
    $this->actingAs($admin)->getJson('/api/procedure-feedbacks?per_page=-1')->assertOk()->assertJsonPath('per_page', 1);
    $this->actingAs($admin)->getJson('/api/procedure-feedbacks?per_page=5000')->assertOk()->assertJsonPath('per_page', 100);
});

it('paginates the delegation archive', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    foreach (range(1, 3) as $i) {
        ArchivedDelegation::forceCreate([
            'original_id' => $i, 'name' => "Trip {$i}", 'destination' => 'Berlin',
            'start_datetime' => '2026-01-10', 'end_datetime' => '2026-01-12', 'registration_open' => false,
            'created_by' => $admin->id, 'archived_by' => $admin->id, 'archived_at' => now()->subDays($i),
        ]);
    }

    $this->actingAs($admin)->getJson('/api/delegation-archive?per_page=2')->assertOk()
        ->assertJsonPath('total', 3)
        ->assertJsonPath('data.0.name', 'Trip 1')
        ->assertJsonCount(2, 'data');
    $this->actingAs($admin)->getJson('/api/delegation-archive?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($admin)->getJson('/api/delegation-archive?per_page=0')->assertOk()->assertJsonPath('per_page', 1);
});
