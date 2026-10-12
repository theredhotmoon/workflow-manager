<?php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// L21: "Generate as remote" switches every office shift on the month's closed dates in one
// request (PUT /api/schedule/work-mode), all or nothing, with the single update's rules.

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->main = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $this->shifts = collect(range(1, 3))->map(fn ($n) => Shift::create([
        'date' => '2029-03-12', 'shift_number' => $n, 'shift_group_id' => $this->main->id, 'work_mode' => 'office',
    ]));
});

it('switches several shifts in one request and leaves the others alone', function () {
    [$a, $b, $c] = $this->shifts->all();

    $this->actingAs($this->admin)
        ->putJson('/api/schedule/work-mode', ['shift_ids' => [$a->id, $b->id], 'work_mode' => 'remote'])
        ->assertOk()
        ->assertJsonPath('updated', 2)
        ->assertJsonPath('work_mode', 'remote');

    expect($a->fresh()->work_mode)->toBe('remote')
        ->and($b->fresh()->work_mode)->toBe('remote')
        ->and($c->fresh()->work_mode)->toBe('office');
});

it('lets a schedule maintainer switch, and refuses an employee', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $employee = User::factory()->create(['role' => 'employee']);
    $ids = $this->shifts->pluck('id')->all();

    $this->actingAs($employee)->putJson('/api/schedule/work-mode', ['shift_ids' => $ids, 'work_mode' => 'remote'])
        ->assertForbidden();
    expect(Shift::where('work_mode', 'remote')->count())->toBe(0);

    $this->actingAs($maintainer)->putJson('/api/schedule/work-mode', ['shift_ids' => $ids, 'work_mode' => 'remote'])
        ->assertOk()->assertJsonPath('updated', 3);
});

it('refuses the whole request when one shift is in an archived group', function () {
    $old = ShiftGroup::create(['name' => 'Old desk', 'is_default' => false, 'sort_order' => 1]);
    $archivedShift = Shift::create(['date' => '2029-03-12', 'shift_number' => 1, 'shift_group_id' => $old->id, 'work_mode' => 'office']);
    $this->actingAs($this->admin)->deleteJson("/api/shift-groups/{$old->id}")->assertOk();

    $this->actingAs($this->admin)
        ->putJson('/api/schedule/work-mode', [
            'shift_ids' => [...$this->shifts->pluck('id')->all(), $archivedShift->id],
            'work_mode' => 'remote',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This shift belongs to the archived shift group "Old desk". Restore the group to change its schedule.');

    expect(Shift::where('work_mode', 'remote')->count())->toBe(0);
});

it('validates the ids and the mode', function () {
    $id = $this->shifts->first()->id;

    $this->actingAs($this->admin)->putJson('/api/schedule/work-mode', ['work_mode' => 'remote'])
        ->assertStatus(422)->assertJsonValidationErrors('shift_ids');
    $this->actingAs($this->admin)->putJson('/api/schedule/work-mode', ['shift_ids' => [], 'work_mode' => 'remote'])
        ->assertStatus(422)->assertJsonValidationErrors('shift_ids');
    $this->actingAs($this->admin)->putJson('/api/schedule/work-mode', ['shift_ids' => [$id, 99999999], 'work_mode' => 'remote'])
        ->assertStatus(422)->assertJsonPath('errors.shift_ids.0', 'One or more of the selected shifts do not exist.');
    $this->actingAs($this->admin)->putJson('/api/schedule/work-mode', ['shift_ids' => [$id, $id], 'work_mode' => 'remote'])
        ->assertStatus(422)->assertJsonValidationErrors('shift_ids.0');
    $this->actingAs($this->admin)->putJson('/api/schedule/work-mode', ['shift_ids' => [$id], 'work_mode' => 'hybrid'])
        ->assertStatus(422)->assertJsonValidationErrors('work_mode');
    $this->actingAs($this->admin)->putJson('/api/schedule/work-mode', ['shift_ids' => range(1, 501), 'work_mode' => 'remote'])
        ->assertStatus(422)->assertJsonValidationErrors('shift_ids');

    expect(Shift::where('work_mode', 'remote')->count())->toBe(0);
});

it('checks the ids with a fixed number of queries, however many there are (M4)', function () {
    $ids = collect(range(1, 300))->map(fn ($n) => Shift::create([
        'date' => '2029-04-01', 'shift_number' => $n, 'shift_group_id' => $this->main->id, 'work_mode' => 'office',
    ])->id)->all();

    DB::enableQueryLog();
    $this->actingAs($this->admin)->putJson('/api/schedule/work-mode', ['shift_ids' => $ids, 'work_mode' => 'remote'])
        ->assertOk()->assertJsonPath('updated', 300);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThan(20);
});
