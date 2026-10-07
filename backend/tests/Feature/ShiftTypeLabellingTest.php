<?php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\User;

function stlMaintainer(): User
{
    return User::factory()->create(['role' => 'schedule_maintainer']);
}

function stlGroup(string $name = 'Main'): ShiftGroup
{
    return ShiftGroup::create(['name' => $name, 'is_default' => $name === 'Main', 'sort_order' => 0]);
}

it('generate stores each slot\'s shift_type normalised and leaves untyped slots null', function () {
    $group = stlGroup();

    $this->actingAs(stlMaintainer())->postJson('/api/schedule/generate', [
        'month' => '2027-03',
        'shift_group_id' => $group->id,
        'shifts_per_day' => 2,
        'shift_times' => [
            ['start_time' => '22:00', 'end_time' => '06:00', 'shift_type' => ' Night '],
            ['start_time' => '06:00', 'end_time' => '14:00'],
        ],
    ])->assertOk();

    expect(Shift::where('shift_group_id', $group->id)->where('shift_number', 1)->distinct()->pluck('shift_type')->all())->toBe(['night']);
    expect(Shift::where('shift_group_id', $group->id)->where('shift_number', 2)->distinct()->pluck('shift_type')->all())->toBe([null]);
});

it('generate does not change the type of a slot that already exists', function () {
    $group = stlGroup();
    $existing = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day']);

    $this->actingAs(stlMaintainer())->postJson('/api/schedule/generate', [
        'month' => '2027-03',
        'shift_group_id' => $group->id,
        'shifts_per_day' => 1,
        'shift_times' => [['start_time' => '22:00', 'end_time' => '06:00', 'shift_type' => 'night']],
    ])->assertOk();

    expect($existing->fresh()->shift_type)->toBe('day');
    expect(Shift::where('shift_group_id', $group->id)->where('date', '2027-03-02')->value('shift_type'))->toBe('night');
});

it('generate rejects a shift_type longer than 50 characters', function () {
    $group = stlGroup();

    $this->actingAs(stlMaintainer())->postJson('/api/schedule/generate', [
        'month' => '2027-03',
        'shift_group_id' => $group->id,
        'shifts_per_day' => 1,
        'shift_times' => [['start_time' => '22:00', 'end_time' => '06:00', 'shift_type' => str_repeat('x', 51)]],
    ])->assertStatus(422)->assertJsonValidationErrors(['shift_times.0.shift_type']);
});

it('includes shift_type in the schedule payload', function () {
    $group = stlGroup();
    Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);

    $response = $this->actingAs(stlMaintainer())->getJson("/api/schedule?month=2027-03&shift_group_id={$group->id}");

    $response->assertOk();
    expect($response->json('schedule.2027-03-01.0.shift_type'))->toBe('night');
});
