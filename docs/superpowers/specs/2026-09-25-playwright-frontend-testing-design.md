# Playwright Frontend Testing Migration — Design

Status: draft, awaiting user review

## Context

The frontend currently tests via Vitest + `@testing-library/vue` + MSW: components are rendered in `happy-dom` (not a real browser) with API calls intercepted by mock handlers. Coverage is thin (5 test files, 17 tests total) and skewed away from the app's core domain — the three shipped scheduling sub-projects (settlement periods, shift streaks, on-call duty; see `[[project-shiftchanger-subproject-decomposition]]`) are covered on the backend (Pest, ~75 passing Feature tests) but barely at all on the frontend. `ScheduleView.vue` (2,853 lines — streak-rules config, settlement-config modal, shift assignment, auto-fill) and `OnCallView.vue` (310 lines) have zero frontend tests. There is no e2e/browser-level testing anywhere in the repo.

The user asked to replace Vitest with Playwright entirely, rewrite the existing scenarios, and build out comprehensive new coverage for every shift/schedule-related view and component, doing "the same for unit tests."

Decisions made during brainstorming:
- Playwright will drive a **real browser against the real Dockerized backend** (not mocked network) — most realistic, catches integration bugs like the on-call rest-clock bug caught only by whole-branch review (`[[project-shiftchanger-subproject-decomposition]]`).
- The handful of pure-logic composable tests become **Playwright Test running in Node mode** (no browser) — same tool/config as e2e, just a separate project, rather than reintroducing a second framework.
- Test data for e2e is seeded via **new Laravel factories** (backend currently only has `UserFactory`; other models are hand-built with `::create()` in Pest tests) reset once per run via `migrate:fresh` + a dedicated `E2eBaselineSeeder`, with individual specs creating their specific fixtures through the **real admin API**, not the factories directly.

## Goals

- Remove Vitest and all its supporting libraries/config from the frontend entirely.
- A single Playwright config with two projects: `unit` (Node-context, pure composable logic) and `e2e` (real Chromium, real backend).
- Every existing test scenario ported with equivalent assertions.
- New e2e coverage for every shift/schedule-related route: `ScheduleView`, `OnCallView`, `ShiftSettingsView`, `SettlementPeriodsView` (extended), `MyShiftsView`, and `ScheduleView`'s embedded sub-components (`AutoFillPanel`, `PlayerSelectionPanel`, `ShiftCountInputs`) via the flows that render them.
- CI (`frontend-tests.yml`) runs the full suite against a real backend, headless, on every push/PR touching `frontend/**`.

## Non-goals

- No refactor of `ScheduleView.vue` itself. It's a large file, but Playwright drives the rendered DOM regardless of how the Vue source is organized internally — splitting it is a separate concern from testability and is out of scope here (YAGNI relative to what was actually asked).
- No Playwright Component Testing (`@playwright/experimental-ct-vue`) tier. The chosen strategy is full e2e; introducing a third testing mode for isolated component mounts would add tooling surface not needed for the coverage goal.
- No isolated tests for `AutoFillPanel`/`PlayerSelectionPanel`/`ShiftCountInputs` — confirmed via grep they only ever render inside `ScheduleView`, so they're exercised through `ScheduleView` flows, not standalone.
- No backend logic changes. New factories are additive test infrastructure; no production `app/` code changes.
- No CI change to `backend-tests.yml` — it stays as-is; only `frontend-tests.yml` gains a live backend dependency.

## Tooling & config

Remove: `vitest`, `@testing-library/vue`, `@testing-library/jest-dom`, `@vue/test-utils`, `happy-dom`, `msw` from `frontend/package.json` devDependencies; the `test: {...}` block from `frontend/vite.config.js`; `frontend/src/mocks/` (MSW handlers/server/setup) entirely.

Add: `@playwright/test`.

Playwright's `globalSetup`/`webServer` options are config-level, not per-project, so `--project=unit` alone would still trigger the e2e project's Docker/dev-server startup if both projects lived in one config file. To keep unit runs dependency-free, use **two separate config files** instead of one config with two projects:

- **`frontend/playwright.unit.config.js`** — no `webServer`, no `globalSetup`, no browser (`testDir: 'src'`, `testMatch: '**/*.test.js'`). Runs co-located with source, matching today's convention (e.g. `useConfirm.test.js` stays next to `useConfirm.js`).
- **`frontend/playwright.config.js`** (the e2e config) — `use: { baseURL: 'http://localhost:5173' }`, `projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }]`, `testDir: 'e2e'`. `webServer` starts `npm run dev:hybrid` (reusing the existing Vite dev-server proxy to the Dockerized backend at `localhost:8090` already defined in `vite.config.hybrid.js`), waiting on the baseURL before running tests. `globalSetup` runs `docker exec shift_changer_backend php artisan migrate:fresh` then `docker exec shift_changer_backend php artisan db:seed --class="Database\Seeders\E2eBaselineSeeder"` once before the suite starts. **Correction from an earlier draft of this spec:** the default `DatabaseSeeder` only creates one admin (`admin@shiftchanger.com` / `1234`) and does not call `UsersSeeder`/`ScheduleMaintainerSeeder` — plain `--seed` would leave no non-admin user for employee-facing flows. A new test-only `E2eBaselineSeeder` (backend, not part of the default `DatabaseSeeder` chain, so `php artisan db:seed` with no `--class` — e.g. in production — is unaffected) explicitly calls `DatabaseSeeder`, `UsersSeeder` (adds `regular@shiftchanger.com` / `password123`, a plain non-admin employee — used for `MyShiftsView` tests), and `ScheduleMaintainerSeeder` (adds `schedule@company.com` / `schedule123`). Assumes the Docker backend is already running — same precondition as manual dev testing today; fails fast with a clear error if `docker exec` fails.

`frontend/package.json` scripts: `"test": "playwright test --config=playwright.unit.config.js"`, `"test:e2e": "playwright test"`, `"test:all": "npm run test && npm run test:e2e"`.

## Test data strategy

**Correction (implementation):** the model factories listed below were dropped; all domain data is seeded through the real admin API (`e2e/fixtures/api-setup.js`) or the UI, as the implementation plan's Task 1 notes explain. Only `E2eBaselineSeeder` was added.

New Laravel factories in `backend/database/factories/` (additive, backend Pest tests may adopt them too but aren't required to):
- `ShiftGroupFactory`, `ShiftFactory`, `ShiftAssignmentFactory`
- `ShiftStreakRuleFactory`
- `OnCallAssignmentFactory`, `OnCallCallbackFactory`

Plus one new seeder, `backend/database/seeders/E2eBaselineSeeder.php`, calling `DatabaseSeeder`, `UsersSeeder`, and `ScheduleMaintainerSeeder` in sequence (none of these are wired together today — see the correction above).

These back the seeded baseline (`E2eBaselineSeeder`) — enough for login and basic navigation to work out of the box. They are **not** called directly from Playwright (Playwright is JS, factories are PHP); they exist so the seeders/baseline can use them instead of hand-rolled `::create()` calls.

Per-spec fixtures go through the real API, via `e2e/fixtures/api-setup.js` — helper functions using an authenticated Playwright `request` context:
```js
// e2e/fixtures/api-setup.js
export async function createShiftGroupWithStreakRule(request, overrides = {}) {
  const group = await request.post('/api/shift-groups', { data: { name: 'Night Crew', ...overrides.group } });
  const groupId = (await group.json()).id;
  await request.post(`/api/shift-groups/${groupId}/streak-rules`, {
    data: { shift_type: 'night', streak_length: 3, rest_days: 2, ...overrides.rule },
  });
  return groupId;
}
```
This reuses already-tested backend logic instead of duplicating validation/business-rule assumptions in test setup.

`e2e/fixtures/auth.js` provides a `loggedInPage` fixture: logs in once per worker via the real `/api/login` (Sanctum session cookie + CSRF), saves Playwright `storageState`, reused across specs for speed. `e2e/auth/login.spec.js` itself does **not** use this fixture — it exercises the real login form fresh, unauthenticated, each time.

## Existing scenario migration (1:1 mapping)

| Current (Vitest) | New (Playwright) | Notes |
|---|---|---|
| `src/views/LoginView.test.js` | `e2e/auth/login.spec.js` | Real page + real backend instead of MSW-mocked render |
| `src/views/SettlementPeriodsView.test.js` (3 tests: load+render report, prev-period nav, streak-status column) | `e2e/settlement-periods/report.spec.js` | Same 3 assertions, real seeded shift group/streak rule/assignments instead of mocked JSON responses |
| `src/composables/useConfirm.test.js` | same path, ported to `@playwright/test` `test`/`expect` | Pure reactive state, no DOM — Node mode works unchanged |
| `src/composables/useToast.test.js` | same path, ported to `@playwright/test` `test`/`expect` | Uses `vi.useFakeTimers()` today — Playwright Test has no built-in fake timers, so this is rewritten using real (short) `setTimeout` waits via `page`-less `await new Promise(r => setTimeout(r, ...))`, or Node's built-in `timers/promises` — timing assertions loosened to real elapsed time rather than mocked ticks |
| `src/composables/useFocusTrap.test.js` | **dropped as isolated test** | Needs a real DOM (mounts a host component, dispatches keyboard events, reads `document.activeElement`) — doesn't fit Node mode. Behavior (focus-in, Tab-wrap, Shift+Tab-wrap, Escape-closes, focus-restore) is instead asserted as explicit steps within a real e2e flow that opens an actual modal using it (e.g. the delete-confirmation flow in `e2e/shift-settings/`) |

## New scenario coverage

- **`e2e/schedule/`** (`ScheduleView.vue`, `/schedule/:month?`):
  - `navigation.spec.js` — month forward/back, empty-month state
  - `manual-assignment.spec.js` — assign/unassign a shift via `PlayerSelectionPanel`
  - `auto-fill.spec.js` — `AutoFillPanel`'s modes, respecting `ShiftCountInputs` caps
  - `streak-rules.spec.js` — configure a streak rule in the settings modal, verify the UI blocks/shows rest windows once the streak length is reached
  - `settlement-config.spec.js` — set settlement mode/anchor/cap from the group settings modal
- **`e2e/on-call/`** (`OnCallView.vue`, `/on-call`): create/edit/delete an on-call assignment; log a callback and verify it renders under its assignment
- **`e2e/shift-settings/`** (`ShiftSettingsView.vue`, `/shift-settings`): shift-group and shift-type CRUD; also hosts the focus-trap assertions described above
- **`e2e/my-shifts/`** (`MyShiftsView.vue`, `/my-shifts`): employee-facing own-shifts view
- **`e2e/settlement-periods/`**: extend beyond the ported 3 tests with an over-cap-week highlighting case and an empty-period case

## CI

`.github/workflows/frontend-tests.yml` currently only runs `npm run test` (frontend-only, no live backend). It needs to:
1. `docker compose up -d db backend` (reusing `docker-compose.yml`'s existing service definitions and healthchecks as-is, rather than hand-installing PHP/Postgres in the Actions runner).
2. Wait for the backend healthcheck, then run migrations/seed inside the container (the same `docker exec ... php artisan migrate:fresh` + `db:seed --class="Database\Seeders\E2eBaselineSeeder"` the e2e `globalSetup` uses locally — `globalSetup` covers this in CI too, so no separate step is needed beyond ensuring the containers are up first).
3. `npx playwright install --with-deps chromium`.
4. `npm run test` (unit project, no Docker dependency) and `npm run test:e2e` (against the now-running containers).

This is the largest infrastructure change outside `frontend/` — the frontend and backend CI workflows currently know nothing about each other, and this makes the frontend job depend on a live backend for the first time.

## Error handling / flakiness

- `globalSetup`'s `docker exec` failure surfaces as a clear config-level error, not a cascade of unrelated test failures.
- Per-spec API fixture setup (`api-setup.js`) lets failures surface at the fixture call site (e.g. "failed to create shift group: 422 ...") rather than as an opaque later UI assertion failure.
- Real-timer-based `useToast` tests use generous margins (e.g. assert "still visible" well before expiry, "dismissed" well after) to avoid CI timing flakiness now that fake timers are gone.

## Testing (of this testing change)

Verification is running the full new suite (`npm run test`, `npm run test:e2e`) locally against the Docker backend and confirming the CI job passes end-to-end before merge — there's no separate "test the tests" layer beyond that.

## Open questions for implementation

None outstanding — all resolved during brainstorming.
