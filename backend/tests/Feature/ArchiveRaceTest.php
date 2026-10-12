<?php

use App\Models\OnCallAssignment;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Archive vs planning writes (review I1). A real race needs two connections; these tests stand in
 * for it deterministically: the group is archived right after the request's own check read it as
 * active (a query listener fires once on the first read of shift_groups), so only the re-check
 * under the group lock inside the write's transaction can stop the new work.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->worker = User::factory()->create(['role' => 'employee']);
    ShiftGroup::where('is_default', true)->update(['is_default' => false]);
    ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $this->group = ShiftGroup::create(['name' => 'Night desk', 'is_default' => false, 'sort_order' => 1]);
});

/** Archives $group right after the first query that reads shift_groups (the request's own check). */
function racArchiveAfterFirstGroupRead(ShiftGroup $group): void
{
    $fired = false;
    DB::listen(function ($query) use (&$fired, $group) {
        if ($fired || ! str_contains($query->sql, 'from "shift_groups"')) {
            return;
        }
        $fired = true;
        DB::table('shift_groups')->where('id', $group->id)->update(['archived_at' => now()]);
    });
}

const RAC_GROUP_ERROR = 'The selected shift group does not exist or is archived.';

it('refuses an assign when the group is archived after the request checked it', function () {
    $shift = Shift::create(['date' => '2026-10-20', 'shift_number' => 1, 'shift_group_id' => $this->group->id]);
    racArchiveAfterFirstGroupRead($this->group);

    $this->actingAs($this->admin)->postJson('/api/schedule/assign', ['shift_id' => $shift->id, 'user_id' => $this->worker->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This shift belongs to the archived shift group "Night desk". Restore the group to change its schedule.');

    expect(ShiftAssignment::count())->toBe(0);
});

it('refuses a move into a group archived after the request checked it, leaving the source in place', function () {
    $main = ShiftGroup::where('name', 'Main')->first();
    $from = Shift::create(['date' => '2026-10-20', 'shift_number' => 1, 'shift_group_id' => $main->id]);
    $source = ShiftAssignment::create(['shift_id' => $from->id, 'user_id' => $this->worker->id]);
    $to = Shift::create(['date' => '2026-10-21', 'shift_number' => 1, 'shift_group_id' => $this->group->id]);
    $fired = false;
    DB::listen(function ($query) use (&$fired) {
        // The target group is read after the source's; archive it once its row was read as active.
        if ($fired || ! str_contains($query->sql, 'from "shift_groups"') || ! in_array($this->group->id, $query->bindings, true)) {
            return;
        }
        $fired = true;
        DB::table('shift_groups')->where('id', $this->group->id)->update(['archived_at' => now()]);
    });

    $this->actingAs($this->admin)->postJson('/api/schedule/assign', [
        'shift_id' => $to->id, 'user_id' => $this->worker->id, 'move_from_assignment_id' => $source->id,
    ])->assertStatus(422);

    expect($source->fresh())->not->toBeNull()
        ->and(ShiftAssignment::where('shift_id', $to->id)->count())->toBe(0);
});

it('refuses generating a month in a group archived after validation', function () {
    racArchiveAfterFirstGroupRead($this->group);

    $this->actingAs($this->admin)->postJson('/api/schedule/generate', ['month' => '2026-11', 'shift_group_id' => $this->group->id])
        ->assertStatus(422)
        ->assertJsonPath('errors.shift_group_id.0', RAC_GROUP_ERROR);

    expect(Shift::where('shift_group_id', $this->group->id)->count())->toBe(0);
});

it('refuses adding day slots in a group archived after validation', function () {
    racArchiveAfterFirstGroupRead($this->group);

    $this->actingAs($this->admin)->putJson('/api/schedule/day-shifts', ['date' => '2026-11-02', 'count' => 2, 'shift_group_id' => $this->group->id])
        ->assertStatus(422)
        ->assertJsonPath('errors.shift_group_id.0', RAC_GROUP_ERROR);

    expect(Shift::where('shift_group_id', $this->group->id)->count())->toBe(0);
});

it('refuses an auto-fill of a group archived after validation', function (string $mode, array $extra) {
    foreach (['2026-11-02', '2026-11-03'] as $date) {
        Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $this->group->id, 'start_time' => '08:00', 'end_time' => '16:00']);
    }
    racArchiveAfterFirstGroupRead($this->group);

    $this->actingAs($this->admin)->postJson("/api/schedule/auto-assign/{$mode}", array_merge([
        'month' => '2026-11', 'shift_group_id' => $this->group->id, 'users_per_shift_weekday' => 1, 'users_per_shift_weekend' => 1,
    ], $extra))->assertStatus(422)->assertJsonPath('errors.shift_group_id.0', RAC_GROUP_ERROR);

    expect(ShiftAssignment::count())->toBe(0);
})->with([
    'random' => ['random', []],
    'rotating' => ['rotating', ['rotation_days' => 2]],
    'polish law' => ['polish-law', ['rotation_days' => 2]],
]);

it('refuses an on-call duty in a group archived after validation', function () {
    racArchiveAfterFirstGroupRead($this->group);

    $this->actingAs($this->admin)->postJson('/api/on-call-assignments', [
        'shift_group_id' => $this->group->id, 'user_id' => $this->worker->id,
        'starts_at' => '2026-11-02 20:00:00', 'ends_at' => '2026-11-03 08:00:00',
    ])->assertStatus(422)->assertJsonPath('errors.shift_group_id.0', RAC_GROUP_ERROR);

    expect(OnCallAssignment::count())->toBe(0);
});
