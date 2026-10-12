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
use Illuminate\Support\Carbon;

// F8: a handover filed without a shift still clears that day's alert for the template (the form's
// shift is optional), but one that was filed for a shift that was later removed (shift_id nulled)
// no longer clears the alert of another slot that day.

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-09 08:00:00', 'UTC')); // 10:00 in Warsaw
    $this->user = User::factory()->create();
    $this->template = ChecklistTemplate::create(['name' => 'Handover', 'is_active' => true]);
    $checklistGroup = ChecklistGroup::create(['checklist_template_id' => $this->template->id, 'name' => 'Doors', 'sort_order' => 0]);
    $this->item = ChecklistItem::create(['checklist_group_id' => $checklistGroup->id, 'label' => 'Locked', 'type' => 'checkbox', 'sort_order' => 0]);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $this->slot = fn (int $number, string $start, string $end) => tap(Shift::create([
        'date' => '2026-10-09', 'shift_number' => $number, 'shift_group_id' => $group->id, 'start_time' => $start, 'end_time' => $end,
    ]), function (Shift $shift) {
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->user->id]);
        ShiftChecklist::create(['shift_id' => $shift->id, 'checklist_template_id' => $this->template->id, 'is_required' => true, 'sort_order' => 0]);
    });
});

function cafFile(?Shift $shift): void
{
    test()->actingAs(test()->user)->postJson('/api/checklist-submissions', [
        'checklist_template_id' => test()->template->id,
        'shift_id' => $shift?->id,
        'date' => '2026-10-09',
        'outgoing_user_id' => test()->user->id,
        'responses' => [['checklist_item_id' => test()->item->id, 'value' => true]],
    ])->assertCreated();
}

function cafAlertShiftIds(): array
{
    return collect(test()->actingAs(test()->user)->getJson('/api/my-checklist-alerts')->assertOk()->json('current_shift_alerts'))
        ->pluck('shift_id')->all();
}

it('lets a handover filed without a shift clear the day\'s alert for its template', function () {
    $morning = ($this->slot)(1, '06:00', '14:00');
    expect(cafAlertShiftIds())->toBe([$morning->id]);

    cafFile(null);

    expect(cafAlertShiftIds())->toBe([]);
});

it('does not let a handover of a removed slot clear another slot\'s alert that day', function () {
    $removed = ($this->slot)(1, '00:00', '06:00');
    $morning = ($this->slot)(2, '06:00', '14:00');
    cafFile($removed);

    // The slot is removed: the handover stays, its shift link nulled.
    $removed->delete();
    expect(ChecklistSubmission::sole()->shift_id)->toBeNull();

    expect(cafAlertShiftIds())->toBe([$morning->id]);
});
