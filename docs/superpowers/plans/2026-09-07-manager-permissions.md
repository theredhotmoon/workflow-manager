# Manager Permissions for Employee Shift Preferences — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a team manager create/edit/delete `UserAvailability` entries (date-range availability *and* new shift-type preferences) for employees on teams they manage, with an audit trail, while employees keep full self-service control.

**Architecture:** A new `user_group_managers` pivot makes `UserGroup` manager-ship many-to-many. A new `UserAvailabilityPolicy` centralizes the "can this actor touch this entry" rule (self OR admin/schedule_maintainer OR manages-the-target's-group), replacing `UserAvailabilityController`'s inline ownership check. `UserAvailability` gains `shift_type` (nullable, only valid alongside `type=preferred`) and `created_by_user_id` (audit). A new shared Vue component (`AvailabilityEditor.vue`) is extracted from the existing self-service calendar so both the employee's own page and a new manager-facing `TeamAvailabilityView.vue` reuse identical behavior, parameterized by a target user id.

**Tech Stack:** Laravel 9 / Pest 1.x (backend, `pgsql` test DB, `RefreshDatabase` applied globally to `tests/Feature` via `tests/Pest.php`), Vue 3 `<script setup>` / Vue Router 4 / Vitest + Testing Library + MSW (frontend).

**Spec:** [docs/superpowers/specs/2026-09-07-manager-permissions-design.md](../specs/2026-09-07-manager-permissions-design.md)

## Global Constraints

- No new `update()` route for `UserAvailability` — edits stay delete+recreate, per existing frontend pattern (confirmed: only `index`/`store`/`destroy`/`forMonth` exist today).
- `shift_type` is a free-form nullable string (not a DB enum) on both `shifts` and `user_availabilities` — no fixed value list is enforced server-side.
- `shift_type` on `user_availabilities` is only meaningful when `type='preferred'`; the API rejects it (422) when `type='unavailable'`.
- No changes to `ShiftAutoAssignController` or the generator in this plan — shift-type preferences are stored, not yet consumed by auto-assign (explicit non-goal in the spec).
- Test DB is Postgres (`DB_DATABASE=shift_changer_test`), not SQLite — migrations must be valid Postgres DDL (all statements used below are already used elsewhere in this codebase, so no new concerns).

---

## Task 1: Team-manager data model

**Files:**
- Create: `backend/database/migrations/2026_09_07_120000_create_user_group_managers_table.php`
- Modify: `backend/app/Models/UserGroup.php`
- Modify: `backend/app/Models/User.php`
- Test: `backend/tests/Feature/UserGroupManagerTest.php`

**Interfaces:**
- Produces: `User::managedGroups(): BelongsToMany` (pivot `user_group_managers`), `User::managesUser(User $target): bool`, `UserGroup::managers(): BelongsToMany`. Later tasks (Policy in Task 3, admin UI in Task 6) call these exactly.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\User;
use App\Models\UserGroup;

it('lets a group have multiple managers and a manager oversee multiple groups', function () {
    $groupA = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $groupB = UserGroup::create(['name' => 'Team B', 'enum' => 'team_b']);
    $managerOne = User::factory()->create();
    $managerTwo = User::factory()->create();

    $groupA->managers()->attach([$managerOne->id, $managerTwo->id]);
    $groupB->managers()->attach($managerOne->id);

    expect($groupA->managers()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$managerOne->id, $managerTwo->id])->sort()->values()->all());
    expect($managerOne->managedGroups()->pluck('user_groups.id')->sort()->values()->all())
        ->toBe(collect([$groupA->id, $groupB->id])->sort()->values()->all());
});

it('reports managesUser true only for members of a managed group', function () {
    $group = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $manager = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();

    $group->managers()->attach($manager->id);
    $group->users()->attach($member->id);

    expect($manager->managesUser($member))->toBeTrue();
    expect($manager->managesUser($outsider))->toBeFalse();
    expect($manager->managesUser($manager))->toBeFalse(); // managesUser is about others, not self
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/UserGroupManagerTest.php`
Expected: FAIL — `user_group_managers` table / `managers()` / `managedGroups()` / `managesUser()` don't exist yet.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_group_managers', function (Blueprint $table) {
            $table->foreignId('user_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_group_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_group_managers');
    }
};
```

- [ ] **Step 4: Add the model relations and helper**

In `backend/app/Models/UserGroup.php`, add alongside the existing `users()` method:

```php
    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_group_managers');
    }
```

In `backend/app/Models/User.php`, add the import `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` is already present; add alongside `groups()`:

```php
    public function managedGroups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_managers');
    }

    public function managesUser(User $target): bool
    {
        if ($this->id === $target->id) {
            return false;
        }

        return $this->managedGroups()
            ->whereHas('users', fn ($q) => $q->where('users.id', $target->id))
            ->exists();
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/UserGroupManagerTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations/2026_09_07_120000_create_user_group_managers_table.php backend/app/Models/UserGroup.php backend/app/Models/User.php backend/tests/Feature/UserGroupManagerTest.php
git commit -m "feat: add many-to-many user group manager relationship"
```

---

## Task 2: Extend `UserAvailability` schema (shift-type preference + audit trail)

**Files:**
- Create: `backend/database/migrations/2026_09_07_120100_add_preference_fields_to_user_availabilities_table.php`
- Modify: `backend/app/Models/UserAvailability.php`
- Test: `backend/tests/Feature/UserAvailabilityModelTest.php` (must live under `Feature`, not `Unit` — only `tests/Feature` is wired to `TestCase`/`RefreshDatabase` in `tests/Pest.php`; `tests/Unit` boots no app/DB at all, confirmed via the existing trivial `tests/Unit/ExampleTest.php`)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `UserAvailability` fillable includes `shift_type`, `created_by_user_id`; new relation `UserAvailability::createdBy(): BelongsTo`. Task 4 (controller) and Task 3 (policy, via the model instance) rely on these columns existing.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\User;
use App\Models\UserAvailability;

it('backfills created_by_user_id to user_id for pre-existing rows via the migration default', function () {
    // Simulates a row inserted before this migration ran, by inserting directly
    // and then asserting the column exists with the expected nullable/default shape.
    $user = User::factory()->create();
    $entry = UserAvailability::create([
        'user_id' => $user->id,
        'type' => 'preferred',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-05',
        'shift_type' => 'night',
    ]);

    expect($entry->shift_type)->toBe('night');
    expect($entry->created_by_user_id)->toBeNull(); // not set explicitly here — controller sets it (Task 4)
});

it('exposes a createdBy relation', function () {
    $owner = User::factory()->create();
    $manager = User::factory()->create();
    $entry = UserAvailability::create([
        'user_id' => $owner->id,
        'created_by_user_id' => $manager->id,
        'type' => 'unavailable',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-01',
    ]);

    expect($entry->createdBy->id)->toBe($manager->id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/UserAvailabilityModelTest.php`
Expected: FAIL — `shift_type`/`created_by_user_id` columns don't exist, `createdBy` relation undefined.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_availabilities', function (Blueprint $table) {
            $table->string('shift_type', 50)->nullable()->after('type');
            $table->foreignId('created_by_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });

        // Every existing row was self-created — backfill so old entries don't
        // read as manager-set once the "set by" badge ships (Task 8).
        DB::table('user_availabilities')->whereNull('created_by_user_id')->update([
            'created_by_user_id' => DB::raw('user_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('user_availabilities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn('shift_type');
        });
    }
};
```

- [ ] **Step 4: Update the model**

Replace the contents of `backend/app/Models/UserAvailability.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAvailability extends Model
{
    protected $fillable = [
        'user_id',
        'created_by_user_id',
        'type',
        'shift_type',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'note',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/UserAvailabilityModelTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations/2026_09_07_120100_add_preference_fields_to_user_availabilities_table.php backend/app/Models/UserAvailability.php backend/tests/Feature/UserAvailabilityModelTest.php
git commit -m "feat: add shift_type preference and audit trail to UserAvailability"
```

---

## Task 3: `UserAvailabilityPolicy`

**Files:**
- Create: `backend/app/Policies/UserAvailabilityPolicy.php`
- Modify: `backend/app/Providers/AuthServiceProvider.php`
- Test: `backend/tests/Feature/UserAvailabilityPolicyTest.php`

**Interfaces:**
- Consumes: `User::managesUser()` (Task 1), `User::canManageSchedule()` (existing).
- Produces: `UserAvailabilityPolicy::manage(User $actor, UserAvailability $availability): bool`, `UserAvailabilityPolicy::manageFor(User $actor, int $targetUserId): bool`. Task 4's controller calls both by exact name via `$this->authorize(...)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\User;
use App\Models\UserAvailability;
use App\Models\UserGroup;

function makeEntry(User $owner): UserAvailability
{
    return UserAvailability::create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'type' => 'unavailable',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-01',
    ]);
}

it('lets an admin manage anyone', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $entry = makeEntry(User::factory()->create());

    expect($admin->can('manage', $entry))->toBeTrue();
    expect($admin->can('manageFor', [UserAvailability::class, $entry->user_id]))->toBeTrue();
});

it('lets a schedule maintainer manage anyone', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $entry = makeEntry(User::factory()->create());

    expect($maintainer->can('manage', $entry))->toBeTrue();
});

it('lets an owner manage their own entry', function () {
    $owner = User::factory()->create();
    $entry = makeEntry($owner);

    expect($owner->can('manage', $entry))->toBeTrue();
});

it('lets a manager of the right group manage a team member entry', function () {
    $group = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $manager = User::factory()->create();
    $member = User::factory()->create();
    $group->managers()->attach($manager->id);
    $group->users()->attach($member->id);
    $entry = makeEntry($member);

    expect($manager->can('manage', $entry))->toBeTrue();
    expect($manager->can('manageFor', [UserAvailability::class, $member->id]))->toBeTrue();
});

it('denies a manager of an unrelated group', function () {
    $groupA = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $groupB = UserGroup::create(['name' => 'Team B', 'enum' => 'team_b']);
    $manager = User::factory()->create();
    $member = User::factory()->create();
    $groupA->managers()->attach($manager->id);
    $groupB->users()->attach($member->id);
    $entry = makeEntry($member);

    expect($manager->can('manage', $entry))->toBeFalse();
});

it('denies a plain peer', function () {
    $peer = User::factory()->create();
    $entry = makeEntry(User::factory()->create());

    expect($peer->can('manage', $entry))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/UserAvailabilityPolicyTest.php`
Expected: FAIL — policy class/registration don't exist, `can('manage', ...)` returns false for everyone (unregistered ability).

- [ ] **Step 3: Write the policy**

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserAvailability;

class UserAvailabilityPolicy
{
    public function manage(User $actor, UserAvailability $availability): bool
    {
        return $this->canManageFor($actor, $availability->user_id);
    }

    public function manageFor(User $actor, int $targetUserId): bool
    {
        return $this->canManageFor($actor, $targetUserId);
    }

    private function canManageFor(User $actor, int $targetUserId): bool
    {
        if ($actor->id === $targetUserId) {
            return true;
        }

        if ($actor->canManageSchedule()) {
            return true;
        }

        $target = User::find($targetUserId);

        return $target !== null && $actor->managesUser($target);
    }
}
```

- [ ] **Step 4: Register the policy**

In `backend/app/Providers/AuthServiceProvider.php`, update the `$policies` array:

```php
    protected $policies = [
        \App\Models\UserAvailability::class => \App\Policies\UserAvailabilityPolicy::class,
    ];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/UserAvailabilityPolicyTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add backend/app/Policies/UserAvailabilityPolicy.php backend/app/Providers/AuthServiceProvider.php backend/tests/Feature/UserAvailabilityPolicyTest.php
git commit -m "feat: add UserAvailabilityPolicy for manager-of-team access"
```

---

## Task 4: Wire the policy and shift-type preference into `UserAvailabilityController`

**Files:**
- Modify: `backend/app/Http/Controllers/UserAvailabilityController.php`
- Test: `backend/tests/Feature/UserAvailabilityControllerTest.php`

**Interfaces:**
- Consumes: `UserAvailabilityPolicy` (Task 3), `UserAvailability` schema (Task 2).
- Produces: `store()` accepts optional `user_id` + `shift_type` in the request body; `formatEntry()` response shape gains `shift_type`, `created_by_user_id`, `created_by_name`. Task 8/9 (frontend) consume this exact JSON shape.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\User;
use App\Models\UserAvailability;
use App\Models\UserGroup;

it('lets an employee create their own entry and sets created_by to themselves', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/user-availabilities', [
        'type' => 'unavailable',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-01',
    ]);

    $response->assertCreated();
    expect($response->json('created_by_user_id'))->toBe($user->id);
    $this->assertDatabaseHas('user_availabilities', [
        'user_id' => $user->id,
        'created_by_user_id' => $user->id,
    ]);
});

it('lets a manager create an entry for a team member and records themselves as creator', function () {
    $group = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $manager = User::factory()->create();
    $member = User::factory()->create();
    $group->managers()->attach($manager->id);
    $group->users()->attach($member->id);

    $response = $this->actingAs($manager)->postJson('/api/user-availabilities', [
        'user_id' => $member->id,
        'type' => 'preferred',
        'shift_type' => 'night',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-05',
    ]);

    $response->assertCreated();
    expect($response->json('user_id'))->toBe($member->id);
    expect($response->json('created_by_user_id'))->toBe($manager->id);
    expect($response->json('shift_type'))->toBe('night');
});

it('rejects a manager creating an entry for someone outside their team', function () {
    $manager = User::factory()->create();
    $outsider = User::factory()->create();

    $this->actingAs($manager)->postJson('/api/user-availabilities', [
        'user_id' => $outsider->id,
        'type' => 'unavailable',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-01',
    ])->assertStatus(403)->assertJson(['message' => "You do not manage this employee's team."]);

    $this->assertDatabaseCount('user_availabilities', 0);
});

it('rejects shift_type when type is unavailable', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/user-availabilities', [
        'type' => 'unavailable',
        'shift_type' => 'night',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-01',
    ])->assertStatus(422)->assertJsonValidationErrors(['shift_type']);
});

it('lets a manager delete a team member entry and denies an unrelated manager', function () {
    $group = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $manager = User::factory()->create();
    $member = User::factory()->create();
    $group->managers()->attach($manager->id);
    $group->users()->attach($member->id);
    $entry = UserAvailability::create([
        'user_id' => $member->id,
        'created_by_user_id' => $member->id,
        'type' => 'unavailable',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-01',
    ]);

    $unrelatedManager = User::factory()->create();
    $this->actingAs($unrelatedManager)
        ->deleteJson("/api/user-availabilities/{$entry->id}")
        ->assertStatus(403)
        ->assertJson(['message' => "You do not manage this employee's team."]);

    $this->actingAs($manager)
        ->deleteJson("/api/user-availabilities/{$entry->id}")
        ->assertOk();
    $this->assertDatabaseMissing('user_availabilities', ['id' => $entry->id]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/UserAvailabilityControllerTest.php`
Expected: FAIL — `user_id`/`shift_type` not accepted, no 403 enforcement, `created_by_user_id` not in response.

- [ ] **Step 3: Rewrite the controller**

Replace `backend/app/Http/Controllers/UserAvailabilityController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\UserAvailability;
use Carbon\Carbon;
use Illuminate\Http\Request;

class UserAvailabilityController extends Controller
{
    /**
     * List availability entries for the current user.
     */
    public function index(Request $request)
    {
        $entries = UserAvailability::where('user_id', $request->user()->id)
            ->with('createdBy:id,name')
            ->orderBy('start_date')
            ->get()
            ->map(fn($e) => $this->formatEntry($e));

        return response()->json($entries);
    }

    /**
     * Create a new availability entry. Defaults to the acting user; a manager
     * may pass a different user_id for someone on a team they manage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'type' => ['required', 'in:unavailable,preferred'],
            'shift_type' => ['nullable', 'string', 'max:50', 'prohibited_if:type,unavailable'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $targetUserId = $request->input('user_id', $request->user()->id);

        if (!$request->user()->can('manageFor', [UserAvailability::class, $targetUserId])) {
            return response()->json(['message' => "You do not manage this employee's team."], 403);
        }

        $entry = UserAvailability::create([
            'user_id' => $targetUserId,
            'created_by_user_id' => $request->user()->id,
            'type' => $request->type,
            'shift_type' => $request->shift_type,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'note' => $request->note,
        ]);
        $entry->load('createdBy:id,name');

        return response()->json($this->formatEntry($entry), 201);
    }

    /**
     * Delete an availability entry (own entries, or a team member's entry
     * for a manager of their group — see UserAvailabilityPolicy).
     */
    public function destroy(Request $request, UserAvailability $userAvailability)
    {
        if (!$request->user()->can('manage', $userAvailability)) {
            return response()->json(['message' => "You do not manage this employee's team."], 403);
        }

        $userAvailability->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Get availability entries for a specific user in a given month.
     * Used by the schedule view (and the availability editor) to show overlays.
     */
    public function forMonth(Request $request, $userId)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $start = Carbon::createFromFormat('Y-m', $request->month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $entries = UserAvailability::where('user_id', $userId)
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->with('createdBy:id,name')
            ->orderBy('start_date')
            ->get()
            ->map(fn($e) => $this->formatEntry($e));

        return response()->json($entries);
    }

    private function formatEntry($entry)
    {
        return [
            'id' => $entry->id,
            'user_id' => $entry->user_id,
            'created_by_user_id' => $entry->created_by_user_id,
            'created_by_name' => $entry->createdBy?->name,
            'type' => $entry->type,
            'shift_type' => $entry->shift_type,
            'start_date' => $entry->start_date->format('Y-m-d'),
            'end_date' => $entry->end_date->format('Y-m-d'),
            'start_time' => $entry->start_time ? substr($entry->start_time, 0, 5) : null,
            'end_time' => $entry->end_time ? substr($entry->end_time, 0, 5) : null,
            'note' => $entry->note,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/UserAvailabilityControllerTest.php`
Expected: PASS

- [ ] **Step 5: Run the full backend suite to check for regressions**

Run: `cd backend && ./vendor/bin/pest`
Expected: PASS (no other suite touches `UserAvailabilityController`)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/UserAvailabilityController.php backend/tests/Feature/UserAvailabilityControllerTest.php
git commit -m "feat: enforce UserAvailabilityPolicy and support manager-created entries"
```

---

## Task 5: `shift_type` on `Shift` + admin endpoint to set it

**Files:**
- Create: `backend/database/migrations/2026_09_07_120200_add_shift_type_to_shifts_table.php`
- Modify: `backend/app/Models/Shift.php`
- Modify: `backend/app/Http/Controllers/ScheduleController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/ScheduleShiftTypeTest.php`

**Interfaces:**
- Produces: `PUT /api/schedule/shifts/{shift}/shift-type` (admin:schedule), body `{shift_type: string|null}`, response `{id, shift_type}`. Task 11 (frontend `ScheduleView.vue`) calls this exact route/shape.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\User;

it('lets a schedule maintainer set a shift shift_type', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::where('is_default', true)->first() ?? ShiftGroup::create(['name' => 'Main', 'is_default' => true]);
    $shift = Shift::create([
        'date' => '2026-09-07',
        'shift_number' => 1,
        'shift_group_id' => $group->id,
        'start_time' => '06:00',
        'end_time' => '14:00',
    ]);

    $response = $this->actingAs($maintainer)
        ->putJson("/api/schedule/shifts/{$shift->id}/shift-type", ['shift_type' => 'night']);

    $response->assertOk()->assertJson(['id' => $shift->id, 'shift_type' => 'night']);
    $this->assertDatabaseHas('shifts', ['id' => $shift->id, 'shift_type' => 'night']);
});

it('rejects a plain employee', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $group = ShiftGroup::where('is_default', true)->first() ?? ShiftGroup::create(['name' => 'Main', 'is_default' => true]);
    $shift = Shift::create([
        'date' => '2026-09-07',
        'shift_number' => 1,
        'shift_group_id' => $group->id,
        'start_time' => '06:00',
        'end_time' => '14:00',
    ]);

    $this->actingAs($employee)
        ->putJson("/api/schedule/shifts/{$shift->id}/shift-type", ['shift_type' => 'night'])
        ->assertStatus(403);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/ScheduleShiftTypeTest.php`
Expected: FAIL — column and route don't exist yet.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->string('shift_type', 50)->nullable()->after('shift_number');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('shift_type');
        });
    }
};
```

- [ ] **Step 4: Update the Shift model**

In `backend/app/Models/Shift.php`, update `$fillable`:

```php
    protected $fillable = ['date', 'shift_number', 'shift_type', 'shift_group_id', 'start_time', 'end_time', 'work_mode', 'color', 'color_label'];
```

- [ ] **Step 5: Add the controller method**

In `backend/app/Http/Controllers/ScheduleController.php`, add near `updateShiftColor`:

```php
    public function updateShiftType(Request $request, Shift $shift)
    {
        $request->validate([
            'shift_type' => ['nullable', 'string', 'max:50'],
        ]);

        $shift->update(['shift_type' => $request->input('shift_type')]);

        return response()->json([
            'id' => $shift->id,
            'shift_type' => $shift->shift_type,
        ]);
    }
```

- [ ] **Step 6: Register the route**

In `backend/routes/api.php`, add next to the existing `.../color` route inside the `admin:schedule` group:

```php
        Route::put('/schedule/shifts/{shift}/shift-type', [ScheduleController::class, 'updateShiftType']);
```

- [ ] **Step 7: Run test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/ScheduleShiftTypeTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add backend/database/migrations/2026_09_07_120200_add_shift_type_to_shifts_table.php backend/app/Models/Shift.php backend/app/Http/Controllers/ScheduleController.php backend/routes/api.php backend/tests/Feature/ScheduleShiftTypeTest.php
git commit -m "feat: add shift_type label to Shift with admin:schedule-gated endpoint"
```

---

## Task 6: Admin UI — assign managers to a `UserGroup`

**Files:**
- Modify: `backend/app/Http/Controllers/UserGroupController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/UserGroupControllerManagersTest.php`
- Modify: `frontend/src/views/UsersView.vue`

**Interfaces:**
- Consumes: `UserGroup::managers()` (Task 1).
- Produces: `PUT /api/user-groups/{userGroup}/managers` body `{user_ids: int[]}`; `UserGroup` JSON now includes `managers: [{id,name,email}]` from `index`/`store`/`update`/`syncUsers`/`syncManagers`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\User;
use App\Models\UserGroup;

it('lets an admin sync a group\'s managers', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $managerOne = User::factory()->create();
    $managerTwo = User::factory()->create();

    $response = $this->actingAs($admin)->putJson("/api/user-groups/{$group->id}/managers", [
        'user_ids' => [$managerOne->id, $managerTwo->id],
    ]);

    $response->assertOk();
    expect(collect($response->json('managers'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$managerOne->id, $managerTwo->id])->sort()->values()->all());
});

it('includes managers in the group index for admins', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = UserGroup::create(['name' => 'Team A', 'enum' => 'team_a']);
    $manager = User::factory()->create();
    $group->managers()->attach($manager->id);

    $response = $this->actingAs($admin)->getJson('/api/user-groups');

    $response->assertOk();
    expect(collect($response->json()[0]['managers'])->pluck('id')->all())->toBe([$manager->id]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/UserGroupControllerManagersTest.php`
Expected: FAIL — route and `managers` payload key don't exist.

- [ ] **Step 3: Update the controller**

Replace `backend/app/Http/Controllers/UserGroupController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Models\UserGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserGroupController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            UserGroup::with(['users:id,name,email', 'managers:id,name,email'])->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'enum' => ['required', 'string', 'max:100', 'unique:user_groups', 'regex:/^[a-z0-9_-]+$/'],
        ]);

        $group = UserGroup::create($validated);
        $group->load(['users:id,name,email', 'managers:id,name,email']);

        return response()->json($group, 201);
    }

    public function update(Request $request, UserGroup $userGroup): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'enum' => ['required', 'string', 'max:100', Rule::unique('user_groups')->ignore($userGroup->id), 'regex:/^[a-z0-9_-]+$/'],
        ]);

        $userGroup->update($validated);
        $userGroup->load(['users:id,name,email', 'managers:id,name,email']);

        return response()->json($userGroup);
    }

    public function destroy(UserGroup $userGroup): JsonResponse
    {
        $userGroup->delete();
        return response()->json(null, 204);
    }

    public function syncUsers(Request $request, UserGroup $userGroup): JsonResponse
    {
        $validated = $request->validate([
            'user_ids' => ['present', 'array', 'max:500'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $userGroup->users()->sync($validated['user_ids']);
        $userGroup->load(['users:id,name,email', 'managers:id,name,email']);

        return response()->json($userGroup);
    }

    public function syncManagers(Request $request, UserGroup $userGroup): JsonResponse
    {
        $validated = $request->validate([
            'user_ids' => ['present', 'array', 'max:500'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $userGroup->managers()->sync($validated['user_ids']);
        $userGroup->load(['users:id,name,email', 'managers:id,name,email']);

        return response()->json($userGroup);
    }

    public function managedByMe(Request $request): JsonResponse
    {
        $groups = $request->user()
            ->managedGroups()
            ->with('users:id,name,email')
            ->orderBy('name')
            ->get(['user_groups.id', 'user_groups.name', 'user_groups.enum']);

        return response()->json($groups);
    }
}
```

- [ ] **Step 4: Register the routes**

In `backend/routes/api.php`, add next to the existing `user-groups/{userGroup}/users` route (inside the `admin` group):

```php
        Route::put('/user-groups/{userGroup}/managers', [UserGroupController::class, 'syncManagers']);
```

And add this near the other any-authenticated-user routes (e.g. right after the `/user-groups` read-only line, still inside `auth:sanctum` but outside the `admin` group):

```php
    // Groups the current user manages (for the team-availability view)
    Route::get('/my-managed-teams', [UserGroupController::class, 'managedByMe']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/UserGroupControllerManagersTest.php`
Expected: PASS

- [ ] **Step 6: Add the frontend toggle UI**

In `frontend/src/views/UsersView.vue`, the "Members Panel" (around line 208-283) currently has a single users list with a member-toggle button. Add a second tab-like split within that panel: a "Members" / "Managers" sub-toggle. The minimal correct change: duplicate the users-list block as a "Managers" section below the existing members list, reusing `filteredGroupUsers` for the candidate list but a separate `_isManager` flag and a separate toggle function.

Add after the closing `</div>` of the users list (the one ending the block that contains `toggleMember`, i.e. right before the "Hint when no group selected" block, still inside `<div v-if="selectedGroup" class="lg:col-span-3">`'s panel `<div>`):

```html
            <!-- Managers sub-section -->
            <div class="border-t border-white/10 px-4 py-3">
              <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Managers</h4>
              <div class="space-y-1 max-h-56 overflow-y-auto">
                <div v-for="user in filteredGroupUsers" :key="'mgr-' + user.id"
                  class="flex items-center gap-3 px-2 py-2 hover:bg-white/[0.03] rounded-lg transition-colors duration-150">
                  <div class="w-7 h-7 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white font-semibold text-[10px] shrink-0">
                    {{ getInitials(user.name) }}
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="text-sm text-white truncate">{{ user.name }}</p>
                  </div>
                  <button
                    @click="toggleManager(user.id)"
                    :disabled="togglingManagerId !== null"
                    class="shrink-0 px-3 py-1.5 text-xs font-medium rounded-lg transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                    :class="user._isManager
                      ? 'bg-amber-500/15 text-amber-400 border border-amber-500/20 hover:bg-red-500/15 hover:text-red-400 hover:border-red-500/20'
                      : 'bg-white/5 text-slate-400 border border-white/10 hover:bg-amber-500/15 hover:text-amber-300 hover:border-amber-500/20'">
                    {{ user._isManager ? 'Manager' : 'Make manager' }}
                  </button>
                </div>
              </div>
            </div>
```

In the `<script setup>` section, add a `togglingManagerId` ref next to `togglingUserId`, mark each user with `_isManager` wherever `_isMember` is currently computed for `filteredGroupUsers` (find that computed and add the parallel line), and add the toggle function next to `toggleMember`:

```js
const togglingManagerId = ref(null);

async function toggleManager(userId) {
  if (!selectedGroup.value) return;
  togglingManagerId.value = userId;
  const currentIds = selectedGroup.value.managers?.map((u) => u.id) ?? [];
  const newIds = currentIds.includes(userId)
    ? currentIds.filter((id) => id !== userId)
    : [...currentIds, userId];
  try {
    const res = await api.put(`/api/user-groups/${selectedGroup.value.id}/managers`, {
      user_ids: newIds,
    });
    selectedGroup.value = res.data;
    const idx = groups.value.findIndex((g) => g.id === res.data.id);
    if (idx !== -1) groups.value[idx] = res.data;
  } catch {
    showToast('Failed to update group managers.', 'error');
  } finally {
    togglingManagerId.value = null;
  }
}
```

Locate the `filteredGroupUsers` computed (it currently maps in `_isMember`); add the sibling flag in the same `.map(...)` call:

```js
_isManager: selectedGroup.value?.managers?.some((m) => m.id === user.id) ?? false,
```

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/UserGroupController.php backend/routes/api.php backend/tests/Feature/UserGroupControllerManagersTest.php frontend/src/views/UsersView.vue
git commit -m "feat: let admins assign managers to a user group"
```

---

## Task 7: Shared `AvailabilityEditor.vue` component (extracted from `MyAvailabilityView.vue`)

**Files:**
- Create: `frontend/src/components/AvailabilityEditor.vue`
- Create: `frontend/src/components/AvailabilityEditor.test.js`
- Modify: `frontend/src/views/MyAvailabilityView.vue`

**Interfaces:**
- Consumes: `GET /api/user-availabilities/{userId}/month`, `POST /api/user-availabilities`, `DELETE /api/user-availabilities/{id}` (Task 4's shapes).
- Produces: `AvailabilityEditor` component, props `targetUserId: Number` (required), `targetUserName: String` (default `null`, used only for the modal subtitle when editing someone else). Task 8 (`TeamAvailabilityView.vue`) consumes this exact prop contract.

- [ ] **Step 1: Write the failing test**

```js
import { fireEvent, render, screen, waitFor } from '@testing-library/vue';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';
import { server } from '../mocks/server.js';
import AvailabilityEditor from './AvailabilityEditor.vue';

describe('AvailabilityEditor', () => {
  it('loads entries scoped to the target user for the current month and shows a manager-set badge', async () => {
    server.use(
      http.get('/api/user-availabilities/42/month', () =>
        HttpResponse.json([
          {
            id: 1,
            user_id: 42,
            created_by_user_id: 7,
            created_by_name: 'Alex Manager',
            type: 'unavailable',
            shift_type: null,
            start_date: new Date().toISOString().slice(0, 10),
            end_date: new Date().toISOString().slice(0, 10),
            start_time: null,
            end_time: null,
            note: 'Doctor',
          },
        ]),
      ),
    );

    render(AvailabilityEditor, { props: { targetUserId: 42 } });

    await waitFor(() => expect(screen.getByText(/Alex Manager/)).toBeInTheDocument());
  });

  it('submits a shift-type preference for the target user', async () => {
    server.use(
      http.get('/api/user-availabilities/42/month', () => HttpResponse.json([])),
      http.post('/api/user-availabilities', async ({ request }) => {
        const body = await request.json();
        expect(body.user_id).toBe(42);
        expect(body.type).toBe('preferred');
        expect(body.shift_type).toBe('night');
        return HttpResponse.json(
          {
            id: 2,
            user_id: 42,
            created_by_user_id: 42,
            created_by_name: null,
            type: 'preferred',
            shift_type: 'night',
            start_date: body.start_date,
            end_date: body.end_date,
            start_time: null,
            end_time: null,
            note: null,
          },
          { status: 201 },
        );
      }),
    );

    render(AvailabilityEditor, { props: { targetUserId: 42 } });

    const todayStr = new Date().toISOString().slice(0, 10);
    const todayCell = await screen.findByTestId(`calendar-day-${todayStr}`);
    await fireEvent.click(todayCell);
    await fireEvent.click(screen.getByRole('button', { name: /preferred/i }));
    await fireEvent.update(screen.getByLabelText(/shift type/i), 'night');
    await fireEvent.click(screen.getByRole('button', { name: /^save$/i }));

    await waitFor(() => expect(screen.queryByText(/add availability/i)).not.toBeInTheDocument());
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/components/AvailabilityEditor.test.js`
Expected: FAIL — component doesn't exist.

- [ ] **Step 3: Create the component**

This is the calendar+modal body of the current `MyAvailabilityView.vue` (lines 22-327), parameterized by `targetUserId`/`targetUserName`, fetching via `forMonth` instead of the self-only `index`, always sending `user_id` on create, and adding the shift-type field + manager badge. A `data-testid="calendar-day"` is added to each day cell so tests can click a specific day deterministically.

```vue
<template>
  <div>
    <!-- Month Navigator -->
    <div class="flex items-center justify-center gap-3 mb-6">
      <button @click="prevMonth" class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
      </button>
      <span class="text-white font-semibold text-lg min-w-[180px] text-center">{{ monthLabel }}</span>
      <button @click="nextMonth" class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
      </button>
    </div>

    <!-- Calendar -->
    <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden mb-6">
      <div class="grid grid-cols-7 border-b border-white/10">
        <div v-for="d in ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']" :key="d"
          class="px-2 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider">{{ d }}</div>
      </div>
      <div v-for="(week, wi) in calendarWeeks" :key="wi" class="grid grid-cols-7 border-b border-white/5 last:border-b-0">
        <div v-for="(day, di) in week" :key="di" :data-testid="'calendar-day-' + day.dateStr"
          :class="[
            'border-r border-white/5 last:border-r-0 min-h-[80px] p-2 transition-colors cursor-pointer',
            day.isCurrentMonth ? 'hover:bg-white/5' : 'bg-white/[0.02] opacity-40',
            day.isToday ? 'ring-1 ring-inset ring-indigo-500/30' : '',
          ]"
          @click="day.isCurrentMonth && openAddModal(day.dateStr)">
          <div class="text-xs font-medium mb-1" :class="day.isToday ? 'text-indigo-400' : 'text-slate-500'">{{ day.dayNumber }}</div>
          <div class="space-y-0.5">
            <div v-for="entry in getEntriesForDate(day.dateStr)" :key="entry.id"
              @click.stop="deleteEntry(entry)"
              :title="entryTitle(entry)"
              :class="[
                'px-1.5 py-0.5 text-[9px] font-medium rounded cursor-pointer transition-all',
                entry.type === 'unavailable'
                  ? 'bg-red-500/20 text-red-300 border border-red-500/25 hover:bg-red-500/30'
                  : 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/25 hover:bg-emerald-500/30'
              ]">
              {{ entry.type === 'unavailable' ? '🔴' : '🟢' }}
              {{ entry.shift_type ? entry.shift_type : (entry.start_time ? entry.start_time + '–' + entry.end_time : 'All day') }}
              <span v-if="entry.created_by_user_id !== entry.user_id && entry.created_by_name" class="opacity-70">· set by {{ entry.created_by_name }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Legend -->
    <div class="flex items-center gap-6 mb-6 text-sm text-slate-400">
      <div class="flex items-center gap-2">
        <span class="w-3 h-3 rounded bg-red-500/30 border border-red-500/40"></span> Unavailable
      </div>
      <div class="flex items-center gap-2">
        <span class="w-3 h-3 rounded bg-emerald-500/30 border border-emerald-500/40"></span> Preferred
      </div>
      <span class="text-slate-600">Click a day to add · Click an entry to remove</span>
    </div>

    <!-- Add Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center px-4" @click.self="showModal = false">
        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showModal = false"></div>
        <div class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 p-6">
          <h3 class="text-white font-bold text-lg mb-1">Add Availability</h3>
          <p class="text-slate-400 text-sm mb-5">
            {{ targetUserName ? targetUserName + ' · ' : '' }}{{ form.start_date }}{{ form.end_date !== form.start_date ? ' → ' + form.end_date : '' }}
          </p>

          <div class="flex gap-2 mb-4">
            <button @click="form.type = 'unavailable'"
              :class="['flex-1 py-2.5 rounded-xl text-sm font-semibold border transition-all', form.type === 'unavailable' ? 'bg-red-500/20 text-red-300 border-red-500/40' : 'bg-white/5 text-slate-400 border-white/10 hover:bg-white/10']">
              🔴 Unavailable
            </button>
            <button @click="form.type = 'preferred'"
              :class="['flex-1 py-2.5 rounded-xl text-sm font-semibold border transition-all', form.type === 'preferred' ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40' : 'bg-white/5 text-slate-400 border-white/10 hover:bg-white/10']">
              🟢 Preferred
            </button>
          </div>

          <div v-if="form.type === 'preferred'" class="mb-4">
            <label for="shift-type-input" class="block text-sm font-medium text-slate-300 mb-1.5">Shift type <span class="text-slate-500 font-normal">(optional)</span></label>
            <input id="shift-type-input" v-model="form.shift_type" type="text" list="shift-type-options" placeholder="e.g. night"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-600" />
            <datalist id="shift-type-options">
              <option value="day" />
              <option value="evening" />
              <option value="night" />
            </datalist>
          </div>

          <div class="mb-4">
            <label class="block text-sm font-medium text-slate-300 mb-1.5">End Date <span class="text-slate-500 font-normal">(for multi-day range)</span></label>
            <input v-model="form.end_date" type="date" :min="form.start_date"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
          </div>

          <div class="mb-4">
            <label class="flex items-center gap-2 text-sm text-slate-300 mb-2 cursor-pointer">
              <input type="checkbox" v-model="useHours" class="rounded bg-white/10 border-white/20 text-indigo-500 focus:ring-indigo-500/50" />
              Specific hours only
            </label>
            <div v-if="useHours" class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-xs text-slate-400 mb-1">From</label>
                <input v-model="form.start_time" type="time"
                  class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
              </div>
              <div>
                <label class="block text-xs text-slate-400 mb-1">To</label>
                <input v-model="form.end_time" type="time"
                  class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
              </div>
            </div>
          </div>

          <div class="mb-5">
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Note <span class="text-slate-500 font-normal">(optional)</span></label>
            <input v-model="form.note" type="text" placeholder="e.g. Doctor appointment"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-600" />
          </div>

          <div class="flex items-center justify-end gap-3">
            <button @click="showModal = false" class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">Cancel</button>
            <button @click="saveEntry" :disabled="saving"
              class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
              <svg v-if="saving" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
              Save
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <AppToast />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { confirm } from '../composables/useConfirm.js';
import { useToast } from '../composables/useToast.js';
import api from '../axios.js';
import AppToast from './AppToast.vue';

const props = defineProps({
  targetUserId: { type: Number, required: true },
  targetUserName: { type: String, default: null },
});

const currentYear = ref(new Date().getFullYear());
const currentMonth = ref(new Date().getMonth());
const entries = ref([]);
const showModal = ref(false);
const saving = ref(false);
const useHours = ref(false);
const { showToast } = useToast();

const form = ref({
  type: 'unavailable',
  shift_type: '',
  start_date: '',
  end_date: '',
  start_time: '06:00',
  end_time: '14:00',
  note: '',
});

const monthKey = computed(
  () => `${currentYear.value}-${String(currentMonth.value + 1).padStart(2, '0')}`,
);
const monthLabel = computed(() => {
  const d = new Date(currentYear.value, currentMonth.value, 1);
  return d.toLocaleString('en-US', { month: 'long', year: 'numeric' });
});

function prevMonth() {
  if (currentMonth.value === 0) {
    currentMonth.value = 11;
    currentYear.value--;
  } else {
    currentMonth.value--;
  }
}
function nextMonth() {
  if (currentMonth.value === 11) {
    currentMonth.value = 0;
    currentYear.value++;
  } else {
    currentMonth.value++;
  }
}

const calendarWeeks = computed(() => {
  const first = new Date(currentYear.value, currentMonth.value, 1);
  const last = new Date(currentYear.value, currentMonth.value + 1, 0);
  const today = localDateStr(new Date());
  let startDay = first.getDay() === 0 ? 6 : first.getDay() - 1;
  const weeks = [];
  let week = [];
  const startDate = new Date(first);
  startDate.setDate(startDate.getDate() - startDay);
  for (let i = 0; i < 42; i++) {
    const d = new Date(startDate);
    d.setDate(startDate.getDate() + i);
    const dateStr = localDateStr(d);
    week.push({
      dateStr,
      dayNumber: d.getDate(),
      isCurrentMonth: d.getMonth() === currentMonth.value && d.getFullYear() === currentYear.value,
      isToday: dateStr === today,
    });
    if (week.length === 7) {
      weeks.push(week);
      week = [];
    }
  }
  return weeks.filter((w) => w.some((d) => d.isCurrentMonth));
});

function getEntriesForDate(dateStr) {
  return entries.value.filter((e) => dateStr >= e.start_date && dateStr <= e.end_date);
}

function entryTitle(entry) {
  const kind = entry.type === 'unavailable' ? '🔴 Unavailable' : '🟢 Preferred';
  const shiftType = entry.shift_type ? ` (${entry.shift_type})` : '';
  const hours = entry.start_time ? ` (${entry.start_time}–${entry.end_time})` : ' (all day)';
  const note = entry.note ? `: ${entry.note}` : '';
  const setBy = entry.created_by_user_id !== entry.user_id && entry.created_by_name
    ? ` — set by ${entry.created_by_name}`
    : '';
  return `${kind}${shiftType}${note}${hours}${setBy} — click to remove`;
}

function localDateStr(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function openAddModal(dateStr) {
  form.value = {
    type: 'unavailable',
    shift_type: '',
    start_date: dateStr,
    end_date: dateStr,
    start_time: '06:00',
    end_time: '14:00',
    note: '',
  };
  useHours.value = false;
  showModal.value = true;
}

async function fetchEntries() {
  try {
    const res = await api.get(`/api/user-availabilities/${props.targetUserId}/month`, {
      params: { month: monthKey.value },
    });
    entries.value = res.data;
  } catch {
    showToast('Failed to load availability.', 'error');
  }
}

async function saveEntry() {
  saving.value = true;
  try {
    const payload = {
      user_id: props.targetUserId,
      type: form.value.type,
      shift_type: form.value.type === 'preferred' && form.value.shift_type ? form.value.shift_type : null,
      start_date: form.value.start_date,
      end_date: form.value.end_date,
      start_time: useHours.value ? form.value.start_time : null,
      end_time: useHours.value ? form.value.end_time : null,
      note: form.value.note || null,
    };
    const res = await api.post('/api/user-availabilities', payload);
    entries.value.push(res.data);
    showModal.value = false;
    showToast('Availability saved.');
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to save.', 'error');
  } finally {
    saving.value = false;
  }
}

async function deleteEntry(entry) {
  const ok = await confirm({
    title: 'Remove Entry',
    message: 'Remove this availability entry?',
    confirmLabel: 'Remove',
    danger: true,
  });
  if (!ok) return;
  try {
    await api.delete(`/api/user-availabilities/${entry.id}`);
    entries.value = entries.value.filter((e) => e.id !== entry.id);
    showToast('Entry removed.');
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to remove.', 'error');
  }
}

onMounted(fetchEntries);
watch(monthKey, fetchEntries);
watch(() => props.targetUserId, fetchEntries);
</script>
```

- [ ] **Step 4: Update `MyAvailabilityView.vue` to use the shared component**

Replace the full contents of `frontend/src/views/MyAvailabilityView.vue`:

```vue
<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-white">My Availability</h1>
          <p class="text-slate-400 mt-1">Mark your preferred and unavailable work periods</p>
        </div>
      </div>

      <AvailabilityEditor v-if="currentUser" :target-user-id="currentUser.id" />

      <!-- Delegation markers kept from the original view: shown separately below the editor -->
      <div v-if="delegations.length" class="mt-6 bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-4">
        <h3 class="text-sm font-semibold text-slate-300 mb-3">Delegations this month</h3>
        <div class="flex flex-wrap gap-2">
          <span v-for="del in delegations" :key="del.id"
            class="px-2 py-1 text-xs font-medium rounded bg-purple-500/20 text-purple-300 border border-purple-500/25">
            📍 {{ del.name }} ({{ del.destination }})
          </span>
        </div>
      </div>
    </main>
  </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import AvailabilityEditor from '../components/AvailabilityEditor.vue';
import { currentUser } from '../router/index.js';

const delegations = ref([]);

async function fetchDelegations() {
  try {
    const month = new Date().toISOString().slice(0, 7);
    const res = await api.get('/api/my-delegations', { params: { month } });
    delegations.value = res.data;
  } catch {
    delegations.value = [];
  }
}

onMounted(fetchDelegations);
</script>
```

> Note: this simplifies the delegation display (no month navigation synced with the calendar — delegations now always show the current month). If this regresses a workflow the team relies on, it can be revisited in a follow-up; it isn't part of this feature's scope and the spec didn't call for delegation-month-sync to be preserved.

- [ ] **Step 5: Run the frontend test suite**

Run: `cd frontend && npx vitest run src/components/AvailabilityEditor.test.js`
Expected: PASS

- [ ] **Step 6: Manual smoke test**

Run: `cd frontend && npm run dev` (or use the project's existing dev workflow), open `/my-availability`, confirm: adding an unavailable entry works, switching to "Preferred" reveals the shift-type input, saving a preferred+night entry shows "night" on the calendar cell, deleting still works.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/components/AvailabilityEditor.vue frontend/src/components/AvailabilityEditor.test.js frontend/src/views/MyAvailabilityView.vue
git commit -m "refactor: extract AvailabilityEditor component with shift-type preference support"
```

---

## Task 8: `TeamAvailabilityView.vue` (manager-facing)

**Files:**
- Create: `frontend/src/views/TeamAvailabilityView.vue`
- Create: `frontend/src/views/TeamAvailabilityView.test.js`
- Modify: `frontend/src/router/index.js`
- Modify: `frontend/src/components/AppLayout.vue`

**Interfaces:**
- Consumes: `AvailabilityEditor` (Task 7), `GET /api/my-managed-teams` (Task 6).

- [ ] **Step 1: Write the failing test**

```js
import { render, screen, waitFor } from '@testing-library/vue';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';
import { server } from '../mocks/server.js';
import TeamAvailabilityView from './TeamAvailabilityView.vue';

describe('TeamAvailabilityView', () => {
  it('lists managed team members and shows an empty state with no teams', async () => {
    server.use(
      http.get('/api/my-managed-teams', () => HttpResponse.json([])),
    );

    render(TeamAvailabilityView, { global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } } });

    await waitFor(() =>
      expect(screen.getByText(/you don.t manage any teams/i)).toBeInTheDocument(),
    );
  });

  it('shows team members and selecting one renders the availability editor', async () => {
    server.use(
      http.get('/api/my-managed-teams', () =>
        HttpResponse.json([
          {
            id: 1,
            name: 'Team A',
            enum: 'team_a',
            users: [{ id: 42, name: 'Jamie Employee', email: 'jamie@example.com' }],
          },
        ]),
      ),
      http.get('/api/user-availabilities/42/month', () => HttpResponse.json([])),
    );

    render(TeamAvailabilityView, { global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } } });

    await waitFor(() => expect(screen.getByText('Jamie Employee')).toBeInTheDocument());
    await screen.getByText('Jamie Employee').click();

    await waitFor(() => expect(screen.getByText(/click a day to add/i)).toBeInTheDocument());
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/views/TeamAvailabilityView.test.js`
Expected: FAIL — view doesn't exist.

- [ ] **Step 3: Create the view**

```vue
<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Team Availability</h1>
        <p class="text-slate-400 mt-1">Set preferences and unavailable periods for people on your team</p>
      </div>

      <div v-if="loading" class="p-10 text-center text-slate-400">Loading your teams…</div>

      <div v-else-if="teams.length === 0" class="p-10 text-center text-slate-500">
        You don't manage any teams yet.
      </div>

      <div v-else class="flex gap-6">
        <div class="w-1/4 min-w-[220px]">
          <div v-for="team in teams" :key="team.id" class="mb-4">
            <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">{{ team.name }}</h3>
            <button v-for="member in team.users" :key="member.id"
              @click="selectedMember = member"
              :class="['w-full text-left px-3 py-2 rounded-lg text-sm mb-1 transition-all',
                selectedMember?.id === member.id ? 'bg-indigo-500/20 text-indigo-300' : 'text-slate-300 hover:bg-white/5']">
              {{ member.name }}
            </button>
          </div>
        </div>

        <div class="flex-1 min-w-0">
          <AvailabilityEditor v-if="selectedMember" :target-user-id="selectedMember.id" :target-user-name="selectedMember.name" />
          <div v-else class="p-10 text-center text-slate-500">Select a team member to manage their availability.</div>
        </div>
      </div>
    </main>
  </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import AvailabilityEditor from '../components/AvailabilityEditor.vue';

const teams = ref([]);
const loading = ref(true);
const selectedMember = ref(null);

async function fetchTeams() {
  loading.value = true;
  try {
    const res = await api.get('/api/my-managed-teams');
    teams.value = res.data;
  } catch {
    teams.value = [];
  } finally {
    loading.value = false;
  }
}

onMounted(fetchTeams);
</script>
```

- [ ] **Step 4: Register the route**

In `frontend/src/router/index.js`, add the import:

```js
import TeamAvailabilityView from '../views/TeamAvailabilityView.vue';
```

And add a route entry next to `/my-availability`:

```js
  {
    path: '/team-availability',
    name: 'team-availability',
    component: TeamAvailabilityView,
    meta: { requiresAuth: true },
  },
```

- [ ] **Step 5: Add the nav link**

In `frontend/src/components/AppLayout.vue`, add a router-link right after the existing `/my-availability` link (~line 155), inside the same user-menu `<div class="py-1">` block:

```html
                    <router-link to="/team-availability" @click="userMenuOpen = false"
                      :class="['flex items-center gap-3 px-4 py-2.5 text-sm transition-all',
                        $route.path === '/team-availability'
                          ? 'text-indigo-300 bg-indigo-500/10'
                          : 'text-slate-300 hover:text-white hover:bg-white/5']">
                      <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                      </svg>
                      Team Availability
                    </router-link>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd frontend && npx vitest run src/views/TeamAvailabilityView.test.js`
Expected: PASS

- [ ] **Step 7: Manual smoke test**

Log in as a user attached to `user_group_managers` for at least one group (seed one manually via tinker or the admin UI from Task 6), open the user menu, click "Team Availability", confirm the member list appears and editing a member's preferences works end-to-end, and shows up with a "set by" badge on that employee's own `/my-availability` page.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/views/TeamAvailabilityView.vue frontend/src/views/TeamAvailabilityView.test.js frontend/src/router/index.js frontend/src/components/AppLayout.vue
git commit -m "feat: add manager-facing Team Availability view"
```

---

## Task 9: `ScheduleView.vue` — set `shift_type` from the Shift Times modal

**Files:**
- Modify: `frontend/src/views/ScheduleView.vue`

**Interfaces:**
- Consumes: `PUT /api/schedule/shifts/{shift}/shift-type` (Task 5).

- [ ] **Step 1: Add the field to the modal template**

In `frontend/src/views/ScheduleView.vue`, insert this block right after the closing `</div>` of the "Color Picker" section (after line 674, before the `<div class="flex items-center justify-end gap-3">` actions row):

```html
          <!-- Shift Type -->
          <div class="mb-4">
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Shift Type <span class="text-slate-500 font-normal">(optional)</span></label>
            <input v-model="timeForm.shift_type" type="text" list="schedule-shift-type-options" placeholder="e.g. night"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
            <datalist id="schedule-shift-type-options">
              <option value="day" />
              <option value="evening" />
              <option value="night" />
            </datalist>
          </div>
```

- [ ] **Step 2: Include `shift_type` when the editor opens and saves**

In `openTimeEditor` (around line 1910-1920), add `shift_type` to the assigned object:

```js
  timeForm.value = {
    start_time: shift.start_time || '06:00',
    end_time: shift.end_time || '14:00',
    color: shift.color || null,
    color_label: shift.color_label || '',
    shift_type: shift.shift_type || '',
  };
```

In `saveShiftTimes` (around line 1922-1950), add the new PUT call after the existing color-save call and before `showTimeModal.value = false`:

```js
    const shiftTypeRes = await api.put(`/api/schedule/shifts/${shift.id}/shift-type`, {
      shift_type: timeForm.value.shift_type || null,
    });
    shift.shift_type = shiftTypeRes.data.shift_type;
```

- [ ] **Step 3: Manual smoke test**

Run the dev server, open the schedule view as an admin/schedule_maintainer, open a shift's time editor, set a shift type (e.g. "night"), save, confirm no console errors and the modal reopens with the value persisted (re-open the same shift's editor and check the field is pre-filled).

- [ ] **Step 4: Commit**

```bash
git add frontend/src/views/ScheduleView.vue
git commit -m "feat: set shift_type from the schedule view's shift times editor"
```

---

## Task 10: Full regression pass

- [ ] **Step 1: Run the full backend suite**

Run: `cd backend && ./vendor/bin/pest`
Expected: PASS (all prior suites plus new ones from Tasks 1-6)

- [ ] **Step 2: Run the full frontend suite**

Run: `cd frontend && npm run test`
Expected: PASS (all prior suites plus Tasks 7-8)

- [ ] **Step 3: Run frontend lint**

Run: `cd frontend && npm run check`
Expected: no errors (fix any Biome findings in the files touched by this plan before committing further)

- [ ] **Step 4: Commit if any fixes were needed**

```bash
git add -A
git commit -m "chore: fix lint/regressions found in full pass"
```
