# Manager Permissions for Employee Shift Preferences — Design

Status: draft, awaiting user review
Part 1 of 4 in the broader feature set (shift streaks, settlement periods, streaks, on-call) — see context below.

## Context

Today, editing a `UserAvailability` entry (an employee's "I'm unavailable" or "I prefer to work" date-range preference) is only possible from the owning employee's own account. `UserAvailabilityController::destroy()` hardcodes an ownership check; `store()`/`index()` implicitly scope to the authenticated user. There is no manager role that can act on behalf of a team.

Separately, the preference model itself can only express date-range availability/unavailability — it cannot express "I prefer night shifts" (a shift-type preference), which was one of the motivating examples from the original request. This design extends the preference model to support that, and grants team managers edit access to both preference kinds for their team's members.

This is sub-project 1 of 4 in a larger set of scheduling features (shift streaks, configurable settlement periods, on-call duty). It was chosen to go first because it's fully independent of the other three — it touches only the preferences subsystem and authorization, not the schedule generator.

## Goals

- A manager can create/edit/delete `UserAvailability` entries — both date-range availability and shift-type preferences — for employees on a team they manage.
- Employees retain full self-service control over their own entries (unchanged).
- Employees can tell when a preference was set by their manager rather than themselves.
- "Team" is modeled so that co-management (multiple managers per team, one manager across multiple teams) is possible without a schema change later.

## Non-goals

- No changes to the schedule generator/auto-assign logic in this sub-project — shift-type preferences are stored but not yet consumed by `ShiftAutoAssignController`. Wiring the generator to actually honor these preferences is out of scope here (candidate follow-up once this data exists).
- No change to `UserGroup`'s existing membership semantics (`user_group_user` pivot) — this only adds a manager-ship pivot alongside it.
- No new "manager" value on `users.role`. Manager-ship is granted via group membership in the new pivot, orthogonal to the role string, so an admin can grant/revoke it without changing someone's role.

## Data model changes

### `user_group_managers` (new table)

```
user_group_id  FK -> user_groups.id
user_id        FK -> users.id
```

Composite unique on (`user_group_id`, `user_id`). Many-to-many: a group can have zero or more managers; a user can manage zero or more groups. A user's "team" for permission purposes is the union of members (via `user_group_user`) across every `UserGroup` they appear in here.

### `shifts.shift_type` (new nullable column)

Free-form string (e.g. `day` / `evening` / `night`), set per shift row rather than derived from a group-level template — the shift_number → time-of-day mapping isn't guaranteed stable within a group, so labeling per-row is the source of truth. No values are enforced by the DB; the frontend offers a fixed set of suggestions but the column stays a plain string to avoid a migration if new labels are needed later.

### `user_availabilities` changes

- `shift_type` (new nullable string column): when set alongside `type='preferred'`, means "prefer this shift type during this date range" instead of "prefer to work at all." When `type='unavailable'`, `shift_type` is ignored/must be null (unavailability isn't shift-type-scoped — you're either available or not).
- `created_by_user_id` (new nullable FK -> users.id): the acting user at creation time. Equal to `user_id` for self-service entries; different when a manager created it. Nullable to avoid backfill issues for existing rows (backfilled to `user_id` in the migration for existing data, since we know today every existing row was self-created).

## Authorization

New `UserAvailabilityPolicy`:

- `manage(User $actor, UserAvailability $availability): bool`
  - true if `$actor->canManageSchedule()` (existing admin/schedule_maintainer check)
  - true if `$actor->id === $availability->user_id` (self)
  - true if `$actor` appears in `user_group_managers` for any `UserGroup` that `$availability->user_id` belongs to
  - false otherwise

- A parallel `manageFor(User $actor, int $targetUserId): bool` (same logic, without needing an existing row) is used by `store()` before creating a new entry for someone else.

`UserAvailabilityController`:
- `store()`: accepts optional `user_id` in the payload. If absent, defaults to the acting user (today's behavior, unchanged). If present and different from the acting user, runs `manageFor` — 403 with `"You do not manage this employee's team."` on failure. Sets `created_by_user_id` to the acting user's id in both cases.
- No `update()` route exists today (confirmed: only `index`/`store`/`destroy`/`forMonth`) — edits are delete+recreate from the frontend. This design keeps that pattern; no new PATCH route is introduced.
- `destroy()`: replace the inline ownership check with `$this->authorize('manage', $userAvailability)`.
- `index()`/`forMonth()`: unchanged (already readable across users, used by schedule views).

## Frontend

- `OfficeAvailabilityView.vue` (currently read-only overview): add an edit affordance, visible only for employees within a `UserGroup` the logged-in manager manages. Reuses the same form component `MyAvailabilityView.vue` uses for creating/editing entries, parameterized with a target `user_id`.
- `MyAvailabilityView.vue`: when rendering an entry where `created_by_user_id !== user_id`, show a small badge — "Set by {manager name}" — sourced from an eager-loaded `createdBy` relation on the API response.
- Preference form (shared component): add a shift-type selector, shown only when `type='preferred'` is selected — mutually exclusive with the existing date-range-only "prefer to work" option (UI clarifies these are two different kinds of "preferred": general availability vs. shift-type preference within a range).

## Error handling

- Policy denial on `store()`/`destroy()` returns 403 with an actionable message distinguishing "not your team" from generic unauthorized, so a manager understands why (rather than assuming a bug).
- Validation: `shift_type` on `user_availabilities` rejected (422) when `type='unavailable'` — prevents nonsensical combinations at the API boundary rather than silently ignoring the field.

## Testing

- Policy unit tests: admin/schedule_maintainer can always manage; owner can manage own; manager of the right group can manage a team member's entry; manager of an unrelated group cannot; a peer (non-manager, non-owner) cannot.
- Feature tests on `UserAvailabilityController`: manager `store()`-ing for a team member succeeds and sets `created_by_user_id` correctly; `store()`-ing for a non-team member returns 403; `destroy()` follows the same matrix.
- Migration test: `user_group_managers` supports one group with two managers, and one manager across two groups (both round-trip correctly).
- Model test: `shift_type` + `type='unavailable'` is rejected by validation.

## Open questions for implementation

- Confirm the fixed set of shift-type label suggestions for the frontend dropdown (e.g. day/evening/night, or something finer-grained already used informally, like the existing `color_label` values on shifts).
