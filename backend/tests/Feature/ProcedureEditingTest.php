<?php

use App\Models\Procedure;
use App\Models\ProcedureFeedback;
use App\Models\ProcedureVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function pedUser(string $role = 'employee'): User
{
    return User::factory()->create(['role' => $role]);
}

/** A procedure at v1 with its v1 snapshot, as the API creates it. */
function pedProcedure(User $owner, string $identifier = 'PED-1'): Procedure
{
    $procedure = Procedure::create([
        'identifier' => $identifier,
        'title' => 'Original title',
        'content' => '<p>v1</p>',
        'owner_id' => $owner->id,
        'version' => 1,
    ]);
    ProcedureVersion::create([
        'procedure_id' => $procedure->id,
        'version' => 1,
        'title' => $procedure->title,
        'content' => $procedure->content,
        'change_reason' => 'Initial version',
        'published_by' => $owner->id,
        'published_at' => now(),
    ]);

    return $procedure;
}

// ── H11: editing a procedure is admin-only ────────────────────────────────────

it('offers editing only to admins, not to a non-admin owner', function () {
    $owner = pedUser();
    $procedure = pedProcedure($owner);

    test()->actingAs($owner)->getJson("/api/procedures/{$procedure->id}")
        ->assertOk()->assertJsonPath('can_edit', false);
    test()->actingAs(pedUser())->getJson("/api/procedures/{$procedure->id}")
        ->assertOk()->assertJsonPath('can_edit', false);
    test()->actingAs(pedUser('schedule_maintainer'))->getJson("/api/procedures/{$procedure->id}")
        ->assertOk()->assertJsonPath('can_edit', false);
    test()->actingAs(pedUser('admin'))->getJson("/api/procedures/{$procedure->id}")
        ->assertOk()->assertJsonPath('can_edit', true);
});

it('refuses every procedure write to a non-admin owner', function () {
    $owner = pedUser();
    $procedure = pedProcedure($owner);
    $as = test()->actingAs($owner);

    $as->putJson("/api/procedures/{$procedure->id}", ['title' => 'Mine'])->assertForbidden();
    $as->putJson("/api/procedures/{$procedure->id}/draft", ['title' => 'Mine'])->assertForbidden();
    $as->postJson("/api/procedures/{$procedure->id}/publish", ['change_reason' => 'x'])->assertForbidden();
    $as->deleteJson("/api/procedures/{$procedure->id}/draft")->assertForbidden();
    $as->putJson("/api/procedures/{$procedure->id}/tags", ['tag_ids' => []])->assertForbidden();
    $as->deleteJson("/api/procedures/{$procedure->id}")->assertForbidden();

    $procedure->refresh();
    expect($procedure->title)->toBe('Original title');
    expect($procedure->has_draft)->toBeFalsy();
});

// ── M8: a direct update snapshots the new version and never changes the owner ─

it('stores a version snapshot when an update bumps the version', function () {
    $admin = pedUser('admin');
    $procedure = pedProcedure(pedUser());

    test()->actingAs($admin)->putJson("/api/procedures/{$procedure->id}", [
        'title' => 'New title',
        'content' => '<p>v2</p>',
        'change_reason' => 'Fixed step 2',
    ])->assertOk()->assertJsonPath('version', 2);

    $snapshot = test()->actingAs($admin)->getJson("/api/procedures/{$procedure->id}/versions/2")
        ->assertOk();
    expect($snapshot->json('title'))->toBe('New title');
    expect($snapshot->json('content'))->toBe('<p>v2</p>');
    expect($snapshot->json('change_reason'))->toBe('Fixed step 2');
    expect($snapshot->json('published_by'))->toBe($admin->id);

    $history = test()->actingAs($admin)->getJson("/api/procedures/{$procedure->id}/versions")->assertOk();
    expect(collect($history->json())->pluck('version')->all())->toBe([2, 1]);
});

it('bumps and snapshots on a title-only change, with a default reason', function () {
    $admin = pedUser('admin');
    $procedure = pedProcedure(pedUser());

    test()->actingAs($admin)->putJson("/api/procedures/{$procedure->id}", ['title' => 'Renamed'])
        ->assertOk()->assertJsonPath('version', 2);

    $snapshot = ProcedureVersion::where('procedure_id', $procedure->id)->where('version', 2)->first();
    expect($snapshot->title)->toBe('Renamed');
    expect($snapshot->content)->toBe('<p>v1</p>');
    expect($snapshot->change_reason)->toBe('Updated');
});

it('does not bump the version when nothing versioned changes', function () {
    $admin = pedUser('admin');
    $procedure = pedProcedure(pedUser());

    test()->actingAs($admin)->putJson("/api/procedures/{$procedure->id}", [
        'title' => 'Original title',
        'content' => '<p>v1</p>',
    ])->assertOk()->assertJsonPath('version', 1);

    expect(ProcedureVersion::where('procedure_id', $procedure->id)->count())->toBe(1);
});

it('rejects an owner change through update and leaves the owner alone', function () {
    $admin = pedUser('admin');
    $owner = pedUser();
    $procedure = pedProcedure($owner);

    test()->actingAs($admin)->putJson("/api/procedures/{$procedure->id}", [
        'owner_id' => pedUser()->id,
    ])->assertStatus(422)->assertJsonValidationErrors('owner_id');

    expect($procedure->refresh()->owner_id)->toBe($owner->id);
    expect(DB::table('procedure_owner_changes')->count())->toBe(0);

    // Sending the current owner back is a no-op, not an error.
    test()->actingAs($admin)->putJson("/api/procedures/{$procedure->id}", [
        'owner_id' => $owner->id,
        'title' => 'Same owner',
    ])->assertOk()->assertJsonPath('owner_id', $owner->id);
});

it('rolls back the update when the snapshot cannot be stored', function () {
    $admin = pedUser('admin');
    $procedure = pedProcedure(pedUser());
    // A stray v2 snapshot makes the new snapshot hit the unique (procedure_id, version) index.
    ProcedureVersion::create([
        'procedure_id' => $procedure->id,
        'version' => 2,
        'title' => 'Stray',
        'change_reason' => 'stray',
        'published_by' => $admin->id,
        'published_at' => now(),
    ]);

    test()->actingAs($admin)->putJson("/api/procedures/{$procedure->id}", [
        'title' => 'Half saved',
        'content' => '<p>v2</p>',
    ])->assertStatus(500);

    $procedure->refresh();
    expect($procedure->version)->toBe(1);
    expect($procedure->title)->toBe('Original title');
});

it('refuses a direct update while a draft is pending, so publishing it cannot overwrite the update', function () {
    $admin = pedUser('admin');
    $procedure = pedProcedure(pedUser());
    test()->actingAs($admin)->putJson("/api/procedures/{$procedure->id}/draft", ['title' => 'Draft title'])
        ->assertOk();

    test()->actingAs($admin)->putJson("/api/procedures/{$procedure->id}", ['title' => 'Direct title'])
        ->assertStatus(409);

    $procedure->refresh();
    expect($procedure->title)->toBe('Original title');
    expect($procedure->version)->toBe(1);
    expect($procedure->draft_title)->toBe('Draft title');
});

it('resolves open feedback for older versions when an update publishes a new one', function () {
    $admin = pedUser('admin');
    $reporter = pedUser();
    $procedure = pedProcedure(pedUser());
    $old = ProcedureFeedback::create([
        'procedure_id' => $procedure->id, 'user_id' => $reporter->id, 'type' => 'error',
        'description' => 'Step 2 is wrong', 'status' => 'open', 'for_version' => 1,
    ]);

    test()->actingAs($admin)->putJson("/api/procedures/{$procedure->id}", ['content' => '<p>v2</p>'])
        ->assertOk()->assertJsonPath('version', 2);

    expect($old->refresh()->status)->toBe('resolved');
});

it('creates a procedure and its v1 snapshot together or not at all', function () {
    $admin = pedUser('admin');
    // Any v1 snapshot insert fails: the procedure must not be left behind without /versions/1.
    DB::statement("CREATE FUNCTION ped_fail() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'no snapshots'; END; \$\$ LANGUAGE plpgsql");
    DB::statement('CREATE TRIGGER ped_fail BEFORE INSERT ON procedure_versions FOR EACH ROW EXECUTE FUNCTION ped_fail()');

    test()->actingAs($admin)->postJson('/api/procedures', [
        'title' => 'Never half created',
        'owner_id' => $admin->id,
    ])->assertStatus(500);

    expect(Procedure::where('title', 'Never half created')->exists())->toBeFalse();
});
