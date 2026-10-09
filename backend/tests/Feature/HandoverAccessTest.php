<?php

use App\Models\ChecklistGroup;
use App\Models\ChecklistItem;
use App\Models\ChecklistSubmission;
use App\Models\ChecklistTemplate;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// M1 / M2: who may file a handover, with what, and who may read it back.
// (A non-admin filing as someone else is covered in PrivilegeGapsTest, "files a handover only as
// yourself unless you are an admin"; confirmation scoping in its H1 tests.)

function havUser(string $role = 'employee'): User
{
    return User::factory()->create(['role' => $role]);
}

/** A template with one group and $count checkbox items. Returns the items. */
function havItems(int $count = 1, string $name = 'Handover'): array
{
    $template = ChecklistTemplate::create(['name' => $name, 'is_active' => true]);
    $group = ChecklistGroup::create(['checklist_template_id' => $template->id, 'name' => 'Doors', 'sort_order' => 0]);

    return collect(range(1, $count))->map(fn ($i) => ChecklistItem::create([
        'checklist_group_id' => $group->id, 'label' => "Item {$i}", 'type' => 'checkbox', 'sort_order' => $i,
    ]))->all();
}

function havShift(string $date = '2026-10-09', ?User $assignee = null): Shift
{
    $group = ShiftGroup::firstOrCreate(['name' => 'Main'], ['is_default' => true, 'sort_order' => 0]);
    $shift = Shift::create([
        'date' => $date, 'shift_number' => Shift::where('date', $date)->count() + 1, 'shift_group_id' => $group->id,
        'start_time' => '06:00', 'end_time' => '14:00',
    ]);
    if ($assignee) {
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $assignee->id]);
    }

    return $shift;
}

/** $filer POSTs a handover; $overrides replace payload keys. */
function havFile(User $filer, array $items, array $overrides = [])
{
    return test()->actingAs($filer)->postJson('/api/checklist-submissions', array_merge([
        'checklist_template_id' => $items[0]->group->checklist_template_id,
        'date' => '2026-10-09',
        'outgoing_user_id' => $filer->id,
        'incoming_user_id' => null,
        'responses' => collect($items)->map(fn ($i) => ['checklist_item_id' => $i->id, 'value' => true])->all(),
    ], $overrides));
}

/** A completed submission from $outgoing to $incoming, created directly. */
function havSubmission(User $outgoing, ?User $incoming = null, ?User $submittedBy = null): ChecklistSubmission
{
    $template = ChecklistTemplate::create(['name' => 'Seeded', 'is_active' => true]);

    $submission = ChecklistSubmission::create([
        'checklist_template_id' => $template->id,
        'date' => '2026-10-09',
        'outgoing_user_id' => $outgoing->id,
        'incoming_user_id' => $incoming?->id,
        'status' => 'completed',
        'submitted_at' => now(),
        'confirmed_at' => now(),
    ]);
    $submission->forceFill(['submitted_by_user_id' => $submittedBy?->id ?? $outgoing->id])->save();

    return $submission;
}

// ── M1: filing a handover ─────────────────────────────────────────────────────

it('records who filed a handover, including an admin filing on behalf of someone', function () {
    $admin = havUser('admin');
    $b = havUser();
    $c = havUser();

    $id = havFile($admin, havItems(), ['outgoing_user_id' => $b->id, 'incoming_user_id' => $c->id])
        ->assertCreated()->assertJsonPath('outgoing_user_id', $b->id)->json('id');
    expect(ChecklistSubmission::find($id)->submitted_by_user_id)->toBe($admin->id);

    test()->actingAs($admin)->getJson("/api/checklist-submissions/{$id}")->assertOk()
        ->assertJsonPath('submitted_by.id', $admin->id)
        ->assertJsonPath('submitted_by.name', $admin->name);

    // An ordinary own submission records its filer too.
    $own = havFile($b, havItems())->assertCreated()->json('id');
    expect(ChecklistSubmission::find($own)->submitted_by_user_id)->toBe($b->id);
});

it('keeps the filer on record: a user who filed a handover cannot be hard-deleted', function () {
    $admin = havUser('admin');
    $b = havUser();
    $id = havFile($admin, havItems(), ['outgoing_user_id' => $b->id])->assertCreated()->json('id');

    // In a savepoint, so the refused delete doesn't abort the test's transaction (pgsql).
    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $admin->id)->delete()))
        ->toThrow(QueryException::class);
    expect(ChecklistSubmission::find($id)->submitted_by_user_id)->toBe($admin->id);
});

it('refuses a response for an item of another template', function () {
    $a = havUser();
    $mine = havItems(1, 'Mine');
    $other = havItems(1, 'Other');

    havFile($a, $mine, ['responses' => [
        ['checklist_item_id' => $mine[0]->id, 'value' => true],
        ['checklist_item_id' => $other[0]->id, 'value' => true],
    ]])->assertStatus(422)->assertJsonValidationErrors('responses.1.checklist_item_id');

    expect(ChecklistSubmission::count())->toBe(0);
});

it('refuses the same item answered twice in one handover', function () {
    $a = havUser();
    $items = havItems(2);

    havFile($a, $items, ['responses' => [
        ['checklist_item_id' => $items[0]->id, 'value' => true],
        ['checklist_item_id' => $items[1]->id, 'value' => true],
        ['checklist_item_id' => $items[0]->id, 'value' => false],
    ]])->assertStatus(422)->assertJsonValidationErrors('responses.2.checklist_item_id');

    expect(ChecklistSubmission::count())->toBe(0);
});

it('ties a handover shift to its date and to the outgoing user', function () {
    $a = havUser();
    $b = havUser();
    $admin = havUser('admin');
    $items = havItems();

    // Someone else's shift on that day.
    $notMine = havShift('2026-10-09', $b);
    havFile($a, $items, ['shift_id' => $notMine->id])
        ->assertStatus(422)->assertJsonValidationErrors('shift_id');
    // An admin filing for A can't pin it on B's shift either.
    havFile($admin, $items, ['outgoing_user_id' => $a->id, 'shift_id' => $notMine->id])
        ->assertStatus(422)->assertJsonValidationErrors('shift_id');

    // A's shift, but on another day than the handover's.
    $otherDay = havShift('2026-10-08', $a);
    havFile($a, $items, ['shift_id' => $otherDay->id])
        ->assertStatus(422)->assertJsonValidationErrors('shift_id');
    expect(ChecklistSubmission::count())->toBe(0);

    // A's own shift on the day: fine, for A and for an admin filing on A's behalf.
    $mine = havShift('2026-10-09', $a);
    havFile($a, $items, ['shift_id' => $mine->id])->assertCreated()->assertJsonPath('shift_id', $mine->id);
    havFile($admin, $items, ['outgoing_user_id' => $a->id, 'shift_id' => $mine->id])->assertCreated();
    // No shift at all stays allowed.
    havFile($a, $items)->assertCreated()->assertJsonPath('shift_id', null);
});

it('answers 422, not a server error, to a malformed shift id or date', function () {
    $a = havUser();
    $items = havItems();

    foreach (['abc', '1.5', ['x']] as $bad) {
        havFile($a, $items, ['shift_id' => $bad])->assertStatus(422)->assertJsonValidationErrors('shift_id');
    }
    // The handover date is a calendar date as the form sends it, nothing Carbon would guess at.
    foreach (['tomorrow', '2026-10-09 23:00:00', '09.10.2026'] as $bad) {
        havFile($a, $items, ['date' => $bad])->assertStatus(422)->assertJsonValidationErrors('date');
    }
    expect(ChecklistSubmission::count())->toBe(0);
});

it('refuses retired items and items of retired groups on a new handover', function () {
    $a = havUser();
    [$live, $retired] = havItems(2);
    $retired->forceFill(['archived_at' => now()])->save();

    havFile($a, [$live, $retired])->assertStatus(422)->assertJsonValidationErrors('responses.1.checklist_item_id');

    // A live item in a group that was retired as a whole.
    $template = ChecklistTemplate::find($live->group->checklist_template_id);
    $oldGroup = ChecklistGroup::create(['checklist_template_id' => $template->id, 'name' => 'Old', 'sort_order' => 1]);
    $orphan = ChecklistItem::create(['checklist_group_id' => $oldGroup->id, 'label' => 'Old item', 'type' => 'checkbox', 'sort_order' => 0]);
    $oldGroup->forceFill(['archived_at' => now()])->save();

    havFile($a, [$live, $orphan])->assertStatus(422)->assertJsonValidationErrors('responses.1.checklist_item_id');
    expect(ChecklistSubmission::count())->toBe(0);

    havFile($a, [$live])->assertCreated();
});

// ── M2: reading handovers back ────────────────────────────────────────────────

it('lists only their own handovers to employees and schedule maintainers', function (string $role) {
    $me = havUser($role);
    $x = havUser();
    $y = havUser();
    $admin = havUser('admin');

    $out = havSubmission($me, $x);
    $in = havSubmission($x, $me);
    $filedByMe = havSubmission($x, null, $me);
    $others = havSubmission($x, $y);
    $adminFiled = havSubmission($y, $x, $admin);

    $ids = fn ($query = '') => collect(test()->actingAs($me)->getJson("/api/checklist-submissions{$query}")
        ->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids())->toBe(collect([$out->id, $in->id, $filedByMe->id])->sort()->values()->all());
    // A user_id filter narrows within their own handovers; it never widens to someone else's.
    expect($ids("?user_id={$x->id}"))->toBe(collect([$out->id, $in->id, $filedByMe->id])->sort()->values()->all());
    expect($ids("?user_id={$y->id}"))->toBe([]);
    expect($ids("?user_id={$me->id}"))->toContain($out->id, $in->id);

    test()->actingAs($me)->getJson("/api/checklist-submissions/{$others->id}")->assertForbidden();
    test()->actingAs($me)->getJson("/api/checklist-submissions/{$adminFiled->id}")->assertForbidden();
    foreach ([$out, $in, $filedByMe] as $submission) {
        test()->actingAs($me)->getJson("/api/checklist-submissions/{$submission->id}")->assertOk();
    }
})->with(['employee', 'schedule_maintainer']);

it('shows admins every handover', function () {
    $admin = havUser('admin');
    $x = havUser();
    $y = havUser();
    $a = havSubmission($x, $y);
    $b = havSubmission($y, null);

    $ids = collect(test()->actingAs($admin)->getJson('/api/checklist-submissions')->assertOk()->json('data'))->pluck('id');
    expect($ids->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
    $filtered = collect(test()->actingAs($admin)->getJson("/api/checklist-submissions?user_id={$y->id}")->json('data'))->pluck('id');
    expect($filtered->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());

    test()->actingAs($admin)->getJson("/api/checklist-submissions/{$a->id}")->assertOk()
        ->assertJsonPath('outgoing_user.id', $x->id);
});
