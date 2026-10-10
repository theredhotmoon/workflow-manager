<?php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\User;
use Illuminate\Support\Carbon;

// L18: with several shift groups, /current-shift lists the shift on now in every group (each with
// its crew), or only the asked group's with shift_group_id; archived groups are left out.
// active_shift stays for older clients: the first of the list.

beforeEach(function () {
    // 10:00 in Warsaw (CEST, UTC+2).
    Carbon::setTestNow(Carbon::parse('2026-10-09 08:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->north = ShiftGroup::create(['name' => 'North', 'sort_order' => 1]);
    $this->south = ShiftGroup::create(['name' => 'South', 'sort_order' => 0]);
});

function csgShift(ShiftGroup $group, string $date, string $start, string $end, array $crew = []): Shift
{
    $shift = Shift::create([
        'date' => $date, 'shift_number' => Shift::where('shift_group_id', $group->id)->where('date', $date)->count() + 1,
        'shift_group_id' => $group->id, 'start_time' => $start, 'end_time' => $end,
    ]);
    foreach ($crew as $user) {
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id]);
    }

    return $shift;
}

it('lists the active shift of every group, in group order, with its crew', function () {
    $ann = User::factory()->create(['name' => 'Ann']);
    $bob = User::factory()->create(['name' => 'Bob']);
    $northDay = csgShift($this->north, '2026-10-09', '06:00', '14:00', [$ann]);
    csgShift($this->north, '2026-10-09', '14:00', '22:00');
    // South's night shift from yesterday is still on until 12:00.
    $southNight = csgShift($this->south, '2026-10-08', '22:00', '12:00', [$bob]);

    $response = $this->actingAs($this->user)->getJson('/api/current-shift')->assertOk();

    expect($response->json('active_shifts.*.id'))->toBe([$southNight->id, $northDay->id])
        ->and($response->json('active_shifts.*.shift_group_name'))->toBe(['South', 'North'])
        ->and($response->json('active_shifts.0.crew.*.name'))->toBe(['Bob'])
        ->and($response->json('active_shifts.1.crew.*.name'))->toBe(['Ann'])
        ->and($response->json('active_shift.id'))->toBe($southNight->id);
});

it('lists only the asked group\'s active shift with shift_group_id', function () {
    $northDay = csgShift($this->north, '2026-10-09', '06:00', '14:00');
    csgShift($this->south, '2026-10-09', '08:00', '16:00');

    $response = $this->actingAs($this->user)->getJson("/api/current-shift?shift_group_id={$this->north->id}")->assertOk();

    expect($response->json('active_shifts.*.id'))->toBe([$northDay->id])
        ->and($response->json('active_shift.id'))->toBe($northDay->id);
});

it('leaves archived groups out', function () {
    csgShift($this->north, '2026-10-09', '06:00', '14:00');
    $this->north->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->user)->getJson('/api/current-shift')->assertOk()
        ->assertJsonPath('active_shifts', [])
        ->assertJsonPath('active_shift', null);
});

it('validates shift_group_id', function () {
    $this->actingAs($this->user)->getJson('/api/current-shift?shift_group_id=abc')
        ->assertUnprocessable()->assertJsonValidationErrors('shift_group_id');
});
