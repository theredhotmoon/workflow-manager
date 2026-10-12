<?php

use App\Models\Delegation;
use App\Models\DelegationLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// M5 (#40 review): changing a delegation's dates left members' own travel dates outside it. They are
// now clamped into the new range in the same transaction; a member whose range no longer overlaps
// at all goes back to the delegation's own dates. Each change is logged, and the answer says how
// many members were adjusted.

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->delegation = Delegation::create([
        'name' => 'Trade fair', 'destination' => 'Berlin',
        'start_datetime' => '2026-11-01', 'end_datetime' => '2026-11-10',
        'registration_open' => true, 'created_by' => $this->admin->id,
    ]);
});

function dcMember(Delegation $delegation, ?string $start, ?string $end, string $status = 'approved'): User
{
    $user = User::factory()->create();
    $delegation->users()->attach($user->id, ['status' => $status, 'custom_start' => $start, 'custom_end' => $end]);

    return $user;
}

/** [custom_start, custom_end] as stored. */
function dcDates(Delegation $delegation, User $user): array
{
    $row = DB::table('delegation_user')->where('delegation_id', $delegation->id)->where('user_id', $user->id)->first();

    return [$row->custom_start ? substr($row->custom_start, 0, 10) : null, $row->custom_end ? substr($row->custom_end, 0, 10) : null];
}

it('clamps members\' travel dates into the shortened delegation and says how many changed', function () {
    $inside = dcMember($this->delegation, '2026-11-03', '2026-11-05');
    $overEnd = dcMember($this->delegation, '2026-11-04', '2026-11-09');
    $openStart = dcMember($this->delegation, null, '2026-11-09');
    $beforeStart = dcMember($this->delegation, '2026-11-01', null, 'pending');
    $noDates = dcMember($this->delegation, null, null);

    $this->actingAs($this->admin)
        ->putJson("/api/delegations/{$this->delegation->id}", ['start_datetime' => '2026-11-02', 'end_datetime' => '2026-11-06'])
        ->assertOk()
        ->assertJsonPath('adjusted_members', 3)
        ->assertJsonPath('end_datetime', fn ($v) => str_starts_with($v, '2026-11-06'));

    expect(dcDates($this->delegation, $inside))->toBe(['2026-11-03', '2026-11-05'])
        ->and(dcDates($this->delegation, $overEnd))->toBe(['2026-11-04', '2026-11-06'])
        ->and(dcDates($this->delegation, $openStart))->toBe([null, '2026-11-06'])
        ->and(dcDates($this->delegation, $beforeStart))->toBe(['2026-11-02', null])
        ->and(dcDates($this->delegation, $noDates))->toBe([null, null]);

    $logs = DelegationLog::where('delegation_id', $this->delegation->id)->where('action', 'dates_adjusted')->pluck('details');
    expect($logs)->toHaveCount(3)
        ->and($logs->contains(fn ($d) => str_contains($d, $overEnd->name) && str_contains($d, '2026-11-04 to 2026-11-09 → 2026-11-04 to 2026-11-06')))->toBeTrue();
});

it('resets a member whose dates fall wholly outside the new range to the delegation\'s dates', function () {
    $late = dcMember($this->delegation, '2026-11-08', '2026-11-10');
    $lateStartOnly = dcMember($this->delegation, '2026-11-09', null);

    $this->actingAs($this->admin)
        ->putJson("/api/delegations/{$this->delegation->id}", ['end_datetime' => '2026-11-05'])
        ->assertOk()
        ->assertJsonPath('adjusted_members', 2);

    expect(dcDates($this->delegation, $late))->toBe([null, null])
        ->and(dcDates($this->delegation, $lateStartOnly))->toBe([null, null]);
    $details = DelegationLog::where('action', 'dates_adjusted')->pluck('details');
    expect($details->filter(fn ($d) => str_contains($d, "reset to the delegation's dates")))->toHaveCount(2);
});

it('changes nothing when the dates stay or only grow', function () {
    $member = dcMember($this->delegation, '2026-11-03', '2026-11-05');

    $this->actingAs($this->admin)
        ->putJson("/api/delegations/{$this->delegation->id}", ['name' => 'Renamed'])
        ->assertOk()->assertJsonPath('adjusted_members', 0);
    $this->actingAs($this->admin)
        ->putJson("/api/delegations/{$this->delegation->id}", ['start_datetime' => '2026-10-25', 'end_datetime' => '2026-11-20'])
        ->assertOk()->assertJsonPath('adjusted_members', 0);

    expect(dcDates($this->delegation, $member))->toBe(['2026-11-03', '2026-11-05'])
        ->and(DelegationLog::where('action', 'dates_adjusted')->count())->toBe(0);
});

it('rolls the clamp back with the edit when the edit fails', function () {
    $member = dcMember($this->delegation, '2026-11-04', '2026-11-09');
    // Logging the member's change fails: the new dates and the clamped member go back together.
    DB::statement("ALTER TABLE delegation_logs ADD CONSTRAINT dc_no_adjusted CHECK (action <> 'dates_adjusted')");

    $this->actingAs($this->admin)
        ->putJson("/api/delegations/{$this->delegation->id}", ['end_datetime' => '2026-11-06'])
        ->assertServerError();

    expect($this->delegation->fresh()->end_datetime->format('Y-m-d'))->toBe('2026-11-10')
        ->and(dcDates($this->delegation, $member))->toBe(['2026-11-04', '2026-11-09']);
});

/**
 * Simulates another admin's edit committing between this request's route binding and its row lock:
 * the first time the delegation is read (the binding), the row is changed underneath.
 */
function dcChangeAfterBinding(Delegation $delegation, array $columns): void
{
    $done = false;
    Delegation::retrieved(function (Delegation $read) use ($delegation, $columns, &$done) {
        if (! $done && $read->id === $delegation->id) {
            $done = true;
            DB::table('delegations')->where('id', $delegation->id)->update($columns);
        }
    });
}

it('checks and clamps an edit against the locked row, not the one read before the lock (m1)', function () {
    $member = dcMember($this->delegation, '2026-11-05', '2026-11-08');
    // Meanwhile another admin moved the start to the 5th.
    dcChangeAfterBinding($this->delegation, ['start_datetime' => '2026-11-05']);

    // Ending on the 3rd would end before the (new) start.
    $this->actingAs($this->admin)
        ->putJson("/api/delegations/{$this->delegation->id}", ['end_datetime' => '2026-11-03'])
        ->assertUnprocessable()->assertJsonValidationErrors('end_datetime');

    expect($this->delegation->fresh()->end_datetime->format('Y-m-d'))->toBe('2026-11-10')
        ->and(dcDates($this->delegation, $member))->toBe(['2026-11-05', '2026-11-08']);
});

it('clamps against the locked dates when another edit landed first (m1)', function () {
    $member = dcMember($this->delegation, '2026-11-02', '2026-11-09');
    dcChangeAfterBinding($this->delegation, ['start_datetime' => '2026-11-04']);

    $this->actingAs($this->admin)
        ->putJson("/api/delegations/{$this->delegation->id}", ['end_datetime' => '2026-11-07'])
        ->assertOk()->assertJsonPath('adjusted_members', 1);

    expect(dcDates($this->delegation, $member))->toBe(['2026-11-04', '2026-11-07']);
});

it('checks a new member\'s travel dates against the locked row (m2)', function () {
    $user = User::factory()->create();
    // Meanwhile another admin shortened the delegation to the 6th.
    dcChangeAfterBinding($this->delegation, ['end_datetime' => '2026-11-06']);

    $this->actingAs($this->admin)
        ->postJson("/api/delegations/{$this->delegation->id}/add-user", [
            'user_id' => $user->id, 'custom_start' => '2026-11-04', 'custom_end' => '2026-11-09',
        ])
        ->assertUnprocessable()->assertJsonValidationErrors('custom_end');

    expect($this->delegation->users()->where('users.id', $user->id)->exists())->toBeFalse();
});
