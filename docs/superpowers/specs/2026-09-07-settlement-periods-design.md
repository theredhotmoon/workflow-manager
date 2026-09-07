# Configurable Settlement Periods — Design

Status: draft, awaiting user review
Part 2 of 4 in the broader feature set (manager permissions — done, PR #4; shift streaks; on-call duty) — see context below.

## Context

Today every hour-cap/generation/reporting endpoint in the schedule generator is hardcoded to a calendar month: `ShiftAutoAssignController::polishLaw()` (and its siblings `random()`/`rotating()`/`clearMonth()`), `ScheduleController::index()`/`generate()`, and `UserAvailabilityController::forMonth()` all take a `month` param shaped `Y-m` and operate on `startOfMonth()`/`endOfMonth()`. There is no concept anywhere in the codebase of a "settlement period" (the accounting window over which labor-law hour caps are actually measured) as distinct from "which calendar month am I looking at in the UI."

The only existing hour-cap logic lives in `ShiftAutoAssignController::polishLaw()` (`backend/app/Http/Controllers/ShiftAutoAssignController.php:519-757`). Reading its actual code (not just its intent) matters here, because two things in it are easy to conflate:

- A **live hard cap of 48h/week** that blocks assignment during generation (line 662: `if ($weekHours + $st['duration_hours'] > 48) continue;`), bucketed by `$weekNum = Carbon::parse($dateStr)->weekOfYear` (ISO calendar week — lines 603, 622).
- A **separate 40h/week "average across the whole calendar month" check** (line 723-727) that is purely post-hoc — it never blocks anything, it only appends a string to a `$complianceNotes` array returned as a warning.

This sub-project replaces both of those with one thing: a single configurable per-group weekly hour cap, enforced live, measured against weeks anchored to the group's actual settlement period — not the ISO calendar week and not a whole-month average.

## Goals

- A schedule maintainer can configure, per `ShiftGroup`: a settlement mode (`weekly`/`monthly`/`quarterly`/`custom`), an anchor date, a custom length in days (only when mode is `custom`), and a weekly hour cap (number, e.g. 40).
- Given that config, the system can compute the settlement period containing any date, or any period relative to "now" (for browsing past periods) — as a pure calculation, no periods table, no manual period creation.
- Within a period, "weeks" are 7-day blocks starting from the period's start date, not calendar Monday–Sunday. The trailing partial week (periods rarely divide evenly by 7) gets a cap prorated by day count.
- `polishLaw()`'s live hard weekly cap is measured against these anchored weeks and reads the group's configured cap instead of a hardcoded 48. The separate 40h/month-average post-hoc note is removed — it was an approximation of exactly what this feature now does correctly.
- A new admin/schedule_maintainer-only reporting page shows, for a chosen group and settlement period (with prev/next navigation across past periods), each employee's hours per anchored week and their total, flagging any week over cap.

## Non-goals

- No change to the month-based calendar/browsing UI anywhere (`ScheduleView.vue`'s month navigator, `MyShiftsView.vue`, `MyAvailabilityView.vue`, any `?month=` param) — those are a separate UX concern (browsing granularity) from the settlement period (a compliance accounting window), and the user confirmed they should stay untouched.
- No hour-cap enforcement anywhere outside `polishLaw()` — manual assignment (`ScheduleController::assign()`) and the `random()`/`rotating()` auto-assign modes get no new validation. They have none today; this isn't a regression, just not extending coverage yet.
- No persisted "hours worked" ledger. The new reporting page computes hours live from `Shift`/`ShiftAssignment` data on every request — settlement periods (even a full quarter) are cheap enough to query directly, and freezing historical numbers for audit purposes wasn't requested.
- No change to the other hard-coded constants in `polishLaw()` (13h/shift max, 5-working-days/week max) — only the weekly hour cap becomes configurable.
- No fix to the 35h-weekly-rest heuristic's underlying correctness (it remains the same rough "is Sunday free" approximation it is today — see "Necessary consequence" below for the one thing about it that does change). Full, correct weekly-rest enforcement is explicitly on-call duty's job (sub-project 4).

## Data model

### `shift_groups` — new nullable columns

```
settlement_mode                  ENUM('weekly','monthly','quarterly','custom'), nullable
settlement_anchor_date           DATE, nullable
settlement_custom_length_days    INTEGER, nullable — required only when settlement_mode = 'custom'
weekly_hour_cap                  DECIMAL, nullable — defaults to 48 for existing groups (migration backfill), matching today's live hard-cap behavior exactly; a new group can be created with no settlement config at all, in which case `polishLaw()` falls back to the same defaults (mode `monthly`, cap 48) so nothing breaks for a group that never configures this
```

Nullable throughout so existing groups keep working with no admin action required; the migration backfills `weekly_hour_cap = 48` and `settlement_mode = 'monthly'` with `settlement_anchor_date` set to each group's `created_at` date, for every existing row.

## `SettlementPeriodCalculator` service (new)

`backend/app/Services/SettlementPeriodCalculator.php` — pure, stateless, no DB writes. Two public entry points, sharing private helpers:

- `periodContaining(ShiftGroup $group, Carbon $date): array` — the settlement period that contains `$date`. Used by `polishLaw()`.
- `periodByOffset(ShiftGroup $group, int $offset): array` — the settlement period `$offset` steps from the one containing today (`0` = current, `-1` = previous, `1` = next). Used by the new reporting endpoint's prev/next navigation.

Both return the same shape:

```php
[
    'period_number' => int,       // count of full period-lengths elapsed since anchor_date
    'start' => Carbon,
    'end' => Carbon,               // inclusive
    'weeks' => [
        ['start' => Carbon, 'end' => Carbon, 'cap_hours' => float],
        // ...
    ],
]
```

Period length by mode:
- `weekly`: exactly 7 days. A weekly-mode period is always exactly one week — no partial week, no proration.
- `monthly`: `start = anchor->copy()->addMonthsNoOverflow($periodNumber)`, `end = start->copy()->addMonthNoOverflow()->subDay()`. Using `addMonthNoOverflow` (not plain `addMonth`) so an anchor on the 31st doesn't skip or roll over in shorter months.
- `quarterly`: same as monthly with `addMonthsNoOverflow($periodNumber * 3)`.
- `custom`: `start = anchor->copy()->addDays($periodNumber * $lengthDays)`, `end = start->copy()->addDays($lengthDays - 1)`.

`$periodNumber` for a given date is computed by walking from the anchor in period-length steps until the date falls within `[start, end]` — cheap for any realistic anchor distance (bounded by a small loop or closed-form division depending on mode; monthly/quarterly use `anchor->diffInMonths($date)` divided by the mode's month-count as a starting guess, refined by at most one step in either direction to handle month-length edge cases).

Weeks within a period: 7-day blocks from `start`. The last block may be shorter; its `cap_hours = round($group->weekly_hour_cap * $daysInLastWeek / 7, 2)`. Every full 7-day week gets the full `weekly_hour_cap`.

## `polishLaw()` changes

Replace `$weekNum = Carbon::parse($dateStr)->weekOfYear` (lines 603, 622) with a lookup into the settlement period's `weeks` array for the group being generated (computed once per request via `periodContaining($group, $start)`, since a single `polishLaw()` call already operates within one calendar month and — for any realistic settlement mode — that month will only span at most 2-3 settlement weeks needing lookup). Replace the hardcoded `> 48` at line 662 with `> $currentWeek['cap_hours']`. Remove the separate 40h-average post-hoc note block (lines 723-727) and its `$totalWeeks`/`$avgWeeklyHours` calculation (lines 615, 723) entirely — the per-week check now does what that was approximating, correctly.

**Necessary consequence of redefining `$weekNum`:** the existing 35h-rest heuristic (lines 673-677, `if ($dayOfWeek == 7) { ... }`) reads from the *same* `$state['weekly_days'][$weekNum]` array that the hour-cap logic uses — so once `$weekNum` buckets by anchored week instead of ISO week, that heuristic's `count($weekDays)` check automatically operates on the right buckets. But its special-casing of "Sunday" (`$dayOfWeekIso == 7`) does not — it assumes every week ends on Sunday, which is false for a group whose anchored week doesn't align to the calendar week. This is fixed by replacing `$dayOfWeek == 7` with "is `$dateStr` the last day of its anchored week" (available directly from the same `weeks` array lookup). This is a minimal, unavoidable fix to code this change already touches — not an attempt to make the heuristic itself more correct (it remains a rough approximation; real weekly-rest correctness is sub-project 4's job).

## New reporting endpoint + page

**Backend:** `backend/app/Http/Controllers/SettlementPeriodController.php` (new), one method:

```
GET /settlement-periods?shift_group_id=X&offset=N
```
Gated by the existing `admin:schedule` middleware group (same tier as `polishLaw()` itself and other schedule-management routes). Calls `periodByOffset($group, $offset)`, then for every employee with at least one `ShiftAssignment` whose shift belongs to `shift_group_id = X` (the requested group) and falls within `[period.start, period.end]`, sums shift durations per anchored week (reusing the same overnight-aware duration calculation `polishLaw()` uses — extracted into a small shared helper, `SettlementPeriodCalculator::shiftDurationHours(Shift $shift): float`, so the two call sites can't drift apart). Response:

```php
[
    'period' => ['period_number' => ..., 'start' => ..., 'end' => ...],
    'weeks' => [['start' => ..., 'end' => ..., 'cap_hours' => ...], ...],
    'employees' => [
        ['id' => ..., 'name' => ..., 'weekly_hours' => [12.0, 36.0, ...], 'total_hours' => ..., 'over_cap_weeks' => [1]],
        // ...
    ],
]
```

**Frontend:** new `SettlementPeriodsView.vue` (route `/settlement-periods`, `meta: { requiresAuth: true }` — no `requiresAdmin`. The backend endpoint is gated to admin OR schedule_maintainer (`admin:schedule`), and `requiresAdmin: true` in the frontend router checks `is_admin`/`role === 'admin'` only, which would incorrectly lock out schedule_maintainers entirely. `ScheduleView.vue` — itself gated only by `admin:schedule` on its own admin actions — follows exactly this pattern: an open route with the real enforcement left to the backend and role-based conditional rendering inside the component, not the router. This view follows the same pattern rather than `OfficeAvailabilityView.vue`'s true-admin-only gating, since that page's audience is narrower (pure admin config) than this one's (admin or schedule_maintainer, same as generation itself)). Group picker (reusing the existing `/api/shift-groups` list), prev/next buttons stepping `offset`, a table: employee name | one column per anchored week | total | a flag icon on any week exceeding its cap.

**Admin config UI:** Correction from initial research — `ShiftSettingsView.vue` turned out to only manage shift *colors* (`ShiftColorController`), not shift groups. Shift-group management (create/rename/delete) actually lives inline in `ScheduleView.vue`'s group tab bar, with no existing per-group settings modal to extend. This adds one: a small "⚙" settings icon next to each group tab (visible under the same `canManageSchedule` condition as the existing delete button, for the active tab), opening a new modal with the four settlement fields (mode select, anchor date picker, custom-length number input shown only when mode is `custom`, weekly hour cap number input) and a Save button calling the existing `PUT /shift-groups/{id}` endpoint (extended to accept these fields). This is a new, small, self-contained addition — it doesn't touch the existing create/rename/delete flows in that same tab bar.

## Error handling

- `periodContaining`/`periodByOffset` on a group with no settlement config at all (`settlement_mode` null) fall back to `mode = 'monthly'`, `anchor_date = $group->created_at->startOfDay()`, `weekly_hour_cap = 48` — matching the migration's backfill defaults, so a newly created group with no explicit config behaves exactly as `polishLaw()` does today.
- `settlement_custom_length_days` is validated as required (`min:1`) only when `settlement_mode = 'custom'`, both on the `ShiftGroup` update endpoint and defensively inside the calculator (throws if `custom` mode is selected with a null length — this should be unreachable past validation, but the calculator doesn't trust that silently).
- The reporting endpoint returns an empty `employees` array (not an error) for a period with no assignments yet — a perfectly normal state for the current, still-in-progress period.

## Testing

- Unit tests on `SettlementPeriodCalculator` directly (no HTTP, no DB beyond a `ShiftGroup` fixture): weekly/monthly/quarterly/custom modes; an anchor date on each day of the week (confirming anchored weeks shift accordingly); the prorated trailing week's `cap_hours` for a period length not divisible by 7; `periodByOffset` at `0`/`-1`/`1` producing contiguous, non-overlapping periods; `periodContaining` for a date exactly on a period boundary.
- Feature tests on `polishLaw()`: confirm it now blocks assignment at a configured `weekly_hour_cap` other than 48 (e.g. 40) instead of the old hardcoded value; confirm a group with no settlement config still behaves exactly as before (regression guard); confirm the removed 40h-average note no longer appears in the response.
- Feature tests on the new `GET /settlement-periods` endpoint: correct period/week boundaries for a known anchor+mode; correct per-employee hour sums including an overnight shift; `admin:schedule` gating (a plain employee gets 403).

## Open questions for implementation

None outstanding — all resolved during brainstorming (see the two clarifying rounds on cap semantics and enforcement scope above).
