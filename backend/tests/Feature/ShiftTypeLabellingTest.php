<?php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\ShiftStreakState;
use App\Services\ShiftStreakTracker;
use App\Models\User;

function stlMaintainer(): User
{
    return User::factory()->create(['role' => 'schedule_maintainer']);
}

function stlGroup(string $name = 'Main'): ShiftGroup
{
    return ShiftGroup::create(['name' => $name, 'is_default' => $name === 'Main', 'sort_order' => 0]);
}

it('generate stores each slot\'s shift_type normalised and leaves untyped slots null', function () {
    $group = stlGroup();

    $this->actingAs(stlMaintainer())->postJson('/api/schedule/generate', [
        'month' => '2027-03',
        'shift_group_id' => $group->id,
        'shifts_per_day' => 2,
        'shift_times' => [
            ['start_time' => '22:00', 'end_time' => '06:00', 'shift_type' => ' Night '],
            ['start_time' => '06:00', 'end_time' => '14:00'],
        ],
    ])->assertOk();

    expect(Shift::where('shift_group_id', $group->id)->where('shift_number', 1)->distinct()->pluck('shift_type')->all())->toBe(['night']);
    expect(Shift::where('shift_group_id', $group->id)->where('shift_number', 2)->distinct()->pluck('shift_type')->all())->toBe([null]);
});

it('generate does not change the type of a slot that already exists', function () {
    $group = stlGroup();
    $existing = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day']);

    $this->actingAs(stlMaintainer())->postJson('/api/schedule/generate', [
        'month' => '2027-03',
        'shift_group_id' => $group->id,
        'shifts_per_day' => 1,
        'shift_times' => [['start_time' => '22:00', 'end_time' => '06:00', 'shift_type' => 'night']],
    ])->assertOk();

    expect($existing->fresh()->shift_type)->toBe('day');
    expect(Shift::where('shift_group_id', $group->id)->where('date', '2027-03-02')->value('shift_type'))->toBe('night');
});

it('generate rejects a shift_type longer than 50 characters', function () {
    $group = stlGroup();

    $this->actingAs(stlMaintainer())->postJson('/api/schedule/generate', [
        'month' => '2027-03',
        'shift_group_id' => $group->id,
        'shifts_per_day' => 1,
        'shift_times' => [['start_time' => '22:00', 'end_time' => '06:00', 'shift_type' => str_repeat('x', 51)]],
    ])->assertStatus(422)->assertJsonValidationErrors(['shift_times.0.shift_type']);
});

it('includes shift_type in the schedule payload', function () {
    $group = stlGroup();
    Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);

    $response = $this->actingAs(stlMaintainer())->getJson("/api/schedule?month=2027-03&shift_group_id={$group->id}");

    $response->assertOk();
    expect($response->json('schedule.2027-03-01.0.shift_type'))->toBe('night');
});

function stlNightRule(ShiftGroup $group): ShiftStreakRule
{
    return ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
}

function stlEmployee(): User
{
    return User::factory()->create(['role' => 'employee', 'is_admin' => false]);
}

function stlAssign($test, User $maintainer, Shift $shift, User $employee): void
{
    $test->actingAs($maintainer)->postJson('/api/schedule/assign', ['shift_id' => $shift->id, 'user_id' => $employee->id])->assertCreated();
}

function stlState(User $employee, ShiftStreakRule $rule): ?ShiftStreakState
{
    return ShiftStreakState::where('user_id', $employee->id)->where('shift_streak_rule_id', $rule->id)->first();
}

it('updates a shift type, normalised', function () {
    $group = stlGroup();
    $shift = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id]);

    $this->actingAs(stlMaintainer())->putJson("/api/schedule/shifts/{$shift->id}/type", ['shift_type' => ' Night '])
        ->assertOk()->assertJson(['id' => $shift->id, 'shift_type' => 'night', 'rebooked' => 0]);

    expect($shift->fresh()->shift_type)->toBe('night');
});

it('rejects a type over 50 characters and non-managers', function () {
    $group = stlGroup();
    $shift = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id]);

    $this->actingAs(stlMaintainer())->putJson("/api/schedule/shifts/{$shift->id}/type", ['shift_type' => str_repeat('x', 51)])
        ->assertStatus(422);
    $this->actingAs(stlEmployee())->putJson("/api/schedule/shifts/{$shift->id}/type", ['shift_type' => 'night'])
        ->assertStatus(403);
});

it('re-books streak counters when an assigned night shift becomes a day shift', function () {
    $maintainer = stlMaintainer();
    $group = stlGroup();
    $rule = stlNightRule($group);
    $employee = stlEmployee();
    $s1 = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    $s2 = Shift::create(['date' => '2027-03-02', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    stlAssign($this, $maintainer, $s1, $employee);
    stlAssign($this, $maintainer, $s2, $employee);
    expect(stlState($employee, $rule)->current_count)->toBe(2);

    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$s2->id}/type", ['shift_type' => 'day'])
        ->assertOk()->assertJson(['rebooked' => 1]);

    expect(stlState($employee, $rule)->current_count)->toBe(1);
});

it('re-books every assigned employee and can trigger a rest window', function () {
    $maintainer = stlMaintainer();
    $group = stlGroup();
    $rule = stlNightRule($group);
    $alice = stlEmployee();
    $bob = stlEmployee();
    foreach (['2027-03-01', '2027-03-02'] as $date) {
        $night = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
        stlAssign($this, $maintainer, $night, $alice);
        stlAssign($this, $maintainer, $night, $bob);
    }
    $third = Shift::create(['date' => '2027-03-03', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day']);
    stlAssign($this, $maintainer, $third, $alice);
    stlAssign($this, $maintainer, $third, $bob);

    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$third->id}/type", ['shift_type' => 'night'])
        ->assertOk()->assertJson(['rebooked' => 2]);

    foreach ([$alice, $bob] as $employee) {
        $state = stlState($employee, $rule);
        expect($state->current_count)->toBe(0);
        expect($state->resting_until->toDateString())->toBe('2027-03-05');
    }
});

it('clearing the type of an assigned night shift decrements the counter', function () {
    $maintainer = stlMaintainer();
    $group = stlGroup();
    $rule = stlNightRule($group);
    $employee = stlEmployee();
    $night = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    stlAssign($this, $maintainer, $night, $employee);

    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$night->id}/type", ['shift_type' => null])
        ->assertOk()->assertJson(['shift_type' => null, 'rebooked' => 1]);

    expect(stlState($employee, $rule)->current_count)->toBe(0);
});

it('makes no tracker writes for an unassigned shift or an unchanged type', function () {
    $maintainer = stlMaintainer();
    $group = stlGroup();
    stlNightRule($group);
    $employee = stlEmployee();
    $unassigned = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day']);
    $assigned = Shift::create(['date' => '2027-03-02', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    stlAssign($this, $maintainer, $assigned, $employee);
    $statesBefore = ShiftStreakState::orderBy('id')->get()->toArray();

    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$unassigned->id}/type", ['shift_type' => 'night'])
        ->assertOk()->assertJson(['rebooked' => 0]);
    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$assigned->id}/type", ['shift_type' => 'NIGHT'])
        ->assertOk()->assertJson(['shift_type' => 'night', 'rebooked' => 0]);

    expect(ShiftStreakState::orderBy('id')->get()->toArray())->toBe($statesBefore);
});

it('rolls back the type change when re-booking fails', function () {
    $maintainer = stlMaintainer();
    $group = stlGroup();
    $rule = stlNightRule($group);
    $employee = stlEmployee();
    $night = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    stlAssign($this, $maintainer, $night, $employee);

    $this->app->bind(ShiftStreakTracker::class, fn () => new class extends ShiftStreakTracker {
        public function persist(array $states): void
        {
            throw new RuntimeException('simulated persist failure');
        }
    });

    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$night->id}/type", ['shift_type' => 'day'])
        ->assertStatus(500);

    expect($night->fresh()->shift_type)->toBe('night');
    expect(stlState($employee, $rule)->current_count)->toBe(1);
});
