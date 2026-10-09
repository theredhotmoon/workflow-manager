<?php

use App\Models\ChecklistGroup;
use App\Models\ChecklistItem;
use App\Models\ChecklistResponse;
use App\Models\ChecklistSubmission;
use App\Models\ChecklistTemplate;
use App\Models\Shift;
use App\Models\ShiftChecklist;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

function cdlAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

/** A template with two groups (two items, one item) and one submission answering every item. */
function cdlTemplateWithSubmission(User $admin, array $templateFields = []): array
{
    $template = ChecklistTemplate::create(array_merge(['name' => 'Handover', 'is_active' => true], $templateFields));
    $g1 = ChecklistGroup::create(['checklist_template_id' => $template->id, 'name' => 'Doors', 'sort_order' => 0]);
    $g2 = ChecklistGroup::create(['checklist_template_id' => $template->id, 'name' => 'Logs', 'sort_order' => 1]);
    $front = ChecklistItem::create(['checklist_group_id' => $g1->id, 'label' => 'Front door locked', 'type' => 'checkbox', 'sort_order' => 0]);
    $back = ChecklistItem::create(['checklist_group_id' => $g1->id, 'label' => 'Back door locked', 'type' => 'checkbox', 'sort_order' => 1]);
    $log = ChecklistItem::create(['checklist_group_id' => $g2->id, 'label' => 'Incidents logged', 'type' => 'text', 'sort_order' => 0]);

    $submission = ChecklistSubmission::create([
        'checklist_template_id' => $template->id,
        'date' => '2026-10-01',
        'outgoing_user_id' => $admin->id,
        'status' => 'completed',
        'submitted_at' => now(),
    ]);
    foreach ([[$front, 'true'], [$back, 'true'], [$log, 'all quiet']] as [$item, $value]) {
        ChecklistResponse::create(['checklist_submission_id' => $submission->id, 'checklist_item_id' => $item->id, 'value' => $value]);
    }

    return compact('template', 'g1', 'g2', 'front', 'back', 'log', 'submission');
}

/** The builder's PUT payload for $t: rename Doors, relabel Front, drop Back, add a new item. */
function cdlEditPayload(array $t, array $extra = []): array
{
    return array_merge([
        'name' => 'Handover',
        'description' => null,
        'groups' => [
            [
                'id' => $t['g1']->id,
                'name' => 'Doors and windows',
                'items' => [
                    ['id' => $t['front']->id, 'label' => 'Front door locked and alarmed', 'type' => 'checkbox'],
                    ['label' => 'Windows closed', 'type' => 'checkbox'],
                ],
            ],
            [
                'id' => $t['g2']->id,
                'name' => 'Logs',
                'items' => [
                    ['id' => $t['log']->id, 'label' => 'Incidents logged', 'type' => 'text'],
                ],
            ],
        ],
    ], $extra);
}

function cdlItemLabels(array $template): array
{
    return collect($template['groups'])->flatMap(fn ($g) => collect($g['items'])->pluck('label'))->all();
}

it('keeps every past response when a template is edited, retiring the removed item', function () {
    $admin = cdlAdmin();
    $t = cdlTemplateWithSubmission($admin);
    $responseIds = ChecklistResponse::pluck('id')->sort()->values()->all();

    $this->actingAs($admin)
        ->putJson("/api/checklist-templates/{$t['template']->id}", cdlEditPayload($t))
        ->assertOk();

    // Every response row survives, still pointing at the same items.
    expect(ChecklistResponse::pluck('id')->sort()->values()->all())->toBe($responseIds);

    // Existing rows were updated in place (same ids), the removed one retired, the new one inserted.
    expect($t['g1']->fresh()->name)->toBe('Doors and windows');
    expect($t['front']->fresh()->label)->toBe('Front door locked and alarmed');
    expect($t['front']->fresh()->archived_at)->toBeNull();
    expect($t['back']->fresh()->archived_at)->not->toBeNull();
    expect(ChecklistItem::where('label', 'Windows closed')->value('checklist_group_id'))->toBe($t['g1']->id);

    // As served for filling, the retired item is gone and the new one is in place, in order.
    $active = $this->actingAs($admin)->getJson('/api/checklist-templates/active')->assertOk()
        ->json('0');
    expect(cdlItemLabels($active))->toBe(['Front door locked and alarmed', 'Windows closed', 'Incidents logged']);
    $shown = $this->actingAs($admin)->getJson("/api/checklist-templates/{$t['template']->id}")->assertOk()->json();
    expect(cdlItemLabels($shown))->toBe(['Front door locked and alarmed', 'Windows closed', 'Incidents logged']);

    // The past submission still shows the removed item with its label and answer.
    $detail = $this->actingAs($admin)->getJson("/api/checklist-submissions/{$t['submission']->id}")->assertOk()->json();
    expect(cdlItemLabels($detail['template']))->toContain('Back door locked');
    expect(collect($detail['responses'])->firstWhere('checklist_item_id', $t['back']->id)['value'])->toBe('true');
});

it('retires a removed group with its items and keeps their responses', function () {
    $admin = cdlAdmin();
    $t = cdlTemplateWithSubmission($admin);
    $payload = cdlEditPayload($t);
    array_pop($payload['groups']); // drop "Logs"

    $this->actingAs($admin)->putJson("/api/checklist-templates/{$t['template']->id}", $payload)->assertOk();

    expect($t['g2']->fresh()->archived_at)->not->toBeNull();
    expect($t['log']->fresh()->archived_at)->not->toBeNull();
    expect(ChecklistResponse::where('checklist_item_id', $t['log']->id)->value('value'))->toBe('all quiet');

    $active = $this->actingAs($admin)->getJson('/api/checklist-templates/active')->json('0');
    expect(collect($active['groups'])->pluck('name')->all())->toBe(['Doors and windows']);

    $detail = $this->actingAs($admin)->getJson("/api/checklist-submissions/{$t['submission']->id}")->json();
    expect(collect($detail['template']['groups'])->pluck('name')->all())->toContain('Logs');
});

it('rejects group or item ids that belong to another template', function () {
    $admin = cdlAdmin();
    $t = cdlTemplateWithSubmission($admin);
    $other = cdlTemplateWithSubmission($admin, ['name' => 'Other']);
    $payload = cdlEditPayload($t);
    $payload['groups'][1]['items'][0]['id'] = $other['log']->id;

    $this->actingAs($admin)->putJson("/api/checklist-templates/{$t['template']->id}", $payload)->assertStatus(422);

    expect($other['log']->fresh()->checklist_group_id)->toBe($other['g2']->id);
});

it('does not re-activate a deactivated template when it is edited (L16)', function () {
    $admin = cdlAdmin();
    $t = cdlTemplateWithSubmission($admin, ['is_active' => false]);

    $this->actingAs($admin)->putJson("/api/checklist-templates/{$t['template']->id}", cdlEditPayload($t))->assertOk();

    expect($t['template']->fresh()->is_active)->toBeFalse();
});

it('archives a template instead of deleting it, keeping its handovers', function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
    $admin = cdlAdmin();
    $t = cdlTemplateWithSubmission($admin);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $past = Shift::create(['date' => '2026-10-01', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    $today = Shift::create(['date' => '2026-10-09', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    $future = Shift::create(['date' => '2026-10-20', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    foreach ([$past, $today, $future] as $shift) {
        ShiftChecklist::create(['shift_id' => $shift->id, 'checklist_template_id' => $t['template']->id, 'is_required' => true]);
    }

    $this->actingAs($admin)->deleteJson("/api/checklist-templates/{$t['template']->id}")
        ->assertOk()
        ->assertJson(['message' => 'Template archived.']);

    expect($t['template']->fresh())->not->toBeNull();
    expect($t['template']->fresh()->archived_at)->not->toBeNull();
    expect(ChecklistSubmission::whereKey($t['submission']->id)->exists())->toBeTrue();
    expect(ChecklistResponse::where('checklist_submission_id', $t['submission']->id)->count())->toBe(3);

    // Gone from the builder list and from filling…
    $list = $this->actingAs($admin)->getJson('/api/checklist-templates')->assertOk()->json();
    expect(collect($list)->pluck('id')->all())->not->toContain($t['template']->id);
    $active = $this->actingAs($admin)->getJson('/api/checklist-templates/active')->json();
    expect(collect($active)->pluck('id')->all())->not->toContain($t['template']->id);

    // …but history still resolves it by name.
    $all = $this->actingAs($admin)->getJson('/api/checklist-templates?include_archived=1')->json();
    expect(collect($all)->pluck('id')->all())->toContain($t['template']->id);
    $this->actingAs($admin)->getJson('/api/checklist-submissions')
        ->assertJsonPath('data.0.template.name', 'Handover');

    // Shifts from tomorrow on no longer require it; past shifts and today's (whose handover may
    // already be submitted) keep what they had.
    expect(ShiftChecklist::where('shift_id', $future->id)->exists())->toBeFalse();
    expect(ShiftChecklist::where('shift_id', $today->id)->exists())->toBeTrue();
    expect(ShiftChecklist::where('shift_id', $past->id)->exists())->toBeTrue();
});

it('takes "tomorrow" in Warsaw time, not UTC, when archiving', function () {
    // 23:30 UTC on the 5th is 00:30 on the 6th in Warsaw: the 6th is today, only the 7th is future.
    Carbon::setTestNow('2029-01-05 23:30:00');
    $admin = cdlAdmin();
    $t = cdlTemplateWithSubmission($admin);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $shifts = [];
    foreach (['2029-01-05', '2029-01-06', '2029-01-07'] as $date) {
        $shifts[$date] = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id]);
        ShiftChecklist::create(['shift_id' => $shifts[$date]->id, 'checklist_template_id' => $t['template']->id, 'is_required' => true]);
    }

    $this->actingAs($admin)->deleteJson("/api/checklist-templates/{$t['template']->id}")->assertOk();

    $kept = ShiftChecklist::where('checklist_template_id', $t['template']->id)->pluck('shift_id')->sort()->values()->all();
    expect($kept)->toBe([$shifts['2029-01-05']->id, $shifts['2029-01-06']->id]);
});

it('does not raise overdue alerts or accept new handovers for an archived template', function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
    $admin = cdlAdmin();
    $t = cdlTemplateWithSubmission($admin);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $past = Shift::create(['date' => '2026-10-05', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    \App\Models\ShiftAssignment::create(['shift_id' => $past->id, 'user_id' => $admin->id]);
    ShiftChecklist::create(['shift_id' => $past->id, 'checklist_template_id' => $t['template']->id, 'is_required' => true]);

    $this->actingAs($admin)->deleteJson("/api/checklist-templates/{$t['template']->id}")->assertOk();

    $this->actingAs($admin)->getJson('/api/my-checklist-alerts')->assertOk()
        ->assertJsonCount(0, 'overdue_alerts');
    $this->actingAs($admin)->postJson('/api/checklist-submissions', [
        'checklist_template_id' => $t['template']->id,
        'date' => '2026-10-09',
        'outgoing_user_id' => $admin->id,
        'responses' => [['checklist_item_id' => $t['front']->id, 'value' => true]],
    ])->assertStatus(422);
    $this->actingAs($admin)->putJson("/api/schedule/shifts/{$past->id}/checklist", [
        'checklists' => [['checklist_template_id' => $t['template']->id, 'is_required' => true]],
    ])->assertOk(); // already on this shift: re-saving the shift keeps it
    $other = Shift::create(['date' => '2026-10-21', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    $this->actingAs($admin)->putJson("/api/schedule/shifts/{$other->id}/checklist", [
        'checklists' => [['checklist_template_id' => $t['template']->id, 'is_required' => true]],
    ])->assertStatus(422);
});

it('makes a stray hard delete of checklist history fail instead of cascading', function (string $what) {
    $admin = cdlAdmin();
    $t = cdlTemplateWithSubmission($admin);

    $delete = [
        'item' => fn () => $t['back']->delete(),
        'group' => fn () => $t['g1']->delete(),
        'template' => fn () => $t['template']->delete(),
    ][$what];

    expect($delete)->toThrow(QueryException::class);
})->with(['item', 'group', 'template']);
