<?php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\User;

it('lets a schedule maintainer set a shift shift_type', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::where('is_default', true)->first() ?? ShiftGroup::create(['name' => 'Main', 'is_default' => true]);
    $shift = Shift::create([
        'date' => '2026-09-07',
        'shift_number' => 1,
        'shift_group_id' => $group->id,
        'start_time' => '06:00',
        'end_time' => '14:00',
    ]);

    $response = $this->actingAs($maintainer)
        ->putJson("/api/schedule/shifts/{$shift->id}/shift-type", ['shift_type' => 'night']);

    $response->assertOk()->assertJson(['id' => $shift->id, 'shift_type' => 'night']);
    $this->assertDatabaseHas('shifts', ['id' => $shift->id, 'shift_type' => 'night']);
});

it('rejects a plain employee', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $group = ShiftGroup::where('is_default', true)->first() ?? ShiftGroup::create(['name' => 'Main', 'is_default' => true]);
    $shift = Shift::create([
        'date' => '2026-09-07',
        'shift_number' => 1,
        'shift_group_id' => $group->id,
        'start_time' => '06:00',
        'end_time' => '14:00',
    ]);

    $this->actingAs($employee)
        ->putJson("/api/schedule/shifts/{$shift->id}/shift-type", ['shift_type' => 'night'])
        ->assertStatus(403);
});

it('includes shift_type in the schedule index payload', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $group = ShiftGroup::where('is_default', true)->first() ?? ShiftGroup::create(['name' => 'Main', 'is_default' => true]);
    $shift = Shift::create([
        'date' => '2026-09-07',
        'shift_number' => 1,
        'shift_group_id' => $group->id,
        'start_time' => '06:00',
        'end_time' => '14:00',
        'shift_type' => 'night',
    ]);

    $response = $this->actingAs($employee)
        ->getJson('/api/schedule?month=2026-09');

    $response->assertOk();
    $shiftPayload = collect($response->json('schedule.2026-09-07'))
        ->firstWhere('id', $shift->id);

    expect($shiftPayload)->not->toBeNull();
    expect($shiftPayload['shift_type'])->toBe('night');
});
