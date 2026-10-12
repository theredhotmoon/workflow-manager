<?php

use App\Models\ChecklistTemplate;
use App\Models\Shift;
use App\Models\ShiftChecklist;
use App\Models\ShiftGroup;
use App\Models\User;

// L17: a shift's checklist list is replaced all-or-nothing, and one template can't be listed twice.

beforeEach(function () {
    $this->manager = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $this->shift = Shift::create(['date' => '2026-10-20', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    $this->handover = ChecklistTemplate::create(['name' => 'Handover', 'is_active' => true]);
    $this->safety = ChecklistTemplate::create(['name' => 'Safety', 'is_active' => true]);
    ShiftChecklist::create(['shift_id' => $this->shift->id, 'checklist_template_id' => $this->handover->id, 'is_required' => true, 'sort_order' => 0]);
});

it('refuses the same template twice on one shift', function () {
    $this->actingAs($this->manager)->putJson("/api/schedule/shifts/{$this->shift->id}/checklist", ['checklists' => [
        ['checklist_template_id' => $this->safety->id, 'is_required' => true],
        ['checklist_template_id' => $this->safety->id, 'is_required' => false],
    ]])->assertUnprocessable()->assertJsonValidationErrors('checklists.1.checklist_template_id');

    expect(ShiftChecklist::pluck('checklist_template_id')->all())->toBe([$this->handover->id]);
});

it('keeps the old checklists when saving the new ones fails part-way', function () {
    // The second insert fails, after the old rows were deleted and the first new one inserted.
    $inserted = 0;
    ShiftChecklist::creating(function () use (&$inserted) {
        if (++$inserted === 2) {
            throw new RuntimeException('disk full');
        }
    });

    $this->actingAs($this->manager)->putJson("/api/schedule/shifts/{$this->shift->id}/checklist", ['checklists' => [
        ['checklist_template_id' => $this->safety->id, 'is_required' => true],
        ['checklist_template_id' => $this->handover->id, 'is_required' => false],
    ]])->assertServerError();

    expect(ShiftChecklist::get(['checklist_template_id', 'is_required'])->toArray())
        ->toBe([['checklist_template_id' => $this->handover->id, 'is_required' => true]]);
});

it('replaces the list when it is valid', function () {
    $this->actingAs($this->manager)->putJson("/api/schedule/shifts/{$this->shift->id}/checklist", ['checklists' => [
        ['checklist_template_id' => $this->safety->id, 'is_required' => false],
        ['checklist_template_id' => $this->handover->id, 'is_required' => true],
    ]])->assertOk()->assertJsonCount(2, 'shift_checklists');

    expect(ShiftChecklist::orderBy('sort_order')->pluck('checklist_template_id')->all())->toBe([$this->safety->id, $this->handover->id]);
});
