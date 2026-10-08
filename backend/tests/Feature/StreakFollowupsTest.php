<?php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;
use App\Services\StreakTimeline;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $this->group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    // night 2 in a row → 2 days off.
    ShiftStreakRule::create(['shift_group_id' => $this->group->id, 'shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 2]);
    $this->employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
});

function sfuShift($test, string $date, ?string $type = 'night', int $slot = 1): Shift
{
    return Shift::firstOrCreate(
        ['date' => $date, 'shift_number' => $slot, 'shift_group_id' => $test->group->id],
        ['shift_type' => $type],
    );
}

function sfuAssign($test, string $date, ?string $type = 'night', ?string $policy = null, int $slot = 1): ShiftAssignment
{
    return ShiftAssignment::create([
        'shift_id' => sfuShift($test, $date, $type, $slot)->id,
        'user_id' => $test->employee->id,
        'rest_policy' => $policy,
    ]);
}

/** Nights 01-01 and 01-02 → rest 01-03..01-04; a not_counted day shift on the rest day 01-03. */
function sfuStampedRestDay($test, int $streakSlot = 1): array
{
    $first = sfuAssign($test, '2029-01-01', 'night', null, $streakSlot);
    $second = sfuAssign($test, '2029-01-02', 'night', null, $streakSlot);
    $stamped = sfuAssign($test, '2029-01-03', 'day', StreakTimeline::NOT_COUNTED, 2);

    return [$first, $second, $stamped];
}

it('unassign clears the policy of a shift it takes out of rest', function () {
    [, $second, $stamped] = sfuStampedRestDay($this);

    $this->actingAs($this->maintainer)->deleteJson("/api/schedule/unassign/{$second->id}")->assertOk();

    expect($stamped->fresh()->rest_policy)->toBeNull();
});

it('keeps the policy of a shift that is still on a rest day', function () {
    [, , $stamped] = sfuStampedRestDay($this);
    $unrelated = sfuAssign($this, '2029-01-10', 'day');

    $this->actingAs($this->maintainer)->deleteJson("/api/schedule/unassign/{$unrelated->id}")->assertOk();

    expect($stamped->fresh()->rest_policy)->toBe(StreakTimeline::NOT_COUNTED);
});

it('assign clears the policy of a later shift its streak shift takes out of rest', function () {
    // Nights 01-02, 01-03 → rest 01-04..01-05; 01-05 night stamped not_counted.
    sfuAssign($this, '2029-01-02');
    sfuAssign($this, '2029-01-03');
    $stamped = sfuAssign($this, '2029-01-05', 'night', StreakTimeline::NOT_COUNTED);
    // A night on 01-01 moves the window to 01-03..01-04: 01-03 is now a rest day, 01-05 no longer is.
    $shift = sfuShift($this, '2029-01-01');

    $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $shift->id, 'user_id' => $this->employee->id, 'later_rest_policy' => StreakTimeline::NOT_COUNTED,
    ])->assertCreated();

    expect($stamped->fresh()->rest_policy)->toBeNull();
});

it('retyping one shift clears the policy of a shift it takes out of rest', function () {
    [, $second, $stamped] = sfuStampedRestDay($this);

    $this->actingAs($this->maintainer)->putJson("/api/schedule/shifts/{$second->shift_id}/type", ['shift_type' => 'day'])->assertOk();

    expect($stamped->fresh()->rest_policy)->toBeNull();
});

it('retyping a slot across the month clears the policy of a shift it takes out of rest', function () {
    [, , $stamped] = sfuStampedRestDay($this);

    $this->actingAs($this->maintainer)->putJson('/api/schedule/shift-types', [
        'shift_group_id' => $this->group->id, 'month' => '2029-01', 'shift_number' => 1, 'shift_type' => 'day',
    ])->assertOk();

    expect($stamped->fresh()->rest_policy)->toBeNull();
});

it('clearing a month clears the policy of a later shift it takes out of rest', function () {
    sfuAssign($this, '2029-01-30');
    sfuAssign($this, '2029-01-31');
    $stamped = sfuAssign($this, '2029-02-01', 'day', StreakTimeline::NOT_COUNTED);

    $this->actingAs($this->maintainer)->deleteJson('/api/schedule/clear-month', ['month' => '2029-01', 'shift_group_id' => $this->group->id])->assertOk();

    expect($stamped->fresh()->rest_policy)->toBeNull();
});

it('editing the streak rules clears the policies of shifts no longer on a rest day', function () {
    [, , $stamped] = sfuStampedRestDay($this);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->putJson("/api/shift-groups/{$this->group->id}/streak-rules", [
        'rules' => [['shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]],
    ])->assertOk();

    expect($stamped->fresh()->rest_policy)->toBeNull();
});

it('removing every streak rule clears every policy in the group, and only in that group', function () {
    [, , $stamped] = sfuStampedRestDay($this);
    $admin = User::factory()->create(['role' => 'admin']);
    // The same employee's answer in another group (no rules there either) is not this edit's business.
    $otherGroup = ShiftGroup::create(['name' => 'Other', 'sort_order' => 1]);
    $otherShift = Shift::create(['date' => '2029-01-03', 'shift_number' => 1, 'shift_group_id' => $otherGroup->id, 'shift_type' => 'day']);
    $elsewhere = ShiftAssignment::create(['shift_id' => $otherShift->id, 'user_id' => $this->employee->id, 'rest_policy' => StreakTimeline::COUNTS]);

    $this->actingAs($admin)->putJson("/api/shift-groups/{$this->group->id}/streak-rules", ['rules' => []])->assertOk();

    expect($stamped->fresh()->rest_policy)->toBeNull();
    expect($elsewhere->fresh()->rest_policy)->toBe(StreakTimeline::COUNTS);
});

it('removing a day\'s slot clears the policy of a shift it takes out of rest', function () {
    // The streak's second night is slot 2 of 01-02; dropping that day to one slot deletes it.
    sfuAssign($this, '2029-01-01');
    sfuShift($this, '2029-01-02', 'day', 1);
    sfuAssign($this, '2029-01-02', 'night', null, 2);
    $stamped = sfuAssign($this, '2029-01-03', 'day', StreakTimeline::NOT_COUNTED, 2);

    $this->actingAs($this->maintainer)->putJson('/api/schedule/day-shifts', [
        'date' => '2029-01-02', 'count' => 1, 'shift_group_id' => $this->group->id,
    ])->assertOk();

    expect($stamped->fresh()->rest_policy)->toBeNull();
});

it('needs one pass: clearing uncovered policies never uncovers another one', function () {
    // Clearing only touches events outside every window, and the replay reads a policy only inside one.
    mt_srand(20261008);
    $rules = collect([
        new ShiftStreakRule(['shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 3]),
        new ShiftStreakRule(['shift_type' => 'day', 'streak_length' => 3, 'rest_days' => 1]),
    ])->keyBy('shift_type');
    $types = ['night', 'day', null];
    for ($run = 0; $run < 200; $run++) {
        $events = [];
        $day = Carbon::parse('2029-01-01');
        for ($i = 0; $i < 40; $i++) {
            $day->addDays(mt_rand(0, 2));
            $events[] = [
                'assignment_id' => $i + 1,
                'date' => $day->toDateString(),
                'shift_number' => mt_rand(1, 2),
                'shift_type' => $types[mt_rand(0, 2)],
                'rest_policy' => mt_rand(0, 1) ? StreakTimeline::POLICIES[mt_rand(0, 2)] : null,
            ];
        }
        $before = new StreakTimeline($rules, [1 => $events]);
        $cleared = array_flip($before->uncoveredWithPolicy(1));
        foreach ($events as $k => $event) {
            if (isset($cleared[$event['assignment_id']])) {
                $events[$k]['rest_policy'] = null;
            }
        }
        $after = new StreakTimeline($rules, [1 => $events]);

        expect($after->uncoveredWithPolicy(1))->toBe([]);
        expect($after->restingAssignments(1))->toBe($before->restingAssignments(1));
    }
});

it('reports the final end of the rest window in the rest-day 409, including later extensions', function () {
    // Nights 01-01, 01-02 → rest 01-03..01-04; an extends_rest shift on 01-04 lengthens it to 01-06.
    sfuAssign($this, '2029-01-01');
    sfuAssign($this, '2029-01-02');
    sfuAssign($this, '2029-01-04', 'day', StreakTimeline::EXTENDS_REST);
    $shift = sfuShift($this, '2029-01-03', 'day');

    $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $shift->id, 'user_id' => $this->employee->id,
    ])->assertStatus(409)->assertJson([
        'code' => 'rest_window_conflict',
        'resting_until' => '2029-01-06',
        'rule' => ['shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 2],
    ]);
});

it('auto-fill clears the policy of a later shift whose window its new shift dissolves', function (string $endpoint, array $extra) {
    // Rule night 2 -> 1. Worked nights 01-05 and 01-10 complete a streak → rest 01-11, where a day
    // shift is stamped not_counted.
    $this->group->update(['settlement_mode' => 'monthly', 'settlement_anchor_date' => '2029-01-01', 'weekly_hour_cap' => 168]);
    ShiftStreakRule::where('shift_group_id', $this->group->id)->update(['rest_days' => 1]);
    $times = ['night' => ['22:00', '06:00'], 'day' => ['08:00', '16:00']];
    $make = function (string $date, string $type) use ($times) {
        return Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $this->group->id, 'shift_type' => $type,
            'start_time' => $times[$type][0], 'end_time' => $times[$type][1]]);
    };
    $make('2029-01-03', 'night'); // empty: auto-fill assigns it
    foreach (['2029-01-05' => 'night', '2029-01-10' => 'night'] as $date => $type) {
        ShiftAssignment::create(['shift_id' => $make($date, $type)->id, 'user_id' => $this->employee->id]);
    }
    $stamped = ShiftAssignment::create(['shift_id' => $make('2029-01-11', 'day')->id, 'user_id' => $this->employee->id, 'rest_policy' => StreakTimeline::NOT_COUNTED]);

    // Night 01-03 puts nothing new into rest (01-03 + 01-05 → rest 01-06, an empty day), but 01-10 now starts a
    // new count, so the 01-11 window is gone and its answer no longer applies.
    $this->actingAs($this->maintainer)->postJson($endpoint, array_merge([
        'month' => '2029-01', 'shift_group_id' => $this->group->id,
        'users_per_shift_weekday' => 1, 'users_per_shift_weekend' => 1,
        'player_mode' => 'include', 'player_ids' => [$this->employee->id],
    ], $extra))->assertOk();

    expect(ShiftAssignment::where('user_id', $this->employee->id)->whereHas('shift', fn ($q) => $q->where('date', '2029-01-03'))->exists())->toBeTrue();
    expect($stamped->fresh()->rest_policy)->toBeNull();
})->with([
    'random' => ['/api/schedule/auto-assign/random', []],
    'rotating' => ['/api/schedule/auto-assign/rotating', ['rotation_days' => 7]],
    'polish-law' => ['/api/schedule/auto-assign/polish-law', ['rotation_days' => 7]],
]);
