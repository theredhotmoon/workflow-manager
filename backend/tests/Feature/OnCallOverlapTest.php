<?php
// M13: a person's on-call periods (and callbacks) must not overlap, and overlapping rows already in
// the database are counted once in the settlement report.

use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $this->group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01',
    ]);
    $this->employee = User::factory()->create(['role' => 'employee', 'name' => 'Ewa Nowak']);
});

function ocoStore($test, string $start, string $end, ?int $userId = null, ?int $groupId = null)
{
    return $test->actingAs($test->maintainer)->postJson('/api/on-call-assignments', [
        'shift_group_id' => $groupId ?? $test->group->id,
        'user_id' => $userId ?? $test->employee->id,
        'starts_at' => $start,
        'ends_at' => $end,
    ]);
}

function ocoAssignment($test, string $start, string $end): OnCallAssignment
{
    return OnCallAssignment::create([
        'shift_group_id' => $test->group->id, 'user_id' => $test->employee->id,
        'starts_at' => $start, 'ends_at' => $end,
    ]);
}

it('rejects an on-call period that overlaps another of the same person', function () {
    ocoStore($this, '2026-09-15 08:00:00', '2026-09-16 08:00:00')->assertCreated();

    $res = ocoStore($this, '2026-09-16 06:00:00', '2026-09-16 20:00:00')
        ->assertStatus(422)->assertJsonValidationErrors(['starts_at']);
    expect($res->json('message'))->toBe('Ewa Nowak is already on call from 2026-09-15 08:00 to 2026-09-16 08:00.');

    // Fully inside, and in another group: still the same person.
    $other = ShiftGroup::create(['name' => 'Other', 'is_default' => false, 'sort_order' => 1]);
    ocoStore($this, '2026-09-15 10:00:00', '2026-09-15 12:00:00', null, $other->id)->assertStatus(422);

    expect(OnCallAssignment::count())->toBe(1);
});

it('accepts back-to-back periods and another person at the same time', function () {
    ocoStore($this, '2026-09-15 08:00:00', '2026-09-16 08:00:00')->assertCreated();
    ocoStore($this, '2026-09-16 08:00:00', '2026-09-17 08:00:00')->assertCreated();
    ocoStore($this, '2026-09-14 08:00:00', '2026-09-15 08:00:00')->assertCreated();
    $colleague = User::factory()->create(['role' => 'employee']);
    ocoStore($this, '2026-09-15 08:00:00', '2026-09-16 08:00:00', $colleague->id)->assertCreated();

    expect(OnCallAssignment::count())->toBe(4);
});

it('rejects a callback that overlaps another callback of the same person, touching is fine', function () {
    $a = ocoAssignment($this, '2026-09-15 08:00:00', '2026-09-16 08:00:00');
    $url = "/api/on-call-assignments/{$a->id}/callbacks";
    $this->actingAs($this->maintainer)->postJson($url, ['starts_at' => '2026-09-15 22:00:00', 'ends_at' => '2026-09-15 23:30:00'])->assertCreated();

    $this->actingAs($this->maintainer)->postJson($url, ['starts_at' => '2026-09-15 23:00:00', 'ends_at' => '2026-09-16 00:30:00'])
        ->assertStatus(422)->assertJsonValidationErrors(['starts_at']);
    $this->actingAs($this->maintainer)->postJson($url, ['starts_at' => '2026-09-15 23:30:00', 'ends_at' => '2026-09-16 00:30:00'])
        ->assertCreated();

    expect(OnCallCallback::count())->toBe(2);
});

it('rejects a callback overlapping one logged on another on-call period of the same person', function () {
    // Back-to-back periods; the first one's callback runs past the hand-over at 08:00.
    $first = ocoAssignment($this, '2026-09-15 08:00:00', '2026-09-16 08:00:00');
    $second = ocoAssignment($this, '2026-09-16 08:00:00', '2026-09-17 08:00:00');
    $this->actingAs($this->maintainer)->postJson("/api/on-call-assignments/{$first->id}/callbacks", [
        'starts_at' => '2026-09-16 07:00:00', 'ends_at' => '2026-09-16 09:00:00',
    ])->assertCreated();

    $res = $this->actingAs($this->maintainer)->postJson("/api/on-call-assignments/{$second->id}/callbacks", [
        'starts_at' => '2026-09-16 08:30:00', 'ends_at' => '2026-09-16 09:30:00',
    ])->assertStatus(422)->assertJsonValidationErrors(['starts_at']);
    expect($res->json('message'))->toBe('This overlaps a callback already logged from 2026-09-16 07:00 to 2026-09-16 09:00.');

    // Touching the first one's end is fine.
    $this->actingAs($this->maintainer)->postJson("/api/on-call-assignments/{$second->id}/callbacks", [
        'starts_at' => '2026-09-16 09:00:00', 'ends_at' => '2026-09-16 09:30:00',
    ])->assertCreated();
    expect(OnCallCallback::count())->toBe(2);
});

it('counts overlapping callbacks already in the database once in the settlement report', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');
    // Two overlapping on-call periods, inserted directly as legacy data, each with a callback:
    // 22:00-01:00, 23:00-02:00 and 00:00-00:30 overlap; their union is 22:00-02:00 = 4 h, not 6.5 h.
    // The touching 02:00-03:30 callback adds 1.5 h: 5.5 h.
    $first = ocoAssignment($this, '2026-09-01 20:00:00', '2026-09-02 08:00:00');
    $second = ocoAssignment($this, '2026-09-01 21:00:00', '2026-09-02 09:00:00');
    DB::table('on_call_callbacks')->insert([
        ['on_call_assignment_id' => $first->id, 'starts_at' => '2026-09-01 22:00:00', 'ends_at' => '2026-09-02 01:00:00', 'created_at' => now(), 'updated_at' => now()],
        ['on_call_assignment_id' => $second->id, 'starts_at' => '2026-09-01 23:00:00', 'ends_at' => '2026-09-02 02:00:00', 'created_at' => now(), 'updated_at' => now()],
        ['on_call_assignment_id' => $second->id, 'starts_at' => '2026-09-02 00:00:00', 'ends_at' => '2026-09-02 00:30:00', 'created_at' => now(), 'updated_at' => now()],
        ['on_call_assignment_id' => $first->id, 'starts_at' => '2026-09-02 02:00:00', 'ends_at' => '2026-09-02 03:30:00', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $res = $this->actingAs($this->maintainer)->getJson('/api/settlement-periods?' . http_build_query([
        'shift_group_id' => $this->group->id,
        'offset' => 0,
    ]))->assertOk();

    // 22:00-02:00 (4 h) + the touching 02:00-03:30 (1.5 h).
    expect($res->json('employees.0.name'))->toBe('Ewa Nowak');
    expect($res->json('employees.0.weekly_hours.0'))->toBe(5.5);
    expect($res->json('employees.0.total_hours'))->toBe(5.5);
});
