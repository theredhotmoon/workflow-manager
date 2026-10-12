<?php

use App\Models\ChecklistGroup;
use App\Models\ChecklistItem;
use App\Models\ChecklistSubmission;
use App\Models\ChecklistTemplate;
use App\Models\Delegation;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftChecklist;
use App\Models\ShiftGroup;
use App\Models\User;
use App\Services\SettlementPeriodCalculator;
use App\Support\BusinessDate;
use Illuminate\Support\Carbon;

// Shift dates and times are Warsaw wall-clock; the app clock and storage are UTC. Every
// setTestNow below is a UTC instant whose Warsaw day or hour differs from the UTC one.

beforeEach(function () {
    // The expected offsets (+01:00/+02:00) and DST days below are Warsaw's.
    config(['app.business_timezone' => 'Europe/Warsaw']);
});

function btzGroup(): ShiftGroup
{
    return ShiftGroup::firstOrCreate(['name' => 'Main'], ['is_default' => true, 'sort_order' => 0]);
}

function btzShift(string $date, ?string $start, ?string $end, int $number = 1): Shift
{
    return Shift::create([
        'date' => $date,
        'shift_number' => $number,
        'shift_group_id' => btzGroup()->id,
        'start_time' => $start,
        'end_time' => $end,
    ]);
}

/** A template with one checkbox item, required on $shift with a deadline of $minutes after $reference. */
function btzChecklist(Shift $shift, int $minutes = 30, string $reference = 'shift_start'): array
{
    $template = ChecklistTemplate::create(['name' => 'Handover', 'is_active' => true]);
    $group = ChecklistGroup::create(['checklist_template_id' => $template->id, 'name' => 'Doors', 'sort_order' => 0]);
    $item = ChecklistItem::create(['checklist_group_id' => $group->id, 'label' => 'Door locked', 'type' => 'checkbox', 'sort_order' => 0]);
    ShiftChecklist::create([
        'shift_id' => $shift->id,
        'checklist_template_id' => $template->id,
        'is_required' => true,
        'deadline_minutes' => $minutes,
        'deadline_reference' => $reference,
    ]);

    return compact('template', 'item');
}

function btzAssign(Shift $shift, ?User $user = null): User
{
    $user ??= User::factory()->create();
    ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id]);

    return $user;
}

function btzAlerts($test, User $user): array
{
    return $test->actingAs($user)->getJson('/api/my-checklist-alerts')->assertOk()->json();
}

it('counts the minutes left to a deadline in Warsaw time', function () {
    // 04:10 UTC is 06:10 in Warsaw (summer time): a 06:00 shift with a 30-minute deadline has 20 left.
    Carbon::setTestNow('2026-07-15 04:10:00');
    $shift = btzShift('2026-07-15', '06:00', '14:00');
    btzChecklist($shift);
    $user = btzAssign($shift);

    $alerts = btzAlerts($this, $user);

    expect($alerts['current_shift_alerts'])->toHaveCount(1);
    expect($alerts['current_shift_alerts'][0]['minutes_remaining'])->toBe(20);
    expect($alerts['current_shift_alerts'][0]['deadline_at'])->toBe('2026-07-15T06:30:00+02:00');
    expect($alerts['overdue_alerts'])->toBe([]);
});

it('sends my-shifts deadlines with the Warsaw offset', function () {
    Carbon::setTestNow('2026-07-15 04:10:00');
    $shift = btzShift('2026-07-15', '06:00', '14:00');
    btzChecklist($shift);
    $user = btzAssign($shift);

    $this->actingAs($user)->getJson('/api/my-shifts?month=2026-07')
        ->assertOk()
        ->assertJsonPath('2026-07-15.0.shift_checklists.0.deadline_at', '2026-07-15T06:30:00+02:00');
});

it('flags a handover submitted after the Warsaw deadline as late, and one before it as on time', function () {
    $shift = btzShift('2026-07-15', '06:00', '14:00');
    $t = btzChecklist($shift);
    $user = btzAssign($shift);
    $submit = fn () => $this->actingAs($user)->postJson('/api/checklist-submissions', [
        'checklist_template_id' => $t['template']->id,
        'shift_id' => $shift->id,
        'date' => '2026-07-15',
        'outgoing_user_id' => $user->id,
        'responses' => [['checklist_item_id' => $t['item']->id, 'value' => true]],
    ])->assertCreated()->json('id');

    // 05:40 UTC is 07:40 in Warsaw: 70 minutes past the 06:30 deadline.
    Carbon::setTestNow('2026-07-15 05:40:00');
    expect(ChecklistSubmission::find($submit())->submitted_on_time)->toBeFalse();

    // 04:20 UTC is 06:20 in Warsaw: before the deadline.
    Carbon::setTestNow('2026-07-15 04:20:00');
    expect(ChecklistSubmission::find($submit())->submitted_on_time)->toBeTrue();
});

it('reports the day shift as current at 07:00 Warsaw, not the night shift that ended at 06:00', function () {
    // 05:00 UTC is 07:00 in Warsaw.
    Carbon::setTestNow('2026-07-15 05:00:00');
    $night = btzShift('2026-07-14', '22:00', '06:00', 3);
    $day = btzShift('2026-07-15', '06:00', '14:00', 1);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/current-shift')
        ->assertOk()
        ->assertJsonPath('active_shift.id', $day->id);

    // 03:00 UTC is 05:00 in Warsaw: the night shift is still on.
    Carbon::setTestNow('2026-07-15 03:00:00');
    $this->actingAs($user)->getJson('/api/current-shift')
        ->assertOk()
        ->assertJsonPath('active_shift.id', $night->id)
        ->assertJsonPath('active_shift.is_overnight', true);
});

it('finds a shift starting at midnight on the Warsaw date while UTC is still the day before', function () {
    // 22:30 UTC on the 14th is 00:30 on the 15th in Warsaw.
    Carbon::setTestNow('2026-07-14 22:30:00');
    $early = btzShift('2026-07-15', '00:00', '08:00');

    $this->actingAs(User::factory()->create())->getJson('/api/current-shift')
        ->assertOk()
        ->assertJsonPath('active_shift.id', $early->id);
});

it('takes "today" as the Warsaw date between local midnight and UTC midnight', function () {
    // 22:30 UTC on the 14th is 00:30 on the 15th in Warsaw.
    Carbon::setTestNow('2026-07-14 22:30:00');

    expect(BusinessDate::todayString())->toBe('2026-07-15');

    $shift = btzShift('2026-07-15', '06:00', '14:00');
    btzChecklist($shift);
    $user = btzAssign($shift);
    $alerts = btzAlerts($this, $user);
    expect(collect($alerts['current_shift_alerts'])->pluck('shift_id')->all())->toBe([$shift->id]);
});

it('refuses a day-off request for a shift that is already yesterday in Warsaw', function () {
    // 22:30 UTC on the 14th is 00:30 on the 15th in Warsaw: the 14th is past.
    Carbon::setTestNow('2026-07-14 22:30:00');
    $yesterday = btzShift('2026-07-14', '06:00', '14:00');
    $today = btzShift('2026-07-15', '06:00', '14:00', 2);
    $user = User::factory()->create();
    $past = ShiftAssignment::create(['shift_id' => $yesterday->id, 'user_id' => $user->id]);
    $current = ShiftAssignment::create(['shift_id' => $today->id, 'user_id' => $user->id]);

    $this->actingAs($user)->postJson('/api/shift-change-requests', ['shift_assignment_id' => $past->id, 'reason' => 'Doctor'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot request changes for past shifts.');
    $this->actingAs($user)->postJson('/api/shift-change-requests', ['shift_assignment_id' => $current->id, 'reason' => 'Doctor'])
        ->assertCreated();
});

it('ends an overnight shift at its end time on the next day', function () {
    $shift = btzShift('2026-07-14', '22:00', '06:00');

    expect(BusinessDate::shiftStart($shift)->toIso8601String())->toBe('2026-07-14T22:00:00+02:00');
    expect(BusinessDate::shiftEnd($shift)->toIso8601String())->toBe('2026-07-15T06:00:00+02:00');
});

it('keeps an overnight shift current, not overdue, until it ends the next morning', function () {
    $shift = btzShift('2026-07-14', '22:00', '06:00');
    btzChecklist($shift, 30, 'shift_end');
    $user = btzAssign($shift);

    // 00:30 and 03:00 in Warsaw on the 15th: the night shift of the 14th is still on.
    foreach (['2026-07-14 22:30:00', '2026-07-15 01:00:00'] as $instant) {
        Carbon::setTestNow($instant);
        $alerts = btzAlerts($this, $user);
        expect(collect($alerts['current_shift_alerts'])->pluck('shift_id')->all())->toBe([$shift->id]);
        expect($alerts['overdue_alerts'])->toBe([]);
    }
    // Its deadline is 30 minutes before the 06:00 end: at 03:00 Warsaw that is 150 minutes away.
    expect($alerts['current_shift_alerts'][0]['minutes_remaining'])->toBe(150);
    expect($alerts['current_shift_alerts'][0]['deadline_at'])->toBe('2026-07-15T05:30:00+02:00');

    // 04:30 UTC is 06:30 in Warsaw: the shift is over and the handover is overdue.
    Carbon::setTestNow('2026-07-15 04:30:00');
    $alerts = btzAlerts($this, $user);
    expect($alerts['current_shift_alerts'])->toBe([]);
    expect(collect($alerts['overdue_alerts'])->pluck('shift_id')->all())->toBe([$shift->id]);
});

it('places shifts on the DST change days at the right UTC instant', function () {
    // Spring forward: 2029-03-25 02:00 CET becomes 03:00 CEST.
    $before = btzShift('2029-03-24', '06:00', '14:00');
    $springDay = btzShift('2029-03-25', '06:00', '14:00', 2);
    $springNight = btzShift('2029-03-24', '22:00', '06:00', 3);
    expect(BusinessDate::shiftStart($before)->utc()->toIso8601String())->toBe('2029-03-24T05:00:00+00:00');
    expect(BusinessDate::shiftStart($springDay)->utc()->toIso8601String())->toBe('2029-03-25T04:00:00+00:00');
    expect(BusinessDate::shiftStart($springNight)->utc()->toIso8601String())->toBe('2029-03-24T21:00:00+00:00');
    expect(BusinessDate::shiftEnd($springNight)->utc()->toIso8601String())->toBe('2029-03-25T04:00:00+00:00');

    // Fall back: 2029-10-28 03:00 CEST becomes 02:00 CET.
    $fallDay = btzShift('2029-10-28', '06:00', '14:00', 4);
    $fallNight = btzShift('2029-10-27', '22:00', '06:00', 5);
    expect(BusinessDate::shiftStart($fallDay)->utc()->toIso8601String())->toBe('2029-10-28T05:00:00+00:00');
    expect(BusinessDate::shiftStart($fallNight)->utc()->toIso8601String())->toBe('2029-10-27T20:00:00+00:00');
    expect(BusinessDate::shiftEnd($fallNight)->utc()->toIso8601String())->toBe('2029-10-28T05:00:00+00:00');
});

it('computes deadlines on the DST change days in Warsaw time', function () {
    $spring = btzShift('2029-03-25', '06:00', '14:00');
    btzChecklist($spring);
    $fall = btzShift('2029-10-28', '06:00', '14:00');
    btzChecklist($fall);
    $user = btzAssign($spring);
    btzAssign($fall, $user);

    // 04:10 UTC on the spring day is 06:10 CEST: 20 minutes left.
    Carbon::setTestNow('2029-03-25 04:10:00');
    $alert = btzAlerts($this, $user)['current_shift_alerts'][0];
    expect($alert['deadline_at'])->toBe('2029-03-25T06:30:00+02:00');
    expect($alert['minutes_remaining'])->toBe(20);

    // 05:10 UTC on the fall day is 06:10 CET: 20 minutes left.
    Carbon::setTestNow('2029-10-28 05:10:00');
    $alert = btzAlerts($this, $user)['current_shift_alerts'][0];
    expect($alert['deadline_at'])->toBe('2029-10-28T06:30:00+01:00');
    expect($alert['minutes_remaining'])->toBe(20);
});

it('picks the current settlement period by the Warsaw date', function () {
    // 22:30 UTC on 7 Sep is 00:30 on 8 Sep in Warsaw: a new weekly period from the 1 Sep anchor.
    Carbon::setTestNow('2026-09-07 22:30:00');
    $group = ShiftGroup::create([
        'name' => 'Weekly',
        'sort_order' => 1,
        'settlement_mode' => 'weekly',
        'settlement_anchor_date' => '2026-09-01',
        'weekly_hour_cap' => 40,
    ]);

    $period = (new SettlementPeriodCalculator)->periodByOffset($group, 0);

    expect($period['start']->toDateString())->toBe('2026-09-08');
    expect($period['end']->toDateString())->toBe('2026-09-14');
});

it('counts deadline minutes in elapsed time when the window crosses a DST change', function () {
    // Fall back (2029-10-28 03:00 CEST -> 02:00 CET): 22:00 CEST on the 27th is 20:00Z; 300 real
    // minutes later is 01:00Z (02:00 CET), not 03:00 on the wall clock.
    $fall = btzShift('2029-10-27', '22:00', '06:00');
    btzChecklist($fall, 300, 'shift_start');
    $deadline = ShiftChecklist::where('shift_id', $fall->id)->first()->getDeadlineDatetime($fall);
    expect($deadline->toIso8601String())->toBe('2029-10-28T02:00:00+01:00');
    expect($deadline->copy()->utc()->toIso8601String())->toBe('2029-10-28T01:00:00+00:00');

    // Spring forward (2029-03-25 02:00 CET -> 03:00 CEST): the shift ends 06:00 CEST = 04:00Z;
    // 240 real minutes earlier is 00:00Z (01:00 CET), not 02:00 on the wall clock.
    $spring = btzShift('2029-03-24', '22:00', '06:00', 2);
    btzChecklist($spring, 240, 'shift_end');
    $deadline = ShiftChecklist::where('shift_id', $spring->id)->first()->getDeadlineDatetime($spring);
    expect($deadline->toIso8601String())->toBe('2029-03-25T01:00:00+01:00');
    expect($deadline->copy()->utc()->toIso8601String())->toBe('2029-03-25T00:00:00+00:00');

    // The same through the API: at 23:30Z on the 24th (00:30 CET) the deadline is 30 minutes away.
    $user = btzAssign($spring);
    Carbon::setTestNow('2029-03-24 23:30:00');
    $alert = btzAlerts($this, $user)['current_shift_alerts'][0];
    expect($alert['deadline_at'])->toBe('2029-03-25T01:00:00+01:00');
    expect($alert['minutes_remaining'])->toBe(30);
});

it('resolves shift times that do not exist or repeat on the DST days deterministically', function () {
    // 02:30 does not exist on the spring-forward day: it rolls forward to 03:30 CEST.
    $gap = btzShift('2029-03-25', '02:30', '10:00');
    expect(BusinessDate::shiftStart($gap)->toIso8601String())->toBe('2029-03-25T03:30:00+02:00');

    // 02:30 happens twice on the fall-back day: it is taken as the second one, in CET (+01:00).
    $repeat = btzShift('2029-10-28', '02:30', '10:00', 2);
    expect(BusinessDate::shiftStart($repeat)->toIso8601String())->toBe('2029-10-28T02:30:00+01:00');
});

it('keeps a delegation open through its last day in Warsaw', function () {
    $user = User::factory()->create();
    $delegation = Delegation::create([
        'name' => 'Expo',
        'destination' => 'Berlin',
        'start_datetime' => '2026-07-10',
        'end_datetime' => '2026-07-15',
        'registration_open' => true,
        'created_by' => $user->id,
    ]);
    $openIds = fn () => collect($this->actingAs($user)->getJson('/api/delegations/open')->assertOk()->json())->pluck('id')->all();

    // 10:00 UTC on the 15th: its last day, still open.
    Carbon::setTestNow('2026-07-15 10:00:00');
    expect($openIds())->toBe([$delegation->id]);

    // 22:30 UTC on the 15th is 00:30 on the 16th in Warsaw: over.
    Carbon::setTestNow('2026-07-15 22:30:00');
    expect($openIds())->toBe([]);
});
