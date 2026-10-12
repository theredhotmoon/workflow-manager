<?php

use App\Models\Alarm;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftChangeRequest;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * PR 20: the managers' Change Requests screen. The list (paged, filtered by status, group and
 * month, pending first), the nav badge's pending count, an idempotent acknowledge that records
 * who and when, and the alarms: managers when a request is filed, the requester when it is
 * acknowledged.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
    $this->group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $this->other = ShiftGroup::create(['name' => 'Night desk', 'sort_order' => 1]);
    $this->employee = User::factory()->create(['role' => 'employee', 'name' => 'Erin Employee']);
    $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin']);
    $this->maintainer = User::factory()->create(['role' => 'schedule_maintainer', 'name' => 'Max Maintainer']);
});

function crsRequest(User $user, string $date, string $status = 'pending', ?ShiftGroup $group = null, int $number = 1): ShiftChangeRequest
{
    $shift = Shift::firstOrCreate(['date' => $date, 'shift_number' => $number, 'shift_group_id' => ($group ?? test()->group)->id]);
    $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id]);

    return ShiftChangeRequest::create([
        'shift_assignment_id' => $assignment->id, 'user_id' => $user->id,
        'shift_id' => $shift->id, 'reason' => 'Doctor', 'status' => $status,
    ]);
}

function crsIds($response): array
{
    return collect($response->assertOk()->json('data'))->pluck('id')->all();
}

it('lists pending requests by default, and acknowledged or all on request', function () {
    $pending = crsRequest($this->employee, '2026-10-20');
    $done = crsRequest($this->employee, '2026-10-21', 'acknowledged');

    expect(crsIds($this->actingAs($this->maintainer)->getJson('/api/shift-change-requests')))->toBe([$pending->id]);
    expect(crsIds($this->actingAs($this->maintainer)->getJson('/api/shift-change-requests?status=pending')))->toBe([$pending->id]);
    expect(crsIds($this->actingAs($this->maintainer)->getJson('/api/shift-change-requests?status=acknowledged')))->toBe([$done->id]);
    expect(crsIds($this->actingAs($this->maintainer)->getJson('/api/shift-change-requests?status=all')))->toBe([$pending->id, $done->id]);
    $this->actingAs($this->maintainer)->getJson('/api/shift-change-requests?status=rejected')
        ->assertUnprocessable()->assertJsonValidationErrors('status');
});

it('filters by group and by month', function () {
    $main = crsRequest($this->employee, '2026-10-20');
    $night = crsRequest($this->employee, '2026-10-21', 'pending', $this->other);
    $november = crsRequest($this->employee, '2026-11-03');

    expect(crsIds($this->actingAs($this->admin)->getJson("/api/shift-change-requests?shift_group_id={$this->other->id}")))->toBe([$night->id]);
    expect(crsIds($this->actingAs($this->admin)->getJson('/api/shift-change-requests?month=2026-11')))->toBe([$november->id]);
    expect(crsIds($this->actingAs($this->admin)->getJson("/api/shift-change-requests?month=2026-10&shift_group_id={$this->group->id}")))->toBe([$main->id]);
    $this->actingAs($this->admin)->getJson('/api/shift-change-requests?shift_group_id=999999')
        ->assertUnprocessable()->assertJsonValidationErrors('shift_group_id');
});

it('orders pending first, then newest filed first', function () {
    $oldPending = crsRequest($this->employee, '2026-10-20');
    Carbon::setTestNow('2026-10-09 11:00:00');
    $newAcknowledged = crsRequest($this->employee, '2026-10-21', 'acknowledged');
    Carbon::setTestNow('2026-10-09 12:00:00');
    $newPending = crsRequest($this->employee, '2026-10-22');
    // Filed in the same second as the newest: the higher id comes first.
    $samePending = crsRequest($this->employee, '2026-10-23');

    expect(crsIds($this->actingAs($this->admin)->getJson('/api/shift-change-requests?status=all')))
        ->toBe([$samePending->id, $newPending->id, $oldPending->id, $newAcknowledged->id]);
});

it('pages the list and clamps per_page to 1..100', function () {
    foreach (range(1, 5) as $day) {
        crsRequest($this->employee, sprintf('2026-10-%02d', 10 + $day));
    }

    $first = $this->actingAs($this->admin)->getJson('/api/shift-change-requests?per_page=2')->assertOk();
    expect($first->json('total'))->toBe(5)
        ->and($first->json('per_page'))->toBe(2)
        ->and($first->json('last_page'))->toBe(3)
        ->and($first->json('data'))->toHaveCount(2);
    $third = $this->actingAs($this->admin)->getJson('/api/shift-change-requests?per_page=2&page=3')->assertOk();
    expect($third->json('data'))->toHaveCount(1);

    expect($this->actingAs($this->admin)->getJson('/api/shift-change-requests?per_page=100000')->json('per_page'))->toBe(100);
    expect($this->actingAs($this->admin)->getJson('/api/shift-change-requests?per_page=0')->json('per_page'))->toBe(1);
    expect($this->actingAs($this->admin)->getJson('/api/shift-change-requests')->json('per_page'))->toBe(20);
});

it('shows the shift, its group, the reason and the requester\'s name, but no email, to a schedule maintainer', function () {
    crsRequest($this->employee, '2026-10-20');

    $row = $this->actingAs($this->maintainer)->getJson('/api/shift-change-requests')->assertOk()->json('data.0');

    expect($row['reason'])->toBe('Doctor')
        ->and($row['user'])->toBe(['id' => $this->employee->id, 'name' => 'Erin Employee'])
        ->and($row['shift']['date'])->toBe('2026-10-20')
        ->and($row['shift']['shift_group']['name'])->toBe('Main')
        ->and($row['shift']['shift_group']['archived_at'])->toBeNull()
        ->and($row['assignment_removed'])->toBeFalse()
        ->and(json_encode($row))->not->toContain($this->employee->email);
});

it('keeps requests in archived groups, flagged with the group\'s archived_at', function () {
    $request = crsRequest($this->employee, '2026-10-20', 'acknowledged', $this->other);
    $this->other->forceFill(['archived_at' => now()])->save();

    $row = $this->actingAs($this->admin)->getJson('/api/shift-change-requests?status=all')->assertOk()->json('data.0');

    expect($row['id'])->toBe($request->id)
        ->and($row['shift']['shift_group']['archived_at'])->not->toBeNull();
});

it('counts the pending requests for the badge', function () {
    crsRequest($this->employee, '2026-10-20');
    crsRequest($this->employee, '2026-10-21');
    crsRequest($this->employee, '2026-10-22', 'acknowledged');

    $this->actingAs($this->maintainer)->getJson('/api/shift-change-requests/pending-count')
        ->assertOk()->assertExactJson(['count' => 2]);
});

it('refuses the list, the count and acknowledging to employees', function () {
    $request = crsRequest($this->employee, '2026-10-20');

    $this->actingAs($this->employee)->getJson('/api/shift-change-requests')->assertForbidden();
    $this->actingAs($this->employee)->getJson('/api/shift-change-requests/pending-count')->assertForbidden();
    $this->actingAs($this->employee)->putJson("/api/shift-change-requests/{$request->id}/acknowledge")->assertForbidden();

    expect($request->fresh()->status)->toBe('pending');
});

it('records who acknowledged a request and when, and tells the requester', function () {
    $request = crsRequest($this->employee, '2026-10-20');

    $this->actingAs($this->maintainer)->putJson("/api/shift-change-requests/{$request->id}/acknowledge")
        ->assertOk()
        ->assertJsonPath('status', 'acknowledged')
        ->assertJsonPath('acknowledged_by.name', 'Max Maintainer');

    $fresh = $request->fresh();
    expect($fresh->acknowledged_by_id)->toBe($this->maintainer->id)
        ->and($fresh->acknowledged_at->toDateTimeString())->toBe('2026-10-09 10:00:00');

    $alarms = Alarm::where('user_id', $this->employee->id)->where('type', Alarm::TYPE_SHIFT_CHANGE_REQUEST_ACKNOWLEDGED)->get();
    expect($alarms)->toHaveCount(1)
        ->and($alarms[0]->short_message)->toContain('Max Maintainer')
        ->and($alarms[0]->short_message)->toContain('2026-10-20')
        ->and($alarms[0]->link_url)->toBe('/my-shifts');
});

it('is idempotent: a second acknowledgement keeps the first one and notifies nobody again', function () {
    $request = crsRequest($this->employee, '2026-10-20');
    $this->actingAs($this->maintainer)->putJson("/api/shift-change-requests/{$request->id}/acknowledge")->assertOk();

    Carbon::setTestNow('2026-10-09 15:00:00');
    $this->actingAs($this->admin)->putJson("/api/shift-change-requests/{$request->id}/acknowledge")
        ->assertOk()
        ->assertJsonPath('status', 'acknowledged')
        ->assertJsonPath('acknowledged_by.id', $this->maintainer->id);

    $fresh = $request->fresh();
    expect($fresh->acknowledged_by_id)->toBe($this->maintainer->id)
        ->and($fresh->acknowledged_at->toDateTimeString())->toBe('2026-10-09 10:00:00')
        ->and(Alarm::where('type', Alarm::TYPE_SHIFT_CHANGE_REQUEST_ACKNOWLEDGED)->count())->toBe(1);
});

it('does not record an acknowledger on a request settled by removing its work', function () {
    $request = crsRequest($this->employee, '2026-10-20');
    $this->actingAs($this->maintainer)->deleteJson("/api/schedule/unassign/{$request->shift_assignment_id}")->assertOk();

    $this->actingAs($this->admin)->putJson("/api/shift-change-requests/{$request->id}/acknowledge")
        ->assertOk()->assertJsonPath('acknowledged_by', null)->assertJsonPath('assignment_removed', true);

    expect(Alarm::where('type', Alarm::TYPE_SHIFT_CHANGE_REQUEST_ACKNOWLEDGED)->count())->toBe(0);
});

it('does not notify a manager who acknowledges their own request', function () {
    $request = crsRequest($this->maintainer, '2026-10-20');

    $this->actingAs($this->maintainer)->putJson("/api/shift-change-requests/{$request->id}/acknowledge")->assertOk();

    expect(Alarm::where('type', Alarm::TYPE_SHIFT_CHANGE_REQUEST_ACKNOWLEDGED)->count())->toBe(0);
});

it('alerts every active schedule manager once when a request is filed, and nobody else', function () {
    $gone = User::factory()->create(['role' => 'admin', 'deactivated_at' => now()]);
    $colleague = User::factory()->create(['role' => 'employee']);
    $shift = Shift::create(['date' => '2026-10-20', 'shift_number' => 1, 'shift_group_id' => $this->group->id]);
    $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->employee->id]);

    $this->actingAs($this->employee)->postJson('/api/shift-change-requests', [
        'shift_assignment_id' => $assignment->id, 'reason' => 'Doctor appointment',
    ])->assertCreated();

    $notified = Alarm::where('type', Alarm::TYPE_EMPLOYEE_CANNOT_ATTEND_SHIFT)->pluck('user_id')->sort()->values()->all();
    expect($notified)->toBe(collect([$this->admin->id, $this->maintainer->id])->sort()->values()->all())
        ->and($notified)->not->toContain($gone->id)
        ->and($notified)->not->toContain($colleague->id);
});

it('answers 404 for an unknown request and for a non-numeric id', function () {
    $this->actingAs($this->admin)->putJson('/api/shift-change-requests/999999/acknowledge')->assertNotFound();
    $this->actingAs($this->admin)->putJson('/api/shift-change-requests/abc/acknowledge')->assertNotFound();
});

it('migrates safely over existing requests (columns added empty, and removed again)', function () {
    $migration = require database_path('migrations/2026_10_12_100000_add_acknowledged_by_to_shift_change_requests_table.php');
    $request = crsRequest($this->employee, '2026-10-20', 'acknowledged');
    $migration->down();
    expect(DB::table('shift_change_requests')->where('id', $request->id)->exists())->toBeTrue();

    $migration->up();

    $row = DB::table('shift_change_requests')->where('id', $request->id)->first();
    expect($row->status)->toBe('acknowledged')
        ->and($row->acknowledged_by_id)->toBeNull()
        ->and($row->acknowledged_at)->toBeNull();
});

it('links the filing alarm to the Change Requests screen on the shift\'s group and month', function () {
    $shift = Shift::create(['date' => '2026-10-20', 'shift_number' => 1, 'shift_group_id' => $this->other->id]);
    $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->employee->id]);

    $this->actingAs($this->employee)->postJson('/api/shift-change-requests', [
        'shift_assignment_id' => $assignment->id, 'reason' => 'Doctor appointment',
    ])->assertCreated();

    $alarm = Alarm::where('user_id', $this->admin->id)->where('type', Alarm::TYPE_EMPLOYEE_CANNOT_ATTEND_SHIFT)->sole();
    expect($alarm->link_url)->toBe("/change-requests?group={$this->other->id}&month=2026-10")
        ->and($alarm->link_label)->toBe('View request');
});

it('does not alert a manager about their own request', function () {
    $shift = Shift::create(['date' => '2026-10-20', 'shift_number' => 1, 'shift_group_id' => $this->group->id]);
    $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->maintainer->id]);

    $this->actingAs($this->maintainer)->postJson('/api/shift-change-requests', [
        'shift_assignment_id' => $assignment->id, 'reason' => 'Doctor appointment',
    ])->assertCreated();

    expect(Alarm::where('type', Alarm::TYPE_EMPLOYEE_CANNOT_ATTEND_SHIFT)->pluck('user_id')->all())->toBe([$this->admin->id]);
});

it('does not notify a requester who has been deactivated', function () {
    $request = crsRequest($this->employee, '2026-10-20');
    $this->employee->forceFill(['deactivated_at' => now()])->save();

    $this->actingAs($this->admin)->putJson("/api/shift-change-requests/{$request->id}/acknowledge")
        ->assertOk()->assertJsonPath('status', 'acknowledged');

    expect(Alarm::where('type', Alarm::TYPE_SHIFT_CHANGE_REQUEST_ACKNOWLEDGED)->count())->toBe(0);
});

it('keeps a request for today\'s shift pending, flagged and counted, when its group is archived', function () {
    $today = crsRequest($this->employee, '2026-10-09', 'pending', $this->other);
    $future = crsRequest($this->employee, '2026-10-20', 'pending', $this->other);

    $this->actingAs($this->admin)->deleteJson("/api/shift-groups/{$this->other->id}", ['remove_future_assignments' => true])->assertOk();

    expect($today->fresh()->status)->toBe('pending')
        ->and($future->fresh()->status)->toBe('acknowledged');
    $row = $this->actingAs($this->maintainer)->getJson("/api/shift-change-requests?shift_group_id={$this->other->id}")->assertOk()->json('data');
    expect(collect($row)->pluck('id')->all())->toBe([$today->id])
        ->and($row[0]['shift']['shift_group']['archived_at'])->not->toBeNull();
    $this->actingAs($this->maintainer)->getJson('/api/shift-change-requests/pending-count')->assertExactJson(['count' => 1]);
});
