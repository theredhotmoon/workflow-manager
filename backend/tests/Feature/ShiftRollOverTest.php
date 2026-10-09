<?php

use App\Models\ChecklistGroup;
use App\Models\ChecklistItem;
use App\Models\ChecklistSubmission;
use App\Models\ChecklistTemplate;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftChecklist;
use App\Models\ShiftGroup;
use App\Models\User;
use App\Services\SettlementPeriodCalculator;
use App\Support\BusinessDate;
use Illuminate\Support\Carbon;

// M11: a shift whose end is at or before its start ends the next day, everywhere. A 24-hour
// shift (00:00-00:00, the 1-shift-per-day preset) ends at 00:00 the next day, not at the start
// of its own day.

beforeEach(function () {
    config(['app.business_timezone' => 'Europe/Warsaw']);
});

function rollShift(string $date, ?string $start, ?string $end): Shift
{
    $group = ShiftGroup::firstOrCreate(['name' => 'Main'], ['is_default' => true, 'sort_order' => 0]);

    return Shift::create([
        'date' => $date,
        'shift_number' => 1,
        'shift_group_id' => $group->id,
        'start_time' => $start,
        'end_time' => $end,
    ]);
}

function rollChecklist(Shift $shift, int $minutes, string $reference): array
{
    $template = ChecklistTemplate::create(['name' => 'Handover', 'is_active' => true]);
    $group = ChecklistGroup::create(['checklist_template_id' => $template->id, 'name' => 'Doors', 'sort_order' => 0]);
    $item = ChecklistItem::create(['checklist_group_id' => $group->id, 'label' => 'Door locked', 'type' => 'checkbox', 'sort_order' => 0]);
    $sc = ShiftChecklist::create([
        'shift_id' => $shift->id,
        'checklist_template_id' => $template->id,
        'is_required' => true,
        'deadline_minutes' => $minutes,
        'deadline_reference' => $reference,
    ]);

    return compact('template', 'item', 'sc');
}

it('ends a 00:00-00:00 shift at 00:00 the next day', function () {
    $shift = rollShift('2026-10-09', '00:00', '00:00');

    expect(BusinessDate::shiftEnd($shift)->toIso8601String())->toBe('2026-10-10T00:00:00+02:00');
    expect(BusinessDate::endsNextDay('00:00:00', '00:00:00'))->toBeTrue();
});

it('keeps a 22:00-06:00 night shift ending at 06:00 the next day, and a day shift on its own day', function () {
    expect(BusinessDate::shiftEnd(rollShift('2026-10-09', '22:00', '06:00'))->toIso8601String())
        ->toBe('2026-10-10T06:00:00+02:00');
    expect(BusinessDate::endsNextDay('06:00', '14:00'))->toBeFalse();
    expect(BusinessDate::endsNextDay(null, '14:00'))->toBeFalse();
});

it('puts the "30 min before shift end" deadline of a 24-hour shift at 23:30 on its own day', function () {
    $shift = rollShift('2026-10-09', '00:00', '00:00');
    $t = rollChecklist($shift, 30, 'shift_end');

    expect($t['sc']->getDeadlineDatetime($shift)->toIso8601String())->toBe('2026-10-09T23:30:00+02:00');

    // 10:00 UTC = 12:00 Warsaw: the alert is current with 11.5 h to go, not overdue.
    Carbon::setTestNow('2026-10-09 10:00:00');
    $user = User::factory()->create();
    ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id]);
    $alerts = $this->actingAs($user)->getJson('/api/my-checklist-alerts')->assertOk()->json();
    expect($alerts['current_shift_alerts'][0]['deadline_at'])->toBe('2026-10-09T23:30:00+02:00');
    expect($alerts['current_shift_alerts'][0]['minutes_remaining'])->toBe(690);
});

it('stores a submission at 12:00 during a 24-hour shift as on time', function () {
    $shift = rollShift('2026-10-09', '00:00', '00:00');
    $t = rollChecklist($shift, 30, 'shift_end');
    $user = User::factory()->create();
    ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id]);

    Carbon::setTestNow('2026-10-09 10:00:00'); // 12:00 Warsaw
    $id = $this->actingAs($user)->postJson('/api/checklist-submissions', [
        'checklist_template_id' => $t['template']->id,
        'shift_id' => $shift->id,
        'date' => '2026-10-09',
        'outgoing_user_id' => $user->id,
        'responses' => [['checklist_item_id' => $t['item']->id, 'value' => true]],
    ])->assertCreated()->json('id');

    expect(ChecklistSubmission::find($id)->submitted_on_time)->toBeTrue();
});

it('counts a 24-hour shift as 24 h, and 25 h on the Warsaw fall-back day', function () {
    $calc = new SettlementPeriodCalculator();

    expect($calc->shiftTiming(rollShift('2026-10-09', '00:00', '00:00'))['duration_hours'])->toEqual(24);

    // 2026-10-25: clocks go back 03:00 -> 02:00, the local day lasts 25 hours.
    $dst = rollShift('2026-10-25', '00:00', '00:00');
    expect(BusinessDate::shiftEnd($dst)->toIso8601String())->toBe('2026-10-26T00:00:00+01:00');
    expect($calc->shiftTiming($dst)['duration_hours'])->toEqual(25);
    expect($calc->shiftTiming(rollShift('2026-10-24', '22:00', '06:00'))['duration_hours'])->toEqual(9);
});
