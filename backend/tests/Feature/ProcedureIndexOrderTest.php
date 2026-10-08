<?php

use App\Models\Procedure;
use App\Models\User;
use Illuminate\Support\Carbon;

function pioCreate(User $owner, int $n, Carbon $at): Procedure
{
    $procedure = Procedure::create([
        'identifier' => sprintf('PIO-%02d', $n),
        'title' => "Order Tie {$n}",
        'owner_id' => $owner->id,
    ]);
    // Same timestamp for all of them, as for procedures created within the same second.
    $procedure->timestamps = false;
    $procedure->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

    return $procedure;
}

it('breaks updated_at ties by id, so paging through the list never repeats or skips one', function () {
    $user = User::factory()->create();
    $at = Carbon::parse('2026-01-01 12:00:00');
    $ids = collect(range(1, 5))->map(fn ($n) => pioCreate($user, $n, $at)->id);

    $seen = [];
    foreach ([1, 2, 3] as $page) {
        $response = $this->actingAs($user)->getJson("/api/procedures?per_page=2&page={$page}");
        $response->assertOk();
        foreach ($response->json('data') as $row) {
            $seen[] = $row['id'];
        }
    }

    // Newest first, ties by id descending: a stable, total order across pages.
    expect($seen)->toBe($ids->sortDesc()->values()->all());
});

it('keeps the tie-break for an explicit sort column too', function () {
    $user = User::factory()->create();
    $at = Carbon::parse('2026-01-01 12:00:00');
    $ids = collect(range(1, 3))->map(fn ($n) => pioCreate($user, $n, $at)->id);

    $response = $this->actingAs($user)->getJson('/api/procedures?sort_by=created_at&sort_dir=desc');

    // Ties follow the sort direction (desc → higher id first).
    expect(collect($response->json('data'))->pluck('id')->all())->toBe($ids->sortDesc()->values()->all());
});
