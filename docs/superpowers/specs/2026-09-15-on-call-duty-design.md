# On-Call Duty — Design

Status: draft, awaiting user review
Part 4 of 4 in the broader feature set (manager permissions — shipped, then reverted; configurable settlement periods — shipped; shift streaks — shipped).

## Context

The user's original request: on-call duty needs its own domain concept, distinct from regular shifts — on-call time counts toward rest but not worked hours, and the schedule generator must keep enforcing 11h daily / 35h weekly rest even while someone is on call. Of the four sub-projects, this was flagged as the largest and most novel, since none of the existing schema (Shift, ShiftGroup, settlement periods, streaks) has anything resembling "reachable but not working."

Established precedent this design follows: `polishLaw()` (`backend/app/Http/Controllers/ShiftAutoAssignController.php:534`) already enforces four Polish Labor Code rules inside its per-shift candidate-eligibility loop — 11h rest since the last shift, the settlement-period-anchored `weekly_hour_cap`, a max-5-working-days-a-week cap, and a 35h-weekly-rest heuristic — all driven by a per-employee state walk (`$employeeState`: `last_shift_end`, `weekly_hours`, `weekly_days`, `total_hours`) that is pre-populated from existing assignments before the main assignment loop runs. Sub-project 3 (shift streaks) added a fifth rule the same way: one more precompute, one more eligibility check, one more thing recorded on assignment.

## Goals

- A `ShiftGroup` can have any number of employees on-call at once, for arbitrary datetime windows (`on_call_assignments`), assigned manually by an admin/schedule_maintainer — no auto-rotation.
- An admin/schedule_maintainer can retroactively log that an employee was actually called in during an on-call window (`on_call_callbacks`) — a real worked interval with its own start/end.
- Passive on-call time (no callback) is invisible to the schedule generator: it neither blocks regular-shift eligibility nor counts as worked hours. Being on-call is not, by itself, a labor-law-relevant event.
- A callback **is** worked time: it resets the 11h/35h rest clock exactly like a regular shift ending would, and its duration counts toward the settlement-period `weekly_hour_cap` and the max-5-working-days rule — using the exact same state (`$employeeState`) and the exact same checks `polishLaw()` already runs for shifts, not a parallel set of rules.
- The Settlement Periods report (`SettlementPeriodController::show()`) includes callback hours in each employee's weekly totals, so it stays consistent with what `polishLaw()` actually enforces.

## Non-goals

- No auto-generated/rotated on-call schedule — on-call assignment is manual only, matching this sub-project's own scope decision (auto-rotation would be a separate future enhancement, not blocking this one).
- No self-service callback logging by the on-call employee — admin/schedule_maintainer only, matching every other schedule-mutation endpoint in this app.
- No "equivalent make-up rest" debt tracking for interrupted on-call rest (a distinct, more complex labor-law concept) — a callback simply resets the rest clock the same way a shift does, nothing is owed afterward.
- No overlap restrictions anywhere: multiple employees can be on-call in the same group at once (primary/backup), on-call can overlap a regular shift, and a callback's `ends_at` is not required to fall within its parent on-call assignment's window (only `starts_at` is).
- No change to manual shift assignment (`ScheduleController::assign()`/`unassign()`) — the 11h/13h/35h/cap rules have only ever been enforced inside `polishLaw()`'s auto-assign, never on manual assignment, and on-call duty does not change that boundary.
- No new calendar-drag UI — on-call assignment and callback logging are simple form-based actions on a dedicated page, matching the after-the-fact, admin-driven nature of this feature.

## Data model

### `on_call_assignments` (new table)

```
id
shift_group_id   FK -> shift_groups.id, cascade delete
user_id          FK -> users.id, cascade delete
starts_at        DATETIME
ends_at          DATETIME
timestamps
```

No uniqueness or overlap constraint — any number of employees can be on-call in the same group at overlapping times (see Non-goals). App-level validation: `ends_at` strictly after `starts_at`.

### `on_call_callbacks` (new table)

```
id
on_call_assignment_id   FK -> on_call_assignments.id, cascade delete
starts_at               DATETIME
ends_at                 DATETIME
timestamps
```

App-level validation: `ends_at` strictly after `starts_at`, and `starts_at` must fall within `[assignment.starts_at, assignment.ends_at]` — `ends_at` is deliberately unconstrained (a callback that runs long is still a valid callback; see Non-goals).

## `OnCallController` (new)

Pure CRUD — no service class needed, since (per the mechanism below) all the labor-law-relevant logic already lives inside `polishLaw()`'s existing state walk and this controller never duplicates it.

- `GET /on-call-assignments?shift_group_id=&month=` — list assignments (each with its `callbacks`) for a group/month, for the frontend view.
- `POST /on-call-assignments` — create (`shift_group_id`, `user_id`, `starts_at`, `ends_at`).
- `DELETE /on-call-assignments/{onCallAssignment}` — remove (cascades its callbacks).
- `POST /on-call-assignments/{onCallAssignment}/callbacks` — record a callback (`starts_at`, `ends_at`).
- `DELETE /on-call-callbacks/{onCallCallback}` — remove a mis-logged callback.

All five routes sit in the same `admin:schedule` middleware group as `ScheduleController::assign()`/`unassign()` and every other schedule-mutation endpoint.

## `polishLaw()` integration

No new blocking check is added anywhere in `polishLaw()`. Being on-call is invisible to the generator; only a callback has any effect, and it has that effect purely by feeding the existing state walk more data — the same "one more precompute, one more thing recorded" pattern settlement periods and shift streaks each already established.

Alongside the existing settlement-period precompute (`ShiftAutoAssignController.php` around line 558, building `$weekKeyByDate`/`$weekCapByDate`/`$weekEndByDate` from `$shifts`) and the existing shift-streak precompute, add:

1. **Load callbacks for the group/month.** Query `OnCallCallback` rows whose `[starts_at, ends_at]` overlaps the requested month, joined through `on_call_assignments` filtered to the current `shift_group_id`, for employees in the current candidate pool (`$employees`).
2. **Widen the date-based precompute maps.** `weekKeyByDate`/`weekCapByDate`/`weekEndByDate` are currently built by iterating `$shifts` only. A callback can land on a date with no shift record at all (called in on an otherwise-scheduled day off), so these maps must also gain an entry for every distinct `starts_at` date among the loaded callbacks, computed the same way (via `SettlementPeriodCalculator::periodContaining()`/`weekFor()`). A callback that crosses midnight is attributed entirely to its `starts_at` date — the same convention `Shift.date` already uses for overnight shifts (a single date field, unadjusted for the end time crossing midnight) — so no separate entry is needed for the date it ends on.
3. **Merge callbacks into the pre-population walk.** The existing pre-population loop (`ShiftAutoAssignController.php` around lines 611-630) walks each shift's existing assignments in query order (`$shifts` is already `orderBy('date')->orderBy('shift_number')`) and updates `$employeeState`. Extend this so that, per employee, callbacks are merged into the same chronological walk as their real shift assignments (sorted by `starts_at`/shift datetime together), and each callback updates `$employeeState` exactly the way a shift assignment does:
   - `last_shift_end = callback.ends_at`
   - `weekly_hours[weekKey] += callback.duration_hours` (duration is simply `ends_at - starts_at`; no need for `shiftTiming()`'s overnight-shift inference, since a callback already has explicit datetimes)
   - `weekly_days[weekKey][callback.starts_at date] = true`
   - `total_hours += callback.duration_hours`
4. This all happens **before** the main per-shift eligibility loop runs, same as today's shift pre-population — so a regular shift immediately after a callback correctly sees the reduced rest window and increased weekly hours through the existing 11h-rest, weekly-cap, 5-day, and 35h-rest checks, unmodified.

If a group has zero on-call assignments/callbacks, step 1's query is empty and the rest of the extension is a no-op — matching the "zero-cost when unconfigured" pattern already used for settlement periods and streaks.

## Settlement Periods reporting

`SettlementPeriodController::show()` currently sums only `ShiftAssignment` hours into `$employeeWeekHours`. Since callbacks count toward `weekly_hour_cap` in live enforcement, the report must include them too or it will silently under-report anyone who had a callback. Add callback hours into the same per-week aggregation, keyed by the same `SettlementPeriodCalculator::weekFor()` match used for shifts — one more data source feeding the existing totals, not a parallel calculation.

## Frontend

**New `OnCallView.vue`**, nav-linked, mirroring `SettlementPeriodsView.vue`'s shape:

- Group picker + month/period navigation (reusing the existing period-stepping pattern).
- A list of on-call assignments for the period: employee, start, end, with their logged callbacks (start/end/duration) nested underneath each.
- "+ Add on-call period" modal (user, start, end) and a "+ Log callback" action per assignment (start, end) — simple forms, no calendar-drag UI.
- Remove controls for both assignments and callbacks.

## Error handling

- `ends_at > starts_at` validated on both `on_call_assignments` and `on_call_callbacks` (422 on violation).
- A callback's `starts_at` must fall within `[assignment.starts_at, assignment.ends_at]` (422, clear message referencing the parent window).
- No overlap validation anywhere, by design (see Non-goals) — creating an on-call assignment or callback never fails due to another existing one.
- `polishLaw()`'s new precompute steps introduce no new failure mode: an empty callback set behaves identically to the feature not existing.

## Testing

- Backend: model/relationship tests (`OnCallAssignment` cascade-deletes its `OnCallCallback` rows), `OnCallController` CRUD + validation tests (rejects `ends_at <= starts_at`, rejects a callback whose `starts_at` falls outside its parent's window, accepts a callback whose `ends_at` extends past the parent's `ends_at`), a `polishLaw()` integration test proving a callback correctly blocks a too-soon regular shift via the existing 11h-rest check and correctly adds to weekly-cap totals (reusing the existing rule, not a new one), a `SettlementPeriodController` test proving callback hours appear in the report.
- Frontend: none planned by default, matching the established precedent in this codebase — admin CRUD screens like this (`ScheduleView.vue`'s settings modals, `TeamAvailabilityView` before its revert) have never carried component tests here.

## Open questions for implementation

None outstanding — all resolved during brainstorming.
