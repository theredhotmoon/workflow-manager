<?php

use App\Models\Alarm;
use App\Models\Delegation;
use App\Models\Procedure;
use App\Models\ProcedureFeedback;
use App\Models\User;
use Illuminate\Support\Carbon;

// L19: nobody can apply for a delegation after its last day (business date, last day included).
// L20: a feedback author is told once that it was resolved, not on every later save.

function lrDelegation(string $end): Delegation
{
    return Delegation::create([
        'name' => 'Fair', 'destination' => 'Berlin', 'start_datetime' => '2026-10-01', 'end_datetime' => $end,
        'registration_open' => true, 'created_by' => User::factory()->create(['role' => 'admin'])->id,
    ]);
}

it('refuses to register for a delegation that has ended (L19)', function () {
    // 00:30 on Oct 10 in Warsaw, still Oct 9 in UTC: the business date is the 10th.
    Carbon::setTestNow(Carbon::parse('2026-10-09 22:30:00', 'UTC'));
    $employee = User::factory()->create();
    $delegation = lrDelegation('2026-10-09');

    $this->actingAs($employee)->postJson("/api/delegations/{$delegation->id}/self-register")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This delegation has ended; registration is closed.');

    expect($delegation->users()->count())->toBe(0);
});

it('still lets you register on a delegation\'s last day (L19)', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-09 21:30:00', 'UTC')); // 23:30 in Warsaw
    $employee = User::factory()->create();
    $delegation = lrDelegation('2026-10-09');

    $this->actingAs($employee)->postJson("/api/delegations/{$delegation->id}/self-register")->assertOk();

    expect($delegation->users()->count())->toBe(1);
});

it('notifies the author only when feedback becomes resolved (L20)', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $author = User::factory()->create();
    $procedure = Procedure::create(['identifier' => 'PROC-01', 'title' => 'Escalation', 'owner_id' => $admin->id]);
    $feedback = ProcedureFeedback::create(['procedure_id' => $procedure->id, 'user_id' => $author->id, 'description' => 'Typo', 'status' => 'open']);
    $resolvedAlarms = fn () => Alarm::where('user_id', $author->id)->where('type', Alarm::TYPE_PROCEDURE_ISSUE_SORTED)->count();

    $this->actingAs($admin)->putJson("/api/procedure-feedbacks/{$feedback->id}", ['status' => 'resolved'])->assertOk();
    expect($resolvedAlarms())->toBe(1);

    // Saving it again as resolved (a comment, a resolution) isn't news.
    $this->actingAs($admin)->putJson("/api/procedure-feedbacks/{$feedback->id}", ['status' => 'resolved', 'owner_comment' => 'Fixed in v2'])->assertOk();
    $this->actingAs($admin)->putJson("/api/procedure-feedbacks/{$feedback->id}", ['status' => 'resolved', 'resolution' => 'accepted'])->assertOk();
    expect($resolvedAlarms())->toBe(1);

    // Re-opened and resolved again: that is news.
    $this->actingAs($admin)->putJson("/api/procedure-feedbacks/{$feedback->id}", ['status' => 'open'])->assertOk();
    $this->actingAs($admin)->putJson("/api/procedure-feedbacks/{$feedback->id}", ['status' => 'resolved'])->assertOk();
    expect($resolvedAlarms())->toBe(2);
});
