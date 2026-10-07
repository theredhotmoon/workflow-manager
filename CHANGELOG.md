# Changelog

All notable changes to the Shift Changer application are documented here.

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
