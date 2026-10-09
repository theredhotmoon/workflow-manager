<?php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;
use App\Services\StreakReplay;
use Illuminate\Support\Carbon;

it('clearing a month removes its assignments from the streak replay', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee']);
    foreach (['2029-01-01', '2029-01-02'] as $date) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
        $this->actingAs($maintainer)->postJson('/api/schedule/assign', ['shift_id' => $shift->id, 'user_id' => $employee->id])->assertCreated();
    }
    $replay = fn () => app(StreakReplay::class)->forGroup($group, [$employee->id], Carbon::parse('2029-02-01'));
    expect($replay()->isResting($employee->id, Carbon::parse('2029-01-03')))->toBeTrue();

    $this->actingAs($maintainer)->deleteJson('/api/schedule/clear-month', ['month' => '2029-01', 'shift_group_id' => $group->id])->assertOk();

    expect(Shift::where('shift_group_id', $group->id)->count())->toBe(0);
    expect($replay()->isResting($employee->id, Carbon::parse('2029-01-03')))->toBeFalse();
});

it('clears a month for a group with no streak rules configured', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee']);
    $shift = Shift::create(['date' => '2026-09-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    $this->actingAs($maintainer)->postJson('/api/schedule/assign', ['shift_id' => $shift->id, 'user_id' => $employee->id])->assertCreated();

    $this->actingAs($maintainer)->deleteJson('/api/schedule/clear-month', ['month' => '2026-09', 'shift_group_id' => $group->id])
        ->assertOk();

    expect(Shift::where('shift_group_id', $group->id)->count())->toBe(0);
});
