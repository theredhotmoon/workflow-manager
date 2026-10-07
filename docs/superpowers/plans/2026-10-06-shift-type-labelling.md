# Shift Type Labelling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let schedule managers set `shifts.shift_type` (per slot when generating, per shift, and per slot in bulk) so Shift Streak rules actually trigger, with streak counters re-booked when an assigned shift's type changes.

**Architecture:** A `ShiftType::normalize()` helper (trim + lower-case, blank → null) is applied through model mutators and in streak-rule sync. A `ShiftTypeChanger` service owns "change type + re-book streak counters in one transaction" and is used by two new endpoints (single shift, per-slot bulk). The frontend adds a Type field to the Generate template and the shift edit modal, a `ShiftTypeBulkEditor.vue` modal, and shows the type on shift chips.

**Tech Stack:** Laravel 9 / PHP 8.2 / PostgreSQL, Pest; Vue 3 (`<script setup>`), Vite, Biome; Playwright e2e.

**Spec:** [docs/superpowers/specs/2026-10-06-shift-type-labelling-design.md](../specs/2026-10-06-shift-type-labelling-design.md)

## Global Constraints

- Normalisation: `trim`, then `mb_strtolower`; empty/whitespace-only → `null`. One helper, `App\Support\ShiftType::normalize(?string): ?string`, used by every write path.
- `shift_type` validation: `nullable`, `string`, `max:50` (shifts); streak rules keep `required|string|max:50`.
- New endpoints live in the `admin:schedule` route group in `backend/routes/api.php`, next to `/schedule/shifts/{shift}/times`.
- `ShiftAssignment` assign/unassign and `ShiftAutoAssignController::polishLaw()` are NOT modified.
- Month parsing in new code: `Carbon::createFromFormat('Y-m-d', $month.'-01')` — never `createFromFormat('Y-m', …)`, which overflows on the 29th–31st of a month.
- Pest loads all test files into one process: do not declare global helper functions whose names exist elsewhere (e.g. `makeStreakShift` is taken). Prefix new helpers `stl` (e.g. `stlShift()`), or use closures.
- Backend tests run in a one-off container with the source mounted (verified working):
  ```bash
  # from the repo root; once per machine if backend/vendor is missing:
  MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W 2>/dev/null || pwd)/backend:/app" -w /app composer:2 composer install --ignore-platform-reqs --no-interaction
  # run tests (FILTER = a test file name or test-name fragment):
  PW=$(grep '^DB_PASSWORD=' .env | cut -d= -f2-); MSYS_NO_PATHCONV=1 docker run --rm --network workflow-manager_app_network -v "$(pwd -W 2>/dev/null || pwd)/backend:/var/www/html" -w /var/www/html -e DB_HOST=db -e DB_PORT=5432 -e DB_USERNAME=postgres -e DB_PASSWORD="$PW" --entrypoint php workflow-manager_backend vendor/bin/pest --filter=FILTER
  ```
  Below this is abbreviated as **`PEST --filter=X`**. The full suite is the same command without `--filter`.
- E2E needs the running backend container to contain the new backend code: after any backend change and before running e2e, from the repo root run `docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build backend` and wait for `curl -sf http://localhost:8090/healthz`. (The entrypoint runs migrations on start.) `npm run test:e2e` resets the dev DB — expected.
- E2E conventions (from the Playwright migration): import `test`/`expect` from `frontend/e2e/fixtures/auth.js`; unique group names; reserved months — this plan uses **2028-02, 2028-03, 2028-04** (unused elsewhere); verify persistence after a reload or via the API, never by toast alone; a positive guard before any absence assertion; prove every new assertion can fail (sabotage, then restore).
- Frontend JS/Vue: Biome (`npx biome check <files>` from `frontend/`); new inputs get `id`/`for` label pairs.
- Changelog: add an entry to root `CHANGELOG.md` in the same change, in the file's current format (`## YYYY-MM-DD — <emoji> Title`, short paragraph, `### Backend` / `### Frontend` tables).

## Review Focus

1. **Case/whitespace drift between a rule's type and a shift's type** ("Night" rule vs "night " shift) — a reasonable user expects them to match. Pinned by Task 1 (mutator) and Task 2 (rule sync) tests.
2. **A type change on a shift with several assigned employees** — every one of them must be re-booked, not just the first. Pinned in Task 4 (two employees on one shift).
3. **Clearing a type (`null`) on an assigned night shift** — counters must go down, not be left stale. Pinned in Task 4.
4. **Bulk relabelling of already-assigned shifts must process days in date order**, so the 3rd night triggers rest on the 3rd date, not an arbitrary one. Pinned in Task 5.
5. **Bulk edit run on the 31st for a 30-day/February month** — `createFromFormat('Y-m')` would overflow into the next month. Pinned in Task 5 with `Carbon::setTestNow('2026-10-31')`.

---

### Task 1: `ShiftType` normaliser, model mutators, normalisation migration

**Files:**
- Create: `backend/app/Support/ShiftType.php`
- Modify: `backend/app/Models/Shift.php`, `backend/app/Models/ShiftStreakRule.php`
- Create: `backend/database/migrations/2026_10_06_120000_normalize_shift_types.php`
- Test: `backend/tests/Feature/ShiftTypeNormalizationTest.php`

**Interfaces:**
- Produces: `App\Support\ShiftType::normalize(?string $value): ?string`; `Shift` and `ShiftStreakRule` normalise `shift_type` on every assignment via an Eloquent `Attribute` mutator.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Feature/ShiftTypeNormalizationTest.php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Support\ShiftType;
use Illuminate\Support\Facades\DB;

it('normalises shift types: trims, lower-cases, blank becomes null', function () {
    expect(ShiftType::normalize(' Night '))->toBe('night');
    expect(ShiftType::normalize('ÉVENING'))->toBe('évening');
    expect(ShiftType::normalize(''))->toBeNull();
    expect(ShiftType::normalize('   '))->toBeNull();
    expect(ShiftType::normalize(null))->toBeNull();
});

it('normalises shift_type when a Shift or ShiftStreakRule is written', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $shift = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => ' Night ']);
    $rule = ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'DAY', 'streak_length' => 3, 'rest_days' => 2]);

    expect($shift->fresh()->shift_type)->toBe('night');
    expect($rule->fresh()->shift_type)->toBe('day');

    $shift->update(['shift_type' => '  ']);
    expect($shift->fresh()->shift_type)->toBeNull();
});

it('migration normalises existing shift and streak-rule types', function () {
    $groupId = DB::table('shift_groups')->insertGetId(['name' => 'Main', 'is_default' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $ruleId = DB::table('shift_streak_rules')->insertGetId(['shift_group_id' => $groupId, 'shift_type' => ' Night ', 'streak_length' => 3, 'rest_days' => 2, 'created_at' => now(), 'updated_at' => now()]);
    $nightId = DB::table('shifts')->insertGetId(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $groupId, 'shift_type' => 'NIGHT ', 'created_at' => now(), 'updated_at' => now()]);
    $blankId = DB::table('shifts')->insertGetId(['date' => '2027-03-02', 'shift_number' => 1, 'shift_group_id' => $groupId, 'shift_type' => '   ', 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_06_120000_normalize_shift_types.php'))->up();

    expect(DB::table('shift_streak_rules')->where('id', $ruleId)->value('shift_type'))->toBe('night');
    expect(DB::table('shifts')->where('id', $nightId)->value('shift_type'))->toBe('night');
    expect(DB::table('shifts')->where('id', $blankId)->value('shift_type'))->toBeNull();
});

it('migration aborts without deleting when two rules in a group collide after normalising', function () {
    $groupId = DB::table('shift_groups')->insertGetId(['name' => 'Main', 'is_default' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('shift_streak_rules')->insert([
        ['shift_group_id' => $groupId, 'shift_type' => 'Night', 'streak_length' => 3, 'rest_days' => 2, 'created_at' => now(), 'updated_at' => now()],
        ['shift_group_id' => $groupId, 'shift_type' => 'night', 'streak_length' => 4, 'rest_days' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect(fn () => (require database_path('migrations/2026_10_06_120000_normalize_shift_types.php'))->up())
        ->toThrow(RuntimeException::class, "group {$groupId}");

    expect(DB::table('shift_streak_rules')->where('shift_group_id', $groupId)->count())->toBe(2);
});
```

If `shift_groups` / `shift_streak_rules` / `shifts` have additional NOT NULL columns without defaults, add them to the raw inserts (check the create-table migrations); do not switch these inserts to Eloquent — the mutator would normalise the input and make the migration test vacuous.

- [ ] **Step 2: Run tests to verify they fail**

Run: `PEST --filter=ShiftTypeNormalizationTest`
Expected: FAIL — `Class "App\Support\ShiftType" not found` / migration file missing.

- [ ] **Step 3: Implement the helper**

```php
<?php
// backend/app/Support/ShiftType.php

namespace App\Support;

/**
 * Shift types are free-form labels (e.g. "night") that Shift Streak rules match on exactly.
 * Normalising every write keeps "Night " and "night" from silently failing to match.
 */
final class ShiftType
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_strtolower($trimmed);
    }
}
```

- [ ] **Step 4: Add the mutators**

In `backend/app/Models/Shift.php` add `use App\Support\ShiftType;` and `use Illuminate\Database\Eloquent\Casts\Attribute;`, then inside the class:

```php
    protected function shiftType(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => ShiftType::normalize($value));
    }
```

Add the identical method (and the same two `use` lines) to `backend/app/Models/ShiftStreakRule.php`.

- [ ] **Step 5: Write the migration**

```php
<?php
// backend/database/migrations/2026_10_06_120000_normalize_shift_types.php

use App\Support\ShiftType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Shift and streak-rule types are now normalised on write (trim + lower-case). Bring existing
 * rows in line. Two rules in one group that only differ by case would collide on
 * (shift_group_id, shift_type); abort instead of deleting either, so an admin can merge them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rules = DB::table('shift_streak_rules')->orderBy('id')->get(['id', 'shift_group_id', 'shift_type']);

        $seen = [];
        foreach ($rules as $rule) {
            $normalized = ShiftType::normalize($rule->shift_type);
            if ($normalized === null) {
                throw new RuntimeException("Cannot normalise streak rule {$rule->id} in group {$rule->shift_group_id}: its shift_type is blank.");
            }
            $key = "{$rule->shift_group_id}|{$normalized}";
            if (isset($seen[$key])) {
                throw new RuntimeException(
                    "Cannot normalise streak rule types: group {$rule->shift_group_id} has rules '{$seen[$key]}' and '{$rule->shift_type}' "
                    ."that both normalise to '{$normalized}'. Merge them manually, then re-run the migration."
                );
            }
            $seen[$key] = $rule->shift_type;
        }

        DB::transaction(function () use ($rules) {
            foreach ($rules as $rule) {
                $normalized = ShiftType::normalize($rule->shift_type);
                if ($normalized !== $rule->shift_type) {
                    DB::table('shift_streak_rules')->where('id', $rule->id)->update(['shift_type' => $normalized]);
                }
            }

            DB::table('shifts')->whereNotNull('shift_type')->orderBy('id')->each(function ($shift) {
                $normalized = ShiftType::normalize($shift->shift_type);
                if ($normalized !== $shift->shift_type) {
                    DB::table('shifts')->where('id', $shift->id)->update(['shift_type' => $normalized]);
                }
            });
        });
    }

    public function down(): void
    {
        // Normalisation is lossy (original casing is not kept); nothing to restore.
    }
};
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `PEST --filter=ShiftTypeNormalizationTest`
Expected: PASS (4 tests). Then the full suite (`PEST` without filter) — all existing tests still pass (existing tests write lower-case types, so the mutator is a no-op for them).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Support/ShiftType.php backend/app/Models/Shift.php backend/app/Models/ShiftStreakRule.php backend/database/migrations/2026_10_06_120000_normalize_shift_types.php backend/tests/Feature/ShiftTypeNormalizationTest.php
git commit -m "feat: normalise shift and streak-rule types (trim + lower-case)"
```

---

### Task 2: Normalise streak-rule types in `syncStreakRules`

**Files:**
- Modify: `backend/app/Http/Controllers/ShiftGroupController.php` (`syncStreakRules()`)
- Test: `backend/tests/Feature/ShiftGroupControllerStreakRulesTest.php` (append)

**Interfaces:**
- Consumes: `ShiftType::normalize()` (Task 1).

- [ ] **Step 1: Write the failing tests** (append to the existing file)

```php
it('stores streak-rule types normalised', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    $this->actingAs($admin)->putJson("/api/shift-groups/{$group->id}/streak-rules", [
        'rules' => [['shift_type' => ' Night ', 'streak_length' => 3, 'rest_days' => 2]],
    ])->assertOk();

    expect(ShiftStreakRule::where('shift_group_id', $group->id)->pluck('shift_type')->all())->toBe(['night']);
});

it('rejects two rules whose types only differ by case or whitespace', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    $this->actingAs($admin)->putJson("/api/shift-groups/{$group->id}/streak-rules", [
        'rules' => [
            ['shift_type' => 'Night', 'streak_length' => 3, 'rest_days' => 2],
            ['shift_type' => 'night ', 'streak_length' => 4, 'rest_days' => 1],
        ],
    ])->assertStatus(422);

    expect(ShiftStreakRule::where('shift_group_id', $group->id)->count())->toBe(0);
});

it('updates an existing rule when the same type is resubmitted in different case', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $rule = ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);

    $this->actingAs($admin)->putJson("/api/shift-groups/{$group->id}/streak-rules", [
        'rules' => [['shift_type' => 'NIGHT', 'streak_length' => 5, 'rest_days' => 2]],
    ])->assertOk();

    expect(ShiftStreakRule::where('shift_group_id', $group->id)->count())->toBe(1);
    expect($rule->fresh()->streak_length)->toBe(5);
});
```

The third test matters: it keeps the rule's id (and its `shift_streak_states`) instead of deleting and recreating it.

- [ ] **Step 2: Run tests to verify they fail**

Run: `PEST --filter=ShiftGroupControllerStreakRulesTest`
Expected: the duplicate-by-case test FAILS (currently both rules are accepted; the mutator then makes the second `updateOrCreate` hit the unique constraint → 500, not 422); the resubmit test FAILS (`'NIGHT'` doesn't match `'night'` in `updateOrCreate`'s lookup, so it creates a second row / collides).

- [ ] **Step 3: Implement**

In `ShiftGroupController::syncStreakRules()`, after `$request->validate([...])`, replace the duplicate check and the transaction body so both use normalised rules:

```php
        $rules = array_map(
            fn (array $rule) => [...$rule, 'shift_type' => ShiftType::normalize($rule['shift_type'])],
            $request->input('rules'),
        );

        $shiftTypes = array_column($rules, 'shift_type');
        if (count($shiftTypes) !== count(array_unique($shiftTypes))) {
            return response()->json([
                'message' => 'Each shift_type can only have one streak rule per group.',
            ], 422);
        }

        DB::transaction(function () use ($rules, $shiftGroup) {
            $keepIds = [];
            foreach ($rules as $rule) {
                $model = ShiftStreakRule::updateOrCreate(
                    ['shift_group_id' => $shiftGroup->id, 'shift_type' => $rule['shift_type']],
                    ['streak_length' => $rule['streak_length'], 'rest_days' => $rule['rest_days']],
                );
                $keepIds[] = $model->id;
            }
            $shiftGroup->streakRules()->whereNotIn('id', $keepIds)->delete();
        });
```

Add `use App\Support\ShiftType;` at the top. (`TrimStrings` + `required` already reject whitespace-only types before this point.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `PEST --filter=ShiftGroupControllerStreakRulesTest`
Expected: PASS (all existing + 3 new).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/ShiftGroupController.php backend/tests/Feature/ShiftGroupControllerStreakRulesTest.php
git commit -m "feat: normalise streak-rule types before the duplicate check"
```

---

### Task 3: Generate Month accepts `shift_type`; schedule payloads include it

**Files:**
- Modify: `backend/app/Http/Controllers/ScheduleController.php` (`generate()` validation + `firstOrCreate` defaults; `index()` `$shiftData`; `updateDayShifts()` response mapping)
- Test: `backend/tests/Feature/ShiftTypeLabellingTest.php` (create)

**Interfaces:**
- Produces: `POST /api/schedule/generate` accepts `shift_times[i].shift_type`; `GET /api/schedule` shift objects include `shift_type`; `PUT /api/schedule/day-shifts` response shift objects include `shift_type`. (`GET /api/shifts-by-date` returns full models and already includes it.)

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Feature/ShiftTypeLabellingTest.php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\User;

function stlMaintainer(): User
{
    return User::factory()->create(['role' => 'schedule_maintainer']);
}

function stlGroup(string $name = 'Main'): ShiftGroup
{
    return ShiftGroup::create(['name' => $name, 'is_default' => $name === 'Main', 'sort_order' => 0]);
}

it('generate stores each slot\'s shift_type normalised and leaves untyped slots null', function () {
    $group = stlGroup();

    $this->actingAs(stlMaintainer())->postJson('/api/schedule/generate', [
        'month' => '2027-03',
        'shift_group_id' => $group->id,
        'shifts_per_day' => 2,
        'shift_times' => [
            ['start_time' => '22:00', 'end_time' => '06:00', 'shift_type' => ' Night '],
            ['start_time' => '06:00', 'end_time' => '14:00'],
        ],
    ])->assertOk();

    expect(Shift::where('shift_group_id', $group->id)->where('shift_number', 1)->distinct()->pluck('shift_type')->all())->toBe(['night']);
    expect(Shift::where('shift_group_id', $group->id)->where('shift_number', 2)->distinct()->pluck('shift_type')->all())->toBe([null]);
});

it('generate does not change the type of a slot that already exists', function () {
    $group = stlGroup();
    $existing = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day']);

    $this->actingAs(stlMaintainer())->postJson('/api/schedule/generate', [
        'month' => '2027-03',
        'shift_group_id' => $group->id,
        'shifts_per_day' => 1,
        'shift_times' => [['start_time' => '22:00', 'end_time' => '06:00', 'shift_type' => 'night']],
    ])->assertOk();

    expect($existing->fresh()->shift_type)->toBe('day');
    expect(Shift::where('shift_group_id', $group->id)->where('date', '2027-03-02')->value('shift_type'))->toBe('night');
});

it('generate rejects a shift_type longer than 50 characters', function () {
    $group = stlGroup();

    $this->actingAs(stlMaintainer())->postJson('/api/schedule/generate', [
        'month' => '2027-03',
        'shift_group_id' => $group->id,
        'shifts_per_day' => 1,
        'shift_times' => [['start_time' => '22:00', 'end_time' => '06:00', 'shift_type' => str_repeat('x', 51)]],
    ])->assertStatus(422)->assertJsonValidationErrors(['shift_times.0.shift_type']);
});

it('includes shift_type in the schedule payload', function () {
    $group = stlGroup();
    Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);

    $response = $this->actingAs(stlMaintainer())->getJson("/api/schedule?month=2027-03&shift_group_id={$group->id}");

    $response->assertOk();
    expect($response->json('schedule.2027-03-01.0.shift_type'))->toBe('night');
});
```

If the generate endpoint's success status is not 200 in this codebase, match the existing assertion style used for it elsewhere (read `generate()`'s return).

- [ ] **Step 2: Run tests to verify they fail**

Run: `PEST --filter=ShiftTypeLabellingTest`
Expected: FAIL — generated slot 1 has `null` type; payload lacks `shift_type`; the 51-char case is accepted.

- [ ] **Step 3: Implement**

In `generate()` validation, next to the `color_label` rule:

```php
            'shift_times.*.shift_type' => ['sometimes', 'nullable', 'string', 'max:50'],
```

In `generate()`'s `Shift::firstOrCreate(...)` defaults array, next to `'color_label' => ...`:

```php
                        'shift_type' => $times['shift_type'] ?? null,
```

(The `Shift` mutator normalises it.) In `index()`'s `$shiftData` array, after `'shift_number' => $shift->shift_number,`:

```php
                'shift_type' => $shift->shift_type,
```

In `updateDayShifts()`'s response mapping (the `->map(fn ($s) => [...])` that returns `'shift_number' => $s->shift_number`), add `'shift_type' => $s->shift_type,` so day edits don't drop the type from the UI.

- [ ] **Step 4: Run tests to verify they pass**

Run: `PEST --filter=ShiftTypeLabellingTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/ScheduleController.php backend/tests/Feature/ShiftTypeLabellingTest.php
git commit -m "feat: set shift_type from the Generate Month template and expose it in schedule payloads"
```

---

### Task 4: `ShiftTypeChanger` service + `PUT /schedule/shifts/{shift}/type`

**Files:**
- Create: `backend/app/Services/ShiftTypeChanger.php`
- Modify: `backend/app/Http/Controllers/ScheduleController.php` (new `updateShiftType()`), `backend/routes/api.php`
- Test: `backend/tests/Feature/ShiftTypeLabellingTest.php` (append)

**Interfaces:**
- Consumes: `ShiftType::normalize()`; `ShiftStreakTracker::rulesForGroup()`, `loadStates()`, `recordUnassignment()`, `recordAssignment(…, advanceWatermark: true)`, `persist()` (existing, unchanged).
- Produces: `App\Services\ShiftTypeChanger::change(Illuminate\Support\Collection $shifts, ?string $rawType): array` returning `['updated' => int, 'rebooked' => int]` (`updated` = shifts whose type changed; `rebooked` = assignments re-booked). Constructor-injected `ShiftStreakTracker` (resolve the service via the container so tests can swap the tracker). Endpoint `PUT /api/schedule/shifts/{shift}/type`, body `{ shift_type: string|null }`, response `{ id, shift_type, rebooked }`.

- [ ] **Step 1: Write the failing tests** (append to `ShiftTypeLabellingTest.php`; add `use App\Models\ShiftStreakRule; use App\Models\ShiftStreakState; use App\Services\ShiftStreakTracker;` at the top)

```php
function stlNightRule(ShiftGroup $group): ShiftStreakRule
{
    return ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 3, 'rest_days' => 2]);
}

function stlEmployee(): User
{
    return User::factory()->create(['role' => 'employee', 'is_admin' => false]);
}

function stlAssign($test, User $maintainer, Shift $shift, User $employee): void
{
    $test->actingAs($maintainer)->postJson('/api/schedule/assign', ['shift_id' => $shift->id, 'user_id' => $employee->id])->assertCreated();
}

function stlState(User $employee, ShiftStreakRule $rule): ?ShiftStreakState
{
    return ShiftStreakState::where('user_id', $employee->id)->where('shift_streak_rule_id', $rule->id)->first();
}

it('updates a shift type, normalised', function () {
    $group = stlGroup();
    $shift = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id]);

    $this->actingAs(stlMaintainer())->putJson("/api/schedule/shifts/{$shift->id}/type", ['shift_type' => ' Night '])
        ->assertOk()->assertJson(['id' => $shift->id, 'shift_type' => 'night', 'rebooked' => 0]);

    expect($shift->fresh()->shift_type)->toBe('night');
});

it('rejects a type over 50 characters and non-managers', function () {
    $group = stlGroup();
    $shift = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id]);

    $this->actingAs(stlMaintainer())->putJson("/api/schedule/shifts/{$shift->id}/type", ['shift_type' => str_repeat('x', 51)])
        ->assertStatus(422);
    $this->actingAs(stlEmployee())->putJson("/api/schedule/shifts/{$shift->id}/type", ['shift_type' => 'night'])
        ->assertStatus(403);
});

it('re-books streak counters when an assigned night shift becomes a day shift', function () {
    $maintainer = stlMaintainer();
    $group = stlGroup();
    $rule = stlNightRule($group);
    $employee = stlEmployee();
    $s1 = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    $s2 = Shift::create(['date' => '2027-03-02', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    stlAssign($this, $maintainer, $s1, $employee);
    stlAssign($this, $maintainer, $s2, $employee);
    expect(stlState($employee, $rule)->current_count)->toBe(2);

    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$s2->id}/type", ['shift_type' => 'day'])
        ->assertOk()->assertJson(['rebooked' => 1]);

    expect(stlState($employee, $rule)->current_count)->toBe(1);
});

it('re-books every assigned employee and can trigger a rest window', function () {
    $maintainer = stlMaintainer();
    $group = stlGroup();
    $rule = stlNightRule($group);
    $alice = stlEmployee();
    $bob = stlEmployee();
    foreach (['2027-03-01', '2027-03-02'] as $date) {
        $night = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
        stlAssign($this, $maintainer, $night, $alice);
        stlAssign($this, $maintainer, $night, $bob);
    }
    $third = Shift::create(['date' => '2027-03-03', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day']);
    stlAssign($this, $maintainer, $third, $alice);
    stlAssign($this, $maintainer, $third, $bob);

    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$third->id}/type", ['shift_type' => 'night'])
        ->assertOk()->assertJson(['rebooked' => 2]);

    foreach ([$alice, $bob] as $employee) {
        $state = stlState($employee, $rule);
        expect($state->current_count)->toBe(0);
        expect($state->resting_until->toDateString())->toBe('2027-03-05');
    }
});

it('clearing the type of an assigned night shift decrements the counter', function () {
    $maintainer = stlMaintainer();
    $group = stlGroup();
    $rule = stlNightRule($group);
    $employee = stlEmployee();
    $night = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    stlAssign($this, $maintainer, $night, $employee);

    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$night->id}/type", ['shift_type' => null])
        ->assertOk()->assertJson(['shift_type' => null, 'rebooked' => 1]);

    expect(stlState($employee, $rule)->current_count)->toBe(0);
});

it('makes no tracker writes for an unassigned shift or an unchanged type', function () {
    $maintainer = stlMaintainer();
    $group = stlGroup();
    stlNightRule($group);
    $employee = stlEmployee();
    $unassigned = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day']);
    $assigned = Shift::create(['date' => '2027-03-02', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    stlAssign($this, $maintainer, $assigned, $employee);
    $statesBefore = ShiftStreakState::orderBy('id')->get()->toArray();

    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$unassigned->id}/type", ['shift_type' => 'night'])
        ->assertOk()->assertJson(['rebooked' => 0]);
    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$assigned->id}/type", ['shift_type' => 'NIGHT'])
        ->assertOk()->assertJson(['shift_type' => 'night', 'rebooked' => 0]);

    expect(ShiftStreakState::orderBy('id')->get()->toArray())->toBe($statesBefore);
});

it('rolls back the type change when re-booking fails', function () {
    $maintainer = stlMaintainer();
    $group = stlGroup();
    $rule = stlNightRule($group);
    $employee = stlEmployee();
    $night = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
    stlAssign($this, $maintainer, $night, $employee);

    $this->app->bind(ShiftStreakTracker::class, fn () => new class extends ShiftStreakTracker {
        public function persist(array $states): void
        {
            throw new RuntimeException('simulated persist failure');
        }
    });

    $this->actingAs($maintainer)->putJson("/api/schedule/shifts/{$night->id}/type", ['shift_type' => 'day'])
        ->assertStatus(500);

    expect($night->fresh()->shift_type)->toBe('night');
    expect(stlState($employee, $rule)->current_count)->toBe(1);
});
```

If `resting_until` is not cast to a date on `ShiftStreakState`, compare with `Carbon::parse($state->resting_until)->toDateString()` instead.

- [ ] **Step 2: Run tests to verify they fail**

Run: `PEST --filter=ShiftTypeLabellingTest`
Expected: the new tests FAIL with 404 (route not defined); Task 3's tests still pass.

- [ ] **Step 3: Implement the service**

```php
<?php
// backend/app/Services/ShiftTypeChanger.php

namespace App\Services;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Support\ShiftType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Changes shifts' types and keeps streak counters consistent: every assignment on a shift whose
 * type changes is re-booked as "unassigned from the old type, assigned to the new type" — the same
 * tracker calls manual unassign/assign make. Type update and counters commit or roll back together.
 */
class ShiftTypeChanger
{
    public function __construct(private ShiftStreakTracker $tracker)
    {
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @return array{updated: int, rebooked: int}
     */
    public function change(Collection $shifts, ?string $rawType): array
    {
        $newType = ShiftType::normalize($rawType);

        // Chronological order, so a rest window triggered by re-booking lands on the right date.
        $changed = $shifts
            ->filter(fn (Shift $shift) => $shift->shift_type !== $newType)
            ->sortBy(fn (Shift $shift) => $shift->date->toDateString().'#'.str_pad((string) $shift->shift_number, 3, '0', STR_PAD_LEFT))
            ->values();

        if ($changed->isEmpty()) {
            return ['updated' => 0, 'rebooked' => 0];
        }

        return DB::transaction(function () use ($changed, $newType) {
            $rebooked = 0;

            foreach ($changed->groupBy('shift_group_id') as $groupShifts) {
                $group = $groupShifts->first()->shiftGroup;
                $rules = $group ? $this->tracker->rulesForGroup($group) : collect();
                $assignmentsByShift = ShiftAssignment::whereIn('shift_id', $groupShifts->pluck('id'))
                    ->get(['shift_id', 'user_id'])
                    ->groupBy('shift_id');
                $userIds = $assignmentsByShift->flatten()->pluck('user_id')->unique()->values()->all();
                $states = $rules->isEmpty() || empty($userIds) ? [] : $this->tracker->loadStates($rules, $userIds);

                foreach ($groupShifts as $shift) {
                    $oldType = $shift->shift_type;
                    $shift->update(['shift_type' => $newType]);

                    $touchesRule = ($oldType !== null && $rules->has($oldType)) || ($newType !== null && $rules->has($newType));
                    if (!$touchesRule) {
                        continue;
                    }

                    foreach ($assignmentsByShift->get($shift->id, collect()) as $assignment) {
                        $userId = (int) $assignment->user_id;
                        $this->tracker->recordUnassignment($states, $rules, $userId, $oldType);
                        $this->tracker->recordAssignment($states, $rules, $userId, $newType, $shift->date, advanceWatermark: true);
                        $rebooked++;
                    }
                }

                if (!empty($states)) {
                    $this->tracker->persist($states);
                }
            }

            return ['updated' => $changed->count(), 'rebooked' => $rebooked];
        });
    }
}
```

- [ ] **Step 4: Add the controller method and route**

In `ScheduleController` (add `use App\Services\ShiftTypeChanger;`), next to `updateShiftColor()`:

```php
    /**
     * Set one shift's type, re-booking streak counters of anyone assigned to it.
     */
    public function updateShiftType(Request $request, Shift $shift, ShiftTypeChanger $changer)
    {
        $request->validate([
            'shift_type' => ['present', 'nullable', 'string', 'max:50'],
        ]);

        $result = $changer->change(collect([$shift]), $request->input('shift_type'));
        $shift->refresh();

        return response()->json([
            'id' => $shift->id,
            'shift_type' => $shift->shift_type,
            'rebooked' => $result['rebooked'],
        ]);
    }
```

In `backend/routes/api.php`, inside the `Route::middleware('admin:schedule')->group(...)` block, after the `/schedule/shifts/{shift}/color` route:

```php
        Route::put('/schedule/shifts/{shift}/type', [ScheduleController::class, 'updateShiftType']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `PEST --filter=ShiftTypeLabellingTest`
Expected: PASS (all). If the rollback test reports the exception instead of a 500 status, check the test isn't running with `withoutExceptionHandling()`; do not catch the exception in the controller.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/ShiftTypeChanger.php backend/app/Http/Controllers/ScheduleController.php backend/routes/api.php backend/tests/Feature/ShiftTypeLabellingTest.php
git commit -m "feat: edit a shift's type and re-book streak counters for its assignments"
```

---

### Task 5: Per-slot bulk endpoint `PUT /schedule/shift-types` + auto-assign end-to-end check

**Files:**
- Modify: `backend/app/Http/Controllers/ScheduleController.php` (new `updateSlotShiftTypes()`), `backend/routes/api.php`
- Test: `backend/tests/Feature/ShiftTypeLabellingTest.php` (append)

**Interfaces:**
- Consumes: `ShiftTypeChanger::change()` (Task 4).
- Produces: `PUT /api/schedule/shift-types`, body `{ shift_group_id: int, month: 'YYYY-MM', shift_number: int>=1, shift_type: string|null }`, response `{ updated: int, rebooked: int }`. Used by the frontend bulk editor (Task 7) and the e2e fixture `setShiftTypes` (Task 7).

- [ ] **Step 1: Write the failing tests** (append; add `use Illuminate\Support\Carbon;` at the top)

```php
it('bulk-sets the type for one slot in one group and month only', function () {
    $group = stlGroup();
    $other = stlGroup('Other');
    $target1 = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    $target2 = Shift::create(['date' => '2027-03-31', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    $otherSlot = Shift::create(['date' => '2027-03-01', 'shift_number' => 2, 'shift_group_id' => $group->id]);
    $otherMonth = Shift::create(['date' => '2027-04-01', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    $otherGroup = Shift::create(['date' => '2027-03-01', 'shift_number' => 1, 'shift_group_id' => $other->id]);

    $this->actingAs(stlMaintainer())->putJson('/api/schedule/shift-types', [
        'shift_group_id' => $group->id, 'month' => '2027-03', 'shift_number' => 1, 'shift_type' => 'Night',
    ])->assertOk()->assertExactJson(['updated' => 2, 'rebooked' => 0]);

    expect($target1->fresh()->shift_type)->toBe('night');
    expect($target2->fresh()->shift_type)->toBe('night');
    foreach ([$otherSlot, $otherMonth, $otherGroup] as $untouched) {
        expect($untouched->fresh()->shift_type)->toBeNull();
    }
});

it('bulk-relabels assigned shifts in date order so the rest window starts on the 3rd night', function () {
    $maintainer = stlMaintainer();
    $group = stlGroup();
    $rule = stlNightRule($group);
    $employee = stlEmployee();
    // Created out of date order on purpose.
    foreach (['2027-03-03', '2027-03-01', '2027-03-02'] as $date) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day']);
        stlAssign($this, $maintainer, $shift, $employee);
    }

    $this->actingAs($maintainer)->putJson('/api/schedule/shift-types', [
        'shift_group_id' => $group->id, 'month' => '2027-03', 'shift_number' => 1, 'shift_type' => 'night',
    ])->assertOk()->assertExactJson(['updated' => 3, 'rebooked' => 3]);

    $state = stlState($employee, $rule);
    expect($state->current_count)->toBe(0);
    expect($state->resting_until->toDateString())->toBe('2027-03-05');
});

it('bulk edit on the 31st still targets the requested month', function () {
    Carbon::setTestNow('2026-10-31 12:00:00');
    $group = stlGroup();
    $february = Shift::create(['date' => '2027-02-10', 'shift_number' => 1, 'shift_group_id' => $group->id]);
    $march = Shift::create(['date' => '2027-03-02', 'shift_number' => 1, 'shift_group_id' => $group->id]);

    $this->actingAs(stlMaintainer())->putJson('/api/schedule/shift-types', [
        'shift_group_id' => $group->id, 'month' => '2027-02', 'shift_number' => 1, 'shift_type' => 'night',
    ])->assertOk()->assertExactJson(['updated' => 1, 'rebooked' => 0]);

    expect($february->fresh()->shift_type)->toBe('night');
    expect($march->fresh()->shift_type)->toBeNull();
    Carbon::setTestNow();
});

it('bulk edit returns updated 0 for a slot with no shifts and validates input', function () {
    $group = stlGroup();
    $maintainer = stlMaintainer();

    $this->actingAs($maintainer)->putJson('/api/schedule/shift-types', [
        'shift_group_id' => $group->id, 'month' => '2027-03', 'shift_number' => 4, 'shift_type' => 'night',
    ])->assertOk()->assertExactJson(['updated' => 0, 'rebooked' => 0]);

    $this->actingAs($maintainer)->putJson('/api/schedule/shift-types', [
        'shift_group_id' => $group->id, 'month' => '2027-3', 'shift_number' => 0, 'shift_type' => 'night',
    ])->assertStatus(422)->assertJsonValidationErrors(['month', 'shift_number']);
});

it('auto-assign respects a streak once shifts are labelled through the bulk endpoint', function () {
    $maintainer = stlMaintainer();
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01', 'weekly_hour_cap' => 168,
    ]);
    stlNightRule($group);
    $employee = stlEmployee();
    foreach (['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05'] as $date) {
        Shift::create([
            'date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id,
            'start_time' => '22:00', 'end_time' => '06:00',
        ]);
    }

    $this->actingAs($maintainer)->putJson('/api/schedule/shift-types', [
        'shift_group_id' => $group->id, 'month' => '2026-09', 'shift_number' => 1, 'shift_type' => 'night',
    ])->assertOk();

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
    // 3 nights, then rest on 09-04 and 09-05 (rest_days 2). Without types, all 5 would be assigned.
    expect($response->json('total_assigned'))->toBe(3);
});
```

The last test is the end-to-end proof the original gap is closed. Its auto-assign request body copies `PolishLawStreakTest.php`'s first test; if that file's request shape has changed, copy its current shape.

- [ ] **Step 2: Run tests to verify they fail**

Run: `PEST --filter=ShiftTypeLabellingTest`
Expected: new tests FAIL with 404; the auto-assign test fails at the bulk request.

- [ ] **Step 3: Implement**

In `ScheduleController` (add `use App\Models\Shift;` if not already imported), next to `updateShiftType()`:

```php
    /**
     * Set the type of one slot (shift_number) across a group's month, re-booking streak counters.
     */
    public function updateSlotShiftTypes(Request $request, ShiftTypeChanger $changer)
    {
        $request->validate([
            'shift_group_id' => ['required', 'integer', 'exists:shift_groups,id'],
            'month' => ['required', 'date_format:Y-m'],
            'shift_number' => ['required', 'integer', 'min:1'],
            'shift_type' => ['present', 'nullable', 'string', 'max:50'],
        ]);

        // 'Y-m-d' with an explicit day: createFromFormat('Y-m') fills in today's day and overflows
        // into the next month on the 29th-31st.
        $start = Carbon::createFromFormat('Y-m-d', $request->month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth();

        $shifts = Shift::where('shift_group_id', $request->shift_group_id)
            ->where('shift_number', $request->shift_number)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();

        return response()->json($changer->change($shifts, $request->input('shift_type')));
    }
```

Route, in the same `admin:schedule` group, after the `/type` route from Task 4:

```php
        Route::put('/schedule/shift-types', [ScheduleController::class, 'updateSlotShiftTypes']);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `PEST --filter=ShiftTypeLabellingTest`, then the full suite (`PEST` without filter).
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/ScheduleController.php backend/routes/api.php backend/tests/Feature/ShiftTypeLabellingTest.php
git commit -m "feat: bulk-set a slot's shift type for a month; auto-assign now honours streaks"
```

---

### Task 6: Frontend — template Type field, shift-edit Type field, chips, suggestions (+ e2e)

**Files:**
- Modify: `frontend/src/views/ScheduleView.vue`
- Create: `frontend/e2e/schedule/shift-types.spec.js`

**Interfaces:**
- Consumes: `POST /api/schedule/generate` with `shift_times[i].shift_type` (Task 3); `GET /api/schedule` `shift_type` (Task 3); `PUT /api/schedule/shifts/{id}/type` → `{ id, shift_type, rebooked }` (Task 4).
- Produces (used by Task 7): in `ScheduleView.vue`, a computed `shiftTypeSuggestions` (array of strings) and a single `<datalist id="shift-type-suggestions">`; chips render `S{n} · {type}` as one text node when a type is set.

Before running e2e: rebuild the backend container (see Global Constraints) so Tasks 3–4 are live.

- [ ] **Step 1: Write the failing e2e tests**

```js
// frontend/e2e/schedule/shift-types.spec.js
import {
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getShiftGroupsAndShifts,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

// Months reserved for this file: 2028-02 (template), 2028-03 (single edit), 2028-04 (bulk editor).

test.describe('Shift types', () => {
  test('a type set in the Generate Month template shows on every generated chip', async ({ adminPage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: 'Types Template Team' } });

    await adminPage.goto('/schedule/2028-02');
    await adminPage.getByRole('button', { name: /Types Template Team/ }).click();
    await adminPage.getByRole('button', { name: 'Generate Month' }).click();
    const modal = adminPage.locator('div.fixed', { has: adminPage.getByText(/Generate Shifts/) });
    await expect(modal).toBeVisible();

    // Only slot 1 gets a type; the assertions below look at slot 1 alone, whatever the slot count.
    await modal.getByLabel('Shift 1 type').fill(' Night ');
    await modal.getByRole('button', { name: /^Generate / }).click();
    await expect(adminPage.getByText(/generated|created/i).first()).toBeVisible();

    const { schedule } = await getShiftGroupsAndShifts(context, '2028-02', groupId);
    const slot1Types = Object.values(schedule).map((shifts) => shifts.find((s) => s.shift_number === 1)?.shift_type);
    expect(slot1Types.length).toBe(29);
    expect(new Set(slot1Types)).toEqual(new Set(['night']));

    await adminPage.reload();
    await adminPage.getByRole('button', { name: /Types Template Team/ }).click();
    await expect(adminPage.getByText('S1 · night', { exact: true })).toHaveCount(29);
  });

  test('editing one shift\'s type persists across a reload', async ({ adminPage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: 'Types Single Team' } });
    await generateMonthShifts(context, { monthKey: '2028-03', shiftGroupId: groupId });

    await adminPage.goto('/schedule/2028-03');
    await adminPage.getByRole('button', { name: /Types Single Team/ }).click();
    const day = adminPage.locator('[data-date="2028-03-15"]');
    await expect(day.getByText('S1', { exact: true })).toBeVisible();

    // The chip's time label opens the shift edit modal (openTimeEditor); fixture shifts are 08:00–16:00.
    await day.getByTitle('Edit shift times').first().click();
    await adminPage.getByLabel('Shift type').fill('day');
    await adminPage.getByRole('button', { name: 'Save' }).click();
    await expect(adminPage.getByText('Shift updated.')).toBeVisible();

    await adminPage.reload();
    await adminPage.getByRole('button', { name: /Types Single Team/ }).click();
    await expect(day.getByText('S1 · day', { exact: true })).toBeVisible();
    await expect(adminPage.locator('[data-date="2028-03-16"]').getByText('S1', { exact: true })).toBeVisible();
  });
});
```

2028-02 has 29 days. If a locator doesn't match what `ScheduleView.vue` renders, fix the locator — keep the assertions.

- [ ] **Step 2: Run to verify they fail**

Run: `cd frontend && npx playwright test e2e/schedule/shift-types.spec.js`
Expected: FAIL — no "Shift 1 type" / "Shift type" fields.

- [ ] **Step 3: Implement in `ScheduleView.vue`**

(a) **Template defaults** — in the shift-template builder (the function returning `presets[count]` / the computed even split), give every slot `shift_type: ''`: add it to each preset object, to the `.map((p) => ({ ...p, checklists: [], color: null, color_label: '' }))` spread (`shift_type: ''`), and to the pushed object in the even-split loop. Also add `shift_type: ''` wherever a single new slot object is created for the generate modal (search for `color_label: ''` in that area).

(b) **Send it** — in `doGenerateMonth()`'s `shiftTimes` map, after `color_label: s.color_label || null,`:

```js
      shift_type: s.shift_type?.trim() || null,
```

(c) **Template input** — in the Generate modal's per-slot block, directly after the "Times + Color in one row" `<div>` and before `<!-- Checklists -->`:

```html
                    <div class="mt-2 flex items-center gap-2">
                      <label :for="`gen-shift-type-${idx}`" class="w-24 shrink-0 text-[10px] text-slate-500 uppercase tracking-wide">
                        Shift {{ idx + 1 }} type
                      </label>
                      <input :id="`gen-shift-type-${idx}`" v-model="shift.shift_type" type="text" maxlength="50"
                        list="shift-type-suggestions" placeholder="e.g. night"
                        class="flex-1 px-2 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
                    </div>
```

(d) **Suggestions** — in `<script setup>`:

```js
// Streak rules match shift types exactly, so offer the active group's rule types plus types
// already used this month; free text is still allowed.
const shiftTypeSuggestions = computed(() => {
  const group = shiftGroups.value.find((g) => g.id === activeGroupId.value);
  const fromRules = (group?.streak_rules ?? []).map((r) => r.shift_type);
  const fromMonth = Object.values(schedule.value ?? {})
    .flat()
    .map((s) => s.shift_type);
  return [...new Set([...fromRules, ...fromMonth].filter(Boolean))].sort();
});
```

and once in the template (inside the root `AppLayout` content, e.g. just before the first modal):

```html
    <datalist id="shift-type-suggestions">
      <option v-for="type in shiftTypeSuggestions" :key="type" :value="type" />
    </datalist>
```

(e) **Shift edit modal** — `openTimeEditor()`: add `shift_type: shift.shift_type || '',` to `timeForm.value`; the `timeForm` ref's initial object: add `shift_type: ''`. In the modal, after the Color Picker block and before the buttons row:

```html
          <div class="mb-4">
            <label for="shift-edit-type" class="block text-sm font-medium text-slate-300 mb-1.5">Shift type</label>
            <input id="shift-edit-type" v-model="timeForm.shift_type" type="text" maxlength="50"
              list="shift-type-suggestions" placeholder="e.g. night (used by streak rules)"
              class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
          </div>
```

In `saveShiftTimes()`, after `shift.color_label = colorRes.data.color_label;` and before closing the modal:

```js
    // Only call the type endpoint when the (normalised) value changed: it re-books streak counters.
    const newType = timeForm.value.shift_type.trim().toLowerCase() || null;
    let rebooked = 0;
    if (newType !== (shift.shift_type ?? null)) {
      const typeRes = await api.put(`/api/schedule/shifts/${shift.id}/type`, { shift_type: newType });
      shift.shift_type = typeRes.data.shift_type;
      rebooked = typeRes.data.rebooked;
    }
```

and replace `showToast('Shift updated.');` with:

```js
    showToast(
      rebooked > 0
        ? `Shift updated; streak counters adjusted for ${rebooked} employee${rebooked === 1 ? '' : 's'}.`
        : 'Shift updated.',
    );
```

(f) **Chips** — in both chip renderings (the All Groups block `S{{ shift.shift_number }}` span and the single-group span with the `activeShiftId` class), change the span content from `S{{ shift.shift_number }}` to:

```html
S{{ shift.shift_number }}<template v-if="shift.shift_type"> · {{ shift.shift_type }}</template>
```

(one text node, so `getByText('S1 · night', { exact: true })` matches; untyped chips still read exactly `S1`, which other specs rely on).

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd frontend && npx playwright test e2e/schedule/shift-types.spec.js` → 2 passed. Then sabotage each assertion once (e.g. assert `S1 · day` in test 1; skip the Save click in test 2), confirm failure, restore. Then `npx playwright test e2e/schedule` (all schedule specs still pass — chips without types are unchanged) and `npx biome check src/views/ScheduleView.vue e2e/schedule/shift-types.spec.js`.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/views/ScheduleView.vue frontend/e2e/schedule/shift-types.spec.js
git commit -m "feat: set shift types in the Generate template and shift editor; show them on chips"
```

---

### Task 7: Frontend — `ShiftTypeBulkEditor.vue` (+ fixture + e2e)

**Files:**
- Create: `frontend/src/components/ShiftTypeBulkEditor.vue`
- Modify: `frontend/src/views/ScheduleView.vue` (button, modal mount, refetch), `frontend/e2e/fixtures/api-setup.js`
- Modify: `frontend/e2e/schedule/shift-types.spec.js` (append a test)

**Interfaces:**
- Consumes: `PUT /api/schedule/shift-types` → `{ updated, rebooked }` (Task 5); `shiftTypeSuggestions` + `<datalist id="shift-type-suggestions">` (Task 6).
- Produces: component `ShiftTypeBulkEditor` with props `{ groupId: Number, monthKey: String, schedule: Object }` (suggestions come from the page-level datalist), emits `close` and `saved` with `{ updated: number, rebooked: number }`. Fixture `setShiftTypes(context, { shiftGroupId, month, shiftNumber, shiftType })` → `{ updated, rebooked }` (used by Task 8).

- [ ] **Step 1: Add the fixture** (append to `frontend/e2e/fixtures/api-setup.js`)

```js
export async function setShiftTypes(context, { shiftGroupId, month, shiftNumber, shiftType }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'put', '/api/schedule/shift-types', {
    data: { shift_group_id: shiftGroupId, month, shift_number: shiftNumber, shift_type: shiftType },
    headers,
  });
  if (!res.ok()) throw new Error(`set shift types failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}
```

- [ ] **Step 2: Write the failing e2e test** (append inside the `describe` in `shift-types.spec.js`; add `setShiftTypes` to the import)

```js
  test('the bulk editor relabels every shift in a slot for the month', async ({ adminPage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: 'Types Bulk Team' } });
    await generateMonthShifts(context, { monthKey: '2028-04', shiftGroupId: groupId });
    // One day pre-labelled differently, so the slot starts as "mixed".
    await setShiftTypes(context, { shiftGroupId: groupId, month: '2028-04', shiftNumber: 1, shiftType: 'night' });
    const { schedule: before } = await getShiftGroupsAndShifts(context, '2028-04', groupId);
    const firstShift = before['2028-04-01'][0];
    const headers = { 'X-XSRF-TOKEN': decodeURIComponent((await context.cookies()).find((c) => c.name === 'XSRF-TOKEN').value) };
    await context.request.put(`/api/schedule/shifts/${firstShift.id}/type`, { data: { shift_type: 'day' }, headers });

    await adminPage.goto('/schedule/2028-04');
    await adminPage.getByRole('button', { name: /Types Bulk Team/ }).click();
    await adminPage.getByRole('button', { name: 'Set shift types' }).click();
    const dialog = adminPage.getByRole('dialog', { name: 'Set shift types' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('Slot 1 type')).toHaveAttribute('placeholder', 'mixed');

    await dialog.getByLabel('Slot 1 type').fill('Evening');
    await dialog.getByRole('button', { name: 'Save' }).click();
    await expect(adminPage.getByText(/30 shifts? updated/)).toBeVisible();

    const { schedule: after } = await getShiftGroupsAndShifts(context, '2028-04', groupId);
    const types = Object.values(after).map((shifts) => shifts.find((s) => s.shift_number === 1)?.shift_type);
    expect(types.length).toBe(30);
    expect(new Set(types)).toEqual(new Set(['evening']));

    await adminPage.reload();
    await adminPage.getByRole('button', { name: /Types Bulk Team/ }).click();
    await expect(adminPage.getByText('S1 · evening', { exact: true })).toHaveCount(30);
  });
```

If `getXsrfHeader` is exported from `fixtures/auth.js`, use it instead of the inline cookie read.

- [ ] **Step 3: Run to verify it fails**

Run: `cd frontend && npx playwright test e2e/schedule/shift-types.spec.js -g "bulk editor"`
Expected: FAIL — no "Set shift types" button.

- [ ] **Step 4: Implement the component**

```vue
<!-- frontend/src/components/ShiftTypeBulkEditor.vue -->
<template>
  <div class="fixed inset-0 z-[90] flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="emit('close')">
    <div role="dialog" aria-modal="true" aria-labelledby="shift-type-bulk-title"
      class="w-full max-w-md bg-slate-900 border border-white/10 rounded-2xl p-6 shadow-2xl">
      <h2 id="shift-type-bulk-title" class="text-lg font-semibold text-white mb-1">Set shift types</h2>
      <p class="text-xs text-slate-400 mb-4">Applies to every shift in the slot for this month. Streak counters of assigned employees are adjusted.</p>

      <p v-if="rows.length === 0" class="text-sm text-slate-400">No shifts in this month yet.</p>
      <div v-for="row in rows" :key="row.shiftNumber" class="flex items-center gap-3 mb-2">
        <label :for="`bulk-shift-type-${row.shiftNumber}`" class="w-24 shrink-0 text-sm text-slate-300">Slot {{ row.shiftNumber }} type</label>
        <input :id="`bulk-shift-type-${row.shiftNumber}`" v-model="row.value" type="text" maxlength="50"
          list="shift-type-suggestions" :placeholder="row.mixed ? 'mixed' : 'none'" @input="row.dirty = true"
          class="flex-1 px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
      </div>

      <div class="flex justify-end gap-3 mt-5">
        <button type="button" class="px-4 py-2 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl" @click="emit('close')">Cancel</button>
        <button type="button" :disabled="saving" class="px-5 py-2 text-sm font-semibold text-white bg-violet-600 hover:bg-violet-700 rounded-xl disabled:opacity-50" @click="save">Save</button>
      </div>
      <p v-if="error" class="mt-3 text-sm text-red-400">{{ error }}</p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import api from '../axios.js';

const props = defineProps({
  groupId: { type: Number, required: true },
  monthKey: { type: String, required: true },
  schedule: { type: Object, required: true },
});
const emit = defineEmits(['close', 'saved']);

// One row per slot number present this month; a slot whose days disagree starts empty as "mixed".
function buildRows() {
  const typesBySlot = new Map();
  for (const shifts of Object.values(props.schedule)) {
    for (const shift of shifts) {
      if (!typesBySlot.has(shift.shift_number)) typesBySlot.set(shift.shift_number, new Set());
      typesBySlot.get(shift.shift_number).add(shift.shift_type ?? null);
    }
  }
  return [...typesBySlot.entries()]
    .sort(([a], [b]) => a - b)
    .map(([shiftNumber, types]) => {
      const mixed = types.size > 1;
      return { shiftNumber, mixed, value: mixed ? '' : ([...types][0] ?? ''), dirty: false };
    });
}

const rows = ref(buildRows());
const saving = ref(false);
const error = ref('');

async function save() {
  saving.value = true;
  error.value = '';
  let updated = 0;
  let rebooked = 0;
  try {
    for (const row of rows.value.filter((r) => r.dirty)) {
      const res = await api.put('/api/schedule/shift-types', {
        shift_group_id: props.groupId,
        month: props.monthKey,
        shift_number: row.shiftNumber,
        shift_type: row.value.trim() || null,
      });
      updated += res.data.updated;
      rebooked += res.data.rebooked;
    }
    emit('saved', { updated, rebooked });
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to update shift types.';
  } finally {
    saving.value = false;
  }
}
</script>
```

The `suggestions` prop is not needed: the inputs use the page-level `<datalist id="shift-type-suggestions">` from Task 6.

- [ ] **Step 5: Mount it in `ScheduleView.vue`**

Import: `import ShiftTypeBulkEditor from '../components/ShiftTypeBulkEditor.vue';`. State: `const showShiftTypeEditor = ref(false);`. Button, directly after the Generate Month button (same visibility):

```html
          <button v-if="canManageSchedule && !isAllGroupsMode" @click="showShiftTypeEditor = true"
            class="px-4 py-2.5 bg-white/5 hover:bg-white/10 text-slate-200 border border-white/10 font-semibold rounded-xl transition-all duration-200 text-sm">
            Set shift types
          </button>
```

Mount next to the other modals:

```html
    <ShiftTypeBulkEditor v-if="showShiftTypeEditor" :group-id="activeGroupId" :month-key="monthKey" :schedule="schedule"
      @close="showShiftTypeEditor = false" @saved="onShiftTypesSaved" />
```

Handler:

```js
async function onShiftTypesSaved({ updated, rebooked }) {
  showShiftTypeEditor.value = false;
  const counters = rebooked > 0 ? `; streak counters adjusted for ${rebooked} assignment${rebooked === 1 ? '' : 's'}` : '';
  showToast(`${updated} shift${updated === 1 ? '' : 's'} updated${counters}.`);
  await fetchSchedule();
}
```

If `schedule` holds other groups' data in single-group mode, filter to the active group before passing it (check `fetchSchedule()`); the endpoint is group-scoped either way.

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd frontend && npx playwright test e2e/schedule/shift-types.spec.js` → 3 passed. Sabotage once (fill nothing / skip Save) to confirm the API assertion fails, restore. `npx biome check src/components/ShiftTypeBulkEditor.vue src/views/ScheduleView.vue e2e/fixtures/api-setup.js e2e/schedule/shift-types.spec.js`.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/components/ShiftTypeBulkEditor.vue frontend/src/views/ScheduleView.vue frontend/e2e/fixtures/api-setup.js frontend/e2e/schedule/shift-types.spec.js
git commit -m "feat: bulk-set shift types per slot for a month"
```

---

### Task 8: Upgrade the settlement-report streak e2e test; CHANGELOG; full verification

**Files:**
- Modify: `frontend/e2e/settlement-periods/report.spec.js` (the "renders the streak status column…" test)
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: `setShiftTypes` fixture (Task 7); the report spec's existing `getPeriod()` and `openReportFor()` helpers.

- [ ] **Step 1: Rewrite the streak test**

Read `SettlementPeriodsView.vue`'s `formatStreakStatus()` and the settlement-periods controller's `streak_status` serialisation to get the exact `resting_until` string format, then replace the test body:

```js
  test('renders mid-streak and resting streak status for an employee on night shifts', async ({ adminPage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Streak Report Team' },
      rule: { shift_type: 'night', streak_length: 3, rest_days: 2 },
    });
    const current = await getPeriod(context, groupId, 0);

    // Three consecutive days from the period start; they may cross a month boundary.
    const days = [0, 1, 2].map((offset) => {
      const d = new Date(`${current.start}T12:00:00Z`);
      d.setUTCDate(d.getUTCDate() + offset);
      return d.toISOString().slice(0, 10);
    });
    const months = [...new Set(days.map((d) => d.slice(0, 7)))];
    const shiftByDay = {};
    for (const month of months) {
      await generateMonthShifts(context, { monthKey: month, shiftGroupId: groupId });
      await setShiftTypes(context, { shiftGroupId: groupId, month, shiftNumber: 1, shiftType: 'night' });
      const { schedule } = await getShiftGroupsAndShifts(context, month, groupId);
      for (const day of days) if (schedule[day]) shiftByDay[day] = schedule[day][0];
    }
    const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');

    await assignShift(context, { shiftId: shiftByDay[days[0]].id, userId: employeeId });
    await assignShift(context, { shiftId: shiftByDay[days[1]].id, userId: employeeId });
    await openReportFor(adminPage, 'Streak Report Team', groupId);
    const row = adminPage.getByRole('row', { name: /Regular Employee/ });
    await expect(row).toBeVisible();
    await expect(row.getByRole('cell').last()).toHaveText('night: 2/3');

    await assignShift(context, { shiftId: shiftByDay[days[2]].id, userId: employeeId });
    const restEnd = new Date(`${days[2]}T12:00:00Z`);
    restEnd.setUTCDate(restEnd.getUTCDate() + 2); // rest_days
    const restingUntil = restEnd.toISOString().slice(0, 10); // match formatStreakStatus()'s actual format
    await openReportFor(adminPage, 'Streak Report Team', groupId);
    await expect(row.getByRole('cell').last()).toHaveText(`night: resting until ${restingUntil}`);
  });
```

Add `setShiftTypes` to the file's fixture import. Remove the old comment saying `night: 2/3` is unreachable.

- [ ] **Step 2: Run and prove it can fail**

Run: `cd frontend && npx playwright test e2e/settlement-periods/report.spec.js` → all pass. Sabotage: comment out the `setShiftTypes` call → the `night: 2/3` assertion must fail (this is exactly the original gap); restore.

- [ ] **Step 3: CHANGELOG**

Add at the top of `CHANGELOG.md` (after the header and `---`), in the file's current format:

```markdown
## 2026-10-07 — 🏷️ Shift Type Labelling

Shift Streaks can now actually trigger: shifts get a type (e.g. `night`), set per slot when generating a month, per shift, or per slot in bulk. Previously nothing wrote `shifts.shift_type`, so streak rules could be configured but never matched.

### Backend
| What | Details |
|------|---------|
| New | `App\Support\ShiftType::normalize()` (trim + lower-case, blank → null), applied via `Shift`/`ShiftStreakRule` mutators and in streak-rule sync (case-only duplicates now rejected); migration normalises existing values and aborts (never deletes) on rule collisions |
| New | `PUT /schedule/shifts/{shift}/type` and `PUT /schedule/shift-types` (per slot, per month), via `ShiftTypeChanger`: changing an assigned shift's type re-books each assignment's streak counters (old type out, new type in) in one transaction |
| Updated | `POST /schedule/generate` accepts `shift_times[i].shift_type`; schedule payloads include `shift_type` |

### Frontend
| View | Change |
|------|--------|
| **Schedule** | Type field per slot in the Generate Month template and in the shift edit modal (suggestions from the group's streak rules); new **Set shift types** bulk editor; chips show `S1 · night` |
| **Tests** | New `e2e/schedule/shift-types.spec.js`; the settlement report streak test now asserts real `night: 2/3` and `night: resting until …` states |

---
```

- [ ] **Step 4: Full verification**

- Backend: `PEST` (full suite) → all pass.
- Rebuild the backend container (Global Constraints), then from `frontend/`: `npm run test` and `npm run test:e2e` → all green; run e2e twice back-to-back.
- `npx biome check` on every changed frontend file.

- [ ] **Step 5: Commit**

```bash
git add frontend/e2e/settlement-periods/report.spec.js CHANGELOG.md
git commit -m "test(e2e): assert real streak states now that shifts can be typed; changelog"
```
