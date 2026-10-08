<?php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;

beforeEach(function () {
    $this->maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $this->group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $this->group->id, 'shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 2]);
    $this->employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    $this->shifts = [];
    foreach (['2029-01-02', '2029-01-03', '2029-01-04', '2029-01-05'] as $date) {
        $this->shifts[$date] = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $this->group->id, 'shift_type' => 'night']);
    }
    // Existing: nights 01-02 and 01-05 (not a streak).
    $this->existing = [];
    foreach (['2029-01-02', '2029-01-05'] as $date) {
        $this->existing[$date] = ShiftAssignment::create(['shift_id' => $this->shifts[$date]->id, 'user_id' => $this->employee->id]);
    }
});

function rrcAssign($test, string $date, array $extra = [])
{
    return $test->actingAs($test->maintainer)->postJson('/api/schedule/assign', array_merge([
        'shift_id' => $test->shifts[$date]->id, 'user_id' => $test->employee->id,
    ], $extra));
}

it('answers 409 when the new shift would put an existing later shift into rest', function () {
    // Night 01-03 completes 2-in-a-row → rest 01-04..01-05 → the existing 01-05 night.
    rrcAssign($this, '2029-01-03')->assertStatus(409)->assertJson([
        'code' => 'creates_rest_conflict',
        'affected' => [['assignment_id' => $this->existing['2029-01-05']->id, 'date' => '2029-01-05', 'shift_type' => 'night', 'resting_until' => '2029-01-05']],
        'rule' => ['shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 2],
    ]);

    expect(ShiftAssignment::where('shift_id', $this->shifts['2029-01-03']->id)->count())->toBe(0);
    expect($this->existing['2029-01-05']->fresh()->rest_policy)->toBeNull();
});

it('creates the assignment and stamps the chosen policy on the affected shifts when confirmed', function () {
    rrcAssign($this, '2029-01-03', ['later_rest_policy' => 'extends_rest'])
        ->assertCreated()
        ->assertJson(['updated_assignment_ids' => [$this->existing['2029-01-05']->id], 'updated_rest_policy' => 'extends_rest']);

    expect(ShiftAssignment::where('shift_id', $this->shifts['2029-01-03']->id)->count())->toBe(1);
    expect($this->existing['2029-01-05']->fresh()->rest_policy)->toBe('extends_rest');
});

it('rejects an unknown later_rest_policy', function () {
    rrcAssign($this, '2029-01-03', ['later_rest_policy' => 'nope'])->assertStatus(422)->assertJsonValidationErrors(['later_rest_policy']);
});

it('checks the dropped day itself first, and a not_counted rest-day assign affects nothing later', function () {
    // Make 01-03 a rest day: assign night 01-01 + 01-02 → rest 01-03..01-04.
    $first = Shift::create(['date' => '2029-01-01', 'shift_number' => 1, 'shift_group_id' => $this->group->id, 'shift_type' => 'night']);
    ShiftAssignment::create(['shift_id' => $first->id, 'user_id' => $this->employee->id]);

    rrcAssign($this, '2029-01-03')->assertStatus(409)->assertJson(['code' => 'rest_window_conflict']);
    rrcAssign($this, '2029-01-03', ['rest_policy' => 'not_counted'])->assertCreated()->assertJson(['updated_assignment_ids' => []]);
});

it('flags assignments that are on a rest day with no policy, and not those with one', function () {
    // Create the conflict by a direct insert (as a retype or rule edit would): 01-02 c1, 01-03 c2 → rest 01-04..01-05.
    ShiftAssignment::create(['shift_id' => $this->shifts['2029-01-03']->id, 'user_id' => $this->employee->id]);

    $payload = $this->actingAs($this->maintainer)->getJson("/api/schedule?month=2029-01&shift_group_id={$this->group->id}");
    $a5 = collect($payload->json('schedule.2029-01-05.0.assignments'))->firstWhere('user_id', $this->employee->id);
    expect($a5)->toMatchArray(['in_rest' => true, 'resting_until' => '2029-01-05']);
    $a3 = collect($payload->json('schedule.2029-01-03.0.assignments'))->firstWhere('user_id', $this->employee->id);
    expect($a3)->toMatchArray(['in_rest' => false, 'resting_until' => null]);

    $this->existing['2029-01-05']->update(['rest_policy' => 'not_counted']);
    $payload = $this->actingAs($this->maintainer)->getJson("/api/schedule?month=2029-01&shift_group_id={$this->group->id}");
    $a5 = collect($payload->json('schedule.2029-01-05.0.assignments'))->firstWhere('user_id', $this->employee->id);
    expect($a5)->toMatchArray(['in_rest' => false, 'resting_until' => '2029-01-05']);
});

it('flags in_rest after a retype makes an existing shift a rest day', function () {
    // 01-03 starts as a day shift with an assignment, then is retyped to night.
    $dayShift = $this->shifts['2029-01-03'];
    $dayShift->update(['shift_type' => 'day']);
    ShiftAssignment::create(['shift_id' => $dayShift->id, 'user_id' => $this->employee->id]);
    // No rest yet: 01-02 night c1, 01-03 day, 01-05 night c2 → window 01-06..01-07.
    $before = $this->actingAs($this->maintainer)->getJson("/api/schedule?month=2029-01&shift_group_id={$this->group->id}");
    expect(collect($before->json('schedule.2029-01-05.0.assignments'))->firstWhere('user_id', $this->employee->id)['in_rest'])->toBeFalse();

    // Retype 01-03 day → night through the real endpoint (see ScheduleController::updateShiftType for the route).
    $this->actingAs($this->maintainer)->putJson("/api/schedule/shifts/{$dayShift->id}/type", ['shift_type' => 'night'])->assertOk();

    $after = $this->actingAs($this->maintainer)->getJson("/api/schedule?month=2029-01&shift_group_id={$this->group->id}");
    expect(collect($after->json('schedule.2029-01-05.0.assignments'))->firstWhere('user_id', $this->employee->id)['in_rest'])->toBeTrue();
});

it('reports a plural, deduplicated message when two later shifts would go into rest', function () {
    // 01-04 is a day shift (never counts): baseline 02 c1, 05 c2 → window 06..07. Assigning 03 → window 04..05.
    $this->shifts['2029-01-04']->update(['shift_type' => 'day']);
    ShiftAssignment::create(['shift_id' => $this->shifts['2029-01-04']->id, 'user_id' => $this->employee->id]);

    $res = rrcAssign($this, '2029-01-03')->assertStatus(409)->assertJson(['code' => 'creates_rest_conflict']);
    expect($res->json('affected'))->toHaveCount(2);
    expect($res->json('message'))->toContain('makes 2029-01-04 and 2029-01-05 rest days for this employee (night: 2 in a row');
});

it('answers both conflicts in turn: dropped day is a rest day, and counting it would rest a later shift', function () {
    // Nights 01-01, 01-02 → window 01-03..01-04. Existing 01-05 (c1) and 01-06 (c2) → window 01-07..01-08.
    $d1 = Shift::create(['date' => '2029-01-01', 'shift_number' => 1, 'shift_group_id' => $this->group->id, 'shift_type' => 'night']);
    ShiftAssignment::create(['shift_id' => $d1->id, 'user_id' => $this->employee->id]);
    $d6 = Shift::create(['date' => '2029-01-06', 'shift_number' => 1, 'shift_group_id' => $this->group->id, 'shift_type' => 'night']);
    $a6 = ShiftAssignment::create(['shift_id' => $d6->id, 'user_id' => $this->employee->id]);

    rrcAssign($this, '2029-01-04')->assertStatus(409)->assertJson(['code' => 'rest_window_conflict']);

    // 'counts' on 01-04 → 04 c1, 05 c2 → window 01-06..01-07 now covers the existing 01-06.
    rrcAssign($this, '2029-01-04', ['rest_policy' => 'counts'])->assertStatus(409)->assertJson([
        'code' => 'creates_rest_conflict',
        'affected' => [['assignment_id' => $a6->id, 'date' => '2029-01-06']],
    ]);

    $res = rrcAssign($this, '2029-01-04', ['rest_policy' => 'counts', 'later_rest_policy' => 'extends_rest'])
        ->assertCreated()
        ->assertJson(['rest_policy' => 'counts', 'updated_assignment_ids' => [$a6->id], 'updated_rest_policy' => 'extends_rest']);
    expect(ShiftAssignment::find($res->json('id'))->rest_policy)->toBe('counts');
    expect($a6->fresh()->rest_policy)->toBe('extends_rest');
});

it('flags in_rest per group in the all-groups payload', function () {
    ShiftAssignment::create(['shift_id' => $this->shifts['2029-01-03']->id, 'user_id' => $this->employee->id]); // rest 01-04..01-05
    $other = ShiftGroup::create(['name' => 'Other', 'is_default' => false, 'sort_order' => 1]);
    $otherShift = Shift::create(['date' => '2029-01-05', 'shift_number' => 1, 'shift_group_id' => $other->id, 'shift_type' => 'night']);
    ShiftAssignment::create(['shift_id' => $otherShift->id, 'user_id' => $this->employee->id]);

    $payload = $this->actingAs($this->maintainer)->getJson('/api/schedule?month=2029-01&shift_group_id=all');
    $day = collect($payload->json('schedule.2029-01-05'));
    $main = collect($day->firstWhere('shift_group_id', $this->group->id)['assignments'])->firstWhere('user_id', $this->employee->id);
    $oth = collect($day->firstWhere('shift_group_id', $other->id)['assignments'])->firstWhere('user_id', $this->employee->id);
    expect($main)->toMatchArray(['in_rest' => true, 'resting_until' => '2029-01-05']);
    expect($oth)->toMatchArray(['in_rest' => false, 'resting_until' => null]);
});

it('includes in_rest and resting_until in the updateDayShifts payload', function () {
    ShiftAssignment::create(['shift_id' => $this->shifts['2029-01-03']->id, 'user_id' => $this->employee->id]); // rest 01-04..01-05

    $res = $this->actingAs($this->maintainer)->putJson('/api/schedule/day-shifts', [
        'date' => '2029-01-05', 'count' => 1, 'shift_group_id' => $this->group->id,
    ])->assertOk();
    $a = collect($res->json('shifts.0.assignments'))->firstWhere('user_id', $this->employee->id);
    expect($a)->toMatchArray(['in_rest' => true, 'resting_until' => '2029-01-05']);
});

function rrcNight($test, string $date, bool $assign = true): ?ShiftAssignment
{
    $test->shifts[$date] ??= Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $test->group->id, 'shift_type' => 'night']);

    return $assign ? ShiftAssignment::create(['shift_id' => $test->shifts[$date]->id, 'user_id' => $test->employee->id]) : null;
}

function rrcPayloadAssignment($test, string $date): array
{
    $payload = $test->actingAs($test->maintainer)->getJson("/api/schedule?month=2029-01&shift_group_id={$test->group->id}");

    return collect($payload->json("schedule.{$date}.0.assignments"))->firstWhere('user_id', $test->employee->id);
}

it('applies the chosen extends_rest policy to a further shift it pushes into rest', function () {
    // Rule night 2 -> 3. Existing nights 02, 05, 08. Assigning 03: 02+03 rest 04..06 covers 05 (affected).
    // Extending the rest from 05 (to 08) then covers 08 as well.
    ShiftStreakRule::where('shift_group_id', $this->group->id)->update(['rest_days' => 3]);
    $a8 = rrcNight($this, '2029-01-08');
    $a5 = $this->existing['2029-01-05'];

    rrcAssign($this, '2029-01-03')->assertStatus(409)->assertJson(['code' => 'creates_rest_conflict']);
    expect(array_column(rrcAssign($this, '2029-01-03')->json('affected'), 'assignment_id'))->toBe([$a5->id]);

    $res = rrcAssign($this, '2029-01-03', ['later_rest_policy' => 'extends_rest', 'expected_affected_ids' => [$a5->id]])
        ->assertCreated()->assertJson(['updated_rest_policy' => 'extends_rest']);
    expect($res->json('updated_assignment_ids'))->toBe([$a5->id, $a8->id]);
    expect($a5->fresh()->rest_policy)->toBe('extends_rest');
    expect($a8->fresh()->rest_policy)->toBe('extends_rest');
    foreach (['2029-01-05', '2029-01-08'] as $date) {
        expect(rrcPayloadAssignment($this, $date)['in_rest'])->toBeFalse();
    }
});

it('applies the chosen counts policy to a further shift it pushes into rest', function () {
    // Rule night 2 -> 3. Existing nights 02, 05, 07, 09. Assigning 03: 02+03 rest 04..06 covers 05 (affected).
    // Counting 05 makes 05+07 a streak, rest 08..10, which covers 09.
    ShiftStreakRule::where('shift_group_id', $this->group->id)->update(['rest_days' => 3]);
    $a7 = rrcNight($this, '2029-01-07');
    $a9 = rrcNight($this, '2029-01-09');
    $a5 = $this->existing['2029-01-05'];

    $res = rrcAssign($this, '2029-01-03', ['later_rest_policy' => 'counts', 'expected_affected_ids' => [$a5->id]])
        ->assertCreated()->assertJson(['updated_rest_policy' => 'counts']);
    expect($res->json('updated_assignment_ids'))->toBe([$a5->id, $a9->id]);
    expect($a5->fresh()->rest_policy)->toBe('counts');
    expect($a9->fresh()->rest_policy)->toBe('counts');
    expect($a7->fresh()->rest_policy)->toBeNull();
    foreach (['2029-01-05', '2029-01-07', '2029-01-09'] as $date) {
        expect(rrcPayloadAssignment($this, $date)['in_rest'])->toBeFalse();
    }
});

it('asks again with the fresh list when the shifts it would affect changed since the prompt', function () {
    $a5 = $this->existing['2029-01-05'];
    rrcAssign($this, '2029-01-03')->assertStatus(409);
    // Meanwhile another later shift lands inside the coming rest (01-04 as a day shift).
    $this->shifts['2029-01-04']->update(['shift_type' => 'day']);
    $a4 = ShiftAssignment::create(['shift_id' => $this->shifts['2029-01-04']->id, 'user_id' => $this->employee->id]);

    $res = rrcAssign($this, '2029-01-03', ['later_rest_policy' => 'extends_rest', 'expected_affected_ids' => [$a5->id]])
        ->assertStatus(409)->assertJson(['code' => 'creates_rest_conflict']);
    expect(array_column($res->json('affected'), 'assignment_id'))->toBe([$a4->id, $a5->id]);
    expect(ShiftAssignment::where('shift_id', $this->shifts['2029-01-03']->id)->count())->toBe(0);
    expect($a5->fresh()->rest_policy)->toBeNull();

    rrcAssign($this, '2029-01-03', ['later_rest_policy' => 'extends_rest', 'expected_affected_ids' => [$a5->id, $a4->id]])
        ->assertCreated();
});

it('rejects a malformed expected_affected_ids', function () {
    rrcAssign($this, '2029-01-03', ['later_rest_policy' => 'counts', 'expected_affected_ids' => ['x']])
        ->assertStatus(422)->assertJsonValidationErrors(['expected_affected_ids.0']);
});

it('leaves a deliberate earlier answer alone on a shift the manager was not shown', function () {
    // Rule night 2 -> 3. Nights 02, 05 and 08; 08 was answered 'counts' (02+05 rest 06..08 covers it).
    // Assigning 03 rests 05 (shown) and uncovers 08; extending the rest from 05 covers 08 again.
    ShiftStreakRule::where('shift_group_id', $this->group->id)->update(['rest_days' => 3]);
    $a8 = rrcNight($this, '2029-01-08');
    $a8->update(['rest_policy' => 'counts']);
    $a5 = $this->existing['2029-01-05'];

    $res = rrcAssign($this, '2029-01-03', ['later_rest_policy' => 'extends_rest', 'expected_affected_ids' => [$a5->id]])
        ->assertCreated();
    expect($res->json('updated_assignment_ids'))->toBe([$a5->id]);
    expect($a5->fresh()->rest_policy)->toBe('extends_rest');
    expect($a8->fresh()->rest_policy)->toBe('counts');
});

it('only updates shifts still on a rest day once the choice is applied', function () {
    // Rule night 2 -> 3. Nights 04, 06 (counts), 10, 11 (counts); none on a rest day yet.
    // Assigning 03: 03+04 rest 05..07 covers 06, which counts, so 06+10 rest 11..13 covers 11: both shown.
    // Choosing extends_rest makes 06 stop counting, so 10+11 is a streak again and 11 is not on a rest day.
    ShiftStreakRule::where('shift_group_id', $this->group->id)->update(['rest_days' => 3]);
    ShiftAssignment::query()->delete();
    rrcNight($this, '2029-01-04');
    $a6 = rrcNight($this, '2029-01-06');
    $a6->update(['rest_policy' => 'counts']);
    rrcNight($this, '2029-01-10');
    $a11 = rrcNight($this, '2029-01-11');
    $a11->update(['rest_policy' => 'counts']);

    expect(array_column(rrcAssign($this, '2029-01-03')->assertStatus(409)->json('affected'), 'assignment_id'))->toBe([$a6->id, $a11->id]);

    $res = rrcAssign($this, '2029-01-03', ['later_rest_policy' => 'extends_rest', 'expected_affected_ids' => [$a6->id, $a11->id]])
        ->assertCreated();
    expect($res->json('updated_assignment_ids'))->toBe([$a6->id]);
    expect($a6->fresh()->rest_policy)->toBe('extends_rest');
    // 11 is not on a rest day, so its old answer no longer applies and is cleared (RestPolicyJanitor).
    expect($a11->fresh()->rest_policy)->toBeNull();
    expect(rrcPayloadAssignment($this, '2029-01-11')['resting_until'])->toBeNull();
});
