# Shift Streaks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a `ShiftGroup` define per-`shift_type` streak rules ("N shifts of this type in a row, then M days fully off") and have `polishLaw()` enforce them live, with manual assignment kept unblocked and bookkeeping-only.

**Architecture:** A new `ShiftStreakTracker` service (stateful, unlike the pure `SettlementPeriodCalculator`) reads/writes two new tables — `shift_streak_rules` (per-group config) and `shift_streak_states` (per-user-per-rule progress) — and is wired into three call sites: `ShiftAutoAssignController::polishLaw()` (enforces + records), `ScheduleController::assign()`/`unassign()` (records only, never blocks), and `SettlementPeriodController::show()` (reports current status). Configuration is edited from the same per-group settings modal built for settlement periods and saved via a full-replace sync endpoint.

**Tech Stack:** Laravel 9 / Pest 1.x (backend, `pgsql` test DB, `RefreshDatabase` applied globally via `tests/Pest.php`), Vue 3 `<script setup>` / Vitest + Testing Library + MSW (frontend).

**Spec:** [docs/superpowers/specs/2026-09-08-shift-streaks-design.md](../specs/2026-09-08-shift-streaks-design.md)

## Global Constraints

- Manual assignment (`ScheduleController::assign()`/`unassign()`) never blocks on streak rules — it only records bookkeeping, matching the settlement-periods precedent that manual assignment stays unblocked.
- A rest window, once granted (`resting_until` set), is never shortened, extended, or re-triggered by anything except its own natural expiry. `recordUnassignment()` only decrements `current_count`, floored at 0, and never un-sets `resting_until` or touches `last_processed_date` — an accepted simplification (see spec's Error handling section).
- Persisted state (`shift_streak_states`) is required for *correctness*, not just performance — a streak's count can span arbitrarily far back in history with no bounded lookback window, unlike settlement periods' live-derived hour totals.
- Rule validation: `shift_type` required non-empty string, max 50 chars (matches the `shifts.shift_type` column width); `streak_length`/`rest_days` required positive integers (`min:1`).
- The streak-rule sync endpoint is a full-replace (delete-then-recreate), mirroring `UserGroupController::syncUsers()` / `ScheduleController::updateShiftChecklist()` — no diffing of individual rule adds/edits/deletes. This is a deliberate spec choice, not an oversight: rule lists are short and change infrequently.
- No enforcement outside `polishLaw()`; no change to the month-based calendar/browsing UI; no interaction with the settlement-period hour cap or the existing 11h/13h/5-day rules — the streak rest window is an additional, independent filter.

---

### Task 1: Data model — streak rules and streak state

**Files:**
- Create: `backend/database/migrations/2026_09_11_130000_create_shift_streak_rules_table.php`
- Create: `backend/database/migrations/2026_09_11_130100_create_shift_streak_states_table.php`
- Create: `backend/app/Models/ShiftStreakRule.php`
- Create: `backend/app/Models/ShiftStreakState.php`
- Modify: `backend/app/Models/ShiftGroup.php`
- Test: `backend/tests/Feature/ShiftStreakModelsTest.php`

**Interfaces:**
- Produces: `ShiftStreakRule` (fillable `shift_group_id`, `shift_type`, `streak_length`, `rest_days`; relations `shiftGroup()`, `states()`), `ShiftStreakState` (fillable `user_id`, `shift_streak_rule_id`, `current_count`, `resting_until`, `last_processed_date`; relations `user()`, `rule()`), `ShiftGroup::streakRules(): HasMany`. Task 2's `ShiftStreakTracker` consumes all of these directly.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\ShiftStreakState;
use App\Models\User;

it('creates a streak rule scoped to a shift group and shift type', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    $rule = ShiftStreakRule::create([
        'shift_group_id' => $group->id,
        'shift_type' => 'night',
        'streak_length' => 3,
        'rest_days' => 2,
    ]);

    expect($rule->shift_group_id)->toBe($group->id);
    expect($group->streakRules()->pluck('shift_type')->all())->toBe(['night']);
});

it('rejects a second rule for the same shift group and shift type', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);

    expect(fn () => ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 4, 'rest_days' => 1]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('creates a streak state keyed by user and rule, and cascades on group deletion', function () {
    $group = ShiftGroup::create(['name' => 'Temp', 'sort_order' => 1]);
    $rule = ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $user = User::factory()->create();

    $state = ShiftStreakState::create([
        'user_id' => $user->id,
        'shift_streak_rule_id' => $rule->id,
        'current_count' => 1,
        'resting_until' => null,
        'last_processed_date' => '2026-09-01',
    ]);

    expect($state->current_count)->toBe(1);

    $group->delete();

    expect(ShiftStreakRule::find($rule->id))->toBeNull();
    expect(ShiftStreakState::find($state->id))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run (inside the backend container — see "Running tests" note at the end of this plan): `./vendor/bin/pest tests/Feature/ShiftStreakModelsTest.php`
Expected: FAIL — `shift_streak_rules`/`shift_streak_states` tables and `App\Models\ShiftStreakRule`/`ShiftStreakState` don't exist yet.

- [ ] **Step 3: Write the migrations and models**

`backend/database/migrations/2026_09_11_130000_create_shift_streak_rules_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_streak_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_group_id')->constrained()->cascadeOnDelete();
            $table->string('shift_type', 50);
            $table->unsignedInteger('streak_length');
            $table->unsignedInteger('rest_days');
            $table->timestamps();

            $table->unique(['shift_group_id', 'shift_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_streak_rules');
    }
};
```

`backend/database/migrations/2026_09_11_130100_create_shift_streak_states_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        Schema::dropIfExists('shift_streak_states');
    }
};
```

`backend/app/Models/ShiftStreakRule.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftStreakRule extends Model
{
    protected $fillable = ['shift_group_id', 'shift_type', 'streak_length', 'rest_days'];

    protected $casts = [
        'streak_length' => 'integer',
        'rest_days' => 'integer',
    ];

    public function shiftGroup(): BelongsTo
    {
        return $this->belongsTo(ShiftGroup::class);
    }

    public function states(): HasMany
    {
        return $this->hasMany(ShiftStreakState::class);
    }
}
```

`backend/app/Models/ShiftStreakState.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftStreakState extends Model
{
    protected $fillable = ['user_id', 'shift_streak_rule_id', 'current_count', 'resting_until', 'last_processed_date'];

    protected $casts = [
        'current_count' => 'integer',
        'resting_until' => 'date:Y-m-d',
        'last_processed_date' => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ShiftStreakRule::class, 'shift_streak_rule_id');
    }
}
```

Modify `backend/app/Models/ShiftGroup.php` — add a `streakRules()` relation next to the existing `shifts()` relation:

```php
    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function streakRules()
    {
        return $this->hasMany(ShiftStreakRule::class);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/ShiftStreakModelsTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations/2026_09_11_130000_create_shift_streak_rules_table.php backend/database/migrations/2026_09_11_130100_create_shift_streak_states_table.php backend/app/Models/ShiftStreakRule.php backend/app/Models/ShiftStreakState.php backend/app/Models/ShiftGroup.php backend/tests/Feature/ShiftStreakModelsTest.php
git commit -m "feat: add shift_streak_rules and shift_streak_states schema"
```

---

### Task 2: `ShiftStreakTracker` service

**Files:**
- Create: `backend/app/Services/ShiftStreakTracker.php`
- Test: `backend/tests/Feature/ShiftStreakTrackerTest.php`

**Interfaces:**
- Consumes: `ShiftGroup::streakRules()` (Task 1), `ShiftStreakState` model (Task 1).
- Produces: `rulesForGroup(ShiftGroup $group): Collection` (keyed by `shift_type`), `loadStates(Collection $rules, array $userIds): array` (keyed `"{userId}:{ruleId}"`, each `['current_count' => int, 'resting_until' => ?Carbon, 'last_processed_date' => ?Carbon, 'dirty' => bool]`), `isBlocked(array &$states, Collection $rules, int $userId, Carbon $date): bool`, `recordAssignment(array &$states, Collection $rules, int $userId, ?string $shiftType, Carbon $date, bool $advanceWatermark = false): void`, `wasAlreadyProcessed(array $states, Collection $rules, int $userId, ?string $shiftType, Carbon $date): bool`, `recordUnassignment(array &$states, Collection $rules, int $userId, ?string $shiftType): void`, `persist(array $states): void`. Tasks 3, 4, and 6 call these exact signatures.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\ShiftStreakState;
use App\Models\User;
use App\Services\ShiftStreakTracker;
use Illuminate\Support\Carbon;

function makeStreakRule(int $groupId, string $shiftType, int $streakLength, int $restDays): ShiftStreakRule
{
    return ShiftStreakRule::create([
        'shift_group_id' => $groupId,
        'shift_type' => $shiftType,
        'streak_length' => $streakLength,
        'rest_days' => $restDays,
    ]);
}

it('increments the count on assignment without triggering rest below the streak length', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = makeStreakRule($group->id, 'night', 3, 2);
    $user = User::factory()->create();
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$user->id]);

    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-01'));
    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-02'));

    $key = "{$user->id}:{$rule->id}";
    expect($states[$key]['current_count'])->toBe(2);
    expect($states[$key]['resting_until'])->toBeNull();
});

it('triggers a rest window and resets the count once the streak length is reached', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = makeStreakRule($group->id, 'night', 3, 2);
    $user = User::factory()->create();
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$user->id]);

    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-01'));
    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-02'));
    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-03'));

    $key = "{$user->id}:{$rule->id}";
    expect($states[$key]['current_count'])->toBe(0);
    expect($states[$key]['resting_until']->toDateString())->toBe('2026-09-05');
});

it('leaves the count and rest window untouched when the assignment is a different shift_type', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = makeStreakRule($group->id, 'night', 3, 2);
    $user = User::factory()->create();
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$user->id]);

    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-01'));
    $tracker->recordAssignment($states, $rules, $user->id, 'day', Carbon::parse('2026-09-02'));

    $key = "{$user->id}:{$rule->id}";
    expect($states[$key]['current_count'])->toBe(1);
});

it('leaves the count untouched when recordAssignment is called with a null shift_type', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = makeStreakRule($group->id, 'night', 3, 2);
    $user = User::factory()->create();
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$user->id]);

    $tracker->recordAssignment($states, $rules, $user->id, null, Carbon::parse('2026-09-01'));

    $key = "{$user->id}:{$rule->id}";
    expect($states[$key]['current_count'])->toBe(0);
});

it('unions multiple simultaneously-active rest windows across different rules when checking isBlocked', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    makeStreakRule($group->id, 'night', 1, 2);
    makeStreakRule($group->id, 'day', 5, 1);
    $user = User::factory()->create();
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$user->id]);

    // One night shift immediately triggers its 2-day rest window (streak_length 1).
    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-01'));

    expect($tracker->isBlocked($states, $rules, $user->id, Carbon::parse('2026-09-02')))->toBeTrue();
    expect($tracker->isBlocked($states, $rules, $user->id, Carbon::parse('2026-09-03')))->toBeTrue();
    expect($tracker->isBlocked($states, $rules, $user->id, Carbon::parse('2026-09-04')))->toBeFalse();
});

it('floors the decrement at 0 when recordUnassignment is called with no prior assignment', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = makeStreakRule($group->id, 'night', 3, 2);
    $user = User::factory()->create();
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$user->id]);

    $tracker->recordUnassignment($states, $rules, $user->id, 'night');

    $key = "{$user->id}:{$rule->id}";
    expect($states[$key]['current_count'])->toBe(0);
});

it('persists only dirty states and reloads the persisted value', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = makeStreakRule($group->id, 'night', 3, 2);
    $user = User::factory()->create();
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$user->id]);

    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-01'));
    $tracker->persist($states);

    expect(ShiftStreakState::where('user_id', $user->id)->where('shift_streak_rule_id', $rule->id)->first()->current_count)->toBe(1);

    $reloaded = $tracker->loadStates($rules, [$user->id]);
    $key = "{$user->id}:{$rule->id}";
    expect($reloaded[$key]['current_count'])->toBe(1);
});

it('does not reprocess an assignment date already covered by the last_processed_date watermark', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    makeStreakRule($group->id, 'night', 3, 2);
    $user = User::factory()->create();
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$user->id]);

    $tracker->recordAssignment($states, $rules, $user->id, 'night', Carbon::parse('2026-09-01'), advanceWatermark: true);

    expect($tracker->wasAlreadyProcessed($states, $rules, $user->id, 'night', Carbon::parse('2026-09-01')))->toBeTrue();
    expect($tracker->wasAlreadyProcessed($states, $rules, $user->id, 'night', Carbon::parse('2026-09-02')))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/ShiftStreakTrackerTest.php`
Expected: FAIL — `App\Services\ShiftStreakTracker` doesn't exist yet.

- [ ] **Step 3: Write the service**

`backend/app/Services/ShiftStreakTracker.php`:

```php
<?php

namespace App\Services;

use App\Models\ShiftGroup;
use App\Models\ShiftStreakState;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ShiftStreakTracker
{
    public function rulesForGroup(ShiftGroup $group): Collection
    {
        return $group->streakRules()->get()->keyBy('shift_type');
    }

    public function loadStates(Collection $rules, array $userIds): array
    {
        $states = [];
        foreach ($userIds as $userId) {
            foreach ($rules as $rule) {
                $states["{$userId}:{$rule->id}"] = [
                    'current_count' => 0,
                    'resting_until' => null,
                    'last_processed_date' => null,
                    'dirty' => false,
                ];
            }
        }

        if ($rules->isEmpty() || empty($userIds)) {
            return $states;
        }

        $existing = ShiftStreakState::whereIn('user_id', $userIds)
            ->whereIn('shift_streak_rule_id', $rules->pluck('id'))
            ->get();

        foreach ($existing as $row) {
            $key = "{$row->user_id}:{$row->shift_streak_rule_id}";
            $states[$key] = [
                'current_count' => $row->current_count,
                'resting_until' => $row->resting_until,
                'last_processed_date' => $row->last_processed_date,
                'dirty' => false,
            ];
        }

        return $states;
    }

    public function isBlocked(array &$states, Collection $rules, int $userId, Carbon $date): bool
    {
        $day = $date->copy()->startOfDay();

        foreach ($rules as $rule) {
            $restingUntil = $states["{$userId}:{$rule->id}"]['resting_until'] ?? null;
            if ($restingUntil !== null && $day->lte($restingUntil)) {
                return true;
            }
        }

        return false;
    }

    public function recordAssignment(array &$states, Collection $rules, int $userId, ?string $shiftType, Carbon $date, bool $advanceWatermark = false): void
    {
        $rule = $shiftType !== null ? $rules->get($shiftType) : null;
        if ($rule === null) {
            return;
        }

        $key = "{$userId}:{$rule->id}";
        $day = $date->copy()->startOfDay();

        $states[$key]['current_count']++;
        if ($states[$key]['current_count'] >= $rule->streak_length) {
            $states[$key]['resting_until'] = $day->copy()->addDays($rule->rest_days);
            $states[$key]['current_count'] = 0;
        }

        if ($advanceWatermark) {
            $existingWatermark = $states[$key]['last_processed_date'];
            if ($existingWatermark === null || $day->gt($existingWatermark)) {
                $states[$key]['last_processed_date'] = $day;
            }
        }

        $states[$key]['dirty'] = true;
    }

    public function wasAlreadyProcessed(array $states, Collection $rules, int $userId, ?string $shiftType, Carbon $date): bool
    {
        $rule = $shiftType !== null ? $rules->get($shiftType) : null;
        if ($rule === null) {
            return false;
        }

        $watermark = $states["{$userId}:{$rule->id}"]['last_processed_date'] ?? null;

        return $watermark !== null && $date->copy()->startOfDay()->lte($watermark);
    }

    public function recordUnassignment(array &$states, Collection $rules, int $userId, ?string $shiftType): void
    {
        $rule = $shiftType !== null ? $rules->get($shiftType) : null;
        if ($rule === null) {
            return;
        }

        $key = "{$userId}:{$rule->id}";
        $states[$key]['current_count'] = max(0, $states[$key]['current_count'] - 1);
        $states[$key]['dirty'] = true;
    }

    public function persist(array $states): void
    {
        foreach ($states as $key => $state) {
            if (!$state['dirty']) {
                continue;
            }

            [$userId, $ruleId] = array_map('intval', explode(':', $key));

            ShiftStreakState::updateOrCreate(
                ['user_id' => $userId, 'shift_streak_rule_id' => $ruleId],
                [
                    'current_count' => $state['current_count'],
                    'resting_until' => $state['resting_until'],
                    'last_processed_date' => $state['last_processed_date'],
                ]
            );
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/ShiftStreakTrackerTest.php`
Expected: PASS (8 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/ShiftStreakTracker.php backend/tests/Feature/ShiftStreakTrackerTest.php
git commit -m "feat: add ShiftStreakTracker service"
```

---

### Task 3: `polishLaw()` integration

**Files:**
- Modify: `backend/app/Http/Controllers/ShiftAutoAssignController.php:556-732`
- Test: `backend/tests/Feature/PolishLawStreakTest.php`

**Interfaces:**
- Consumes: `ShiftStreakTracker::rulesForGroup()`, `loadStates()`, `wasAlreadyProcessed()`, `recordAssignment()`, `isBlocked()`, `persist()` (Task 2).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\ShiftStreakState;
use App\Models\User;

function makeStreakShift(int $groupId, string $date, int $shiftNumber, string $shiftType, string $start, string $end): Shift
{
    return Shift::create([
        'date' => $date,
        'shift_number' => $shiftNumber,
        'shift_group_id' => $groupId,
        'shift_type' => $shiftType,
        'start_time' => $start,
        'end_time' => $end,
    ]);
}

it('blocks the 4th night shift after a 3-shift streak and blocks a day shift too during the same rest window', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01', 'weekly_hour_cap' => 168,
    ]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    makeStreakShift($group->id, '2026-09-01', 1, 'night', '22:00', '06:00');
    makeStreakShift($group->id, '2026-09-02', 1, 'night', '22:00', '06:00');
    makeStreakShift($group->id, '2026-09-03', 1, 'night', '22:00', '06:00');
    // Would be the 4th night shift in a row — must be blocked by the rest window the 3rd triggered.
    makeStreakShift($group->id, '2026-09-04', 1, 'night', '22:00', '06:00');
    // A day shift during the same rest window — must also be blocked ("fully off", not just off nights).
    makeStreakShift($group->id, '2026-09-05', 1, 'day', '06:00', '14:00');

    $response = $this->actingAs($maintainer)->postJson('/api/schedule/auto-assign/polish-law', [
        'month' => '2026-09',
        'shift_group_id' => $group->id,
        'users_per_shift_weekday' => 1,
        'users_per_shift_weekend' => 1,
        'rotation_days' => 7,
        'player_mode' => 'include',
        'player_ids' => [$employee->id],
    ]);

    $response->assertOk();
    expect($response->json('total_assigned'))->toBe(3);
});

it('carries streak state across separate generation requests for different months', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-08-01', 'weekly_hour_cap' => 168,
    ]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    // 2 night shifts in August — one shy of the streak, no rest window yet.
    makeStreakShift($group->id, '2026-08-30', 1, 'night', '22:00', '06:00');
    makeStreakShift($group->id, '2026-08-31', 1, 'night', '22:00', '06:00');

    $this->actingAs($maintainer)->postJson('/api/schedule/auto-assign/polish-law', [
        'month' => '2026-08',
        'shift_group_id' => $group->id,
        'users_per_shift_weekday' => 1,
        'users_per_shift_weekend' => 1,
        'rotation_days' => 7,
        'player_mode' => 'include',
        'player_ids' => [$employee->id],
    ])->assertOk();

    // September's 1st night shift should complete the streak carried over from
    // August (proving the count is not reset just because a new month started).
    makeStreakShift($group->id, '2026-09-01', 1, 'night', '22:00', '06:00');
    makeStreakShift($group->id, '2026-09-02', 1, 'night', '22:00', '06:00');

    $response = $this->actingAs($maintainer)->postJson('/api/schedule/auto-assign/polish-law', [
        'month' => '2026-09',
        'shift_group_id' => $group->id,
        'users_per_shift_weekday' => 1,
        'users_per_shift_weekend' => 1,
        'rotation_days' => 7,
        'player_mode' => 'include',
        'player_ids' => [$employee->id],
    ]);

    $response->assertOk();
    // Sep 1 completes the streak (rest triggered); Sep 2 is blocked by it.
    expect($response->json('total_assigned'))->toBe(1);
});

it('is idempotent: running polishLaw twice over the same already-generated month leaves streak state unchanged', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01', 'weekly_hour_cap' => 168,
    ]);
    $rule = ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    makeStreakShift($group->id, '2026-09-01', 1, 'night', '22:00', '06:00');
    makeStreakShift($group->id, '2026-09-02', 1, 'night', '22:00', '06:00');

    $payload = [
        'month' => '2026-09',
        'shift_group_id' => $group->id,
        'users_per_shift_weekday' => 1,
        'users_per_shift_weekend' => 1,
        'rotation_days' => 7,
        'player_mode' => 'include',
        'player_ids' => [$employee->id],
    ];

    $this->actingAs($maintainer)->postJson('/api/schedule/auto-assign/polish-law', $payload)->assertOk();

    $state = ShiftStreakState::where('user_id', $employee->id)->where('shift_streak_rule_id', $rule->id)->first();
    expect($state->current_count)->toBe(2);

    // Without the last_processed_date watermark, this re-scan would double-count to 4.
    $this->actingAs($maintainer)->postJson('/api/schedule/auto-assign/polish-law', $payload)->assertOk();

    $state->refresh();
    expect($state->current_count)->toBe(2);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PolishLawStreakTest.php`
Expected: FAIL — `total_assigned` is 5 in the first test (streak isn't blocking anything yet), 2 in the second (no carryover), 4 in the third (double-counted).

- [ ] **Step 3: Wire the tracker into `polishLaw()`**

In `backend/app/Http/Controllers/ShiftAutoAssignController.php`, insert a new precompute block right after the existing settlement-period precompute's `try`/`catch` (i.e. right before the `// Build shift time info` comment, currently line 584):

```php
        $streakTracker = new \App\Services\ShiftStreakTracker();
        $streakRules = $streakTracker->rulesForGroup($group);
        $streakStates = $streakTracker->loadStates($streakRules, $employees->pluck('id')->toArray());

        // Build shift time info
```

In the pre-population loop (currently lines 611-630), add a streak pre-population call inside the existing `if (isset($employeeState[$a->user_id]) && isset($shiftTimes[$shift->id]))` block, right after the last existing line in it:

```php
            // Pre-populate state for existing assignments
            foreach ($shift->assignments as $a) {
                if (isset($employeeState[$a->user_id]) && isset($shiftTimes[$shift->id])) {
                    $st = $shiftTimes[$shift->id];
                    $weekNum = $weekKeyByDate[$dateStr];
                    $employeeState[$a->user_id]['last_shift_end'] = $st['end'];
                    $employeeState[$a->user_id]['total_hours'] += $st['duration_hours'];
                    $employeeState[$a->user_id]['total_shifts']++;
                    $employeeState[$a->user_id]['weekly_hours'][$weekNum] = ($employeeState[$a->user_id]['weekly_hours'][$weekNum] ?? 0) + $st['duration_hours'];
                    $employeeState[$a->user_id]['weekly_days'][$weekNum][$dateStr] = true;

                    if (!$streakRules->isEmpty() && !$streakTracker->wasAlreadyProcessed($streakStates, $streakRules, $a->user_id, $shift->shift_type, $shift->date)) {
                        $streakTracker->recordAssignment($streakStates, $streakRules, $a->user_id, $shift->shift_type, $shift->date, advanceWatermark: true);
                    }
                }
            }
```

In the candidate-eligibility loop, add the blocking filter right before the `// Scoring` comment (currently line 697):

```php
                // Rule: shift-streak mandatory rest (see ShiftStreakTracker)
                if (!$streakRules->isEmpty() && $streakTracker->isBlocked($streakStates, $streakRules, $emp->id, $shift->date)) {
                    continue;
                }

                // Scoring: prefer employees with fewer hours (fairness)
                $score = $state['total_hours'];
```

In the assignment-creation loop, record the new assignment right after the existing `weekly_days` update (currently line 723):

```php
                $assignmentTracker[$currentKey][] = $userId;
                $employeeState[$userId]['last_shift_end'] = $st['end'];
                $employeeState[$userId]['total_hours'] += $st['duration_hours'];
                $employeeState[$userId]['total_shifts']++;
                $employeeState[$userId]['weekly_hours'][$weekNum] = ($employeeState[$userId]['weekly_hours'][$weekNum] ?? 0) + $st['duration_hours'];
                $employeeState[$userId]['weekly_days'][$weekNum][$dateStr] = true;

                if (!$streakRules->isEmpty()) {
                    $streakTracker->recordAssignment($streakStates, $streakRules, $userId, $shift->shift_type, $shift->date, advanceWatermark: true);
                }

                $assigned++;
                $totalAssigned++;
```

Finally, right after the main `foreach ($shifts as $shift) { ... }` loop closes (currently line 732), before the `// Count night shifts` comment, persist once:

```php
        if (!$streakRules->isEmpty()) {
            $streakTracker->persist($streakStates);
        }

        // Count night shifts
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/PolishLawStreakTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Run the full backend suite to check for regressions**

Run: `./vendor/bin/pest`
Expected: All tests pass, including the pre-existing `PolishLawSettlementPeriodTest.php`.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/ShiftAutoAssignController.php backend/tests/Feature/PolishLawStreakTest.php
git commit -m "feat: enforce shift-streak rest windows in polishLaw()"
```

---

### Task 4: Manual assign/unassign integration

**Files:**
- Modify: `backend/app/Http/Controllers/ScheduleController.php:283-332`
- Test: `backend/tests/Feature/ScheduleControllerStreakTest.php`

**Interfaces:**
- Consumes: `ShiftStreakTracker::rulesForGroup()`, `loadStates()`, `recordAssignment()`, `recordUnassignment()`, `persist()` (Task 2).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\ShiftStreakState;
use App\Models\User;

it('increments the streak counter on a manual assign without being blocked by an active rest window', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    $shift = Shift::create([
        'date' => '2026-09-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
    ]);

    $response = $this->actingAs($maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $shift->id,
        'user_id' => $employee->id,
    ]);

    $response->assertCreated();
    $state = ShiftStreakState::where('user_id', $employee->id)->where('shift_streak_rule_id', $rule->id)->first();
    expect($state->current_count)->toBe(1);
});

it('decrements the streak counter on a manual unassign', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    $shift = Shift::create([
        'date' => '2026-09-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
    ]);

    $this->actingAs($maintainer)->postJson('/api/schedule/assign', [
        'shift_id' => $shift->id,
        'user_id' => $employee->id,
    ])->assertCreated();

    $assignment = ShiftAssignment::where('shift_id', $shift->id)->where('user_id', $employee->id)->first();

    $this->actingAs($maintainer)->deleteJson("/api/schedule/unassign/{$assignment->id}")->assertOk();

    $state = ShiftStreakState::where('user_id', $employee->id)->where('shift_streak_rule_id', $rule->id)->first();
    expect($state->current_count)->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/ScheduleControllerStreakTest.php`
Expected: FAIL — no `ShiftStreakState` row is ever created by `assign()`/`unassign()` yet.

- [ ] **Step 3: Wire the tracker into `assign()` and `unassign()`**

Replace `assign()` and `unassign()` in `backend/app/Http/Controllers/ScheduleController.php` (currently lines 283-332):

```php
    public function assign(Request $request)
    {
        $request->validate([
            'shift_id' => ['required', 'exists:shifts,id'],
            'user_id' => ['required', 'exists:users,id'],
        ]);

        // Check if user is already assigned to this shift
        $existing = ShiftAssignment::where('shift_id', $request->shift_id)
            ->where('user_id', $request->user_id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'User is already assigned to this shift.',
                'assignment' => $existing,
            ], 422);
        }

        $assignment = ShiftAssignment::create([
            'shift_id' => $request->shift_id,
            'user_id' => $request->user_id,
        ]);

        $shift = Shift::find($request->shift_id);
        $streakTracker = new \App\Services\ShiftStreakTracker();
        $streakRules = $streakTracker->rulesForGroup($shift->shiftGroup);
        if (!$streakRules->isEmpty()) {
            $streakStates = $streakTracker->loadStates($streakRules, [(int) $request->user_id]);
            $streakTracker->recordAssignment($streakStates, $streakRules, (int) $request->user_id, $shift->shift_type, $shift->date, advanceWatermark: true);
            $streakTracker->persist($streakStates);
        }

        $assignment->load('user:id,name');

        return response()->json([
            'id' => $assignment->id,
            'shift_id' => $assignment->shift_id,
            'user_id' => $assignment->user_id,
            'user_name' => $assignment->user->name,
        ], 201);
    }

    /**
     * Remove a user from a shift slot.
     */
    public function unassign(ShiftAssignment $assignment)
    {
        $shift = $assignment->shift;
        $streakTracker = new \App\Services\ShiftStreakTracker();
        $streakRules = $streakTracker->rulesForGroup($shift->shiftGroup);
        if (!$streakRules->isEmpty()) {
            $streakStates = $streakTracker->loadStates($streakRules, [$assignment->user_id]);
            $streakTracker->recordUnassignment($streakStates, $streakRules, $assignment->user_id, $shift->shift_type);
            $streakTracker->persist($streakStates);
        }

        // Auto-acknowledge any pending change requests for this assignment
        ShiftChangeRequest::where('shift_assignment_id', $assignment->id)
            ->where('status', 'pending')
            ->update(['status' => 'acknowledged']);

        $assignment->delete();

        return response()->json([
            'message' => 'Assignment removed successfully.',
        ]);
    }
```

(The `assign()` docblock above it, `/** Assign a user to a shift slot. */`, is unchanged.)

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/ScheduleControllerStreakTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/ScheduleController.php backend/tests/Feature/ScheduleControllerStreakTest.php
git commit -m "feat: record shift-streak bookkeeping on manual assign/unassign"
```

---

### Task 5: Streak-rule configuration endpoint

**Files:**
- Modify: `backend/app/Http/Controllers/ShiftGroupController.php`
- Modify: `backend/routes/api.php:251`
- Test: `backend/tests/Feature/ShiftGroupControllerStreakRulesTest.php`

**Interfaces:**
- Produces: `PUT /api/shift-groups/{shiftGroup}/streak-rules` (body `{ rules: [{shift_type, streak_length, rest_days}] }`, admin-only), and `GET /api/shift-groups` now includes each group's `streak_rules` array. Task 7 (frontend) consumes both.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;

it('replaces the full streak-rule set for a group in one call', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'old', 'streak_length' => 1, 'rest_days' => 1]);

    $response = $this->actingAs($admin)->putJson("/api/shift-groups/{$group->id}/streak-rules", [
        'rules' => [
            ['shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2],
            ['shift_type' => 'day', 'streak_length' => 5, 'rest_days' => 1],
        ],
    ]);

    $response->assertOk();
    expect(ShiftStreakRule::where('shift_group_id', $group->id)->pluck('shift_type')->sort()->values()->all())
        ->toBe(['day', 'night']);
    expect(ShiftStreakRule::where('shift_type', 'old')->count())->toBe(0);
});

it('replacing the rule set with an empty array removes all existing rules', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);

    $this->actingAs($admin)->putJson("/api/shift-groups/{$group->id}/streak-rules", ['rules' => []])
        ->assertOk();

    expect(ShiftStreakRule::where('shift_group_id', $group->id)->count())->toBe(0);
});

it('rejects a streak_length of 0', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    $this->actingAs($admin)->putJson("/api/shift-groups/{$group->id}/streak-rules", [
        'rules' => [['shift_type' => 'night', 'streak_length' => 0, 'rest_days' => 2]],
    ])->assertStatus(422)->assertJsonValidationErrors(['rules.0.streak_length']);
});

it('rejects two rules for the same shift_type in the same payload', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    $response = $this->actingAs($admin)->putJson("/api/shift-groups/{$group->id}/streak-rules", [
        'rules' => [
            ['shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2],
            ['shift_type' => 'night', 'streak_length' => 4, 'rest_days' => 1],
        ],
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('one streak rule per group');
});

it('includes streak_rules on the shift-groups index response', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);

    $response = $this->actingAs($admin)->getJson('/api/shift-groups');

    $response->assertOk();
    expect($response->json('0.streak_rules.0.shift_type'))->toBe('night');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/ShiftGroupControllerStreakRulesTest.php`
Expected: FAIL — route `PUT /shift-groups/{shiftGroup}/streak-rules` doesn't exist (404), and `GET /shift-groups` doesn't include `streak_rules`.

- [ ] **Step 3: Add the endpoint and eager-load the relation**

In `backend/app/Http/Controllers/ShiftGroupController.php`, add the import and change `index()`:

```php
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShiftGroupController extends Controller
{
    /**
     * List all shift groups ordered by sort_order.
     */
    public function index()
    {
        $groups = ShiftGroup::with('streakRules')->orderBy('sort_order')->orderBy('id')->get();

        return response()->json($groups);
    }
```

Add a new method at the end of the class, right before the closing `}`:

```php
    /**
     * Replace a group's full set of shift-streak rules in one call.
     */
    public function syncStreakRules(Request $request, ShiftGroup $shiftGroup)
    {
        $request->validate([
            'rules' => ['present', 'array'],
            'rules.*.shift_type' => ['required', 'string', 'max:50'],
            'rules.*.streak_length' => ['required', 'integer', 'min:1'],
            'rules.*.rest_days' => ['required', 'integer', 'min:1'],
        ]);

        $shiftTypes = array_column($request->rules, 'shift_type');
        if (count($shiftTypes) !== count(array_unique($shiftTypes))) {
            return response()->json([
                'message' => 'Each shift_type can only have one streak rule per group.',
            ], 422);
        }

        $shiftGroup->streakRules()->delete();

        foreach ($request->rules as $rule) {
            ShiftStreakRule::create([
                'shift_group_id' => $shiftGroup->id,
                'shift_type' => $rule['shift_type'],
                'streak_length' => $rule['streak_length'],
                'rest_days' => $rule['rest_days'],
            ]);
        }

        $shiftGroup->load('streakRules');

        return response()->json($shiftGroup);
    }
}
```

In `backend/routes/api.php`, add the route right after the existing `/shift-groups/reorder` route (currently line 251):

```php
        Route::post('/shift-groups/reorder', [ShiftGroupController::class, 'reorder']);
        Route::put('/shift-groups/{shiftGroup}/streak-rules', [ShiftGroupController::class, 'syncStreakRules']);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/ShiftGroupControllerStreakRulesTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/ShiftGroupController.php backend/routes/api.php backend/tests/Feature/ShiftGroupControllerStreakRulesTest.php
git commit -m "feat: add streak-rule sync endpoint on shift groups"
```

---

### Task 6: Streak status in the Settlement Periods report

**Files:**
- Modify: `backend/app/Http/Controllers/SettlementPeriodController.php`
- Test: `backend/tests/Feature/SettlementPeriodControllerStreakTest.php`

**Interfaces:**
- Consumes: `ShiftStreakTracker::rulesForGroup()`, `loadStates()` (Task 2).
- Produces: `GET /api/settlement-periods` response now includes `employees[].streak_status: [{shift_type, current_count, streak_length, resting_until}]`. Task 8 (frontend) consumes this.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;
use App\Services\ShiftStreakTracker;
use Illuminate\Support\Carbon;

it('includes streak_status per employee in the settlement periods report', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01',
    ]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    foreach (['2026-09-01', '2026-09-02'] as $date) {
        $shift = Shift::create([
            'date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night',
            'start_time' => '22:00', 'end_time' => '06:00',
        ]);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);
    }

    // The report reads persisted streak state, not the raw assignments — populate
    // it the same way a real polishLaw()/manual-assign call would.
    $tracker = new ShiftStreakTracker();
    $rules = $tracker->rulesForGroup($group);
    $states = $tracker->loadStates($rules, [$employee->id]);
    $tracker->recordAssignment($states, $rules, $employee->id, 'night', Carbon::parse('2026-09-01'));
    $tracker->recordAssignment($states, $rules, $employee->id, 'night', Carbon::parse('2026-09-02'));
    $tracker->persist($states);

    $response = $this->actingAs($maintainer)->getJson('/api/settlement-periods?' . http_build_query([
        'shift_group_id' => $group->id,
        'offset' => 0,
    ]));

    $response->assertOk();
    $status = $response->json('employees.0.streak_status');
    expect($status)->toHaveCount(1);
    expect($status[0])->toBe([
        'shift_type' => 'night',
        'current_count' => 2,
        'streak_length' => 3,
        'resting_until' => null,
    ]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/SettlementPeriodControllerStreakTest.php`
Expected: FAIL — `streak_status` key does not exist on the response.

- [ ] **Step 3: Add `streak_status` to the report**

Replace the full contents of `backend/app/Http/Controllers/SettlementPeriodController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Services\SettlementPeriodCalculator;
use App\Services\ShiftStreakTracker;
use Illuminate\Http\Request;

class SettlementPeriodController extends Controller
{
    public function show(Request $request)
    {
        $request->validate([
            'shift_group_id' => ['required', 'integer', 'exists:shift_groups,id'],
            'offset' => ['sometimes', 'integer'],
        ]);

        $group = ShiftGroup::findOrFail($request->shift_group_id);
        $offset = (int) $request->input('offset', 0);
        $calculator = new SettlementPeriodCalculator();

        try {
            $period = $calculator->periodByOffset($group, $offset);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => "Settlement period configuration error for this group: {$e->getMessage()}",
            ], 422);
        }

        $assignments = ShiftAssignment::with(['shift', 'user:id,name'])
            ->whereHas('shift', function ($q) use ($group, $period) {
                $q->where('shift_group_id', $group->id)
                    ->whereBetween('date', [$period['start']->toDateString(), $period['end']->toDateString()]);
            })
            ->get();

        $employeeWeekHours = [];
        $employeeNames = [];

        foreach ($assignments as $assignment) {
            $shift = $assignment->shift;
            $timing = $calculator->shiftTiming($shift);
            $match = $calculator->weekFor($period, $shift->date->copy()->startOfDay());

            $userId = $assignment->user_id;
            $employeeNames[$userId] = $assignment->user->name;
            $employeeWeekHours[$userId][$match['index']] =
                ($employeeWeekHours[$userId][$match['index']] ?? 0) + $timing['duration_hours'];
        }

        $weeks = array_map(fn($w) => [
            'start' => $w['start']->toDateString(),
            'end' => $w['end']->toDateString(),
            'cap_hours' => $w['cap_hours'],
        ], $period['weeks']);

        $streakTracker = new ShiftStreakTracker();
        $streakRules = $streakTracker->rulesForGroup($group);
        $streakStates = $streakTracker->loadStates($streakRules, array_keys($employeeNames));

        $employees = [];
        foreach ($employeeNames as $userId => $name) {
            $weeklyHours = [];
            $overCapWeeks = [];
            foreach ($period['weeks'] as $index => $week) {
                $hours = round($employeeWeekHours[$userId][$index] ?? 0, 2);
                $weeklyHours[] = $hours;
                if ($hours > $week['cap_hours']) {
                    $overCapWeeks[] = $index;
                }
            }

            $streakStatus = [];
            foreach ($streakRules as $rule) {
                $state = $streakStates["{$userId}:{$rule->id}"];
                $streakStatus[] = [
                    'shift_type' => $rule->shift_type,
                    'current_count' => $state['current_count'],
                    'streak_length' => $rule->streak_length,
                    'resting_until' => $state['resting_until']?->toDateString(),
                ];
            }

            $employees[] = [
                'id' => $userId,
                'name' => $name,
                'weekly_hours' => $weeklyHours,
                'total_hours' => round(array_sum($weeklyHours), 2),
                'over_cap_weeks' => $overCapWeeks,
                'streak_status' => $streakStatus,
            ];
        }

        usort($employees, fn($a, $b) => strcmp($a['name'], $b['name']));

        return response()->json([
            'period' => [
                'period_number' => $period['period_number'],
                'start' => $period['start']->toDateString(),
                'end' => $period['end']->toDateString(),
            ],
            'weeks' => $weeks,
            'employees' => array_values($employees),
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/SettlementPeriodControllerStreakTest.php`
Expected: PASS (1 test)

- [ ] **Step 5: Run the full backend suite to check for regressions**

Run: `./vendor/bin/pest`
Expected: All tests pass, including the pre-existing `SettlementPeriodControllerTest.php`.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/SettlementPeriodController.php backend/tests/Feature/SettlementPeriodControllerStreakTest.php
git commit -m "feat: report streak status in the settlement periods endpoint"
```

---

### Task 7: Frontend — streak-rule configuration UI

**Files:**
- Modify: `frontend/src/views/ScheduleView.vue:747-751` (template), `frontend/src/views/ScheduleView.vue:1404-1448` (script)

**Interfaces:**
- Consumes: `PUT /api/shift-groups/{id}/streak-rules`, `group.streak_rules` from `GET /api/shift-groups` (Task 5).

This task has no dedicated component test — matching the existing convention in this file (the settlement-period settings modal it extends has none either; `ScheduleView.vue` has zero test coverage in this codebase today). Verify manually per Step 4.

- [ ] **Step 1: Add the streak-rules section to the settings modal template**

In `frontend/src/views/ScheduleView.vue`, insert a new section between the existing "Weekly Hour Cap" field and the modal's action buttons (currently lines 747-753):

```html
          <div class="mb-5">
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Weekly Hour Cap</label>
            <input v-model.number="groupSettingsForm.weekly_hour_cap" type="number" min="0" step="0.5"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
          </div>

          <div class="mb-5">
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Shift Streak Rules</label>
            <div v-for="(rule, idx) in groupSettingsForm.streak_rules" :key="idx" class="flex items-center gap-2 mb-2">
              <input v-model="rule.shift_type" type="text" placeholder="shift type (e.g. night)"
                class="flex-1 px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
              <input v-model.number="rule.streak_length" type="number" min="1" title="Streak length"
                class="w-16 px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
              <span class="text-slate-500 text-xs whitespace-nowrap">then rest</span>
              <input v-model.number="rule.rest_days" type="number" min="1" title="Rest days"
                class="w-16 px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
              <span class="text-slate-500 text-xs whitespace-nowrap">days</span>
              <button @click="groupSettingsForm.streak_rules.splice(idx, 1)"
                class="w-6 h-6 flex items-center justify-center text-slate-500 hover:text-red-400 transition-colors shrink-0" title="Remove rule">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
              </button>
            </div>
            <button @click="groupSettingsForm.streak_rules.push({ shift_type: '', streak_length: 3, rest_days: 2 })"
              class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">+ Add streak rule</button>
          </div>

          <div class="flex items-center justify-end gap-3">
```

- [ ] **Step 2: Wire the form state**

Replace the `groupSettingsForm` ref, `openGroupSettings()`, and `saveGroupSettings()` in the `<script setup>` block (currently lines 1407-1448):

```js
const groupSettingsForm = ref({
  settlement_mode: 'monthly',
  settlement_anchor_date: '',
  settlement_custom_length_days: null,
  weekly_hour_cap: 48,
  streak_rules: [],
});

function openGroupSettings(group) {
  groupSettingsTarget.value = group;
  groupSettingsForm.value = {
    settlement_mode: group.settlement_mode || 'monthly',
    settlement_anchor_date: group.settlement_anchor_date || '',
    settlement_custom_length_days: group.settlement_custom_length_days ?? null,
    weekly_hour_cap: group.weekly_hour_cap ?? 48,
    streak_rules: (group.streak_rules ?? []).map((r) => ({
      shift_type: r.shift_type,
      streak_length: r.streak_length,
      rest_days: r.rest_days,
    })),
  };
  showGroupSettingsModal.value = true;
}

async function saveGroupSettings() {
  if (!groupSettingsTarget.value) return;
  savingGroupSettings.value = true;
  try {
    const payload = {
      settlement_mode: groupSettingsForm.value.settlement_mode,
      settlement_anchor_date: groupSettingsForm.value.settlement_anchor_date,
      settlement_custom_length_days:
        groupSettingsForm.value.settlement_mode === 'custom'
          ? groupSettingsForm.value.settlement_custom_length_days
          : null,
      weekly_hour_cap: groupSettingsForm.value.weekly_hour_cap,
    };
    const res = await api.put(`/api/shift-groups/${groupSettingsTarget.value.id}`, payload);

    const streakRes = await api.put(`/api/shift-groups/${groupSettingsTarget.value.id}/streak-rules`, {
      rules: groupSettingsForm.value.streak_rules.filter((r) => r.shift_type),
    });

    const idx = shiftGroups.value.findIndex((g) => g.id === res.data.id);
    if (idx !== -1) shiftGroups.value[idx] = { ...res.data, streak_rules: streakRes.data.streak_rules };
    showGroupSettingsModal.value = false;
    showToast('Settlement settings saved.');
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to save settlement settings.', 'error');
  } finally {
    savingGroupSettings.value = false;
  }
}
```

- [ ] **Step 3: Lint the touched file**

Run: `cd frontend && npx biome check --write src/views/ScheduleView.vue`
Expected: No errors (only auto-formatting, if any).

- [ ] **Step 4: Manually verify in the browser**

Start the dev server (`docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d db backend` + `cd frontend && npm run dev`, or reuse a running stack), then as an admin:
1. Open Schedule → select a group → click the settings gear.
2. Click "+ Add streak rule", enter `night` / `3` / `2`, click Save.
3. Reopen the settings modal for the same group — the rule must still be there (proves the round-trip through `PUT .../streak-rules` and back through `GET /shift-groups`).
4. Click the remove (×) button on the rule, click Save, reopen — the rule must be gone.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/views/ScheduleView.vue
git commit -m "feat: add streak-rule configuration to the group settings modal"
```

---

### Task 8: Frontend — streak status column on the Settlement Periods report

**Files:**
- Modify: `frontend/src/views/SettlementPeriodsView.vue`
- Modify: `frontend/src/views/SettlementPeriodsView.test.js`

**Interfaces:**
- Consumes: `employees[].streak_status` from `GET /api/settlement-periods` (Task 6).

- [ ] **Step 1: Write the failing test**

Append to `frontend/src/views/SettlementPeriodsView.test.js` (inside the existing `describe('SettlementPeriodsView', ...)` block, as a new `it(...)` alongside the existing two):

```js
  it('renders the streak status column for a mid-streak and a resting employee', async () => {
    server.use(
      http.get('/api/shift-groups', () =>
        HttpResponse.json([{ id: 1, name: 'Main', is_default: true }]),
      ),
      http.get('/api/settlement-periods', () =>
        HttpResponse.json({
          period: { period_number: 5, start: '2026-09-01', end: '2026-09-30' },
          weeks: [{ start: '2026-09-01', end: '2026-09-07', cap_hours: 40 }],
          employees: [
            {
              id: 1,
              name: 'Jamie Employee',
              weekly_hours: [16],
              total_hours: 16,
              over_cap_weeks: [],
              streak_status: [
                { shift_type: 'night', current_count: 2, streak_length: 3, resting_until: null },
              ],
            },
            {
              id: 2,
              name: 'Sam Resting',
              weekly_hours: [0],
              total_hours: 0,
              over_cap_weeks: [],
              streak_status: [
                { shift_type: 'night', current_count: 0, streak_length: 3, resting_until: '2026-09-05' },
              ],
            },
          ],
        }),
      ),
    );

    render(SettlementPeriodsView, {
      global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } },
    });

    await waitFor(() => expect(screen.getByText('Jamie Employee')).toBeInTheDocument());
    expect(screen.getByText('night: 2/3')).toBeInTheDocument();
    expect(screen.getByText('night: resting until 2026-09-05')).toBeInTheDocument();
  });
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && LARAVEL_BYPASS_ENV_CHECK=1 npx vitest run src/views/SettlementPeriodsView.test.js`
Expected: FAIL — no "Streak" column exists, so neither text is found.

- [ ] **Step 3: Add the column**

In `frontend/src/views/SettlementPeriodsView.vue`, update the table header and body:

```html
              <th class="px-4 py-3">Total</th>
              <th class="px-4 py-3">Streak</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="emp in report.employees" :key="emp.id" class="border-b border-white/5">
              <td class="px-4 py-3 text-white">{{ emp.name }}</td>
              <td v-for="(hours, i) in emp.weekly_hours" :key="i" class="px-4 py-3"
                :class="emp.over_cap_weeks.includes(i) ? 'text-red-400 font-semibold' : 'text-slate-300'">
                {{ hours }}<span v-if="emp.over_cap_weeks.includes(i)"> ⚠️</span>
              </td>
              <td class="px-4 py-3 text-white font-semibold">{{ emp.total_hours }}</td>
              <td class="px-4 py-3 text-slate-300 text-xs">{{ formatStreakStatus(emp.streak_status) }}</td>
            </tr>
            <tr v-if="report.employees.length === 0">
              <td :colspan="report.weeks.length + 3" class="px-4 py-6 text-center text-slate-500">No assignments in this period yet.</td>
            </tr>
```

Add a helper function in the `<script setup>` block, right after `stepPeriod()` and before `onMounted()`:

```js
function formatStreakStatus(streakStatus) {
  if (!streakStatus || streakStatus.length === 0) return '';
  return streakStatus
    .map((s) => {
      if (s.resting_until) return `${s.shift_type}: resting until ${s.resting_until}`;
      if (s.current_count > 0) return `${s.shift_type}: ${s.current_count}/${s.streak_length}`;
      return null;
    })
    .filter(Boolean)
    .join(', ');
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && LARAVEL_BYPASS_ENV_CHECK=1 npx vitest run src/views/SettlementPeriodsView.test.js`
Expected: PASS (3 tests)

- [ ] **Step 5: Run the full frontend suite to check for regressions**

Run: `cd frontend && LARAVEL_BYPASS_ENV_CHECK=1 npm run test -- --run`
Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/views/SettlementPeriodsView.vue frontend/src/views/SettlementPeriodsView.test.js
git commit -m "feat: show streak status column on the settlement periods report"
```

---

## Running tests in this environment

The host's local PHP (8.4, via Herd) is too new for this project's Pest/Symfony-console stack — running `./vendor/bin/pest` directly on the host fails with a deprecation-to-exception error unrelated to this feature. Run backend tests inside the `shift_changer_backend` container instead, which runs PHP 8.2:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d db backend
docker exec shift_changer_backend composer install --prefer-dist --no-interaction   # first time only, installs dev deps (pest etc.)
docker exec shift_changer_backend php artisan test
```

(`php artisan test` runs the full suite; `docker exec shift_changer_backend ./vendor/bin/pest tests/Feature/SomeTest.php` runs a single file once dev deps are installed.) The container needs a `shift_changer_test` Postgres database — create it once with `docker exec shift_changer_db psql -U postgres -c "CREATE DATABASE shift_changer_test"` if `artisan test` reports it missing.

Frontend tests run fine on the host: `cd frontend && LARAVEL_BYPASS_ENV_CHECK=1 npm run test -- --run`.
