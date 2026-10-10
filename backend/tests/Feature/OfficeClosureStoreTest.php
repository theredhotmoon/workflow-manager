<?php

use App\Models\OfficeClosure;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\User;

// L10: a closure range is capped (366 days), the answer counts only the days actually created,
// and the conflict check lists the office-mode shifts already planned on closed days.

function ocAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

it('refuses a closure range longer than 366 days', function () {
    $this->actingAs(ocAdmin())->postJson('/api/office-closures', [
        'start_date' => '2000-01-01', 'end_date' => '2100-01-01', 'title' => 'Forever',
    ])->assertUnprocessable()->assertJsonValidationErrors('end_date');

    expect(OfficeClosure::count())->toBe(0);
});

it('accepts a range of exactly 366 days', function () {
    $this->actingAs(ocAdmin())->postJson('/api/office-closures', [
        'start_date' => '2028-01-01', 'end_date' => '2028-12-31', 'title' => 'Leap year',
    ])->assertCreated()->assertJsonPath('created', 366);
});

it('counts only the days it created, not ones already closed', function () {
    $admin = ocAdmin();
    OfficeClosure::create(['date' => '2026-12-25', 'title' => 'Christmas', 'created_by' => $admin->id]);

    $this->actingAs($admin)->postJson('/api/office-closures', [
        'start_date' => '2026-12-24', 'end_date' => '2026-12-26', 'title' => 'Holidays',
    ])->assertCreated()
        ->assertJsonPath('created', 2)
        ->assertJsonPath('message', 'Created 2 closure(s). 1 day(s) were already closed.');

    // The existing closure keeps its title.
    expect(OfficeClosure::where('date', '2026-12-25')->value('title'))->toBe('Christmas')
        ->and(OfficeClosure::count())->toBe(3);
});

it('lists the office-mode shifts planned on closed days', function () {
    $admin = ocAdmin();
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    OfficeClosure::create(['date' => '2026-12-24', 'title' => 'Christmas Eve', 'created_by' => $admin->id]);
    OfficeClosure::create(['date' => '2026-12-27', 'title' => 'Inventory', 'created_by' => $admin->id]);
    $office = Shift::create(['date' => '2026-12-24', 'shift_number' => 1, 'shift_group_id' => $group->id, 'work_mode' => 'office', 'start_time' => '08:00', 'end_time' => '16:00']);
    Shift::create(['date' => '2026-12-24', 'shift_number' => 2, 'shift_group_id' => $group->id, 'work_mode' => 'remote']);
    ShiftAssignment::create(['shift_id' => $office->id, 'user_id' => User::factory()->create()->id]);
    // An office shift on a day that isn't closed is no conflict.
    Shift::create(['date' => '2026-12-23', 'shift_number' => 1, 'shift_group_id' => $group->id, 'work_mode' => 'office']);

    $response = $this->actingAs($admin)->getJson('/api/office-closures/conflicts?month=2026-12')->assertOk();

    // Still the month's closures (the generate dialog reads those), each with its office shifts.
    expect($response->json('*.date'))->toBe(['2026-12-24', '2026-12-27'])
        ->and($response->json('0.office_shifts'))->toBe([[
            'id' => $office->id, 'date' => '2026-12-24', 'shift_number' => 1, 'shift_group_id' => $group->id,
            'shift_group_name' => 'Main', 'start_time' => '08:00', 'end_time' => '16:00', 'assigned_count' => 1,
        ]])
        ->and($response->json('1.office_shifts'))->toBe([]);
});
