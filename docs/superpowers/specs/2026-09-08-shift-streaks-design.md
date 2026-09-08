# Shift Streaks — Design

Status: draft, awaiting user review
Part 3 of 4 in the broader feature set (manager permissions — shipped; configurable settlement periods — shipped; on-call duty — next).

## Context

The user's original request: "add a mechanism in the schedule generator to define a rule like 'N identical shifts in a row, then M days off' (e.g. 3 night shifts, then 2 days off — following the equivalent-time 3.2/4.1-style pattern). This will make manually adding/removing shifts easier and help keep generated schedules compliant with labor law."

Sub-project 1 (manager permissions) added a free-form `shift_type` label to `Shift` (e.g. "day"/"evening"/"night"), giving this sub-project a concrete way to identify "identical shifts": by `shift_type`. Sub-project 2 (settlement periods) established the pattern this design follows closely — a per-`ShiftGroup` configuration, enforced live inside `ShiftAutoAssignController::polishLaw()`'s candidate-eligibility loop, with a settings modal on the group tabs and a column in the existing Settlement Periods report.

This sub-project required merging sub-project 1's PR (previously open, unmerged) into `main` first, since the design depends on `shifts.shift_type` — done as part of this brainstorming session.

## Goals

- A `ShiftGroup` can define any number of streak rules, each keyed by a `shift_type` (e.g. one rule for "night", a separate rule for "day"), each specifying a streak length (N) and a rest length (M).
- `polishLaw()` enforces each rule live: once an employee has been assigned N shifts of a rule's `shift_type` since their last completed rest period, they become ineligible for *any* shift in that group for the next M consecutive calendar days.
- The streak/rest position for each employee is tracked correctly across separate generation requests (e.g. generating September, then October) — not reset to zero every time `polishLaw()` runs.
- Manual assignment and removal (`ScheduleController::assign()`/`unassign()`) keep the same tracked counters accurate, without being blocked by the rule themselves.
- An admin can configure rules from the same per-group settings modal built for settlement periods, and see each employee's current streak/rest position in the existing Settlement Periods report.

## Non-goals

- No enforcement outside `polishLaw()` — manual assignment stays unblocked, matching the settlement-periods precedent.
- No change to the month-based calendar/browsing UI.
- No attempt to fully reconstruct historical state from real `ShiftAssignment` rows on every request — see Data model below for why persisted state is used instead, and see Error handling for the accepted simplification around `unassign()`'s reversal behavior.
- No interaction with the settlement-period hour cap or the 11h/13h/5-day rules already in `polishLaw()` — a streak rest window is a separate, additional constraint layered alongside them, not a replacement for any of them.

## Data model

### `shift_streak_rules` (new table)

```
id
shift_group_id   FK -> shift_groups.id, cascade delete
shift_type       VARCHAR(50) — matches the free-form Shift.shift_type values this rule applies to
streak_length    UNSIGNED INT — N: how many assignments of shift_type trigger rest
rest_days        UNSIGNED INT — M: how many consecutive calendar days of full rest follow
timestamps
```

A group can have zero or more rules; each covers exactly one `shift_type`. Two rules for the same `(shift_group_id, shift_type)` pair are not meaningful and are prevented by a unique constraint.

### `shift_streak_states` (new table)

```
id
user_id                 FK -> users.id, cascade delete
shift_streak_rule_id    FK -> shift_streak_rules.id, cascade delete
current_count           UNSIGNED INT, default 0
resting_until            DATE, nullable — the last day (inclusive) of an active mandatory rest window
last_processed_date     DATE, nullable — the latest shift date whose effect on this state has already been applied
timestamps
```

Unique on `(user_id, shift_streak_rule_id)`. A row is created lazily the first time an employee is assigned a shift matching that rule; absence of a row means "count 0, not resting, nothing processed yet" (the same conservative default settlement periods uses for unconfigured groups).

**`last_processed_date` exists to make re-generation idempotent.** `polishLaw()`'s existing pre-population step re-scans *every* already-existing assignment in the requested month on *every* call, including a second `polishLaw()` run over a month that was already generated once. Without a watermark, that re-scan would call `recordAssignment()` again for shifts whose effect on `current_count`/`resting_until` was already applied and persisted the first time, double-counting them. `last_processed_date` records how far the pre-population pass has already advanced for this specific `(user, rule)`; re-processing only applies to shift dates strictly after it. This only guards the pre-population pass — manual `assign()`/`unassign()` each correspond to exactly one real mutation and call `recordAssignment()`/`recordUnassignment()` exactly once when it happens, so they need no such guard.

**Why persisted state instead of live derivation:** a streak's count is "assignments of this shift_type since the employee's last completed rest window," which — because a day worked as a *different* shift_type or a genuine day off neither resets nor advances it (see State machine below) — can span arbitrarily far back in real history. A live backward scan from `ShiftAssignment` rows (the pattern settlement periods uses successfully for its own hour totals) has no natural stopping point here: there's no guarantee of finding a rest-window boundary within any bounded lookback window. Persisting the count and rest-window end date, updated incrementally by every code path that changes an assignment, avoids an unbounded search entirely and is the correctness-driving reason for the persisted table, not just a performance optimization.

## State machine

For a given `(user, rule)` pair, exactly one event changes the state: **the user is assigned a shift whose `shift_type` matches the rule's `shift_type`**, on a date the user is not currently resting.

- `current_count += 1`.
- If `current_count >= streak_length`: set `resting_until = <assignment date> + rest_days` (inclusive of that many calendar days), reset `current_count = 0`.

Every other event — a genuine day off, or an assignment of a *different* `shift_type` (tracked by another rule or untracked entirely) — leaves `current_count` and `resting_until` for this rule completely untouched. This is a deliberate "pause, not reset" semantics: a streak resumes exactly where it left off once the employee is assigned this rule's `shift_type` again, regardless of what happened in between. A rest window, once granted, is not shortened, extended, or re-triggered by anything except its own natural expiry.

**Eligibility is type-agnostic and rule-agnostic on the blocking side:** an employee is ineligible for *any* shift in the group on a given date if *any* of their `shift_streak_states` rows (across every rule belonging to that group) has `resting_until` on or after that date. Multiple simultaneously-active rest windows (e.g. from both a night-rule and a day-rule completing around the same time) simply overlap — the effective block is their union, requiring no special-casing.

## `ShiftStreakTracker` service (new)

`backend/app/Services/ShiftStreakTracker.php` — mirrors `SettlementPeriodCalculator`'s role as the single place this logic lives, but this one does read/write real rows (it is not pure).

- `rulesForGroup(ShiftGroup $group): Collection` — all `ShiftStreakRule` rows for the group, keyed by `shift_type`.
- `loadStates(Collection $rules, array $userIds): array` — bulk-fetches existing `ShiftStreakState` rows for the given rules × users, returning an in-memory array keyed `"{$userId}:{$ruleId}"`, defaulting missing pairs to `['current_count' => 0, 'resting_until' => null, 'last_processed_date' => null, 'dirty' => false]`.
- `isBlocked(array &$states, Collection $rules, int $userId, Carbon $date): bool` — true if any of this user's loaded states (for rules in the currently-relevant group) has `resting_until` on/after `$date`.
- `recordAssignment(array &$states, Collection $rules, int $userId, ?string $shiftType, Carbon $date, bool $advanceWatermark = false): void` — no-ops if `$shiftType` doesn't match any rule; otherwise applies the state-machine transition above and marks the state `dirty`. When `$advanceWatermark` is true (used only by the pre-population pass — see below), also sets `last_processed_date = max(current value, $date)`.
- `wasAlreadyProcessed(array $states, Collection $rules, int $userId, ?string $shiftType, Carbon $date): bool` — true only when `$shiftType` matches a rule AND that rule's state for this user has a `last_processed_date` on or after `$date`. Used exclusively to gate the pre-population pass against re-applying the same historical assignment twice.
- `recordUnassignment(array &$states, Collection $rules, int $userId, ?string $shiftType): void` — no-ops if `$shiftType` doesn't match any rule; otherwise decrements `current_count` by 1, floored at 0, and marks `dirty`. Deliberately does not attempt to un-trigger an already-set `resting_until`, nor does it touch `last_processed_date` — see Error handling.
- `persist(array $states): void` — bulk-upserts every state marked `dirty` back to `shift_streak_states`.

## `polishLaw()` integration

Alongside the existing per-date precompute (settlement-period weeks), add one precompute step: load this group's streak rules and bulk-load state for every employee in the candidate pool (`ShiftStreakTracker::rulesForGroup()` + `loadStates()`). In the existing pre-population pass over already-existing assignments for the month, for each one call `recordAssignment(..., advanceWatermark: true)` **only if `!wasAlreadyProcessed(...)` for that same date** — this is what makes re-running generation over a month that was already (fully or partially) generated idempotent, rather than double-counting assignments the watermark already accounts for. Inside the per-shift candidate-eligibility loop, add one more filter next to the existing checks: skip a candidate if `isBlocked($states, $rules, $emp->id, $shiftDate)`. When a *new* assignment is made by this request, call `recordAssignment(..., advanceWatermark: true)` for it too (a newly-created assignment is, by construction, never something the watermark could have already covered). After the full method completes, call `persist()` once.

## Manual assignment integration

`ScheduleController::assign()` (currently: validate → check not already assigned → `ShiftAssignment::create()` → return) gains, after the successful create: load the shift's group + `shift_type`, call `ShiftStreakTracker::recordAssignment(..., advanceWatermark: true)` for a single user against that group's rules, and `persist()`. Passing `advanceWatermark: true` here (not just in `polishLaw()`) matters: without it, a *later* `polishLaw()` run whose pre-population pass scans this same manually-created assignment would have no record that it was already accounted for, and would double-count it. `unassign()` (currently: acknowledge pending change requests → delete) gains the symmetric `recordUnassignment()` + `persist()` call before the delete (needs the shift's `shift_type` and group, fetched via the assignment's `shift` relation, before the row is gone) — it does not touch `last_processed_date` (per the tracker's own contract above), since the assignment it's reversing is being deleted outright, not left in place for a future pre-population pass to reconsider. Neither method blocks on the result — this is bookkeeping, not enforcement, matching the confirmed non-goal.

## Frontend

**Rule configuration:** the per-group settings modal (built in sub-project 2, currently editing `settlement_mode`/`settlement_anchor_date`/`settlement_custom_length_days`/`weekly_hour_cap`) gains a new section: a repeatable list of `(shift_type, streak_length, rest_days)` rows with add/remove controls, saved via a new `PUT /shift-groups/{id}/streak-rules` endpoint that replaces the group's full rule set in one call (sync semantics, matching the existing `syncUsers`/`syncManagers` pattern elsewhere in this codebase) — simpler than diffing individual rule add/edit/delete operations for what's expected to be a short, infrequently-changed list.

**Reporting:** the Settlement Periods report page (`SettlementPeriodsView.vue`) gains one more column per employee row: their current streak/rest status, summarized as e.g. "night: 2/3" (mid-streak) or "resting until 2026-09-12" (in a rest window) or blank (no active rules or fully clear). The backend's `GET /settlement-periods` response gains a `streak_status` array per employee (one entry per rule that applies to the group, each `{shift_type, current_count, streak_length, resting_until}`).

## Error handling

- `unassign()`'s decrement-only behavior (no un-triggering an already-granted rest window) is an accepted simplification: reversing a rest-window trigger correctly would require knowing whether *this specific* assignment was the one that completed the streak, and if so, recomputing every subsequent day's eligibility that depended on that rest window already being in effect — a cascading recalculation this design does not attempt. In practice this means: removing a shift that completed someone's streak leaves their earned rest window in place rather than retroactively cancelling it. If this proves too surprising in practice, a future iteration could add a "last triggering assignment" pointer to make targeted reversal possible.
- Creating/updating streak rules is validated the same way settlement-period fields are: `shift_type` required non-empty string, `streak_length`/`rest_days` required positive integers (`min:1`) — a rule with `streak_length` or `rest_days` of 0 is meaningless and rejected at the API boundary.
- Deleting a `ShiftGroup` cascades to its rules (existing FK cascade), which cascades to their states — no orphaned rows possible.

## Testing

- Unit tests on `ShiftStreakTracker` directly: increment without triggering rest; increment that triggers rest (count resets, `resting_until` set correctly); pause on a different shift_type (count unchanged); pause on no assignment; `isBlocked` correctly unions multiple active rest windows; `recordUnassignment`'s floor-at-0 decrement.
- Feature tests on `polishLaw()`: a 4th night shift is blocked after 3 (with a 2-day rest rule), and the employee is ineligible for a *day*-type shift during that same rest window (proving "fully off" semantics); state persisted from generating one month correctly carries into generating the next month for the same employee (the specific cross-month-continuity requirement that drove the persisted-state design); **running `polishLaw()` a second time over the exact same already-generated month leaves `current_count`/`resting_until` unchanged from the first run** (the specific idempotency property the `last_processed_date` watermark exists to guarantee — without it this test would show the count doubling).
- Feature tests on `ScheduleController::assign()`/`unassign()`: manually assigning a tracked shift_type increments the counter without being blocked by an active rest window; manually unassigning decrements it.
- Feature tests on the rule-configuration endpoint: sync replaces the full rule set; validation rejects a zero-length streak/rest value.

## Open questions for implementation

None outstanding — all resolved during brainstorming.
