<?php

use App\Models\Delegation;
use App\Models\DelegationLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function dldDelegation(User $creator, User $member): Delegation
{
    $delegation = Delegation::create([
        'name' => 'Trip', 'destination' => 'Berlin', 'created_by' => $creator->id,
        'start_datetime' => '2026-11-01 08:00:00', 'end_datetime' => '2026-11-02 18:00:00',
    ]);
    $delegation->users()->attach($member->id, ['status' => 'approved']);
    DelegationLog::create(['delegation_id' => $delegation->id, 'performed_by' => $creator->id, 'action' => 'created']);

    return $delegation;
}

it('refuses a delegation delete to anyone but an admin', function (string $role) {
    $member = User::factory()->create(['role' => 'employee']);
    $delegation = dldDelegation(User::factory()->create(['role' => 'admin']), $member);

    $this->actingAs(User::factory()->create(['role' => $role]))
        ->deleteJson("/api/delegations/{$delegation->id}")
        ->assertForbidden();

    expect($delegation->fresh())->not->toBeNull();
})->with(['employee', 'schedule_maintainer']);

it('deletes a delegation with its members and its log', function () {
    // Current behaviour: cascades; see follow-up. Deleting (rather than archiving) a delegation
    // drops who took part and its whole action log; the people stay.
    $admin = User::factory()->create(['role' => 'admin']);
    $member = User::factory()->create(['role' => 'employee']);
    $delegation = dldDelegation($admin, $member);

    $this->actingAs($admin)->deleteJson("/api/delegations/{$delegation->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Delegation deleted.');

    expect($delegation->fresh())->toBeNull()
        ->and(DB::table('delegation_user')->where('delegation_id', $delegation->id)->count())->toBe(0)
        ->and(DelegationLog::where('delegation_id', $delegation->id)->count())->toBe(0)
        ->and($member->fresh())->not->toBeNull()
        ->and($admin->fresh())->not->toBeNull();
});
