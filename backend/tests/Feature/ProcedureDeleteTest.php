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

it('refuses a procedure archive to anyone but an admin, including its owner', function (string $who) {
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

it('refuses to archive a procedure that has sub-procedures, listing them', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $h = prdProcedureWithHistory(User::factory()->create(), User::factory()->create());

    $this->actingAs($admin)->deleteJson("/api/procedures/{$h['procedure']->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'has_children')
        ->assertJsonPath('message', 'Move or archive its sub-procedures first: PRD-2 Sub-step.')
        ->assertJsonPath('children.0.id', $h['child']->id);

    expect($h['procedure']->fresh()->archived_at)->toBeNull();
});

it('archives a procedure keeping its versions, FAQ, feedback, reads, access log, owner changes and links', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $owner = User::factory()->create(['role' => 'employee']);
    $reader = User::factory()->create(['role' => 'employee']);
    $h = prdProcedureWithHistory($owner, $reader);

    // Its sub-procedure goes first (an archived child doesn't block its parent).
    $this->actingAs($admin)->deleteJson("/api/procedures/{$h['child']->id}")->assertOk();
    $this->actingAs($admin)->deleteJson("/api/procedures/{$h['procedure']->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Procedure archived.');

    $id = $h['procedure']->id;
    expect($h['procedure']->fresh()->archived_at)->not->toBeNull()
        ->and(ProcedureVersion::where('procedure_id', $id)->count())->toBe(2)
        ->and($h['faq']->fresh())->not->toBeNull()
        ->and($h['reply']->fresh())->not->toBeNull()
        ->and($h['responseTemplate']->fresh())->not->toBeNull()
        ->and($h['access']->fresh())->not->toBeNull()
        ->and($h['feedback']->fresh())->not->toBeNull()
        ->and(ProcedureUserRead::where('procedure_id', $id)->count())->toBe(1)
        ->and($h['ownerChange']->fresh())->not->toBeNull()
        ->and(DB::table('procedure_tag')->where('procedure_id', $id)->count())->toBe(1)
        ->and($h['child']->fresh()->parent_id)->toBe($id)
        ->and($h['item']->fresh()->procedure_id)->toBe($id);
    $this->actingAs($admin)->deleteJson("/api/procedures/{$id}")->assertStatus(422)
        ->assertJsonPath('message', 'This procedure is already archived.');
});

it('hides an archived procedure from the tree, lists, search, to-learn and unread checks', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $employee = User::factory()->create(['role' => 'employee']);
    $kept = Procedure::create(['identifier' => 'PRD-8', 'title' => 'Alarm kept', 'owner_id' => $admin->id, 'version' => 1]);
    $gone = Procedure::create(['identifier' => 'PRD-9', 'title' => 'Alarm gone', 'owner_id' => $admin->id, 'version' => 1]);
    $this->actingAs($admin)->deleteJson("/api/procedures/{$gone->id}")->assertOk();

    $treeIds = collect($this->actingAs($employee)->getJson('/api/procedures-tree')->assertOk()->json())->pluck('id')->all();
    $listIds = collect($this->actingAs($employee)->getJson('/api/procedures')->assertOk()->json('data'))->pluck('id')->all();
    $searchIds = collect($this->actingAs($employee)->getJson('/api/procedures?search=Alarm')->assertOk()->json('data'))->pluck('id')->all();
    $toLearnIds = collect($this->actingAs($employee)->getJson('/api/procedures-to-learn')->assertOk()->json())->pluck('id')->all();
    $unreadIds = collect($this->actingAs($employee)->postJson('/api/procedures/check-read-status', [
        'procedure_ids' => [$kept->id, $gone->id],
    ])->assertOk()->json())->pluck('id')->all();

    expect($treeIds)->toBe([$kept->id])
        ->and($listIds)->toBe([$kept->id])
        ->and($searchIds)->toBe([$kept->id])
        ->and($toLearnIds)->toBe([$kept->id])
        ->and($unreadIds)->toBe([$kept->id]);
});

it('answers 404 for an archived procedure to non-admins and shows it to admins as archived', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $owner = User::factory()->create(['role' => 'employee']);
    $proc = Procedure::create(['identifier' => 'PRD-9', 'title' => 'Old', 'owner_id' => $owner->id, 'version' => 1]);
    $faq = ProcedureFaqItem::create(['procedure_id' => $proc->id, 'question' => 'Who calls?', 'created_by' => $owner->id]);
    $proc->forceFill(['archived_at' => now()])->save();

    foreach ([$owner, User::factory()->create(['role' => 'schedule_maintainer'])] as $user) {
        $this->actingAs($user)->postJson("/api/procedure-faq/{$faq->id}/replies", ['body' => 'Me.'])->assertNotFound();
        $this->actingAs($user)->getJson("/api/procedures/{$proc->id}")->assertNotFound();
        $this->actingAs($user)->getJson("/api/procedures/{$proc->id}/versions")->assertNotFound();
        $this->actingAs($user)->postJson("/api/procedures/{$proc->id}/faq", ['question' => 'Still valid?'])->assertNotFound();
    }

    $this->actingAs($admin)->getJson("/api/procedures/{$proc->id}")
        ->assertOk()
        ->assertJsonPath('id', $proc->id)
        ->assertJsonPath('can_edit', false);
    expect($this->actingAs($admin)->getJson("/api/procedures/{$proc->id}")->json('archived_at'))->not->toBeNull();
});

it('lists archived procedures to admins only', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $kept = Procedure::create(['identifier' => 'PRD-8', 'title' => 'Kept', 'owner_id' => $admin->id]);
    $gone = Procedure::create(['identifier' => 'PRD-9', 'title' => 'Gone', 'owner_id' => $admin->id]);
    $gone->forceFill(['archived_at' => now()])->save();

    $ids = collect($this->actingAs($admin)->getJson('/api/procedures?archived=1')->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$gone->id]);

    $this->actingAs(User::factory()->create(['role' => 'employee']))
        ->getJson('/api/procedures?archived=1')->assertForbidden();
});

it('refuses edits to an archived procedure until it is restored', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $proc = Procedure::create(['identifier' => 'PRD-9', 'title' => 'Old', 'owner_id' => $admin->id, 'version' => 1]);
    $proc->forceFill(['archived_at' => now()])->save();

    $this->actingAs($admin)->putJson("/api/procedures/{$proc->id}/draft", ['title' => 'New'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'This procedure is archived. Restore it before changing it.');
    $this->actingAs($admin)->putJson("/api/procedures/{$proc->id}/move", ['parent_id' => null])->assertStatus(409);

    expect($proc->fresh()->has_draft)->toBeFalse();
});

it('does not move a procedure under an archived parent or link an archived one from a new checklist', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $proc = Procedure::create(['identifier' => 'PRD-8', 'title' => 'Live', 'owner_id' => $admin->id]);
    $gone = Procedure::create(['identifier' => 'PRD-9', 'title' => 'Gone', 'owner_id' => $admin->id]);
    $gone->forceFill(['archived_at' => now()])->save();

    $this->actingAs($admin)->putJson("/api/procedures/{$proc->id}/move", ['parent_id' => $gone->id])
        ->assertStatus(422)->assertJsonValidationErrors('parent_id');
    $this->actingAs($admin)->postJson('/api/checklist-templates', [
        'name' => 'Handover',
        'groups' => [['name' => 'G', 'items' => [['label' => 'Read it', 'procedure_id' => $gone->id]]]],
    ])->assertStatus(422)->assertJsonValidationErrors('groups.0.items.0.procedure_id');
});

it('restores a procedure to its old place in the tree', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $parent = Procedure::create(['identifier' => 'PRD-1', 'title' => 'Parent', 'owner_id' => $admin->id]);
    $child = Procedure::create(['identifier' => 'PRD-2', 'title' => 'Child', 'owner_id' => $admin->id, 'parent_id' => $parent->id]);
    $this->actingAs($admin)->deleteJson("/api/procedures/{$child->id}")->assertOk();

    $this->actingAs($admin)->postJson("/api/procedures/{$child->id}/restore")
        ->assertOk()
        ->assertJsonPath('message', 'Procedure restored.')
        ->assertJsonPath('moved_to_root', false);

    expect($child->fresh()->archived_at)->toBeNull()
        ->and($child->fresh()->parent_id)->toBe($parent->id);
    $tree = $this->actingAs($admin)->getJson('/api/procedures-tree')->json();
    expect(collect($tree)->firstWhere('id', $parent->id)['children'][0]['id'])->toBe($child->id);
    $this->actingAs($admin)->postJson("/api/procedures/{$child->id}/restore")->assertStatus(422);
});

it('restores a procedure at the top level when its old parent is archived', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $parent = Procedure::create(['identifier' => 'PRD-1', 'title' => 'Parent', 'owner_id' => $admin->id]);
    $child = Procedure::create(['identifier' => 'PRD-2', 'title' => 'Child', 'owner_id' => $admin->id, 'parent_id' => $parent->id]);
    $this->actingAs($admin)->deleteJson("/api/procedures/{$child->id}")->assertOk();
    $this->actingAs($admin)->deleteJson("/api/procedures/{$parent->id}")->assertOk();

    $this->actingAs($admin)->postJson("/api/procedures/{$child->id}/restore")
        ->assertOk()
        ->assertJsonPath('moved_to_root', true)
        ->assertJsonPath('message', 'Procedure restored at the top level of the tree (its old parent is archived).');

    expect($child->fresh()->parent_id)->toBeNull()
        ->and(collect($this->actingAs($admin)->getJson('/api/procedures-tree')->json())->pluck('id')->all())->toBe([$child->id]);
});

it('freezes an archived procedure: FAQ, feedback and template writes are refused, reads still work for admins', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $owner = User::factory()->create(['role' => 'employee']);
    $reader = User::factory()->create(['role' => 'employee']);
    $h = prdProcedureWithHistory($owner, $reader);
    $ownReply = ProcedureFaqReply::create(['faq_item_id' => $h['faq']->id, 'body' => 'Mine.', 'created_by' => $reader->id]);
    $this->actingAs($admin)->deleteJson("/api/procedures/{$h['child']->id}")->assertOk();
    $this->actingAs($admin)->deleteJson("/api/procedures/{$h['procedure']->id}")->assertOk();
    $id = $h['procedure']->id;
    $archived = 'This procedure is archived. Restore it before changing it.';

    // Admins see it, so they are told why (409).
    $adminWrites = [
        ['postJson', "/api/procedures/{$id}/faq", ['question' => 'Still valid?']],
        ['postJson', "/api/procedure-faq/{$h['faq']->id}/replies", ['body' => 'Yes.']],
        ['putJson', "/api/procedure-faq/{$h['faq']->id}/toggle-outdated", []],
        ['putJson', "/api/procedure-faq-replies/{$h['reply']->id}/toggle-outdated", []],
        ['deleteJson', "/api/procedure-faq/{$h['faq']->id}", []],
        ['deleteJson', "/api/procedure-faq-replies/{$h['reply']->id}", []],
        ['postJson', "/api/procedures/{$id}/feedback", ['type' => 'error', 'description' => 'Typo']],
        ['putJson', "/api/procedure-feedbacks/{$h['feedback']->id}", ['status' => 'resolved', 'owner_comment' => 'Fixed.']],
        ['postJson', "/api/procedures/{$id}/templates", ['title' => 'T', 'body' => 'B']],
        ['putJson', "/api/procedure-templates/{$h['responseTemplate']->id}", ['title' => 'T2']],
        ['deleteJson', "/api/procedure-templates/{$h['responseTemplate']->id}", []],
    ];
    foreach ($adminWrites as [$method, $url, $body]) {
        $this->actingAs($admin)->{$method}($url, $body)->assertStatus(409)->assertJsonPath('message', $archived);
    }
    // Everyone else gets 404, even on their own FAQ rows or as the owner.
    $this->actingAs($owner)->putJson("/api/procedure-faq/{$h['faq']->id}/toggle-outdated")->assertNotFound();
    $this->actingAs($reader)->deleteJson("/api/procedure-faq/{$h['faq']->id}")->assertNotFound();
    $this->actingAs($reader)->deleteJson("/api/procedure-faq-replies/{$ownReply->id}")->assertNotFound();

    expect($h['faq']->fresh()->is_outdated)->toBeFalsy()
        ->and($h['reply']->fresh())->not->toBeNull()
        ->and($ownReply->fresh())->not->toBeNull()
        ->and($h['feedback']->fresh()->status)->not->toBe('resolved')
        ->and($h['responseTemplate']->fresh()->title)->toBe('Email')
        ->and(ProcedureFaqItem::where('procedure_id', $id)->count())->toBe(1);

    $this->actingAs($admin)->getJson("/api/procedures/{$id}/faq")->assertOk()->assertJsonCount(1);
    $this->actingAs($admin)->getJson("/api/procedures/{$id}/templates")->assertOk()->assertJsonCount(1);
    $this->actingAs($admin)->getJson("/api/procedures/{$id}/versions")->assertOk()->assertJsonCount(2);
});

it('keeps an item\'s link to a procedure archived since, but refuses new links to it on any other item', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $gone = Procedure::create(['identifier' => 'PRD-9', 'title' => 'Gone', 'owner_id' => $admin->id]);
    $live = Procedure::create(['identifier' => 'PRD-8', 'title' => 'Live', 'owner_id' => $admin->id]);
    $template = $this->actingAs($admin)->postJson('/api/checklist-templates', [
        'name' => 'Handover',
        'groups' => [['name' => 'G', 'items' => [
            ['label' => 'Linked', 'procedure_id' => $gone->id],
            ['label' => 'Other', 'procedure_id' => $live->id],
        ]]],
    ])->assertCreated()->json();
    $this->actingAs($admin)->deleteJson("/api/procedures/{$gone->id}")->assertOk();
    [$linked, $other] = $template['groups'][0]['items'];
    $payload = fn (array $items) => ['name' => 'Handover', 'groups' => [['id' => $template['groups'][0]['id'], 'name' => 'G', 'items' => $items]]];
    $message = 'This procedure is archived and can\'t be newly linked.';

    // Re-saving keeps the existing link.
    $this->actingAs($admin)->putJson("/api/checklist-templates/{$template['id']}", $payload([
        ['id' => $linked['id'], 'label' => 'Linked', 'procedure_id' => $gone->id],
        ['id' => $other['id'], 'label' => 'Other', 'procedure_id' => $live->id],
    ]))->assertOk();
    // Another existing item switching to it, or a new item, is refused.
    $this->actingAs($admin)->putJson("/api/checklist-templates/{$template['id']}", $payload([
        ['id' => $linked['id'], 'label' => 'Linked', 'procedure_id' => $gone->id],
        ['id' => $other['id'], 'label' => 'Other', 'procedure_id' => $gone->id],
        ['label' => 'New', 'procedure_id' => $gone->id],
    ]))->assertStatus(422)
        ->assertJsonPath('errors', [
            'groups.0.items.1.procedure_id' => [$message],
            'groups.0.items.2.procedure_id' => [$message],
        ]);

    expect(ChecklistItem::find($other['id'])->procedure_id)->toBe($live->id)
        ->and(ChecklistItem::find($linked['id'])->procedure_id)->toBe($gone->id);
});

it('refuses a move under a parent archived after the move was validated', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $parent = Procedure::create(['identifier' => 'PRD-1', 'title' => 'Parent', 'owner_id' => $admin->id]);
    $proc = Procedure::create(['identifier' => 'PRD-2', 'title' => 'Moving', 'owner_id' => $admin->id]);
    // Deterministic stand-in for a concurrent archive: it lands right after the parent_id rule ran.
    $fired = false;
    DB::listen(function ($query) use (&$fired, $parent) {
        if ($fired || ! str_contains($query->sql, 'count(*)') || ! str_contains($query->sql, 'from "procedures"')) {
            return;
        }
        $fired = true;
        DB::table('procedures')->where('id', $parent->id)->update(['archived_at' => now()]);
    });

    $this->actingAs($admin)->putJson("/api/procedures/{$proc->id}/move", ['parent_id' => $parent->id])
        ->assertStatus(422)
        ->assertJsonPath('errors.parent_id.0', 'The selected parent procedure is archived or no longer exists.');

    expect($proc->fresh()->parent_id)->toBeNull();
});

it('keeps a restored procedure\'s sort order', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $proc = Procedure::create(['identifier' => 'PRD-2', 'title' => 'Ordered', 'owner_id' => $admin->id, 'sort_order' => 7]);
    $this->actingAs($admin)->deleteJson("/api/procedures/{$proc->id}")->assertOk();
    $this->actingAs($admin)->postJson("/api/procedures/{$proc->id}/restore")->assertOk();

    expect($proc->fresh()->sort_order)->toBe(7);
});

it('lets an admin settle open feedback on an archived procedure, but not comment on it', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $h = prdProcedureWithHistory(User::factory()->create(), User::factory()->create());
    $this->actingAs($admin)->deleteJson("/api/procedures/{$h['child']->id}")->assertOk();
    $this->actingAs($admin)->deleteJson("/api/procedures/{$h['procedure']->id}")->assertOk();

    $this->actingAs($admin)->putJson("/api/procedure-feedbacks/{$h['feedback']->id}", [
        'status' => 'resolved', 'resolution' => 'deferred',
    ])->assertOk()->assertJsonPath('status', 'resolved');
    $this->actingAs($admin)->putJson("/api/procedure-feedbacks/{$h['feedback']->id}", ['owner_comment' => 'Later.'])
        ->assertStatus(409);

    $fresh = $h['feedback']->fresh();
    expect($fresh->status)->toBe('resolved')
        ->and($fresh->resolution)->toBe('deferred')
        ->and($fresh->owner_comment)->toBeNull();
});
