<?php
// backend/tests/Feature/ScheduleControllerStreakTest.php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;
use App\Services\StreakReplay;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $this->group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $this->group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $this->employee = User::factory()->create(['role' => 'employee']);
    $this->shifts = [];
    foreach (['2029-01-01', '2029-01-02', '2029-01-03', '2029-01-04', '2029-01-07'] as $date) {
        $this->shifts[$date] = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $this->group->id, 'shift_type' => 'night']);
    }
    foreach (['2029-01-01', '2029-01-02', '2029-01-03'] as $date) {
        $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
            'shift_id' => $this->shifts[$date]->id, 'user_id' => $this->employee->id,
        ])->assertCreated();
    }
});

it('answers 409 with the rest window when assigning on a rest day without a policy', function () {
    $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $this->shifts['2029-01-04']->id, 'user_id' => $this->employee->id,
    ])->assertStatus(409)->assertJson([
        'code' => 'rest_window_conflict',
        'resting_until' => '2029-01-05',
        'rule' => ['shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2],
    ]);

    expect(ShiftAssignment::where('shift_id', $this->shifts['2029-01-04']->id)->count())->toBe(0);
});

it('stores the chosen policy when the manager confirms', function (string $policy) {
    $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $this->shifts['2029-01-04']->id, 'user_id' => $this->employee->id, 'rest_policy' => $policy,
    ])->assertCreated()->assertJson(['rest_policy' => $policy]);

    expect(ShiftAssignment::where('shift_id', $this->shifts['2029-01-04']->id)->value('rest_policy'))->toBe($policy);
})->with(['not_counted', 'counts', 'extends_rest']);

it('rejects an unknown policy', function () {
    $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $this->shifts['2029-01-04']->id, 'user_id' => $this->employee->id, 'rest_policy' => 'whatever',
    ])->assertStatus(422)->assertJsonValidationErrors(['rest_policy']);
});

it('assigns normally outside a rest window and ignores a policy sent there', function () {
    $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $this->shifts['2029-01-07']->id, 'user_id' => $this->employee->id, 'rest_policy' => 'counts',
    ])->assertCreated()->assertJson(['rest_policy' => null]);
});

it('unassigning the shift that started a rest window clears the window', function () {
    $third = ShiftAssignment::where('shift_id', $this->shifts['2029-01-03']->id)->first();
    $this->actingAs($this->maintainer)->deleteJson("/api/schedule/unassign/{$third->id}")->assertOk();

    $timeline = app(StreakReplay::class)->forGroup($this->group, [$this->employee->id], Carbon::parse('2029-02-01'));
    expect($timeline->isResting($this->employee->id, Carbon::parse('2029-01-04')))->toBeFalse();
    $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $this->shifts['2029-01-04']->id, 'user_id' => $this->employee->id,
    ])->assertCreated();
});

it('includes rest_policy in the schedule payload', function () {
    $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $this->shifts['2029-01-04']->id, 'user_id' => $this->employee->id, 'rest_policy' => 'extends_rest',
    ])->assertCreated();

    $response = $this->actingAs($this->maintainer)->getJson("/api/schedule?month=2029-01&shift_group_id={$this->group->id}");
    expect($response->json('schedule.2029-01-04.0.assignments.0.rest_policy'))->toBe('extends_rest');
});
