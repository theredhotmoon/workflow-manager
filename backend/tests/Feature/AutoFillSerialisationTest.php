<?php

use App\Http\Controllers\ShiftAutoAssignController;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\User;
use App\Support\AdvisoryLock;
use Illuminate\Support\Facades\DB;

// F4: two auto-fill runs (or a run and a clear) for the same group and month can't both commit on
// the same starting state: each takes the group+month advisory lock before it reads anything, so
// the second waits for the first and then sees its assignments. (The group row lock from PR #38
// is FOR SHARE: it keeps an archive out, but two runs both get it.)

beforeEach(function () {
    $this->group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $this->employee = User::factory()->create();
    $this->maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    Shift::create(['date' => '2026-11-02', 'shift_number' => 1, 'shift_group_id' => $this->group->id, 'start_time' => '08:00', 'end_time' => '16:00']);
});

function afsRun(string $endpoint, ShiftGroup $group, array $extra = [])
{
    $method = $endpoint === '/api/schedule/clear-month' ? 'deleteJson' : 'postJson';

    return test()->actingAs(test()->maintainer)->{$method}($endpoint, array_merge([
        'month' => '2026-11', 'shift_group_id' => $group->id,
        'users_per_shift_weekday' => 1, 'users_per_shift_weekend' => 1, 'rotation_days' => 7,
        'player_mode' => 'include', 'player_ids' => [test()->employee->id],
    ], $extra));
}

it('waits for a concurrent run of the same group and month', function (string $endpoint) {
    $side = sideConnection();
    $side->beginTransaction();
    $side->select('SELECT pg_advisory_xact_lock(?)', [AdvisoryLock::key(ShiftAutoAssignController::monthLockName($this->group->id, '2026-11'))]);

    try {
        DB::statement("SET LOCAL lock_timeout = '300ms'");
        afsRun($endpoint, $this->group)->assertServerError();
        expect(ShiftAssignment::count())->toBe(0);
    } finally {
        $side->rollBack();
        DB::statement('SET LOCAL lock_timeout = 0');
    }

    afsRun($endpoint, $this->group)->assertOk();
})->with(['/api/schedule/auto-assign/random', '/api/schedule/auto-assign/rotating', '/api/schedule/auto-assign/polish-law', '/api/schedule/clear-month']);

it('does not wait for a run of another month or group', function () {
    $other = ShiftGroup::create(['name' => 'Other', 'sort_order' => 1]);
    $side = sideConnection();
    $side->beginTransaction();
    $side->select('SELECT pg_advisory_xact_lock(?)', [AdvisoryLock::key(ShiftAutoAssignController::monthLockName($this->group->id, '2026-12'))]);
    $side->select('SELECT pg_advisory_xact_lock(?)', [AdvisoryLock::key(ShiftAutoAssignController::monthLockName($other->id, '2026-11'))]);

    try {
        DB::statement("SET LOCAL lock_timeout = '300ms'");
        afsRun('/api/schedule/auto-assign/random', $this->group)->assertOk();
        expect(ShiftAssignment::count())->toBe(1);
    } finally {
        $side->rollBack();
        DB::statement('SET LOCAL lock_timeout = 0');
    }
});

it('locks the default group\'s month when no group is sent', function () {
    // The default group the run falls back to (the migrations create one).
    $defaultId = ShiftGroup::where('is_default', true)->value('id');
    $side = sideConnection();
    $side->beginTransaction();
    $side->select('SELECT pg_advisory_xact_lock(?)', [AdvisoryLock::key(ShiftAutoAssignController::monthLockName($defaultId, '2026-11'))]);

    try {
        DB::statement("SET LOCAL lock_timeout = '300ms'");
        test()->actingAs($this->maintainer)->postJson('/api/schedule/auto-assign/random', [
            'month' => '2026-11', 'users_per_shift_weekday' => 1, 'users_per_shift_weekend' => 1,
            'player_mode' => 'include', 'player_ids' => [$this->employee->id],
        ])->assertServerError();
    } finally {
        $side->rollBack();
        DB::statement('SET LOCAL lock_timeout = 0');
    }
});
