<?php

use App\Models\Procedure;
use App\Models\User;

// L7: every list filter is validated, so a bad date, id or month answers 422 naming the filter
// (not a 500 from PostgreSQL), and per_page is clamped to 1..cap.

function fvAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function fvProcedure(User $owner): Procedure
{
    return Procedure::create([
        'identifier' => 'PROC-01',
        'title' => 'Escalation',
        'version' => 1,
        'owner_id' => $owner->id,
    ]);
}

it('validates the procedure list filters', function () {
    $this->actingAs(fvAdmin());

    $this->getJson('/api/procedures?created_after=yesterday-ish')->assertUnprocessable()->assertJsonValidationErrors('created_after');
    $this->getJson('/api/procedures?updated_before=2026-13-45')->assertUnprocessable()->assertJsonValidationErrors('updated_before');
    $this->getJson('/api/procedures?tags=abc')->assertUnprocessable()->assertJsonValidationErrors('tags.0');
    $this->getJson('/api/procedures?node_id=abc')->assertUnprocessable()->assertJsonValidationErrors('node_id');
    $this->getJson('/api/procedures?tags=1,2&node_id=root&created_after=2026-01-01')->assertOk();
});

it('clamps the procedure list per_page to 1..100', function () {
    $this->actingAs(fvAdmin());

    $this->getJson('/api/procedures?per_page=-5')->assertOk()->assertJsonPath('per_page', 1);
    $this->getJson('/api/procedures?per_page=100000')->assertOk()->assertJsonPath('per_page', 100);
});

it('validates the procedure access log filters', function () {
    $admin = fvAdmin();
    $procedure = fvProcedure($admin);
    $this->actingAs($admin);

    $this->getJson("/api/procedures/{$procedure->id}/access-log?user_id=abc")->assertUnprocessable()->assertJsonValidationErrors('user_id');
    $this->getJson("/api/procedures/{$procedure->id}/access-log?version=v2")->assertUnprocessable()->assertJsonValidationErrors('version');
    $this->getJson("/api/procedures/{$procedure->id}/access-log?date_from=soon")->assertUnprocessable()->assertJsonValidationErrors('date_from');
    $this->getJson("/api/procedures/{$procedure->id}/access-log?user_id={$admin->id}&version=1&date_from=2026-01-01")->assertOk();
});

it('validates the handover archive filters and clamps per_page', function () {
    $this->actingAs(fvAdmin());

    $this->getJson('/api/checklist-submissions?date_from=01.10.2026')->assertUnprocessable()->assertJsonValidationErrors('date_from');
    $this->getJson('/api/checklist-submissions?template_id=abc')->assertUnprocessable()->assertJsonValidationErrors('template_id');
    $this->getJson('/api/checklist-submissions?status=whatever')->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->getJson('/api/checklist-submissions?per_page=-5')->assertOk()->assertJsonPath('per_page', 1);
});

it('validates the alarm list filters', function () {
    $this->actingAs(User::factory()->create());

    $this->getJson('/api/alarms?date_from=nope')->assertUnprocessable()->assertJsonValidationErrors('date_from');
    $this->getJson('/api/alarms?date_to=2026-02-30')->assertUnprocessable()->assertJsonValidationErrors('date_to');
    $this->getJson('/api/alarms?status=maybe')->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->getJson('/api/alarms?status=unread&date_from=2026-01-01')->assertOk();
});

it('validates the schedule group filter', function () {
    $this->actingAs(User::factory()->create());

    $this->getJson('/api/schedule?month=2026-10&shift_group_id=abc')->assertUnprocessable()->assertJsonValidationErrors('shift_group_id');
    $this->getJson('/api/schedule?month=2026-10&shift_group_id=all')->assertOk();
    $this->getJson('/api/shifts-by-date?date=2026-10-01&shift_group_id=abc')->assertUnprocessable()->assertJsonValidationErrors('shift_group_id');
    $this->getJson('/api/shifts-by-date?date=2026-10-01')->assertOk();
});

it('validates the office closure month filter', function () {
    $this->actingAs(fvAdmin());

    $this->getJson('/api/office-closures?month=October')->assertUnprocessable()->assertJsonValidationErrors('month');
    $this->getJson('/api/office-closures?month=2026-10')->assertOk();
    $this->getJson('/api/office-closures')->assertOk();
});

it('validates the delegation archive filters', function () {
    $this->actingAs(fvAdmin());

    $this->getJson('/api/delegation-archive?start_from=someday')->assertUnprocessable()->assertJsonValidationErrors('start_from');
    $this->getJson('/api/delegation-archive?end_to=2026-99-01')->assertUnprocessable()->assertJsonValidationErrors('end_to');
    $this->getJson('/api/delegation-archive?employee_id=abc')->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    $this->getJson('/api/delegation-archive?start_from=2026-01-01&employee_id=1&destination=Kra')->assertOk();
});

it('validates the procedure feedback status filter', function () {
    $this->actingAs(fvAdmin());

    $this->getJson('/api/procedure-feedbacks?status=closed')->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->getJson('/api/procedure-feedbacks?status=open')->assertOk();
});

it('refuses an access log version beyond the column range (review M1)', function () {
    $admin = fvAdmin();
    $procedure = fvProcedure($admin);

    $this->actingAs($admin)->getJson("/api/procedures/{$procedure->id}/access-log?version=3000000000")
        ->assertUnprocessable()->assertJsonValidationErrors('version');
    $this->actingAs($admin)->getJson("/api/procedures/{$procedure->id}/access-log?version=2147483647")->assertOk();
});
