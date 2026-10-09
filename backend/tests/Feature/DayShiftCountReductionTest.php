<?php
// M12: reducing a day's shift count removes empty slots first and never deletes a staffed slot
// without the manager's confirmation.

use App\Models\ChecklistSubmission;
use App\Models\ChecklistTemplate;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftChangeRequest;
use App\Models\ShiftGroup;
use App\Models\User;

beforeEach(function () {
    $this->maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $this->group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $this->employee = User::factory()->create(['role' => 'employee', 'name' => 'Ewa Nowak']);
    // 2029-02-12: three slots, 06-14 / 14-22 / 22-06.
    $this->slots = collect([[1, '06:00', '14:00'], [2, '14:00', '22:00'], [3, '22:00', '06:00']])
        ->mapWithKeys(fn ($s) => [$s[0] => Shift::create([
            'date' => '2029-02-12', 'shift_number' => $s[0], 'shift_group_id' => $this->group->id,
            'start_time' => $s[1], 'end_time' => $s[2],
        ])]);
});

function dscStaff($test, int $slot): ShiftAssignment
{
    return ShiftAssignment::create(['shift_id' => $test->slots[$slot]->id, 'user_id' => $test->employee->id]);
}

function dscReduce($test, int $count, array $extra = [])
{
    return $test->actingAs($test->maintainer)->putJson('/api/schedule/day-shifts', array_merge([
        'date' => '2029-02-12', 'count' => $count, 'shift_group_id' => $test->group->id,
    ], $extra));
}

it('removes the empty slot, not the staffed one after it, without asking', function () {
    dscStaff($this, 1);
    dscStaff($this, 3);

    $res = dscReduce($this, 2)->assertOk();

    expect(Shift::find($this->slots[2]->id))->toBeNull();
    $night = Shift::find($this->slots[3]->id);
    expect($night)->not->toBeNull();
    // The kept slots are renumbered 1..n in their order, keeping their times and staff.
    expect($night->shift_number)->toBe(2);
    expect(substr($night->start_time, 0, 5))->toBe('22:00');
    expect(ShiftAssignment::where('shift_id', $night->id)->count())->toBe(1);
    expect(collect($res->json('shifts'))->pluck('id')->all())->toBe([$this->slots[1]->id, $night->id]);
    expect(collect($res->json('shifts'))->pluck('shift_number')->all())->toBe([1, 2]);

    // Growing again adds a fresh slot 3 (no clash with the renumbered one), with the preset times
    // the remaining slots don't use: the afternoon that was removed, not a second night.
    dscReduce($this, 3)->assertOk();
    $day = Shift::where('date', '2029-02-12')->orderBy('shift_number')->get();
    expect($day->pluck('shift_number')->all())->toBe([1, 2, 3]);
    expect($day->map(fn ($s) => substr($s->start_time, 0, 5) . '-' . substr($s->end_time, 0, 5))->all())
        ->toBe(['06:00-14:00', '22:00-06:00', '14:00-22:00']);
});

function dscDay($test, array $slots): void
{
    Shift::where('date', '2029-02-12')->delete();
    foreach ($slots as $n => [$start, $end]) {
        Shift::create(['date' => '2029-02-12', 'shift_number' => $n + 1, 'shift_group_id' => $test->group->id,
            'start_time' => $start, 'end_time' => $end]);
    }
}

function dscTimes(): array
{
    return Shift::where('date', '2029-02-12')->orderBy('shift_number')->get()
        ->map(fn ($s) => substr($s->start_time, 0, 5) . '-' . substr($s->end_time, 0, 5))->all();
}

it('gives a new slot its positional preset times when the day does not have them yet', function () {
    // 1 -> 2 on a 24-hour day: slot 2 is the 2-shift preset's night (18-06), not its first entry.
    dscDay($this, [['00:00', '00:00']]);
    dscReduce($this, 2)->assertOk();
    expect(dscTimes())->toBe(['00:00-00:00', '18:00-06:00']);

    // Plain 2 -> 3 on a 2-shift day: slot 3 is the 3-shift preset's night (22-06).
    dscDay($this, [['06:00', '18:00'], ['18:00', '06:00']]);
    dscReduce($this, 3)->assertOk();
    expect(dscTimes())->toBe(['06:00-18:00', '18:00-06:00', '22:00-06:00']);

    // 2 -> 4: positions 3 and 4 of the 4-shift split (18-00, 00-06).
    dscDay($this, [['06:00', '18:00'], ['18:00', '06:00']]);
    dscReduce($this, 4)->assertOk();
    expect(dscTimes())->toBe(['06:00-18:00', '18:00-06:00', '18:00-00:00', '00:00-06:00']);
});

it('takes the first unused preset entry when the positional one is already on the day', function () {
    // After an empty-first removal (06-14, 22-06 left), slot 3's positional 22-06 is taken:
    // the removed afternoon comes back instead of a second night.
    dscDay($this, [['06:00', '14:00'], ['22:00', '06:00']]);
    dscReduce($this, 3)->assertOk();
    expect(dscTimes())->toBe(['06:00-14:00', '22:00-06:00', '14:00-22:00']);
});

it('refuses to remove staffed slots without confirmation, listing them, and deletes nothing', function () {
    dscStaff($this, 1);
    $a2 = dscStaff($this, 2);
    dscStaff($this, 3);
    ShiftChangeRequest::create([
        'shift_assignment_id' => $a2->id, 'user_id' => $a2->user_id, 'shift_id' => $a2->shift_id,
        'reason' => 'Doctor', 'status' => 'pending',
    ]);

    $res = dscReduce($this, 1)->assertStatus(409);

    expect($res->json('code'))->toBe('staffed_shifts_removal');
    expect($res->json('message'))->toContain('2 staffed shifts');
    expect($res->json('shifts'))->toBe([
        ['id' => $this->slots[2]->id, 'date' => '2029-02-12', 'shift_number' => 2, 'start_time' => '14:00', 'end_time' => '22:00',
            'assigned_users' => ['Ewa Nowak'], 'pending_change_requests' => 1],
        ['id' => $this->slots[3]->id, 'date' => '2029-02-12', 'shift_number' => 3, 'start_time' => '22:00', 'end_time' => '06:00',
            'assigned_users' => ['Ewa Nowak'], 'pending_change_requests' => 0],
    ]);
    expect(Shift::where('date', '2029-02-12')->count())->toBe(3);
    expect(ShiftAssignment::count())->toBe(3);
    expect(ShiftChangeRequest::count())->toBe(1);
});

it('removes staffed slots once confirmed, keeping handover submissions', function () {
    dscStaff($this, 1);
    dscStaff($this, 2);
    dscStaff($this, 3);
    $template = ChecklistTemplate::create(['name' => 'Handover', 'is_active' => true]);
    $submission = ChecklistSubmission::create([
        'checklist_template_id' => $template->id,
        'shift_id' => $this->slots[3]->id,
        'date' => '2029-02-12',
        'outgoing_user_id' => $this->employee->id,
    ]);

    dscReduce($this, 2, ['confirm_remove_staffed' => true])->assertOk();

    expect(Shift::find($this->slots[3]->id))->toBeNull();
    expect(Shift::where('date', '2029-02-12')->count())->toBe(2);
    expect(ShiftAssignment::count())->toBe(2);
    // The handover history stays, detached from the deleted slot.
    $kept = ChecklistSubmission::find($submission->id);
    expect($kept)->not->toBeNull();
    expect($kept->shift_id)->toBeNull();
    expect($kept->date->format('Y-m-d'))->toBe('2029-02-12');
});

it('asks again when the staffed slots changed since the manager confirmed', function () {
    dscStaff($this, 1);
    dscStaff($this, 2);
    dscStaff($this, 3);

    // The manager was shown only slot 3 (slot 2 has been staffed since).
    dscReduce($this, 1, ['confirm_remove_staffed' => true, 'expected_remove_ids' => [$this->slots[3]->id]])
        ->assertStatus(409);
    expect(Shift::where('date', '2029-02-12')->count())->toBe(3);

    dscReduce($this, 1, ['confirm_remove_staffed' => true, 'expected_remove_ids' => [$this->slots[3]->id, $this->slots[2]->id]])
        ->assertOk();
    expect(Shift::where('date', '2029-02-12')->pluck('id')->all())->toBe([$this->slots[1]->id]);
});

it('answers 404 when the shift is deleted while an assign is being placed', function () {
    // Deterministic stand-in for a concurrent slot removal: the shift goes away after the request's
    // validation and lookup, just before the assignment is written.
    $shift = $this->slots[2];
    app()->bind(\App\Services\StreakReplay::class, fn () => new class($shift->id) extends \App\Services\StreakReplay {
        public function __construct(private int $doomedShiftId) {}

        public function forGroup(\App\Models\ShiftGroup $group, array $userIds, ?\Carbon\Carbon $until = null): \App\Services\StreakTimeline
        {
            Shift::whereKey($this->doomedShiftId)->delete();

            return parent::forGroup($group, $userIds, $until);
        }
    });

    $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $shift->id,
        'user_id' => $this->employee->id,
    ])->assertNotFound()->assertJson(['message' => 'This shift no longer exists.']);

    expect(ShiftAssignment::count())->toBe(0);
});
