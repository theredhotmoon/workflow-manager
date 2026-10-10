# Changelog

All notable changes to the Shift Changer application are documented here.

---

## 2026-10-09 — 🗄️ Deletes keep history: shift groups and procedures are archived, delegations with members can't be deleted, change requests survive

> **Deploy notes**
> - Two migrations: `2026_10_10_100000_add_archived_at_to_shift_groups_and_procedures` (nullable `archived_at` on `shift_groups` and `procedures`) and `2026_10_10_100100_keep_shift_change_requests_when_work_is_removed` (adds `shift_date`/`shift_number` to `shift_change_requests`, backfilled from the shift; `shift_assignment_id` and `shift_id` become nullable with `ON DELETE SET NULL` instead of `CASCADE`). Both run on existing data; existing rows are unaffected. Rebuild backend and frontend.
>
> **Behaviour changes**
> - **Deleting a shift group archives it.** It leaves the schedule tabs, the all-groups view, on-call and auto-fill pickers; its shifts, assignments, hours, change requests, checklists, streak rules, on-call duties and callbacks stay, and settlement reports still count them (the Settlement Periods picker lists archived groups as "(archived)"). While the group has assignments or on-call duties from tomorrow on, archiving answers 409 `future_work` listing them; confirming removes them the way user deactivation does (pending change requests acknowledged and kept, rest policies cleared). Today's and past work is never touched. Planning in an archived group (generate, day count, auto-fill, slot types, on-call, assign/unassign, shift edits) is refused until it is restored. Admins restore groups from Schedule → "Archived (n)". An archived group's name does not block a new group with the same name (names were never unique).
> - **Deleting a procedure archives it.** It leaves the tree, lists, search, to-learn, unread checks and checklist pickers; versions, FAQ, feedback, reads, access log, owner changes and tags stay. A procedure with sub-procedures that aren't archived is refused (409 `has_children`, listing them). Its URL answers 404 to non-admins; admins see it with an "Archived" banner and Restore. Restored procedures return to their old parent, or to the top level if that parent is archived (the response says so). Procedures → "Archived" lists them for admins.
> - **A delegation can be deleted only while nobody has joined it** (no member with status `approved`). Otherwise 409 "Archive it instead"; the Delete button is disabled with that hint.
> - **Change requests are kept** when their assignment or shift is removed (unassign, move, deactivation, group archive, day-count reduction, clear month): pending ones are acknowledged, the link is nulled, and the request keeps its shift's date and slot. The month list and "mine" include them, flagged `assignment_removed` / `shift_removed`.
> - **An archived procedure is frozen:** FAQ questions, replies, outdated toggles, FAQ deletes, feedback and response templates answer 409 "This procedure is archived" to admins and 404 to everyone else; reads still work for admins. A checklist item keeps a link to a procedure archived since, but no item can newly link one (the builder offers it only on the item that already has it).
> - **Archive vs concurrent writes:** every write that plans work in a group locks the group row first (FOR SHARE) and re-checks it, so an archive that lands mid-request is refused instead of leaving future work in a hidden group. Deleting a delegation locks it against a concurrent approve/add; a change request filed while its assignment is being removed gets 404 instead of a server error; a move under a procedure being archived is refused.
> - A change request's slot number follows its shift when a day-count reduction renumbers the day. Reads that accept archived groups (settlement, on-call list) keep the plain "invalid" message for unknown ids; only planning endpoints say "or is archived".

- **Deletes wiped history.** The cascades pinned in M25 (`// Current behaviour: cascades`) are gone: groups and procedures archive, delegations with members are refused, change requests survive. The old `DELETE` routes still work (they archive); new admin-only `POST /api/shift-groups/{shiftGroup}/restore` and `POST /api/procedures/{procedure}/restore`.

### Backend
| What | Details |
|------|---------|
| New | Migrations above. `App\Services\FutureWorkRemover` (shared by user deactivation and group archive). `ShiftGroup::notArchived()`, `isArchived()`, `activeExistsRule()`; `Procedure::notArchived()`, `isArchived()`, and route binding that hides archived procedures from non-admins. `ShiftChangeRequest` fills `shift_date`/`shift_number` on create and appends `assignment_removed`/`shift_removed` |
| Changed | `ShiftGroupController`: `index` hides archived unless `include_archived=1`; `destroy` archives (409 `future_work`, `remove_future_assignments`); new `restore`. Planning endpoints validate `shift_group_id` with `activeExistsRule()`; shift-level schedule writes refuse shifts of archived groups (422). The all-groups schedule leaves archived groups out |
| Changed | `ProcedureController`: `index` hides archived (`archived=1` for admins lists them), `tree`, `checkReadStatus` and `ProceduresToLearnController` skip them; `destroy` archives (409 `has_children`); new `restore`; edits/move/owner change on an archived procedure answer 409; move refuses an archived parent. Checklist templates can't newly link an archived procedure; the fill form doesn't offer it; FAQ replies on an archived procedure answer 404 to non-admins |
| Changed | `DelegationController::destroy`: 409 `has_members` when anyone is approved |
| Changed | Change request month list filters on `shift_date`; day-count reduction and clear month acknowledge pending requests of the slots they remove |
| Tests | The six `Current behaviour: cascades` pins rewritten to the new behaviour; new Pest tests for archive/restore, refusals with listed blockers, hidden from planning/listing, admin listings, settlement of an archived group's past period, delegation 409 vs allowed, change requests surviving unassign/shift deletion/day-count reduction/deactivation, and the migration's backfill. Route permission matrix includes the two restore routes |
| Changed | Lock order documented on `ShiftGroup`: the group row is the first row any planning transaction locks (`lockShared` / `lockActiveOrFail`, FOR SHARE; archive takes it FOR UPDATE). New rule `App\Rules\ActiveShiftGroup`. Delegation destroy/approve/add lock the delegation row; change request filing and every assignment removal lock the assignment row; procedure archive locks the procedure, move locks the new parent FOR SHARE. `Procedure::refuseWritesIfArchived()`. The backfill is one `UPDATE … FROM` |
| Tests | New `ArchiveRaceTest` (archive landing after validation: assign, move, generate, day slots, the three auto-fills, on-call); delegation deleted during approve/add; change request filed while its assignment goes; move under a parent archived meanwhile; archived procedure writes; per-item archived links; slot renumbering; planning refusals for checklist, colour, type, work mode, moves in/out, slot types, clear-month, rotating, polish-law; tomorrow-vs-today boundary; restore keeps sort order; the move test asserts the request is kept and acknowledged |

### Frontend
| What | Details |
|------|---------|
| Changed | Schedule: "Archive group" (confirm says what is kept; a second confirm when future work must be removed), "Archived (n)" list with Restore |
| Changed | Procedure editor: "Archive Procedure"; detail page: Archived banner + Restore; procedures list: "Archived" dialog with Restore |
| Changed | Delegations manage: Delete disabled with a hint when the delegation has members; the server's 409 is shown as a toast |
| Changed | Settlement Periods lists archived groups; checklist builder shows an archived linked procedure as "(archived)" |
| Tests | E2E: `schedule/shift-groups.spec.js` archives and restores a group; new `procedures/archive.spec.js`; `delegations/manage.spec.js` covers delete with a member |

---

## 2026-10-09 — ♿ Dialogs you can use with a keyboard, announced toasts, a header that fits a phone

> **Deploy notes**
> - No migrations, no backend change. Rebuild the frontend.
>
> **Behaviour changes**
> - Every dialog now closes on **Escape** (only the top one when dialogs are stacked), keeps **Tab** inside itself, takes focus when it opens and gives it back to the button that opened it. Escape does what the dialog's Cancel does (the conflict prompts on Schedule cancel the assignment or generation).
> - The four conflict prompts and the delete-month prompt on Schedule, and Deactivate User / Delete Group on Users, are now `alertdialog`s.
> - While a dialog is saving, Escape and backdrop clicks don't close it; its Cancel/✕ still do. A backdrop click only counts if the press started on the backdrop, so drag-selecting text out of a field no longer closes the dialog. If Users' Change Password or Deactivate is closed mid-request and the request then fails, the error arrives as a toast instead of being left on the closed form.
> - The Users dialogs (Create/Edit User, Change Password, Deactivate, Group edit, Group delete) now close on a backdrop click like every other dialog; before, a backdrop layer swallowed the click.
> - Toasts are drawn above dialogs.
> - The notifications menu closes on Escape and on a click elsewhere; the account menu also closes on Escape.
> - On a phone the header no longer runs off the screen: the page links scroll sideways in their own strip, the bell and account menu stay visible, and the "Shift Changer" text is hidden below the `sm` breakpoint.

- **Most dialogs had no dialog semantics, no focus trap and no Escape, and toasts were not announced.** A keyboard user could tab behind "Request Day Off" and could not close it; a screen-reader user never heard "Failed to assign user". New `BaseModal` shell (built on `useFocusTrap`'s shared modal stack): `role="dialog"`/`alertdialog`, `aria-modal`, `aria-labelledby` its heading (`aria-describedby` where there is a description), focus in on open, back to the opener on close, Tab/Shift+Tab wrap, Escape closes the top layer only. Each dialog keeps its look and its backdrop-click behaviour. 31 dialogs migrated: Schedule (10, plus Set shift types), Users (7), Procedure detail (2), On-Call (2), Office, My Availability, My Shifts, Procedure Tags, Delegations manage, Checklist fill, Checklist history (2), Procedures to learn. Toasts now render into two always-present live regions: errors in `role="alert"`/`aria-live="assertive"`, everything else in `role="status"`/`aria-live="polite"`; focus is not moved. (M22)

### Frontend
| What | Details |
|------|---------|
| New | `components/BaseModal.vue`: props `overlay-class`, `backdrop-class`, `panel-class`, `role`, `label`, `described`, `close-on-backdrop`, `close-on-escape`, `initial-focus`, `return-focus`, `busy`; emits `close`; slot props `titleId`, `descriptionId`. Shown by mounting it (`v-if`), so an enclosing `<Transition>` still animates it |
| Changed | `useFocusTrap.js`: the trap is now `createFocusTrap()` (activate/deactivate), driven by both `useFocusTrap` and `BaseModal`. It skips hidden controls, pulls focus back in when it sits outside the dialog, falls back to focusing the dialog itself. When the opener is gone, focus goes to `returnFocus`, else the dialog below, else `<main>` (the password dialog returns to the account button). Delete Schedule's step 2 is its own (keyed) alertdialog, so it takes focus |
| Changed | `AppToast.vue`: two live regions, always in the DOM, holding the card but not the Dismiss button (laid over it, so it isn't announced); `z-[110]`, above every dialog |
| Fixed | Users: Deactivate and Change Password read their target before the request and only touch the dialog afterwards if it still shows that user, so a close mid-request can no longer swallow a successful deactivation's toast and list refresh, close a dialog reopened for someone else, or leave a late error on a closed form |
| Changed | Focus fallback: the temporary `tabindex` on `<main>` is removed when focus leaves; only that element (`data-focus-fallback`) loses its focus ring |
| Changed | `AppLayout.vue`: sidebar toggle `aria-label`/`aria-expanded`/`aria-controls`; bell and account button `aria-controls`; notifications menu closes on click-away and Escape (focus back to the bell); `aria-controls` only while the panel is open; logo link named for the phone layout; links get `aria-current="page"`; the phone link strip fades at its right edge; the notifications panel is never wider than the viewport |
| Changed | Icon-only close buttons in the Users dialogs and the checklist procedure preview have `aria-label`s |
| Tests | Unit: `BaseModal.test.js` (real browser via a throwaway Vite server: focus on open, Tab / Shift+Tab wrap, hidden controls skipped, Escape closes only the top of two dialogs, focus back to each opener, `initial-focus`, backdrop / no-close options). E2E: `accessibility/modals-and-toasts.spec.js` (Generate Month and Add User from the keyboard, Tab trapped both ways, Escape, focus return; error toast in the assertive region; notifications menu Escape / click-away). `users/deactivate.spec.js` looks for an `alertdialog`; `my-shifts/load-errors.spec.js` scopes its alert/status checks to `main`, since the toast regions are always present |

---

## 2026-10-09 — 🧪 Tests for destructive endpoints, lint and build in CI, npm vulnerabilities cleared

> **Deploy notes**
> - No migrations. Frontend dependencies changed (in-range bumps): run `npm ci` and rebuild the frontend image.
>
> **Behaviour changes**
> - None in the app. CI now fails a frontend change that has a Biome error or does not build, before e2e runs, and builds both Docker images.

- **Destructive endpoints and the shift change request flow had no backend tests.** New Pest feature tests cover shift group delete, procedure delete, delegation delete, user deactivation gaps and checklist template archiving (roles, status codes, what happens to related rows), plus the change request endpoints. Deletes that still wipe history are pinned as current behaviour, marked `// Current behaviour: cascades; see follow-up`, and left for a decision: **shift group delete** removes the group's shifts, assignments, change requests, shift checklists, streak rules, on-call duties and callbacks (handovers stay, unlinked from the shift); **procedure delete** removes its versions, FAQ items and replies, response templates, feedback, read receipts, access log, owner changes and tag links (child procedures move to the root, checklist items lose the link); **delegation delete** removes its members and log; **unassigning, moving, or deactivating with "remove future shifts"** acknowledges a pending change request and then the FK cascade deletes it with the assignment; **deleting a shift** deletes its change requests. The change request flow has no approve/reject/cancel or swap step (a manager edits the schedule and acknowledges); no bugs found. (M25)
- **CI had no lint, format or build step, and Biome had a backlog.** Safe fixes (import order) applied; genuinely unused code removed by hand; `noUnusedVariables`/`noUnusedImports` turned off for `.vue` files, where Biome cannot see template usage (~440 of the 459 warnings were such false positives). Biome: 6 errors / 459 warnings / 32 infos → 0 / 0 / 32. New `lint-build` CI job (`npm run lint`, `npm run build`) gates e2e; new Docker Build workflow builds both images. Pint is installed but not enforced: it would reformat 136 of 249 PHP files. (M27)
- **npm audit reported 8 vulnerabilities (7 high).** In-range bumps and `npm audit fix` clear all of them (0 in `npm audit` and `npm audit --omit=dev`). `axios`, which ships in the bundle, moves to `dependencies` so `--omit=dev` audits it; the unused `src/bootstrap.js` and its only dependency `lodash` are removed. (M28)

### Backend
| What | Details |
|------|---------|
| Tests | New `ShiftGroupDeleteTest`, `ProcedureDeleteTest`, `DelegationDeleteTest`, `ShiftChangeRequestTest` (file, duplicate, validation, someone else's assignment for every role, mine, month list for managers only, acknowledge by admin/maintainer and not by employees, 404, reassigned shift, deleted shift). `UserDeactivationTest`: non-admins refused, delegation memberships kept, first deactivation time kept, API tokens revoked, change requests and on-call callbacks of removed future work. `ChecklistTemplateHistoryTest`: non-admins cannot archive, re-archiving keeps the first `archived_at` |

### Frontend
| What | Details |
|------|---------|
| Changed | `npm run lint` is `biome check --diagnostic-level=error .` (lint + format + import order, as the pre-commit hook); `lint:fix` is `biome check --write .` |
| Changed | `biome.json`: override turning `noUnusedVariables`/`noUnusedImports` off for `**/*.vue` |
| Changed | Removed unused code: `useRouter`/`useRoute` handles in `ProcedureEditorModal`, `ChecklistBuilderView`, `ChecklistFillView`, `ProceduresToLearnView`; `collectIds` (`ProceduresListView`), `fmtDate` (`ProcedureDetailView`), `formatTime` (`MyShiftsView`), an unused local in `MyAvailabilityView`. `Number.isNaN`, optional chains and optional catch bindings where Biome flagged them (same behaviour) |
| Dependencies | `@tiptap/*` 3.20.0 → 3.31.4, `vue` 3.5.28 → 3.5.43, `vite` 7.3.6 → 7.3.7, `axios` 1.19.0 → 1.20.0; transitive `prosemirror-view` 1.42.6, `nanoid` 3.3.20, `source-map-js` 1.2.2 via `npm audit fix`; `markdown-it` (and `prosemirror-markdown`) no longer installed, since `@tiptap/pm` 3.31 does not pull them in. `axios` moved from devDependencies to dependencies; `lodash` removed |
| Removed | `src/bootstrap.js` (imported by nothing; set `window._`/`window.axios`, which the app does not use) |

### CI
| What | Details |
|------|---------|
| Added | `frontend-tests.yml`: `lint-build` job (npm ci, lint, build); the e2e `test` job `needs` it |
| Added | `docker-build.yml`: builds `backend/` and `frontend/` images (matrix, `docker/build-push-action`, no push, no secrets) with a GitHub Actions layer cache per image; on pull requests and pushes to `main` touching backend/frontend |
| Changed | `permissions: contents: read` on every workflow |

## 2026-10-09 — 🔕 No more silent failures: delegations, expired sessions, My Shifts, dashboard and the checklist archive

> **Deploy notes**
> - No migrations, no new dependencies. Rebuild both images (new API route, new frontend files).
>
> **Behaviour changes**
> - **A delegation can be a single day** (start date = end date). An end date before the start is still refused, now also when an edit changes only one of the two dates.
> - **A delegation is "Ongoing" through its last day** (Europe/Warsaw), "Completed" from the next day. The My Delegations badge says "Ongoing" (it said "Active"), and archiving a delegation that ends today asks "still active?".
> - **Withdrawing a request to join a delegation asks for confirmation.**
> - **When the session ends while a page is open** (expiry, logout elsewhere, deactivation), the next request takes the user to `/login?redirect=<that page>`, and signing in returns there (only paths inside the app are followed). Opening a protected page while logged out does the same, and a user who is in fact still signed in (e.g. after a stale-token 419) is sent straight on to the `redirect` page.
> - **Logout retries once** with a fresh CSRF cookie; if it still fails the user stays on the page with an alert giving the reason and "You are still signed in." (for a stale token: "Your session token expired; reload the page and try again."). It used to show the login page over a live session.
> - **The dashboard no longer shows statistics** (they were hard-coded: 12 active shifts, 24 employees, 168 hours, 3 pending swaps) **or the Reports button** (it did nothing). Quick Actions only shows for admins.
> - **The checklist archive API pages at most 100 rows per request** (it allowed 1000). The archive screen pages on the server and its CSV comes from the new export endpoint, with every matching row.
> - **The CSV export lists handovers newest filed first** (by id), whatever column the screen is sorted by, and starts with a UTF-8 BOM so Excel shows Polish names correctly.

- **Delegations: a one-day delegation couldn't be created, and every failed action was silent.** The API required the end to be after the start, although delegations are whole days with the last day included. Adding, removing, approving and rejecting people, changing their dates, opening/closing registration, deleting, archiving, joining and withdrawing only wrote to the console on failure; they now show the server's message (form fields show their 422 errors), and their buttons are disabled while the request runs. The status label and the archive's "still active" check use one helper that counts the last day in the business timezone. The Users screen's delegation form, which had drifted (date-times, no validation), is now the same component as Manage Delegations'. (M15)
- **A session that expired after the page loaded wasn't handled well, and a failed logout pretended to succeed.** The existing 401/419 interceptor (`sessionExpiry.js`) now sends a `redirect` back to the current page and navigates once for any number of failing calls; the alarm poll stops when the session ends. Logout refreshes the CSRF cookie and retries once on failure, clears the signed-in user before navigating, and says so when it couldn't log out. (M16)
- **My Shifts said "No shifts or delegations for this month" when loading failed.** A failed shifts load now shows an error with Retry; if only delegations fail, the shifts still show with a small notice. (M19)
- **The dashboard showed made-up statistics and a dead Reports button.** Both removed, with their data. (M21)
- **The checklist archive silently stopped at 1000 handovers, and so did its CSV.** The list is paginated, searched and sorted on the server, and Export CSV downloads every handover matching the filters, search and sort from a streamed endpoint with the same per-user visibility as the list. Pending confirmations were checked: neither the API nor the fill screen caps them, so nothing changed there. (M23)

### Backend
| What | Details |
|------|---------|
| Changed | `POST /api/delegations`: `end_datetime` is `after_or_equal:start_datetime` (was `after`). `PUT /api/delegations/{id}`: 422 on `end_datetime` when the resulting end (sent or stored) is before the resulting start |
| Changed | `GET /api/checklist-submissions`: `per_page` 1..100 (was up to 1000); new `search` (template or outgoing/incoming name, case-insensitive), `sort` (`date`, `template`, `outgoing`, `incoming`, `status`, `timing`, `submitted_at`; anything else 422) and `direction` (`asc`/`desc`) params; ties broken by date, created_at, id; response adds `status_counts {completed, pending_confirmation, draft}` over all matching rows; `date_from`/`date_to` must be `YYYY-MM-DD` |
| Added | `GET /api/checklist-submissions/export` (any logged-in user, scoped by `visibleTo()` like the list): `streamDownload` of `checklist-archive-YYYY-MM-DD.csv`, columns Date, Template, Outgoing Employee, Incoming Employee, Status, Timing, Submitted At (business timezone), UTF-8 BOM first; rows read with `lazyByIdDesc(500)` (keyset on the id, no OFFSET; order: newest filed first, the list's `sort` is not applied). Cells starting with `=`, `+`, `-`, `@`, tab or CR get a leading `'`. `index()` and `export()` share `archiveQuery()` |
| Tests | New `DelegationDatesTest` (single day, end before start on create and edit) and `ChecklistArchiveExportTest` (1100 rows exported, every filter, employee scoping and 401 for guests, CSV escaping and formula prefixes, page-size cap and status counts, search and sort). `RoutePermissionMatrixTest` lists the export route and reads statuses with `getStatusCode()` (works for streamed responses) |

### Frontend
| What | Details |
|------|---------|
| Added | `src/delegationStatus.js` (`businessTodayStr`, `delegationStatus`, `isDelegationCompleted`; Europe/Warsaw, end inclusive) with `delegationStatus.test.js`; `src/components/DelegationForm.vue` (shared create/edit form: client checks, server field errors that clear once their field is edited, `saving`, optional description/registration; kept mounted in Users so switching to Assign Existing keeps the draft) |
| Changed | `DelegationsManageView.vue`, `DelegationsView.vue`, `UsersView.vue`: errors via `describeApiError` + toast (or the modal's error line in Users), per-action in-flight guards, Withdraw confirm (`ConfirmModal`), `DelegationForm` |
| Changed | `sessionExpiry.js`: `redirect` query, dedupe (the first failure clears `currentUser`), `/sanctum/csrf-cookie` left to its caller, new `safeRedirect()`; router guard passes `redirect`, and the login route's guard follows it for a still signed-in user; `LoginView.vue` returns to it |
| Added | `src/logout.js` (`logoutWithRetry`, `logoutFailureMessage`) with `logout.test.js`. `AppLayout.vue`: logout uses it, disables the button while running, shows an alert on failure, clears `currentUser` before navigating; `stopAlarmPoll()` on 401/419, on `currentUser` becoming null, on logout and on unmount |
| Changed | `MyShiftsView.vue`: `Promise.allSettled`, error state with Retry, delegations notice; stale-response guard kept |
| Changed | `DashboardView.vue`: stat cards, their data and the Reports action removed; Quick Actions admin-only; the crew/schedule row only has two columns when a crew is live |
| Changed | `ChecklistHistoryView.vue`: server pagination (page buttons labelled "Page N"), filters applied on Apply and sent with every page, sort and export; debounced server search; stats from `status_counts`; error state with Retry; Export CSV downloads from the new endpoint and shows the server's message on failure (new `readBlobErrorBody()` in `apiErrors.js` parses a JSON error body that arrived as a Blob) |
| Tests | E2E: `delegations/manage.spec.js` (one-day create, forced 500 on approve shows a message, Withdraw asks first), `auth/session-expiry.spec.js` (expired session → `/login?redirect=…` → back after login; logout with a forced 419 still logs out; a logout that keeps failing says so), `my-shifts/load-errors.spec.js`, `dashboard/dashboard.spec.js`, `checklists/history.spec.js` (page 2, CSV header and row count for a filtered set). `users/deactivate.spec.js` expects the `redirect` query. Unit: `sessionExpiry.test.js` (redirect, dedupe of parallel failures, 419, CSRF call excluded, `safeRedirect`) |

---

## 2026-10-09 — 🔐 Access and XSS: handovers are filed and read by the right people, procedure HTML is sanitised, every route's permissions are tested

> **Deploy notes**
> - Two migrations: `submitted_by_user_id` on `checklist_submissions`, and a one-off clean-up that sanitises the HTML already stored in procedures, drafts and version snapshots (idempotent; it only rewrites rows that change). The backend container runs `migrate` on start; otherwise run `php artisan migrate --force`.
> - New PHP dependency `ezyang/htmlpurifier`, new npm dependency `dompurify`: rebuild both images.
> - The frontend Nginx now sends a `Content-Security-Policy` (see README, "Content Security Policy and procedure HTML"). If the SPA loads anything from another origin, extend it.
>
> **Behaviour changes**
> - **The checklist history API shows employees and schedule maintainers only their own handovers** (as outgoing or incoming user, or as the one who filed it); opening someone else's answers 403. Admins see everything. The history screen was already admin-only; no schedule screen reads handovers.
> - **A handover's shift must be the outgoing employee's shift on the handover date** (also when an admin files on their behalf). The fill form warns when the chosen shift isn't theirs. Leaving the shift empty is still allowed.
> - **A handover only accepts answers for current items of its own template, each once** (not items retired from it, nor items of a retired group; the form never offers those). Its `date` must be `YYYY-MM-DD`.
> - **Procedure content keeps only what the editor produces** (headings, paragraphs, lists, quotes, code, links, images, bold/italic/underline/strike). Scripts, event handlers, inline styles, classes, `javascript:` links and non-image `data:` URLs are removed on save. An image without alt text is stored with `alt=""`.
> - **A user without the role for an admin route now always gets 403**, also for an id that doesn't exist (it used to get 404, which told them whether the record existed).

- **Handovers could be filed with items from other templates and pinned on other people's shifts, and didn't say who filed them.** Each answer's item must belong to the submitted template (and appear once), a given shift must be on the handover date with the outgoing user assigned to it, and every new handover records who filed it (`submitted_by_user_id`), shown in the history detail as "Submitted by …" when an admin filed for someone. Only admins may file for someone else (since H1/#25, unchanged). (M1)
- **Any logged-in user could read every handover of every user** through `GET /api/checklist-submissions` (and `/{id}`), including with a `user_id` filter. Non-admins now get only their own; the filters narrow that list and never widen it. (M2)
- **Stored XSS: procedure HTML was saved as sent and rendered with `v-html`, with no CSP.** It is now sanitised on every write (HTMLPurifier allowlist matching the TipTap editor; new-tab links get `rel="noopener noreferrer"`; images only http(s) or base64 png/jpeg/gif/webp), sanitised again in the browser with DOMPurify before rendering, existing rows are cleaned by a migration, and the frontend Nginx sends a CSP that blocks inline and foreign scripts. (M3)
- **Route permissions weren't tested systematically.** A new test walks every `/api` route from the router: a reviewed per-group list (public, any logged-in user, admin, admin-or-schedule-maintainer) fails on any added, removed or moved route; guests get 401 everywhere but login; every admin route answers 403 to employees and schedule maintainers, every schedule route 403 to employees, and allowed roles get through. (M24)

### Backend
| What | Details |
|------|---------|
| Added | Migration `add_submitted_by_user_id_to_checklist_submissions_table` (nullable FK to users, `restrictOnDelete` like the other authored-by columns); `ChecklistSubmission::submittedBy()`, `scopeVisibleTo(User)`, `isVisibleTo(User)` |
| Changed | `POST /api/checklist-submissions`: `responses.*.checklist_item_id` is `integer`, `distinct` and must be a current (not retired) item in a current group of `checklist_template_id` (422 otherwise); `shift_id` is `integer` (a malformed id was a 500), must be dated `date` and assigned to `outgoing_user_id` (422 on `shift_id`); `date` is `date_format:Y-m-d` (was `date`, which took "tomorrow" or a datetime); stores `submitted_by_user_id` = caller; the response includes `submitted_by` |
| Changed | `GET /api/checklist-submissions`: `visibleTo(caller)` before the filters; `GET /api/checklist-submissions/{id}`: 403 unless visible, includes `submitted_by {id, name}` |
| Added | `App\Services\ProcedureHtmlSanitizer` (HTMLPurifier, HTML 4.01 output, definition cache off) with `ProcedureHtml\CodeLanguageClass` (only `class="language-*"` on `<code>`) and `ProcedureHtml\ImageDataUriScheme` (adds webp); bound as a singleton |
| Changed | `Procedure` (`content`, `draft_content`) and `ProcedureVersion` (`content`) sanitise in a set mutator, so every write path is covered; `ProcedureController::update()` compares the sanitised content, so equivalent HTML doesn't publish a version |
| Added | Migration `sanitize_stored_procedure_html`: cleans `procedures.content`, `procedures.draft_content`, `procedure_versions.content` in chunks of 10 (snapshots can hold multi-MB inline images), writing only changed rows |
| Changed | `App\Http\Kernel`: `EnsureUserIsActive` and `EnsureUserIsAdmin` run before `SubstituteBindings` |
| Tests | New: `HandoverAccessTest` (M1/M2), `ProcedureSanitizingTest` (M3, incl. a round trip of the editor's real output and the data migration), `RoutePermissionMatrixTest` (M24) |

### Frontend
| What | Details |
|------|---------|
| Added | `src/procedureHtmlSanitizer.js` (DOMPurify config + hooks, same allowlist as the backend) and `src/procedureHtml.js` (`sanitizeProcedureHtml`); unit test `procedureHtmlSanitizer.test.js` runs DOMPurify in a browser page |
| Changed | `ProcedureContent.vue` renders `sanitizeProcedureHtml(procedure.content)` |
| Changed | `ChecklistFillView.vue`: a warning under the shift picker when the outgoing employee isn't assigned to the chosen shift. `ChecklistHistoryView.vue`: "Submitted by X" in the detail when it differs from the outgoing user |
| Changed | `docker/security-headers.conf` (new, included by `docker/nginx.conf` at server level and in the static-asset location, which used to drop every security header; copied by the `Dockerfile`): the old headers plus `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'` |

---

## 2026-10-09 — 🧮 Data fixes: archived delegations keep their history dates, 24-hour shifts, staffed slots and overlapping on-call

> **Behaviour changes**
> - **Removing a day's slot that has people on it now asks first.** Empty slots go first; a staffed one is only removed after the manager confirms the list of affected shifts (if the list changes before the confirm lands, the dialog asks again, up to 3 times). The kept slots are renumbered 1..n in their order, e.g. after removing an empty shift 2 the night shift 3 becomes shift 2, so a month-wide slot type set later for "slot 2" applies to it. Growing the day again gives each new slot its usual preset times, unless the day already has those; then the first preset times it lacks (the removed afternoon comes back, not a second night).
> - **New on-call periods can't overlap** another period of the same person (in any group), and **callbacks can't overlap** the person's other callbacks. Back-to-back is fine. Overlapping rows already stored are left as they are and counted once in the settlement report.
> - **Checklist alerts look back 7 days.** A checklist missed more than 7 days ago no longer shows as an overdue alert (it is still in the checklist history).
> - **Settlement hours of a shift are elapsed time** in the business timezone: a 00:00-00:00 shift on the October fall-back day counts 25 h (a night shift that night 9 h), on the March spring-forward day 23 h.
> - Archive timestamps already reset by earlier archive/unarchive round trips can't be recovered; from now on they are kept.

- **Archiving or unarchiving a delegation reset every log entry's date to "now"**, and the delegation's own created/updated dates too, so its history showed everything as having happened at the moment of archiving. Logs, the delegation and its members' pivot rows now keep their original timestamps both ways. (M9)
- **"My checklist alerts" scanned every shift the user ever had** and every submission they ever made, on every page load. It now reads shifts from 7 days before today (business timezone) through today, which also covers yesterday's overnight shift, with a fixed number of queries. (M10)
- **24-hour shifts (00:00-00:00, the one-shift-per-day preset) end at 00:00 the next day everywhere.** A "30 min before shift end" checklist is due 23:30 on the shift's own day and a midday submission is on time (this deadline was already right since the business-timezone fix; it is now pinned by tests). The "end at or before start ⇒ next day" rule lives in one place, `BusinessDate::endsNextDay()`, used by shift end times, the settlement calculator and the current-shift "overnight" flag. (M11)
- **Reducing a day's shift count silently deleted staffed shifts**, latest first, with their assignments and day-off requests, although the code promised "unassigned first". Empty slots are now removed first; if staffed ones must go, the API answers 409 with the list (date, slot, times, who is assigned, pending day-off requests) and the Schedule screen shows it in the confirm dialog. Cancel leaves the day unchanged; confirm removes them. Handover submissions of a removed slot are kept (their shift link is cleared, date and template stay). (M12)
- **Overlapping on-call entries were counted twice in the settlement report.** Overlaps are now refused on entry (422 with the clashing period) and merged per person before callback hours are summed. (M13)

### Backend
| What | Details |
|------|---------|
| Fixed | `DelegationArchiveController`: delegation copied with `forceFill` (keeps `created_at`/`updated_at`), logs bulk-inserted with their raw `created_at`, pivot rows keep their timestamps. `create()` dropped `created_at` (not fillable) and the column default stamped now |
| Changed | `ChecklistAlertController`: `ALERT_LOOKBACK_DAYS = 7`; shifts and date-matched submissions limited to `[today − 7, today]`; keyed-array lookups instead of `in_array` |
| Added | `BusinessDate::endsNextDay(?start, ?end)` (the single roll-over rule) and `BusinessDate::localInstant(date, time)` |
| Changed | `SettlementPeriodCalculator::shiftTiming()`: built in the business timezone with `endsNextDay()` (was a UTC wall-clock parse with its own `lte` roll-over); `CurrentShiftController` `is_overnight` uses `endsNextDay()` |
| Changed | `PUT /api/schedule/day-shifts`: one locked transaction for add and remove; empty slots removed first, kept slots renumbered; staffed removal needs `confirm_remove_staffed: true` (optional `expected_remove_ids`, a changed list asks again), else 409 `{code: "staffed_shifts_removal", message, shifts: [{id, date, shift_number, start_time, end_time, assigned_users, pending_change_requests}]}` |
| Changed | `PUT /api/schedule/day-shifts` growing: a new slot takes the preset entry at its position as before, unless the day's slots already have those times; then the first preset entry the day doesn't have |
| Fixed | `POST /api/schedule/assign`: the shift is re-checked under a row lock inside the write transaction; a slot deleted meanwhile answers 404 "This shift no longer exists." instead of a foreign-key 500 |
| Changed | `POST /api/on-call-assignments`: 422 (`starts_at`) when the user already has an on-call period with `starts_at < end AND ends_at > start`; `POST /api/on-call-assignments/{id}/callbacks`: same for the user's callbacks. Both lock the user row |
| Changed | `SettlementPeriodController`: callbacks merged per user (union of intervals) before summing; a merged block counts in the week of its first start |
| Tests | New: `DelegationArchiveTimestampsTest`, `ChecklistAlertWindowTest` (incl. a query-count guard), `ShiftRollOverTest` (incl. the 25 h DST day), `DayShiftCountReductionTest`, `OnCallOverlapTest`. `StreakFollowupsTest`'s slot-removal case now staffs both slots and confirms |

### Frontend
| What | Details |
|------|---------|
| Changed | `ScheduleView.vue`: the slot "−" button handles the 409 by listing the staffed shifts in the confirm dialog (with a note that the remaining slots are renumbered) and resending with `confirm_remove_staffed`; a further 409 shows the new list again, up to 3 dialogs, then a toast. Errors toast the server's message. The −/+ buttons have `aria-label`s |
| Added | `src/dayShiftCount.js` (the confirm loop and dialog text) with unit tests `dayShiftCount.test.js` |
| Changed | `ConfirmModal.vue`: the message keeps line breaks (`whitespace-pre-line`) |
| Tests | New e2e `schedule/day-shift-count.spec.js` (cancel keeps the staffed slot, a stale confirm asks again, confirm removes it); fixture `setDayShiftCount` |

---

## 2026-10-09 — ⬆️ Laravel 13 on PHP 8.4 (off end-of-life Laravel 9)

> **Action needed (existing deployments)**
> - **Rebuild the backend image**: `docker compose up -d --build backend`. It now runs PHP 8.4 (was 8.2); the entrypoint re-caches config, routes and views on start, so no manual `cache:clear` is needed. Running artisan or the tests on the host now needs PHP 8.4.
> - No new env keys and no new migrations. Logged-in users stay logged in: sessions keep the `php` serializer, and sessions from before the upgrade are still accepted.
> - **Don't change `BCRYPT_ROUNDS` casually.** Laravel now rehashes a password at login when its bcrypt cost differs from `BCRYPT_ROUNDS` (default 10), and the new hash ends that user's other logged-in sessions. Raising it means each user is logged out of their other browsers once, at their next login.

- **The backend ran on Laravel 9, unsupported since February 2024**, so framework security fixes since then never reached it. It is now on Laravel 13 (security fixes until early 2028), upgraded one major at a time (10, 11, 12, 13) with the backend suite green after each. PHP moves to 8.4 in the image and CI (security support until the end of 2028; PHP 8.2's ends December 2026). `composer audit` reports no advisories.
- **The application structure is unchanged**: same HTTP Kernel, middleware, service providers and config files (Laravel 11+ still supports the Laravel 10 layout).
- **Date maths keeps its results under Carbon 3**, whose `diffIn*` methods return signed fractions where Carbon 2 returned whole absolute numbers. The settlement period calculator and on-call callback hours now ask for absolute values explicitly and round down to whole minutes as before. Hours keep their fraction, so a 7:30 shift or a 1:30 callback counts 7.5 h / 1.5 h (now tested).
- **Session files no longer contain password hashes.** Laravel 12.45+ and Sanctum's `AuthenticateSession` keep an HMAC of the password hash instead of the bcrypt hash itself. Laravel's login now records it too, so `login()` no longer writes it by hand. A password change still ends the user's other sessions, whether a session holds the new HMAC or an old raw hash from before the upgrade (both are tested).
- **Cached values can no longer unserialize into PHP objects** (`cache.serializable_classes = false`, Laravel 13's default), so a leaked `APP_KEY` or writable cache can't be turned into a deserialization attack. The app caches only counters.
- **CSRF protection also accepts a same-origin `Sec-Fetch-Site` header** (Laravel 13's `PreventRequestForgery`); requests without it still need the XSRF token, as before.

### Backend
| What | Details |
|------|---------|
| Changed | `laravel/framework` ^9.19 (9.52.21) → ^13.0 (13.35.0); `laravel/sanctum` ^3.0 → ^4.0 (4.3.3); `laravel/tinker` ^2.7 → ^3.0; `nunomaduro/collision` ^6.1 → ^8.6; `spatie/laravel-ignition` ^1.0 → ^2.4; `nesbot/carbon` 2 → 3 |
| Changed | Tests: `pestphp/pest` ^1.23 → ^4.0, `pestphp/pest-plugin-laravel` ^1.4 → ^4.0, `phpunit/phpunit` ^9.5 → ^12.0; `phpunit.xml` `<coverage processUncoveredFiles>` → `<source>` |
| Changed | `composer.json` `php` ^8.0.2 → ^8.4; `config.platform.php` = `8.4.26` (the image's PHP), so composer on a newer PHP (the `composer:2` build stage runs 8.5) can't lock packages the 8.4 runtime can't load |
| Changed | `SettlementPeriodCalculator`, `SettlementPeriodController`: `diffInMinutes/Days/Months` with an explicit absolute flag and `(int)` casts (Carbon 3) |
| Removed | `AuthController::login()`: the manual `password_hash_{guard}` write; `Auth::attempt()` → `SessionGuard::login()` stores the HMAC itself |
| Changed | `config/sanctum.php`: `verify_csrf_token` → `validate_csrf_token` (Sanctum 4 key, same `App\Http\Middleware\VerifyCsrfToken`) |
| Changed | `App\Http\Middleware\VerifyCsrfToken` extends `PreventRequestForgery` (was the deprecated `VerifyCsrfToken` alias) |
| Added | `config/cache.php`: `serializable_classes => false` |

### Infrastructure
| What | Details |
|------|---------|
| Changed | `backend/Dockerfile`: `php:8.2-fpm-alpine` → `php:8.4-fpm-alpine` |
| Fixed | `backend/Dockerfile`: creates `storage/framework/cache/data`, `sessions`, `views` one path each (the brace form made a literal `{cache,sessions,views}` directory under `sh`). Laravel 13's `cache:clear` exits 1 when the file cache directory is missing, which broke the e2e global setup on a fresh container |
| Changed | `.github/workflows/backend-tests.yml`: PHP 8.0 → 8.4 |
| Changed | README: Laravel 13 / PHP 8.4, and how to run the Pest suite in the backend image without PHP 8.4 on the host |

---

## 2026-10-09 — 🔐 Secrets and logins: no default admin password in production, per-account login limit, password changes end other sessions, `.env` out of git

> **Action needed (existing deployments)**
> - **Change the admin password** of any server seeded before this release: `migrate --seed` used to create `admin@shiftchanger.com` with the password `1234`. Log in and use **Change password**, or reset it from another admin.
> - **Rotate `APP_KEY` and the database password.** The root `.env` with both was committed to git; removing it from the index doesn't remove it from history. Generate a new `APP_KEY` (rotating it logs everyone out and voids anything encrypted with the old key) and a new `DB_PASSWORD` (change it in Postgres with `ALTER USER`, then in `.env`), and purge the old `.env` from the history if the repository was ever shared.
> - **`docker compose` now needs `APP_KEY` and `DB_PASSWORD` in `.env`**; there is no `secret` default any more. A deployment that relied on that default must set `DB_PASSWORD=secret` explicitly (and then change it).
> - Behind HTTPS, set `SESSION_SECURE_COOKIE=true` in `.env`.

- **A deployment had a known admin login.** Following the README (`migrate --seed`) gave every server `admin@shiftchanger.com` / `1234`. Outside `APP_ENV=local`/`testing` the seeder now takes the admin password from `SEED_ADMIN_PASSWORD` (at least 12 characters, not `1234` or `password123`) and stops with a clear error, creating nothing, without it. The demo-user seeders (`UsersSeeder`, `ScheduleMaintainerSeeder`, `E2eBaselineSeeder`, passwords `password123` / `schedule123`) refuse to run outside local/testing. The dev stack, CI and the e2e suite (all local/testing) keep `1234`; `SEED_ADMIN_PASSWORD` can override it locally. Re-seeding still never changes an existing admin's password, needs no `SEED_ADMIN_PASSWORD` once the admin exists, and warns (log and console) when that admin still has `1234` outside local/testing. The container entrypoint only migrates, it never seeds. The README lists the credentials under "local development only" and shows the production seed: `docker compose exec -e SEED_ADMIN_PASSWORD=... backend php artisan db:seed --force`. Forcing a password change on first login is a follow-up.
- **One looping client could lock everyone out of login, or guess ~86k passwords a day.** Without trusted proxies every request had the frontend container's IP, so all users shared one per-IP bucket. The backend now trusts the private and loopback ranges (the compose network; override with `TRUSTED_PROXIES`, never `*`) for `X-Forwarded-For` and `-Proto` only, so limits and logs see the real client. The frontend Nginx **sets** `X-Forwarded-For` to the connecting address instead of appending the client's header, so a client that reaches it from a private address (LAN, IPv6 via docker-proxy, Docker Desktop) can't forge a new IP per attempt; the README and `nginx.conf` show the `real_ip` lines (and the `X-Forwarded-Proto` line) to use behind a TLS proxy. Failed logins are counted per email and client IP (counted before the password check, so parallel bursts can't overshoot): after 5 failures in a minute (`LOGIN_RATE_LIMIT`) that pair gets **429** with `Retry-After`, even with the right password, until the minute is up; other emails from the same IP are unaffected, and a successful login resets the counter (so the e2e suite's many logins never reach it). The per-IP API limit still applies. Failed and throttled logins are logged at warning level with email and IP, never the password.
- **Changing a password left other sessions logged in.** Sanctum's `AuthenticateSession` now runs on the SPA's requests: each session keeps the password hash it saw, and one whose hash is stale gets **401**. The login itself records the hash, so a session that only logged in and never called the API is ended too. Changing your own password ends your other sessions (this one stays logged in); an admin reset ends all of that user's sessions. It works with the `file` session driver the stack uses. Both also rotate the remember token.
- **The root `.env` was in git.** It is now untracked and git-ignored (the local file is unchanged). `.env.example` documents every key the compose files read, with `APP_KEY` and `DB_PASSWORD` empty, and `docker-compose.yml` refuses to start without them (`${APP_KEY:?}`, `${DB_PASSWORD:?}`, was `secret`). CI generates both as throwaway values.
- **The dev backend (APP_DEBUG=true) listened on every interface.** `docker-compose.dev.yml` binds it to `127.0.0.1:8090`; Vite's proxy and the e2e suite still reach it.
- **No guidance for HTTPS.** `SESSION_SECURE_COOKIE` is passed through compose (default `false`, forced `false` in the dev override for plain-http localhost); the README's new "HTTPS in production" section and the env templates say to put TLS in front and set it to `true`.

### Backend
| What | Details |
|------|---------|
| Changed | `DatabaseSeeder`: outside local/testing the admin password comes from `env('SEED_ADMIN_PASSWORD')` (min 12, not a published dev password), else `RuntimeException` before any write; local/testing default `1234` (overridable) |
| Added | `SeedsDevelopmentData` trait (`isDevelopmentEnvironment()`, `refuseOutsideDevelopment()`), used by `UsersSeeder`, `ScheduleMaintainerSeeder`, `E2eBaselineSeeder` (checked before it calls the others) |
| Changed | `AuthController::login()`: `RateLimiter` key `login:lower(email)\|ip`, `config('app.login_rate_limit')` (env `LOGIN_RATE_LIMIT`, default 5) failures per 60 s, 429 + `Retry-After`; cleared on success; `Log::warning` on failure and on throttle (email, ip) |
| Changed | `TrustProxies`: proxies from `config('app.trusted_proxies')` (env `TRUSTED_PROXIES`, default `127.0.0.1/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1,fc00::/7`); trusted headers `X-Forwarded-For` and `X-Forwarded-Proto` only |
| Changed | `AuthController::login()`: `RateLimiter::hit` before `Auth::attempt`; stores `password_hash_{guard}` (each `sanctum.guard`) in the session after `regenerate()` |
| Changed | `DatabaseSeeder`: an existing admin is left untouched without needing `SEED_ADMIN_PASSWORD`; outside local/testing warns (`Log::warning` + console) if it still verifies against `1234` |
| Changed | `config/sanctum.php`: `middleware.authenticate_session` = `Laravel\Sanctum\Http\Middleware\AuthenticateSession` |
| Changed | `PUT /api/change-my-password` and `UserController::changePassword()`: also rotate `remember_token` |

### Infrastructure
| What | Details |
|------|---------|
| Removed | Root `.env` from the git index (`git rm --cached`); `.env` added to the root `.gitignore` |
| Changed | `docker-compose.yml`: `${APP_KEY:?}`, `${DB_PASSWORD:?}` (db and backend); passes `LOGIN_RATE_LIMIT`, `TRUSTED_PROXIES`, `SESSION_SECURE_COOKIE` |
| Changed | `frontend/docker/nginx.conf`: `X-Forwarded-For $remote_addr` (was `$proxy_add_x_forwarded_for`); commented `set_real_ip_from` / `real_ip_header` / `real_ip_recursive` and `X-Forwarded-Proto $http_x_forwarded_proto` for a TLS proxy in front |
| Changed | `docker-compose.yml`: passes `BUSINESS_TIMEZONE` (default `Europe/Warsaw`); `.env.example` says `SEED_ADMIN_PASSWORD` is not read from `.env` |
| Changed | `docker-compose.dev.yml`: backend port `127.0.0.1:${BACKEND_PORT:-8090}:8080`; `SESSION_SECURE_COOKIE: "false"` |
| Changed | `.env.example` (all compose keys, empty secrets, `SEED_ADMIN_PASSWORD`, `LOGIN_RATE_LIMIT`, `TRUSTED_PROXIES`, `SESSION_SECURE_COOKIE`, dev-only keys), `backend/.env.example` (same app keys, commented) |
| Changed | `.github/workflows/frontend-tests.yml`: generates a throwaway `DB_PASSWORD` next to `APP_KEY` |
| Changed | README: credentials under "Default credentials (local development only)", production seed with `SEED_ADMIN_PASSWORD`, key generation with `openssl`, "HTTPS in production", root `.env` step for e2e |

### Tests
- Pest `SeedAdminPasswordTest`: production without `SEED_ADMIN_PASSWORD`, with 11 characters, or with `1234`/`password123` throws and creates no user; any non-local env (staging) too; a valid password seeds the admin (`Hash::check` passes for it, fails for `1234`), no demo users; exactly 12 characters accepted; `UsersSeeder`/`ScheduleMaintainerSeeder`/`E2eBaselineSeeder` refuse in production with nothing written; a re-seed keeps the existing password; local and testing keep `1234` + demo users and the admin can log in; `SEED_ADMIN_PASSWORD` overrides locally; a production re-seed with the admin present needs no password and warns when it still has `1234` (no warning in local).
- Pest `LoginRateLimitTest`: the 6th wrong password for one email in a minute is 429 with `Retry-After` (the right password too); email counted case-insensitively; another email from the same IP is not blocked; success resets the counter; `LOGIN_RATE_LIMIT` respected, default 5; behind a trusted proxy the forwarded client IP is the key; a forged `X-Forwarded-For` from an untrusted address is ignored; a failed login is logged as a warning without the password; a private-range client behind Nginx (which overwrites `X-Forwarded-For`) can't rotate the key; `nginx.conf` sets `X-Forwarded-For $remote_addr` and doesn't append; the default 5 is read with `LOGIN_RATE_LIMIT` unset.
- Pest `PasswordChangeSessionsTest`: after a self-change a second session gets 401 and the changing session stays logged in; after an admin reset the user's session gets 401, the admin's doesn't, and the new password logs in; the remember token rotates; a session that only logged in (no API call) gets 401 after a password change elsewhere.

---

## 2026-10-09 — 📝 Procedure editor: errors you can see, admin-only editing, versioned updates, Escape that closes one dialog

- **The procedure editor swallowed every failed save.** A rejected Save Draft, Publish or Create (a validation error, a server error, a refused request) just stopped the spinner, and a successful Save Draft said nothing, so authors closed the editor and lost their work. Every write in the editor now says what happened: a 422 puts each field's message under its field (title, purpose, requirements, tags, content, owner; the change reason in the publish dialog) plus a summary above the form; a 5xx shows "Could not save, try again (500)." and any other refusal shows the server's message. The editor stays open and keeps what was typed, and the next attempt clears the old errors. Success shows a toast: **Draft saved**, **Published v*N***, **Procedure created**, **Procedure deleted**, **Draft discarded**. A failed load of the owner list and tags is shown in the editor, a failed open of the procedure as a toast. An expired session (401/419) is left to the existing redirect to the login page, with no error on top.
- **Owners who aren't admins were offered an editor that couldn't save.** The detail page showed **Edit** to a procedure's owner, but every procedure write is an admin route, so the owner list came up empty and every save failed silently. Editing stays admin-only: `can_edit` is now true for admins only, so non-admin owners no longer see **Edit** / **Continue Draft** or the draft badge, and **+ New Procedure** / **Create your first procedure** on the list and the template add/edit/delete buttons are shown to admins only (those writes were already admin-only on the server). Owners keep everything else: FAQ moderation, feedback, notifications.
- **A direct update bumped the version without a snapshot.** `PUT /api/procedures/{id}` moved v3 to v4 without a `procedure_versions` row, so `/versions/4` answered 404 and history and the diff skipped it. A change to any versioned field (title, purpose, requirements, content — the content alone used to count) now publishes the next version with its snapshot (optional `change_reason`, default "Updated"), in one transaction with the row locked, as publishing a draft does (that is now transactional and locked too), including resolving open feedback on older versions. While a draft is pending such an update is refused (**409**), since publishing the draft later would silently overwrite it. No screen calls this endpoint; it is kept for API clients. Creating a procedure now stores it, its tags and its v1 snapshot in one transaction (no procedure without `/versions/1`). The update **rejects an owner change** (422; sending the current owner back is fine): the owner is changed only through change-owner, which records who changed it and why. In the editor the owner select is read-only when editing, with a pointer to the Owners tab (saving a draft never changed the owner anyway).
- **Escape in a dialog over the editor also closed the editor.** Every open modal had its own document key listener, so Escape on the publish dialog or a delete/discard confirm also closed the editor, and the next open reset it. Open modals now form one stack (in `useFocusTrap`, which `ConfirmModal` and the schedule's rest-day dialog now use too): only the top one gets Escape and Tab. Closing an editor with unsaved changes by Escape, ✕ or Cancel asks **Discard unsaved changes?** first; a clean editor closes at once. Text typed while Save Draft is on its way still counts as unsaved (the saved state is what was sent). A server error on a field goes away as soon as that field is edited.
- **Procedure feedback, FAQ, templates and tags: double posts, one-click deletes, silent failures.** Double-clicking **Submit** on a report filed it twice (and alarmed the owner twice) and pressing Enter twice asked a FAQ question twice: Submit, Ask, Reply and the template and tag Save buttons are now disabled while their request is on its way, and a second call is ignored. Deleting a FAQ question (with all its replies), a reply or a response template now asks first. Failed saves — e.g. a duplicate tag name (422) — show the server's message (tags: under the name field, the dialog stays open) and successes show a toast, with a single message for a duplicate tag (under the field, no toast on top); a failed feedback status change goes back to the status it had. The FAQ "mark as outdated" toggles ignore a second click while one is on its way (a double click used to toggle twice).

### Backend
| What | Details |
|------|---------|
| Changed | `ProcedureController::show()`: `can_edit` = `isAdmin()` (was owner or admin) |
| Changed | `ProcedureController::update()`: bumps the version when title, purpose, requirements or content changes and stores the `ProcedureVersion` snapshot (`change_reason` optional, default "Updated"); `owner_id` must be the current owner (`Rule::in`), else 422; 409 when a versioned change meets a pending draft; resolves open feedback on older versions; runs in `DB::transaction` with `lockForUpdate` |
| Changed | `ProcedureController::store()`: procedure, tags and v1 snapshot in one `DB::transaction` |
| Changed | `ProcedureController::publishDraft()`: in `DB::transaction` with `lockForUpdate`; re-checks `has_draft` under the lock (422 "No draft to publish." when a concurrent publish/discard won) |
| Changed | Snapshot creation shared by `store()`, `update()` and `publishDraft()` (`snapshotVersion()`); the owner-or-admin checks inside `update`/`saveDraft`/`publishDraft`/`discardDraft` removed (the routes are in the admin group, which is the one rule; the route comment says so) |

### Frontend
| What | Details |
|------|---------|
| Added | `src/apiErrors.js`: `describeApiError(err, fallback)` → `{ silent, status, message, fieldErrors }` (401/419 silent; 422 first message per field + summary; 5xx generic with status; else server message), `fieldError(errors, name)` (also matches `name.N`) |
| Added | `useFocusTrap.js`: shared modal layer stack (`pushModalLayer`, `handleModalKeydown`, `modalLayerCount`), one document listener, keys go to the top layer only |
| Changed | `RestDayPolicyModal.vue` uses `useFocusTrap` (its own Escape listener removed; focus moves into the dialog) |
| Changed | `ConfirmModal.vue` uses `useFocusTrap` (its own keydown listener removed) |
| Changed | `ProcedureEditorModal.vue`: server field errors + summary (`role="alert"`), publish-dialog errors, success/error toasts, in-flight guard, unsaved-changes prompt (snapshot after load/save), owner select read-only when editing, admin-only open |
| Changed | `ProcedureDetailView.vue` (feedback/template in-flight flags, template delete confirm, toasts, templates editable for admins only), `ProcedureFaqTab.vue` (in-flight ask/reply, delete confirms, toasts, aria-labels on the outdated toggles), `ProcedureFeedbackTab.vue` (status change toast, reverts on failure), `ProcedureTagsView.vue` (name error, toasts, `AppToast`), `ProceduresListView.vue` (create buttons admin-only, `AppToast`) |

### Tests
- Pest `ProcedureEditingTest`: `can_edit` false for a non-admin owner, employee and schedule maintainer, true for an admin; every procedure write route 403 for the owner; an update stores the snapshot and `/versions/2` + the version list show it; a title-only change bumps with the default reason; no bump when nothing changes; an owner change is rejected (current owner accepted); a failed snapshot rolls the update back; an update during a pending draft is 409; an update resolves older open feedback; a failed v1 snapshot leaves no procedure.
- Playwright `procedures/editor.spec.js`: a non-admin owner (throwaway user) sees no Edit and no + New Procedure, an admin sees Edit; Save Draft 422 → field error, summary, editor and content kept; Save Draft 500 → message, retry clears it and shows "Draft saved"; publish 422 → change-reason error in the publish dialog; create 403 → message, editor kept; Escape on a confirm over the editor closes only the confirm; a dirty editor asks on Escape, Cancel and ✕, a clean one closes; typing while Save Draft is in flight still prompts; editing a field clears its server error.
- Playwright `procedures/feedback-faq-tags.spec.js`: double-clicked report Submit posts once; Enter twice asks once; a double-clicked outdated toggle sends one request; FAQ question and template deletes ask first; a duplicate tag shows the server error once, with the dialog open.
- Playwright `schedule/rest-day-policy.spec.js`: Escape cancels the rest-day dialog (focus starts inside it), nothing assigned.
- Unit `src/apiErrors.test.js`, `src/composables/useFocusTrap.test.js` (top layer only, out-of-order and repeated release).

---

## 2026-10-09 — 😴 Auto-fill rest rules see every shift: 11 h daily rest, 35 h weekly rest, delegations, all-or-nothing runs

- **Auto-fill could book 0 h of rest.** It only looked at the month and group being filled, and Polish Law compared the rest gap with the *last* shift in the month, ignoring direction. An employee with Oct 10 06:00–14:00 could get Oct 9 22:00–06:00 (16 h straight); a Sep 30 night shift was invisible to the October fill, so Oct 1 06:00 could follow it; and shifts in the employee's other groups were never seen (overlapping bookings were possible). Every mode (Random, Rotating, Polish Law) now keeps each employee's busy time — their shifts in **all groups**, from two days before the month to two days after it, the picks of the same run and, in Polish Law, their on-call callbacks — and gives a shift only with **at least 11 h of rest before and after it** (exactly 11 h is allowed; overlap never). Gaps are measured in Warsaw time as elapsed hours, so a DST night counts right. Random and Rotating used to block only back-to-back slots, so they now also skip e.g. 14:00–22:00 followed by 06:00 the next day (8 h).
- **Polish Law's weekly limits missed hours.** The weekly hour cap and the 5-working-days rule counted only the filled group's shifts in the month; they now count every group and the neighbouring month's days of a settlement week that crosses the month boundary (callbacks from every group as well).
- **The 35 h weekly rest was never checked.** Polish Law now refuses a pick that would leave the employee without 35 h of uninterrupted rest in a settlement week (exactly 35 h is allowed). The week is the settlement week from `SettlementPeriodCalculator`, taken as seven full days from its start (a week shortened by the end of a settlement period still gets seven days, or a one-day week could never hold 35 h), and every window a pick overlaps is checked — including the window of the previous week, settlement period or month that runs into it (October's last, shortened week Oct 29–31 keeps its window to Nov 4, so the November fill checks it too).
- **Auto-fill booked employees who were away on a delegation.** An employee on an **approved** delegation is now unavailable on every day it covers (their own dates when set, else the delegation's), in all three modes. Pending and rejected delegations don't block. In Rotating mode, as for unavailability, one delegation day keeps the employee out of the whole rotation block's subteam (the fallback can still pick them on the other days of the block), so long rotation blocks fill with other people. Manual assignment is unchanged (the schedule screen already warns). Delegation dates sent to the API with a time or an offset are now stored as the date written (`2026-10-05T00:30:00+05:00` → the 5th).
- **A failed run left a half-filled month.** Each auto-fill run (including the rest-policy clean-up) now runs in one database transaction (`DB::transaction`): an error part-way leaves no assignments behind.

> **Note:** existing assignments are not re-checked retroactively; only new auto-fill picks follow these rules. Shifts without start/end times count as busy for their whole date (00:00–24:00), as in the timezone fix, so Polish Law skips them as longer than 13 h.

### Backend
| What | Details |
|------|---------|
| Added | `App\Services\BusyIntervals`: per-employee sorted `[start, end)` intervals (`allowsDailyRest()` binary search + short backward scan, `longestRestWith()` for the 35 h check); loads all of a run's assignments in every group in one query (and the callbacks in one more); `MIN_DAILY_REST_HOURS = 11` (replaces the literal `11`), `MIN_WEEKLY_REST_HOURS = 35` |
| Changed | `ShiftAutoAssignController` `random`/`rotating`: `isAdjacentAssigned()` (back-to-back, month and group only) replaced by the 11 h interval check. `polishLaw`: shift times from `BusinessDate::shiftStart()/shiftEnd()` instead of `SettlementPeriodCalculator::shiftTiming()` (UTC wall clock); callbacks from every group, read as Warsaw wall clock; the "35 h" day-count heuristic replaced by the real check |
| Changed | `DelegationController` store/update/addUser/updateUserDates keep only the date of `start_datetime`/`end_datetime`/`custom_start`/`custom_end` (as written, no timezone conversion) |
| Changed | Approved delegations (`delegation_user.status = 'approved'`, `custom_start/custom_end` or the delegation's dates) added to the blocked days of all modes |
| Changed | `random`, `rotating`, `polishLaw` wrap the assignment loop and `RestPolicyJanitor::clearUncovered()` in a transaction |

### Tests
- Pest `AutoFillRestIntervalsTest` (helpers `afr`, all three modes): later existing shift, previous-month night, other-group overlap and 8 h gap, 11 h vs 10 h 59 min before and after, same-run picks, the 2026-10-25 DST night, weekly cap with other-group and previous-month hours, 35 h exactly vs 34 h 59 min and across the month boundary/other group, approved vs pending/rejected delegations and custom dates, rollback on a failure mid-run. Also: the 35 h window spilling over from the previous month (monthly) and from a mid-month custom period end, a callback from another group, rollback when the rest-policy clean-up fails, delegation dates sent with an offset. `PolishLawOnCallTest`: the earlier shift now ends 12 h before the callback (16:00 would now be refused by the forward rest check).

---

## 2026-10-09 — 🕕 Shift times in Warsaw time: deadlines, current shift, overnight shifts

- **Shift times were read as UTC.** A shift's date and start/end times are Warsaw wall-clock, but the server compared them with the UTC clock, so everything was off by one hour in winter and two in summer: a 06:00 shift with a 30-minute handover deadline showed *140 min left* at 06:10 instead of 20, a handover filed up to two hours late was recorded as on time, at 07:00 the dashboard still showed the night shift as current, and between local midnight and 01:00/02:00 "today" was still yesterday (today's checklists missing from the dashboard, day-off requests still accepted for yesterday's shifts, the settlement page's current period a day behind on its first day). Shift starts, ends and deadlines are now built in the business timezone (`BUSINESS_TIMEZONE`, default `Europe/Warsaw`), which also keeps them right across the DST changes (deadline minutes count elapsed time, so a long deadline over the 02:00–03:00 change lands on the right hour). Storage and the app clock stay UTC.
- **Overnight shifts turned "overdue" at midnight.** A 22:00–06:00 shift's checklist jumped from the dashboard's current list to *Overdue* at midnight. It now stays current until the shift ends at 06:00 the next day (a shift whose end time is at or before its start time ends the next day), then becomes overdue if it was required.
- **Open delegations disappeared on their last day.** A delegation ending on the 15th dropped out of the open list at 01:00/02:00 Warsaw on the 15th; it now stays open through its last day (Warsaw date).

> **Deploy note:** deadlines and on-time flags computed before this release used UTC. Handovers already filed keep the `submitted_on_time` they were stored with (nothing is recomputed), so some filed up to an hour (winter) or two (summer) late may still show as on time.

### Backend
| What | Details |
|------|---------|
| Added | `BusinessDate::now()`, `shiftStart(Shift)`, `shiftEnd(Shift)` (business timezone; overnight shifts end the next day; no start time → 00:00, no end time → end of the date; a time skipped by spring-forward rolls to the next hour, a repeated fall-back time is taken in CET) |
| Changed | `ShiftChecklist::getDeadlineDatetime()` takes the `Shift` and returns its deadline in the business timezone. `deadline_at` in `GET /api/my-checklist-alerts` and `GET /api/my-shifts` is ISO-8601 with the Warsaw offset (e.g. `2026-07-15T06:30:00+02:00`; it used to be the wrong instant with `+00:00`) |
| Fixed | `GET /api/my-checklist-alerts` (minutes left, today, overnight shifts still on), `POST /api/checklist-submissions` (`submitted_on_time`), `GET /api/current-shift` (by instants; a shift whose end equals its start also counts as overnight), `POST /api/shift-change-requests` (past-shift check), `GET /api/delegations/open` (open through the last day) and the settlement calculator's current period use the business timezone |

### Tests
- Pest `BusinessTimezoneTest`: minutes left and late/on-time handovers at Warsaw hours, `/my-shifts` offset, current shift at 07:00 and at local midnight, "today" between local and UTC midnight, past-shift day-off request, overnight shift current until 06:00 then overdue, DST spring-forward and fall-back days (2029-03-25, 2029-10-28) incl. deadlines whose window crosses the change and skipped/repeated local times, settlement period by the Warsaw date, open delegation on its last day.

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
