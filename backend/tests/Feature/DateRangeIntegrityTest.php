<?php

use App\Models\Delegation;
use App\Models\NewbiePeriod;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// L11: an edit that sends one date is checked against the other date as stored (end >= start),
// members' own travel dates stay inside the delegation, and the database itself refuses a range
// that ends before it starts.

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->employee = User::factory()->create();
});

function driPeriod(): NewbiePeriod
{
    return NewbiePeriod::create([
        'user_id' => test()->employee->id, 'start_date' => '2026-10-10', 'end_date' => '2026-10-20',
        'created_by' => test()->admin->id,
    ]);
}

function driDelegation(): Delegation
{
    $delegation = Delegation::create([
        'name' => 'Fair', 'destination' => 'Berlin', 'start_datetime' => '2026-11-02', 'end_datetime' => '2026-11-06',
        'created_by' => test()->admin->id,
    ]);
    $delegation->users()->attach(test()->employee->id, ['status' => 'approved']);

    return $delegation;
}

it('refuses a newbie period edit that moves only the end before the stored start', function () {
    $period = driPeriod();

    $this->actingAs($this->admin)->putJson("/api/newbie-periods/{$period->id}", ['end_date' => '2026-10-05'])
        ->assertUnprocessable()->assertJsonValidationErrors('end_date');
    $this->actingAs($this->admin)->putJson("/api/newbie-periods/{$period->id}", ['start_date' => '2026-10-25'])
        ->assertUnprocessable()->assertJsonValidationErrors('end_date');

    expect($period->fresh()->end_date->toDateString())->toBe('2026-10-20');
});

it('accepts a newbie period edit that keeps the range valid', function () {
    $period = driPeriod();

    $this->actingAs($this->admin)->putJson("/api/newbie-periods/{$period->id}", ['end_date' => '2026-10-10'])->assertOk();
    $this->actingAs($this->admin)->putJson("/api/newbie-periods/{$period->id}", ['start_date' => '2026-10-01', 'end_date' => '2026-10-31'])->assertOk();

    expect($period->fresh()->start_date->toDateString())->toBe('2026-10-01')
        ->and($period->fresh()->end_date->toDateString())->toBe('2026-10-31');
});

it('keeps a delegation edit that moves only the end before the start refused (M15, already fixed)', function () {
    $delegation = driDelegation();

    $this->actingAs($this->admin)->putJson("/api/delegations/{$delegation->id}", ['end_datetime' => '2026-11-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('end_datetime');
});

it('refuses member travel dates outside the delegation or ending before they start', function (string $endpoint) {
    $delegation = driDelegation();
    $send = fn (array $dates) => $this->actingAs($this->admin)
        ->{$endpoint === 'add-user' ? 'postJson' : 'putJson'}("/api/delegations/{$delegation->id}/{$endpoint}", ['user_id' => $this->employee->id] + $dates);

    $send(['custom_start' => '2026-11-01', 'custom_end' => '2026-11-03'])->assertUnprocessable()->assertJsonValidationErrors('custom_start');
    $send(['custom_start' => '2026-11-03', 'custom_end' => '2026-11-07'])->assertUnprocessable()->assertJsonValidationErrors('custom_end');
    $send(['custom_start' => '2026-11-05', 'custom_end' => '2026-11-03'])->assertUnprocessable()->assertJsonValidationErrors('custom_end');

    $send(['custom_start' => '2026-11-03', 'custom_end' => '2026-11-06'])->assertOk();
    $pivot = $delegation->users()->where('users.id', $this->employee->id)->first()->pivot;
    expect([$pivot->custom_start, $pivot->custom_end])->toBe(['2026-11-03', '2026-11-06']);
})->with(['add-user', 'user-dates']);

it('checks a single new travel date against the member\'s other stored date', function () {
    $delegation = driDelegation();
    $delegation->users()->updateExistingPivot($this->employee->id, ['custom_start' => '2026-11-04', 'custom_end' => '2026-11-05']);

    // add-user leaves a date it isn't sent as it is: an end before the kept start is refused.
    $this->actingAs($this->admin)->postJson("/api/delegations/{$delegation->id}/add-user", [
        'user_id' => $this->employee->id, 'custom_end' => '2026-11-03',
    ])->assertUnprocessable()->assertJsonValidationErrors('custom_end');
});

it('lets the database refuse ranges that end before they start', function (string $table, array $row) {
    $row = array_map(fn ($v) => $v instanceof Closure ? $v() : $v, $row);

    expect(fn () => DB::transaction(fn () => DB::table($table)->insert($row)))
        ->toThrow(QueryException::class, 'check constraint');
})->with([
    'newbie period' => ['newbie_periods', [
        'user_id' => fn () => test()->employee->id, 'start_date' => '2026-10-10', 'end_date' => '2026-10-09',
        'created_by' => fn () => test()->admin->id,
    ]],
    'delegation' => ['delegations', [
        'name' => 'X', 'destination' => 'Y', 'start_datetime' => '2026-10-10', 'end_datetime' => '2026-10-09',
        'created_by' => fn () => test()->admin->id,
    ]],
    'availability' => ['user_availabilities', [
        'user_id' => fn () => test()->employee->id, 'type' => 'unavailable', 'start_date' => '2026-10-10', 'end_date' => '2026-10-09',
    ]],
]);

it('lets the database refuse member travel dates that end before they start', function () {
    $delegation = driDelegation();

    expect(fn () => DB::transaction(fn () => DB::table('delegation_user')
        ->where('delegation_id', $delegation->id)
        ->update(['custom_start' => '2026-11-05', 'custom_end' => '2026-11-04'])))
        ->toThrow(QueryException::class, 'check constraint');
});

it('swaps rows saved with the dates reversed instead of failing the migration', function () {
    $migration = require database_path('migrations/2026_10_10_130000_add_end_after_start_checks.php');
    $migration->down();
    $id = DB::table('newbie_periods')->insertGetId([
        'user_id' => $this->employee->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-10',
        'created_by' => $this->admin->id,
    ]);

    $migration->up();

    $row = DB::table('newbie_periods')->find($id);
    expect([$row->start_date, $row->end_date])->toBe(['2026-10-10', '2026-10-20']);
});
