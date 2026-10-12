<?php

use App\Models\Procedure;
use App\Models\User;
use App\Support\AdvisoryLock;
use Illuminate\Support\Facades\DB;

// L9: a procedure identifier is never handed out twice. Archived procedures keep theirs (PR #38
// made delete an archive), the next number comes from the highest PROC-n of every procedure,
// and two concurrent creates are serialised by an advisory lock instead of colliding.

function piCreate(User $admin, string $title = 'New procedure'): string
{
    return test()->actingAs($admin)
        ->postJson('/api/procedures', ['title' => $title, 'owner_id' => $admin->id])
        ->assertCreated()
        ->json('identifier');
}

it('never reuses the identifier of an archived procedure', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $first = piCreate($admin, 'First');
    $second = piCreate($admin, 'Second');
    $procedure = Procedure::where('identifier', $second)->sole();

    $this->actingAs($admin)->deleteJson("/api/procedures/{$procedure->id}")->assertOk();

    expect([$first, $second])->toBe(['PROC-01', 'PROC-02'])
        ->and(piCreate($admin, 'Third'))->toBe('PROC-03');
});

it('continues from the highest PROC number, not from the last row', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Procedure::create(['identifier' => 'PROC-10', 'title' => 'Imported first', 'owner_id' => $admin->id]);
    Procedure::create(['identifier' => 'PROC-02', 'title' => 'Imported later', 'owner_id' => $admin->id]);
    Procedure::create(['identifier' => 'LEGACY-7', 'title' => 'Other scheme', 'owner_id' => $admin->id]);

    expect(piCreate($admin))->toBe('PROC-11');
});

it('takes the identifier lock, so a concurrent create waits instead of taking the same number', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $side = sideConnection();
    // The concurrent create, still inside its transaction, holds the lock.
    $side->beginTransaction();
    $side->select('SELECT pg_advisory_xact_lock(?)', [AdvisoryLock::key(Procedure::IDENTIFIER_LOCK)]);

    try {
        // This create waits for it; with a short lock_timeout the wait ends in an error instead.
        DB::statement("SET LOCAL lock_timeout = '300ms'");
        $this->actingAs($admin)
            ->postJson('/api/procedures', ['title' => 'Blocked', 'owner_id' => $admin->id])
            ->assertServerError();
        expect(Procedure::count())->toBe(0);
    } finally {
        $side->rollBack();
        DB::statement('SET LOCAL lock_timeout = 0');
    }

    expect(piCreate($admin, 'After the other one'))->toBe('PROC-01');
});
