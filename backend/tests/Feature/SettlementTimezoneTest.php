<?php

use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// F5: callback hours are elapsed time, like shift hours: a callback across the end of DST counts
// the repeated hour. F6: with no anchor and no creation date, a settlement period is anchored on
// the business date, not the UTC one.

afterEach(fn () => Carbon::setTestNow());

it('counts a callback across the fall-back night as elapsed hours (F5)', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00', 'UTC'));
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-10-01',
    ]);
    $employee = User::factory()->create();
    $onCall = OnCallAssignment::create([
        'shift_group_id' => $group->id, 'user_id' => $employee->id,
        'starts_at' => '2026-10-24 20:00:00', 'ends_at' => '2026-10-25 08:00:00',
    ]);
    // Warsaw wall clock: 01:00 CEST to 04:00 CET is 4 hours (02:00-03:00 happens twice).
    OnCallCallback::create(['on_call_assignment_id' => $onCall->id, 'starts_at' => '2026-10-25 01:00:00', 'ends_at' => '2026-10-25 04:00:00']);

    $response = $this->actingAs($maintainer)->getJson("/api/settlement-periods?shift_group_id={$group->id}&offset=0")->assertOk();

    expect($response->json('employees.0.total_hours'))->toBe(4.0);
});

it('counts a callback across the spring-forward night as elapsed hours (F5)', function () {
    Carbon::setTestNow(Carbon::parse('2027-03-30 12:00:00', 'UTC'));
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2027-03-01',
    ]);
    $employee = User::factory()->create();
    $onCall = OnCallAssignment::create([
        'shift_group_id' => $group->id, 'user_id' => $employee->id,
        'starts_at' => '2027-03-27 20:00:00', 'ends_at' => '2027-03-28 08:00:00',
    ]);
    // 01:00 CET to 04:00 CEST is 2 hours (02:00-03:00 doesn't exist).
    OnCallCallback::create(['on_call_assignment_id' => $onCall->id, 'starts_at' => '2027-03-28 01:00:00', 'ends_at' => '2027-03-28 04:00:00']);

    $response = $this->actingAs($maintainer)->getJson("/api/settlement-periods?shift_group_id={$group->id}&offset=0")->assertOk();

    expect($response->json('employees.0.total_hours'))->toBe(2.0);
});

it('anchors a group without anchor or creation date on the business date (F6)', function () {
    // 00:30 on Nov 1 in Warsaw, still Oct 31 in UTC.
    Carbon::setTestNow(Carbon::parse('2026-10-31 23:30:00', 'UTC'));
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $id = DB::table('shift_groups')->insertGetId([
        'name' => 'Legacy', 'is_default' => false, 'sort_order' => 5,
        'settlement_mode' => 'custom', 'settlement_custom_length_days' => 7,
        'created_at' => null, 'updated_at' => null,
    ]);

    $this->actingAs($maintainer)->getJson("/api/settlement-periods?shift_group_id={$id}&offset=0")->assertOk()
        ->assertJsonPath('period.start', '2026-11-01')
        ->assertJsonPath('period.end', '2026-11-07');
});
