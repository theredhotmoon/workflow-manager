# Configurable Settlement Periods Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let each `ShiftGroup` configure a settlement-period mode (weekly/monthly/quarterly/custom) with an anchor date and a weekly hour cap, enforce that cap in `polishLaw()` against settlement-period-anchored weeks (not calendar weeks), and add a reporting page showing per-employee hours against that cap.

**Architecture:** A new pure, stateless `SettlementPeriodCalculator` service is the single source of truth for period/week math — no periods table, everything derives from a group's `(settlement_mode, settlement_anchor_date, settlement_custom_length_days, weekly_hour_cap)` config via date arithmetic. `ShiftAutoAssignController::polishLaw()` and a new `SettlementPeriodController` both consume it, so they can't disagree about where a week boundary falls or what a shift's duration is.

**Tech Stack:** Laravel 9 / Pest 1.x (backend, Postgres test DB), Vue 3 `<script setup>` / Vue Router 4 / Vitest (frontend). Carbon 2.73 (confirmed via `composer.lock`) — `addMonthsNoOverflow` is used for month/quarter arithmetic so an anchor on the 29th–31st doesn't skip or roll into the wrong month.

**Spec:** [docs/superpowers/specs/2026-09-07-settlement-periods-design.md](../specs/2026-09-07-settlement-periods-design.md)

## Global Constraints

- No periods table — everything is computed from the group's config via `SettlementPeriodCalculator`.
- Weeks are 7-day blocks anchored to the period's own start date, not calendar Monday–Sunday. The trailing partial week's cap is prorated: `round(weekly_hour_cap * days_in_week / 7, 2)`.
- The weekly hour cap replaces the *live* 48h hard block in `polishLaw()` (`ShiftAutoAssignController.php:662`), not the separate 40h/month "average" note (`:723-727`) — that note is removed entirely, not made configurable.
- No hour-cap enforcement anywhere outside `polishLaw()` — manual assignment (`ScheduleController::assign()`) and `random()`/`rotating()` stay untouched.
- No change to the month-based calendar/browsing UI anywhere (`ScheduleView.vue`'s month navigator, `MyShiftsView.vue`, `MyAvailabilityView.vue`, any `?month=` param).
- No persisted hours-worked ledger — the reporting endpoint computes hours live from `ShiftAssignment`/`Shift` on every request.
- Existing groups get a migration backfill of `settlement_mode = 'monthly'`, `weekly_hour_cap = 48`, `settlement_anchor_date = DATE(created_at)`. Correction from Task 2's review: this does NOT reproduce today's exact behavior bit-for-bit — today's `polishLaw()` buckets by ISO calendar week (Mon–Sun) and periods by the requested calendar month; the fallback instead anchors weeks/periods to the group's creation date, which is a different (but equally valid, and equally capped-at-48h) partition of days into weeks whenever a group wasn't created on the 1st of a month that's also a Monday. This is an accepted, deliberate consequence of the feature replacing ad-hoc calendar-week reasoning everywhere, including for unconfigured groups — not a defect to fix. The load-bearing guarantee that DOES hold: no employee can ever be assigned more than `weekly_hour_cap` (48 by default) hours within any single anchored week, matching the spirit (never the exact boundaries) of today's cap.

---

## Task 1: `shift_groups` settlement-config columns

**Files:**
- Create: `backend/database/migrations/2026_09_07_130000_add_settlement_config_to_shift_groups_table.php`
- Modify: `backend/app/Models/ShiftGroup.php`
- Test: `backend/tests/Feature/ShiftGroupSettlementConfigTest.php`

**Interfaces:**
- Produces: `ShiftGroup` gains fillable/cast attributes `settlement_mode` (string, one of `weekly`/`monthly`/`quarterly`/`custom`), `settlement_anchor_date` (Carbon date), `settlement_custom_length_days` (int, nullable), `weekly_hour_cap` (float, nullable). Task 2's `SettlementPeriodCalculator` reads these directly off a `ShiftGroup` instance.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\ShiftGroup;

it('leaves settlement fields null on a group created with none supplied', function () {
    // Note on scope: this only confirms the columns are nullable with no DB-level
    // default — it cannot exercise the migration's one-time backfill UPDATE, since
    // RefreshDatabase runs migrations against an empty table before any test creates
    // rows (the same limitation noted in sub-project 1's equivalent backfill test).
    // The calculator's own null-config fallback (tested in Task 2) is what actually
    // guarantees "no config" behaves like "monthly, 48h" at read time.
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    expect($group->settlement_mode)->toBeNull();
    expect($group->weekly_hour_cap)->toBeNull();
    expect($group->settlement_anchor_date)->toBeNull();
});

it('lets a group be created with explicit settlement config', function () {
    $group = ShiftGroup::create([
        'name' => 'Event Coverage',
        'sort_order' => 1,
        'settlement_mode' => 'quarterly',
        'settlement_anchor_date' => '2026-02-01',
        'weekly_hour_cap' => 40,
    ]);

    expect($group->settlement_mode)->toBe('quarterly');
    expect($group->settlement_anchor_date->toDateString())->toBe('2026-02-01');
    expect($group->weekly_hour_cap)->toBe(40.0);
    expect($group->settlement_custom_length_days)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php -d error_reporting=24575 vendor/bin/pest tests/Feature/ShiftGroupSettlementConfigTest.php`
Expected: FAIL — columns don't exist yet.

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
        Schema::table('shift_groups', function (Blueprint $table) {
            $table->enum('settlement_mode', ['weekly', 'monthly', 'quarterly', 'custom'])->nullable()->after('sort_order');
            $table->date('settlement_anchor_date')->nullable()->after('settlement_mode');
            $table->unsignedInteger('settlement_custom_length_days')->nullable()->after('settlement_anchor_date');
            $table->decimal('weekly_hour_cap', 5, 2)->nullable()->after('settlement_custom_length_days');
        });

        DB::statement(
            "UPDATE shift_groups SET settlement_mode = ?, weekly_hour_cap = ?, settlement_anchor_date = DATE(created_at) WHERE settlement_mode IS NULL",
            ['monthly', 48]
        );
    }

    public function down(): void
    {
        Schema::table('shift_groups', function (Blueprint $table) {
            $table->dropColumn(['settlement_mode', 'settlement_anchor_date', 'settlement_custom_length_days', 'weekly_hour_cap']);
        });
    }
};
```

- [ ] **Step 4: Update the model**

Replace `backend/app/Models/ShiftGroup.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftGroup extends Model
{
    protected $fillable = [
        'name',
        'description',
        'color',
        'is_default',
        'sort_order',
        'settlement_mode',
        'settlement_anchor_date',
        'settlement_custom_length_days',
        'weekly_hour_cap',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
        'settlement_anchor_date' => 'date:Y-m-d',
        'settlement_custom_length_days' => 'integer',
        'weekly_hour_cap' => 'float',
    ];

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && php -d error_reporting=24575 vendor/bin/pest tests/Feature/ShiftGroupSettlementConfigTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations/2026_09_07_130000_add_settlement_config_to_shift_groups_table.php backend/app/Models/ShiftGroup.php backend/tests/Feature/ShiftGroupSettlementConfigTest.php
git commit -m "feat: add settlement-period config columns to ShiftGroup"
```

---

## Task 2: `SettlementPeriodCalculator` — period and week math

**Files:**
- Create: `backend/app/Services/SettlementPeriodCalculator.php`
- Test: `backend/tests/Feature/SettlementPeriodCalculatorTest.php`

**Interfaces:**
- Consumes: `ShiftGroup` (Task 1) — reads `settlement_mode`/`settlement_anchor_date`/`settlement_custom_length_days`/`weekly_hour_cap` directly.
- Produces: `SettlementPeriodCalculator::periodContaining(ShiftGroup $group, Carbon $date): array`, `::periodByOffset(ShiftGroup $group, int $offset): array` — both return `['period_number' => int, 'start' => Carbon, 'end' => Carbon, 'weeks' => [['start' => Carbon, 'end' => Carbon, 'cap_hours' => float], ...]]`. `::weekFor(array $period, Carbon $date): array` returns `['index' => int, 'week' => [...]]`. Task 3's `shiftTiming()` lives on this same class. Task 5 (`polishLaw()`) and Task 6 (`SettlementPeriodController`) both call all of these by these exact names.

This is the highest-risk piece of the whole plan — every other task depends on this being correct. Write every test below before implementing; don't skip any.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\ShiftGroup;
use App\Services\SettlementPeriodCalculator;
use Carbon\Carbon;

function makeGroup(string $mode, string $anchor, float $cap = 40.0, ?int $customLength = null): ShiftGroup
{
    return ShiftGroup::create([
        'name' => 'Test Group',
        'sort_order' => 0,
        'settlement_mode' => $mode,
        'settlement_anchor_date' => $anchor,
        'settlement_custom_length_days' => $customLength,
        'weekly_hour_cap' => $cap,
    ]);
}

it('computes a 7-day weekly period containing a date, with one full-cap week', function () {
    $group = makeGroup('weekly', '2026-09-01'); // a Tuesday
    $calc = new SettlementPeriodCalculator();

    $period = $calc->periodContaining($group, Carbon::parse('2026-09-05'));

    expect($period['start']->toDateString())->toBe('2026-09-01');
    expect($period['end']->toDateString())->toBe('2026-09-07');
    expect($period['weeks'])->toHaveCount(1);
    expect($period['weeks'][0]['cap_hours'])->toBe(40.0);
});

it('computes weekly periods before and after the anchor via offset and by-date lookup consistently', function () {
    // periodByOffset is defined relative to the REAL current date, so freeze
    // "today" to a known date inside the anchor's first week (period 0) —
    // otherwise this test's expected dates would only hold on one specific
    // real-world day. Reset afterward so it doesn't leak into other tests.
    Carbon::setTestNow(Carbon::parse('2026-09-03'));

    $group = makeGroup('weekly', '2026-09-01');
    $calc = new SettlementPeriodCalculator();

    $viaOffset = $calc->periodByOffset($group, -1);
    $viaDate = $calc->periodContaining($group, Carbon::parse('2026-08-27'));

    expect($viaOffset['start']->toDateString())->toBe($viaDate['start']->toDateString());
    expect($viaOffset['end']->toDateString())->toBe($viaDate['end']->toDateString());
    expect($viaOffset['start']->toDateString())->toBe('2026-08-25');
    expect($viaOffset['end']->toDateString())->toBe('2026-08-31');

    Carbon::setTestNow();
});

it('anchors monthly periods to the anchor date, not calendar month boundaries', function () {
    $group = makeGroup('monthly', '2026-02-15');
    $calc = new SettlementPeriodCalculator();

    $period = $calc->periodContaining($group, Carbon::parse('2026-03-01'));

    expect($period['start']->toDateString())->toBe('2026-02-15');
    expect($period['end']->toDateString())->toBe('2026-03-14');
});

it('computes quarterly periods spanning 3 months from the anchor', function () {
    // Same freezing rationale as the weekly offset test above: periodByOffset
    // reads the real current date, so pin it inside the anchor's first quarter.
    Carbon::setTestNow(Carbon::parse('2026-03-15'));

    $group = makeGroup('quarterly', '2026-02-01');
    $calc = new SettlementPeriodCalculator();

    $current = $calc->periodContaining($group, Carbon::parse('2026-03-15'));
    $next = $calc->periodByOffset($group, 1);

    expect($current['start']->toDateString())->toBe('2026-02-01');
    expect($current['end']->toDateString())->toBe('2026-04-30');
    expect($next['start']->toDateString())->toBe('2026-05-01');
    expect($next['end']->toDateString())->toBe('2026-07-31');

    Carbon::setTestNow();
});

it('computes a custom-length period', function () {
    $group = makeGroup('custom', '2026-01-01', 40.0, 45);
    $calc = new SettlementPeriodCalculator();

    $period = $calc->periodContaining($group, Carbon::parse('2026-01-01'));

    expect($period['start']->toDateString())->toBe('2026-01-01');
    expect($period['end']->toDateString())->toBe('2026-02-14'); // 45 days inclusive
});

it('prorates the trailing partial week of a period not divisible by 7', function () {
    // Quarterly from 2026-02-01 to 2026-04-30 is 89 days = 12 full weeks + 5 leftover days.
    $group = makeGroup('quarterly', '2026-02-01', 40.0);
    $calc = new SettlementPeriodCalculator();

    $period = $calc->periodContaining($group, Carbon::parse('2026-02-01'));

    expect($period['weeks'])->toHaveCount(13);
    foreach (array_slice($period['weeks'], 0, 12) as $week) {
        expect($week['cap_hours'])->toBe(40.0);
    }
    $lastWeek = $period['weeks'][12];
    expect($lastWeek['start']->diffInDays($lastWeek['end']) + 1)->toBe(5);
    expect($lastWeek['cap_hours'])->toBe(round(40.0 * 5 / 7, 2));
});

it('finds which week a date falls into within a period', function () {
    $group = makeGroup('quarterly', '2026-02-01', 40.0);
    $calc = new SettlementPeriodCalculator();
    $period = $calc->periodContaining($group, Carbon::parse('2026-02-01'));

    $match = $calc->weekFor($period, Carbon::parse('2026-02-10'));

    expect($match['index'])->toBe(1); // second week: Feb 8-14
    expect($match['week']['start']->toDateString())->toBe('2026-02-08');
});

it('falls back to monthly/48h defaults when a group has no settlement config at all', function () {
    // The migration's backfill only ever ran once against rows that existed at
    // that moment — a freshly created group with no settlement fields supplied
    // naturally has them null (the columns carry no DB-level default), exercising
    // the calculator's own fallback rather than the migration's backfill.
    $group = ShiftGroup::create(['name' => 'Unconfigured', 'sort_order' => 0]);

    $calc = new SettlementPeriodCalculator();
    $period = $calc->periodContaining($group, Carbon::today());

    expect($period['weeks'][0]['cap_hours'])->toBeGreaterThan(0.0);
    expect($period['weeks'][0]['cap_hours'])->toBeLessThanOrEqual(48.0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php -d error_reporting=24575 vendor/bin/pest tests/Feature/SettlementPeriodCalculatorTest.php`
Expected: FAIL — class doesn't exist yet.

- [ ] **Step 3: Implement the calculator**

```php
<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\ShiftGroup;
use Carbon\Carbon;
use InvalidArgumentException;

class SettlementPeriodCalculator
{
    private const DEFAULT_MODE = 'monthly';
    private const DEFAULT_WEEKLY_HOUR_CAP = 48.0;

    public function periodContaining(ShiftGroup $group, Carbon $date): array
    {
        [$mode, $anchor, $customLength, $weeklyCap] = $this->resolveConfig($group);
        $date = $date->copy()->startOfDay();
        $periodNumber = $this->periodNumberForDate($mode, $anchor, $customLength, $date);

        return $this->buildPeriod($mode, $anchor, $customLength, $weeklyCap, $periodNumber);
    }

    public function periodByOffset(ShiftGroup $group, int $offset): array
    {
        [$mode, $anchor, $customLength, $weeklyCap] = $this->resolveConfig($group);
        $today = Carbon::today();
        $currentPeriodNumber = $this->periodNumberForDate($mode, $anchor, $customLength, $today);

        return $this->buildPeriod($mode, $anchor, $customLength, $weeklyCap, $currentPeriodNumber + $offset);
    }

    public function weekFor(array $period, Carbon $date): array
    {
        $date = $date->copy()->startOfDay();
        foreach ($period['weeks'] as $index => $week) {
            if ($date->between($week['start'], $week['end'])) {
                return ['index' => $index, 'week' => $week];
            }
        }

        throw new InvalidArgumentException('Date does not fall within the given period.');
    }

    public function shiftTiming(Shift $shift): array
    {
        $dateStr = $shift->date->format('Y-m-d');
        $startTime = $shift->start_time ? Carbon::parse($dateStr . ' ' . $shift->start_time) : Carbon::parse($dateStr . ' 06:00');
        $endTime = $shift->end_time ? Carbon::parse($dateStr . ' ' . $shift->end_time) : Carbon::parse($dateStr . ' 14:00');

        if ($endTime->lte($startTime)) {
            $endTime->addDay();
        }

        return [
            'start' => $startTime,
            'end' => $endTime,
            'duration_hours' => $startTime->diffInMinutes($endTime) / 60,
        ];
    }

    private function resolveConfig(ShiftGroup $group): array
    {
        $mode = $group->settlement_mode ?? self::DEFAULT_MODE;
        $anchor = $group->settlement_anchor_date
            ? $group->settlement_anchor_date->copy()->startOfDay()
            : $group->created_at->copy()->startOfDay();
        $customLength = $group->settlement_custom_length_days;
        $weeklyCap = $group->weekly_hour_cap ?? self::DEFAULT_WEEKLY_HOUR_CAP;

        if ($mode === 'custom' && !$customLength) {
            throw new InvalidArgumentException('Custom settlement mode requires settlement_custom_length_days.');
        }

        return [$mode, $anchor, $customLength, (float) $weeklyCap];
    }

    private function fixedLengthDays(string $mode, ?int $customLength): ?int
    {
        return match ($mode) {
            'weekly' => 7,
            'custom' => $customLength,
            default => null,
        };
    }

    private function periodNumberForDate(string $mode, Carbon $anchor, ?int $customLength, Carbon $date): int
    {
        if (in_array($mode, ['weekly', 'custom'], true)) {
            $lengthDays = $this->fixedLengthDays($mode, $customLength);
            $diffDays = $anchor->diffInDays($date, false);

            return intdiv($diffDays >= 0 ? $diffDays : $diffDays - $lengthDays + 1, $lengthDays);
        }

        $monthsPerPeriod = $mode === 'quarterly' ? 3 : 1;
        $diffMonths = $anchor->diffInMonths($date, false);
        $periodNumber = intdiv($diffMonths >= 0 ? $diffMonths : $diffMonths - $monthsPerPeriod + 1, $monthsPerPeriod);

        while ($anchor->copy()->addMonthsNoOverflow($periodNumber * $monthsPerPeriod)->gt($date)) {
            $periodNumber--;
        }
        while ($anchor->copy()->addMonthsNoOverflow(($periodNumber + 1) * $monthsPerPeriod)->lte($date)) {
            $periodNumber++;
        }

        return $periodNumber;
    }

    private function buildPeriod(string $mode, Carbon $anchor, ?int $customLength, float $weeklyCap, int $periodNumber): array
    {
        if (in_array($mode, ['weekly', 'custom'], true)) {
            $lengthDays = $this->fixedLengthDays($mode, $customLength);
            $start = $anchor->copy()->addDays($periodNumber * $lengthDays);
            $end = $start->copy()->addDays($lengthDays - 1);
        } else {
            $monthsPerPeriod = $mode === 'quarterly' ? 3 : 1;
            $start = $anchor->copy()->addMonthsNoOverflow($periodNumber * $monthsPerPeriod);
            $end = $anchor->copy()->addMonthsNoOverflow(($periodNumber + 1) * $monthsPerPeriod)->subDay();
        }

        return [
            'period_number' => $periodNumber,
            'start' => $start,
            'end' => $end,
            'weeks' => $this->buildWeeks($start, $end, $weeklyCap),
        ];
    }

    private function buildWeeks(Carbon $start, Carbon $end, float $weeklyCap): array
    {
        $weeks = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $weekEnd = $cursor->copy()->addDays(6);
            if ($weekEnd->gt($end)) {
                $weekEnd = $end->copy();
            }
            $daysInWeek = $cursor->diffInDays($weekEnd) + 1;
            $weeks[] = [
                'start' => $cursor->copy(),
                'end' => $weekEnd->copy(),
                'cap_hours' => round($weeklyCap * $daysInWeek / 7, 2),
            ];
            $cursor = $weekEnd->copy()->addDay();
        }

        return $weeks;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php -d error_reporting=24575 vendor/bin/pest tests/Feature/SettlementPeriodCalculatorTest.php`
Expected: PASS (all 8 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/SettlementPeriodCalculator.php backend/tests/Feature/SettlementPeriodCalculatorTest.php
git commit -m "feat: add SettlementPeriodCalculator for period/week math"
```

---

## Task 3: Wire `SettlementPeriodCalculator` into `ShiftGroupController`

**Files:**
- Modify: `backend/app/Http/Controllers/ShiftGroupController.php`
- Test: `backend/tests/Feature/ShiftGroupControllerSettlementTest.php`

**Interfaces:**
- Consumes: `ShiftGroup` fillable fields from Task 1.
- Produces: `ShiftGroupController::store()`/`update()` now accept and validate `settlement_mode`, `settlement_anchor_date`, `settlement_custom_length_days`, `weekly_hour_cap`. Task 7 (frontend group-settings modal) sends these exact field names to `PUT /shift-groups/{id}`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\ShiftGroup;
use App\Models\User;

it('lets an admin set settlement config when creating a group', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->postJson('/api/shift-groups', [
        'name' => 'Event Coverage',
        'settlement_mode' => 'quarterly',
        'settlement_anchor_date' => '2026-02-01',
        'weekly_hour_cap' => 40,
    ]);

    $response->assertCreated();
    expect($response->json('settlement_mode'))->toBe('quarterly');
    expect($response->json('weekly_hour_cap'))->toBe(40.0);
});

it('requires settlement_custom_length_days when mode is custom', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->postJson('/api/shift-groups', [
        'name' => 'Custom Group',
        'settlement_mode' => 'custom',
        'settlement_anchor_date' => '2026-01-01',
        'weekly_hour_cap' => 40,
    ])->assertStatus(422)->assertJsonValidationErrors(['settlement_custom_length_days']);
});

it('lets an admin update settlement config on an existing group', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    $response = $this->actingAs($admin)->putJson("/api/shift-groups/{$group->id}", [
        'settlement_mode' => 'weekly',
        'settlement_anchor_date' => '2026-09-01',
        'weekly_hour_cap' => 36,
    ]);

    $response->assertOk();
    expect($response->json('settlement_mode'))->toBe('weekly');
    expect($response->json('weekly_hour_cap'))->toBe(36.0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php -d error_reporting=24575 vendor/bin/pest tests/Feature/ShiftGroupControllerSettlementTest.php`
Expected: FAIL — new fields not accepted/validated yet.

- [ ] **Step 3: Update the controller**

In `backend/app/Http/Controllers/ShiftGroupController.php`, update `store()`:

```php
    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', 'string', 'max:7'],
            'settlement_mode' => ['sometimes', 'nullable', 'in:weekly,monthly,quarterly,custom'],
            'settlement_anchor_date' => ['sometimes', 'nullable', 'date'],
            'settlement_custom_length_days' => ['required_if:settlement_mode,custom', 'nullable', 'integer', 'min:1'],
            'weekly_hour_cap' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $maxOrder = ShiftGroup::max('sort_order') ?? 0;

        $group = ShiftGroup::create([
            'name' => $request->name,
            'description' => $request->description,
            'color' => $request->color,
            'sort_order' => $maxOrder + 1,
            'settlement_mode' => $request->settlement_mode,
            'settlement_anchor_date' => $request->settlement_anchor_date,
            'settlement_custom_length_days' => $request->settlement_custom_length_days,
            'weekly_hour_cap' => $request->weekly_hour_cap,
        ]);

        return response()->json($group, 201);
    }
```

And `update()`:

```php
    public function update(Request $request, ShiftGroup $shiftGroup)
    {
        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', 'string', 'max:7'],
            'settlement_mode' => ['sometimes', 'nullable', 'in:weekly,monthly,quarterly,custom'],
            'settlement_anchor_date' => ['sometimes', 'nullable', 'date'],
            'settlement_custom_length_days' => ['required_if:settlement_mode,custom', 'nullable', 'integer', 'min:1'],
            'weekly_hour_cap' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $shiftGroup->update($request->only([
            'name', 'description', 'color',
            'settlement_mode', 'settlement_anchor_date', 'settlement_custom_length_days', 'weekly_hour_cap',
        ]));

        return response()->json($shiftGroup);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php -d error_reporting=24575 vendor/bin/pest tests/Feature/ShiftGroupControllerSettlementTest.php`
Expected: PASS

- [ ] **Step 5: Run the full backend suite to check for regressions**

Run: `cd backend && php -d error_reporting=24575 vendor/bin/pest`
Expected: PASS (the existing inline `saveGroupRename` frontend flow sends only `{name}`, which the now-`sometimes` settlement fields correctly ignore — no regression to the existing rename-only PUT calls)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/ShiftGroupController.php backend/tests/Feature/ShiftGroupControllerSettlementTest.php
git commit -m "feat: accept settlement config on ShiftGroup create/update"
```

---

## Task 4: Rework `polishLaw()` to enforce the settlement-period-anchored weekly cap

**Files:**
- Modify: `backend/app/Http/Controllers/ShiftAutoAssignController.php:519-757`
- Test: `backend/tests/Feature/PolishLawSettlementPeriodTest.php`

**Interfaces:**
- Consumes: `SettlementPeriodCalculator::periodContaining()`/`weekFor()`/`shiftTiming()` (Task 2), `ShiftGroup` settlement fields (Task 1).
- Produces: `polishLaw()`'s response no longer includes the removed 40h-average compliance notes; its live per-week cap now reads from the group's `weekly_hour_cap`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\User;

function makeShiftForPolishLaw(int $groupId, string $date, int $shiftNumber, string $start, string $end): Shift
{
    return Shift::create([
        'date' => $date,
        'shift_number' => $shiftNumber,
        'shift_group_id' => $groupId,
        'start_time' => $start,
        'end_time' => $end,
    ]);
}

it('blocks assignment once a configured weekly_hour_cap lower than 48 would be exceeded', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'monthly', 'settlement_anchor_date' => '2026-09-01', 'weekly_hour_cap' => 24,
    ]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    // Three 12h shifts in the same anchored week (Sep 1-7): a 24h cap allows only 2 before blocking the 3rd.
    makeShiftForPolishLaw($group->id, '2026-09-01', 1, '06:00', '18:00');
    makeShiftForPolishLaw($group->id, '2026-09-02', 1, '06:00', '18:00');
    makeShiftForPolishLaw($group->id, '2026-09-03', 1, '06:00', '18:00');

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
    expect($response->json('total_assigned'))->toBe(2);
});

it('does not include the removed 40h-average compliance note in the response', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);
    makeShiftForPolishLaw($group->id, '2026-09-01', 1, '06:00', '18:00');

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
    foreach ($response->json('warnings') as $warning) {
        expect($warning)->not->toContain('exceeds 40h avg');
        expect($warning)->not->toContain('exceeds 48h cap');
    }
});

it('caps at 48h/week (the fallback default) for a group with no settlement config', function () {
    // The fallback anchor is the group's created_at — i.e. whenever this test
    // actually runs in real wall-clock time. Freeze "now" to 2026-09-01 so the
    // resulting monthly period (and its first 7-day week) starts exactly on
    // 2026-09-01, deterministically containing all 5 test shift dates below in
    // one week bucket — without this freeze, which anchored week each shift
    // falls into (and therefore whether the cap accumulates across all 5, or
    // splits across two weeks) would depend on whatever real date the test
    // suite happens to run on.
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-09-01'));

    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);
    $employee = User::factory()->create(['role' => 'employee', 'is_admin' => false]);

    // 5 consecutive 12h days = 60h, exceeding the default 48h cap partway through.
    foreach (range(1, 5) as $i) {
        makeShiftForPolishLaw($group->id, "2026-09-0{$i}", 1, '06:00', '18:00');
    }

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
    // 48h / 12h per shift = 4 shifts max before the cap blocks the 5th.
    expect($response->json('total_assigned'))->toBe(4);

    \Illuminate\Support\Carbon::setTestNow();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php -d error_reporting=24575 vendor/bin/pest tests/Feature/PolishLawSettlementPeriodTest.php`
Expected: FAIL — `polishLaw()` still uses the hardcoded 48h calendar-week check.

- [ ] **Step 3: Rewrite `polishLaw()`**

Replace the entire method body of `ShiftAutoAssignController::polishLaw()` (`backend/app/Http/Controllers/ShiftAutoAssignController.php:519-757`) with:

```php
    public function polishLaw(Request $request)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'shift_group_id' => ['sometimes', 'integer', 'exists:shift_groups,id'],
            'users_per_shift_weekday' => ['required', 'integer', 'min:1', 'max:20'],
            'users_per_shift_weekend' => ['required', 'integer', 'min:1', 'max:20'],
            'rotation_days' => ['required', 'integer', 'min:1', 'max:31'],
            'player_mode' => ['sometimes', 'in:all,include,exclude'],
            'player_ids' => ['sometimes', 'array'],
            'player_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $start = Carbon::createFromFormat('Y-m', $request->month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $groupId = $request->input('shift_group_id') ?? ShiftGroup::where('is_default', true)->value('id');
        $group = ShiftGroup::findOrFail($groupId);
        $calculator = new \App\Services\SettlementPeriodCalculator();

        $shifts = Shift::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('shift_group_id', $groupId)
            ->orderBy('date')
            ->orderBy('shift_number')
            ->with('assignments')
            ->get();

        if ($shifts->isEmpty()) {
            return response()->json(['message' => 'No shifts found for this month. Generate shifts first.'], 422);
        }

        $employees = User::where('is_admin', false)->orderBy('name')->get();
        $employees = $this->applyPlayerFilter($employees, $request);

        if ($employees->isEmpty()) {
            return response()->json(['message' => 'No employees found to assign.'], 422);
        }

        $blockedMap = $this->buildBlockedMap($employees->pluck('id')->toArray(), $request->month);

        // Pre-compute each distinct date's settlement-anchored week: a period-qualified
        // key (so weeks from different periods never collide), the week's hour cap,
        // and whether the date is the last day of its anchored week (used below in
        // place of the old "is it Sunday" heuristic).
        $weekKeyByDate = [];
        $weekCapByDate = [];
        $weekEndByDate = [];
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

        // Build shift time info
        $shiftTimes = [];
        foreach ($shifts as $shift) {
            $timing = $calculator->shiftTiming($shift);

            $shiftTimes[$shift->id] = [
                'start' => $timing['start'],
                'end' => $timing['end'],
                'duration_minutes' => $timing['duration_hours'] * 60,
                'duration_hours' => $timing['duration_hours'],
                'is_night' => $this->isNightShift($timing['start'], $timing['end']),
            ];
        }

        // Track per-employee state
        $employeeState = [];
        foreach ($employees as $emp) {
            $employeeState[$emp->id] = [
                'last_shift_end' => null,        // Carbon of last shift end
                'weekly_hours' => [],             // weekKey => hours
                'weekly_days' => [],              // weekKey => [dates worked]
                'total_hours' => 0,
                'total_shifts' => 0,
                'last_rest_start' => null,        // When last rest period began (for 35h weekly check)
            ];
        }

        // Track assignments
        $assignmentTracker = [];
        foreach ($shifts as $shift) {
            $dateStr = $shift->date->format('Y-m-d');
            $key = $dateStr . ':' . $shift->shift_number;
            $assignmentTracker[$key] = $shift->assignments->pluck('user_id')->toArray();

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
                }
            }
        }

        $totalAssigned = 0;
        $warnings = [];

        foreach ($shifts as $shift) {
            $dateStr = $shift->date->format('Y-m-d');
            $dayOfWeek = $shift->date->dayOfWeekIso;
            $isWeekend = $dayOfWeek >= 6;
            $needed = $isWeekend ? $request->users_per_shift_weekend : $request->users_per_shift_weekday;
            $weekNum = $weekKeyByDate[$dateStr];
            $weekCap = $weekCapByDate[$dateStr];

            $currentKey = $dateStr . ':' . $shift->shift_number;
            $alreadyAssigned = $assignmentTracker[$currentKey] ?? [];
            $spotsToFill = $needed - count($alreadyAssigned);

            if ($spotsToFill <= 0)
                continue;

            if (!isset($shiftTimes[$shift->id]))
                continue;
            $st = $shiftTimes[$shift->id];

            // Check shift doesn't exceed 13h
            if ($st['duration_hours'] > 13) {
                $warnings[] = "{$dateStr} S{$shift->shift_number}: shift exceeds 13h limit ({$st['duration_hours']}h)";
                continue;
            }

            // Build list of eligible employees for this shift
            $candidates = [];
            foreach ($employees as $emp) {
                if (in_array($emp->id, $alreadyAssigned))
                    continue;
                if ($this->isUserBlockedOnDate($emp->id, $dateStr, $blockedMap))
                    continue;
                if ($this->isAlreadyAssignedOnDate($emp->id, $dateStr, $shift->shift_number, $assignmentTracker))
                    continue;

                $state = $employeeState[$emp->id];

                // Rule: 11h rest since last shift
                if ($state['last_shift_end'] !== null) {
                    $restHours = $state['last_shift_end']->diffInMinutes($st['start']) / 60;
                    if ($restHours < 11)
                        continue;
                }

                // Rule: max weekly_hour_cap this settlement-anchored week
                $weekHours = $state['weekly_hours'][$weekNum] ?? 0;
                if ($weekHours + $st['duration_hours'] > $weekCap)
                    continue;

                // Rule: max 5 working days this week
                $weekDays = $state['weekly_days'][$weekNum] ?? [];
                if (!isset($weekDays[$dateStr]) && count($weekDays) >= 5)
                    continue;

                // Rule: 35h weekly rest — check if employee had at least 35h continuous rest this week
                // (We enforce this by checking if the last day of the anchored week is free when
                //  possible; the check below prevents assigning every day of the week without a break.)
                if ($weekEndByDate[$dateStr]) {
                    if (count($weekDays) >= 5)
                        continue;
                }

                // Scoring: prefer employees with fewer hours (fairness)
                $score = $state['total_hours'];

                $candidates[] = ['id' => $emp->id, 'score' => $score];
            }

            // Sort by fewest hours first (fair distribution)
            usort($candidates, fn($a, $b) => $a['score'] <=> $b['score']);

            $assigned = 0;
            foreach ($candidates as $c) {
                if ($assigned >= $spotsToFill)
                    break;

                $userId = $c['id'];

                ShiftAssignment::create([
                    'shift_id' => $shift->id,
                    'user_id' => $userId,
                ]);

                $assignmentTracker[$currentKey][] = $userId;
                $employeeState[$userId]['last_shift_end'] = $st['end'];
                $employeeState[$userId]['total_hours'] += $st['duration_hours'];
                $employeeState[$userId]['total_shifts']++;
                $employeeState[$userId]['weekly_hours'][$weekNum] = ($employeeState[$userId]['weekly_hours'][$weekNum] ?? 0) + $st['duration_hours'];
                $employeeState[$userId]['weekly_days'][$weekNum][$dateStr] = true;

                $assigned++;
                $totalAssigned++;
            }

            if ($assigned < $spotsToFill) {
                $warnings[] = "Could only assign {$assigned}/{$spotsToFill} to {$dateStr} S{$shift->shift_number} (labor code limits)";
            }
        }

        // Count night shifts
        $nightShiftCount = 0;
        foreach ($shifts as $shift) {
            if (isset($shiftTimes[$shift->id]) && $shiftTimes[$shift->id]['is_night']) {
                $nightShiftCount++;
            }
        }

        $message = "Auto-assigned {$totalAssigned} slots (Polish Labor Code mode). {$nightShiftCount} night shifts identified.";
        if (count($warnings) > 0) {
            $message .= ' ' . count($warnings) . ' shifts could not be fully filled.';
        }

        return response()->json([
            'message' => $message,
            'total_assigned' => $totalAssigned,
            'warnings' => $warnings,
        ]);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php -d error_reporting=24575 vendor/bin/pest tests/Feature/PolishLawSettlementPeriodTest.php`
Expected: PASS

- [ ] **Step 5: Run the full backend suite**

Run: `cd backend && php -d error_reporting=24575 vendor/bin/pest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/ShiftAutoAssignController.php backend/tests/Feature/PolishLawSettlementPeriodTest.php
git commit -m "feat: enforce settlement-period-anchored weekly hour cap in polishLaw()"
```

---

## Task 5: `SettlementPeriodController` — per-employee hours report endpoint

**Files:**
- Create: `backend/app/Http/Controllers/SettlementPeriodController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/SettlementPeriodControllerTest.php`

**Interfaces:**
- Consumes: `SettlementPeriodCalculator::periodByOffset()`/`weekFor()`/`shiftTiming()` (Task 2).
- Produces: `GET /settlement-periods?shift_group_id=X&offset=N` → `{period: {period_number, start, end}, weeks: [{start, end, cap_hours}], employees: [{id, name, weekly_hours: [...], total_hours, over_cap_weeks: [...]}]}`. Task 8 (frontend `SettlementPeriodsView.vue`) consumes this exact shape.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Models\User;

it('reports per-employee hours for the current settlement period, flagging over-cap weeks', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $employee = User::factory()->create(['name' => 'Jamie Employee']);
    $group = ShiftGroup::create([
        'name' => 'Main', 'is_default' => true, 'sort_order' => 0,
        'settlement_mode' => 'weekly', 'settlement_anchor_date' => now()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString(),
        'weekly_hour_cap' => 20,
    ]);

    $shift = Shift::create([
        'date' => now()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString(),
        'shift_number' => 1,
        'shift_group_id' => $group->id,
        'start_time' => '06:00',
        'end_time' => '18:00', // 12h — over a 20h... no, under; use two shifts to exceed
    ]);
    ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id]);

    $shift2 = Shift::create([
        'date' => now()->startOfWeek(\Carbon\Carbon::MONDAY)->addDay()->toDateString(),
        'shift_number' => 1,
        'shift_group_id' => $group->id,
        'start_time' => '06:00',
        'end_time' => '18:00',
    ]);
    ShiftAssignment::create(['shift_id' => $shift2->id, 'user_id' => $employee->id]);

    $response = $this->actingAs($maintainer)->getJson("/api/settlement-periods?shift_group_id={$group->id}&offset=0");

    $response->assertOk();
    expect($response->json('employees.0.name'))->toBe('Jamie Employee');
    expect($response->json('employees.0.total_hours'))->toBe(24.0);
    expect($response->json('employees.0.over_cap_weeks'))->toBe([0]);
});

it('rejects a plain employee', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    $this->actingAs($employee)
        ->getJson("/api/settlement-periods?shift_group_id={$group->id}")
        ->assertStatus(403);
});

it('returns an empty employees list for a period with no assignments yet', function () {
    $maintainer = User::factory()->create(['role' => 'schedule_maintainer']);
    $group = ShiftGroup::create(['name' => 'Main', 'is_default' => true, 'sort_order' => 0]);

    $response = $this->actingAs($maintainer)->getJson("/api/settlement-periods?shift_group_id={$group->id}");

    $response->assertOk();
    expect($response->json('employees'))->toBe([]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php -d error_reporting=24575 vendor/bin/pest tests/Feature/SettlementPeriodControllerTest.php`
Expected: FAIL — controller/route don't exist yet.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\ShiftAssignment;
use App\Models\ShiftGroup;
use App\Services\SettlementPeriodCalculator;
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
        $period = $calculator->periodByOffset($group, $offset);

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
            $employees[] = [
                'id' => $userId,
                'name' => $name,
                'weekly_hours' => $weeklyHours,
                'total_hours' => round(array_sum($weeklyHours), 2),
                'over_cap_weeks' => $overCapWeeks,
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
        ]);
    }
}
```

- [ ] **Step 4: Register the route**

In `backend/routes/api.php`, add the import `use App\Http\Controllers\SettlementPeriodController;` alongside the other controller imports, and add inside the existing `admin:schedule` middleware group (`backend/routes/api.php:319-334`, next to `polish-law`):

```php
        Route::get('/settlement-periods', [SettlementPeriodController::class, 'show']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd backend && php -d error_reporting=24575 vendor/bin/pest tests/Feature/SettlementPeriodControllerTest.php`
Expected: PASS

- [ ] **Step 6: Run the full backend suite**

Run: `cd backend && php -d error_reporting=24575 vendor/bin/pest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/SettlementPeriodController.php backend/routes/api.php backend/tests/Feature/SettlementPeriodControllerTest.php
git commit -m "feat: add settlement-period per-employee hours report endpoint"
```

---

## Task 6: Frontend — group settlement-settings modal in `ScheduleView.vue`

**Files:**
- Modify: `frontend/src/views/ScheduleView.vue`

**Interfaces:**
- Consumes: `PUT /shift-groups/{id}` accepting `settlement_mode`/`settlement_anchor_date`/`settlement_custom_length_days`/`weekly_hour_cap` (Task 3).

- [ ] **Step 1: Add a settings button next to each group tab**

In `frontend/src/views/ScheduleView.vue`, in the group tab button block (around line 87-93, right after the existing delete button, still inside the `<button v-for="group in shiftGroups">` element and gated the same way as delete — `canManageSchedule && activeGroupId === group.id`, but shown regardless of `is_default` since every group, including the default one, needs settlement configuration):

```html
            <!-- Group settlement settings (admin/schedule_maintainer) -->
            <button v-if="canManageSchedule && activeGroupId === group.id"
              @click.stop="openGroupSettings(group)"
              class="w-4 h-4 flex items-center justify-center text-slate-500 hover:text-indigo-400 transition-colors ml-1"
              title="Settlement period settings">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </button>
```

- [ ] **Step 2: Add the modal template**

Add this modal near the other modals in the file (e.g. right after the "Shift Times" modal's closing `</Transition>`, following the exact same `Transition`/backdrop/panel structure used there):

```html
    <!-- Group Settlement Settings Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="showGroupSettingsModal" class="fixed inset-0 z-50 flex items-center justify-center px-4" @click.self="showGroupSettingsModal = false">
        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showGroupSettingsModal = false"></div>
        <div class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 p-6">
          <h3 class="text-white font-bold text-lg mb-1">Settlement Period Settings</h3>
          <p class="text-slate-400 text-sm mb-5">{{ groupSettingsTarget?.name }}</p>

          <div class="mb-4">
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Mode</label>
            <select v-model="groupSettingsForm.settlement_mode"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
              <option value="quarterly">Quarterly</option>
              <option value="custom">Custom</option>
            </select>
          </div>

          <div class="mb-4">
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Anchor Date</label>
            <input v-model="groupSettingsForm.settlement_anchor_date" type="date"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
          </div>

          <div v-if="groupSettingsForm.settlement_mode === 'custom'" class="mb-4">
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Custom Length (days)</label>
            <input v-model.number="groupSettingsForm.settlement_custom_length_days" type="number" min="1"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
          </div>

          <div class="mb-5">
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Weekly Hour Cap</label>
            <input v-model.number="groupSettingsForm.weekly_hour_cap" type="number" min="0" step="0.5"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
          </div>

          <div class="flex items-center justify-end gap-3">
            <button @click="showGroupSettingsModal = false" class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">Cancel</button>
            <button @click="saveGroupSettings" :disabled="savingGroupSettings"
              class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
              <svg v-if="savingGroupSettings" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
              Save
            </button>
          </div>
        </div>
      </div>
    </Transition>
```

- [ ] **Step 3: Add the script state and functions**

Near `editingGroupId`/`editingGroupName` (around line 1226-1227), add:

```js
const showGroupSettingsModal = ref(false);
const savingGroupSettings = ref(false);
const groupSettingsTarget = ref(null);
const groupSettingsForm = ref({
  settlement_mode: 'monthly',
  settlement_anchor_date: '',
  settlement_custom_length_days: null,
  weekly_hour_cap: 48,
});

function openGroupSettings(group) {
  groupSettingsTarget.value = group;
  groupSettingsForm.value = {
    settlement_mode: group.settlement_mode || 'monthly',
    settlement_anchor_date: group.settlement_anchor_date || '',
    settlement_custom_length_days: group.settlement_custom_length_days ?? null,
    weekly_hour_cap: group.weekly_hour_cap ?? 48,
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
      settlement_custom_length_days: groupSettingsForm.value.settlement_mode === 'custom'
        ? groupSettingsForm.value.settlement_custom_length_days
        : null,
      weekly_hour_cap: groupSettingsForm.value.weekly_hour_cap,
    };
    const res = await api.put(`/api/shift-groups/${groupSettingsTarget.value.id}`, payload);
    const idx = shiftGroups.value.findIndex((g) => g.id === res.data.id);
    if (idx !== -1) shiftGroups.value[idx] = res.data;
    showGroupSettingsModal.value = false;
    showToast('Settlement settings saved.');
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to save settlement settings.', 'error');
  } finally {
    savingGroupSettings.value = false;
  }
}
```

- [ ] **Step 4: Run the frontend suite to check for regressions**

Run: `cd frontend && npm run test`
Expected: PASS (no existing test covers `ScheduleView.vue`; this confirms nothing else broke)

- [ ] **Step 5: Commit**

```bash
git add frontend/src/views/ScheduleView.vue
git commit -m "feat: add settlement-period settings modal to shift group tabs"
```

---

## Task 7: Frontend — `SettlementPeriodsView.vue` reporting page

**Files:**
- Create: `frontend/src/views/SettlementPeriodsView.vue`
- Create: `frontend/src/views/SettlementPeriodsView.test.js`
- Modify: `frontend/src/router/index.js`
- Modify: `frontend/src/components/AppLayout.vue`

**Interfaces:**
- Consumes: `GET /api/shift-groups` (existing), `GET /api/settlement-periods?shift_group_id=X&offset=N` (Task 5).

- [ ] **Step 1: Write the failing test**

```js
import { fireEvent, render, screen, waitFor } from '@testing-library/vue';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';
import { server } from '../mocks/server.js';
import SettlementPeriodsView from './SettlementPeriodsView.vue';

describe('SettlementPeriodsView', () => {
  it('loads groups and shows the report for the selected group', async () => {
    server.use(
      http.get('/api/shift-groups', () =>
        HttpResponse.json([{ id: 1, name: 'Main', is_default: true }]),
      ),
      http.get('/api/settlement-periods', ({ request }) => {
        const url = new URL(request.url);
        expect(url.searchParams.get('shift_group_id')).toBe('1');
        expect(url.searchParams.get('offset')).toBe('0');
        return HttpResponse.json({
          period: { period_number: 5, start: '2026-09-01', end: '2026-09-30' },
          weeks: [
            { start: '2026-09-01', end: '2026-09-07', cap_hours: 40 },
            { start: '2026-09-08', end: '2026-09-14', cap_hours: 40 },
          ],
          employees: [
            { id: 1, name: 'Jamie Employee', weekly_hours: [44, 20], total_hours: 64, over_cap_weeks: [0] },
          ],
        });
      }),
    );

    render(SettlementPeriodsView, { global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } } });

    await waitFor(() => expect(screen.getByText('Jamie Employee')).toBeInTheDocument());
    expect(screen.getByText('2026-09-01')).toBeInTheDocument();
    expect(screen.getByText('64')).toBeInTheDocument();
  });

  it('steps to the previous period on prev click', async () => {
    server.use(
      http.get('/api/shift-groups', () =>
        HttpResponse.json([{ id: 1, name: 'Main', is_default: true }]),
      ),
      http.get('/api/settlement-periods', ({ request }) => {
        const url = new URL(request.url);
        const offset = url.searchParams.get('offset');
        return HttpResponse.json({
          period: { period_number: offset === '-1' ? 4 : 5, start: offset === '-1' ? '2026-08-01' : '2026-09-01', end: offset === '-1' ? '2026-08-31' : '2026-09-30' },
          weeks: [],
          employees: [],
        });
      }),
    );

    render(SettlementPeriodsView, { global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } } });

    await waitFor(() => expect(screen.getByText(/2026-09-01/)).toBeInTheDocument());
    await fireEvent.click(screen.getByRole('button', { name: /prev/i }));
    await waitFor(() => expect(screen.getByText(/2026-08-01/)).toBeInTheDocument());
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/views/SettlementPeriodsView.test.js`
Expected: FAIL — view doesn't exist.

- [ ] **Step 3: Create the view**

```vue
<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Settlement Periods</h1>
        <p class="text-slate-400 mt-1">Review hours worked against each group's settlement-period cap</p>
      </div>

      <div class="flex items-center gap-3 mb-6">
        <select v-model.number="selectedGroupId" @change="fetchReport"
          class="px-4 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
          <option v-for="group in groups" :key="group.id" :value="group.id">{{ group.name }}</option>
        </select>

        <button aria-label="Previous period" @click="stepPeriod(-1)"
          class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
        </button>
        <span v-if="report" class="text-white text-sm">{{ report.period.start }} – {{ report.period.end }}</span>
        <button aria-label="Next period" @click="stepPeriod(1)"
          class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
        </button>
      </div>

      <div v-if="loading" class="p-10 text-center text-slate-400">Loading…</div>

      <div v-else-if="report" class="bg-white/5 border border-white/10 rounded-2xl overflow-x-auto">
        <table class="w-full text-sm text-left">
          <thead class="text-slate-400 border-b border-white/10">
            <tr>
              <th class="px-4 py-3">Employee</th>
              <th v-for="(week, i) in report.weeks" :key="i" class="px-4 py-3">
                Week {{ i + 1 }}<br /><span class="text-[10px] text-slate-500">cap {{ week.cap_hours }}h</span>
              </th>
              <th class="px-4 py-3">Total</th>
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
            </tr>
            <tr v-if="report.employees.length === 0">
              <td :colspan="report.weeks.length + 2" class="px-4 py-6 text-center text-slate-500">No assignments in this period yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </main>
  </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';

const groups = ref([]);
const selectedGroupId = ref(null);
const offset = ref(0);
const report = ref(null);
const loading = ref(true);

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

async function fetchReport() {
  if (!selectedGroupId.value) return;
  loading.value = true;
  try {
    const res = await api.get('/api/settlement-periods', {
      params: { shift_group_id: selectedGroupId.value, offset: offset.value },
    });
    report.value = res.data;
  } catch {
    report.value = null;
  } finally {
    loading.value = false;
  }
}

function stepPeriod(direction) {
  offset.value += direction;
  fetchReport();
}

onMounted(async () => {
  await fetchGroups();
  await fetchReport();
});
</script>
```

- [ ] **Step 4: Register the route**

In `frontend/src/router/index.js`, add the import `import SettlementPeriodsView from '../views/SettlementPeriodsView.vue';` and a route entry (no `requiresAdmin` — same reasoning as the schedule view itself: the backend's `admin:schedule` gate covers admin and schedule_maintainer, and `requiresAdmin` in this router only checks `is_admin`/`role === 'admin'`):

```js
  {
    path: '/settlement-periods',
    name: 'settlement-periods',
    component: SettlementPeriodsView,
    meta: { requiresAuth: true },
  },
```

- [ ] **Step 5: Add the nav link, visible only to admin/schedule_maintainer**

In `frontend/src/components/AppLayout.vue`, update the `topLinks` computed (around line 388-398) to add the new link and extend the filter:

```js
const topLinks = computed(() => {
  const links = [
    { to: '/dashboard', label: 'Dashboard' },
    { to: '/schedule', label: 'Schedule' },
    { to: '/checklist/fill', label: 'Fill Checklist' },
    { to: '/procedures', label: 'Procedures' },
    { to: '/procedures-to-learn', label: 'To Learn' },
    { to: '/delegations', label: 'Delegations' },
    { to: '/settlement-periods', label: 'Settlement Periods', scheduleOnly: true },
  ];
  return links.filter((l) => (!l.adminOnly || isAdmin.value) && (!l.scheduleOnly || canManageSchedule.value));
});
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd frontend && npx vitest run src/views/SettlementPeriodsView.test.js`
Expected: PASS

- [ ] **Step 7: Manual smoke test**

Log in as an admin or schedule_maintainer, click "Settlement Periods" in the top nav, confirm the group picker and period navigation work, and that a group with assignments over its cap shows the ⚠️ marker in the right week column.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/views/SettlementPeriodsView.vue frontend/src/views/SettlementPeriodsView.test.js frontend/src/router/index.js frontend/src/components/AppLayout.vue
git commit -m "feat: add Settlement Periods reporting page"
```

---

## Task 8: Full regression pass

- [ ] **Step 1: Run the full backend suite**

Run: `cd backend && php -d error_reporting=24575 vendor/bin/pest`
Expected: PASS (all prior suites plus Tasks 1-5)

- [ ] **Step 2: Run the full frontend suite**

Run: `cd frontend && npm run test`
Expected: PASS (all prior suites plus Task 7)

- [ ] **Step 3: Run frontend lint scoped to files this plan touched**

Run: `cd frontend && npx biome check src/views/ScheduleView.vue src/views/SettlementPeriodsView.vue src/views/SettlementPeriodsView.test.js src/router/index.js src/components/AppLayout.vue`
Expected: 0 errors (warnings from the pre-existing Biome/Vue-template-awareness false-positive pattern, documented in sub-project 1's plan, are expected and not a regression)

- [ ] **Step 4: Commit if any fixes were needed**

```bash
git add -A
git commit -m "chore: fix lint/regressions found in full pass"
```
