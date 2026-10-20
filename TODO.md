# TODO

Open items after the 2026-10-09 audit (#33–#42) and its follow-ups (#43–#45). None of them is a known bug that loses data or breaks a screen.

## Deploy / operations (owner)
- [ ] Rotate `APP_KEY` and the database password; change the `1234` passwords on deployments.
- [ ] Rebuild the backend and frontend images when deploying #43–#45. #44 adds a migration (`acknowledged_by_id`, `acknowledged_at` on shift change requests).
- [ ] If a TLS proxy sits in front of the app, allow 50 MB bodies on `POST /api/procedures`, `PUT /api/procedures/{id}` and `PUT /api/procedures/{id}/draft`. Everything else is capped at 2 MB.
- [ ] Keep the `postgres:15-alpine` tag in `backend/Dockerfile`, which supplies the pg_dump/pg_restore tools for `BACKUP_BEFORE_MIGRATE`, in step with the db image when that is upgraded.
- [ ] Optionally remove the unused Docker volume `workflow-manager_backend_logs`.

## Schedule screen
- [ ] **Page-load race.** `onMounted` sets the month before shift groups have loaded, so the first `/api/schedule` request can go out without a group. If it answers first, it shows briefly and triggers three extra overlay requests. The correct group's data always ends up on screen. This was already on main before the split (#45).
- [ ] Dialog and side-panel components edit their form objects through props (`timeForm`, `genConfig`, …). Move them to `defineModel` or emits.
- [ ] `useGenerateMonth` also holds Delete Schedule, and `useEmployeeSelection` holds the shift-colour cell style. Rename or split them.
- [ ] Router ↔ ScheduleView import cycle, now also through `useShiftGroups` and `ScheduleGroupTabs`. Move `currentUser` into its own module.
- [x] Add unit tests for `useAssignmentFlow` (the confirm chain) and an e2e test for "Request day off" on the Schedule. Done 2026-10-20, with the other six schedule composables.

## Frontend tests
- [ ] **Component-level tests for the Vue components and views.** Every `.js` module has unit tests (99.7 % of lines, 2026-10-20); the `.vue` files are covered only by the e2e suite. Add Vue Test Utils with the jsdom environment already set up for Vitest (`// @vitest-environment jsdom`), starting with the Schedule dialogs (`src/components/schedule/`) and `BaseModal`, and decide whether `BaseModal.browser.test.js` (the one remaining Playwright unit test) can then move too.

## Backend
- [ ] The checklist archive CSV export follows the list's sort order, so its keyset can't use the primary-key index. Add a row-value comparison or an index for the default sort if archives get large.

## Decided, no action
- Settlement periods for groups without an anchor start on the creation date in UTC. Kept as is on purpose (2026-10-12): changing it would move existing periods.
