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

it('refuses to delete a delegation someone has joined and points to Archive', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $member = User::factory()->create(['role' => 'employee', 'name' => 'Ada Member']);
    $delegation = dldDelegation($admin, $member);

    $this->actingAs($admin)->deleteJson("/api/delegations/{$delegation->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'has_members')
        ->assertJsonPath('message', 'This delegation has members (Ada Member), so it can\'t be deleted. Archive it instead.')
        ->assertJsonPath('members', ['Ada Member']);

    expect($delegation->fresh())->not->toBeNull()
        ->and(DB::table('delegation_user')->where('delegation_id', $delegation->id)->count())->toBe(1)
        ->and(DelegationLog::where('delegation_id', $delegation->id)->count())->toBe(1);
});

it('deletes a delegation nobody has joined, with its pending and rejected requests and its log', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $delegation = Delegation::create([
        'name' => 'Trip', 'destination' => 'Berlin', 'created_by' => $admin->id,
        'start_datetime' => '2026-11-01 08:00:00', 'end_datetime' => '2026-11-02 18:00:00',
    ]);
    $delegation->users()->attach(User::factory()->create()->id, ['status' => 'pending']);
    $delegation->users()->attach(User::factory()->create()->id, ['status' => 'rejected']);
    DelegationLog::create(['delegation_id' => $delegation->id, 'performed_by' => $admin->id, 'action' => 'created']);

    $this->actingAs($admin)->deleteJson("/api/delegations/{$delegation->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Delegation deleted.');

    expect($delegation->fresh())->toBeNull()
        ->and(DB::table('delegation_user')->where('delegation_id', $delegation->id)->count())->toBe(0)
        ->and(DelegationLog::where('delegation_id', $delegation->id)->count())->toBe(0)
        ->and($admin->fresh())->not->toBeNull();
});

it('answers 404, not a server error, when the delegation is deleted while a member is approved or added', function (string $action) {
    $admin = User::factory()->create(['role' => 'admin']);
    $member = User::factory()->create(['role' => 'employee']);
    $delegation = Delegation::create([
        'name' => 'Trip', 'destination' => 'Berlin', 'created_by' => $admin->id,
        'start_datetime' => '2026-11-01 08:00:00', 'end_datetime' => '2026-11-02 18:00:00',
    ]);
    $delegation->users()->attach($member->id, ['status' => 'pending']);
    // Deterministic stand-in for a concurrent delete: it lands right after route binding found the row.
    $fired = false;
    DB::listen(function ($query) use (&$fired, $delegation) {
        if ($fired || !str_contains($query->sql, 'from "delegations"')) {
            return;
        }
        $fired = true;
        DB::table('delegations')->where('id', $delegation->id)->delete();
    });

    $this->actingAs($admin)->postJson("/api/delegations/{$delegation->id}/{$action}", ['user_id' => $member->id])
        ->assertNotFound()
        ->assertJsonPath('message', 'This delegation no longer exists.');

    expect(DelegationLog::where('delegation_id', $delegation->id)->count())->toBe(0);
})->with(['approve-user', 'add-user']);

it('answers 404 and archives nothing when the delegation is deleted while it is being archived', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $delegation = dldDelegation($admin, User::factory()->create(['role' => 'employee']));
    // Deterministic stand-in for a concurrent delete: it lands right after route binding found the row.
    $fired = false;
    DB::listen(function ($query) use (&$fired, $delegation) {
        if ($fired || !str_contains($query->sql, 'from "delegations"')) {
            return;
        }
        $fired = true;
        DB::table('delegations')->where('id', $delegation->id)->delete();
    });

    $this->actingAs($admin)->postJson("/api/delegations/{$delegation->id}/archive")
        ->assertNotFound()
        ->assertJsonPath('message', 'This delegation no longer exists.');

    expect(\App\Models\ArchivedDelegation::where('original_id', $delegation->id)->count())->toBe(0);
});
