# Changelog

All notable changes to the Shift Changer application are documented here.

---

## 2026-10-09 — 🕕 Shift times in Warsaw time: deadlines, current shift, overnight shifts

- **Shift times were read as UTC.** A shift's date and start/end times are Warsaw wall-clock, but the server compared them with the UTC clock, so everything was off by one hour in winter and two in summer: a 06:00 shift with a 30-minute handover deadline showed *140 min left* at 06:10 instead of 20, a handover filed up to two hours late was recorded as on time, at 07:00 the dashboard still showed the night shift as current, and between local midnight and 01:00/02:00 "today" was still yesterday (today's checklists missing from the dashboard, day-off requests still accepted for yesterday's shifts, the settlement page's current period a day behind on its first day). Shift starts, ends and deadlines are now built in the business timezone (`BUSINESS_TIMEZONE`, default `Europe/Warsaw`), which also keeps them right across the DST changes. Storage and the app clock stay UTC.
- **Overnight shifts turned "overdue" at midnight.** A 22:00–06:00 shift's checklist jumped from the dashboard's current list to *Overdue* at midnight. It now stays current until the shift ends at 06:00 the next day (a shift whose end time is at or before its start time ends the next day), then becomes overdue if it was required.

> **Deploy note:** deadlines and on-time flags computed before this release used UTC. Handovers already filed keep the `submitted_on_time` they were stored with (nothing is recomputed), so some filed up to an hour (winter) or two (summer) late may still show as on time.

### Backend
| What | Details |
|------|---------|
| Added | `BusinessDate::now()`, `shiftStart(Shift)`, `shiftEnd(Shift)` (business timezone; overnight shifts end the next day; no start time → 00:00, no end time → end of the date) |
| Changed | `ShiftChecklist::getDeadlineDatetime()` takes the `Shift` and returns its deadline in the business timezone. `deadline_at` in `GET /api/my-checklist-alerts` and `GET /api/my-shifts` is ISO-8601 with the Warsaw offset (e.g. `2026-07-15T06:30:00+02:00`; it used to be the wrong instant with `+00:00`) |
| Fixed | `GET /api/my-checklist-alerts` (minutes left, today, overnight shifts still on), `POST /api/checklist-submissions` (`submitted_on_time`), `GET /api/current-shift` (by instants; a shift whose end equals its start also counts as overnight), `POST /api/shift-change-requests` (past-shift check) and the settlement calculator's current period use the business timezone |

### Tests
- Pest `BusinessTimezoneTest`: minutes left and late/on-time handovers at Warsaw hours, `/my-shifts` offset, current shift at 07:00 and at local midnight, "today" between local and UTC midnight, past-shift day-off request, overnight shift current until 06:00 then overdue, DST spring-forward and fall-back days (2029-03-25, 2029-10-28), settlement period by the Warsaw date.

---

## 2026-10-09 — 🗓️ Schedule screen: remote shifts, safe drag-moves, correct closure dates, maintainer access

- **Remote shifts showed as Office after a reload.** The month view didn't send a shift's work mode, so a remote shift came back as 🏢, the toggle needed two clicks, and **Switch to Remote** in the "office closed" dialog of Generate Month switched nothing. Editing a day's shift count also dropped the day-off request chip, because that answer had a different shape. Every schedule answer now has the same shape, with the work mode and the request fields.
- **Moving a chip and then cancelling a warning unassigned the employee.** The old shift was removed the moment the chip left it, before any back-to-back / unavailable / delegation / rest-day dialog. A move is now one request: the old assignment goes and the new one is created together, and a Cancel, a refused rest day or any error leaves the employee on the original shift (the chip comes back).
- **Office closures landed a day early east of UTC.** In Warsaw, clicking the 15th on the Office Availability calendar created a closure on the 14th, existing closures showed a day late and "today" could be wrong. Calendar dates are now built in local time everywhere (one shared helper), including the checklist form's default date, the newbie star and the CSV file name.
- **Schedule maintainers hit silent permission errors.** Delegations, newbie periods, office closures and closure conflicts, checklist templates and shift colours were admin-only, so for maintainers the overlays were empty, the delegation warning never appeared, auto-fill's player list stayed empty and colours were missing. Maintainers can now read them (changing them stays admin-only); shift colours are visible to everyone. When one of these loads fails, the screen now says the check couldn't run instead of treating it as "nothing found". Creating, renaming, deleting a shift group and its settlement settings are shown to admins only (they always were admin-only on the server).

### Backend
| What | Details |
|------|---------|
| Changed | `GET /api/schedule` and `PUT /api/schedule/day-shifts` build shifts with one shared builder: both include `work_mode` (default `office`) and the assignment's `has_change_request`, `change_request_reason`, `change_request_id`, `change_request_date` |
| Added | `POST /api/schedule/assign` takes optional `move_from_assignment_id` (the same user's assignment, not on the target shift; 422 otherwise). The source is removed exactly as by unassign (rest policies cleaned up; its day-off requests are deleted with it by the existing foreign-key cascade) and the new assignment created in one transaction; a 409/422 rolls everything back. The 201 body adds `moved_from_assignment_id` |
| Changed | Moved to the `admin:schedule` group (admins and schedule maintainers): `GET /api/delegations/month`, `GET /api/newbie-periods/month`, `GET /api/office-closures`, `GET /api/office-closures/conflicts`, `GET /api/checklist-templates`. `GET /api/shift-colors` is open to every signed-in user. All writes stay admin-only |

### Frontend
| What | Details |
|------|---------|
| Changed | Schedule: dragging a chip to another shift sends one assign with `move_from_assignment_id` (also on the retries after the rest-day and confirm dialogs); no more `DELETE` on drag. Cancel or a failed assign reloads the board quietly |
| Added | `src/dates.js` (`localDateStr`, `todayStr`) replacing `toISOString()` dates in Office Availability, Schedule, Users, Checklist fill/history and the copies in My Availability, My Shifts and Dashboard |
| Changed | Schedule: failed delegation / newbie / closure loads and availability checks show a warning; a failed closure check before Generate Month asks before generating. Delegations, newbies and templates are only loaded for schedule managers. Auto-fill's player list says when newbie periods or delegations couldn't load |
| Changed | Schedule: New Group, rename, delete and group settings only for admins |

### Tests
- Pest `ScheduleScreenTest` (payload shape, generate-as-remote, atomic moves incl. rollbacks and 422s, maintainer/employee access); unit `dates.test.js` (runs in `Europe/Warsaw`); e2e `schedule/move-assignment.spec.js` (cancelled move, move, remote after reload, maintainer delegation warning) and a Warsaw-timezone closure click in `office-availability/closures.spec.js`. The drag helper moved to `e2e/fixtures/drag.js`; a `maintainerPage` fixture logs in the seeded schedule maintainer.

---

## 2026-10-09 — 🔒 Closed three privilege gaps: handover confirmation, procedure ownership, admin status

- **Anyone could confirm anyone's handover.** The outgoing employee (or any user) could confirm a handover in place of the incoming one, which defeats the two-person check. Now only the incoming user named on the handover can confirm it (admins can't confirm on someone's behalf); everyone else gets *"Only the incoming user named on this handover can confirm it."* The confirmation records who confirmed. Filing is closed too: an employee files a handover only as its outgoing employee themselves (the Outgoing select is fixed to them), the incoming employee must be someone else, and an admin filing on someone's behalf can't name themselves as incoming. (A handover with no incoming employee still completes at once, as before.) **View Pending Confirmations** now lists only handovers naming you as the incoming user (it used to list your own outgoing ones too); Checklist History still shows both directions.
- **Any employee could take over a procedure or rearrange the procedure tree.** Changing a procedure's owner and moving it in the tree are now admin-only on the server too (the screens already showed these controls to admins only).
- **Admin status had two sources of truth** (an `is_admin` flag and `role`), and screens disagreed: unticking *Admin* left a user whose role was admin an admin; admins created on the Users screen got no change-request alarms; role-only admins were picked by auto-fill. Now `role` (**Admin** / **Schedule maintainer** / **Employee**) is the only one. The Users screen has a **Role** select instead of the Admin switch and shows a role badge. An admin can't change their own role, and the last active admin can't be demoted or deactivated (*"There must be at least one active admin. Make someone else an admin first."*).

> **Deploy note:** a migration converts `is_admin` into `role` (everyone with `is_admin = true` becomes `role = 'admin'`; existing roles are kept otherwise) and then **drops the `is_admin` column**. `down()` re-adds it from `role`. **Before migrating**, run `SELECT id, email, role, is_admin FROM users WHERE (role = 'admin') <> is_admin;` and review the rows: `is_admin = false` with `role = 'admin'` **stay admins** (often people someone tried to demote with the old Admin checkbox, which never touched `role`), and `is_admin = true` with `role = 'schedule_maintainer'` **become admins**. Demote anyone who shouldn't be an admin on the Users screen afterwards. Another migration adds `checklist_submissions.confirmed_by_id`. API clients must send `role` instead of `is_admin` when creating or editing users (`is_admin` is now refused with 422); users' JSON still carries a read-only `is_admin` derived from `role`.

### Backend
| What | Details |
|------|---------|
| Changed | `PUT /api/checklist-submissions/{id}/confirm` answers 403 unless the caller is the submission's `incoming_user_id`, and sets the new nullable `confirmed_by_id` (FK `users`, restrict on delete); the response includes it and `confirmed_by` |
| Changed | `POST /api/checklist-submissions`: a non-admin's `outgoing_user_id` must be themselves (422 otherwise); `incoming_user_id` must differ from `outgoing_user_id`, and from the caller when an admin files on someone's behalf (422). Ids are compared as integers |
| Added | `GET /api/checklist-submissions/pending-confirmations`: the caller's handovers awaiting confirmation as incoming user (plain array). `GET /api/checklist-submissions` (history, both directions) is unchanged |
| Changed | `PUT /api/procedures/{id}/move` and `PUT /api/procedures/{id}/change-owner` moved into the `admin` route group (403 for employees and schedule maintainers) |
| Changed | `role` is the single source of truth: `User::isAdmin()` checks `role === 'admin'` only; new `User::ROLES` constants and `admins()` / `notAdmins()` / `scheduleManagers()` scopes. Procedure and FAQ owner-or-admin checks, change-request alarm recipients (`AlarmService`) and the auto-fill pools (admins excluded) all go through them |
| Changed | `POST /api/users` and `PUT /api/users/{id}` take `role` (`admin` / `schedule_maintainer` / `employee`, default `employee`) and refuse `is_admin` (422). Changing your own role or demoting the last active admin answers 422; deactivating the last active admin too. `GET /api/users`, `GET /api/users/{id}`, the schedule's employee pool and `GET /api/employees` return `role`; users' JSON carries a read-only `is_admin` (derived from `role`) whenever `role` is loaded |
| Changed | Seeders and `UserFactory` set `role` (factory default `employee`) |

### Frontend
| What | Details |
|------|---------|
| Added | `src/roles.js`: `isAdmin(user)`, `canManageSchedule(user)`, `ROLE_OPTIONS`, `roleLabel()`. The router guard, sidebar, dashboard, schedule, procedures, procedure editor and auto-fill player list use it instead of reading `is_admin` / `role` themselves |
| Changed | Users screen: **Role** select (disabled with *"You cannot change your own role."* when editing yourself) instead of the Administrator switch; Admin / Schedule maintainer badge in the list |
| Changed | Fill checklist: the **Outgoing Employee** select is fixed to yourself unless you're an admin; **Incoming Employee** leaves out the outgoing employee and yourself |
| Changed | Fill checklist → **View Pending Confirmations** uses the new endpoint. A 403 on confirm shows the server's message and goes back to the pending list |
| Fixed | The auto-fill player list now leaves admins out, matching the server (it used to read an `is_admin` that `/api/employees` never returned) |

### Tests
| What | Details |
|------|---------|
| Added | `tests/Feature/PrivilegeGapsTest.php`: only the incoming user confirms (outgoing, third user, maintainer and admin get 403) and `confirmed_by_id` is set; filing forgeries refused (employee filing from a colleague, naming themselves as incoming; admin on behalf naming themselves), admin on behalf of a third person allowed; pending list excludes your own outgoing handover; move/change-owner 403 for employee and maintainer, admin succeeds; one read and one write route per `admin` / `admin:schedule` group per role; the migration's `is_admin` → `role` mapping and `down()`; a demoted admin loses admin routes; role on create/update with `is_admin` refused; self-demotion and last-admin demotion refused; Users-screen admins get change-request alarms; admins left out of all three auto-fill modes |
| Changed | Existing tests create users by `role` only |
| Added | `src/roles.test.js` (unit); `e2e/users/roles.spec.js` (role select on a throwaway user, checked through the API; own role locked); `e2e/checklists/fill.spec.js` checks the outgoing employee's Outgoing select is fixed and they don't see their own handover to confirm while the incoming admin does; its handovers are filed by the outgoing employee. `roles.spec.js` deactivates its throwaway user at the end, even on failure |

---

## 2026-10-09 — 🛡️ Editing or deleting a checklist template, or deleting a user, no longer wipes history

Three data-loss bugs are fixed:

- **Editing a checklist template deleted every past handover response.** Saving a template recreated all its groups and items, and the responses cascaded away with the old items. Now groups and items are updated in place. A removed item (or group) is *retired*: it is no longer offered for new handovers, but past handovers still show it with its answer.
- **Deleting a template deleted its whole handover history.** "Delete" is now **Archive**: the template disappears from the builder, from filling and from the shift checklist pickers, and shifts from tomorrow on stop requiring it. Its submitted handovers stay in the history under its name. Past shifts and today's (whose handover may already be in) keep the checklist they had, but an archived template no longer raises overdue alerts.
- **Deleting a user deleted everything they created or owned** (procedures, delegations, office closures, handovers, audit logs …). Users are now **deactivated** instead of deleted. A deactivated user can't log in (*"This account is deactivated."*), and a session that is already open is signed out on its next request: the app returns to the login page and shows that message. They no longer appear in the schedule's employee pool, in auto-fill or in the pickers, but their name stays wherever history shows it. The confirm dialog shows how many shifts and on-call duties the user has from tomorrow on and offers to remove them (on by default); today's shift may already be worked, so it is kept. Delegation memberships and newbie periods are left as they are. Deactivated users are hidden on the Users screen unless **Show deactivated** is on, and can be **reactivated**.

Also fixed: saving a deactivated (inactive) template no longer re-activates it.

> **Deploy note:** take a database backup before deploying. These migrations change foreign-key delete behaviour: hard-deleting a template, group, item or a user with history now fails instead of cascading. `DELETE /api/users/{id}` is gone (405). Use `POST /api/users/{id}/deactivate` / `reactivate` instead.

### Backend
| What | Details |
|------|---------|
| Added | Nullable `archived_at` on `checklist_templates`, `checklist_groups`, `checklist_items`; nullable `deactivated_at` on `users` |
| Added | `config('app.business_timezone')` (`BUSINESS_TIMEZONE`, default `Europe/Warsaw`) and `App\Support\BusinessDate::today()`: "today" for shift dates in the business timezone (the app clock stays UTC). Used by the archive and deactivate cleanups below, so between local midnight and 02:00 "tomorrow" is not a day early |
| Changed | `PUT /api/checklist-templates/{id}` updates groups/items by `id` (ids must belong to the template, 422 otherwise), adds rows without an id, and sets `archived_at` on rows left out instead of deleting them. `is_active` only changes when sent. Editing an archived template answers 422 |
| Changed | `DELETE /api/checklist-templates/{id}` archives the template (`"Template archived."`) and deletes its `shift_checklists` rows for shifts dated after today (business timezone) |
| Changed | `ChecklistTemplate::groups()` / `ChecklistGroup::items()` return current rows only; `allGroups()` / `allItems()` include retired ones. `GET /api/checklist-templates` leaves out archived templates unless `include_archived=1`; `/active` leaves them out |
| Changed | `GET /api/checklist-submissions/{id}` serves the template's current groups/items plus retired ones that this submission answered |
| Changed | New handovers, `POST /api/schedule/generate` and `PUT /api/schedule/shifts/{id}/checklist` reject archived templates (re-saving a shift that already has one keeps it). `/api/my-checklist-alerts` skips archived templates. A handover to a deactivated outgoing/incoming user answers 422 with a clear message |
| Added | `POST /api/users/{id}/deactivate` (`remove_future_assignments`: in the same transaction removes the user's shift assignments dated after today and on-call duties starting tomorrow or later, settles their pending change requests and runs `RestPolicyJanitor`; returns `removed_assignments` / `removed_on_call`; an admin can't deactivate themselves) and `POST /api/users/{id}/reactivate`. `GET /api/users/{id}` returns `future_assignments_count` and `future_on_call_count`. Delegation memberships and newbie periods are not touched |
| Removed | `DELETE /api/users/{id}` (405) |
| Added | `active` middleware on the `auth:sanctum` group: a deactivated user gets 401 *"This account is deactivated."* and their session is invalidated. Login answers 403 with the same message (only after the password matched) |
| Changed | Deactivated users are left out of the schedule's employee pool, the three auto-fill modes, `GET /api/employees` and `GET /api/users` (both accept `include_deactivated=1`), and admin/maintainer alarms. Assigning shifts, on-call, delegations, newbie periods, procedure owners and handover users reject them (a procedure's current deactivated owner is still accepted when it is edited) |
| Changed | FKs moved from cascade to **restrict**: `checklist_responses.checklist_item_id`, `checklist_items.checklist_group_id`, `checklist_groups.checklist_template_id`, `checklist_submissions.checklist_template_id`. On `users.id`: `procedures.owner_id`, `delegations.created_by`, `archived_delegations.created_by`/`archived_by`, `delegation_logs.performed_by`, `archived_delegation_logs.performed_by`, `office_closures.created_by`, `newbie_periods.created_by`, `checklist_submissions.outgoing_user_id`, `procedure_faq_items.created_by`, `procedure_faq_replies.created_by`, `procedure_owner_changes.new_owner_id`/`changed_by_id`, `procedure_feedbacks.user_id`, `shift_assignments.user_id`, `on_call_assignments.user_id`, `delegation_user.user_id`, `archived_delegation_user.user_id`, `procedure_access_logs.user_id`. `down()` restores the cascades |
| Unchanged | Per-user personal data still cascades with a (hard-deleted) user: `alarms`, `procedure_user_reads`, `user_availabilities`, `user_group_user`, `shift_change_requests`, `newbie_periods.user_id`. The set-null references (`checklist_submissions.incoming_user_id`, `procedure_owner_changes.old_owner_id`, `procedure_versions.published_by`) are as before |

### Frontend
| What | Details |
|------|---------|
| Changed | Checklist builder sends group/item ids when saving an edit; **Delete** became **Archive** with a confirm explaining that handovers are kept |
| Changed | Users screen: **Delete** became **Deactivate** (dialog with an "Also remove them from these N shifts and M on-call duties from tomorrow on" checkbox, on by default). Deactivated users are hidden unless **Show deactivated** is on, show a "Deactivated" badge and only a **Reactivate** action. The group member picker lists active users only |
| Added | Global session handling (`src/sessionExpiry.js`, installed in `app.js`): a 401/419 on any API call while logged in clears the user and opens the login page, showing *"This account is deactivated."* when that is the reason. The `/api/user` probe, login and logout keep their own handling; 429s are untouched |
| Changed | Filling a checklist: if the chosen incoming user was deactivated meanwhile, the submit error says so and the choice is cleared so the answers can be sent again |
| Changed | Checklist history filters list archived templates and deactivated users; the delegation archive filter lists deactivated users |

### Tests
| What | Details |
|------|---------|
| Added | `tests/Feature/ChecklistTemplateHistoryTest.php` covers: an edit keeps every response; removed items/groups are retired, missing from filling and present in the submission detail; foreign ids are rejected; L16; archiving keeps submissions, hides the template and drops future shift requirements; no alerts or new handovers for archived templates; restrict FKs on item/group/template; the cleanup uses Warsaw "tomorrow" at 00:30 local / 23:30 UTC |
| Added | `tests/Feature/UserDeactivationTest.php` covers: deactivation keeps authored history; `remove_future_assignments` true/false (shifts and on-call from tomorrow on, today kept, Warsaw midnight case); DELETE gives 405; an admin can't deactivate themselves; login refused; an open session gets 401; hidden from the pool, pickers and assign; left out of auto-fill (random, rotating, polish-law); reactivation; restrict FK on a procedure owner; a handover to a deactivated incoming user is refused with a clear message |
| Added | `src/sessionExpiry.test.js` (unit): 401/419 while logged in goes to login once; the deactivation message is shown; `/api/user`, login, logout, guests, the login page and 429/403/500 are left alone |
| Added | `e2e/checklists/template-history.spec.js` (removing an item keeps it in the past handover; archiving hides the template and keeps its handover) and `e2e/users/deactivate.spec.js` (deactivate, show deactivated, reactivate on a throwaway user; a user deactivated while logged in is sent to login with the reason on their next action); `createUser` fixture and `itemLabels` option for `createChecklistTemplate` |

---

## 2026-10-09 — 🧪 E2E suite can run on another port

The e2e suite used to require port 5173, so it couldn't run while another project's dev server held it (it would talk to that app instead). `E2E_PORT` now picks the suite's dev-server port.

### Tooling
| What | Details |
|------|---------|
| Added | `E2E_PORT` (default 5173) in `playwright.config.js`; passed to Vite as `VITE_PORT` (`vite.config.hybrid.js`) and to the e2e login fixture as the `Origin` header |
| Changed | `docker-compose.dev.yml` accepts session cookies from any `localhost` port (`localhost:*`); dev only — production keeps its explicit list. Recreate the backend container to apply |
| Docs | README e2e notes: `E2E_PORT=5183 npm run test:e2e` |

---

## 2026-10-08 — 🧹 Tree editor: the always-empty "Unassigned Procedures" panel is gone

Every procedure belongs to the procedure tree (one without a parent is a top-level node), so the "Unassigned Procedures" panel in the tree editor could never list anything. It is removed; "All Assigned" now takes the right-hand column, and the page no longer loads the full procedures list just to compute an empty panel.

### Frontend
| What | Details |
|------|---------|
| Removed | The "Unassigned Procedures" panel and its search box in `ProcedureTreeEditorView`; the page loads only `/api/procedures-tree` |
| Changed | "All Assigned" fills the right-hand column (taller scroll area) |

### Tests
| What | Details |
|------|---------|
| Changed | `e2e/procedures/tree-editor.spec.js` — the page renders without errors, a new procedure shows in "All Assigned", and the old panel is absent |

---

## 2026-10-08 — ⏳ Rest-day answers that no longer apply are cleared; one "resting until" everywhere

A shift's rest-day answer (**Doesn't count**, **Counts toward the next streak** or **Extends the rest**) only means something while the shift is on a rest day. When a change takes a shift out of rest — removing an assignment, assigning another shift that moves the streak (by hand or by auto-fill), changing a shift's or a slot's type, clearing a month, removing a day's slot, or editing the streak rules — the answer is now cleared. If a later change puts the shift on a rest day again, it shows ⚠ and the manager is asked again instead of the old answer applying silently.

The "This employee is resting until …" prompt (dropping a shift on a rest day) now reports the same date as the ⚠ tooltip and the later-shift prompt: the end of the rest including later shifts that extend it (it used to report the end as known before that day).

Auto-fill's rest checks are much faster on long, dense histories (a month of auto-fill over five years of daily shifts for 30 people: ~0.45 s, was ~6 s); results are unchanged.

### Backend
| What | Details |
|------|---------|
| Added | `RestPolicyJanitor::clearUncovered()` nulls `rest_policy` on the affected employees' assignments that no rest window covers (one pass is enough: the replay ignores an uncovered assignment's policy); called by unassign, assign, both shift-type endpoints (`ShiftTypeChanger`), clear-month, `PUT /api/schedule/day-shifts` when it removes slots, `PUT /api/shift-groups/{id}/streak-rules`, and the three auto-fill modes (for the employees each run assigned); unassign, clear-month and day-slot removal now delete and clear in one transaction |
| Added | `StreakTimeline::uncoveredWithPolicy()` and `StreakTimeline::finalWindowAt()` |
| Changed | `409 rest_window_conflict` reports `resting_until` / `rule` from the window's final end (later `extends_rest` included); whether the day is a rest day is decided as before |
| Changed | `StreakTimeline` caches each employee's replay with a checkpoint every 16 assignments: `isResting` resumes from the checkpoint before the day, `newlyResting` from the one before the candidate and stops once its state matches the cached replay again, and `wouldConflict` stops at the first shift put into rest |

### Tests
| What | Details |
|------|---------|
| Added | `StreakFollowupsTest` (Pest): every clearing entry point (auto-fill for all three modes), the no-rules clear leaving other groups alone, a policy on a still-covered shift is kept, the one-pass property, and the 409's final end |
| Added | Dense perf guard in `StreakReplayTest`: 30 users × 5 years of daily shifts, 2,700 `wouldConflict` calls in < 3 s |

---

## 2026-10-08 — 🐛 Checklist templates can link any procedure, not just the first 100

The checklist builder only loaded the first 100 procedures for the "Link a procedure" dropdown (the API caps a page at 100), so with more procedures the rest couldn't be linked, and an item already linked to one of them showed an empty selection when the template was edited. It now loads every procedure, sorted by title. Separately, the procedures list had no tie-break in its sort, so procedures sharing a timestamp could come back in a different order on each request and paging through them could repeat one and skip another.

### Frontend
| What | Details |
|------|---------|
| Added | `src/fetchAllPages.js` — loads every page of a paginated list endpoint (100 per request); used by the checklist builder and the procedure tree editor |
| Fixed | Checklist builder offers every procedure for linking, sorted by title (was: first 100, newest first) |

### Backend
| What | Details |
|------|---------|
| Fixed | `GET /api/procedures` breaks sort ties by `id` in the sort's direction, so the order is the same on every request and pages never overlap or leave gaps |

### Tests
| What | Details |
|------|---------|
| Added | `fetchAllPages.test.js` (unit), `ProcedureIndexOrderTest` (Pest), and a builder e2e test linking a procedure that sits beyond the first 100 |

---

## 2026-10-08 — 🐛 Opening another procedure from a procedure page shows it

Going from one procedure's page straight to another without leaving the page — e.g. through a notification in the bell menu — changed the address but kept showing the first procedure, and an old version viewed on the first one could appear for the second. The procedure page is now rebuilt whenever the procedure changes; switching between versions of the same procedure still keeps the page.

### Frontend
| What | Details |
|------|---------|
| Fixed | Routes can set `meta.remountOn` (a route param); `App.vue` keys the routed view by it. `procedure-detail` and `procedure-version` use `remountOn: 'id'`, so their state (data, version cache, tabs, dialogs) starts fresh for each procedure |

### Tests
| What | Details |
|------|---------|
| Added | `e2e/procedures/detail-navigation.spec.js` — in-app navigation to another procedure shows it; an old version viewed on one procedure isn't shown for the next |

---

## 2026-10-08 — 🐛 The procedure tree editor opens again

The procedure tree editor (`/procedures/tree-editor`) failed to render at all ("allProcedures.value.filter is not a function"). It read the paginated `/api/procedures` response as if it were a plain list, so the page crashed before showing the tree. It now loads every page of procedures (100 per request) and renders the tree, the "Unassigned Procedures" panel and the "All Assigned" list.

### Frontend
| What | Details |
|------|---------|
| Fixed | `ProcedureTreeEditorView` loads all pages of `/api/procedures` instead of assigning the paginated object to an array; the combined load is still guarded against stale responses |
| Note | Every procedure belongs to the tree (a procedure without a parent is a root node), so "Unassigned Procedures" is always empty with today's data model |

### Tests
| What | Details |
|------|---------|
| Added | `e2e/procedures/tree-editor.spec.js` — the page renders without a page error and a new procedure shows in "All Assigned" |

---

## 2026-10-08 — 🐛 Views no longer show stale data after fast clicks

Clicking faster than a screen loads — stepping months, switching groups, filters, sort or pages, opening one item and then another — could end with an older, slower response replacing what you had just asked for: another month's data, results that didn't match the filter bar, a cleared loading indicator, a blanked list or a selection snapping back. All of these screens now share one guard, so only the latest request for a piece of state can apply its result. Closing a dialog or panel also drops its pending loads.

### Frontend
| What | Details |
|------|---------|
| Added | `useLatestRequest()` composable: only the latest request for a piece of state may apply its result, clear `loading` or report an error |
| Changed | Schedule, Settlement report and My Shifts use it instead of their hand-written guards |
| Fixed | Schedule (delegation, newbie and closure overlays; employee availability, including deselecting an employee), On-Call, Office Availability, My Availability, Alarms, Checklist History, Checklist Fill, Checklist Builder, Delegation Archive, Procedures list (search, filters, sort, paging, "Updating results…"), Procedure detail (version browsing, compare), procedure access log, procedure editor, Procedures to learn (preview), Users (newbie dialog; deleting a period also drops an in-flight reload) |
| Fixed | Checklist Fill: changing the date no longer keeps the previous date's shift selected |
| Fixed | Checklist Fill: a slow "unread procedures" check for a previous template can no longer block (or unblock) Submit for the current one; Submit stays disabled while the check runs, and the previous shift's info no longer flashes |
| Changed | The procedure tree editor has the same guard on its tree refresh (the page's separate rendering bug is fixed in the entry above) |
| Fixed | Procedures to learn no longer leaves a `hashchange` listener behind |
| Tests | New e2e fixture `holdRequests` (`e2e/fixtures/hold.js`) and race tests for each screen |

---

## 2026-10-08 — ⏳ Assigning a shift can no longer silently put a later shift on a rest day

Manual assign now also asks when a new shift would turn the employee's existing **later** shift into a mandatory rest day (for example, assigning the 2nd night of a streak when they already work a night inside the coming rest). The prompt offers the same three choices — **Doesn't count**, **Counts toward the next streak** or **Extends the rest** — applied to that later shift — and to any further shifts the choice itself pushes into rest (counting a shift can complete a new streak, extending the rest can reach the next shift); a further shift that already had its own rest-day answer keeps it, and only shifts that end up on a rest day are updated. Cancel changes nothing. On success the toast reads "Assigned; later shift updated." (or "Assigned; later shifts updated." when several were updated). If the employee's schedule changed while the prompt was open, the server asks again with the fresh list instead of applying the old answer.

Auto-fill (Random Coverage, Rotating Subteam and Polish Law) never does this: it skips such candidates, looking at the employee's whole schedule including later months. Shifts that end up on a rest day anyway (through a shift-type change or a streak-rule edit) now show ⚠ "Now on a rest day (resting until …): nobody chose how it counts". The ⚠ and ⏸ markers are part of the shared schedule view, so everyone who can see the schedule sees them. ⏸ ("On a rest day: counts toward next streak" / "On a rest day: extends rest") now shows only while the shift is actually on a rest day, and the schedule refreshes the markers after every assign and after removing an assignment.

### Backend
| What | Details |
|------|---------|
| Added | `POST /api/schedule/assign` answers `409 creates_rest_conflict` (`affected[]`, `rule`) and accepts `later_rest_policy` plus `expected_affected_ids` (the ids the manager was shown; a mismatch answers the 409 again with the fresh list); the 201 response includes `updated_assignment_ids` / `updated_rest_policy` |
| Added | The chosen `later_rest_policy` is also stamped on shifts that it pushes into rest in turn (`StreakTimeline::cascadePolicy`); all of them are listed in `updated_assignment_ids` |
| Changed | The streak replay loads plain rows instead of models |
| Added | Schedule payload carries `in_rest` / `resting_until` per assignment |
| Changed | Auto-fill skips candidates whose new shift would put a later shift on a rest day |

### Frontend
| What | Details |
|------|---------|
| Added | Retroactive rest-day prompt on manual assign (Doesn't count / Counts toward the next streak / Extends the rest), noting that the choice also applies to further shifts it pushes into rest; "Assigned; later shift updated." / "Assigned; later shifts updated." toast, based on what the server updated |
| Added | ⚠ marker "Now on a rest day (resting until …): nobody chose how it counts" on affected chips, visible to everyone in the shared schedule view |
| Changed | ⏸ shows only while the assignment is on a rest day; tooltips read "On a rest day: counts toward next streak" / "On a rest day: extends rest" |
| Changed | The schedule refreshes after every successful assign and after removing an assignment, so other chips' markers stay current |
| Fixed | The prompt lists several dates as "A and B rest days" (deduplicated) |

---

## 2026-10-07 — 🔁 Streaks are replayed from real assignments

Streak status is no longer kept in stored counters; it is replayed from the real assignments every time it is needed. This fixes: unassigning or retyping the shift that started a rest window now clears it; no more drift on night→day→night retypes; edits to past shifts and to streak rules apply immediately and retroactively; and concurrent assigns no longer lose updates.

Manually assigning a shift on an employee's rest day now asks how it counts: **Doesn't count**, **Counts toward the next streak** or **Extends the rest**. The choice is stored on the assignment, and chips whose rest-day assignment counts or extends the rest show a ⏸ marker with a tooltip. Random Coverage and Rotating Subteam auto-fill now skip employees on rest days (previously only Polish Law did), and every auto-fill counts its own assignments as it goes. The settlement report shows streak status as of the end of the displayed period. The back-to-back "Assigned" toast no longer appears when the rest-day prompt follows it.

> **Deploy note:** statuses are recomputed from real history on deploy. Drifted counters are corrected and Random/Rotating assignments (never counted before) now count, so some employees' streak status may change. The `shift_streak_states` table is dropped (its `down()` recreates it empty). Existing rest-day assignments have no policy and don't count. Because Random/Rotating assignments now count, some already-planned future assignments may now fall on a rest day; they are kept and aren't counted, and the schedule flags them (see the 2026-10-08 entry).

### Backend
| What | Details |
|------|---------|
| Changed | Streak status is replayed from assignments; `ShiftStreakTracker` and the `shift_streak_states` table are removed |
| Added | `shift_assignments.rest_policy`; `POST /api/schedule/assign` answers `409 rest_window_conflict` until a `rest_policy` is sent |
| Changed | Random Coverage and Rotating Subteam auto-fill skip employees on rest days; settlement report streak status is as of the period end |

### Frontend
| What | Details |
|------|---------|
| Added | Rest-day prompt on manual assign (Doesn't count / Counts toward the next streak / Extends the rest); ⏸ marker with tooltip on chips whose rest-day assignment counts or extends the rest |
| Fixed | The "Assigned" toast is suppressed when the rest-day prompt follows it |

---

## 2026-10-07 — 🚦 A throttled API no longer logs you out; the API rate limit is configurable

The SPA treated any failure of `GET /api/user` as "not logged in", so a 429 from the rate limiter sent a still-logged-in user to `/login` (the router guard and the dashboard both did this). Only 401/419 now mean logged out; a 429 is retried once after `Retry-After` (capped at 3 s) and other failures (5xx, network) keep the current session. The per-user API limit, previously hardcoded at 60 requests/minute, is now configurable.

> **Operator note:** set `API_RATE_LIMIT` (in the root `.env`) to change the per-user API limit. The default is 60 requests/minute, so nothing changes unless you set it. `docker-compose.dev.yml` (local dev and the e2e CI job) defaults it to 1000 — a value in the root `.env` overrides that, so leave it unset for local development. Known limitation: if `/api/user` is still throttled after the one retry on first load, navigation is cancelled (the page stays on its current route until reload) rather than logging the user out.

### Backend
| What | Details |
|------|---------|
| Added | `config('app.api_rate_limit')` (`API_RATE_LIMIT` env, default 60), used by the `api` limiter in `RouteServiceProvider`; passed through `docker-compose.yml`, documented in `.env.example` and `backend/.env.example` |
| Tests | New `ApiRateLimitTest`: with the limit set to 3, the 4th request returns 429 with `Retry-After`; default stays 60 |

### Frontend
| What | Details |
|------|---------|
| Fixed | New `fetchCurrentUser.js` (`fetchCurrentUser()`, `isLoggedOut()`); the router guard and `DashboardView` only log out on 401/419 and otherwise keep the session |
| Tests | New `e2e/auth/session.spec.js`: a 429 on the guard's `/api/user` call and on the dashboard's call no longer logs out (both failed before the fix). The per-test `resetRateLimit` e2e fixture is removed now that the dev stack has a high limit; the once-per-run `cache:clear` and the fixtures' 429 retry stay as safety nets |

---

## 2026-10-07 — 🐛 My Shifts no longer shows the previous month's shifts after switching

On My Shifts, moving to another month while the current one was still loading could end with the earlier month's shifts (often none) displayed under the new month's heading — `fetchMyShifts()` waits for both `/my-shifts` and `/my-delegations`, so a slow earlier load could finish last. Found as an intermittent CI failure.

### Frontend
| What | Details |
|------|---------|
| Fixed | `MyShiftsView.vue` `fetchMyShifts()` only applies the latest load's result (request-sequence guard, as on the schedule and settlement pages) |
| Tests | New e2e test in `my-shifts/my-shifts.spec.js` holds the initial month's load until after switching (fails without the fix); `manual-assignment.spec.js` uses a 1280×1000 window so the drag target stays on screen on CI fonts, and its drag helper now errors clearly when a target is off screen |

---

## 2026-10-07 — 🧩 Assigned-employee chips fit their day on narrower screens

On the schedule calendar, an assigned employee's chip couldn't shrink below its full name. At a 1280 px window a day column is ~133 px but "Regular Employee" plus initials and × needs ~142 px, so the chip spilled into the next day — whose drop-zone then sat on top of the × button, making "remove" click the wrong day.

### Frontend
| What | Details |
|------|---------|
| Fixed | `ScheduleView.vue`: chips can shrink to their column (`min-w-0 max-w-full`); the name truncates with an ellipsis and shows the full name as a tooltip; initials, day-off, Pending and × controls keep their size |
| Tests | New `e2e/schedule/chip-layout.spec.js` at the default 1280×720 viewport: chip stays inside its cell, × is the element under the pointer, full name available via tooltip (failed before the fix: chip edge 734.7 px vs cell 713.3 px); `manual-assignment.spec.js` drops its 1920×1080 workaround |

---

## 2026-10-07 — 🐛 Settlement report no longer shows another group's data

On the Settlement Periods page, picking a group (or stepping periods) while an earlier report request was still in flight could end with the earlier response replacing the report — e.g. the default group's report loaded on page open arriving after the user had already switched to another group, while the selector still showed the chosen one.

### Frontend
| What | Details |
|------|---------|
| Fixed | `SettlementPeriodsView.vue` `fetchReport()` only applies the latest request's response (same request-sequence guard as the schedule page); older responses can no longer replace the report, null it on error, or clear the loading state |
| Tests | New e2e test in `settlement-periods/report.spec.js` holds the on-load request until after the group switch (fails without the fix); the spec's `openReportFor()` helper no longer needs its workaround |

---

## 2026-10-07 — 📅 Month views no longer jump to the next month on the 29th–31st

Every endpoint that takes a `month` (`YYYY-MM`) parsed it with `Carbon::createFromFormat('Y-m', …)`, which fills in *today's* day of the month. On the 31st, any 30-day month or February rolled over into the next month (`2027-02` became March); on the 29th–30th, February did. On those days the schedule, auto-assign, generate, on-call, delegations, newbie periods, office closures, change requests, availability and My Shifts silently showed — or wrote to — the wrong month.

### Backend
| What | Details |
|------|---------|
| New | `App\Support\Month::start('YYYY-MM')` — first day of the month at 00:00, independent of today's date |
| Fixed | All 16 call sites switched to it: `ScheduleController` (index, generate, slot shift types), `ShiftAutoAssignController` (5), `OfficeClosureController` (2), `DelegationController`, `NewbiePeriodController`, `OnCallController`, `ShiftChangeRequestController`, `UserAvailabilityController`, and the `/my-shifts` route |
| Tests | `MonthParsingTest` pins the clock to the 31st (helper, `/api/schedule`, `/api/my-shifts`), plus a guard test that fails if the `createFromFormat('Y-m'` pattern reappears under `app/` or `routes/` |

---

## 2026-10-07 — 🐛 Schedule no longer snaps back to the default group

Opening a deep link such as `/schedule/2027-03` and switching group tabs right away could jump the view back to the default group. The page's first schedule request goes out before the groups have loaded, without a group, and the server answers with the default group. When that slower response arrived after the switch, it overwrote the user's choice. This was also the cause of the intermittently failing `group-settings` e2e test, whose gear click then opened the default group's settings.

### Frontend
| What | Details |
|------|---------|
| Fixed | `ScheduleView.vue` `fetchSchedule()` only applies the latest request's response (request sequence guard); an older response can no longer change the active group, the schedule, or clear the loading state |
| Tests | New e2e regression test in `schedule/navigation.spec.js` that holds the initial request until after the tab switch (fails without the fix); `group-settings.spec.js` now clicks the gear inside the edited group's own tab |
| Tests | `manual-assignment.spec.js` waits for the assign/unassign requests to finish before reloading — the drop handler fetches availability before POSTing, so an immediate reload could abort the save (second intermittent CI failure, reproduced deterministically with a delayed availability response) |

---

## 2026-10-07 — 🏷️ Shift Type Labelling

Shift Streaks can now actually trigger: shifts get a type (e.g. `night`), set per slot when generating a month, per shift, or per slot in bulk. Previously nothing wrote `shifts.shift_type`, so streak rules could be configured but never matched.

### Backend
| What | Details |
|------|---------|
| New | `App\Support\ShiftType::normalize()` (trim + lower-case, blank → null), applied via `Shift`/`ShiftStreakRule` mutators and in streak-rule sync (case-only duplicates now rejected); migration normalises existing values and aborts (never deletes) on rule collisions |
| New | `PUT /schedule/shifts/{shift}/type` and `PUT /schedule/shift-types` (per slot, per month), via `ShiftTypeChanger`: changing an assigned shift's type re-books each assignment's streak counters (old type out, new type in) in one transaction |
| Updated | `POST /schedule/generate` accepts `shift_times[i].shift_type`; schedule payloads include `shift_type` |

### Frontend
| View | Change |
|------|--------|
| **Schedule** | Type field per slot in the Generate Month template and in the shift edit modal (suggestions come from the group's streak-rule types and the types already used in the month); new **Set shift types** bulk editor; chips show `S1 · night`; **Set shift types** is disabled while the month loads, and the editor fills in if the schedule arrives after it opened |
| **Tests** | New `e2e/schedule/shift-types.spec.js`; the settlement report streak test now asserts real `night: 2/3` and `night: resting until …` states |

> **Deploy note:** the normalisation migration aborts the backend start if two streak rules in one group differ only by case or whitespace. Before deploying, run `SELECT shift_group_id, lower(trim(shift_type)) AS t, count(*) FROM shift_streak_rules GROUP BY 1, 2 HAVING count(*) > 1;`. If it returns rows, merge or delete the duplicates in psql, then restart the backend.

---

## 2026-10-06 — 🎭 Playwright Frontend Testing

Replaced Vitest/`@testing-library/vue`/MSW with Playwright as the frontend's only test tool, because the mocked-API tests could not catch real backend/frontend drift. Two configs: `playwright.unit.config.js` (`npm run test`, Node-mode composable tests in `src/composables`) and `playwright.config.js` (`npm run test:e2e`, real Chromium against the Dockerized backend, with `npm run dev:hybrid` as its `webServer`). 11 e2e spec files (23 tests) cover auth, settlement periods (including over-cap highlighting), schedule (navigation, group settings, group rename/delete, month generation, manual assignment, auto-fill in all three modes with the users-per-shift caps), on-call duty, shift colors and My Shifts. The README now has a "Frontend tests (Playwright)" section (prerequisites, commands, and a warning that `test:e2e` resets the local dev database); `npm run test:all` runs both suites.

### Backend
| What | Details |
|------|---------|
| Added | Test-only `E2eBaselineSeeder` (run by the e2e `globalSetup` after `migrate:fresh`). No production code changes |

### Frontend / tooling
| What | Details |
|------|---------|
| Added | Accessibility attributes so controls are reliably addressable: `aria-label` on the month navigation in `ScheduleView.vue` and `MyShiftsView.vue`; `id`/`for` label pairs in `ScheduleView.vue`'s group-settings modal and `OnCallView.vue`'s modals |
| Added | E2E fixtures send an `Origin` header so Sanctum treats their API calls as stateful |
| Added | The API rate limiter is reset before every e2e test via an auto fixture in `fixtures/auth.js`, plus once in `globalSetup` (`cache:clear` in the backend container), because the backend hard-codes 60 req/min per user and the admin user is shared across specs. Fixture API calls retry once on HTTP 429, and all `docker exec ... artisan` calls run as `www-data` so they never leave root-owned log files |
| Added | The on-call and my-shifts specs pin the browser clock so date-relative data is deterministic; my-shifts uses a far-future month (2099-08) because the server also rejects past shifts. CI now also triggers on docker-compose changes and uploads `docker.log` on failure |
| Removed | `vitest`, `@testing-library/vue`, `@testing-library/jest-dom`, `@vue/test-utils`, `happy-dom`, `msw`, the `test` block in `vite.config.js`, and `src/mocks/` |

---

## 2026-10-06 — 🛠️ Hybrid Dev Mode & Log Permission Fixes

Two local-dev fixes: `./dev.ps1` now actually serves the app on `:5173`, and the backend no longer loses write access to `laravel.log`.

### Backend
| What | Details |
|------|---------|
| Fixed | `laravel.log` "Permission denied" — `storage/logs` and `storage/app` are named volumes, so the image's build-time `chown` never reached root-owned files left there by `docker compose exec backend php artisan ...`. `docker/entrypoint.sh` now re-chowns both to `www-data` on every container start |
| Fixed | `SettlementPeriodControllerOnCallTest` / `SettlementPeriodControllerStreakTest` started failing once October began — they query `offset=0` (the period containing *now*) against September data. Both now pin the clock to 2026-09-15 with `Carbon::setTestNow()` |

### Frontend / tooling
| What | Details |
|------|---------|
| Fixed | Hybrid dev mode showed the laravel-vite-plugin placeholder page instead of the SPA. New `vite.config.hybrid.js` (Docker standalone-SPA config + `/api` & `/sanctum` proxy, target overridable via `BACKEND_URL`) and `npm run dev:hybrid` script; `dev.ps1` uses it |
| Removed | The unused `server.proxy` block from `vite.config.js` |
| Docs | README hybrid section updated; artisan example now uses `exec -u www-data`. Playwright migration plan/spec now start the e2e `webServer` with `npm run dev:hybrid` (dropping the laravel-plugin-only `LARAVEL_BYPASS_ENV_CHECK`) |

---

## 2026-09-16 — 📟 On-Call Duty

Shift groups can now have on-call periods — a manually-assigned window (any number of employees at once, can overlap regular shifts) during which an admin can log a "callback": a real worked interval that counts exactly like a regular shift for the existing rest/hour-cap rules. Passive on-call time itself has no effect on scheduling or hours.

Sub-project 4 of 4 in the broader scheduling feature set (manager permissions — shipped, then reverted; configurable settlement periods — shipped; shift streaks — shipped).

### Backend
| What | Details |
|------|---------|
| New tables | `on_call_assignments` (shift_group_id/user_id/starts_at/ends_at, no overlap constraints), `on_call_callbacks` (on_call_assignment_id/starts_at/ends_at — starts_at must fall within the parent window, ends_at may extend past it) |
| New endpoints | `GET/POST/DELETE /on-call-assignments`, `POST /on-call-assignments/{id}/callbacks`, `DELETE /on-call-callbacks/{id}` |
| Updated | `ShiftAutoAssignController::polishLaw()` — a logged callback resets the 11h/35h rest clock and counts toward the settlement-anchored weekly_hour_cap, computed fresh per eligibility check rather than folded into a mutable running counter (which would get overwritten by later shift assignments in the same run) |
| Updated | `GET /settlement-periods` — callback hours are included in each employee's weekly totals |

### Frontend
| View | Change |
|------|--------|
| **New: On-Call** | Manage on-call assignments per group/month, log/remove callbacks against them |

## 2026-09-14 — 🔥 Shift Streaks

Each shift group can now define per-shift-type streak rules — "N shifts of this type in a row, then M days fully off" (e.g. 3 night shifts, then 2 days off) — enforced live inside the schedule auto-generator. Manual assignment is unaffected by design: assigning or unassigning a shift always succeeds, it just keeps the streak bookkeeping up to date in the background.

Sub-project 3 of 4 in the broader scheduling feature set (manager permissions — shipped, then reverted; configurable settlement periods — shipped; on-call duty — next).

### Backend
| What | Details |
|------|---------|
| New tables | `shift_streak_rules` (per-group config: shift_type/streak_length/rest_days), `shift_streak_states` (per-employee-per-rule progress, persisted rather than derived live since a streak's history has no bounded lookback) |
| New service | `ShiftStreakTracker` — the single place streak state is read/written |
| Updated | `ShiftAutoAssignController::polishLaw()` — a new eligibility filter blocks any shift for an employee during an active mandatory-rest window, alongside the existing 11h/13h/5-day/weekly-cap rules; a watermark makes re-running generation over an already-generated month idempotent |
| New endpoint | `PUT /shift-groups/{id}/streak-rules` (admin) — full-replace config, preserving existing employee progress for unchanged/edited rules |
| Updated | `GET /settlement-periods` — each employee gains a `streak_status` field |

### Frontend
| View | Change |
|------|--------|
| **Schedule** | Group settings modal gained a repeatable streak-rule editor |
| **Settlement Periods** | New "Streak" column showing each employee's current progress or active rest window |

### Known follow-ups (not in this release)
`random()`/`rotating()` auto-assign modes don't record streak progress (only `polishLaw()` does); the re-run watermark can skip assignments if months are generated out of order; retyping or bulk-deleting shifts doesn't reconcile streak state; an employee resting for an entire settlement period isn't listed in that period's report. All four share the same shape — a controller changing a `ShiftAssignment` without going through the tracker — and the recommended fix is a model observer on `ShiftAssignment` create/delete.

---

## 2026-09-11 — ↩️ Revert: Manager Permissions for Employee Availability

Reverted the manager-editable-availability feature shipped 2026-09-07 (PR #4). Managers can no longer create/edit/delete availability entries for employees on teams they manage; each user is back to managing only their own entries. Removed the shift-type preference field, the "set by [manager]" badge, the Team Availability page, and the manager-assignment panel on the Users page.

Kept as-is: the `2026_09_07_*` migrations that added this schema (already applied on `origin/main`, so deleting them would orphan the schema elsewhere) — a new migration (`2026_09_11_120000_revert_manager_permissions_schema`) drops the added table/columns instead.

### Backend
| What | Details |
|------|---------|
| Removed | `UserAvailabilityPolicy`, `User::managedGroups()`/`managesUser()`, `UserGroup::managers()`, `UserGroupController::syncManagers()`/`managedByMe()`, `ScheduleController::updateShiftType()` |
| Schema | New migration drops `user_group_managers`, `user_availabilities.shift_type`/`created_by_user_id`, `shifts.shift_type` |

### Frontend
| View | Change |
|------|--------|
| **My Availability** | `AvailabilityEditor.vue` folded back into `MyAvailabilityView.vue` directly; manager "set by" badge and shift-type field removed |
| **Removed** | `TeamAvailabilityView.vue`, its nav link, and the Managers sub-panel on Users |

---

## 2026-09-07 23:15 — 📅 Configurable Settlement Periods

Each shift group can now define its own settlement-period accounting window (weekly, monthly, quarterly, or a custom length) with its own anchor date and weekly hour cap — replacing the schedule generator's previously hardcoded 48h/calendar-week limit. Weeks within a period are 7-day blocks anchored to the period's own start date, not calendar Monday–Sunday, with the trailing partial week's cap prorated by day count. A new reporting page shows each employee's hours per anchored week against the cap.

Sub-project 2 of 4 in the broader scheduling feature set (manager permissions shipped previously but was later reverted — see above; shift streaks and on-call duty are next).

### Backend
| What | Details |
|------|---------|
| New columns | `shift_groups.settlement_mode` (weekly/monthly/quarterly/custom), `settlement_anchor_date`, `settlement_custom_length_days`, `weekly_hour_cap` (nullable, backfilled to match today's behavior for existing groups) |
| New service | `SettlementPeriodCalculator` — pure date-arithmetic service computing period/week boundaries from a group's config, with no persisted periods table |
| Updated | `ShiftAutoAssignController::polishLaw()` — the live weekly hour-cap check now reads each group's configured cap, measured against settlement-period-anchored weeks instead of ISO calendar weeks; the old separate 40h/month "average" note is removed |
| New endpoint | `GET /settlement-periods` (admin/schedule_maintainer) — per-employee hours report for a group's settlement period, with prev/next period navigation |
| Updated | `ShiftGroupController` — `store()`/`update()` accept the four settlement-config fields |

### Frontend
| View | Change |
|------|--------|
| **New: Settlement Periods** | Reporting page — pick a group, browse settlement periods, see each employee's hours per week against the cap |
| **Schedule** | Group tabs gained a settings icon to configure a group's settlement period and weekly hour cap |

---

## 2026-03-20 12:34 — 👁️ All Groups Combined View

New **"All Groups"** tab on the Schedule page — view shifts from **all shift groups at once** on the same calendar.

- **Gradient-styled tab** appears first in the tab bar (indigo → purple → pink)
- Day cells show shifts **grouped by group** with **colored left borders** and group name labels
- **Read-only mode** — admin actions (generate, delete, auto-fill, drag-assign, shift count ±) are disabled with a helpful banner
- Switching to a specific group restores full editing capability

---

## 2026-03-20 09:27 — 📋 Shift Groups (Multi-Schedule Support)

New system for managing **multiple independent shift groups** — perfect for events, special coverage, or any scenario requiring separate schedules alongside the main one.

### How It Works
- **Tab bar** on the Schedule page — each tab is a shift group with its own shifts and assignments
- **Default "Main" group** preserves all existing data — zero disruption
- Click **"+ New Group"** to create additional groups (name + color)
- **Double-click** a tab name to rename inline
- **Delete** non-default groups (cascades to shifts & assignments)
- All operations are **scoped per group**: generate, auto-fill, clear month, day shift count

### Backend
| What | Details |
|------|---------|
| New table | `shift_groups` (name, description, color, is_default, sort_order) |
| Migration | `shift_group_id` FK on `shifts`, unique constraint → `(date, shift_number, shift_group_id)` |
| New model | `ShiftGroup` with `shifts()` relationship |
| New controller | `ShiftGroupController` — full CRUD + reorder |
| Updated | `ScheduleController`, `ShiftAutoAssignController`, `CurrentShiftController` — all scoped by group |
| New routes | 5 admin routes for shift group CRUD |

### Frontend
| View | Change |
|------|--------|
| **Schedule** | Tab bar with group switching, create, rename, delete |
| **AutoFill** | Passes `shift_group_id` to all 3 auto-assign algorithms |
| **My Shifts** | Shows group name badge next to each shift |

---

## 2026-03-04 00:09 — Version History, Change Reasons & Version Browsing

- **Publish requires reason**: When publishing a new version, a modal prompts for a change reason description (stored in `procedure_versions` table).
- **Version history**: Each published version is stored as a snapshot (title, purpose, requirements, content, change_reason, publisher, published_at).
- **Version browsing**: `‹` and `›` arrows next to version number in procedure detail page. Click to load any historical version. Amber banner shows when viewing old version with "go to latest" link.
- **Change reason banner**: Displayed below header showing what changed in the current version, with publisher and date.
- **New tables**: `procedure_versions` for full version history snapshots.
- **New API endpoints**: `GET /procedures/{id}/versions`, `GET /procedures/{id}/versions/{version}`.

---

## 2026-03-03 23:55 — Procedure Versioning, Feedback Review & Learning Tracker

- **Draft/Publish workflow**: Owner can save drafts (no version bump) or publish as new version (increments version). Resume editing saved drafts anytime.
- **Owner-only editing**: Only procedure owner (or admin) can edit. Edit button hidden from non-owners. Editor redirects if unauthorized.
- **Feedback review in editor**: Right sidebar panel shows error reports submitted since last version. Owner can mark each as accepted / rejected / deferred + add resolution comment. Open feedbacks auto-resolved on publish.
- **Procedures to learn**: New "To Learn" page showing procedures with new versions the user hasn't read. Auto mark-as-read on view. Shows 🎉 empty state when all caught up.
- **DB changes**: Draft fields on procedures, `for_version`/`resolution`/`owner_comment` on feedbacks, new `procedure_user_reads` table.
- **Route restructuring**: Procedure reading routes moved to auth group (accessible to all users), write operations remain admin-guarded with owner checks in controller.

---

## 2026-03-03 16:17 — Refactored Procedure Display into Shared Components

- Extracted **5 shared components** in `components/procedure/`:
  - `ProcedureContent.vue` — main orchestrator (purpose at top, tags, metadata grid, tabbed content)
  - `ProcedureFaqTab.vue` — FAQ questions & replies with full CRUD
  - `ProcedureTemplatesTab.vue` — response templates with copy/edit/delete (editable mode for admins)
  - `ProcedureFeedbackTab.vue` — feedback list with admin status editing
  - `ProcedureAccessLogTab.vue` — lazy-loaded access history
- **Both views now use the same `ProcedureContent`** — purpose displays at the top in both routable page and modal
- `ProcedureDetailView.vue` simplified to ~130 lines (from ~430) — only page-specific header/modals remain
- `ChecklistFillView.vue` modal now uses `<ProcedureContent>` — removed ~200 lines of duplicated code + inline styles

---

## 2026-03-03 16:00 — Full Procedure Modal in Checklist Fill View

- Procedure modal now shows **all details** from the procedure detail page:
  - Tags, metadata (Owner, Created, Modified, Last Access)
  - Tabs: **Content**, **FAQ** (ask/reply/delete), **Templates** (copy to clipboard), **Feedback** (view with status), **Access Log** (lazy-loaded)
- Interactive: ask FAQ questions, reply to threads, copy response templates — all without leaving the checklist
- Modal widened to max-w-4xl, max-h-90vh for more comfortable browsing

---

## 2026-03-03 15:36 — Checklist Fill View Enhancements

- **Shift Info Card**: when filling a checklist linked to a shift, shows shift number, time range, and colleague names (badges for other users on the same shift)
- **Progress Bar**: animated gradient bar tracking filled items vs total — turns green on 100% completion
  - Checkboxes: counted when checked; Text/Number: counted when non-empty
  - Comments not counted (not mandatory)
- Added `/api/shifts/{shift}` endpoint returning shift details with assignments

---

## 2026-03-03 15:13 — Procedure Modal Preview & Copy Link

- **Checklist Fill View**: clicking the 📋 badge now opens a **procedure preview modal** with full content instead of navigating away — users stay on the checklist
  - Modal shows: identifier, title, version, purpose, requirements, and rich HTML content
  - Header actions: **Copy Link** (🔗), **Open Full Page** (↗), Close (✕)
  - Loading spinner while procedure data fetches via API
- **Procedure Detail Page**: added **Copy Link** button in the header (next to Report Error)
- Copy link always generates the public URL to the procedure detail page (`/procedures/:id`)

---

## 2026-03-02 22:37 — Link Procedures to Checklist Items

- Added `procedure_id` nullable FK to `checklist_items` table
- **Checklist Builder**: new "procedure" dropdown per item to link a procedure
- **Checklist Fill View**: items with an assigned procedure show a clickable 📋 badge with the procedure identifier — links directly to the procedure detail page
- Badge appears in all item types (checkbox, text, number) and in the confirmation step
- Procedure data is eager-loaded via `groups.items.procedure:id,identifier,title`

---

## 2026-03-02 22:15 — Procedures Module

Full knowledge base system for managing operational procedures.

### Database (6 migrations, 7 models)
- `procedure_tags` — colored keyword tags
- `procedures` — main entity with identifier (PROC-XX), versioned content, owner
- `procedure_tag` — many-to-many pivot
- `procedure_faq_items` + `procedure_faq_replies` — threaded Q&A
- `procedure_response_templates` — copy-ready client response fragments with language
- `procedure_access_logs` — tracks who viewed when
- `procedure_feedbacks` — error reports & suggestions with status tracking

### Backend (5 controllers, ~25 API routes)
- `ProcedureTagController` — CRUD for tags
- `ProcedureController` — CRUD with search/filter, auto-identifier, version bump on content change, access logging, tag sync
- `ProcedureFaqController` — threaded Q&A (any user can ask/reply, admins can delete any)
- `ProcedureResponseTemplateController` — CRUD with language support (en, pl, de, fr, es)
- `ProcedureFeedbackController` — create (any user), list + status update (admin)

### Frontend (4 new views)
- **ProcedureTagsView** — admin CRUD table with color picker
- **ProceduresListView** — card grid with search bar and tag filter chips
- **ProcedureDetailView** — 5 tabs:
  - Content (styled rich HTML), FAQ (threaded Q&A), Templates (copy-to-clipboard), Feedback (status management), Access Log
  - Report Error button with modal
- **ProcedureEditorView** — Tiptap rich text editor with:
  - Full toolbar (bold/italic/underline/headings/lists/blockquote/code)
  - **Image paste from clipboard** (base64 inline)
  - Owner selector, tag multi-select, delete procedure

### Navigation
- "Procedures" link (all users)
- "Proc. Tags" link (admin only)

---

## 2026-03-02 20:00 — Office Unavailability Overlay & Newbie Star Indicators

### Schedule View — New Overlay
- ☑️ **"Show Office Unavailability"** checkbox (grey dot) below Show Newbies
- **🚫 Grey badges** on calendar days when office is closed, showing closure title
- Tooltip shows description on hover

### Newbie ⭐ Star Indicators
- **Users View**: yellow ⭐ appears next to user name if they have an active newbie period
- **Schedule View**: yellow ⭐ appears next to employee name in the sidebar employee pool
- Stars appear automatically based on current date vs newbie period dates

---

## 2026-03-02 19:55 — Newbie Marking System

New feature to mark employees as **"newbie"** for a configurable time period, with visibility on the schedule.

### Users View
- ⭐ **Yellow star button** per user → opens newbie period modal
- Set **start & end date** for the newbie period
- View and delete existing periods
- Multiple periods supported per user

### Schedule View
- ☑️ **"Show Newbies"** checkbox in sidebar (yellow dot indicator)
- **Yellow ⭐ badges** on calendar days showing newbie employee names
- **Employee filtering**: selecting an employee shows only their newbie periods (same logic as delegations)

### Backend
- `newbie_periods` table (user_id, start/end dates, created_by)
- `NewbiePeriodController` with forUser, forMonth, CRUD
- 5 new admin API routes

---

## 2026-03-02 19:45 — Smart Office Closure Conflict Resolution

Enhanced the office closure conflict modal with **three choices**:

| Button | Action |
|--------|--------|
| **Cancel** | Abort generation |
| **🏠 Switch to Remote** | Generate shifts, then auto-switch closed dates to remote mode |
| **Generate as Office** | Generate as-is (office shifts on closed dates) |

The "Switch to Remote" option generates the month normally, then batch-updates all office shifts on closed dates to 🏠 remote — with a toast confirming how many were switched.

---

## 2026-03-02 19:34 — Multi-Day Office Closures

Added **date range support** to the Office Availability view:
- New **End Date** field when creating closures
- Define a whole range (e.g. Dec 24–26) with one title & description
- Backend creates one entry per day, skipping existing dates
- Toast shows how many days were added

---

## 2026-03-02 19:20 — Office Availability (Closures Management)

New admin module for managing **office closure dates** — prevents accidental scheduling of office shifts on closed days.

### New Admin View — "Office" (nav link)
- 📅 **Calendar view** with orange markers on closed dates
- Click any day to **add a closure** (title + optional description)
- Click existing closure to **edit** or **delete**
- Monthly closures list below the calendar with edit/delete actions

### Schedule Conflict Detection
- When generating shifts with **🏢 Office** work mode, the system checks for closures
- If conflicts found → **orange confirmation modal** lists all affected dates with titles & descriptions
- Admin can **Cancel** or **Generate Anyway**
- Intelligently checks weekday vs weekend closures based on selected work mode defaults

### Backend
- `office_closures` table with unique date constraint
- Full CRUD controller + conflict check endpoint
- 5 new admin API routes

---

## 2026-03-02 19:10 — Delegation Conflict Warning on Assignment

When dragging an employee onto a shift, the system now checks for **delegation conflicts**:

- If the employee is on delegation during the shift date → **purple confirmation modal** appears
- Shows delegation name, destination, and date range
- Admin can **Cancel** or **Assign Anyway**
- Seamlessly chains with existing checks: unavailability → delegation → adjacent shift
- Delegation data is always pre-loaded for fast conflict detection

---

## 2026-03-02 19:04 — Remote/Office Shift Mode

Added a new **work mode** property to every shift — either 🏢 **Office** or 🏠 **Remote**.

### Generate Month Modal
- New **"Default work mode"** section with separate dropdowns for **Weekdays** and **Weekends**
- All generated shifts get the selected mode automatically

### Schedule View
- Each shift slot now shows a clickable **🏢** or **🏠** icon
- **Click to toggle** between Office and Remote — updates instantly
- Styled with teal (remote) / amber (office) colors

### My Shifts View
- Each shift displays a **🏢 Office** or **🏠 Remote** badge next to the time

### How it works
1. When generating a new month, pick the default work mode for weekdays and weekends
2. After generation, click any shift's 🏢/🏠 icon to override individually
3. Employees see the mode on their My Shifts page

---

## 2026-03-02 18:56 — Delegation Filtering by Employee

Updated the Schedule View delegation overlay to respect employee selection:
- **Employee selected** → only that employee's delegations appear on the calendar
- **No employee selected** → all delegations shown (as before)

---

## 2026-03-02 18:44 — Delegations Module

Added a full **delegation management system**: admins define delegations, assign employees, and delegation info is visible across the app.

### Backend
- **`delegations`** table (name, destination, start/end datetime) + **`delegation_user`** pivot
- **`DelegationController`** with full CRUD, addUser/removeUser, myDelegations, forMonth
- 8 new API routes (admin CRUD + user self-service)

### Frontend Integration

| View | What's new |
|------|-----------|
| **Users** | 📍 purple pin button per user → modal with **Create New** / **Assign Existing** tabs |
| **Schedule** | ☑️ "Show Delegations" checkbox → purple badges on day cells with delegated employee names |
| **My Shifts** | Purple delegation cards with name, destination & time range alongside shift cards |
| **Availability** | Read-only purple badges on calendar days + purple delegation legend item |

---

## 2026-03-02 18:43 — Polish Law Auto-Assignment Mode

Implemented **"Polish Law"** auto-assignment mode enforcing Polish Labor Code regulations.

### Polish Labor Code rules enforced
- **11h daily rest** — minimum gap between consecutive shifts
- **35h weekly rest** — Sundays protected when possible
- **Max 48h/week** hard cap, targeting 40h average
- **Max 5 working days/week**
- **Max 13h/shift** — shifts exceeding this are flagged and skipped
- **Night shift detection** — shifts overlapping 22:00–06:00 are counted
- **Fair distribution** — employees with fewer hours get priority
- **Unavailability respected** — blocked dates are honored

### UI
- Dropdown now has 3 options: Random Coverage, Rotating Subteam, **Polish Law**
- Modal with shift counts + rotation days configuration
- Toast reports: total assigned, night shift count, unfilled shifts, and compliance notes
