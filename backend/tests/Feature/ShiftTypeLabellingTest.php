<?php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;
use Illuminate\Support\Carbon;
use App\Services\StreakReplay;

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
    return User::factory()->create(['role' => 'employee']);
}

function stlAssign($test, User $maintainer, Shift $shift, User $employee): void
{
    $test->actingAs($maintainer)->postJson('/api/schedule/assign', ['shift_id' => $shift->id, 'user_id' => $employee->id])->assertCreated();
}

/** Night-rule status read through the replay of the group's assignments. */
function stlStatus(ShiftGroup $group, User $employee, string $asOf): array
{
    return app(StreakReplay::class)->forGroup($group, [$employee->id], Carbon::parse($asOf))
        ->statusAsOf($employee->id, Carbon::parse($asOf))[0];
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

it('changes the replayed streak count when an assigned night shift becomes a day shift', function () {
    $maintainer = stlMaintainer();
    $group = stlGroup();
    $rule = stlNightRule($group);
    $employee = stlEmployee();
    $s1 = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    $s2 = Shift::create(['date' => '2027-03-02', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    stlAssign($this, $maintainer, $s1, $employee);
    stlAssign($this, $maintainer, $s2, $employee);
    expect(stlStatus($group, $employee, '2027-03-10')['current_count'])->toBe(2);

    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$s2->id}/type", ['shift_type' => 'day'])
        ->assertOk()->assertJson(['rebooked' => 1]);

    expect(stlStatus($group, $employee, '2027-03-10')['current_count'])->toBe(1);
});

it('relabelling counts every assigned employee and can trigger a rest window', function () {
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
        $status = stlStatus($group, $employee, '2027-03-04');
        expect($status['current_count'])->toBe(0);
        expect($status['resting_until'])->toBe('2027-03-05');
    }
});

it('clearing the type of an assigned night shift drops it from the replayed streak', function () {
    $maintainer = stlMaintainer();
    $group = stlGroup();
    $rule = stlNightRule($group);
    $employee = stlEmployee();
    $night = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    stlAssign($this, $maintainer, $night, $employee);

    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$night->id}/type", ['shift_type' => null])
        ->assertOk()->assertJson(['shift_type' => null, 'rebooked' => 1]);

    expect(stlStatus($group, $employee, '2027-03-10')['current_count'])->toBe(0);
});

it('reports rebooked 0 when no streak rule is touched (unassigned, unchanged, or between rule-less types)', function () {
    $maintainer = stlMaintainer();
    $group = stlGroup();
    stlNightRule($group);
    $employee = stlEmployee();
    $unassigned = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day']);
    $assigned = Shift::create(['date' => '2027-03-02', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    stlAssign($this, $maintainer, $assigned, $employee);

    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$unassigned->id}/type", ['shift_type' => 'night'])
        ->assertOk()->assertJson(['rebooked' => 0]);
    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$assigned->id}/type", ['shift_type' => 'NIGHT'])
        ->assertOk()->assertJson(['shift_type' => 'night', 'rebooked' => 0]);

    // An assigned shift moving between two types that have no rule affects no streak either.
    $plain = Shift::create(['date' => '2027-03-03', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day']);
    stlAssign($this, $maintainer, $plain, $employee);
    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$plain->id}/type", ['shift_type' => 'evening'])
        ->assertOk()->assertJson(['shift_type' => 'evening', 'rebooked' => 0]);

});

it('bulk-sets the type for one slot in one group and month only', function () {
    $group = stlGroup();
    $other = stlGroup('Other');
    $target1 = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    $target2 = Shift::create(['date' => '2027-03-31', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    $otherSlot = Shift::create(['date' => '2027-03-01', 'shift_number' => 2, 'shift_group_id' => $group->id]);
    $otherMonth = Shift::create(['date' => '2027-04-01', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    $otherGroup = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $other->id]);

    $this->actingAs(stlMaintainer())->putJson('/api/schedule/shift-types', [
        'shift_group_id' => $group->id, 'month' => '2027-03', 'shift_number' => 1, 'shift_type' => 'Night',
    ])->assertOk()->assertExactJson(['updated' => 2, 'rebooked' => 0]);

    expect($target1->fresh()->shift_type)->toBe('night');
    expect($target2->fresh()->shift_type)->toBe('night');
    foreach ([$otherSlot, $otherMonth, $otherGroup] as $untouched) {
        expect($untouched->fresh()->shift_type)->toBeNull();
    }
});

it('bulk-relabels assigned shifts in date order so the rest window starts on the 3rd night', function () {
    $maintainer = stlMaintainer();
    $group = stlGroup();
    $rule = stlNightRule($group);
    $employee = stlEmployee();
    // Created out of date order on purpose.
    foreach (['2027-03-03', '2027-03-01', '2027-03-02'] as $date) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day']);
        stlAssign($this, $maintainer, $shift, $employee);
    }

    $this->actingAs($maintainer)->putJson('/api/schedule/shift-types', [
        'shift_group_id' => $group->id, 'month' => '2027-03', 'shift_number' => 1, 'shift_type' => 'night',
    ])->assertOk()->assertExactJson(['updated' => 3, 'rebooked' => 3]);

    expect(stlStatus($group, $employee, '2027-03-04'))->toMatchArray(['current_count' => 0, 'resting_until' => '2027-03-05']);
});

it('bulk edit on the 31st still targets the requested month', function () {
    Carbon::setTestNow('2026-10-31 12:00:00');
    $group = stlGroup();
    $february = Shift::create(['date' => '2027-02-10', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    $march = Shift::create(['date' => '2027-03-02', 'shift_number' => 1, 'shift_group_id' => $group->id]);

    $this->actingAs(stlMaintainer())->putJson('/api/schedule/shift-types', [
        'shift_group_id' => $group->id, 'month' => '2027-02', 'shift_number' => 1, 'shift_type' => 'night',
    ])->assertOk()->assertExactJson(['updated' => 1, 'rebooked' => 0]);

    expect($february->fresh()->shift_type)->toBe('night');
    expect($march->fresh()->shift_type)->toBeNull();
    Carbon::setTestNow();
});

it('bulk edit returns updated 0 for a slot with no shifts and validates input', function () {
    $group = stlGroup();
    $maintainer = stlMaintainer();

    $this->actingAs($maintainer)->putJson('/api/schedule/shift-types', [
        'shift_group_id' => $group->id, 'month' => '2027-03', 'shift_number' => 4, 'shift_type' => 'night',
    ])->assertOk()->assertExactJson(['updated' => 0, 'rebooked' => 0]);

    $this->actingAs($maintainer)->putJson('/api/schedule/shift-types', [
        'shift_group_id' => $group->id, 'month' => '2027-3', 'shift_number' => 0, 'shift_type' => 'night',
    ])->assertStatus(422)->assertJsonValidationErrors(['month', 'shift_number']);
});

it('auto-assign respects a streak once shifts are labelled through the bulk endpoint', function () {
    $maintainer = stlMaintainer();
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01', 'weekly_hour_cap' => 168,
    ]);
    stlNightRule($group);
    $employee = stlEmployee();
    foreach (['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05'] as $date) {
        Shift::create([
            'date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id,
            'start_time' => '22:00', 'end_time' => '06:00',
        ]);
    }

    $this->actingAs($maintainer)->putJson('/api/schedule/shift-types', [
        'shift_group_id' => $group->id, 'month' => '2026-09', 'shift_number' => 1, 'shift_type' => 'night',
    ])->assertOk();

    $response = $this->actingAs($maintainer)->postJson('/api/schedule/auto-assign/polish-law', [
        'month' => '2026-09',
        'shift_group_id' => $group->id,
        'users_per_shift_weekday' => 1,
        'users_per_shift_weekend' => 1,
        'rotation_days' => 7,
        'player_mode' => 'include',
        'player_ids' => [$employee->id],
    ]);

    $response->assertOk();
    // 3 nights, then rest on 09-04 and 09-05 (rest_days 2). Without types, all 5 would be assigned.
    expect($response->json('total_assigned'))->toBe(3);
});

it('relabelling an earlier month keeps the later rest window active', function () {
    $maintainer = stlMaintainer();
    $group = stlGroup();
    $rule = stlNightRule($group);
    $employee = stlEmployee();
    foreach (['2027-04', '2027-03'] as $month) {
        foreach ([1, 2, 3] as $day) {
            $shift = Shift::create(['date' => "{$month}-0{$day}", 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day']);
            stlAssign($this, $maintainer, $shift, $employee);
        }
    }

    foreach (['2027-04', '2027-03'] as $month) {
        $this->actingAs($maintainer)->putJson('/api/schedule/shift-types', [
            'shift_group_id' => $group->id, 'month' => $month, 'shift_number' => 1, 'shift_type' => 'night',
        ])->assertOk();
    }
    expect(app(StreakReplay::class)->forGroup($group, [$employee->id], Carbon::parse('2027-05-01'))->isResting($employee->id, Carbon::parse('2027-04-05')))->toBeTrue();
});
