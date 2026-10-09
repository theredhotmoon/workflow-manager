<?php

use App\Models\ChecklistGroup;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\Procedure;
use App\Models\ProcedureAccessLog;
use App\Models\ProcedureFaqItem;
use App\Models\ProcedureFaqReply;
use App\Models\ProcedureFeedback;
use App\Models\ProcedureOwnerChange;
use App\Models\ProcedureResponseTemplate;
use App\Models\ProcedureTag;
use App\Models\ProcedureUserRead;
use App\Models\ProcedureVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** A procedure with one row of every kind that hangs off it, plus a child and a checklist item linking it. */
function prdProcedureWithHistory(User $owner, User $reader): array
{
    $procedure = Procedure::create(['identifier' => 'PRD-1', 'title' => 'Alarm response', 'content' => '<p>v2</p>', 'owner_id' => $owner->id, 'version' => 2]);
    $child = Procedure::create(['identifier' => 'PRD-2', 'title' => 'Sub-step', 'owner_id' => $owner->id, 'parent_id' => $procedure->id]);
    $tag = ProcedureTag::create(['name' => 'Security', 'color' => '#f00']);
    $procedure->tags()->attach($tag->id);

    $versions = collect([1, 2])->map(fn ($v) => ProcedureVersion::create([
        'procedure_id' => $procedure->id, 'version' => $v, 'title' => 'Alarm response', 'content' => "<p>v{$v}</p>",
        'change_reason' => "Version {$v}", 'published_by' => $owner->id, 'published_at' => now(),
    ]));
    $faq = ProcedureFaqItem::create(['procedure_id' => $procedure->id, 'question' => 'Who calls?', 'created_by' => $reader->id]);
    $reply = ProcedureFaqReply::create(['faq_item_id' => $faq->id, 'body' => 'The lead.', 'created_by' => $owner->id]);
    $responseTemplate = ProcedureResponseTemplate::create(['procedure_id' => $procedure->id, 'title' => 'Email', 'body' => 'Hello']);
    $access = ProcedureAccessLog::create(['procedure_id' => $procedure->id, 'user_id' => $reader->id, 'accessed_at' => now()]);
    $feedback = ProcedureFeedback::create(['procedure_id' => $procedure->id, 'user_id' => $reader->id, 'description' => 'Typo']);
    ProcedureUserRead::create(['procedure_id' => $procedure->id, 'user_id' => $reader->id, 'version_read' => 2, 'read_at' => now()]);
    $ownerChange = ProcedureOwnerChange::create([
        'procedure_id' => $procedure->id, 'old_owner_id' => $reader->id, 'new_owner_id' => $owner->id,
        'changed_by_id' => $owner->id, 'reason' => 'Handed on',
    ]);

    $template = ChecklistTemplate::create(['name' => 'Handover', 'is_active' => true]);
    $group = ChecklistGroup::create(['checklist_template_id' => $template->id, 'name' => 'G', 'sort_order' => 0]);
    $item = ChecklistItem::create(['checklist_group_id' => $group->id, 'label' => 'Alarm tested', 'type' => 'checkbox', 'sort_order' => 0, 'procedure_id' => $procedure->id]);

    return compact('procedure', 'child', 'tag', 'versions', 'faq', 'reply', 'responseTemplate', 'access', 'feedback', 'ownerChange', 'item');
}

it('refuses a procedure delete to anyone but an admin, including its owner', function (string $who) {
    $owner = User::factory()->create(['role' => 'employee']);
    $h = prdProcedureWithHistory($owner, User::factory()->create(['role' => 'employee']));
    $actor = $who === 'owner' ? $owner : User::factory()->create(['role' => $who]);

    $this->actingAs($actor)->deleteJson("/api/procedures/{$h['procedure']->id}")->assertForbidden();

    expect($h['procedure']->fresh())->not->toBeNull()
        ->and(ProcedureVersion::where('procedure_id', $h['procedure']->id)->count())->toBe(2);
})->with(['owner', 'employee', 'schedule_maintainer']);

it('answers 404 for an unknown procedure', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->deleteJson('/api/procedures/999999')->assertNotFound();
});

it('deletes a procedure with its versions, FAQ, feedback, reads and access log', function () {
    // Current behaviour: cascades; see follow-up. A procedure delete is a hard delete: its version
    // history, FAQ items and replies, response templates, feedback, read receipts, access log, owner
    // changes and tag links all go with it. Child procedures move to the root and checklist items
    // that linked it lose the link.
    $admin = User::factory()->create(['role' => 'admin']);
    $owner = User::factory()->create(['role' => 'employee']);
    $reader = User::factory()->create(['role' => 'employee']);
    $h = prdProcedureWithHistory($owner, $reader);

    $this->actingAs($admin)->deleteJson("/api/procedures/{$h['procedure']->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Procedure deleted.');

    $id = $h['procedure']->id;
    expect($h['procedure']->fresh())->toBeNull()
        ->and(ProcedureVersion::where('procedure_id', $id)->count())->toBe(0)
        ->and($h['faq']->fresh())->toBeNull()
        ->and($h['reply']->fresh())->toBeNull()
        ->and($h['responseTemplate']->fresh())->toBeNull()
        ->and($h['access']->fresh())->toBeNull()
        ->and($h['feedback']->fresh())->toBeNull()
        ->and(ProcedureUserRead::where('procedure_id', $id)->count())->toBe(0)
        ->and($h['ownerChange']->fresh())->toBeNull()
        ->and(DB::table('procedure_tag')->where('procedure_id', $id)->count())->toBe(0);

    // Kept: the tag itself, the people, the child (now top-level) and the checklist item (unlinked).
    expect($h['tag']->fresh())->not->toBeNull()
        ->and($owner->fresh())->not->toBeNull()
        ->and($reader->fresh())->not->toBeNull()
        ->and($h['child']->fresh())->not->toBeNull()
        ->and($h['child']->fresh()->parent_id)->toBeNull()
        ->and($h['item']->fresh())->not->toBeNull()
        ->and($h['item']->fresh()->procedure_id)->toBeNull();
});
