# Streak Replay — Design

Status: draft, awaiting user review
Hardens Shift Streaks (shipped 2026-09-14, made reachable by Shift Type Labelling, PR #9).

## Context

Shift Streaks tracks, per employee and rule, how many shifts of a type they have worked "since their last rest", and blocks them for `rest_days` after `streak_length` such shifts. Today this lives in persisted, incrementally-updated counters (`shift_streak_states`: `current_count`, `resting_until`, `last_processed_date`) maintained by `ShiftStreakTracker` from every path that changes an assignment.

The original design (`2026-09-08-shift-streaks-design.md`) chose persisted counters because a streak *pauses* rather than resets (a day off or another shift type leaves the count untouched), so a bounded look-back cannot find a reliable starting point. Since PR #9 made streaks reachable, reviews found the counter approach cannot stay correct:

- **(a)** Unassigning or retyping the shift that started a rest window never clears the window; night→day→night retypes drift the count.
- **(b)** A past-dated change could shorten a later window (patched in PR #9 with `max()`).
- **(c)** The `last_processed_date` watermark lets a later auto-assign replay skip assignments that were never counted.
- **(d)** No locking: concurrent assigns can lose an update.
- Random Coverage and Rotating Subteam neither record streaks nor respect rest windows; editing a rule's numbers never re-evaluates history; the code counts rest-day assignments although the original spec says they don't count.

A full replay of history is cheap: one group's assignments are a few hundred rows per employee per year and can be loaded in one query. This design replaces the counters with a deterministic replay of real assignments, and lets the manager decide what a rest-day assignment means.

## Goals

- Streak state is always derived from actual assignments, so it can never drift, needs no locking, and reflects unassign, retype, past edits and rule edits immediately.
- Rest windows are respected by every assignment path: manual assign asks the manager; all three auto-fill modes skip resting employees.
- The manager's decision for a rest-day assignment is recorded on that assignment and applied consistently by the replay.

## Non-goals

- A cache/materialised table for streak state (add only if measured to be needed).
- Per-employee streak history views.
- Changing streak semantics beyond the rest-day policy below ("pause, not reset", union of rest windows across rules, rule matching by normalised `shift_type`, all unchanged).

## Semantics

Inputs per group: its streak rules (each `shift_type`, `streak_length` N, `rest_days` R) and the employee's assignments in that group whose shifts are on dates, ordered by `(date, shift_number)`.

State per employee: per rule a `count`; a set of rest windows (each `[start, end]` inclusive, tied to the rule that produced it). An employee is **resting on day D** if any window contains D.

Replay processes each assignment on date D (state includes only assignments strictly before D when evaluating "as of D"):

| Situation | Effect |
|---|---|
| Not resting on D, and the shift's type has rule X | `count[X] += 1`; if `count[X] >= N_X`: add window `[D+1, D+R_X]`, `count[X] = 0` |
| Not resting on D, no rule for the shift's type | no effect |
| Resting on D, `rest_policy` = `not_counted` or null (default) | no effect |
| Resting on D, `rest_policy` = `counts` | as the first row, if the shift's type has a rule (count advances toward the next streak; a new window, if triggered, is added — windows union) |
| Resting on D, `rest_policy` = `extends_rest` | every window containing D gets `end = max(end, D + R_rule)` where `R_rule` is the window's rule's `rest_days` |

Days without an assignment, and assignments whose shift type has no rule, pause streaks (no reset). Rule edits apply retroactively because state is always recomputed.

**As-of:** `stateAsOf(D)` replays assignments with date < D. Eligibility for D uses `stateAsOf(D)`; later assignments never affect earlier days.

## Data model

- `shift_assignments.rest_policy` — new nullable string column, values `not_counted | counts | extends_rest`; null ≡ `not_counted`. Existing rows stay null.
- Drop `shift_streak_states` (and model `ShiftStreakState`). The migration's `down()` recreates the table empty (same schema as `2026_09_11_130100_create_shift_streak_states_table.php`).
- Add an index on `shifts (shift_group_id, date)` (the existing unique index leads with `date`).

## Components

### `App\Services\StreakReplay` (new, replaces `ShiftStreakTracker`)

Pure engine plus a loader:
- `forGroup(ShiftGroup $group, array $userIds, Carbon $until): StreakTimeline` — one query loading the users' assignments in the group with shift date < `$until` (plus `shift_type`, `shift_number`, `rest_policy`), replays them per user, returns a timeline.
- `StreakTimeline` (value object): `isResting(int $userId, Carbon $day): bool`, `restingUntil(int $userId, Carbon $day): ?Carbon`, `statusAsOf(int $userId, Carbon $day): array` (per rule: `shift_type`, `current_count`, `streak_length`, `resting_until`), and `append(int $userId, string|null $shiftType, Carbon $date, ?string $restPolicy): void` so auto-assign can extend a user's replay in memory as it assigns in date order.
- Groups with no streak rules short-circuit (no query).

`ShiftStreakTracker` and `ShiftStreakState` are deleted; all callers move to `StreakReplay`.

### Manual assign — `POST /schedule/assign`

- Accepts optional `rest_policy` (`nullable|in:not_counted,counts,extends_rest`).
- If the group has rules and the employee is resting on the shift's date (per `stateAsOf(date)`) and no `rest_policy` was sent: **409** `{ code: 'rest_window_conflict', message, resting_until, rule: { shift_type, streak_length, rest_days } }` (rule = the window ending last), nothing created.
- Otherwise creates the assignment (storing `rest_policy` when given). No streak bookkeeping.

### Unassign, clear-month, retype

`unassign`, `clearMonth`, `ShiftAutoAssignController`'s clear path and `ShiftTypeChanger` drop all tracker calls — state follows from data. `ShiftTypeChanger` and both retype endpoints keep returning `rebooked`, now meaning "assignments on changed shifts whose old or new type has a rule" (UI toasts unchanged).

### Auto-assign — Random Coverage, Rotating Subteam, Polish Law

Each mode builds one `StreakTimeline` for the group's eligible employees up to the end of the target month, skips an employee on any day they are resting (as of that day), and calls `append()` for every assignment it makes, processing days in date order. Auto-assign never assigns into a rest window, so it never sets `rest_policy`. Polish Law's watermark logic is removed.

### Settlement report

Each employee's `streak_status` is `statusAsOf(period_end + 1 day)` — the state after every assignment inside the displayed period (keeps today's `night: 2/3` / `resting until` display meaningful for the current period). Response shape unchanged.

### Frontend

- New `frontend/src/components/RestDayPolicyModal.vue`: on a 409 `rest_window_conflict` from a drop in `ScheduleView`, shows "<name> is resting until <date> (<type>: <N> in a row → <R> days off). How should this shift count?" with the three options (Doesn't count / Counts toward the next streak / Extends the rest) and Cancel. Choosing re-POSTs with `rest_policy`; Cancel reverts the optimistic drop (removes the cloned chip) and creates nothing.
- Schedule payloads include each assignment's `rest_policy`; chips of assignments with a non-null policy other than `not_counted` show a small marker with a tooltip ("Assigned during rest: counts toward next streak" / "…: extends rest").
- Accessibility: the modal is `role="dialog"` with a labelled title and labelled radio options.

## Rollout

On deploy, statuses are recomputed from real history: drifted counters are corrected, and assignments made by Random/Rotating (never counted before) now count — some employees' streak status may change. Existing rest-day assignments (null policy) don't count, matching the original spec. This goes in the CHANGELOG as a deploy note.

## Error handling

- Invalid `rest_policy` → 422.
- 409 conflict carries enough data for the modal; the frontend treats any other error as today (toast, revert).
- All new code PHP 8.0-compatible (CI runs 8.0).

## Testing

### Unit (Pest, engine only)
Basic streak → window; pause on days off and other types; date ordering independent of creation order; as-of excludes later assignments; each policy (`not_counted`, `counts` incl. triggering a new window, `extends_rest` taking the later end); two overlapping rules; rule edit re-evaluates; and every old-tracker defect replays correctly: unassigning the window-starting shift clears it, night→day→night has no drift, Random/Rotating assignments count, retyping a past shift updates state, a relabel never shortens a later window.

### Feature (Pest)
Assign: 409 with window details; retry with each policy stores it; non-rest days unchanged; invalid policy 422. Unassign / clear-month / both retype endpoints: state follows; `rebooked` still reported. All three auto-assign modes skip a resting employee and produce correct downstream state. Settlement report status as of period end across periods. Migration adds the column, drops the table; down recreates it. Existing streak tests (`PolishLawStreakTest`, `ScheduleControllerStreakTest`, `ClearMonthStreakTest`, `SettlementPeriodControllerStreakTest`, `ShiftStreakTrackerTest`, re-booking cases in `ShiftTypeLabellingTest`) are rewritten to assert behaviour rather than stored counters; any test that pinned the old (wrong) behaviour is listed in the plan, not silently changed. Run on PHP 8.2 and 8.0.

### E2E (Playwright)
Drop an employee onto a rest day → modal; choose "Extends the rest" → policy stored, chip marker shown, after reload the settlement report shows the extended `resting until`; Cancel → nothing created (verified via API). Existing report streak tests (`night: 2/3`, `resting until`) pass on the new engine. Reserved new months; server-side verification; every new assertion sabotage-proven.

### Docs
CHANGELOG entry with the deploy note above.

## Open questions for implementation

- Exact polishLaw loop order: confirm it processes days chronologically before relying on `append()`; if not, sort its work by date first.
- Whether `ScheduleView`'s optimistic clone removal on Cancel needs Sortable's API or a refetch — either is acceptable.
