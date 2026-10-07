# Streak Replay Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the persisted, incrementally-updated streak counters with a deterministic replay of real assignments, let the manager choose what a rest-day assignment means, and make every assignment path respect rest windows.

**Architecture:** A pure `StreakTimeline` replays an employee's assignments (date, then slot order) through a group's rules; `StreakReplay` loads those assignments in one query. Every former `ShiftStreakTracker` caller moves to it one task at a time (the suite stays green after each task); the tracker, the `shift_streak_states` table and its model are deleted last. Manual assign answers 409 on a rest day until the manager picks a `rest_policy` (stored on the assignment); the frontend prompts via a new `RestDayPolicyModal.vue`.

**Tech Stack:** Laravel 9 / PHP 8.0–8.2 / PostgreSQL, Pest; Vue 3 `<script setup>`, Biome; Playwright e2e.

**Spec:** [docs/superpowers/specs/2026-10-07-streak-replay-design.md](../specs/2026-10-07-streak-replay-design.md)

## Global Constraints

- Semantics are exactly the spec's table (pause-not-reset; window after the Nth counted shift on day D is `[D+1, D+R]`; rest windows union across rules; `rest_policy` ∈ `not_counted | counts | extends_rest`, null ≡ `not_counted`; "as of D" uses only assignments with date < D).
- No PHP 8.1+ syntax (CI runs PHP 8.0): no string-key array spread, enums, `readonly`, `never`, first-class callables, `new` in initializers.
- Pest helper functions are global across files: prefix new ones `srl` (engine tests) / reuse existing `stl*` in `ShiftTypeLabellingTest.php`; never redeclare.
- Backend tests (repo root, bash):
  - PHP 8.2: `PW=$(grep '^DB_PASSWORD=' .env | cut -d= -f2-); MSYS_NO_PATHCONV=1 docker run --rm --network workflow-manager_app_network -v "C:/Projects/ShiftChanger/workflow-manager/backend:/var/www/html" -w /var/www/html -e DB_HOST=db -e DB_PORT=5432 -e DB_USERNAME=postgres -e DB_PASSWORD="$PW" --entrypoint php workflow-manager_backend vendor/bin/pest [--filter=X]` — abbreviated **`PEST`** below.
  - PHP 8.0: same, but `-v ".../backend:/app" -w /app` and image/command `php80-pgsql php vendor/bin/pest` — abbreviated **`PEST80`**. Run the full suite on both at the end of every backend task.
- E2E: rebuild the backend after backend changes (`docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build backend`, wait for `curl -sf http://localhost:8090/healthz`); `cd frontend && npm run test:e2e` resets the dev DB. Conventions: import `test`/`expect` from `e2e/fixtures/auth.js`; unique group names; this plan reserves months **2029-01** and **2029-02**; verify via API or after reload; positive guards before absence checks; sabotage-prove each new assertion (undo sabotage by exact edit, never `git checkout`).
- Biome on changed frontend files. CHANGELOG entry (root `CHANGELOG.md`, current top-entry format) in the last task.
- Commit trailer (own line, after a blank line): `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`

## Review Focus

1. **A manager assigns during rest without being asked** — any path that creates an assignment on a rest day without a policy and without a 409. Pinned in Task 3 (assign endpoint 409) and Task 6 (auto modes skip resting employees).
2. **Window boundary off-by-one** — the day of the Nth shift is not resting; D+1 and D+R are; D+R+1 is not. Pinned in Task 1.
3. **Same-day / out-of-order data** — assignments created out of date order or two slots on one day replay in (date, slot) order. Pinned in Task 1.
4. **Report shows a stale or missing window** — a window from a previous period must not show; one inside the displayed period must. Pinned in Task 2.
5. **Cancel on the rest-day prompt leaves a ghost chip or a server row** — pinned in Task 8 (API check after Cancel).

---

### Task 1: `StreakTimeline` engine, `StreakReplay` loader, `rest_policy` column

**Files:**
- Create: `backend/app/Services/StreakTimeline.php`, `backend/app/Services/StreakReplay.php`
- Create: `backend/database/migrations/2026_10_07_100000_add_rest_policy_to_shift_assignments.php`
- Modify: `backend/app/Models/ShiftAssignment.php` (`$fillable`)
- Test: `backend/tests/Feature/StreakReplayTest.php`

**Interfaces:**
- Produces:
  - `StreakTimeline::__construct(Collection $rules /* ShiftStreakRule keyed by shift_type */, array $eventsByUser /* userId => list<{date:'Y-m-d', shift_number:int, shift_type:?string, rest_policy:?string}> */)`
  - `StreakTimeline::POLICIES` (= `['not_counted','counts','extends_rest']`), constants `NOT_COUNTED`, `COUNTS`, `EXTENDS_REST`
  - `hasRules(): bool`; `append(int $userId, ?string $shiftType, Carbon $date, int $shiftNumber, ?string $restPolicy = null): void`
  - `isResting(int $userId, Carbon $day): bool`; `activeWindow(int $userId, Carbon $day): ?array{rule: ShiftStreakRule, start: Carbon, end: Carbon}` (the covering window ending last)
  - `statusAsOf(int $userId, Carbon $until, ?Carbon $windowsFrom = null): list<array{shift_type: string, current_count: int, streak_length: int, resting_until: ?string}>`
  - `StreakReplay::forGroup(ShiftGroup $group, array $userIds, Carbon $until): StreakTimeline` (loads assignments with shift date < `$until`)
  - `shift_assignments.rest_policy` (nullable string), index `shifts_group_date_index` on `shifts(shift_group_id, date)`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Feature/StreakReplayTest.php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;
use App\Services\StreakReplay;
use App\Services\StreakTimeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** @param list<array{0: string, 1: int, 2: int}> $defs [shift_type, streak_length, rest_days] */
function srlRules(array $defs): Collection
{
    return collect($defs)
        ->map(fn ($d) => new ShiftStreakRule(['shift_type' => $d[0], 'streak_length' => $d[1], 'rest_days' => $d[2]]))
        ->keyBy('shift_type');
}

function srlEvent(string $date, ?string $type, ?string $policy = null, int $slot = 1): array
{
    return ['date' => $date, 'shift_number' => $slot, 'shift_type' => $type, 'rest_policy' => $policy];
}

function srlTimeline(array $rules, array $events): StreakTimeline
{
    return new StreakTimeline(srlRules($rules), [1 => $events]);
}

it('opens a rest window [D+1, D+R] after the Nth shift and not before', function () {
    $t = srlTimeline([['night', 3, 2]], [
        srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night'), srlEvent('2029-01-03', 'night'),
    ]);

    expect($t->isResting(1, Carbon::parse('2029-01-03')))->toBeFalse();
    expect($t->isResting(1, Carbon::parse('2029-01-04')))->toBeTrue();
    expect($t->isResting(1, Carbon::parse('2029-01-05')))->toBeTrue();
    expect($t->isResting(1, Carbon::parse('2029-01-06')))->toBeFalse();
    expect($t->activeWindow(1, Carbon::parse('2029-01-05'))['end']->toDateString())->toBe('2029-01-05');
});

it('pauses (does not reset) on days off and on other shift types', function () {
    $t = srlTimeline([['night', 3, 2]], [
        srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night'),
        srlEvent('2029-01-04', 'day'), srlEvent('2029-01-06', 'night'),
    ]);

    expect($t->isResting(1, Carbon::parse('2029-01-07')))->toBeTrue();
});

it('replays in (date, slot) order regardless of the order events were given', function () {
    $t = srlTimeline([['night', 2, 1]], [
        srlEvent('2029-01-02', 'night', null, 2), srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'day', null, 1),
    ]);

    // Second night is on 01-02 → window 01-03..01-03.
    expect($t->isResting(1, Carbon::parse('2029-01-03')))->toBeTrue();
    expect($t->statusAsOf(1, Carbon::parse('2029-01-02'))[0]['current_count'])->toBe(1);
});

it('as-of excludes assignments on or after the requested day', function () {
    $t = srlTimeline([['night', 3, 2]], [
        srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night'), srlEvent('2029-01-03', 'night'),
    ]);

    expect($t->statusAsOf(1, Carbon::parse('2029-01-02')))->toBe([
        ['shift_type' => 'night', 'current_count' => 1, 'streak_length' => 3, 'resting_until' => null],
    ]);
});

it('ignores a rest-day assignment by default (null or not_counted)', function () {
    foreach ([null, StreakTimeline::NOT_COUNTED] as $policy) {
        $t = srlTimeline([['night', 3, 2]], [
            srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night'), srlEvent('2029-01-03', 'night'),
            srlEvent('2029-01-04', 'night', $policy),
            srlEvent('2029-01-06', 'night'), srlEvent('2029-01-07', 'night'),
        ]);

        expect($t->statusAsOf(1, Carbon::parse('2029-01-08'))[0]['current_count'])->toBe(2);
        expect($t->isResting(1, Carbon::parse('2029-01-08')))->toBeFalse();
    }
});

it('counts a rest-day assignment toward the next streak when the policy is counts', function () {
    $t = srlTimeline([['night', 3, 2]], [
        srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night'), srlEvent('2029-01-03', 'night'),
        srlEvent('2029-01-04', 'night', StreakTimeline::COUNTS),
        srlEvent('2029-01-06', 'night'), srlEvent('2029-01-07', 'night'),
    ]);

    // 04 (counts), 06, 07 → third counted night on 07 → window 08..09.
    expect($t->isResting(1, Carbon::parse('2029-01-08')))->toBeTrue();
    expect($t->isResting(1, Carbon::parse('2029-01-09')))->toBeTrue();
});

it('extends every covering window to D + rest_days when the policy is extends_rest, never shortening it', function () {
    $t = srlTimeline([['night', 3, 2]], [
        srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night'), srlEvent('2029-01-03', 'night'),
        srlEvent('2029-01-04', 'day', StreakTimeline::EXTENDS_REST),
    ]);

    expect($t->activeWindow(1, Carbon::parse('2029-01-05'))['end']->toDateString())->toBe('2029-01-06');
    expect($t->isResting(1, Carbon::parse('2029-01-06')))->toBeTrue();
    expect($t->isResting(1, Carbon::parse('2029-01-07')))->toBeFalse();
});

it('unions rest windows from different rules', function () {
    $t = srlTimeline([['night', 2, 1], ['day', 2, 3]], [
        srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night'), // window 01-03
        srlEvent('2029-01-05', 'day'), srlEvent('2029-01-06', 'day'),     // window 01-07..01-09
    ]);

    expect($t->isResting(1, Carbon::parse('2029-01-03')))->toBeTrue();
    expect($t->isResting(1, Carbon::parse('2029-01-04')))->toBeFalse();
    expect($t->isResting(1, Carbon::parse('2029-01-09')))->toBeTrue();
});

it('re-evaluates history when a rule changes (state is never stored)', function () {
    $events = [srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night'), srlEvent('2029-01-03', 'night')];

    expect(srlTimeline([['night', 3, 2]], $events)->isResting(1, Carbon::parse('2029-01-04')))->toBeTrue();
    expect(srlTimeline([['night', 4, 2]], $events)->isResting(1, Carbon::parse('2029-01-04')))->toBeFalse();
});

it('reports resting_until only for windows ending on or after windowsFrom', function () {
    $t = srlTimeline([['night', 2, 1]], [srlEvent('2029-01-01', 'night'), srlEvent('2029-01-02', 'night')]); // window 01-03

    expect($t->statusAsOf(1, Carbon::parse('2029-02-01'))[0]['resting_until'])->toBeNull();
    expect($t->statusAsOf(1, Carbon::parse('2029-02-01'), Carbon::parse('2029-01-01'))[0]['resting_until'])->toBe('2029-01-03');
});

it('is never resting and reports no status without rules', function () {
    $t = srlTimeline([], [srlEvent('2029-01-01', 'night')]);

    expect($t->hasRules())->toBeFalse();
    expect($t->isResting(1, Carbon::parse('2029-01-02')))->toBeFalse();
    expect($t->statusAsOf(1, Carbon::parse('2029-01-02')))->toBe([]);
});

it('append() extends the replay for later days', function () {
    $t = srlTimeline([['night', 2, 1]], [srlEvent('2029-01-01', 'night')]);
    $t->append(1, 'night', Carbon::parse('2029-01-02'), 1);

    expect($t->isResting(1, Carbon::parse('2029-01-03')))->toBeTrue();
});

it('loads only the group\'s assignments before $until, with their rest_policy', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $other = ShiftGroup::create(['name' => 'Other', 'sort_order' => 1]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    foreach (['2029-01-01', '2029-01-02'] as $date) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);
    }
    $elsewhere = Shift::create(['date' => '2029-01-01', 'shift_number' => 1, 'shift_group_id' => $other->id, 'shift_type' => 'night']);
    ShiftAssignment::create(['shift_id' => $elsewhere->id, 'user_id' => $employee->id]);
    $restDay = Shift::create(['date' => '2029-01-03', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    ShiftAssignment::create(['shift_id' => $restDay->id, 'user_id' => $employee->id, 'rest_policy' => StreakTimeline::EXTENDS_REST]);

    $timeline = app(StreakReplay::class)->forGroup($group, [$employee->id], Carbon::parse('2029-02-01'));

    // Window 01-03..01-04, extended by the 01-03 rest-day shift to 01-05.
    expect($timeline->activeWindow($employee->id, Carbon::parse('2029-01-04'))['end']->toDateString())->toBe('2029-01-05');

    $unassigned = ShiftAssignment::where('shift_id', $restDay->id)->first();
    $unassigned->delete();
    ShiftAssignment::where('user_id', $employee->id)->whereHas('shift', fn ($q) => $q->where('date', '2029-01-02'))->delete();
    $timeline = app(StreakReplay::class)->forGroup($group, [$employee->id], Carbon::parse('2029-02-01'));

    // Unassigning the window-starting night clears the window (old tracker kept it forever).
    expect($timeline->isResting($employee->id, Carbon::parse('2029-01-03')))->toBeFalse();
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `PEST --filter=StreakReplayTest` → FAIL: `Class "App\Services\StreakTimeline" not found`.

- [ ] **Step 3: Write the migration**

```php
<?php
// backend/database/migrations/2026_10_07_100000_add_rest_policy_to_shift_assignments.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Streak state is now replayed from assignments (see StreakTimeline). A manager who assigns
 * someone on a rest day chooses what that assignment means; null ≡ not_counted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->string('rest_policy', 20)->nullable();
        });
        Schema::table('shifts', function (Blueprint $table) {
            $table->index(['shift_group_id', 'date'], 'shifts_group_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex('shifts_group_date_index');
        });
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropColumn('rest_policy');
        });
    }
};
```

In `backend/app/Models/ShiftAssignment.php`: `protected $fillable = ['shift_id', 'user_id', 'rest_policy'];`

- [ ] **Step 4: Write the engine**

```php
<?php
// backend/app/Services/StreakTimeline.php

namespace App\Services;

use App\Models\ShiftStreakRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Streak state derived by replaying an employee's assignments, in (date, slot) order, through a
 * group's streak rules. Nothing is stored, so it can't drift and needs no locking. Rules:
 * docs/superpowers/specs/2026-10-07-streak-replay-design.md ("Semantics").
 */
final class StreakTimeline
{
    public const NOT_COUNTED = 'not_counted';
    public const COUNTS = 'counts';
    public const EXTENDS_REST = 'extends_rest';
    public const POLICIES = [self::NOT_COUNTED, self::COUNTS, self::EXTENDS_REST];

    /** @var array<int, list<array{date: string, shift_number: int, shift_type: ?string, rest_policy: ?string}>> */
    private array $eventsByUser = [];

    /**
     * @param  Collection<string, ShiftStreakRule>  $rules  keyed by shift_type
     * @param  array<int, list<array{date: string, shift_number: int, shift_type: ?string, rest_policy: ?string}>>  $eventsByUser
     */
    public function __construct(private Collection $rules, array $eventsByUser)
    {
        foreach ($eventsByUser as $userId => $events) {
            usort($events, [self::class, 'compareEvents']);
            $this->eventsByUser[(int) $userId] = $events;
        }
    }

    public function hasRules(): bool
    {
        return $this->rules->isNotEmpty();
    }

    public function append(int $userId, ?string $shiftType, Carbon $date, int $shiftNumber, ?string $restPolicy = null): void
    {
        $this->eventsByUser[$userId][] = [
            'date' => $date->toDateString(),
            'shift_number' => $shiftNumber,
            'shift_type' => $shiftType,
            'rest_policy' => $restPolicy,
        ];
        usort($this->eventsByUser[$userId], [self::class, 'compareEvents']);
    }

    public function isResting(int $userId, Carbon $day): bool
    {
        return $this->activeWindow($userId, $day) !== null;
    }

    /** @return array{rule: ShiftStreakRule, start: Carbon, end: Carbon}|null the covering window that ends last */
    public function activeWindow(int $userId, Carbon $day): ?array
    {
        $day = $day->copy()->startOfDay();
        $latest = null;
        foreach ($this->replay($userId, $day)['windows'] as $window) {
            if ($day->betweenIncluded($window['start'], $window['end'])
                && ($latest === null || $window['end']->gt($latest['end']))) {
                $latest = $window;
            }
        }

        return $latest;
    }

    /**
     * Per-rule status after every assignment before $until. resting_until is the end of the rule's
     * latest window ending on or after $windowsFrom (default $until), or null.
     *
     * @return list<array{shift_type: string, current_count: int, streak_length: int, resting_until: ?string}>
     */
    public function statusAsOf(int $userId, Carbon $until, ?Carbon $windowsFrom = null): array
    {
        $state = $this->replay($userId, $until->copy()->startOfDay());
        $from = ($windowsFrom ?? $until)->copy()->startOfDay();

        $status = [];
        foreach ($this->rules as $type => $rule) {
            $end = null;
            foreach ($state['windows'] as $window) {
                if ($window['rule']->shift_type === $type && $window['end']->gte($from)
                    && ($end === null || $window['end']->gt($end))) {
                    $end = $window['end'];
                }
            }
            $status[] = [
                'shift_type' => $type,
                'current_count' => $state['counts'][$type] ?? 0,
                'streak_length' => (int) $rule->streak_length,
                'resting_until' => $end === null ? null : $end->toDateString(),
            ];
        }

        return $status;
    }

    /** @return array{counts: array<string, int>, windows: list<array{rule: ShiftStreakRule, start: Carbon, end: Carbon}>} */
    private function replay(int $userId, Carbon $until): array
    {
        $counts = [];
        $windows = [];
        if ($this->rules->isEmpty()) {
            return ['counts' => $counts, 'windows' => $windows];
        }

        $untilStr = $until->toDateString();
        foreach ($this->eventsByUser[$userId] ?? [] as $event) {
            if ($event['date'] >= $untilStr) {
                break;
            }
            $day = Carbon::parse($event['date'])->startOfDay();

            $covering = [];
            foreach ($windows as $i => $window) {
                if ($day->betweenIncluded($window['start'], $window['end'])) {
                    $covering[] = $i;
                }
            }

            if ($covering !== []) {
                $policy = $event['rest_policy'] ?? self::NOT_COUNTED;
                if ($policy === self::EXTENDS_REST) {
                    foreach ($covering as $i) {
                        $candidate = $day->copy()->addDays((int) $windows[$i]['rule']->rest_days);
                        if ($candidate->gt($windows[$i]['end'])) {
                            $windows[$i]['end'] = $candidate;
                        }
                    }
                }
                if ($policy !== self::COUNTS) {
                    continue;
                }
            }

            $rule = $event['shift_type'] !== null ? $this->rules->get($event['shift_type']) : null;
            if ($rule === null) {
                continue;
            }

            $type = $rule->shift_type;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
            if ($counts[$type] >= (int) $rule->streak_length) {
                $windows[] = [
                    'rule' => $rule,
                    'start' => $day->copy()->addDay(),
                    'end' => $day->copy()->addDays((int) $rule->rest_days),
                ];
                $counts[$type] = 0;
            }
        }

        return ['counts' => $counts, 'windows' => $windows];
    }

    private static function compareEvents(array $a, array $b): int
    {
        return [$a['date'], $a['shift_number']] <=> [$b['date'], $b['shift_number']];
    }
}
```

```php
<?php
// backend/app/Services/StreakReplay.php

namespace App\Services;

use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use Illuminate\Support\Carbon;

/** Loads the assignments a StreakTimeline replays — one query per group. */
class StreakReplay
{
    /** @param  list<int>  $userIds */
    public function forGroup(ShiftGroup $group, array $userIds, Carbon $until): StreakTimeline
    {
        $rules = $group->streakRules()->get()->keyBy('shift_type');
        if ($rules->isEmpty() || $userIds === []) {
            return new StreakTimeline($rules, []);
        }

        $rows = ShiftAssignment::query()
            ->join('shifts', 'shifts.id', '=', 'shift_assignments.shift_id')
            ->where('shifts.shift_group_id', $group->id)
            ->whereIn('shift_assignments.user_id', $userIds)
            ->where('shifts.date', '<', $until->toDateString())
            ->get(['shift_assignments.user_id', 'shifts.date', 'shifts.shift_number', 'shifts.shift_type', 'shift_assignments.rest_policy']);

        $events = [];
        foreach ($rows as $row) {
            $events[(int) $row->user_id][] = [
                'date' => substr((string) $row->date, 0, 10),
                'shift_number' => (int) $row->shift_number,
                'shift_type' => $row->shift_type,
                'rest_policy' => $row->rest_policy,
            ];
        }

        return new StreakTimeline($rules, $events);
    }
}
```

- [ ] **Step 5: Run to verify they pass** — `PEST --filter=StreakReplayTest` → 13 passed. Sabotage once (e.g. window start `addDay()` → `copy()`), confirm the boundary test fails, undo. Full `PEST` and `PEST80` → all pass (nothing uses the engine yet).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/StreakTimeline.php backend/app/Services/StreakReplay.php backend/app/Models/ShiftAssignment.php backend/database/migrations/2026_10_07_100000_add_rest_policy_to_shift_assignments.php backend/tests/Feature/StreakReplayTest.php
git commit -m "feat: replay streak state from assignments (StreakTimeline) and add rest_policy"
```

---

### Task 2: Settlement report uses the replay

**Files:**
- Modify: `backend/app/Http/Controllers/SettlementPeriodController.php` (the `$streakTracker`/`$streakStates` block and the per-employee `$streakStatus` loop)
- Test: replace `backend/tests/Feature/SettlementPeriodControllerStreakTest.php`

**Interfaces:**
- Consumes: `StreakReplay::forGroup()`, `StreakTimeline::statusAsOf($userId, $until, $windowsFrom)` (Task 1).
- Response shape of `streak_status` unchanged.

- [ ] **Step 1: Rewrite the test file** (it seeded state through the tracker; it now seeds real typed assignments)

```php
<?php
// backend/tests/Feature/SettlementPeriodControllerStreakTest.php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;
use Illuminate\Support\Carbon;

function spsReport($test, User $maintainer, ShiftGroup $group, int $offset = 0)
{
    return $test->actingAs($maintainer)->getJson('/api/settlement-periods?'.http_build_query([
        'shift_group_id' => $group->id, 'offset' => $offset,
    ]));
}

function spsNight(ShiftGroup $group, User $employee, string $date): void
{
    $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
        'start_time' => '22:00', 'end_time' => '06:00']);
    ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-15');
    $this->maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $this->group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01',
    ]);
    ShiftStreakRule::create(['shift_group_id' => $this->group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $this->employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
});

it('reports a mid-streak count as of the end of the displayed period', function () {
    spsNight($this->group, $this->employee, '2026-09-01');
    spsNight($this->group, $this->employee, '2026-09-02');

    expect(spsReport($this, $this->maintainer, $this->group)->json('employees.0.streak_status'))->toBe([
        ['shift_type' => 'night', 'current_count' => 2, 'streak_length' => 3, 'resting_until' => null],
    ]);
});

it('reports a rest window that ends inside the displayed period', function () {
    foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
        spsNight($this->group, $this->employee, $date);
    }

    expect(spsReport($this, $this->maintainer, $this->group)->json('employees.0.streak_status.0.resting_until'))->toBe('2026-09-05');
});

it('does not report a rest window that ended before the displayed period', function () {
    foreach (['2026-08-01', '2026-08-02', '2026-08-03'] as $date) {
        spsNight($this->group, $this->employee, $date);
    }
    spsNight($this->group, $this->employee, '2026-09-10'); // so the employee appears in September's report

    expect(spsReport($this, $this->maintainer, $this->group)->json('employees.0.streak_status.0'))->toBe(
        ['shift_type' => 'night', 'current_count' => 1, 'streak_length' => 3, 'resting_until' => null],
    );
});
```

- [ ] **Step 2: Run** — `PEST --filter=SettlementPeriodControllerStreakTest` → the window tests FAIL (the tracker has no state for raw assignments: count 0, resting_until null).

- [ ] **Step 3: Implement** — in `SettlementPeriodController`, replace `use App\Services\ShiftStreakTracker;` with `use App\Services\StreakReplay;`, replace the three `$streakTracker`/`$streakRules`/`$streakStates` lines with:

```php
        // Status as of the end of the displayed period; windows ending inside or after it are shown.
        $streakUntil = $period['end']->copy()->addDay();
        $streakTimeline = app(StreakReplay::class)->forGroup($group, array_keys($employeeNames), $streakUntil);
```

and replace the `$streakStatus = []; foreach ($streakRules as $rule) { ... }` loop with:

```php
            $streakStatus = $streakTimeline->statusAsOf($userId, $streakUntil, $period['start']);
```

- [ ] **Step 4: Run** — the file passes (3); full `PEST`, `PEST80` pass.

- [ ] **Step 5: Commit** — `git add backend/app/Http/Controllers/SettlementPeriodController.php backend/tests/Feature/SettlementPeriodControllerStreakTest.php` and `git commit -m "feat: settlement report streak status from the replay, as of period end"`.

---

### Task 3: Manual assign asks on rest days; unassign needs no bookkeeping

**Files:**
- Modify: `backend/app/Http/Controllers/ScheduleController.php` — `assign()`, `unassign()`, and the assignment maps in `index()` (`'assignments' => $shift->assignments->map(...)`) and `updateDayShifts()` (add `'rest_policy' => $a->rest_policy`)
- Test: replace `backend/tests/Feature/ScheduleControllerStreakTest.php`

**Interfaces:**
- Consumes: `StreakReplay::forGroup()`, `StreakTimeline::activeWindow()`, `StreakTimeline::POLICIES`.
- Produces: `POST /api/schedule/assign` accepts `rest_policy`; **409** `{ code: 'rest_window_conflict', message, resting_until, rule: { shift_type, streak_length, rest_days } }`; 201 body gains `rest_policy`; schedule payload assignments gain `rest_policy`. Used by Task 8.

**Ruling recorded in this plan:** the old test `increments the streak counter on a manual assign without being blocked by an active rest window` pinned behaviour the user replaced (rest-day assigns now require a policy). It is deleted, not adapted.

- [ ] **Step 1: Rewrite the test file**

```php
<?php
// backend/tests/Feature/ScheduleControllerStreakTest.php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;
use App\Services\StreakReplay;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $this->group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $this->group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $this->employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    $this->shifts = [];
    foreach (['2029-01-01', '2029-01-02', '2029-01-03', '2029-01-04', '2029-01-07'] as $date) {
        $this->shifts[$date] = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $this->group->id, 'shift_type' => 'night']);
    }
    foreach (['2029-01-01', '2029-01-02', '2029-01-03'] as $date) {
        $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
            'shift_id' => $this->shifts[$date]->id, 'user_id' => $this->employee->id,
        ])->assertCreated();
    }
});

it('answers 409 with the rest window when assigning on a rest day without a policy', function () {
    $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $this->shifts['2029-01-04']->id, 'user_id' => $this->employee->id,
    ])->assertStatus(409)->assertJson([
        'code' => 'rest_window_conflict',
        'resting_until' => '2029-01-05',
        'rule' => ['shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2],
    ]);

    expect(ShiftAssignment::where('shift_id', $this->shifts['2029-01-04']->id)->count())->toBe(0);
});

it('stores the chosen policy when the manager confirms', function (string $policy) {
    $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $this->shifts['2029-01-04']->id, 'user_id' => $this->employee->id, 'rest_policy' => $policy,
    ])->assertCreated()->assertJson(['rest_policy' => $policy]);

    expect(ShiftAssignment::where('shift_id', $this->shifts['2029-01-04']->id)->value('rest_policy'))->toBe($policy);
})->with(['not_counted', 'counts', 'extends_rest']);

it('rejects an unknown policy', function () {
    $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $this->shifts['2029-01-04']->id, 'user_id' => $this->employee->id, 'rest_policy' => 'whatever',
    ])->assertStatus(422)->assertJsonValidationErrors(['rest_policy']);
});

it('assigns normally outside a rest window and ignores a policy sent there', function () {
    $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $this->shifts['2029-01-07']->id, 'user_id' => $this->employee->id, 'rest_policy' => 'counts',
    ])->assertCreated()->assertJson(['rest_policy' => null]);
});

it('unassigning the shift that started a rest window clears the window', function () {
    $third = ShiftAssignment::where('shift_id', $this->shifts['2029-01-03']->id)->first();
    $this->actingAs($this->maintainer)->deleteJson("/api/schedule/unassign/{$third->id}")->assertOk();

    $timeline = app(StreakReplay::class)->forGroup($this->group, [$this->employee->id], Carbon::parse('2029-02-01'));
    expect($timeline->isResting($this->employee->id, Carbon::parse('2029-01-04')))->toBeFalse();
    $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $this->shifts['2029-01-04']->id, 'user_id' => $this->employee->id,
    ])->assertCreated();
});

it('includes rest_policy in the schedule payload', function () {
    $this->actingAs($this->maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $this->shifts['2029-01-04']->id, 'user_id' => $this->employee->id, 'rest_policy' => 'extends_rest',
    ])->assertCreated();

    $response = $this->actingAs($this->maintainer)->getJson("/api/schedule?month=2029-01&shift_group_id={$this->group->id}");
    expect($response->json('schedule.2029-01-04.0.assignments.0.rest_policy'))->toBe('extends_rest');
});
```

- [ ] **Step 2: Run** — `PEST --filter=ScheduleControllerStreakTest` → FAIL (no 409; `rest_policy` unknown).

- [ ] **Step 3: Implement `assign()`** — add `use App\Services\StreakReplay;` and `use App\Services\StreakTimeline;`; new signature and body (keep the existing duplicate-assignment check as is):

```php
    public function assign(Request $request, StreakReplay $streakReplay)
    {
        $request->validate([
            'shift_id' => ['required', 'exists:shifts,id'],
            'user_id' => ['required', 'exists:users,id'],
            'rest_policy' => ['nullable', 'in:'.implode(',', StreakTimeline::POLICIES)],
        ]);

        // (existing "User is already assigned to this shift." check, unchanged)

        $shift = Shift::with('shiftGroup')->findOrFail($request->shift_id);
        $userId = (int) $request->user_id;

        // A rest day needs the manager's decision on how it counts (StreakTimeline policies).
        $window = $shift->shiftGroup
            ? $streakReplay->forGroup($shift->shiftGroup, [$userId], $shift->date)->activeWindow($userId, $shift->date)
            : null;
        if ($window !== null && !$request->filled('rest_policy')) {
            $rule = $window['rule'];
            $restingUntil = $window['end']->toDateString();

            return response()->json([
                'code' => 'rest_window_conflict',
                'message' => "This employee is resting until {$restingUntil} ({$rule->shift_type}: {$rule->streak_length} in a row, then {$rule->rest_days} days off).",
                'resting_until' => $restingUntil,
                'rule' => [
                    'shift_type' => $rule->shift_type,
                    'streak_length' => (int) $rule->streak_length,
                    'rest_days' => (int) $rule->rest_days,
                ],
            ], 409);
        }

        $assignment = ShiftAssignment::create([
            'shift_id' => $shift->id,
            'user_id' => $userId,
            'rest_policy' => $window !== null ? $request->input('rest_policy') : null,
        ]);
        $assignment->load('user:id,name');

        return response()->json([
            'id' => $assignment->id,
            'shift_id' => $assignment->shift_id,
            'user_id' => $assignment->user_id,
            'user_name' => $assignment->user->name,
            'rest_policy' => $assignment->rest_policy,
        ], 201);
    }
```

In `unassign()`, delete the `$streakTracker`…`persist` block (state follows from the data). Add `'rest_policy' => $a->rest_policy,` to the assignment maps in `index()` and `updateDayShifts()`.

- [ ] **Step 4: Run** — the file passes (8 incl. dataset); full `PEST`, `PEST80` pass. (Tests that still read `ShiftStreakState` after manual assigns — `ClearMonthStreakTest`, `ShiftTypeLabellingTest` re-booking cases — now see no counter writes from `assign()`; if any fail here, convert them now using Task 4's code for that test, and note it in the report.)

- [ ] **Step 5: Commit** — `git commit -m "feat: manual assign asks for a rest-day policy (409) and stores it; unassign needs no bookkeeping"` (add the controller and test file).

---

### Task 4: Clear-month and shift-type changes need no bookkeeping

**Files:**
- Modify: `backend/app/Http/Controllers/ShiftAutoAssignController.php` `clearMonth()` (delete the `$streakTracker`/`$streakRules`/`$streakStates` lines, the per-assignment `recordUnassignment` loop and the `persist`)
- Modify: `backend/app/Services/ShiftTypeChanger.php` (no tracker)
- Test: replace `backend/tests/Feature/ClearMonthStreakTest.php`; modify `backend/tests/Feature/ShiftTypeLabellingTest.php`

**Interfaces:**
- Produces: `ShiftTypeChanger::change(Collection $shifts, ?string $rawType): array{updated: int, rebooked: int}` — `rebooked` = assignments on changed shifts whose old or new type has a rule (unchanged meaning for the UI). Constructor takes no arguments.

**Ruling recorded in this plan:** `rolls back the type change when re-booking fails` tested tracker-persist atomicity, which no longer exists; it is deleted. `makes no tracker writes …` becomes a `rebooked: 0` behaviour test.

- [ ] **Step 1: Rewrite `ClearMonthStreakTest.php`**

```php
<?php
// backend/tests/Feature/ClearMonthStreakTest.php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;
use App\Services\StreakReplay;
use Illuminate\Support\Carbon;

it('clearing a month removes its assignments from the streak replay', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    foreach (['2029-01-01', '2029-01-02'] as $date) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
        $this->actingAs($maintainer)->postJson('/api/schedule/assign', ['shift_id' => $shift->id, 'user_id' => $employee->id])->assertCreated();
    }
    $replay = fn () => app(StreakReplay::class)->forGroup($group, [$employee->id], Carbon::parse('2029-02-01'));
    expect($replay()->isResting($employee->id, Carbon::parse('2029-01-03')))->toBeTrue();

    $this->actingAs($maintainer)->deleteJson('/api/schedule/clear-month', ['month' => '2029-01', 'shift_group_id' => $group->id])->assertOk();

    expect(Shift::where('shift_group_id', $group->id)->count())->toBe(0);
    expect($replay()->isResting($employee->id, Carbon::parse('2029-01-03')))->toBeFalse();
});
```

- [ ] **Step 2: Update `ShiftTypeLabellingTest.php`** — replace the `stlState()` helper and `use App\Models\ShiftStreakState;` / `use App\Services\ShiftStreakTracker;` imports with:

```php
use App\Services\StreakReplay;

function stlStatus(ShiftGroup $group, User $employee, string $until): array
{
    return app(StreakReplay::class)
        ->forGroup($group, [$employee->id], Carbon::parse($until))
        ->statusAsOf($employee->id, Carbon::parse($until), Carbon::parse('2000-01-01'))[0];
}
```

Then convert the state assertions (keep each test's setup and requests, which still pass through the real endpoints):
- `re-books streak counters when an assigned night shift becomes a day shift`: after the PUT, `expect(stlStatus($group, $employee, '2027-03-10')['current_count'])->toBe(1);` (replaces both `stlState` expectations; before the PUT assert `…['current_count'])->toBe(2)`).
- `re-books every assigned employee and can trigger a rest window`: per employee `expect(stlStatus($group, $employee, '2027-03-10'))->toMatchArray(['current_count' => 0, 'resting_until' => '2027-03-05']);`
- `clearing the type of an assigned night shift decrements the counter`: `expect(stlStatus($group, $employee, '2027-03-10')['current_count'])->toBe(0);`
- `makes no tracker writes for an unassigned shift or an unchanged type`: delete the `$statesBefore`/`ShiftStreakState` lines; keep both `->assertJson(['rebooked' => 0])` requests.
- delete `rolls back the type change when re-booking fails`.
- `bulk-relabels assigned shifts in date order …`: `expect(stlStatus($group, $employee, '2027-03-10'))->toMatchArray(['current_count' => 0, 'resting_until' => '2027-03-05']);`
- `re-booking an earlier month never shortens an active rest window`: replace its state assertions with `expect(app(StreakReplay::class)->forGroup($group, [$employee->id], Carbon::parse('2027-05-01'))->isResting($employee->id, Carbon::parse('2027-04-05')))->toBeTrue();` after both relabels.

- [ ] **Step 3: Run** — `PEST --filter='ClearMonthStreakTest|ShiftTypeLabellingTest'` → the new assertions FAIL where the old code still writes/reads counters inconsistently (record which ones fail).

- [ ] **Step 4: Implement** — `clearMonth()`: delete the tracker lines described above (keep the counting and deletes). `ShiftTypeChanger`:

```php
<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Support\ShiftType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Changes shifts' types. Streak state is replayed from assignments (StreakTimeline), so there is no
 * bookkeeping; `rebooked` reports how many assignments' streaks the change affects, for the UI.
 */
class ShiftTypeChanger
{
    /**
     * @param  Collection<int, Shift>  $shifts
     * @return array{updated: int, rebooked: int}
     */
    public function change(Collection $shifts, ?string $rawType): array
    {
        $newType = ShiftType::normalize($rawType);
        $changed = $shifts->filter(fn (Shift $shift) => $shift->shift_type !== $newType)->values();
        if ($changed->isEmpty()) {
            return ['updated' => 0, 'rebooked' => 0];
        }

        return DB::transaction(function () use ($changed, $newType) {
            $rebooked = 0;
            foreach ($changed->groupBy('shift_group_id') as $groupShifts) {
                $group = $groupShifts->first()->shiftGroup;
                $ruleTypes = $group ? $group->streakRules()->pluck('shift_type')->all() : [];
                $assignmentCounts = ShiftAssignment::whereIn('shift_id', $groupShifts->pluck('id'))
                    ->selectRaw('shift_id, count(*) as n')
                    ->groupBy('shift_id')
                    ->pluck('n', 'shift_id');

                foreach ($groupShifts as $shift) {
                    $touchesRule = in_array($shift->shift_type, $ruleTypes, true) || in_array($newType, $ruleTypes, true);
                    $shift->update(['shift_type' => $newType]);
                    if ($touchesRule) {
                        $rebooked += (int) ($assignmentCounts[$shift->id] ?? 0);
                    }
                }
            }

            return ['updated' => $changed->count(), 'rebooked' => $rebooked];
        });
    }
}
```

- [ ] **Step 5: Run** — both files pass; full `PEST`, `PEST80` pass.

- [ ] **Step 6: Commit** — `git commit -m "refactor: clear-month and shift-type changes rely on the streak replay"` (controller, service, both test files).

---

### Task 5: Polish Law auto-assign uses the replay

**Files:**
- Modify: `backend/app/Http/Controllers/ShiftAutoAssignController.php` `polishLaw()`
- Test: modify `backend/tests/Feature/PolishLawStreakTest.php` (third test only)

**Interfaces:**
- Consumes: `StreakReplay::forGroup()`, `StreakTimeline::isResting()`, `StreakTimeline::append()`.

- [ ] **Step 1: Rewrite the idempotency test** (replace its two `ShiftStreakState` blocks; add `use App\Services\StreakReplay; use Illuminate\Support\Carbon;` and drop `use App\Models\ShiftStreakState;`):

```php
    $this->actingAs($maintainer)->postJson('/api/schedule/auto-assign/polish-law', $payload)->assertOk();
    $status = fn () => app(StreakReplay::class)
        ->forGroup($group, [$employee->id], Carbon::parse('2026-10-01'))
        ->statusAsOf($employee->id, Carbon::parse('2026-10-01'))[0]['current_count'];
    expect($status())->toBe(2);

    // A second run over the same month must not double-count (replay counts each assignment once).
    $this->actingAs($maintainer)->postJson('/api/schedule/auto-assign/polish-law', $payload)->assertOk();
    expect($status())->toBe(2);
```

- [ ] **Step 2: Run** — `PEST --filter=PolishLawStreakTest` → the rewritten test passes or fails depending on the tracker; record the result (the behaviour tests 1–2 must stay green throughout).

- [ ] **Step 3: Implement** — in `polishLaw()` (add `use App\Services\StreakReplay;`):
  - Replace the three `$streakTracker`/`$streakRules`/`$streakStates` lines with
    ```php
    // Everything already assigned before the month's end is part of the replay; days are processed in
    // date order below and every new assignment is appended, so eligibility stays exact.
    $streakTimeline = app(StreakReplay::class)->forGroup($group, $employees->pluck('id')->all(), $end->copy()->addDay());
    ```
  - Delete the `if (!$streakRules->isEmpty() && !$streakTracker->wasAlreadyProcessed(...)) { ...recordAssignment... }` block inside the existing-assignments loop.
  - Replace the eligibility check with
    ```php
                // Rule: shift-streak mandatory rest (see StreakTimeline)
                if ($streakTimeline->isResting($emp->id, $shift->date)) {
                    continue;
                }
    ```
  - Replace the post-create `if (!$streakRules->isEmpty()) { $streakTracker->recordAssignment(...); }` with
    ```php
                $streakTimeline->append($userId, $shift->shift_type, $shift->date, $shift->shift_number);
    ```
  - Delete the final `if (!$streakRules->isEmpty()) { $streakTracker->persist($streakStates); }`.
  - Confirm `$shifts` is ordered by `date` then `shift_number` (it is: `->orderBy('date')->orderBy('shift_number')`).

- [ ] **Step 4: Run** — `PolishLawStreakTest` 3/3, `ShiftTypeLabellingTest` (auto-assign case) green; full `PEST`, `PEST80`.

- [ ] **Step 5: Commit** — `git commit -m "feat: Polish Law auto-assign reads streak rest windows from the replay"`.

---

### Task 6: Random Coverage and Rotating Subteam skip resting employees

**Files:**
- Modify: `backend/app/Http/Controllers/ShiftAutoAssignController.php` — `random()`, `rotating()`, `getAvailableUsers()`
- Test: create `backend/tests/Feature/AutoAssignStreakRestTest.php`

**Interfaces:**
- Consumes: `StreakReplay::forGroup()`, `isResting()`, `append()`.
- Changes: `getAvailableUsers(..., array $excludeUserIds = [], ?StreakTimeline $streakTimeline = null)` — new trailing optional parameter.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Feature/AutoAssignStreakRestTest.php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;

function aasSetup(): array
{
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    // Two worked nights → resting 2029-01-03 and 2029-01-04.
    foreach (['2029-01-01', '2029-01-02'] as $date) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
            'start_time' => '22:00', 'end_time' => '06:00']);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);
    }
    foreach (['2029-01-03', '2029-01-04', '2029-01-05'] as $date) {
        Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day',
            'start_time' => '08:00', 'end_time' => '16:00']);
    }

    return [$maintainer, $group, $employee];
}

function aasAssignedDates(ShiftGroup $group, User $employee): array
{
    return ShiftAssignment::where('user_id', $employee->id)
        ->join('shifts', 'shifts.id', '=', 'shift_assignments.shift_id')
        ->where('shifts.shift_group_id', $group->id)
        ->orderBy('shifts.date')
        ->pluck('shifts.date')
        ->map(fn ($d) => substr((string) $d, 0, 10))
        ->all();
}

it('skips a resting employee', function (string $endpoint, array $extra) {
    [$maintainer, $group, $employee] = aasSetup();

    $this->actingAs($maintainer)->postJson($endpoint, array_merge([
        'month' => '2029-01',
        'shift_group_id' => $group->id,
        'users_per_shift_weekday' => 1,
        'users_per_shift_weekend' => 1,
        'player_mode' => 'include',
        'player_ids' => [$employee->id],
    ], $extra))->assertOk();

    expect(aasAssignedDates($group, $employee))->toBe(['2029-01-01', '2029-01-02', '2029-01-05']);
})->with([
    'random' => ['/api/schedule/auto-assign/random', []],
    'rotating' => ['/api/schedule/auto-assign/rotating', ['rotation_days' => 7]],
]);
```

Check the routes in `backend/routes/api.php` (`auto-assign/random`, `auto-assign/rotating`) and the two modes' required request fields before running; adjust the dataset's `$extra` to the real validation rules (do not loosen the assertion).

- [ ] **Step 2: Run** — `PEST --filter=AutoAssignStreakRestTest` → FAIL (both modes assign 01-03 and 01-04).

- [ ] **Step 3: Implement** (add `use App\Services\StreakReplay; use App\Services\StreakTimeline;`):
  - `getAvailableUsers()`: add the trailing parameter `?StreakTimeline $streakTimeline = null` and, inside the loop after the existing checks:
    ```php
            if ($streakTimeline !== null && $streakTimeline->isResting($emp->id, $shift->date))
                continue;
    ```
  - `random()`: after `$employees` is final, build `$streakTimeline = ($group = ShiftGroup::find($groupId)) ? app(StreakReplay::class)->forGroup($group, $employees->pluck('id')->all(), $end->copy()->addDay()) : null;`; pass `$streakTimeline` as the new last argument of `getAvailableUsers(...)`; after each `ShiftAssignment::create([...])` add `if ($streakTimeline !== null) $streakTimeline->append($userId, $shift->shift_type, $shift->date, $shift->shift_number);`.
  - `rotating()`: same timeline construction; in the subteam filter loop add `if ($streakTimeline !== null && $streakTimeline->isResting($userId, $shift->date)) continue;` alongside the other `continue` checks; pass `$streakTimeline` to the fallback `getAvailableUsers(...)`; `append()` after both `ShiftAssignment::create` calls.
  - Both modes already load `$shifts` ordered by date then slot; rotating walks date blocks in order — confirm, and note it in the report.

- [ ] **Step 4: Run** — the new file passes (2); full `PEST`, `PEST80` (the existing e2e auto-fill tests run later in Task 9).

- [ ] **Step 5: Commit** — `git commit -m "feat: Random and Rotating auto-fill skip employees on streak rest days"`.

---

### Task 7: Remove the tracker and the `shift_streak_states` table

**Files:**
- Delete: `backend/app/Services/ShiftStreakTracker.php`, `backend/app/Models/ShiftStreakState.php`, `backend/tests/Feature/ShiftStreakTrackerTest.php`
- Modify: `backend/app/Models/ShiftStreakRule.php` (remove the `states()` relation)
- Create: `backend/database/migrations/2026_10_07_100100_drop_shift_streak_states_table.php`
- Modify: `backend/tests/Feature/ShiftStreakModelsTest.php` (third test), `backend/tests/Feature/ShiftGroupControllerStreakRulesTest.php` (two state tests)

- [ ] **Step 1: Update tests**
  - `ShiftStreakModelsTest.php`: delete `creates a streak state keyed by user and rule, and cascades on group deletion` and the `ShiftStreakState` import; add:
    ```php
    it('no longer has a shift_streak_states table', function () {
        expect(\Illuminate\Support\Facades\Schema::hasTable('shift_streak_states'))->toBeFalse();
    });
    ```
  - `ShiftGroupControllerStreakRulesTest.php`: drop the `ShiftStreakState` import; replace `preserves an existing streak state when the rule payload is resubmitted unchanged` with a test that resubmits the same rule and asserts `ShiftStreakRule::where('shift_group_id', $group->id)->pluck('id')->all()` equals `[$rule->id]` (rule row kept, so nothing downstream changes); replace `removes a streak rule and its cascaded state …` with one asserting only that the dropped rule is gone (`ShiftStreakRule::find($rule->id)` null) and the new `day` rule exists.
- [ ] **Step 2: Run** — `PEST --filter=ShiftStreakModelsTest` → the new table test FAILS.
- [ ] **Step 3: Implement** — `grep -rn "ShiftStreakTracker\|ShiftStreakState" backend/app backend/tests` must be empty after the deletes and edits. Migration:

```php
<?php
// backend/database/migrations/2026_10_07_100100_drop_shift_streak_states_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Streak state is replayed from assignments (StreakTimeline); the counter table is obsolete. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('shift_streak_states');
    }

    public function down(): void
    {
        Schema::create('shift_streak_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_streak_rule_id')->constrained('shift_streak_rules')->cascadeOnDelete();
            $table->unsignedInteger('current_count')->default(0);
            $table->date('resting_until')->nullable();
            $table->date('last_processed_date')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'shift_streak_rule_id']);
        });
    }
};
```

- [ ] **Step 4: Run** — full `PEST`, `PEST80` pass; grep above empty.
- [ ] **Step 5: Commit** — `git commit -m "refactor: drop ShiftStreakTracker and the shift_streak_states table"`.

---

### Task 8: Rest-day prompt in the schedule (+ e2e)

**Files:**
- Create: `frontend/src/components/RestDayPolicyModal.vue`
- Modify: `frontend/src/views/ScheduleView.vue` (`doAssign()`, chip markup, modal mount)
- Modify: `frontend/e2e/fixtures/api-setup.js` (add `assignShiftWithPolicy`)
- Create: `frontend/e2e/schedule/rest-day-policy.spec.js`

**Interfaces:**
- Consumes: Task 3's 409 body and `rest_policy` in responses/payloads.
- Produces: `RestDayPolicyModal` props `{ userName: String, restingUntil: String, rule: Object }`, emits `choose(policy)` and `cancel`.

- [ ] **Step 1: Write the e2e spec** (rebuild the backend first)

```js
// frontend/e2e/schedule/rest-day-policy.spec.js
import {
  assignShift,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
  setShiftTypes,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

// Months reserved for this file: 2029-01 (extends rest), 2029-02 (cancel).
async function seedRestingEmployee(context, name, month) {
  const groupId = await createShiftGroupWithStreakRule(context, {
    group: { name },
    rule: { shift_type: 'night', streak_length: 2, rest_days: 2 },
  });
  await generateMonthShifts(context, { monthKey: month, shiftGroupId: groupId });
  await setShiftTypes(context, { shiftGroupId: groupId, month, shiftNumber: 1, shiftType: 'night' });
  const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
  const { schedule } = await getShiftGroupsAndShifts(context, month, groupId);
  for (const day of ['01', '02']) {
    await assignShift(context, { shiftId: schedule[`${month}-${day}`][0].id, userId: employeeId });
  }
  return { groupId, employeeId, schedule }; // resting on -03 and -04
}

async function dragEmployeeTo(page, dayCell) {
  const chip = page.locator('div.cursor-grab', { hasText: 'Regular Employee' });
  const zone = dayCell.locator('[data-shift-id]').first();
  const s = await chip.boundingBox();
  const t = await zone.boundingBox();
  await page.mouse.move(s.x + s.width / 2, s.y + s.height / 2);
  await page.mouse.down();
  for (let i = 1; i <= 8; i++) {
    await page.mouse.move(s.x + ((t.x - s.x) * i) / 8, s.y + ((t.y - s.y) * i) / 8, { steps: 3 });
  }
  await page.mouse.move(t.x + t.width / 2, t.y + t.height / 2, { steps: 3 });
  await page.mouse.up();
}

test.describe('Rest-day policy prompt', () => {
  test.beforeEach(async ({ adminPage }) => {
    await adminPage.setViewportSize({ width: 1280, height: 1000 });
  });

  test('asks how a rest-day shift counts and stores "extends the rest"', async ({ adminPage }) => {
    const context = adminPage.context();
    const { groupId, employeeId } = await seedRestingEmployee(context, 'Rest Prompt Team', '2029-01');

    await adminPage.goto('/schedule/2029-01');
    await adminPage.getByRole('button', { name: /Rest Prompt Team/ }).click();
    const day3 = adminPage.locator('[data-date="2029-01-03"]');
    await expect(day3.locator('[data-shift-id]').first()).toBeVisible();
    const assigned = adminPage.waitForResponse(
      (res) => res.url().includes('/api/schedule/assign') && res.status() === 201,
    );
    await dragEmployeeTo(adminPage, day3);

    const dialog = adminPage.getByRole('dialog', { name: /rest day/i });
    await expect(dialog).toContainText('resting until 2029-01-04');
    await dialog.getByLabel('Extends the rest').check();
    await dialog.getByRole('button', { name: 'Assign' }).click();
    await assigned;

    const { schedule } = await getShiftGroupsAndShifts(context, '2029-01', groupId);
    const a = schedule['2029-01-03'][0].assignments.find((x) => x.user_id === employeeId);
    expect(a.rest_policy).toBe('extends_rest');

    await adminPage.reload();
    await adminPage.getByRole('button', { name: /Rest Prompt Team/ }).click();
    await expect(day3.getByTitle(/Assigned during rest: extends rest/)).toBeVisible();
  });

  test('Cancel leaves nothing assigned', async ({ adminPage }) => {
    const context = adminPage.context();
    const { groupId, employeeId } = await seedRestingEmployee(context, 'Rest Cancel Team', '2029-02');

    await adminPage.goto('/schedule/2029-02');
    await adminPage.getByRole('button', { name: /Rest Cancel Team/ }).click();
    const day3 = adminPage.locator('[data-date="2029-02-03"]');
    await expect(day3.locator('[data-shift-id]').first()).toBeVisible();
    await dragEmployeeTo(adminPage, day3);

    const dialog = adminPage.getByRole('dialog', { name: /rest day/i });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
    await expect(day3.locator('[data-shift-id]').first()).toBeVisible();
    await expect(day3.locator('[data-shift-id] > div', { hasText: 'Regular' })).toHaveCount(0);

    const { schedule } = await getShiftGroupsAndShifts(context, '2029-02', groupId);
    expect(schedule['2029-02-03'][0].assignments.filter((x) => x.user_id === employeeId)).toHaveLength(0);
  });
});
```

- [ ] **Step 2: Run** — `cd frontend && npx playwright test e2e/schedule/rest-day-policy.spec.js` → FAIL (no dialog; the drop is reverted with an error toast).

- [ ] **Step 3: Create the modal**

```vue
<!-- frontend/src/components/RestDayPolicyModal.vue -->
<template>
  <div class="fixed inset-0 z-[90] flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="emit('cancel')">
    <div role="dialog" aria-modal="true" aria-labelledby="rest-day-title"
      class="w-full max-w-md bg-slate-900 border border-white/10 rounded-2xl p-6 shadow-2xl">
      <h2 id="rest-day-title" class="text-lg font-semibold text-white mb-1">Assign on a rest day?</h2>
      <p class="text-sm text-slate-300 mb-4">
        {{ userName }} is resting until {{ restingUntil }}
        ({{ rule.shift_type }}: {{ rule.streak_length }} in a row, then {{ rule.rest_days }} days off).
        How should this shift count?
      </p>
      <fieldset class="space-y-2 mb-5">
        <legend class="sr-only">Rest-day policy</legend>
        <label v-for="option in options" :key="option.value" class="flex items-start gap-2 text-sm text-slate-200 cursor-pointer">
          <input v-model="policy" type="radio" name="rest-policy" :value="option.value" class="mt-1" />
          <span>
            <span class="font-medium">{{ option.label }}</span>
            <span class="block text-xs text-slate-400">{{ option.hint }}</span>
          </span>
        </label>
      </fieldset>
      <div class="flex justify-end gap-3">
        <button type="button" class="px-4 py-2 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl" @click="emit('cancel')">Cancel</button>
        <button type="button" class="px-5 py-2 text-sm font-semibold text-white bg-violet-600 hover:bg-violet-700 rounded-xl" @click="emit('choose', policy)">Assign</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';

defineProps({
  userName: { type: String, required: true },
  restingUntil: { type: String, required: true },
  rule: { type: Object, required: true },
});
const emit = defineEmits(['choose', 'cancel']);

const options = [
  { value: 'not_counted', label: "Doesn't count", hint: 'An exception; the streak and the rest window are unchanged.' },
  { value: 'counts', label: 'Counts toward the next streak', hint: 'Brings the next mandatory rest closer.' },
  { value: 'extends_rest', label: 'Extends the rest', hint: 'The rest window ends rest-days after this shift.' },
];
const policy = ref('not_counted');
</script>
```

- [ ] **Step 4: Wire it into `ScheduleView.vue`**
  - Import `RestDayPolicyModal`; state `const restPrompt = ref(null); // { shift, item, index, conflict }`.
  - Replace `doAssign`:
    ```js
    async function doAssign(shift, item, index, restPolicy = null) {
      try {
        const response = await api.post('/api/schedule/assign', {
          shift_id: shift.id,
          user_id: item.user_id,
          ...(restPolicy ? { rest_policy: restPolicy } : {}),
        });
        item.id = response.data.id;
        item.user_name = response.data.user_name;
        item.rest_policy = response.data.rest_policy;
      } catch (err) {
        // A rest day needs the manager's decision; keep the chip while asking.
        if (err.response?.status === 409 && err.response.data?.code === 'rest_window_conflict') {
          restPrompt.value = { shift, item, index, conflict: err.response.data };
          return;
        }
        shift.assignments.splice(index, 1);
        const msg = err.response?.data?.message || 'Failed to assign user.';
        showToast(msg, 'error');
      }
    }

    async function chooseRestPolicy(policy) {
      const { shift, item, index } = restPrompt.value;
      restPrompt.value = null;
      await doAssign(shift, item, index, policy);
    }

    function cancelRestPolicy() {
      const { shift, index } = restPrompt.value;
      restPrompt.value = null;
      shift.assignments.splice(index, 1);
    }
    ```
  - Mount next to the other modals:
    ```html
        <RestDayPolicyModal v-if="restPrompt" :user-name="restPrompt.item.user_name || 'This employee'"
          :resting-until="restPrompt.conflict.resting_until" :rule="restPrompt.conflict.rule"
          @choose="chooseRestPolicy" @cancel="cancelRestPolicy" />
    ```
  - In the assigned-employee chip, after the name `<span>`:
    ```html
                          <span v-if="assignment.rest_policy && assignment.rest_policy !== 'not_counted'" class="shrink-0 text-[9px] text-amber-300"
                            :title="assignment.rest_policy === 'extends_rest' ? 'Assigned during rest: extends rest' : 'Assigned during rest: counts toward next streak'">⏸</span>
    ```

- [ ] **Step 5: Run** — the new spec → 2 passed; sabotage each (skip the `.check()` → stored policy is `not_counted`, assertion fails; remove the `splice` in `cancelRestPolicy` → chip-count assertion fails); undo exactly. `npx playwright test e2e/schedule e2e/settlement-periods` all green. Biome on the changed files.

- [ ] **Step 6: Commit** — `git commit -m "feat: ask the manager how a rest-day shift counts"` (component, view, spec).

---

### Task 9: CHANGELOG and full verification

**Files:** Modify: `CHANGELOG.md`

- [ ] **Step 1: CHANGELOG** — new top entry (current format), dated 2026-10-07, titled e.g. `## 2026-10-07 — 🔁 Streaks are replayed from real assignments`. Cover: replay replaces counters (fixes: unassign/retype of a rest-starting shift, drift, skipped assignments, concurrency, rule edits now apply retroactively); manual assign on a rest day asks (doesn't count / counts / extends rest), stored as `shift_assignments.rest_policy`; Random and Rotating now skip resting employees; settlement report status as of the period end. **Deploy note:** statuses are recomputed from real history on deploy — drifted counters are corrected and Random/Rotating assignments (never counted before) now count, so some employees' streak status may change; `shift_streak_states` is dropped (its `down()` recreates it empty).
- [ ] **Step 2: Verify** — `PEST` and `PEST80` full; rebuild backend; `cd frontend && npm run test` (7) and `npm run test:e2e` twice back-to-back, all green; `git grep -n "ShiftStreakTracker\|ShiftStreakState\|shift_streak_states" -- backend/app backend/tests frontend` → only the drop migration; nothing on :5173.
- [ ] **Step 3: Commit** — `git commit -m "docs: changelog for streak replay"`.
