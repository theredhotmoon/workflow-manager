<?php
// Schedule screen correctness: one shift payload shape (H7), atomic drag-moves (H9) and the reads a
// schedule maintainer needs (H12).

use App\Models\OfficeClosure;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftChangeRequest;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;
use App\Services\StreakTimeline;

beforeEach(function () {
    $this->maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $this->group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    // night 2 in a row → 2 days off.
    ShiftStreakRule::create(['shift_group_id' => $this->group->id, 'shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 2]);
    $this->employee = User::factory()->create(['role' => 'employee']);
});

function schShift($test, string $date, ?string $type = 'night', int $slot = 1, array $fields = []): Shift
{
    return Shift::firstOrCreate(
        ['date' => $date, 'shift_number' => $slot, 'shift_group_id' => $test->group->id],
        array_merge(['shift_type' => $type], $fields),
    );
}

function schAssign($test, string $date, ?string $type = 'night', ?string $policy = null, int $slot = 1): ShiftAssignment
{
    return ShiftAssignment::create([
        'shift_id' => schShift($test, $date, $type, $slot)->id,
        'user_id' => $test->employee->id,
        'rest_policy' => $policy,
    ]);
}

function schMove($test, ShiftAssignment $source, Shift $target, array $extra = [])
{
    return $test->actingAs($test->maintainer)->postJson('/api/schedule/assign', array_merge([
        'shift_id' => $target->id,
        'user_id' => $source->user_id,
        'move_from_assignment_id' => $source->id,
    ], $extra));
}

function schPendingRequest(ShiftAssignment $assignment): ShiftChangeRequest
{
    return ShiftChangeRequest::create([
        'shift_assignment_id' => $assignment->id,
        'user_id' => $assignment->user_id,
        'shift_id' => $assignment->shift_id,
        'reason' => 'Doctor',
        'status' => 'pending',
    ]);
}

function schEmployeeOn(Shift $shift, User $employee): bool
{
    return ShiftAssignment::where('shift_id', $shift->id)->where('user_id', $employee->id)->exists();
}

// --- H7: one payload shape --------------------------------------------------------------------

it('returns work_mode and the same keys from the month view and from a day-count edit', function () {
    $remote = schShift($this, '2029-03-05', 'day', 1, ['work_mode' => 'remote']);
    $assignment = ShiftAssignment::create(['shift_id' => $remote->id, 'user_id' => $this->employee->id]);
    schPendingRequest($assignment);

    $index = $this->actingAs($this->maintainer)
        ->getJson("/api/schedule?month=2029-03&shift_group_id={$this->group->id}")
        ->assertOk()->json('schedule.2029-03-05.0');

    $day = $this->actingAs($this->maintainer)->putJson('/api/schedule/day-shifts', [
        'date' => '2029-03-05', 'count' => 2, 'shift_group_id' => $this->group->id,
    ])->assertOk()->json('shifts');

    expect($index['work_mode'])->toBe('remote')
        ->and($day[0]['work_mode'])->toBe('remote')
        ->and($day[1]['work_mode'])->toBe('office');

    $keys = fn (array $a) => collect(array_keys($a))->sort()->values()->all();
    expect($keys($day[0]))->toBe($keys($index))
        ->and($keys($day[0]['assignments'][0]))->toBe($keys($index['assignments'][0]));
    // The day-off request chip survives a day edit.
    expect($day[0]['assignments'][0]['has_change_request'])->toBeTrue()
        ->and($day[0]['assignments'][0]['change_request_reason'])->toBe('Doctor');
});

it('lets "generate as remote on closed dates" find and switch the office shifts on a closed date', function () {
    // An admin, so this checks only the read path (the maintainer's access is covered below).
    $admin = User::factory()->create(['role' => 'admin']);
    OfficeClosure::create(['date' => '2029-03-12', 'title' => 'Moving day', 'created_by' => $admin->id]);
    $this->actingAs($admin)->postJson('/api/schedule/generate', [
        'month' => '2029-03', 'shift_group_id' => $this->group->id, 'shifts_per_day' => 2,
        'work_mode_weekday' => 'office', 'work_mode_weekend' => 'office',
    ])->assertOk();

    // The screen's flow: read the conflicts and the month, then switch every office shift on a closed date.
    $closed = collect($this->actingAs($admin)->getJson('/api/office-closures/conflicts?month=2029-03')
        ->assertOk()->json())->map(fn ($c) => substr($c['date'], 0, 10))->all();
    $schedule = $this->actingAs($admin)
        ->getJson("/api/schedule?month=2029-03&shift_group_id={$this->group->id}")->json('schedule');
    $switched = 0;
    foreach ($closed as $date) {
        foreach ($schedule[$date] as $shift) {
            if (($shift['work_mode'] ?? null) === 'office') {
                $this->actingAs($admin)
                    ->putJson("/api/schedule/shifts/{$shift['id']}/work-mode", ['work_mode' => 'remote'])->assertOk();
                $switched++;
            }
        }
    }

    expect($switched)->toBe(2);
    $after = $this->actingAs($admin)
        ->getJson("/api/schedule?month=2029-03&shift_group_id={$this->group->id}")->json('schedule');
    expect(collect($after['2029-03-12'])->pluck('work_mode')->all())->toBe(['remote', 'remote'])
        ->and(collect($after['2029-03-13'])->pluck('work_mode')->all())->toBe(['office', 'office']);
});

// --- H9: a drag-move is one request in one transaction -----------------------------------------

it('moves an assignment in one request: the source is gone and the target created', function () {
    $source = schAssign($this, '2029-04-01', 'day');
    $target = schShift($this, '2029-04-10', 'day');

    schMove($this, $source, $target)->assertCreated()->assertJson([
        'shift_id' => $target->id, 'user_id' => $this->employee->id, 'moved_from_assignment_id' => $source->id,
    ]);

    expect(ShiftAssignment::find($source->id))->toBeNull()
        ->and(schEmployeeOn($target, $this->employee))->toBeTrue()
        ->and(ShiftAssignment::where('user_id', $this->employee->id)->count())->toBe(1);
});

it('leaves the source untouched when the move hits a rest day (rest_window_conflict)', function () {
    schAssign($this, '2029-04-01');
    schAssign($this, '2029-04-02'); // rest 04-03..04-04
    $source = schAssign($this, '2029-04-20', 'day');
    $request = schPendingRequest($source);
    $target = schShift($this, '2029-04-03');

    schMove($this, $source, $target)->assertStatus(409)->assertJson(['code' => 'rest_window_conflict']);

    expect(ShiftAssignment::find($source->id))->not->toBeNull()
        ->and(schEmployeeOn($target, $this->employee))->toBeFalse()
        ->and($request->fresh()->status)->toBe('pending');

    // Answering the question completes the move.
    schMove($this, $source, $target, ['rest_policy' => StreakTimeline::NOT_COUNTED])->assertCreated();
    expect(ShiftAssignment::find($source->id))->toBeNull()
        ->and(schEmployeeOn($target, $this->employee))->toBeTrue();
});

it('leaves the source untouched when the move would put a later shift into rest (creates_rest_conflict)', function () {
    schAssign($this, '2029-04-01');
    $later = schAssign($this, '2029-04-04');
    $source = schAssign($this, '2029-04-20', 'day');
    $target = schShift($this, '2029-04-02'); // 04-01 + 04-02 → rest 04-03..04-04 → the 04-04 night

    schMove($this, $source, $target)->assertStatus(409)->assertJson([
        'code' => 'creates_rest_conflict',
        'affected' => [['assignment_id' => $later->id]],
    ]);

    expect(ShiftAssignment::find($source->id))->not->toBeNull()
        ->and(schEmployeeOn($target, $this->employee))->toBeFalse()
        ->and($later->fresh()->rest_policy)->toBeNull();
});

it('rolls back the side effects of removing the source when the move is refused', function () {
    // Nights 01-01, 01-02 → rest 01-03..01-04, with a not_counted day shift on 01-03.
    schAssign($this, '2029-01-01');
    $source = schAssign($this, '2029-01-02');
    $stamped = schAssign($this, '2029-01-03', 'day', StreakTimeline::NOT_COUNTED, 2);
    // A second window: nights 01-10, 01-11 → rest 01-12..01-13.
    schAssign($this, '2029-01-10');
    schAssign($this, '2029-01-11');
    $target = schShift($this, '2029-01-12');

    // Removing 01-02 alone would clear 01-03's policy; the refused move must keep it.
    schMove($this, $source, $target)->assertStatus(409)->assertJson(['code' => 'rest_window_conflict']);

    expect(ShiftAssignment::find($source->id))->not->toBeNull()
        ->and($stamped->fresh()->rest_policy)->toBe(StreakTimeline::NOT_COUNTED);
});

it('applies the unassign side effects to the source of a successful move', function () {
    schAssign($this, '2029-01-01');
    $source = schAssign($this, '2029-01-02');
    $stamped = schAssign($this, '2029-01-03', 'day', StreakTimeline::NOT_COUNTED, 2);
    $request = schPendingRequest($source);
    // The target is in another group, so only the source removal's janitor can clear 01-03.
    $other = ShiftGroup::create(['name' => 'Other', 'is_default' => false, 'sort_order' => 1]);
    $target = Shift::create(['date' => '2029-01-20', 'shift_number' => 1, 'shift_group_id' => $other->id, 'shift_type' => 'day']);

    schMove($this, $source, $target)->assertCreated();

    // As after DELETE /schedule/unassign: the pending request no longer stands (the FK cascades it
    // away after the acknowledgement) and 01-03, out of rest now, loses its policy.
    expect(ShiftChangeRequest::where('id', $request->id)->where('status', 'pending')->exists())->toBeFalse()
        ->and($stamped->fresh()->rest_policy)->toBeNull();
});

it('rejects a move whose source is not the assigned user\'s assignment', function (string $case) {
    $other = User::factory()->create(['role' => 'employee']);
    $othersAssignment = ShiftAssignment::create(['shift_id' => schShift($this, '2029-04-01', 'day')->id, 'user_id' => $other->id]);
    $own = schAssign($this, '2029-04-02', 'day');
    $target = schShift($this, '2029-04-10', 'day');

    $sourceId = match ($case) {
        'another user' => $othersAssignment->id,
        'unknown id' => 999999,
        'same shift' => $own->id,
    };
    $shiftId = $case === 'same shift' ? $own->shift_id : $target->id;

    $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $shiftId, 'user_id' => $this->employee->id, 'move_from_assignment_id' => $sourceId,
    ])->assertStatus(422)->assertJsonValidationErrors(['move_from_assignment_id']);

    expect(ShiftAssignment::find($othersAssignment->id))->not->toBeNull()
        ->and(ShiftAssignment::find($own->id))->not->toBeNull()
        ->and(schEmployeeOn($target, $this->employee))->toBeFalse();
})->with(['another user', 'unknown id', 'same shift']);

// --- H12: reads the Schedule screen needs --------------------------------------------------------

dataset('schedule screen reads', [
    'month delegations' => ['/api/delegations/month?month=2029-05', 'post', '/api/delegations'],
    'month newbie periods' => ['/api/newbie-periods/month?month=2029-05', 'post', '/api/newbie-periods'],
    'office closures' => ['/api/office-closures?month=2029-05', 'post', '/api/office-closures'],
    'closure conflicts' => ['/api/office-closures/conflicts?month=2029-05', 'post', '/api/office-closures'],
    'shift colours' => ['/api/shift-colors', 'post', '/api/shift-colors'],
    'checklist templates' => ['/api/checklist-templates', 'post', '/api/checklist-templates'],
]);

it('lets a schedule maintainer read it while the matching write stays admin-only', function (string $read, string $method, string $write) {
    // A JSON list, not the SPA catch-all page (web.php answers any unknown GET with 200 HTML).
    expect($this->actingAs($this->maintainer)->getJson($read)->assertOk()->json())->toBeArray();
    $this->actingAs($this->maintainer)->json(strtoupper($method), $write, [])->assertForbidden();
})->with('schedule screen reads');

it('shows shift colours to an employee but nothing else of the manager reads', function (string $read) {
    $response = $this->actingAs($this->employee)->getJson($read);
    if (str_starts_with($read, '/api/shift-colors')) {
        expect($response->assertOk()->json())->toBeArray();
    } else {
        $response->assertForbidden();
    }
})->with([
    '/api/shift-colors',
    '/api/delegations/month?month=2029-05',
    '/api/newbie-periods/month?month=2029-05',
    '/api/office-closures?month=2029-05',
    '/api/office-closures/conflicts?month=2029-05',
    '/api/checklist-templates',
]);
