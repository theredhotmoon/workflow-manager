# Retroactive Rest-Day Conflicts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When adding a shift would turn one of the same employee's existing later shifts into a mandatory rest day, prevent it where a person or auto-fill is choosing (manual assign asks; auto-fill skips), and flag it where it can't be prevented (retype, rule edits) with a ⚠ marker on the chip.

**Architecture:** `StreakTimeline` gains `newlyResting()` (replay with vs without a candidate assignment; return existing assignments that flip from working to resting), `wouldConflict()` (resting on the day OR newlyResting non-empty) and `restingAssignments()` (assignment ids currently covered by a window, with the window end). Events carry their `assignment_id`; `StreakReplay::forGroup()` accepts `null` for "all dates". Manual assign answers a second 409 (`creates_rest_conflict`) until `later_rest_policy` is sent, then creates the assignment and stamps that policy on the affected assignments in one transaction. All three auto-fill modes use `wouldConflict()`. The schedule payload gets `in_rest` / `resting_until` per assignment; the UI shows ⚠ when `in_rest`.

**Tech Stack:** Laravel 9 / PHP 8.0–8.2 / PostgreSQL, Pest; Vue 3 `<script setup>`, Biome; Playwright e2e.

**Spec:** the design agreed in chat on 2026-10-08 (user chose "Prevent + flag"), building on [docs/superpowers/specs/2026-10-07-streak-replay-design.md](../specs/2026-10-07-streak-replay-design.md) ("Semantics" is unchanged). Summary of the agreed design:
- Manual assign: if the new shift would make existing later shifts rest days → 409 `creates_rest_conflict` listing them; the same prompt asks how those shifts count; confirm stores that `rest_policy` on them; Cancel changes nothing. The existing `rest_window_conflict` (the dropped day itself) is checked first.
- Auto-fill (Random, Rotating, Polish Law): skip a candidate whose assignment would push one of their existing later shifts into rest.
- Retype / rule edits: not blocked; the schedule marks an assignment ⚠ "Now on a rest day (resting until …): nobody chose how it counts" when it is covered by a rest window and has no `rest_policy`.
- Out of scope: setting a policy by clicking ⚠; notifications.

## Global Constraints

- Semantics of the replay are unchanged (spec "Semantics"). A candidate on day D can only affect existing assignments on days > D (its windows start at D+1).
- `rest_policy` values: `not_counted | counts | extends_rest` (`StreakTimeline::POLICIES`); `later_rest_policy` uses the same set.
- No PHP 8.1+ syntax (CI runs PHP 8.0): no string-key array spread, enums, `readonly`, `never`, first-class callables, `new` in initializers.
- Pest helper functions are global across files: prefix new helpers `rrc`.
- Backend tests (repo root, bash):
  - **`PEST`**: `PW=$(grep '^DB_PASSWORD=' .env | cut -d= -f2-); MSYS_NO_PATHCONV=1 docker run --rm --network workflow-manager_app_network -v "C:/Projects/ShiftChanger/workflow-manager/backend:/var/www/html" -w /var/www/html -e DB_HOST=db -e DB_PORT=5432 -e DB_USERNAME=postgres -e DB_PASSWORD="$PW" --entrypoint php workflow-manager_backend vendor/bin/pest [--filter=X]`
  - **`PEST80`**: same env/network, `-v "C:/Projects/ShiftChanger/workflow-manager/backend:/app" -w /app`, image `php80-pgsql`, command `php vendor/bin/pest`. Full suite on both at the end of every backend task.
- Performance: the existing perf-guard test in `StreakReplayTest.php` must stay green; Task 3 extends it to `wouldConflict()`.
- E2E: rebuild the backend after backend changes (`docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build backend`, wait for `curl -sf http://localhost:8090/healthz`); e2e resets the dev DB. Conventions: import `test`/`expect` from `e2e/fixtures/auth.js`; months reserved by this plan: **2029-03**, **2029-04**; verify via API or after reload; positive guard before absence checks; wait for the save response before reload; 1280×1000 viewport; sabotage-prove each new assertion (undo by exact edit, never `git checkout`/`git restore`).
- Biome on changed frontend files. CHANGELOG entry in the last task.
- If Docker runs out of disk, stop and report; never prune.
- Commit trailer (own line, after a blank line): `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`

## Review Focus

1. **Chain effects** — adding an early shift shifts the count so a window lands much later (not just the next few days). Pinned in Task 1 (chain test) and by `forGroup(..., null)` loading all dates (Task 2/3).
2. **Same-day / earlier assignments are never reported** — a candidate can't affect anything on or before its own day. Pinned in Task 1.
3. **Policy of the candidate matters** — a candidate assigned during rest with `not_counted` changes nothing, so must not trigger `creates_rest_conflict`. Pinned in Task 2.
4. **Cancel leaves no trace** — no assignment created, no later `rest_policy` changed. Pinned in Task 2 (API) and Task 4 (e2e).
5. **⚠ only where nobody decided** — a covered assignment with a `rest_policy` (any value) shows no ⚠. Pinned in Task 2 (payload).

---

### Task 1: Engine — `newlyResting`, `wouldConflict`, `restingAssignments`; loader `until = null`

**Files:**
- Modify: `backend/app/Services/StreakTimeline.php`, `backend/app/Services/StreakReplay.php`
- Test: `backend/tests/Feature/StreakReplayTest.php` (add tests; existing ones stay unchanged)

**Interfaces:**
- Produces:
  - event arrays (constructor input) accept optional `assignment_id` (`?int`).
  - `StreakTimeline::newlyResting(int $userId, ?string $shiftType, \Carbon\Carbon $date, int $shiftNumber, ?string $restPolicy = null): list<array{assignment_id: ?int, date: string, shift_number: int, shift_type: ?string, resting_until: string, rule: ShiftStreakRule}>` — existing events of the user that are NOT covered by a rest window now but WOULD be if the candidate were added; `resting_until` is the final end of the covering window (latest-ending one) in the with-candidate replay.
  - `StreakTimeline::wouldConflict(int $userId, ?string $shiftType, \Carbon\Carbon $date, int $shiftNumber): bool` — `isResting($userId, $date) || newlyResting(...) !== []`.
  - `StreakTimeline::restingAssignments(int $userId): array<int, string>` — assignment_id ⇒ resting_until (Y-m-d) for every event (with an assignment_id) covered by a window when replayed.
  - `StreakReplay::forGroup(ShiftGroup $group, array $userIds, ?\Carbon\Carbon $until = null)` — `null` loads all dates; rows include `assignment_id`.

- [ ] **Step 1: Write the failing tests** (append to `StreakReplayTest.php`; reuse `srlRules`; new helpers prefixed `rrc`)

```php
function rrcEvent(int $id, string $date, ?string $type, ?string $policy = null, int $slot = 1): array
{
    return ['assignment_id' => $id, 'date' => $date, 'shift_number' => $slot, 'shift_type' => $type, 'rest_policy' => $policy];
}

it('reports an existing later shift that a new earlier shift would put into rest', function () {
    // night 2 in a row → 2 days off. Existing: night 01-02, night 01-05.
    $t = new StreakTimeline(srlRules([['night', 2, 2]]), [1 => [
        rrcEvent(10, '2029-01-02', 'night'), rrcEvent(11, '2029-01-05', 'night'),
    ]]);

    // Adding night 01-03 → second night on 01-03 → rest 01-04..01-05 → covers 01-05.
    $affected = $t->newlyResting(1, 'night', Carbon::parse('2029-01-03'), 1);

    expect($affected)->toHaveCount(1);
    expect($affected[0])->toMatchArray(['assignment_id' => 11, 'date' => '2029-01-05', 'resting_until' => '2029-01-05']);
    expect($affected[0]['rule']->shift_type)->toBe('night');
    expect($t->wouldConflict(1, 'night', Carbon::parse('2029-01-03'), 1))->toBeTrue();
});

it('follows chain effects far beyond the candidate day', function () {
    // Rule night 3 → 2. Existing nights 01-10, 01-24, 01-25, 01-26.
    //   without: 01-10 c1, 01-24 c2, 01-25 c3 → rest 01-26..01-27 → 01-26 covered.
    //   with night 01-09: 01-09 c1, 01-10 c2, 01-24 c3 → rest 01-25..01-26 → 01-25 and 01-26 covered.
    // Only 01-25 is NEWLY covered (two weeks after the candidate); 01-26 already was.
    $t = new StreakTimeline(srlRules([['night', 3, 2]]), [1 => [
        rrcEvent(20, '2029-01-10', 'night'), rrcEvent(21, '2029-01-24', 'night'),
        rrcEvent(22, '2029-01-25', 'night'), rrcEvent(23, '2029-01-26', 'night'),
    ]]);

    $affected = $t->newlyResting(1, 'night', Carbon::parse('2029-01-09'), 1);

    expect(array_column($affected, 'assignment_id'))->toBe([22]);
    expect($affected[0]['resting_until'])->toBe('2029-01-26');
});

it('never reports assignments on or before the candidate day', function () {
    $t = new StreakTimeline(srlRules([['night', 1, 1]]), [1 => [
        rrcEvent(30, '2029-01-03', 'night', null, 2), rrcEvent(31, '2029-01-02', 'night'),
    ]]);

    // A night on 01-03 slot 1 opens 01-04; slot 2 on the same day and 01-02 are untouched.
    expect($t->newlyResting(1, 'night', Carbon::parse('2029-01-03'), 1))->toBe([]);
});

it('takes the candidate rest_policy into account', function () {
    // night 2 → 2. Existing nights 01-01, 01-02 (rest 01-03..01-04), 01-06.
    $events = [rrcEvent(40, '2029-01-01', 'night'), rrcEvent(41, '2029-01-02', 'night'), rrcEvent(42, '2029-01-06', 'night')];
    $t = new StreakTimeline(srlRules([['night', 2, 2]]), [1 => $events]);

    // Candidate on rest day 01-04: not_counted changes nothing; extends_rest → end 01-06 → covers 01-06.
    expect($t->newlyResting(1, 'night', Carbon::parse('2029-01-04'), 1, StreakTimeline::NOT_COUNTED))->toBe([]);
    expect(array_column($t->newlyResting(1, 'night', Carbon::parse('2029-01-04'), 1, StreakTimeline::EXTENDS_REST), 'assignment_id'))->toBe([42]);
});

it('lists the assignments currently covered by a rest window', function () {
    $t = new StreakTimeline(srlRules([['night', 2, 2]]), [1 => [
        rrcEvent(50, '2029-01-01', 'night'), rrcEvent(51, '2029-01-02', 'night'),
        rrcEvent(52, '2029-01-03', 'day'), rrcEvent(53, '2029-01-05', 'night'),
    ]]);

    expect($t->restingAssignments(1))->toBe([52 => '2029-01-04']);
    expect($t->restingAssignments(2))->toBe([]);
});

it('loads every date when until is null, with assignment ids', function () {
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $group->id, 'shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 2]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    $ids = [];
    foreach (['2029-01-01', '2029-01-02', '2031-06-01', '2031-06-02'] as $date) {
        $shift = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'night']);
        $ids[$date] = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id])->id;
    }
    $rest = Shift::create(['date' => '2029-01-03', 'shift_number' => 1, 'shift_group_id' => $group->id, 'shift_type' => 'day']);
    $restId = ShiftAssignment::create(['shift_id' => $rest->id, 'user_id' => $employee->id])->id;

    $timeline = app(StreakReplay::class)->forGroup($group, [$employee->id]);

    expect($timeline->restingAssignments($employee->id))->toBe([$restId => '2029-01-04']);
    // Far-future assignments are loaded: without the candidate, 2031-06-01 c1, 06-02 c2 (nothing covered);
    // with a night on 2031-05-31: 05-31 c1, 06-01 c2 → rest 06-02..06-03 → 06-02 newly covered.
    expect(array_column($timeline->newlyResting($employee->id, 'night', Carbon::parse('2031-05-31'), 1), 'assignment_id'))
        ->toBe([$ids['2031-06-02']]);
    expect($timeline->wouldConflict($employee->id, 'day', Carbon::parse('2029-01-04'), 1))->toBeTrue();
});
```

- [ ] **Step 2: Run** — `PEST --filter=StreakReplayTest` → new tests FAIL (unknown methods / `forGroup` arity).

- [ ] **Step 3: Implement**

In `StreakTimeline`:
- Store `assignment_id` on each mapped event (`'assignment_id' => isset($event['assignment_id']) ? (int) $event['assignment_id'] : null`); `append()` gets an optional trailing `?int $assignmentId = null` param stored the same way.
- Split the replay: `private function replay(int $userId, int $until): array` becomes a thin wrapper over `private function replayEvents(array $events, int $until): array`, which additionally returns `'covered' => array<int position, int windowIndex>` — for every processed event position whose day is covered by at least one open window, the index of the covering window with the latest end at that moment. Everything else in the loop is unchanged.
- Add:

```php
    /**
     * Existing assignments of the user that would newly fall inside a rest window if this candidate
     * assignment were added (a candidate on day D only affects days > D). Chain effects are included:
     * the whole history is replayed with and without the candidate.
     *
     * @return list<array{assignment_id: ?int, date: string, shift_number: int, shift_type: ?string, resting_until: string, rule: ShiftStreakRule}>
     */
    public function newlyResting(int $userId, ?string $shiftType, \Carbon\Carbon $date, int $shiftNumber, ?string $restPolicy = null): array
    {
        $events = $this->eventsByUser[$userId] ?? [];
        $day = self::dayNumber($date->toDateString());
        if ($this->rules->isEmpty() || $events === [] || end($events)['day'] <= $day) {
            return [];
        }

        $before = $this->replayEvents($events, PHP_INT_MAX)['covered'];

        $with = [];
        foreach ($events as $i => $event) {
            $with[] = $event + ['origin' => $i];
        }
        $with[] = ['day' => $day, 'shift_number' => $shiftNumber, 'shift_type' => $shiftType,
            'rest_policy' => $restPolicy, 'assignment_id' => null, 'origin' => -1];
        usort($with, [self::class, 'compareEvents']);
        $after = $this->replayEvents($with, PHP_INT_MAX);

        $affected = [];
        foreach ($after['covered'] as $pos => $windowIndex) {
            $origin = $with[$pos]['origin'];
            if ($origin < 0 || isset($before[$origin])) {
                continue;
            }
            $window = $after['windows'][$windowIndex];
            $affected[] = [
                'assignment_id' => $with[$pos]['assignment_id'],
                'date' => gmdate('Y-m-d', $with[$pos]['day'] * 86400),
                'shift_number' => $with[$pos]['shift_number'],
                'shift_type' => $with[$pos]['shift_type'],
                'resting_until' => gmdate('Y-m-d', $window['end'] * 86400),
                'rule' => $window['rule'],
            ];
        }

        return $affected;
    }

    public function wouldConflict(int $userId, ?string $shiftType, \Carbon\Carbon $date, int $shiftNumber): bool
    {
        return $this->isResting($userId, $date)
            || $this->newlyResting($userId, $shiftType, $date, $shiftNumber) !== [];
    }

    /** @return array<int, string> assignment_id => resting_until (Y-m-d) for every covered assignment */
    public function restingAssignments(int $userId): array
    {
        $events = $this->eventsByUser[$userId] ?? [];
        $state = $this->replayEvents($events, PHP_INT_MAX);
        $resting = [];
        foreach ($state['covered'] as $pos => $windowIndex) {
            if ($events[$pos]['assignment_id'] !== null) {
                $resting[$events[$pos]['assignment_id']] = gmdate('Y-m-d', $state['windows'][$windowIndex]['end'] * 86400);
            }
        }

        return $resting;
    }
```

`replayEvents()` uses `foreach ($events as $pos => $event)`; callers pass lists (0-based, sorted). `resting_until` reports the window's FINAL end (after later extends_rest), consistent with `statusAsOf`.

In `StreakReplay::forGroup()`: make `$until` nullable with default `null`; apply the date filter only when non-null; add `'shift_assignments.id as assignment_id'` to the selected columns and pass `'assignment_id' => (int) $row->assignment_id` into each event. Existing callers keep passing `$until`.

- [ ] **Step 4: Run** — `StreakReplayTest` green (incl. the unchanged existing tests and the perf guard). Sabotage: in `newlyResting` drop the `isset($before[$origin])` skip → the "lists only newly" expectation in the chain test fails (01-26 reported too); undo exactly. Full `PEST`, `PEST80`.

- [ ] **Step 5: Commit** — `feat: StreakTimeline reports which existing shifts a new shift would put into rest`

---

### Task 2: Manual assign asks about later shifts; schedule payload flags `in_rest`

**Files:**
- Modify: `backend/app/Http/Controllers/ScheduleController.php` (`assign()`, `index()`, `updateDayShifts()`)
- Test: create `backend/tests/Feature/RetroRestConflictTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `POST /api/schedule/assign` accepts `later_rest_policy` (`nullable|in:` POLICIES). After the existing `rest_window_conflict` check, if `newlyResting(...)` (computed with the effective `rest_policy` of the new assignment) is non-empty and `later_rest_policy` is not filled → **409** `{ code: 'creates_rest_conflict', message, affected: [{ assignment_id, date, shift_type, resting_until }], rule: { shift_type, streak_length, rest_days } }` (rule = the first affected entry's rule). With `later_rest_policy`: create the assignment and `ShiftAssignment::whereIn('id', affectedIds)->update(['rest_policy' => later])` in one `DB::transaction`. 201 body gains `updated_assignment_ids` (list<int>, `[]` when none) and `updated_rest_policy` (string|null).
  - Schedule payload (both `index()` maps and `updateDayShifts()`'s map): each assignment gains `in_rest` (bool: covered by a window AND `rest_policy === null`) and `resting_until` (string|null; set whenever covered).
- `assign()` loads the timeline once with `forGroup($group, [$userId])` (no `until` — `activeWindow` is still "as of" the date, and later assignments are needed for `newlyResting`).
- The payload uses one helper, e.g. `private function restingAssignmentMap(Collection $shifts, \Carbon\Carbon $until): array` (assignment_id ⇒ resting_until), which groups `$shifts` by `shift_group_id`, loads those groups' rules via `StreakReplay::forGroup($group, $userIdsInThoseShifts, $until)` (until = month end + 1 day is enough: coverage of a day depends only on earlier assignments), and merges `restingAssignments()` per user. Shifts without a group are skipped.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Feature/RetroRestConflictTest.php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\ShiftStreakRule;
use App\Models\User;

beforeEach(function () {
    $this->maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $this->group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    ShiftStreakRule::create(['shift_group_id' => $this->group->id, 'shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 2]);
    $this->employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    $this->shifts = [];
    foreach (['2029-01-02', '2029-01-03', '2029-01-04', '2029-01-05'] as $date) {
        $this->shifts[$date] = Shift::create(['date' => $date, 'shift_number' => 1, 'shift_group_id' => $this->group->id, 'shift_type' => 'night']);
    }
    // Existing: nights 01-02 and 01-05 (not a streak).
    $this->existing = [];
    foreach (['2029-01-02', '2029-01-05'] as $date) {
        $this->existing[$date] = ShiftAssignment::create(['shift_id' => $this->shifts[$date]->id, 'user_id' => $this->employee->id]);
    }
});

function rrcAssign($test, string $date, array $extra = [])
{
    return $test->actingAs($test->maintainer)->postJson('/api/schedule/assign', array_merge([
        'shift_id' => $test->shifts[$date]->id, 'user_id' => $test->employee->id,
    ], $extra));
}

it('answers 409 when the new shift would put an existing later shift into rest', function () {
    // Night 01-03 completes 2-in-a-row → rest 01-04..01-05 → the existing 01-05 night.
    rrcAssign($this, '2029-01-03')->assertStatus(409)->assertJson([
        'code' => 'creates_rest_conflict',
        'affected' => [['assignment_id' => $this->existing['2029-01-05']->id, 'date' => '2029-01-05', 'shift_type' => 'night', 'resting_until' => '2029-01-05']],
        'rule' => ['shift_type' => 'night', 'streak_length' => 2, 'rest_days' => 2],
    ]);

    expect(ShiftAssignment::where('shift_id', $this->shifts['2029-01-03']->id)->count())->toBe(0);
    expect($this->existing['2029-01-05']->fresh()->rest_policy)->toBeNull();
});

it('creates the assignment and stamps the chosen policy on the affected shifts when confirmed', function () {
    rrcAssign($this, '2029-01-03', ['later_rest_policy' => 'extends_rest'])
        ->assertCreated()
        ->assertJson(['updated_assignment_ids' => [$this->existing['2029-01-05']->id], 'updated_rest_policy' => 'extends_rest']);

    expect(ShiftAssignment::where('shift_id', $this->shifts['2029-01-03']->id)->count())->toBe(1);
    expect($this->existing['2029-01-05']->fresh()->rest_policy)->toBe('extends_rest');
});

it('rejects an unknown later_rest_policy', function () {
    rrcAssign($this, '2029-01-03', ['later_rest_policy' => 'nope'])->assertStatus(422)->assertJsonValidationErrors(['later_rest_policy']);
});

it('checks the dropped day itself first, and a not_counted rest-day assign affects nothing later', function () {
    // Make 01-03 a rest day: assign night 01-01 + 01-02 → rest 01-03..01-04.
    $first = Shift::create(['date' => '2029-01-01', 'shift_number' => 1, 'shift_group_id' => $this->group->id, 'shift_type' => 'night']);
    ShiftAssignment::create(['shift_id' => $first->id, 'user_id' => $this->employee->id]);

    rrcAssign($this, '2029-01-03')->assertStatus(409)->assertJson(['code' => 'rest_window_conflict']);
    rrcAssign($this, '2029-01-03', ['rest_policy' => 'not_counted'])->assertCreated()->assertJson(['updated_assignment_ids' => []]);
});

it('flags assignments that are on a rest day with no policy, and not those with one', function () {
    // Create the conflict by a direct insert (as a retype or rule edit would): 01-02 c1, 01-03 c2 → rest 01-04..01-05.
    ShiftAssignment::create(['shift_id' => $this->shifts['2029-01-03']->id, 'user_id' => $this->employee->id]);

    $payload = $this->actingAs($this->maintainer)->getJson("/api/schedule?month=2029-01&shift_group_id={$this->group->id}");
    $a5 = collect($payload->json('schedule.2029-01-05.0.assignments'))->firstWhere('user_id', $this->employee->id);
    expect($a5)->toMatchArray(['in_rest' => true, 'resting_until' => '2029-01-05']);
    $a3 = collect($payload->json('schedule.2029-01-03.0.assignments'))->firstWhere('user_id', $this->employee->id);
    expect($a3)->toMatchArray(['in_rest' => false, 'resting_until' => null]);

    $this->existing['2029-01-05']->update(['rest_policy' => 'not_counted']);
    $payload = $this->actingAs($this->maintainer)->getJson("/api/schedule?month=2029-01&shift_group_id={$this->group->id}");
    $a5 = collect($payload->json('schedule.2029-01-05.0.assignments'))->firstWhere('user_id', $this->employee->id);
    expect($a5)->toMatchArray(['in_rest' => false, 'resting_until' => '2029-01-05']);
});

it('flags in_rest after a retype makes an existing shift a rest day', function () {
    // 01-03 starts as a day shift with an assignment, then is retyped to night.
    $dayShift = $this->shifts['2029-01-03'];
    $dayShift->update(['shift_type' => 'day']);
    ShiftAssignment::create(['shift_id' => $dayShift->id, 'user_id' => $this->employee->id]);
    // No rest yet: 01-02 night c1, 01-03 day, 01-05 night c2 → window 01-06..01-07.
    $before = $this->actingAs($this->maintainer)->getJson("/api/schedule?month=2029-01&shift_group_id={$this->group->id}");
    expect(collect($before->json('schedule.2029-01-05.0.assignments'))->firstWhere('user_id', $this->employee->id)['in_rest'])->toBeFalse();

    // Retype 01-03 day → night through the real endpoint (see ScheduleController::updateShiftType for the route).
    $this->actingAs($this->maintainer)->putJson("/api/schedule/shifts/{$dayShift->id}/type", ['shift_type' => 'night'])->assertOk();

    $after = $this->actingAs($this->maintainer)->getJson("/api/schedule?month=2029-01&shift_group_id={$this->group->id}");
    expect(collect($after->json('schedule.2029-01-05.0.assignments'))->firstWhere('user_id', $this->employee->id)['in_rest'])->toBeTrue();
});
```

Check the real retype route in `backend/routes/api.php` (name it after `updateShiftType`) and the payload path in `schedule.<date>.<index>` before running; adapt route/path only.

- [ ] **Step 2: Run** — `PEST --filter=RetroRestConflictTest` → FAIL.

- [ ] **Step 3: Implement** — in `assign()`:
  - validation: add `'later_rest_policy' => ['nullable', 'in:'.implode(',', StreakTimeline::POLICIES)]`;
  - `$timeline = $shift->shiftGroup ? $streakReplay->forGroup($shift->shiftGroup, [$userId]) : null;` and `$window = $timeline ? $timeline->activeWindow($userId, $shift->date) : null;` (existing 409 block unchanged);
  - `$restPolicy = $window !== null ? $request->input('rest_policy') : null;`
  - `$affected = $timeline ? $timeline->newlyResting($userId, $shift->shift_type, $shift->date, $shift->shift_number, $restPolicy) : [];`
  - if `$affected !== [] && !$request->filled('later_rest_policy')` → 409 as specified; message e.g. `"Assigning this shift makes {$dates} a rest day for this employee ({$rule->shift_type}: {$rule->streak_length} in a row, then {$rule->rest_days} days off)."` where `$dates` is the affected dates joined with ", ";
  - otherwise `DB::transaction` creating the assignment (`rest_policy` => `$restPolicy`) and, when `$affected !== []`, updating the affected ids to `later_rest_policy`;
  - 201 body adds `updated_assignment_ids` and `updated_rest_policy` (`null` when none).
  - Payload: add the `restingAssignmentMap()` helper and use it in `index()` (both modes; month end + 1 day) and `updateDayShifts()` (the day's shifts; that day + 1). Add `'in_rest' => isset($map[$a->id]) && $a->rest_policy === null, 'resting_until' => $map[$a->id] ?? null` to each map.

- [ ] **Step 4: Run** — new file green; existing `ScheduleControllerStreakTest` green; full `PEST`, `PEST80`. Sabotage: skip the `creates_rest_conflict` return → test 1 fails; drop `&& $a->rest_policy === null` → the not_counted payload assertion fails; undo exactly.

- [ ] **Step 5: Commit** — `feat: manual assign asks before turning an existing later shift into a rest day; payload flags in_rest`

---

### Task 3: Auto-fill skips candidates that would put their own later shifts into rest

**Files:**
- Modify: `backend/app/Http/Controllers/ShiftAutoAssignController.php` (`random()`, `rotating()`, `getAvailableUsers()`, `polishLaw()`)
- Test: modify `backend/tests/Feature/AutoAssignStreakRestTest.php`; extend the perf guard in `StreakReplayTest.php`

**Interfaces:**
- Consumes: `StreakReplay::forGroup($group, $ids)` with no `until` (all dates — later existing assignments must be visible), `StreakTimeline::wouldConflict()`, `append()`.

- [ ] **Step 1: Write the failing tests**

In `AutoAssignStreakRestTest.php` add a dataset test over random / rotating / polish-law (copy the request payloads the file's existing tests use; for polish-law copy the payload shape from `PolishLawStreakTest.php`):
- rule night 2 → 2; one employee (the only candidate); empty night shifts (slot 1) on 2029-01-02, 01-03, 01-04; the employee already has a night assignment on 01-05 (pre-created); the month's other days have no shifts.
- Without the new check the mode would assign 01-02 and 01-03 (back-to-back permitting — check each mode's back-to-back rule; if it forbids 01-03 after 01-02, use rule night 2 → 3 with nights on 01-01 and 01-03 and the existing night on 01-05: 01-01 c1, 01-03 c2 → rest 01-04..01-06 covers 01-05).
- Expect the mode NOT to assign the shift that would complete the streak, and the existing 01-05 assignment to keep `rest_policy` null and stay uncovered: `app(StreakReplay::class)->forGroup($group, [$id])->restingAssignments($id)` is `[]`.

In `StreakReplayTest.php`, extend the perf-guard test (or add a sibling) so 30 users also have assignments AFTER the month being filled (e.g. history spans 3 years before and 2 months after), and time 2,700 `wouldConflict()` calls across the month: assert < 3 s and record the local time in the report.

- [ ] **Step 2: Run** — new cases FAIL (modes assign the completing shift).

- [ ] **Step 3: Implement** — in all three modes:
  - build the timeline with `forGroup($group, $ids)` (drop the `$end->copy()->addDay()` argument);
  - replace each `isResting($id, $shift->date)` eligibility check (rotating subteam filter, `getAvailableUsers()`, polishLaw) with `wouldConflict($id, $shift->shift_type, $shift->date, $shift->shift_number)`;
  - keep the `append()` calls (they go into the same timeline; `append()` already re-sorts when out of order).

- [ ] **Step 4: Run** — new tests and perf guard green; `PolishLawStreakTest`, `ShiftTypeLabellingTest` (auto-assign case) green; full `PEST`, `PEST80`. Sabotage: revert one mode's check to `isResting` → that mode's case fails; undo exactly.

- [ ] **Step 5: Commit** — `feat: auto-fill never turns an employee's existing later shift into a rest day`

---

### Task 4: Frontend — later-shift prompt and ⚠ chip (+ e2e)

**Files:**
- Modify: `frontend/src/components/RestDayPolicyModal.vue`, `frontend/src/views/ScheduleView.vue`
- Create: `frontend/e2e/schedule/retro-rest-conflict.spec.js`

**Interfaces:**
- Consumes: Task 2's 409 `creates_rest_conflict` body, `later_rest_policy`, 201 `updated_assignment_ids` / `updated_rest_policy`, payload `in_rest` / `resting_until`.
- Produces: `RestDayPolicyModal` gains an optional prop `affected: Array` (default `null`). When `affected` is set, the title is "This makes a later shift a rest day" and the text reads "Assigning {userName} here makes their {shift_type} on {dates} a rest day ({type}: N in a row, then R days off). How should {that shift | those shifts} count?"; `restingUntil` becomes optional. Options, Cancel, Escape unchanged.

- [ ] **Step 1: Write the e2e spec** (rebuild the backend first). Reuse the drag helper with the off-screen guard and the `Assign Anyway` handling from `e2e/schedule/rest-day-policy.spec.js` (copy, don't import across specs unless a shared fixture already exists). Months: 2029-03 (prompt), 2029-04 (⚠ after retype).
  - **Test A (2029-03):** group with rule night 2 → 2; generate the month; slot 1 typed night (`setShiftTypes`); API-assign Regular Employee to 03-02 and 03-05. Drag them onto 03-03 → (Back-to-Back modal → "Assign Anyway") → dialog shows "2029-03-05"; choose "Extends the rest"; wait for the 201; API check: a 03-03 assignment exists and the 03-05 assignment's `rest_policy` is `extends_rest`; the 03-05 chip shows the ⏸ marker after reload.
  - **Test A2 (same month, Cancel):** use a different day pair in 2029-03 (e.g. existing 03-20 and 03-23, drop on 03-21) → Cancel → no 03-21 assignment (API) and 03-23's `rest_policy` still null.
  - **Test B (2029-04):** rule night 2 → 2; slot 1 typed night, except 04-03 typed day (retype via the per-shift endpoint); API-assign 04-02, 04-03, 04-05 (no conflict: 04-02 c1, 04-05 c2). Retype 04-03 to night via the API. Reload; the 04-05 chip shows `getByTitle(/Now on a rest day/)`; the 04-02 chip doesn't (positive guard first that 04-02's chip is visible).

- [ ] **Step 2: Run** — FAIL (no dialog / no ⚠).

- [ ] **Step 3: Implement**
  - `doAssign(shift, item, index, restPolicy = null, laterRestPolicy = null)` sends `later_rest_policy` when given. On 409 `creates_rest_conflict`, open the prompt with `{ shift, item, index, conflict, restPolicy }` and return `'prompted'`. On success, if `response.data.updated_assignment_ids?.length`, set `rest_policy = updated_rest_policy` and `in_rest = false` on those chips across the loaded schedule (walk the schedule's days → shifts → assignments by id).
  - `chooseRestPolicy(policy)`: if `conflict.code === 'creates_rest_conflict'` call `doAssign(shift, item, index, prompt.restPolicy, policy)`, else `doAssign(shift, item, index, policy)` — a `rest_window_conflict` answered with a policy can be followed by a `creates_rest_conflict` prompt (the second 409 re-opens the prompt carrying the first answer). Success toasts as today.
  - Mount: pass `:affected="restPrompt.conflict.affected || null"` and `:resting-until="restPrompt.conflict.resting_until || ''"`.
  - Chip: next to the ⏸ marker add `<span v-if="assignment.in_rest" class="shrink-0 text-[9px] text-red-300" :title="'Now on a rest day (resting until ' + assignment.resting_until + '): nobody chose how it counts'">⚠</span>`.

- [ ] **Step 4: Run** — the new spec green; sabotage each test (don't send `later_rest_policy` → API check fails; remove the ⚠ span → Test B fails; in Cancel, create anyway… → use: skip the splice in cancel → the absence check fails); undo exactly. `npx playwright test e2e/schedule e2e/settlement-periods` green; `npm run test`; Biome on changed files.

- [ ] **Step 5: Commit** — `feat: schedule asks before making a later shift a rest day and marks shifts now on a rest day`

---

### Task 5: CHANGELOG and full verification

- [ ] **Step 1:** New top CHANGELOG entry (match the format of the current top entries), dated the day you write it: manual assign now also asks when a new shift would turn the employee's existing later shift into a rest day (same three choices, applied to that later shift); auto-fill never does it; shifts that end up on a rest day through a shift-type change or rule edit show ⚠ ("Now on a rest day … nobody chose how it counts"). API: `creates_rest_conflict` 409, `later_rest_policy`, payload `in_rest` / `resting_until`. Remove/adjust the previous entry's deploy-note sentence that said such shifts "aren't flagged" — they are flagged now (edit that sentence to say so).
- [ ] **Step 2: Verify** — `PEST` and `PEST80` full; rebuild backend; `cd frontend && npm run test` and `npm run test:e2e` twice back-to-back; nothing you started left on :5173.
- [ ] **Step 3: Commit** — `docs: changelog for retroactive rest-day conflicts`
