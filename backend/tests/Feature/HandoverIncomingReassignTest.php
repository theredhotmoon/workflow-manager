<?php

use App\Models\ChecklistGroup;
use App\Models\ChecklistItem;
use App\Models\ChecklistResponse;
use App\Models\ChecklistSubmission;
use App\Models\ChecklistTemplate;
use App\Models\User;

// F7: a pending handover whose incoming user was deactivated could never be confirmed. An admin
// may hand it to another active user; the handover records who it was handed over from, by whom
// and when, and the new incoming user confirms it as usual.

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->outgoing = User::factory()->create(['name' => 'Olga Outgoing']);
    $this->gone = User::factory()->create(['name' => 'Gus Gone']);
    $this->replacement = User::factory()->create(['name' => 'Rita Replacement']);
    $template = ChecklistTemplate::create(['name' => 'Handover', 'is_active' => true]);
    $this->submission = ChecklistSubmission::create([
        'checklist_template_id' => $template->id, 'date' => '2026-10-08',
        'outgoing_user_id' => $this->outgoing->id, 'incoming_user_id' => $this->gone->id,
        'status' => 'pending_confirmation', 'submitted_at' => now(), 'submitted_by_user_id' => $this->outgoing->id,
    ]);
    $group = ChecklistGroup::create(['checklist_template_id' => $template->id, 'name' => 'Keys', 'sort_order' => 0]);
    $item = ChecklistItem::create(['checklist_group_id' => $group->id, 'label' => 'Keys handed over', 'type' => 'checkbox', 'requires_confirmation' => true, 'sort_order' => 0]);
    $this->response = ChecklistResponse::create(['checklist_submission_id' => $this->submission->id, 'checklist_item_id' => $item->id, 'value' => 'true']);
    $this->gone->forceFill(['deactivated_at' => now()])->save();
});

function hirReassign(User $as, int $userId)
{
    return test()->actingAs($as)->putJson('/api/checklist-submissions/' . test()->submission->id . '/incoming', ['incoming_user_id' => $userId]);
}

it('lets an admin hand a pending handover of a deactivated incoming user to someone active', function () {
    hirReassign($this->admin, $this->replacement->id)
        ->assertOk()
        ->assertJsonPath('incoming_user.id', $this->replacement->id)
        ->assertJsonPath('previous_incoming_user.name', 'Gus Gone')
        ->assertJsonPath('incoming_reassigned_by.id', $this->admin->id);

    $fresh = $this->submission->fresh();
    expect($fresh->incoming_user_id)->toBe($this->replacement->id)
        ->and($fresh->previous_incoming_user_id)->toBe($this->gone->id)
        ->and($fresh->incoming_reassigned_by_id)->toBe($this->admin->id)
        ->and($fresh->incoming_reassigned_at)->not->toBeNull()
        ->and($fresh->status)->toBe('pending_confirmation');

    // The new incoming user can now confirm it.
    $this->actingAs($this->replacement)->putJson("/api/checklist-submissions/{$this->submission->id}/confirm", [
        'confirmed_item_ids' => [$this->response->id],
    ])->assertOk()->assertJsonPath('status', 'completed');
});

it('shows the handover history on the handover', function () {
    hirReassign($this->admin, $this->replacement->id)->assertOk();

    $this->actingAs($this->replacement)->getJson("/api/checklist-submissions/{$this->submission->id}")->assertOk()
        ->assertJsonPath('previous_incoming_user.name', 'Gus Gone')
        ->assertJsonPath('incoming_reassigned_by.name', $this->admin->name);
});

it('refuses while the incoming user is still active', function () {
    $this->gone->forceFill(['deactivated_at' => null])->save();

    hirReassign($this->admin, $this->replacement->id)->assertUnprocessable()
        ->assertJsonPath('message', 'Only a handover whose incoming user was deactivated can be handed to someone else.');
});

it('refuses a handover that is not waiting for confirmation', function () {
    $this->submission->update(['status' => 'completed']);

    hirReassign($this->admin, $this->replacement->id)->assertUnprocessable();
});

it('refuses a deactivated, outgoing or self new incoming user', function () {
    $alsoGone = User::factory()->create(['deactivated_at' => now()]);

    hirReassign($this->admin, $alsoGone->id)->assertUnprocessable()->assertJsonValidationErrors('incoming_user_id');
    hirReassign($this->admin, $this->outgoing->id)->assertUnprocessable()->assertJsonValidationErrors('incoming_user_id');
    hirReassign($this->admin, $this->admin->id)->assertUnprocessable()->assertJsonValidationErrors('incoming_user_id');

    expect($this->submission->fresh()->incoming_user_id)->toBe($this->gone->id);
});

it('is for admins only', function () {
    hirReassign($this->outgoing, $this->replacement->id)->assertForbidden();
});
