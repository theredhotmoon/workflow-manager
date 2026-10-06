# Shift Type Labelling — Design

Status: draft, awaiting user review
Follow-up to Shift Streaks (shipped 2026-09-14) and the Playwright frontend testing migration (shipped 2026-10-06, PR #8).

## Context

Shift Streaks rules are keyed by `shifts.shift_type` ("3 *night* shifts in a row, then 2 days off"), and both `ScheduleController::assign()/unassign()` and `ShiftAutoAssignController::polishLaw()` already read `$shift->shift_type` to drive `ShiftStreakTracker`. But **nothing in the application writes `shifts.shift_type`**: it is only listed in `Shift::$fillable`. The column was introduced by the manager-permissions sub-project (`2026-09-07-manager-permissions-design.md`, "free-form string set per shift row… the frontend offers a fixed set of suggestions"), whose UI and write paths were reverted with that sub-project; Shift Streaks later restored only the column (`2026_09_14_120000_ensure_shift_type_on_shifts_table.php`). As a result, streak rules can be configured but can never trigger.

The Playwright e2e migration surfaced this: `frontend/e2e/settlement-periods/report.spec.js` can only assert an empty streak cell, because `night: 2/3` / `night: resting until …` are unreachable end-to-end.

Established patterns this design follows:
- The Generate Month template already carries per-slot settings (`shift_times[i]` with `start_time`, `end_time`, `checklists`, `color`, `color_label`), applied only to newly created slots (`ScheduleController::generate()`).
- Each shift already has small dedicated edit endpoints in the `admin:schedule` route group: `PUT /schedule/shifts/{shift}/times`, `/color`, `/work-mode`, `/checklist`.
- The shift edit modal in `ScheduleView.vue` (`openTimeEditor()` / `saveShiftTimes()`) saves times, then colour as a second call.
- Manual assign/unassign update streak state through `ShiftStreakTracker::recordAssignment(…, advanceWatermark: true)` / `recordUnassignment()` followed by `persist()`.

## Goals

- An admin/schedule_maintainer can give shifts a type (e.g. `night`, `day`) so streak rules trigger in manual assignment and in auto-assign.
- Types can be set per slot when generating a month, edited on a single shift afterwards, and bulk-set per slot for an existing month.
- Changing the type of a shift that already has assignments keeps each assigned employee's streak counters consistent with the new label.
- Type matching is robust to case and surrounding whitespace.
- The settlement report's streak column can show real `night: 2/3` and `night: resting until …` states, and the e2e suite asserts them.

## Non-goals

- A managed list/table of shift types: types stay free-form text with suggestions.
- Deriving a type from shift times.
- Type-scoped availability preferences (manager-permissions' other use of `shift_type`, reverted).
- Rebuilding streak history from scratch; re-booking reuses the tracker's existing incremental assign/unassign semantics.
- Restructuring `ScheduleView.vue` beyond the additions below.

## Normalisation

One helper, used by every write path for both shift types and streak-rule types: `trim`, then `mb_strtolower`; an empty or whitespace-only value becomes `null` (for shifts) and fails validation (for streak rules, which require a non-empty type).

- Location: a small static helper (e.g. `App\Support\ShiftType::normalize(?string): ?string`).
- `ShiftGroupController::syncStreakRules()` normalises each `rules.*.shift_type` **before** its duplicate-type check, so `"Night"` and `"night"` in one request are rejected as duplicates.
- A migration normalises existing `shifts.shift_type` and `shift_streak_rules.shift_type` values. If normalising streak rules would make two rules in the same group collide on `(shift_group_id, shift_type)`, the migration aborts with a message naming the group and types; it never deletes rules silently.

## Backend

### Generate Month

`POST /schedule/generate` accepts `shift_times.*.shift_type` (`sometimes`, `nullable`, `string`, `max:50`) and writes the normalised value on newly created slots alongside `color`/`color_label`. As today, existing slots are not touched.

### Single-shift edit

New `PUT /schedule/shifts/{shift}/type` → `ScheduleController::updateShiftType()`, in the `admin:schedule` group next to `/times` and `/color`.

- Body: `{ shift_type: string|null }` (`present`, `nullable`, `string`, `max:50`).
- If the normalised value equals the current value: no-op, return the shift unchanged with `rebooked: 0`.
- Otherwise, inside one `DB::transaction`:
  1. Update `shift_type`.
  2. If the shift has assignments and its group has streak rules, load states for the assigned users and, per assigned user: `recordUnassignment($states, $rules, $userId, $oldType)` then `recordAssignment($states, $rules, $userId, $newType, $shift->date, advanceWatermark: true)` — the same calls manual unassign/assign make. Then `persist($states)`.
- Response: `{ id, shift_type, rebooked }` where `rebooked` is the number of assigned employees whose counters were re-booked (0 when no rule matches either type).

### Bulk edit per slot

New `PUT /schedule/shift-types` → `ScheduleController::updateSlotShiftTypes()`, same route group.

- Body: `{ shift_group_id, month (Y-m), shift_number (int ≥ 1), shift_type: string|null }`.
- Updates every shift in that group/month/slot whose normalised type differs, re-booking assigned employees exactly as the single-shift edit does, all in one transaction.
- Response: `{ updated, rebooked }`.

### Payloads

`shift_type` is added to the shift objects returned by `GET /api/schedule` (`ScheduleController`, `$shiftData`) and `GET /shifts-by-date`.

### Unchanged

Manual assign/unassign and `polishLaw()` need no changes: they already read `$shift->shift_type`.

## Frontend

### Generate Month template

Each slot row in the Generate Month config modal gets a **Type** text input next to the colour picker, bound to `shift_times[i].shift_type`; default preset rows start empty. Sent with the existing generate request.

### Single-shift edit

The shift edit modal (times + colour) gets a **Shift type** input, initialised from `shift.shift_type`. `saveShiftTimes()` calls `PUT /schedule/shifts/{id}/type` only when the value changed, after the colour call, and updates `shift.shift_type` from the response. When `rebooked > 0` the success toast says so (e.g. "Shift updated; streak counters adjusted for 2 employees.").

### Bulk edit

New component `frontend/src/components/ShiftTypeBulkEditor.vue` (kept out of `ScheduleView.vue`, already ~2,850 lines), opened by a **Set shift types** button next to Generate Month — shown to schedule managers in single-group mode only, like Generate Month.

- Props: the active group id, month key, and the month's shifts (to list slots and their current types).
- Lists each slot number present in the month with its current type, or *mixed* when days differ, and an input per slot.
- Save calls `PUT /schedule/shift-types` once per changed slot, then emits an event so `ScheduleView` refetches the schedule; a toast summarises `updated` / `rebooked`.

### Suggestions

Every type input uses a `<datalist>` of the active group's streak-rule types plus the distinct types already present in the month, so common values are one click away without restricting free text.

### Display

Shift chips show the type next to the slot number — `S1 · night` — in both single-group and All Groups views; nothing extra when the type is empty.

### Accessibility

New inputs get `id`/`for` label associations, matching the convention established in the Playwright migration, so tests can use `getByLabel`.

## Error handling

- Validation failures (type longer than 50 chars, bad month/slot, unknown group) return 422 through Laravel validation; the UI shows the API message in a toast, as existing shift editors do.
- Non-managers get 403 from the `admin:schedule` middleware.
- Re-booking runs in the same transaction as the type update: if anything fails, neither the type nor any counter changes.
- Bulk edit on a slot with no shifts in that month returns `updated: 0` (not an error).

## Testing

### Backend (Pest, real Postgres test DB)

- Normaliser: `" Night "` → `"night"`; `""`/whitespace → `null`.
- Generate: stores per-slot `shift_type` normalised; `null` when omitted; existing slots untouched.
- `PUT /schedule/shifts/{shift}/type`: updates + normalises; 422 over 50 chars; 403 for a non-manager; same value is a no-op (`rebooked: 0`, no tracker writes).
- Re-booking, with a group rule `night` (streak 3, rest 2) and an assigned employee:
  - night → day decrements their counter;
  - day → night increments it, and as their 3rd consecutive night sets `resting_until`;
  - an unassigned shift causes no tracker writes;
  - a failure mid-transaction rolls back the type and the counters.
- Bulk endpoint: changes only the given group/month/slot; returns `updated`/`rebooked`; re-books assigned shifts.
- `syncStreakRules`: stores rule types normalised; rejects `"Night"` + `"night"` in one request as duplicates.
- Migration: normalises existing values; aborts on a rule collision without deleting.
- `GET /api/schedule` includes `shift_type`.
- Gap closed end to end on the backend: after labelling via the new endpoint, `polishLaw()` auto-assign respects the streak (an employee who hit N nights is not assigned during their rest window).

### E2E (Playwright, existing conventions: unique groups, reserved months, reload/server-side verification, positive guards, sabotage-proven assertions)

- New fixture `setShiftTypes(context, { shiftGroupId, month, shiftNumber, shiftType })` in `frontend/e2e/fixtures/api-setup.js` (bulk endpoint).
- `settlement-periods/report.spec.js` streak test upgraded: label the slot `night`, assign two consecutive night shifts → assert `night: 2/3`; assign a third → assert `night: resting until <date>` with the date computed from `rest_days`. Replaces today's empty-cell assertion.
- New `schedule/shift-types.spec.js` (new reserved months):
  - Generate with a template Type → chips show `S1 · night`.
  - Single-shift edit persists across reload.
  - Bulk editor relabels every S1 in the month (verified after reload and via the API).

### Docs

CHANGELOG entry (per the project's changelog convention). No README change.

## Open questions for implementation

- Exact placement of the **Set shift types** button and the Type input in the template rows — follow the surrounding layout in `ScheduleView.vue`.
- Whether the `/shifts-by-date` consumer needs to display the type anywhere today (payload addition only unless a view uses it).
