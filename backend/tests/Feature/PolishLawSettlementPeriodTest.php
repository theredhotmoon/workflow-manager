<?php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Support\Carbon;

function makeShiftForPolishLaw(int $groupId, string $date, int $shiftNumber, string $start, string $end): Shift
{
    return Shift::create([
        'date' => $date,
        'shift_number' => $shiftNumber,
        'shift_group_id' => $groupId,
        'start_time' => $start,
        'end_time' => $end,
    ]);
}

it('blocks assignment once a configured weekly_hour_cap lower than 48 would be exceeded', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01', 'weekly_hour_cap' => 24,
    ]);
    $employee = User::factory()->create(['role' => 'employee']);

    // Three 12h shifts in the same anchored week (Sep 1-7): a 24h cap allows only 2 before blocking the 3rd.
    makeShiftForPolishLaw($group->id, '2026-09-01', 1, '06:00', '18:00');
    makeShiftForPolishLaw($group->id, '2026-09-02', 1, '06:00', '18:00');
    makeShiftForPolishLaw($group->id, '2026-09-03', 1, '06:00', '18:00');

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
    expect($response->json('total_assigned'))->toBe(2);
});

it('does not include the removed 40h-average compliance note in the response', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee']);
    makeShiftForPolishLaw($group->id, '2026-09-01', 1, '06:00', '18:00');

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
    foreach ($response->json('warnings') as $warning) {
        expect($warning)->not->toContain('exceeds 40h avg');
        expect($warning)->not->toContain('exceeds 48h cap');
    }
});

it('caps at 48h/week (the fallback default) for a group with no settlement config', function () {
    // The fallback anchor is the group's created_at — i.e. whenever this test
    // actually runs in real wall-clock time. Freeze "now" to 2026-09-01 so the
    // resulting monthly period (and its first 7-day week) starts exactly on
    // 2026-09-01, deterministically containing all 5 test shift dates below in
    // one week bucket — without this freeze, which anchored week each shift
    // falls into (and therefore whether the cap accumulates across all 5, or
    // splits across two weeks) would depend on whatever real date the test
    // suite happens to run on.
    Carbon::setTestNow(Carbon::parse('2026-09-01'));

    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee']);

    // 5 consecutive 12h days = 60h, exceeding the default 48h cap partway through.
    foreach (range(1, 5) as $i) {
        makeShiftForPolishLaw($group->id, "2026-09-0{$i}", 1, '06:00', '18:00');
    }

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
    // 48h / 12h per shift = 4 shifts max before the cap blocks the 5th.
    expect($response->json('total_assigned'))->toBe(4);

    Carbon::setTestNow();
});

it('returns a 422 with a clear message instead of a 500 when the group is misconfigured (custom mode, null length)', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Broken Group', 'is_default' => true, 'sort_order' => 0]);
    // Force the group into an invalid state that validation should normally prevent.
    $group->forceFill(['settlement_mode' => 'custom', 'settlement_custom_length_days' => null])->save();
    $employee = User::factory()->create(['role' => 'employee']);
    makeShiftForPolishLaw($group->id, '2026-09-01', 1, '06:00', '18:00');

    $response = $this->actingAs($maintainer)->postJson('/api/schedule/auto-assign/polish-law', [
        'month' => '2026-09',
        'shift_group_id' => $group->id,
        'users_per_shift_weekday' => 1,
        'users_per_shift_weekend' => 1,
        'rotation_days' => 7,
        'player_mode' => 'include',
        'player_ids' => [$employee->id],
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('Settlement period configuration error');
});
