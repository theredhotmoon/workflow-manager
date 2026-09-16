# On-Call Duty Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a `ShiftGroup` have manually-assigned on-call periods, let an admin/schedule_maintainer log a callback (real worked time) against one, and have `polishLaw()` and the Settlement Periods report treat a callback's hours exactly like a regular shift's for rest/cap purposes — while passive on-call time itself has zero effect.

**Architecture:** Two new tables (`on_call_assignments`, `on_call_callbacks`) behind a plain CRUD `OnCallController` — no new service class, unlike settlement periods/shift streaks, because all the labor-law-relevant logic folds directly into `polishLaw()`'s existing per-employee state walk (`$employeeState`) rather than needing its own state machine. A callback is read (never written) by `polishLaw()` and by the Settlement Periods report; neither ever creates or mutates on-call data.

**Tech Stack:** Laravel 9 / Pest 1.x (backend, `pgsql` test DB, `RefreshDatabase` applied globally via `tests/Pest.php`), Vue 3 `<script setup>` (frontend, no new component tests — matches this codebase's existing convention for admin CRUD screens).

**Spec:** [docs/superpowers/specs/2026-09-15-on-call-duty-design.md](../specs/2026-09-15-on-call-duty-design.md)

## Global Constraints

- Passive on-call time has zero effect anywhere — no blocking check, no hour accounting. Only a logged callback has any effect, and only through the mechanism below.
- A callback counts toward `weekly_hour_cap`, the max-5-working-days rule, and resets the 11h/35h rest clock **exactly like a regular shift** — same `$employeeState` fields, no parallel accounting.
- No overlap restrictions anywhere: multiple employees can be on-call in the same group at once, on-call can overlap a regular shift, and a callback's `ends_at` is not required to fall within its parent on-call assignment's window (only `starts_at` is, via app-level validation).
- `ends_at` must be strictly after `starts_at` on both `on_call_assignments` and `on_call_callbacks` (422 on violation) — app-level validation, no DB check constraint (matches the existing convention: shift-streaks' `streak_length`/`rest_days` also rely on `min:1` app validation alone).
- A callback that crosses midnight is attributed entirely to its `starts_at` date for weekly bucketing — the same convention `Shift.date` already uses for overnight shifts.
- No changes to `ScheduleController::assign()`/`unassign()` — the 11h/13h/35h/cap rules have only ever been enforced inside `polishLaw()`'s auto-assign, never on manual assignment, and on-call duty does not change that boundary.
- All new routes sit in the `admin:schedule` middleware group (`backend/routes/api.php:320-337`), matching every other schedule-mutation endpoint (admin or schedule_maintainer, not admin-only).

---

### Task 1: Data model — on-call assignments and callbacks

**Files:**
- Create: `backend/database/migrations/2026_09_15_100000_create_on_call_assignments_table.php`
- Create: `backend/database/migrations/2026_09_15_100100_create_on_call_callbacks_table.php`
- Create: `backend/app/Models/OnCallAssignment.php`
- Create: `backend/app/Models/OnCallCallback.php`
- Modify: `backend/app/Models/ShiftGroup.php`
- Test: `backend/tests/Feature/OnCallModelsTest.php`

**Interfaces:**
- Produces: `OnCallAssignment` (fillable `shift_group_id`, `user_id`, `starts_at`, `ends_at`; casts both datetimes; relations `shiftGroup()`, `user()`, `callbacks(): HasMany`), `OnCallCallback` (fillable `on_call_assignment_id`, `starts_at`, `ends_at`; casts both datetimes; relation `onCallAssignment()`), `ShiftGroup::onCallAssignments(): HasMany`. Tasks 2-4 consume all of these directly.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\ShiftGroup;
use App\Models\User;

it('creates an on-call assignment scoped to a shift group and user', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $user = User::factory()->create();

    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $user->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);

    expect($assignment->shift_group_id)->toBe($group->id);
    expect($group->onCallAssignments()->pluck('user_id')->all())->toBe([$user->id]);
});

it('creates a callback linked to its on-call assignment', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $user = User::factory()->create();
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $user->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);

    $callback = OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-15 22:00:00',
        'ends_at' => '2026-09-15 23:30:00',
    ]);

    expect($assignment->callbacks()->pluck('id')->all())->toBe([$callback->id]);
});

it('cascades callback deletion when the on-call assignment is deleted', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $user = User::factory()->create();
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $user->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);
    $callback = OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-15 22:00:00',
        'ends_at' => '2026-09-15 23:30:00',
    ]);

    $assignment->delete();

    expect(OnCallCallback::find($callback->id))->toBeNull();
});

it('cascades on-call assignment deletion when the shift group is deleted', function () {
    $group = ShiftGroup::create(['name' => 'Temp', 'sort_order' => 1]);
    $user = User::factory()->create();
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $user->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);

    $group->delete();

    expect(OnCallAssignment::find($assignment->id))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run (inside the backend container — see "Running tests" note at the end of this plan): `./vendor/bin/pest tests/Feature/OnCallModelsTest.php`
Expected: FAIL — `on_call_assignments`/`on_call_callbacks` tables and `App\Models\OnCallAssignment`/`OnCallCallback` don't exist yet.

- [ ] **Step 3: Write the migrations and models**

`backend/database/migrations/2026_09_15_100000_create_on_call_assignments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('on_call_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('on_call_assignments');
    }
};
```

`backend/database/migrations/2026_09_15_100100_create_on_call_callbacks_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('on_call_callbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('on_call_assignment_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('on_call_callbacks');
    }
};
```

`backend/app/Models/OnCallAssignment.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnCallAssignment extends Model
{
    protected $fillable = ['shift_group_id', 'user_id', 'starts_at', 'ends_at'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function shiftGroup(): BelongsTo
    {
        return $this->belongsTo(ShiftGroup::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function callbacks(): HasMany
    {
        return $this->hasMany(OnCallCallback::class);
    }
}
```

`backend/app/Models/OnCallCallback.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnCallCallback extends Model
{
    protected $fillable = ['on_call_assignment_id', 'starts_at', 'ends_at'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function onCallAssignment(): BelongsTo
    {
        return $this->belongsTo(OnCallAssignment::class);
    }
}
```

Modify `backend/app/Models/ShiftGroup.php` — add an `onCallAssignments()` relation next to the existing `streakRules()` relation:

```php
    public function streakRules()
    {
        return $this->hasMany(ShiftStreakRule::class);
    }

    public function onCallAssignments()
    {
        return $this->hasMany(OnCallAssignment::class);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/OnCallModelsTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations/2026_09_15_100000_create_on_call_assignments_table.php backend/database/migrations/2026_09_15_100100_create_on_call_callbacks_table.php backend/app/Models/OnCallAssignment.php backend/app/Models/OnCallCallback.php backend/app/Models/ShiftGroup.php backend/tests/Feature/OnCallModelsTest.php
git commit -m "feat: add on_call_assignments and on_call_callbacks schema"
```

---

### Task 2: `OnCallController` — CRUD for assignments and callbacks

**Files:**
- Create: `backend/app/Http/Controllers/OnCallController.php`
- Modify: `backend/routes/api.php:29` (import), `backend/routes/api.php:320-337` (routes, inside the `admin:schedule` group)
- Test: `backend/tests/Feature/OnCallControllerTest.php`

**Interfaces:**
- Consumes: `OnCallAssignment`, `OnCallCallback` (Task 1).
- Produces: `GET /api/on-call-assignments?shift_group_id=&month=`, `POST /api/on-call-assignments`, `DELETE /api/on-call-assignments/{onCallAssignment}`, `POST /api/on-call-assignments/{onCallAssignment}/callbacks`, `DELETE /api/on-call-callbacks/{onCallCallback}`. Task 5 (frontend) consumes all five.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\ShiftGroup;
use App\Models\User;

it('lists on-call assignments with their callbacks for a group and month', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);
    OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-15 22:00:00',
        'ends_at' => '2026-09-15 23:30:00',
    ]);

    $response = $this->actingAs($maintainer)->getJson('/api/on-call-assignments?' . http_build_query([
        'shift_group_id' => $group->id,
        'month' => '2026-09',
    ]));

    $response->assertOk();
    expect($response->json('0.user.name'))->toBe($employee->name);
    expect($response->json('0.callbacks'))->toHaveCount(1);
});

it('creates an on-call assignment', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    $response = $this->actingAs($maintainer)->postJson('/api/on-call-assignments', [
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);

    $response->assertCreated();
    expect(OnCallAssignment::where('user_id', $employee->id)->count())->toBe(1);
});

it('rejects an on-call assignment where ends_at is not after starts_at', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    $this->actingAs($maintainer)->postJson('/api/on-call-assignments', [
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-15 08:00:00',
    ])->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
});

it('deletes an on-call assignment and cascades its callbacks', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);
    $callback = OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-15 22:00:00',
        'ends_at' => '2026-09-15 23:30:00',
    ]);

    $this->actingAs($maintainer)->deleteJson("/api/on-call-assignments/{$assignment->id}")->assertOk();

    expect(OnCallAssignment::find($assignment->id))->toBeNull();
    expect(OnCallCallback::find($callback->id))->toBeNull();
});

it('records a callback that starts within the on-call window', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);

    $response = $this->actingAs($maintainer)->postJson("/api/on-call-assignments/{$assignment->id}/callbacks", [
        'starts_at' => '2026-09-15 22:00:00',
        'ends_at' => '2026-09-15 23:30:00',
    ]);

    $response->assertCreated();
    expect(OnCallCallback::where('on_call_assignment_id', $assignment->id)->count())->toBe(1);
});

it('allows a callback to extend past the on-call assignment end time', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);

    $response = $this->actingAs($maintainer)->postJson("/api/on-call-assignments/{$assignment->id}/callbacks", [
        'starts_at' => '2026-09-16 07:30:00',
        'ends_at' => '2026-09-16 09:00:00',
    ]);

    $response->assertCreated();
});

it('rejects a callback whose starts_at falls outside the on-call assignment window', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);

    $response = $this->actingAs($maintainer)->postJson("/api/on-call-assignments/{$assignment->id}/callbacks", [
        'starts_at' => '2026-09-17 01:00:00',
        'ends_at' => '2026-09-17 02:00:00',
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('must start within');
});

it('deletes a callback', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-15 08:00:00',
        'ends_at' => '2026-09-16 08:00:00',
    ]);
    $callback = OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-15 22:00:00',
        'ends_at' => '2026-09-15 23:30:00',
    ]);

    $this->actingAs($maintainer)->deleteJson("/api/on-call-callbacks/{$callback->id}")->assertOk();

    expect(OnCallCallback::find($callback->id))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/OnCallControllerTest.php`
Expected: FAIL — routes don't exist yet (404s).

- [ ] **Step 3: Write the controller**

`backend/app/Http/Controllers/OnCallController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use Carbon\Carbon;
use Illuminate\Http\Request;

class OnCallController extends Controller
{
    /**
     * List on-call assignments (with their callbacks) for a group/month.
     */
    public function index(Request $request)
    {
        $request->validate([
            'shift_group_id' => ['required', 'integer', 'exists:shift_groups,id'],
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $start = Carbon::createFromFormat('Y-m', $request->month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $assignments = OnCallAssignment::with(['user:id,name', 'callbacks'])
            ->where('shift_group_id', $request->shift_group_id)
            ->where('starts_at', '<=', $end)
            ->where('ends_at', '>=', $start)
            ->orderBy('starts_at')
            ->get();

        return response()->json($assignments);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'shift_group_id' => ['required', 'integer', 'exists:shift_groups,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $assignment = OnCallAssignment::create($validated);
        $assignment->load('user:id,name');

        return response()->json($assignment, 201);
    }

    public function destroy(OnCallAssignment $onCallAssignment)
    {
        $onCallAssignment->delete();

        return response()->json(['message' => 'On-call assignment removed.']);
    }

    public function storeCallback(Request $request, OnCallAssignment $onCallAssignment)
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $startsAt = Carbon::parse($validated['starts_at']);
        if ($startsAt->lt($onCallAssignment->starts_at) || $startsAt->gt($onCallAssignment->ends_at)) {
            return response()->json([
                'message' => 'A callback must start within its on-call assignment\'s window.',
            ], 422);
        }

        $callback = $onCallAssignment->callbacks()->create($validated);

        return response()->json($callback, 201);
    }

    public function destroyCallback(OnCallCallback $onCallCallback)
    {
        $onCallCallback->delete();

        return response()->json(['message' => 'Callback removed.']);
    }
}
```

- [ ] **Step 4: Add the routes**

In `backend/routes/api.php`, add the import right after the existing `SettlementPeriodController` import (currently line 29):

```php
use App\Http\Controllers\SettlementPeriodController;
use App\Http\Controllers\OnCallController;
```

Add the five routes inside the existing `admin:schedule` middleware group, right after the `/settlement-periods` route (currently line 336), before the group's closing `});`:

```php
        Route::get('/settlement-periods', [SettlementPeriodController::class, 'show']);
        Route::get('/on-call-assignments', [OnCallController::class, 'index']);
        Route::post('/on-call-assignments', [OnCallController::class, 'store']);
        Route::delete('/on-call-assignments/{onCallAssignment}', [OnCallController::class, 'destroy']);
        Route::post('/on-call-assignments/{onCallAssignment}/callbacks', [OnCallController::class, 'storeCallback']);
        Route::delete('/on-call-callbacks/{onCallCallback}', [OnCallController::class, 'destroyCallback']);
    });
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/OnCallControllerTest.php`
Expected: PASS (8 tests)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/OnCallController.php backend/routes/api.php backend/tests/Feature/OnCallControllerTest.php
git commit -m "feat: add on-call assignment and callback CRUD endpoints"
```

---

### Task 3: `polishLaw()` integration — callbacks feed the existing state walk

**Files:**
- Modify: `backend/app/Http/Controllers/ShiftAutoAssignController.php:573-653`
- Test: `backend/tests/Feature/PolishLawOnCallTest.php`

**Interfaces:**
- Consumes: `OnCallCallback`, `OnCallAssignment` (Task 1).

No new blocking check is added. A callback only ever adds to `$employeeState`, which the existing 11h-rest/weekly-cap/5-day/35h checks already read — see the plan's Global Constraints and the spec's `polishLaw()` integration section for the full rationale.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\User;

it('blocks a regular shift that would start less than 11h after a logged callback ends', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01', 'weekly_hour_cap' => 168,
    ]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-01 20:00:00',
        'ends_at' => '2026-09-02 08:00:00',
    ]);
    OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-02 02:00:00',
        'ends_at' => '2026-09-02 04:00:00',
    ]);

    // Only 4h of rest between the callback ending at 04:00 and this shift
    // starting at 08:00 — below the 11h minimum.
    Shift::create([
        'date' => '2026-09-02', 'shift_number' => 1, 'shift_group_id' => $group->id,
        'start_time' => '08:00', 'end_time' => '16:00',
    ]);

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
    expect($response->json('total_assigned'))->toBe(0);
});

it('assigns the same shift when there is no callback blocking rest', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01', 'weekly_hour_cap' => 168,
    ]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    Shift::create([
        'date' => '2026-09-02', 'shift_number' => 1, 'shift_group_id' => $group->id,
        'start_time' => '08:00', 'end_time' => '16:00',
    ]);

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
    expect($response->json('total_assigned'))->toBe(1);
});

it('counts a callback hours toward the settlement-anchored weekly_hour_cap, including on a date with no shift record', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01', 'weekly_hour_cap' => 10,
    ]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-01 20:00:00',
        'ends_at' => '2026-09-02 08:00:00',
    ]);
    // 6h callback, no shift exists on 2026-09-01 at all — this only lands in
    // weekKeyByDate/weekCapByDate if polishLaw() widens those maps to cover
    // callback dates, not just shift dates.
    OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-01 22:00:00',
        'ends_at' => '2026-09-02 04:00:00',
    ]);

    // A separate 6h shift 2 days later in the same anchored week (Sep 1-7).
    // 6h (callback) + 6h (shift) = 12h, over the 10h cap — the shift must be
    // blocked, proving the callback's hours were actually counted. Rest is
    // not the cause here: the callback ends 2026-09-02 04:00 and this shift
    // starts 2026-09-03 08:00, a 28h gap, well over the 11h minimum.
    Shift::create([
        'date' => '2026-09-03', 'shift_number' => 1, 'shift_group_id' => $group->id,
        'start_time' => '08:00', 'end_time' => '14:00',
    ]);

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
    expect($response->json('total_assigned'))->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PolishLawOnCallTest.php`
Expected: FAIL — the first and third tests show `total_assigned` of 1 instead of 0 (nothing blocks the shift yet); the second test already passes (it has no callback) and stays green throughout.

- [ ] **Step 3: Query callbacks and widen the date-based precompute**

In `backend/app/Http/Controllers/ShiftAutoAssignController.php`, insert a new query immediately before the existing settlement-period precompute comment (currently starting at line 573), and extend the same `try` block to also cover callback dates:

Original (lines 573-597):
```php
        // Pre-compute each distinct date's settlement-anchored week: a period-qualified
        // key (so weeks from different periods never collide), the week's hour cap,
        // and whether the date is the last day of its anchored week (used below in
        // place of the old "is it Sunday" heuristic).
        $weekKeyByDate = [];
        $weekCapByDate = [];
        $weekEndByDate = [];
        try {
            foreach ($shifts as $shift) {
                $dateStr = $shift->date->format('Y-m-d');
                if (isset($weekKeyByDate[$dateStr])) {
                    continue;
                }
                $shiftDate = $shift->date->copy()->startOfDay();
                $period = $calculator->periodContaining($group, $shiftDate);
                $match = $calculator->weekFor($period, $shiftDate);
                $weekKeyByDate[$dateStr] = "{$period['period_number']}:{$match['index']}";
                $weekCapByDate[$dateStr] = $match['week']['cap_hours'];
                $weekEndByDate[$dateStr] = $dateStr === $match['week']['end']->format('Y-m-d');
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => "Settlement period configuration error for group \"{$group->name}\": {$e->getMessage()}",
            ], 422);
        }
```

New:
```php
        // On-call callbacks for this group/month — read-only input to the
        // state walk below (see docs/superpowers/specs/2026-09-15-on-call-duty-design.md).
        // polishLaw() never creates or mutates on-call data, only reads it.
        $onCallCallbacks = \App\Models\OnCallCallback::whereHas('onCallAssignment', function ($q) use ($groupId, $start, $end) {
            $q->where('shift_group_id', $groupId)
                ->where('starts_at', '<=', $end)
                ->where('ends_at', '>=', $start)
                ->whereIn('user_id', $employees->pluck('id'));
        })
            ->with('onCallAssignment:id,user_id')
            ->orderBy('starts_at')
            ->get();

        // Pre-compute each distinct date's settlement-anchored week: a period-qualified
        // key (so weeks from different periods never collide), the week's hour cap,
        // and whether the date is the last day of its anchored week (used below in
        // place of the old "is it Sunday" heuristic).
        $weekKeyByDate = [];
        $weekCapByDate = [];
        $weekEndByDate = [];
        try {
            foreach ($shifts as $shift) {
                $dateStr = $shift->date->format('Y-m-d');
                if (isset($weekKeyByDate[$dateStr])) {
                    continue;
                }
                $shiftDate = $shift->date->copy()->startOfDay();
                $period = $calculator->periodContaining($group, $shiftDate);
                $match = $calculator->weekFor($period, $shiftDate);
                $weekKeyByDate[$dateStr] = "{$period['period_number']}:{$match['index']}";
                $weekCapByDate[$dateStr] = $match['week']['cap_hours'];
                $weekEndByDate[$dateStr] = $dateStr === $match['week']['end']->format('Y-m-d');
            }
            // A callback can land on a date with no shift record at all
            // (called in on an otherwise-scheduled day off) — a callback
            // crossing midnight is attributed entirely to its starts_at
            // date, the same convention Shift.date uses for overnight shifts.
            foreach ($onCallCallbacks as $callback) {
                $dateStr = $callback->starts_at->format('Y-m-d');
                if (isset($weekKeyByDate[$dateStr])) {
                    continue;
                }
                $callbackDate = $callback->starts_at->copy()->startOfDay();
                $period = $calculator->periodContaining($group, $callbackDate);
                $match = $calculator->weekFor($period, $callbackDate);
                $weekKeyByDate[$dateStr] = "{$period['period_number']}:{$match['index']}";
                $weekCapByDate[$dateStr] = $match['week']['cap_hours'];
                $weekEndByDate[$dateStr] = $dateStr === $match['week']['end']->format('Y-m-d');
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => "Settlement period configuration error for group \"{$group->name}\": {$e->getMessage()}",
            ], 422);
        }
```

- [ ] **Step 4: Fold callbacks into the pre-population walk**

Right after the existing shift pre-population loop closes (currently ending at line 653 with the `}` that matches `foreach ($shifts as $shift) {` at line 632), add a new loop that updates `$employeeState` from callbacks. A callback can only ever push `last_shift_end` forward, never backward — it uses `max()` semantics instead of blind overwrite, so it stays correct regardless of whether the callback falls chronologically before or after the employee's latest shift this month (the existing shift loop's blind overwrite at line 642 is safe on its own only because `$shifts` is already date-ordered; this new loop cannot assume any particular order relative to shifts, so it compares explicitly):

Original (end of the block, lines 651-653):
```php
                }
            }
        }
```

New (same closing brace, with the callback loop appended immediately after):
```php
                }
            }
        }

        // Fold on-call callbacks into the same per-employee state used for
        // shifts: a callback counts as worked time for weekly_hour_cap/5-day
        // purposes, and can only push last_shift_end forward, never
        // backward, regardless of whether it falls before or after the
        // employee's last shift this month.
        foreach ($onCallCallbacks as $callback) {
            $userId = $callback->onCallAssignment->user_id;
            if (!isset($employeeState[$userId])) {
                continue;
            }

            $dateStr = $callback->starts_at->format('Y-m-d');
            $weekNum = $weekKeyByDate[$dateStr];
            $durationHours = $callback->starts_at->diffInMinutes($callback->ends_at) / 60;

            $currentEnd = $employeeState[$userId]['last_shift_end'];
            if ($currentEnd === null || $callback->ends_at->gt($currentEnd)) {
                $employeeState[$userId]['last_shift_end'] = $callback->ends_at;
            }
            $employeeState[$userId]['total_hours'] += $durationHours;
            $employeeState[$userId]['weekly_hours'][$weekNum] = ($employeeState[$userId]['weekly_hours'][$weekNum] ?? 0) + $durationHours;
            $employeeState[$userId]['weekly_days'][$weekNum][$dateStr] = true;
        }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/PolishLawOnCallTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Run the full backend suite to check for regressions**

Run: `./vendor/bin/pest`
Expected: All tests pass, including the pre-existing `PolishLawSettlementPeriodTest.php` and `PolishLawStreakTest.php`.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/ShiftAutoAssignController.php backend/tests/Feature/PolishLawOnCallTest.php
git commit -m "feat: fold on-call callbacks into polishLaw()'s rest/cap accounting"
```

---

### Task 4: Settlement Periods reporting — include callback hours

**Files:**
- Modify: `backend/app/Http/Controllers/SettlementPeriodController.php:42-51`
- Test: `backend/tests/Feature/SettlementPeriodControllerOnCallTest.php`

**Interfaces:**
- Consumes: `OnCallCallback` (Task 1).

Since a callback counts toward `weekly_hour_cap` in `polishLaw()`'s live enforcement (Task 3), the report must include it too, or it will silently under-report anyone who had a callback.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\OnCallAssignment;
use App\Models\OnCallCallback;
use App\Models\ShiftGroup;
use App\Models\User;

it('includes callback hours in the settlement periods report', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01',
    ]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    $assignment = OnCallAssignment::create([
        'shift_group_id' => $group->id,
        'user_id' => $employee->id,
        'starts_at' => '2026-09-01 20:00:00',
        'ends_at' => '2026-09-02 08:00:00',
    ]);
    OnCallCallback::create([
        'on_call_assignment_id' => $assignment->id,
        'starts_at' => '2026-09-01 22:00:00',
        'ends_at' => '2026-09-02 01:00:00',
    ]);

    $response = $this->actingAs($maintainer)->getJson('/api/settlement-periods?' . http_build_query([
        'shift_group_id' => $group->id,
        'offset' => 0,
    ]));

    $response->assertOk();
    expect($response->json('employees.0.name'))->toBe($employee->name);
    expect($response->json('employees.0.weekly_hours.0'))->toBe(3.0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/SettlementPeriodControllerOnCallTest.php`
Expected: FAIL — `employees` is empty (no `ShiftAssignment` exists in this test, only a callback), so `employees.0.name` doesn't resolve.

- [ ] **Step 3: Add callback hours to the report**

In `backend/app/Http/Controllers/SettlementPeriodController.php`, insert a new aggregation block right after the existing per-assignment loop closes (currently ending at line 51):

Original (lines 39-51):
```php
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
```

New:
```php
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

        // On-call callback hours count toward the same weekly totals as
        // shifts (see docs/superpowers/specs/2026-09-15-on-call-duty-design.md)
        // — filtered directly on the callback's own starts_at date, so
        // weekFor() is only ever called with a date guaranteed to fall
        // inside $period, matching how the shift loop above stays safe.
        $callbacks = \App\Models\OnCallCallback::whereHas('onCallAssignment', function ($q) use ($group) {
            $q->where('shift_group_id', $group->id);
        })
            ->whereBetween('starts_at', [$period['start'], $period['end']->copy()->endOfDay()])
            ->with('onCallAssignment.user:id,name')
            ->get();

        foreach ($callbacks as $callback) {
            $match = $calculator->weekFor($period, $callback->starts_at->copy()->startOfDay());
            $durationHours = $callback->starts_at->diffInMinutes($callback->ends_at) / 60;

            $userId = $callback->onCallAssignment->user_id;
            $employeeNames[$userId] = $callback->onCallAssignment->user->name;
            $employeeWeekHours[$userId][$match['index']] =
                ($employeeWeekHours[$userId][$match['index']] ?? 0) + $durationHours;
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/SettlementPeriodControllerOnCallTest.php`
Expected: PASS (1 test)

- [ ] **Step 5: Run the full backend suite to check for regressions**

Run: `./vendor/bin/pest`
Expected: All tests pass, including the pre-existing `SettlementPeriodControllerTest.php` and `SettlementPeriodControllerStreakTest.php`.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/SettlementPeriodController.php backend/tests/Feature/SettlementPeriodControllerOnCallTest.php
git commit -m "feat: include on-call callback hours in the settlement periods report"
```

---

### Task 5: Frontend — On-Call view

**Files:**
- Create: `frontend/src/views/OnCallView.vue`
- Modify: `frontend/src/components/AppLayout.vue` (nav link)
- Modify: `frontend/src/router/index.js` (route)

**Interfaces:**
- Consumes: `GET/POST/DELETE /api/on-call-assignments`, `POST /api/on-call-assignments/{id}/callbacks`, `DELETE /api/on-call-callbacks/{id}` (Task 2), `GET /api/employees` (existing, returns `[{id, name, email}]`, accessible to any authenticated user — confirmed at `backend/routes/api.php:53-55`, inside the top-level `auth:sanctum` group, not the admin-only one), `GET /api/shift-groups` (existing).

This task has no dedicated component test — matching the existing convention in this codebase (admin CRUD screens like `ScheduleView.vue`'s settings modals carry none either).

- [ ] **Step 1: Create the view**

`frontend/src/views/OnCallView.vue`:

```vue
<template>
  <AppLayout>
    <main class="max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">On-Call Duty</h1>
        <p class="text-slate-400 mt-1">Manage on-call assignments and log callbacks</p>
      </div>

      <div class="flex items-center gap-3 mb-6">
        <select v-model.number="selectedGroupId" @change="fetchAssignments"
          class="px-4 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
          <option v-for="group in groups" :key="group.id" :value="group.id">{{ group.name }}</option>
        </select>

        <button aria-label="Previous month" @click="stepMonth(-1)"
          class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
        </button>
        <span class="text-white text-sm font-medium min-w-[140px] text-center">{{ monthLabel }}</span>
        <button aria-label="Next month" @click="stepMonth(1)"
          class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
        </button>

        <button @click="openAddModal" class="ml-auto px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white text-sm font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all">
          + Add On-Call Period
        </button>
      </div>

      <div v-if="loading" class="p-10 text-center text-slate-400">Loading…</div>

      <div v-else class="space-y-3">
        <div v-for="assignment in assignments" :key="assignment.id"
          class="bg-white/5 border border-white/10 rounded-2xl p-4">
          <div class="flex items-center justify-between mb-3">
            <div>
              <p class="text-white font-semibold">{{ assignment.user.name }}</p>
              <p class="text-slate-400 text-sm">{{ formatRange(assignment.starts_at, assignment.ends_at) }}</p>
            </div>
            <div class="flex items-center gap-2">
              <button @click="openCallbackModal(assignment)"
                class="px-3 py-1.5 text-xs font-medium rounded-lg bg-amber-500/15 text-amber-300 border border-amber-500/20 hover:bg-amber-500/25 transition-all">
                + Log Callback
              </button>
              <button @click="removeAssignment(assignment)"
                class="w-7 h-7 flex items-center justify-center text-slate-500 hover:text-red-400 transition-colors" title="Remove">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
              </button>
            </div>
          </div>

          <div v-if="assignment.callbacks.length" class="space-y-1.5 pl-3 border-l-2 border-amber-500/20">
            <div v-for="callback in assignment.callbacks" :key="callback.id"
              class="flex items-center justify-between text-sm">
              <span class="text-amber-300">📞 {{ formatRange(callback.starts_at, callback.ends_at) }}</span>
              <button @click="removeCallback(callback)"
                class="text-slate-500 hover:text-red-400 text-xs transition-colors">Remove</button>
            </div>
          </div>
          <p v-else class="text-slate-600 text-sm pl-3">No callbacks logged.</p>
        </div>

        <div v-if="assignments.length === 0" class="p-10 text-center text-slate-500">
          No on-call periods for this group in {{ monthLabel }}.
        </div>
      </div>

      <!-- Add On-Call Period Modal -->
      <Transition
        enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
        enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <div v-if="showAddModal" class="fixed inset-0 z-50 flex items-center justify-center px-4" @click.self="showAddModal = false">
          <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showAddModal = false"></div>
          <div class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 p-6">
            <h3 class="text-white font-bold text-lg mb-5">Add On-Call Period</h3>

            <div class="mb-4">
              <label class="block text-sm font-medium text-slate-300 mb-1.5">Employee</label>
              <select v-model.number="addForm.user_id"
                class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
                <option v-for="u in employees" :key="u.id" :value="u.id">{{ u.name }}</option>
              </select>
            </div>

            <div class="mb-4">
              <label class="block text-sm font-medium text-slate-300 mb-1.5">Starts</label>
              <input v-model="addForm.starts_at" type="datetime-local"
                class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
            </div>

            <div class="mb-5">
              <label class="block text-sm font-medium text-slate-300 mb-1.5">Ends</label>
              <input v-model="addForm.ends_at" type="datetime-local"
                class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
            </div>

            <div class="flex items-center justify-end gap-3">
              <button @click="showAddModal = false" class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">Cancel</button>
              <button @click="saveAssignment" :disabled="savingAssignment"
                class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed">
                Save
              </button>
            </div>
          </div>
        </div>
      </Transition>

      <!-- Log Callback Modal -->
      <Transition
        enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
        enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <div v-if="showCallbackModal" class="fixed inset-0 z-50 flex items-center justify-center px-4" @click.self="showCallbackModal = false">
          <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showCallbackModal = false"></div>
          <div class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 p-6">
            <h3 class="text-white font-bold text-lg mb-1">Log Callback</h3>
            <p class="text-slate-400 text-sm mb-5">{{ callbackTarget?.user?.name }}</p>

            <div class="mb-4">
              <label class="block text-sm font-medium text-slate-300 mb-1.5">Starts</label>
              <input v-model="callbackForm.starts_at" type="datetime-local"
                class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
            </div>

            <div class="mb-5">
              <label class="block text-sm font-medium text-slate-300 mb-1.5">Ends</label>
              <input v-model="callbackForm.ends_at" type="datetime-local"
                class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
            </div>

            <div class="flex items-center justify-end gap-3">
              <button @click="showCallbackModal = false" class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">Cancel</button>
              <button @click="saveCallback" :disabled="savingCallback"
                class="px-5 py-2 bg-amber-600 hover:bg-amber-700 text-white font-semibold rounded-xl shadow-lg shadow-amber-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed">
                Save
              </button>
            </div>
          </div>
        </div>
      </Transition>

      <AppToast />
    </main>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { confirm } from '../composables/useConfirm.js';
import { useToast } from '../composables/useToast.js';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import AppToast from '../components/AppToast.vue';

const { showToast } = useToast();

const groups = ref([]);
const employees = ref([]);
const selectedGroupId = ref(null);
const currentYear = ref(new Date().getFullYear());
const currentMonth = ref(new Date().getMonth());
const assignments = ref([]);
const loading = ref(true);

const monthKey = computed(() => `${currentYear.value}-${String(currentMonth.value + 1).padStart(2, '0')}`);
const monthLabel = computed(() => {
  const d = new Date(currentYear.value, currentMonth.value, 1);
  return d.toLocaleString('en-US', { month: 'long', year: 'numeric' });
});

function stepMonth(direction) {
  currentMonth.value += direction;
  if (currentMonth.value < 0) {
    currentMonth.value = 11;
    currentYear.value--;
  } else if (currentMonth.value > 11) {
    currentMonth.value = 0;
    currentYear.value++;
  }
  fetchAssignments();
}

function formatRange(startsAt, endsAt) {
  const fmt = (s) => new Date(s).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  return `${fmt(startsAt)} → ${fmt(endsAt)}`;
}

async function fetchGroups() {
  try {
    const res = await api.get('/api/shift-groups');
    groups.value = res.data;
    if (groups.value.length > 0) {
      selectedGroupId.value = groups.value.find((g) => g.is_default)?.id ?? groups.value[0].id;
    }
  } catch {
    groups.value = [];
  }
}

async function fetchEmployees() {
  try {
    const res = await api.get('/api/employees');
    employees.value = res.data;
  } catch {
    employees.value = [];
  }
}

async function fetchAssignments() {
  if (!selectedGroupId.value) {
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const res = await api.get('/api/on-call-assignments', {
      params: { shift_group_id: selectedGroupId.value, month: monthKey.value },
    });
    assignments.value = res.data;
  } catch {
    assignments.value = [];
  } finally {
    loading.value = false;
  }
}

const showAddModal = ref(false);
const savingAssignment = ref(false);
const addForm = ref({ user_id: null, starts_at: '', ends_at: '' });

function openAddModal() {
  addForm.value = { user_id: employees.value[0]?.id ?? null, starts_at: '', ends_at: '' };
  showAddModal.value = true;
}

async function saveAssignment() {
  savingAssignment.value = true;
  try {
    await api.post('/api/on-call-assignments', {
      shift_group_id: selectedGroupId.value,
      user_id: addForm.value.user_id,
      starts_at: addForm.value.starts_at,
      ends_at: addForm.value.ends_at,
    });
    showAddModal.value = false;
    showToast('On-call period added.');
    await fetchAssignments();
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to add on-call period.', 'error');
  } finally {
    savingAssignment.value = false;
  }
}

async function removeAssignment(assignment) {
  if (!(await confirm(`Remove ${assignment.user.name}'s on-call period?`))) return;
  try {
    await api.delete(`/api/on-call-assignments/${assignment.id}`);
    showToast('On-call period removed.');
    await fetchAssignments();
  } catch {
    showToast('Failed to remove on-call period.', 'error');
  }
}

const showCallbackModal = ref(false);
const savingCallback = ref(false);
const callbackTarget = ref(null);
const callbackForm = ref({ starts_at: '', ends_at: '' });

function openCallbackModal(assignment) {
  callbackTarget.value = assignment;
  callbackForm.value = { starts_at: '', ends_at: '' };
  showCallbackModal.value = true;
}

async function saveCallback() {
  if (!callbackTarget.value) return;
  savingCallback.value = true;
  try {
    await api.post(`/api/on-call-assignments/${callbackTarget.value.id}/callbacks`, {
      starts_at: callbackForm.value.starts_at,
      ends_at: callbackForm.value.ends_at,
    });
    showCallbackModal.value = false;
    showToast('Callback logged.');
    await fetchAssignments();
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to log callback.', 'error');
  } finally {
    savingCallback.value = false;
  }
}

async function removeCallback(callback) {
  if (!(await confirm('Remove this callback?'))) return;
  try {
    await api.delete(`/api/on-call-callbacks/${callback.id}`);
    showToast('Callback removed.');
    await fetchAssignments();
  } catch {
    showToast('Failed to remove callback.', 'error');
  }
}

onMounted(async () => {
  await fetchGroups();
  await fetchEmployees();
  await fetchAssignments();
});
</script>
```

- [ ] **Step 2: Add the navigation link**

In `frontend/src/components/AppLayout.vue`, add a new entry to the `topLinks` array (currently lines 388-397), right after the existing Settlement Periods link:

```js
    { to: '/settlement-periods', label: 'Settlement Periods', scheduleOnly: true },
    { to: '/on-call', label: 'On-Call', scheduleOnly: true },
```

- [ ] **Step 3: Add the route**

In `frontend/src/router/index.js`, add the import alphabetically (currently between `OfficeAvailabilityView` at line 15 and `ProcedureDetailView` at line 16):

```js
import OnCallView from '../views/OnCallView.vue';
```

Add the route entry right after the existing `settlement-periods` route (currently lines 162-167), before the array's closing `];`:

```js
  {
    path: '/settlement-periods',
    name: 'settlement-periods',
    component: SettlementPeriodsView,
    meta: { requiresAuth: true },
  },
  {
    path: '/on-call',
    name: 'on-call',
    component: OnCallView,
    meta: { requiresAuth: true },
  },
];
```

- [ ] **Step 4: Lint the touched files**

Run: `cd frontend && npx biome check --write src/views/OnCallView.vue src/components/AppLayout.vue src/router/index.js`
Expected: No errors (only auto-formatting, if any).

- [ ] **Step 5: Manually verify in the browser**

Start the dev server (`docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d db backend` + `cd frontend && npm run dev`, or reuse a running stack), then as an admin or schedule_maintainer:
1. Open the "On-Call" nav link, select a group.
2. Click "+ Add On-Call Period", pick an employee, set a start/end, save — it appears in the list.
3. Click "+ Log Callback" on that assignment, set a start/end within the window, save — it appears nested under the assignment.
4. Remove the callback, then remove the assignment — both disappear.
5. Navigate to the previous/next month and back — the list updates correctly for each month.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/views/OnCallView.vue frontend/src/components/AppLayout.vue frontend/src/router/index.js
git commit -m "feat: add On-Call view for managing on-call assignments and callbacks"
```

---

## Running tests in this environment

The host's local PHP is too new for this project's Pest/Symfony-console stack — run backend tests inside the `shift_changer_backend` Docker container, which runs PHP 8.2:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build db backend
docker exec shift_changer_backend composer install --prefer-dist --no-interaction   # after any container rebuild — dev deps (pest etc.) aren't baked into the image
docker exec shift_changer_backend php artisan test
```

**Important:** the container does NOT bind-mount this worktree — its code is baked in at Docker build time. A file created or edited on disk is invisible to `docker exec` until the container is rebuilt (`docker compose ... up -d --build backend`, then `composer install` again since a rebuilt container starts without dev deps). This applies to every task in this plan that creates or modifies a backend file.

Frontend tests/lint run fine on the host: `cd frontend && npx biome check src/views/OnCallView.vue`.
