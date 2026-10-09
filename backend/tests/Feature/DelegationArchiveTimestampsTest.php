<?php

use App\Models\ArchivedDelegation;
use App\Models\ArchivedDelegationLog;
use App\Models\Delegation;
use App\Models\DelegationLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * M9: archiving / unarchiving a delegation must keep every timestamp it
 * carries (the delegation's own created_at/updated_at and each log's
 * created_at) instead of stamping "now" on all of them.
 */
function seedDelegationWithHistory(User $creator, User $member): Delegation
{
    $delegation = Delegation::create([
        'name' => 'Berlin trade fair',
        'destination' => 'Berlin',
        'start_datetime' => '2026-11-02 08:00:00',
        'end_datetime' => '2026-11-04 18:00:00',
        'created_by' => $creator->id,
    ]);
    DB::table('delegations')->where('id', $delegation->id)->update([
        'created_at' => '2026-01-05 09:15:00',
        'updated_at' => '2026-02-10 10:20:00',
    ]);
    $delegation->users()->attach($member->id, ['status' => 'approved']);
    DB::table('delegation_user')->where('delegation_id', $delegation->id)->update([
        'created_at' => '2026-01-06 11:00:00',
        'updated_at' => '2026-01-07 12:00:00',
    ]);

    DB::table('delegation_logs')->insert([
        ['delegation_id' => $delegation->id, 'performed_by' => $creator->id, 'action' => 'created', 'details' => 'one', 'created_at' => '2026-01-05 09:15:00'],
        ['delegation_id' => $delegation->id, 'performed_by' => $creator->id, 'action' => 'user_added', 'details' => 'two', 'created_at' => '2026-01-06 11:00:00'],
        ['delegation_id' => $delegation->id, 'performed_by' => $member->id, 'action' => 'self_registered', 'details' => 'three', 'created_at' => '2026-03-01 07:45:30'],
    ]);

    return $delegation->fresh();
}

function logTimesByAction(string $table, string $fk, int $id): array
{
    return DB::table($table)->where($fk, $id)->orderBy('id')
        ->pluck('created_at', 'action')
        ->map(fn ($t) => substr((string) $t, 0, 19))
        ->all();
}

it('keeps delegation and log timestamps through archive and unarchive', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $member = User::factory()->create();
    $delegation = seedDelegationWithHistory($admin, $member);

    $expectedLogs = [
        'created' => '2026-01-05 09:15:00',
        'user_added' => '2026-01-06 11:00:00',
        'self_registered' => '2026-03-01 07:45:30',
    ];

    // Archive
    $this->actingAs($admin)->postJson("/api/delegations/{$delegation->id}/archive")->assertOk();

    $archived = ArchivedDelegation::where('original_id', $delegation->id)->firstOrFail();
    expect(substr($archived->getRawOriginal('created_at'), 0, 19))->toBe('2026-01-05 09:15:00');
    expect(substr($archived->getRawOriginal('updated_at'), 0, 19))->toBe('2026-02-10 10:20:00');

    $archivedLogs = logTimesByAction('archived_delegation_logs', 'archived_delegation_id', $archived->id);
    foreach ($expectedLogs as $action => $time) {
        expect($archivedLogs[$action])->toBe($time);
    }
    expect($archivedLogs)->toHaveKey('archived'); // the new entry itself is "now"

    $pivot = DB::table('archived_delegation_user')->where('archived_delegation_id', $archived->id)->first();
    expect(substr($pivot->created_at, 0, 19))->toBe('2026-01-06 11:00:00');

    // Unarchive
    $this->actingAs($admin)->postJson("/api/delegation-archive/{$archived->id}/unarchive")->assertOk();

    $restored = Delegation::where('name', 'Berlin trade fair')->firstOrFail();
    expect(substr($restored->getRawOriginal('created_at'), 0, 19))->toBe('2026-01-05 09:15:00');
    expect(substr($restored->getRawOriginal('updated_at'), 0, 19))->toBe('2026-02-10 10:20:00');

    $restoredLogs = logTimesByAction('delegation_logs', 'delegation_id', $restored->id);
    foreach ($expectedLogs as $action => $time) {
        expect($restoredLogs[$action])->toBe($time);
    }
    expect($restoredLogs)->toHaveKeys(['archived', 'unarchived']);
    expect(DelegationLog::where('delegation_id', $restored->id)->count())->toBe(5);
    expect(ArchivedDelegationLog::count())->toBe(0);

    $restoredPivot = DB::table('delegation_user')->where('delegation_id', $restored->id)->first();
    expect(substr($restoredPivot->created_at, 0, 19))->toBe('2026-01-06 11:00:00');
});
