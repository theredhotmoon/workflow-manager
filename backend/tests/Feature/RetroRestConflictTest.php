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
