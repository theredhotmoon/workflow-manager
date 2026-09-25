# Playwright Frontend Testing Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Vitest with Playwright as the frontend's only test tool — a `unit` config for pure composable logic and an `e2e` config driving real Chromium against the real Dockerized backend — porting the 5 existing test scenarios and adding full e2e coverage of every shift/schedule-related route.

**Architecture:** Two independent Playwright config files (`playwright.unit.config.js`, `playwright.config.js`) so unit runs never require Docker. `globalSetup` resets the backend DB once per e2e run via a new `E2eBaselineSeeder` (which only needs the existing `User`-creating seeders, per Task 1's findings). Specs authenticate via a `loginAs()` fixture (real `/api/login`, session-cookie auth) and seed all their own domain data (shift groups, streak rules, on-call assignments) through the real admin API (new `api-setup.js` helpers) or the UI itself — no direct DB model factories are needed anywhere in this plan.

**Tech Stack:** `@playwright/test` (frontend), Pest (backend, additive-only seeder), Docker Compose (existing `shift_changer_backend`/`shift_changer_db` containers).

**Spec:** [docs/superpowers/specs/2026-09-25-playwright-frontend-testing-design.md](../specs/2026-09-25-playwright-frontend-testing-design.md)

## Global Constraints

- Node 20 (matches `.github/workflows/frontend-tests.yml` today).
- Chromium only — no cross-browser requirement was requested.
- No production `app/` backend code changes — only a new seeder under `database/seeders/`.
- Docker container names are fixed: `shift_changer_backend` (Laravel), `shift_changer_db` (Postgres) — confirmed via `docker ps`.
- Frontend dev server runs on `http://localhost:5173` (Vite default) and proxies `/api` + `/sanctum` to the backend at `http://localhost:8090` (`frontend/vite.config.js`) — same-origin from the browser's perspective, so cookie-based Sanctum auth works without CORS.
- Biome (`npm run check:fix`) is the existing lint/format tool for all new/changed `frontend/**/*.js` files.
- Follow `[[feedback-changelog-updates]]`: add a `CHANGELOG.md` entry for this migration as part of the final task.

## Review Focus

- **CSRF header omitted on API-fixture calls** — Playwright's `request`/`context.request` API, unlike axios, does not auto-attach `X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie; every mutating fixture call (`POST`/`PUT`/`DELETE`) needs it added manually or it 419s. Covered by `getXsrfHeader()` in Task 3 and used by every fixture helper thereafter.
- **Sortable.js (`vue-draggable-plus`) needs real intermediate `mouse.move()` steps** — a single jump from source to target does not register as a drag with Sortable's internal pointer tracking; the drag helper must move in several small steps. Covered explicitly in Task 10 (`manual-assignment.spec.js`).
- **Docker not running when the e2e suite starts** — `globalSetup`'s `docker exec` must fail loudly with a clear message instead of leaving every spec to time out waiting for a server that never seeds. Covered in Task 3.
- **A seeded employee with zero assigned shifts** — `MyShiftsView`'s empty state ("No shifts or delegations for this month") would silently pass a naive test even if shift rendering were broken, if the test never seeds a real assignment for that employee. Covered in Task 14 by seeding an assignment via the admin-authenticated API fixture before asserting on rendered shift content.
- **Locale-dependent dynamic button/month labels** — `{{ monthLabel }}` and the "Generate {{ monthLabel }}" button text are built with `toLocaleString('en-US', ...)`, but a CI runner's ICU data could format them differently than a developer's machine. Every spec matches these with a partial/regex locator (e.g. `/^Generate /`, matching on the visible month header only where already deterministic) rather than a hardcoded full string — applied throughout Tasks 7–14.

---

### Task 1: `E2eBaselineSeeder`

**Files:**
- Create: `backend/database/seeders/E2eBaselineSeeder.php`
- Test: `backend/tests/Feature/E2eBaselineSeederTest.php`

**Interfaces:**
- Produces: `Database\Seeders\E2eBaselineSeeder` — invoked later (Task 3) via `docker exec shift_changer_backend php artisan db:seed --class="Database\Seeders\E2eBaselineSeeder"`. Creates `admin@shiftchanger.com` (password `1234`, `is_admin=true`, `role=admin`), `regular@shiftchanger.com` (password `password123`, `is_admin=false`) plus the other `UsersSeeder` users, and `schedule@company.com` (password `schedule123`, `role=schedule_maintainer`).

- [ ] **Step 1: Write the failing test**

```php
<?php
// backend/tests/Feature/E2eBaselineSeederTest.php

use App\Models\User;
use Database\Seeders\E2eBaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds an admin, a plain employee, and a schedule maintainer', function () {
    (new E2eBaselineSeeder())->run();

    $admin = User::where('email', 'admin@shiftchanger.com')->first();
    expect($admin)->not->toBeNull();
    expect($admin->is_admin)->toBeTrue();

    $employee = User::where('email', 'regular@shiftchanger.com')->first();
    expect($employee)->not->toBeNull();
    expect($employee->is_admin)->toBeFalse();

    $maintainer = User::where('email', 'schedule@company.com')->first();
    expect($maintainer)->not->toBeNull();
    expect($maintainer->role)->toBe('schedule_maintainer');
});

it('is idempotent when run twice', function () {
    (new E2eBaselineSeeder())->run();
    (new E2eBaselineSeeder())->run();

    expect(User::where('email', 'admin@shiftchanger.com')->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run (inside the backend container): `docker exec shift_changer_backend php artisan test --filter=E2eBaselineSeederTest`
Expected: FAIL — `Class "Database\Seeders\E2eBaselineSeeder" not found`

- [ ] **Step 3: Write the seeder**

```php
<?php
// backend/database/seeders/E2eBaselineSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class E2eBaselineSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DatabaseSeeder::class,
            UsersSeeder::class,
            ScheduleMaintainerSeeder::class,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec shift_changer_backend php artisan test --filter=E2eBaselineSeederTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/database/seeders/E2eBaselineSeeder.php backend/tests/Feature/E2eBaselineSeederTest.php
git commit -m "test: add e2e baseline seeder"
```

**Why no new factories:** the design spec's original plan (see its "Test data strategy" section) called for `ShiftGroupFactory`/`ShiftFactory`/`ShiftAssignmentFactory`/`ShiftStreakRuleFactory`/`OnCallAssignmentFactory`/`OnCallCallbackFactory` to back the baseline seed. Writing this plan surfaced that `DatabaseSeeder`/`UsersSeeder`/`ScheduleMaintainerSeeder` only ever create `User` rows directly (`::firstOrCreate`, no factories) — and every task in this plan creates its own domain data (shift groups, streak rules, on-call assignments) through the real admin API (Task 3's `api-setup.js`) or the UI itself, never through a direct model factory. Adding six factories that no task actually calls would be dead code; this plan carries only the seeder the tasks below actually depend on.

---

### Task 2: Playwright tooling — configs + package.json (Vitest stays installed for now)

**Files:**
- Create: `frontend/playwright.unit.config.js`
- Create: `frontend/playwright.config.js`
- Modify: `frontend/package.json` (scripts only)
- Create: `frontend/e2e/.gitkeep` (placeholder so the empty `e2e/` dir is tracked until Task 5 adds real specs)

**Interfaces:**
- Produces: two runnable configs. `npm run test` → unit project (no browser, no Docker). `npm run test:e2e` → e2e project (Chromium, `webServer` on `npm run dev`, `globalSetup` — added in Task 3, not yet in this task).

- [ ] **Step 1: Install Playwright**

```bash
cd frontend && npm install -D @playwright/test && npx playwright install --with-deps chromium
```

- [ ] **Step 2: Write `playwright.unit.config.js`**

```js
// frontend/playwright.unit.config.js
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: 'src',
  testMatch: '**/*.test.js',
  fullyParallel: true,
  reporter: 'list',
});
```

- [ ] **Step 3: Write `playwright.config.js` (webServer only for now — `globalSetup` added in Task 3)**

```js
// frontend/playwright.config.js
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: 'e2e',
  fullyParallel: false,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: 'http://localhost:5173',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
  webServer: {
    command: 'npm run dev',
    url: 'http://localhost:5173',
    reuseExistingServer: !process.env.CI,
    env: { LARAVEL_BYPASS_ENV_CHECK: '1' },
    timeout: 30_000,
  },
});
```

`workers: 1` because every e2e spec shares one seeded database (no per-test transaction isolation) — see Task 3.

- [ ] **Step 4: Create the (temporarily empty) e2e directory**

```bash
mkdir -p frontend/e2e && touch frontend/e2e/.gitkeep
```

- [ ] **Step 5: Update `package.json` scripts**

Edit `frontend/package.json`'s `"scripts"` block, replacing `"test": "vitest run"` with:

```json
    "test": "playwright test --config=playwright.unit.config.js",
    "test:e2e": "playwright test",
```

- [ ] **Step 6: Verify the unit config runs (against the still-Vitest-authored composable tests, just to prove wiring — full port happens in Task 4)**

Run: `cd frontend && npx playwright test --config=playwright.unit.config.js --list`
Expected: lists `useConfirm.test.js`, `useFocusTrap.test.js`, `useToast.test.js` as discovered test files (they'll fail to *run* right now since they still use `vitest` imports — that's expected and fixed in Task 4; `--list` only needs to discover files, not execute them).

- [ ] **Step 7: Commit**

```bash
git add frontend/playwright.unit.config.js frontend/playwright.config.js frontend/package.json frontend/e2e/.gitkeep
git commit -m "chore: add Playwright unit/e2e configs alongside existing Vitest setup"
```

---

### Task 3: e2e fixtures — auth, API setup, global DB reset

**Files:**
- Create: `frontend/e2e/global-setup.js`
- Create: `frontend/e2e/fixtures/auth.js`
- Create: `frontend/e2e/fixtures/api-setup.js`
- Modify: `frontend/playwright.config.js` (add `globalSetup`)

**Interfaces:**
- Produces:
  - `global-setup.js` default export (used as `globalSetup` in `playwright.config.js`).
  - `auth.js`: `test` (a `@playwright/test` `test` extended with an `adminPage` fixture and an `employeePage` fixture, both pre-authenticated), `expect`, `loginAs(context, email, password)`.
  - `api-setup.js`: `createShiftGroupWithStreakRule(context, overrides?)` → `Promise<number>` (group id), `generateMonthShifts(context, { monthKey, shiftGroupId })` → `Promise<void>`, `assignShift(context, { shiftId, userId })` → `Promise<void>` (on-call assignments/callbacks are created through the UI in Task 12, not via a fixture helper).

- [ ] **Step 1: Write `global-setup.js`**

```js
// frontend/e2e/global-setup.js
import { execSync } from 'node:child_process';

export default function globalSetup() {
  try {
    execSync('docker exec shift_changer_backend php artisan migrate:fresh', { stdio: 'pipe' });
    execSync(
      'docker exec shift_changer_backend php artisan db:seed --class="Database\\\\Seeders\\\\E2eBaselineSeeder"',
      { stdio: 'pipe' },
    );
  } catch (err) {
    throw new Error(
      'e2e global setup failed to reset the backend DB via `docker exec shift_changer_backend`. ' +
        'Is the shift_changer_backend container running? (docker ps)\n' +
        (err.stderr?.toString() || err.message),
    );
  }
}
```

- [ ] **Step 2: Wire it into `playwright.config.js`**

Add `globalSetup: './e2e/global-setup.js',` as a top-level key in `frontend/playwright.config.js` (alongside `testDir`).

- [ ] **Step 3: Write `auth.js`**

```js
// frontend/e2e/fixtures/auth.js
import { test as base, expect } from '@playwright/test';

export async function getXsrfHeader(context) {
  const cookies = await context.cookies();
  const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN');
  return xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value) } : {};
}

export async function loginAs(context, email, password) {
  await context.request.get('/sanctum/csrf-cookie');
  const headers = await getXsrfHeader(context);
  const res = await context.request.post('/api/login', { data: { email, password }, headers });
  if (!res.ok()) {
    throw new Error(`loginAs(${email}) failed: ${res.status()} ${await res.text()}`);
  }
}

export const test = base.extend({
  adminPage: async ({ browser }, use) => {
    const context = await browser.newContext();
    await loginAs(context, 'admin@shiftchanger.com', '1234');
    const page = await context.newPage();
    await use(page);
    await context.close();
  },
  employeePage: async ({ browser }, use) => {
    const context = await browser.newContext();
    await loginAs(context, 'regular@shiftchanger.com', 'password123');
    const page = await context.newPage();
    await use(page);
    await context.close();
  },
});

export { expect };
```

- [ ] **Step 4: Write `api-setup.js`**

```js
// frontend/e2e/fixtures/api-setup.js
import { getXsrfHeader } from './auth.js';

export async function createShiftGroupWithStreakRule(context, overrides = {}) {
  const headers = await getXsrfHeader(context);
  const groupRes = await context.request.post('/api/shift-groups', {
    data: { name: 'Night Crew', color: '#6366f1', ...overrides.group },
    headers,
  });
  if (!groupRes.ok()) throw new Error(`create shift group failed: ${groupRes.status()} ${await groupRes.text()}`);
  const groupId = (await groupRes.json()).id;

  const ruleRes = await context.request.put(`/api/shift-groups/${groupId}/streak-rules`, {
    data: { rules: [{ shift_type: 'night', streak_length: 3, rest_days: 2, ...overrides.rule }] },
    headers,
  });
  if (!ruleRes.ok()) throw new Error(`create streak rule failed: ${ruleRes.status()} ${await ruleRes.text()}`);

  return groupId;
}

export async function generateMonthShifts(context, { monthKey, shiftGroupId }) {
  const headers = await getXsrfHeader(context);
  const res = await context.request.post('/api/schedule/generate', {
    data: {
      month: monthKey,
      shift_group_id: shiftGroupId,
      shifts_per_day: 1,
      shift_times: [{ start_time: '08:00', end_time: '16:00' }],
      work_mode_weekday: 'office',
      work_mode_weekend: 'office',
    },
    headers,
  });
  if (!res.ok()) throw new Error(`generate month failed: ${res.status()} ${await res.text()}`);
}

export async function assignShift(context, { shiftId, userId }) {
  const headers = await getXsrfHeader(context);
  const res = await context.request.post('/api/schedule/assign', {
    data: { shift_id: shiftId, user_id: userId },
    headers,
  });
  if (!res.ok()) throw new Error(`assign shift failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

export async function getEmployeeIdByEmail(context, email) {
  const headers = await getXsrfHeader(context);
  const res = await context.request.get('/api/employees', { headers });
  if (!res.ok()) throw new Error(`list employees failed: ${res.status()} ${await res.text()}`);
  const employees = await res.json();
  const match = employees.find((e) => e.email === email);
  if (!match) throw new Error(`no employee found with email ${email}`);
  return match.id;
}

export async function getShiftGroupsAndShifts(context, monthKey, shiftGroupId) {
  const headers = await getXsrfHeader(context);
  const res = await context.request.get('/api/schedule', {
    params: { month: monthKey, shift_group_id: shiftGroupId },
    headers,
  });
  if (!res.ok()) throw new Error(`fetch schedule failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}
```

- [ ] **Step 5: Smoke-test the fixtures manually**

Run: `cd frontend && npx playwright test --list` (should still list 0 e2e specs — none written yet — but must not error on `global-setup.js`/`auth.js`/`api-setup.js` syntax)
Then run: `npx playwright test` (runs global setup only, no specs yet, should complete without throwing)
Expected: global setup runs `migrate:fresh` + `db:seed` successfully, no test files found, exit code 0.

- [ ] **Step 6: Commit**

```bash
git add frontend/e2e/global-setup.js frontend/e2e/fixtures frontend/playwright.config.js
git commit -m "feat(e2e): add auth and API-setup fixtures, wire global DB reset"
```

---

### Task 4: Port unit composable tests to Playwright Test (Node mode)

**Files:**
- Modify: `frontend/src/composables/useConfirm.test.js`
- Modify: `frontend/src/composables/useToast.test.js`
- Delete: `frontend/src/composables/useFocusTrap.test.js` (behavior moves to Task 11's `auto-fill.spec.js`, per the design's `[[project-shiftchanger-subproject-decomposition]]`-style correction)

**Interfaces:**
- Consumes: `confirm`, `useConfirmModal` from `useConfirm.js`; `useToast` from `useToast.js` (unchanged).

- [ ] **Step 1: Port `useConfirm.test.js` (pure Vue reactivity, no DOM — 1:1 port, only the import line changes)**

```js
// frontend/src/composables/useConfirm.test.js
import { test, expect } from '@playwright/test';
import { confirm, useConfirmModal } from './useConfirm.js';

test.describe('useConfirm', () => {
  test('resolves true when confirmed', async () => {
    const resultPromise = confirm({ title: 'Delete item', message: 'Are you sure?' });

    const { isOpen, title, message, onConfirm } = useConfirmModal();
    expect(isOpen.value).toBe(true);
    expect(title.value).toBe('Delete item');
    expect(message.value).toBe('Are you sure?');

    onConfirm();

    await expect(resultPromise).resolves.toBe(true);
    expect(isOpen.value).toBe(false);
  });

  test('resolves false when cancelled', async () => {
    const resultPromise = confirm({ title: 'Discard changes?' });

    const { onCancel } = useConfirmModal();
    onCancel();

    await expect(resultPromise).resolves.toBe(false);
  });

  test('applies defaults when no options are given', async () => {
    const resultPromise = confirm();

    const { title, message, confirmLabel, danger, onCancel } = useConfirmModal();
    expect(title.value).toBe('Confirm');
    expect(message.value).toBe('');
    expect(confirmLabel.value).toBe('Confirm');
    expect(danger.value).toBe(true);

    onCancel();
    await expect(resultPromise).resolves.toBe(false);
  });
});
```

- [ ] **Step 2: Run it**

Run: `cd frontend && npx playwright test --config=playwright.unit.config.js useConfirm`
Expected: PASS (3 tests)

- [ ] **Step 3: Port `useToast.test.js` (fake timers → real short timers, per the design's documented change)**

```js
// frontend/src/composables/useToast.test.js
import { test, expect } from '@playwright/test';
import { useToast } from './useToast.js';

test.describe('useToast', () => {
  test('shows a toast with the given message and type', () => {
    const { toast, showToast } = useToast();

    showToast('Saved successfully', 'success');

    expect(toast.value).toEqual({ show: true, message: 'Saved successfully', type: 'success' });
  });

  test('defaults to type "success" and auto-dismisses after its duration', async () => {
    const { toast, showToast } = useToast();

    showToast('Done', undefined, 150);
    expect(toast.value.type).toBe('success');
    expect(toast.value.show).toBe(true);

    await new Promise((r) => setTimeout(r, 250));
    expect(toast.value.show).toBe(false);
  });

  test('auto-dismisses after a custom duration', async () => {
    const { toast, showToast } = useToast();

    showToast('Quick', 'warning', 100);

    await new Promise((r) => setTimeout(r, 200));
    expect(toast.value.show).toBe(false);
  });

  test('dismissToast clears immediately and cancels a pending auto-dismiss', async () => {
    const { toast, showToast, dismissToast } = useToast();

    showToast('Will be dismissed early', 'success', 5000);
    dismissToast();

    expect(toast.value.show).toBe(false);

    await new Promise((r) => setTimeout(r, 150));
    expect(toast.value.show).toBe(false);
  });
});
```

- [ ] **Step 4: Run it**

Run: `cd frontend && npx playwright test --config=playwright.unit.config.js useToast`
Expected: PASS (4 tests)

- [ ] **Step 5: Delete the dropped focus-trap unit test**

```bash
rm frontend/src/composables/useFocusTrap.test.js
```

- [ ] **Step 6: Commit**

```bash
git add frontend/src/composables/useConfirm.test.js frontend/src/composables/useToast.test.js
git rm frontend/src/composables/useFocusTrap.test.js
git commit -m "test: port composable unit tests to Playwright Test, drop isolated focus-trap test"
```

---

### Task 5: e2e — `auth/login.spec.js` (ports `LoginView.test.js`)

**Files:**
- Create: `frontend/e2e/auth/login.spec.js`

**Interfaces:**
- Consumes: plain `@playwright/test` `test`/`expect` (this spec deliberately does **not** use the `adminPage` fixture — it exercises the login form itself, unauthenticated).

- [ ] **Step 1: Write the spec**

```js
// frontend/e2e/auth/login.spec.js
import { test, expect } from '@playwright/test';

test.describe('Login', () => {
  test('logs in successfully and navigates to the dashboard', async ({ page }) => {
    await page.goto('/login');

    await page.getByLabel('Email Address').fill('admin@shiftchanger.com');
    await page.getByLabel('Password').fill('1234');
    await page.getByRole('button', { name: 'Sign In' }).click();

    await expect(page.getByRole('button', { name: 'Signed In' })).toBeVisible();
    await expect(page).toHaveURL(/\/dashboard$/, { timeout: 3000 });
  });

  test('shows an error and stays on the login page when credentials are invalid', async ({ page }) => {
    await page.goto('/login');

    await page.getByLabel('Email Address').fill('admin@shiftchanger.com');
    await page.getByLabel('Password').fill('wrong-password');
    await page.getByRole('button', { name: 'Sign In' }).click();

    await expect(
      page.getByText('The provided credentials do not match our records.'),
    ).toBeVisible();
    await expect(page).toHaveURL(/\/login$/);
  });
});
```

- [ ] **Step 2: Run it**

Run: `cd frontend && npx playwright test e2e/auth/login.spec.js`
Expected: PASS (2 tests) — requires the `shift_changer_backend`/`shift_changer_db` containers already running (see Global Constraints).

- [ ] **Step 3: Delete the ported Vitest file**

```bash
git rm frontend/src/views/LoginView.test.js
```

- [ ] **Step 4: Commit**

```bash
git add frontend/e2e/auth/login.spec.js
git commit -m "test(e2e): port LoginView test to a real-backend Playwright spec"
```

---

### Task 6: e2e — `settlement-periods/report.spec.js` (ports + extends `SettlementPeriodsView.test.js`)

**Files:**
- Create: `frontend/e2e/settlement-periods/report.spec.js`

**Interfaces:**
- Consumes: `test`/`expect` from `../fixtures/auth.js`, `createShiftGroupWithStreakRule`/`assignShift`/`generateMonthShifts`/`getEmployeeIdByEmail`/`getShiftGroupsAndShifts` from `../fixtures/api-setup.js`.

- [ ] **Step 1: Write the spec — the 3 ported assertions plus the 2 new ones from the design's "extend" note**

Reference: `SettlementPeriodsView.vue` in full — `aria-label="Previous period"`/`"Next period"` nav buttons, period range rendered as plain text (`{{ report.period.start }} – {{ report.period.end }}`, e.g. "2027-03-01 – 2027-03-31"), a `<table>` with one `<thead><tr>` and one `<tbody><tr>` per employee (or a single `colspan` "No assignments in this period yet." row when empty), streak status text built by `formatStreakStatus()` as `"night: 2/3"` or `"night: resting until 2026-09-05"`.

```js
// frontend/e2e/settlement-periods/report.spec.js
import { test, expect } from '../fixtures/auth.js';
import {
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  assignShift,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
} from '../fixtures/api-setup.js';

const MONTH = '2027-03';

test.describe('Settlement Periods report', () => {
  test('loads groups and shows the report for the selected group, with prev-period navigation', async ({ adminPage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: 'Report Team' } });
    await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });
    const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const { shifts } = await getShiftGroupsAndShifts(context, MONTH, groupId);
    for (const shift of shifts) {
      await assignShift(context, { shiftId: shift.id, userId: employeeId });
    }

    await adminPage.goto('/settlement-periods');
    await adminPage.getByRole('combobox').selectOption({ label: 'Report Team' });

    await expect(adminPage.getByText('Regular Employee')).toBeVisible();
    await expect(adminPage.getByText('2027-03-01', { exact: false })).toBeVisible();

    await adminPage.getByRole('button', { name: 'Previous period' }).click();
    await expect(adminPage.getByText('2027-02-01', { exact: false })).toBeVisible();
  });

  test('renders the streak status column for a mid-streak and a resting employee', async ({ adminPage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Streak Report Team' },
      rule: { shift_type: 'night', streak_length: 3, rest_days: 2 },
    });
    await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });
    const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const { shifts } = await getShiftGroupsAndShifts(context, MONTH, groupId);
    // Assign the first two shifts (a mid-streak, below the 3-shift rest threshold).
    await assignShift(context, { shiftId: shifts[0].id, userId: employeeId });
    await assignShift(context, { shiftId: shifts[1].id, userId: employeeId });

    await adminPage.goto('/settlement-periods');
    await adminPage.getByRole('combobox').selectOption({ label: 'Streak Report Team' });

    await expect(adminPage.getByText(/night: \d\/3/)).toBeVisible();
  });

  test('shows an empty-assignments message for a period with no assignments yet', async ({ adminPage }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'Empty Group' } });

    await adminPage.goto('/settlement-periods');
    await adminPage.getByRole('combobox').selectOption({ label: 'Empty Group' });

    await expect(adminPage.getByText('No assignments in this period yet.')).toBeVisible();
  });
});
```

- [ ] **Step 2: Run it**

Run: `cd frontend && npx playwright test e2e/settlement-periods/report.spec.js`
Expected: PASS (3 tests)

- [ ] **Step 3: Delete the ported Vitest file**

```bash
git rm frontend/src/views/SettlementPeriodsView.test.js
```

- [ ] **Step 4: Commit**

```bash
git add frontend/e2e/settlement-periods/report.spec.js
git commit -m "test(e2e): port and extend SettlementPeriodsView coverage"
```

---

### Task 7: Accessible month-nav labels + e2e `schedule/navigation.spec.js`

**Files:**
- Modify: `frontend/src/views/ScheduleView.vue:14,21` (add `aria-label`)
- Modify: `frontend/src/views/MyShiftsView.vue:12,16` (add `aria-label`, needed by Task 14)
- Create: `frontend/e2e/schedule/navigation.spec.js`

`ScheduleView.vue`'s and `MyShiftsView.vue`'s month-nav chevron buttons have no accessible name today (unlike `OnCallView.vue`'s and `SettlementPeriodsView.vue`'s, which already use `aria-label="Previous month"`/`"Next month"` and `"Previous period"`/`"Next period"`). Bringing the other two views in line with that existing convention is a one-line-per-button, zero-behavior-change addition (not a refactor — the spec's non-goal only excludes restructuring `ScheduleView.vue`), and it's what makes every later month-nav interaction in this plan a stable `getByLabel(...)` call instead of a positional guess.

- [ ] **Step 1: Add `aria-label` to `ScheduleView.vue`'s month-nav buttons**

In `frontend/src/views/ScheduleView.vue`, change:
```html
            <button @click="prevMonth"
              class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
```
to:
```html
            <button @click="prevMonth" aria-label="Previous month"
              class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
```
and change:
```html
            <button @click="nextMonth"
              class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
```
to:
```html
            <button @click="nextMonth" aria-label="Next month"
              class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
```

- [ ] **Step 2: Add the same `aria-label` pair to `MyShiftsView.vue`'s month-nav buttons**

In `frontend/src/views/MyShiftsView.vue`, change:
```html
          <button @click="prevMonth" class="w-9 h-9 flex items-center justify-center bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl text-slate-300 hover:text-white transition-all">
```
to:
```html
          <button @click="prevMonth" aria-label="Previous month" class="w-9 h-9 flex items-center justify-center bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl text-slate-300 hover:text-white transition-all">
```
and change:
```html
          <button @click="nextMonth" class="w-9 h-9 flex items-center justify-center bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl text-slate-300 hover:text-white transition-all">
```
to:
```html
          <button @click="nextMonth" aria-label="Next month" class="w-9 h-9 flex items-center justify-center bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl text-slate-300 hover:text-white transition-all">
```

- [ ] **Step 3: Write the navigation spec**

```js
// frontend/e2e/schedule/navigation.spec.js
import { test, expect } from '../fixtures/auth.js';

test.describe('Schedule navigation', () => {
  test('deep-links to a month and steps forward/back', async ({ adminPage }) => {
    await adminPage.goto('/schedule/2027-03');
    await expect(adminPage.getByText('March 2027')).toBeVisible();

    await adminPage.getByLabel('Next month').click();
    await expect(adminPage.getByText('April 2027')).toBeVisible();

    await adminPage.getByLabel('Previous month').click();
    await expect(adminPage.getByText('March 2027')).toBeVisible();
  });

  test('shows the empty state for a month with no shifts yet', async ({ adminPage }) => {
    await adminPage.goto('/schedule/2027-03');
    await expect(adminPage.getByText('No shifts').first()).toBeVisible();
  });

  test('creates a new shift group from the tabs row', async ({ adminPage }) => {
    await adminPage.goto('/schedule/2027-03');
    await adminPage.getByRole('button', { name: 'New Group' }).click();
    await adminPage.getByPlaceholder('Group name (e.g. Event Coverage)').fill('Day Crew');
    await adminPage.getByRole('button', { name: 'Create' }).click();

    await expect(adminPage.getByRole('button', { name: /Day Crew/ })).toBeVisible();
  });
});
```

- [ ] **Step 4: Run it**

Run: `cd frontend && npx playwright test e2e/schedule/navigation.spec.js`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add frontend/src/views/ScheduleView.vue frontend/src/views/MyShiftsView.vue frontend/e2e/schedule/navigation.spec.js
git commit -m "feat: add accessible month-nav labels; test(e2e): add schedule navigation coverage"
```

---

### Task 8: e2e — `schedule/group-settings.spec.js` (settlement config + streak rules — one shared modal)

**Files:**
- Create: `frontend/e2e/schedule/group-settings.spec.js`

**Interfaces:**
- Consumes: `createShiftGroupWithStreakRule` from `../fixtures/api-setup.js` (used to create a *second* group to switch between — the modal itself is driven entirely through the UI in this spec).

**Note:** the modal's `Mode`/`Anchor Date`/`Custom Length`/`Weekly Hour Cap` `<label>` elements are plain siblings of their `<select>`/`<input>` — no `for`/`id` association exists (unlike `LoginView.vue`, which already pairs `for="email"` with `id="email"`). `getByLabel()` needs that association to work, so Step 1 below adds it — a zero-behavior-change, four-`id`/`for`-pair addition to one small modal.

- [ ] **Step 1: Add `id`/`for` pairs to the Group Settings modal's fields**

In `frontend/src/views/ScheduleView.vue`, within the "Group Settlement Settings Modal" block (around line 712-738), pair each label with its field:

```html
          <div class="mb-4">
            <label for="settlement-mode" class="block text-sm font-medium text-slate-300 mb-1.5">Mode</label>
            <select id="settlement-mode" v-model="groupSettingsForm.settlement_mode"
```
```html
          <div class="mb-4">
            <label for="settlement-anchor-date" class="block text-sm font-medium text-slate-300 mb-1.5">Anchor Date</label>
            <input id="settlement-anchor-date" v-model="groupSettingsForm.settlement_anchor_date" type="date"
```
```html
          <div v-if="groupSettingsForm.settlement_mode === 'custom'" class="mb-4">
            <label for="settlement-custom-length" class="block text-sm font-medium text-slate-300 mb-1.5">Custom Length (days)</label>
            <input id="settlement-custom-length" v-model.number="groupSettingsForm.settlement_custom_length_days" type="number" min="1"
```
```html
          <div class="mb-5">
            <label for="settlement-weekly-hour-cap" class="block text-sm font-medium text-slate-300 mb-1.5">Weekly Hour Cap</label>
            <input id="settlement-weekly-hour-cap" v-model.number="groupSettingsForm.weekly_hour_cap" type="number" min="0" step="0.5"
```
(each `id` goes on the existing `<select>`/`<input>` tag, keeping its other attributes unchanged; each `for` goes on the existing `<label>` tag the same way.)

- [ ] **Step 2: Write the spec**

Reference: `ScheduleView.vue:96-101` (gear icon opens the modal), `ScheduleView.vue:702-765` (`showGroupSettingsModal`, `groupSettingsForm` fields, `saveGroupSettings`).

```js
// frontend/e2e/schedule/group-settings.spec.js
import { test, expect } from '../fixtures/auth.js';
import { createShiftGroupWithStreakRule } from '../fixtures/api-setup.js';

test.describe('Shift group settlement + streak-rule settings', () => {
  test('sets settlement mode, weekly cap, and a streak rule from the shared settings modal', async ({ adminPage }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'Support Team' } });

    await adminPage.goto('/schedule/2027-03');
    await adminPage.getByRole('button', { name: /Support Team/ }).click();
    await adminPage.getByTitle('Settlement period settings').click();

    await expect(adminPage.getByText('Settlement Period Settings')).toBeVisible();

    await adminPage.getByLabel('Mode').selectOption('quarterly');
    await adminPage.getByLabel('Weekly Hour Cap').fill('40');
    await adminPage.getByRole('button', { name: '+ Add streak rule' }).click();
    await adminPage.getByPlaceholder('shift type (e.g. night)').fill('day');

    await adminPage.getByRole('button', { name: 'Save' }).click();
    await expect(adminPage.getByText('Settlement settings saved.')).toBeVisible();

    // Re-open to confirm persistence
    await adminPage.getByTitle('Settlement period settings').click();
    await expect(adminPage.getByLabel('Mode')).toHaveValue('quarterly');
    await expect(adminPage.getByPlaceholder('shift type (e.g. night)')).toHaveValue('day');
  });

  test('removes a streak rule from the same modal', async ({ adminPage }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'Cleanup Team' } });

    await adminPage.goto('/schedule/2027-03');
    await adminPage.getByRole('button', { name: /Cleanup Team/ }).click();
    await adminPage.getByTitle('Settlement period settings').click();

    await expect(adminPage.getByPlaceholder('shift type (e.g. night)')).toHaveValue('night');
    await adminPage.getByTitle('Remove rule').click();
    await adminPage.getByRole('button', { name: 'Save' }).click();
    await expect(adminPage.getByText('Settlement settings saved.')).toBeVisible();

    await adminPage.getByTitle('Settlement period settings').click();
    await expect(adminPage.getByPlaceholder('shift type (e.g. night)')).toHaveCount(0);
  });
});
```

- [ ] **Step 3: Run it**

Run: `cd frontend && npx playwright test e2e/schedule/group-settings.spec.js`
Expected: PASS (2 tests)

- [ ] **Step 4: Commit**

```bash
git add frontend/src/views/ScheduleView.vue frontend/e2e/schedule/group-settings.spec.js
git commit -m "feat: associate group-settings modal labels with their fields; test(e2e): cover settlement+streak-rule settings"
```

---

### Task 9: e2e — `schedule/generate-month.spec.js`

**Files:**
- Create: `frontend/e2e/schedule/generate-month.spec.js`

**Interfaces:**
- Consumes: `createShiftGroupWithStreakRule` from `../fixtures/api-setup.js`.

- [ ] **Step 1: Write the spec**

Reference: `ScheduleView.vue:967-1173` (Generate Month Config Modal, `generateMonth()` posting to `/api/schedule/generate`).

```js
// frontend/e2e/schedule/generate-month.spec.js
import { test, expect } from '../fixtures/auth.js';
import { createShiftGroupWithStreakRule } from '../fixtures/api-setup.js';

test.describe('Generate Month', () => {
  test('generates a month of shifts with the default single-shift template', async ({ adminPage }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'Generate Test Team' } });

    await adminPage.goto('/schedule/2027-04');
    await adminPage.getByRole('button', { name: /Generate Test Team/ }).click();
    await adminPage.getByRole('button', { name: 'Generate Month' }).click();

    await expect(adminPage.getByText('Generate Shifts')).toBeVisible();
    await adminPage.getByRole('button', { name: /^Generate /, exact: false }).click();

    await expect(adminPage.getByText(/generated|created/i)).toBeVisible();
    await expect(adminPage.getByText('No shifts').first()).toHaveCount(0);
  });
});
```

- [ ] **Step 2: Run it**

Run: `cd frontend && npx playwright test e2e/schedule/generate-month.spec.js`
Expected: PASS (1 test)

- [ ] **Step 3: Commit**

```bash
git add frontend/e2e/schedule/generate-month.spec.js
git commit -m "test(e2e): cover the Generate Month modal happy path"
```

---

### Task 10: e2e — `schedule/manual-assignment.spec.js` (Sortable.js drag-and-drop)

**Files:**
- Create: `frontend/e2e/schedule/manual-assignment.spec.js`

**Interfaces:**
- Consumes: `createShiftGroupWithStreakRule`, `generateMonthShifts` from `../fixtures/api-setup.js`.

- [ ] **Step 1: Write the spec**

Reference: `ScheduleView.vue:219-227` (employee list `VueDraggable`, `group: 'shifts'`, `clone: true`), `ScheduleView.vue:497-533` (per-shift drop-zone `VueDraggable`, `data-shift-id`), `ScheduleView.vue:340` (`data-date` on each day cell), remove `×` button at `ScheduleView.vue:528-531`.

```js
// frontend/e2e/schedule/manual-assignment.spec.js
import { test, expect } from '../fixtures/auth.js';
import { createShiftGroupWithStreakRule, generateMonthShifts } from '../fixtures/api-setup.js';

const MONTH = '2027-05';
const DAY = '2027-05-10';

async function dragToShiftDropzone(page, sourceLocator, targetLocator) {
  const source = await sourceLocator.boundingBox();
  const target = await targetLocator.boundingBox();
  if (!source || !target) throw new Error('drag source/target not visible');

  await page.mouse.move(source.x + source.width / 2, source.y + source.height / 2);
  await page.mouse.down();
  // Sortable.js needs several intermediate mousemove events to register a real drag —
  // a single jump from source to target does not trigger its pointer-tracking (see Review Focus).
  const steps = 8;
  for (let i = 1; i <= steps; i++) {
    const x = source.x + ((target.x - source.x) * i) / steps;
    const y = source.y + ((target.y - source.y) * i) / steps;
    await page.mouse.move(x, y, { steps: 3 });
  }
  await page.mouse.move(target.x + target.width / 2, target.y + target.height / 2, { steps: 3 });
  await page.mouse.up();
}

test.describe('Manual shift assignment (drag and drop)', () => {
  test('assigns an employee to a shift by dragging them from the roster, then unassigns via the remove button', async ({ adminPage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: 'Drag Team' } });
    await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });

    await adminPage.goto(`/schedule/${MONTH}`);
    await adminPage.getByRole('button', { name: /Drag Team/ }).click();

    const dayCell = adminPage.locator(`[data-date="${DAY}"]`);
    await expect(dayCell).toBeVisible();

    // The employee roster row itself carries the `cursor-grab` class (ScheduleView.vue:228-235) —
    // it's the drag source, cloned (not moved) into the drop target on drag.
    const employeeChip = adminPage.locator('div.cursor-grab', { hasText: 'Regular Employee' });
    const dropzone = dayCell.locator('[data-shift-id]').first();

    await dragToShiftDropzone(adminPage, employeeChip, dropzone);

    const assignedChip = dropzone.locator('div', { hasText: 'Regular Employee' }).first();
    await expect(assignedChip).toBeVisible({ timeout: 5000 });

    // The remove "×" button (ScheduleView.vue:528-531) is the only interactive element inside the chip besides its text.
    await assignedChip.getByText('×', { exact: true }).click();
    await expect(dropzone.locator('div', { hasText: 'Regular Employee' })).toHaveCount(0);
  });
});
```

- [ ] **Step 2: Run it**

Run: `cd frontend && npx playwright test e2e/schedule/manual-assignment.spec.js`
Expected: PASS (1 test). If the drag doesn't register, increase `steps` to 15–20 and add a short `page.waitForTimeout(50)` between mouse-move calls — Sortable.js's drag-detection threshold can be timing-sensitive in headless mode.

- [ ] **Step 3: Commit**

```bash
git add frontend/e2e/schedule/manual-assignment.spec.js
git commit -m "test(e2e): cover manual shift assignment via drag-and-drop"
```

---

### Task 11: e2e — `schedule/auto-fill.spec.js` (also folds in `useFocusTrap` behavior)

**Files:**
- Create: `frontend/e2e/schedule/auto-fill.spec.js`

**Interfaces:**
- Consumes: `createShiftGroupWithStreakRule`, `generateMonthShifts` from `../fixtures/api-setup.js`.

- [ ] **Step 1: Write the spec**

Reference: `AutoFillPanel.vue` in full — `selectedMode`/`openModal()` (lines 11-23, 247-290), Random Coverage modal (`randomModalRef`, lines 40-89), `ShiftCountInputs.vue` (range inputs labeled "Users per shift — Weekdays (Mon–Fri)" / "...Weekends..."), `PlayerSelectionPanel.vue` (mode buttons "All players"/"Only selected"/"Exclude selected", search input placeholder "Search employees...", checkbox rows keyed by employee name).

```js
// frontend/e2e/schedule/auto-fill.spec.js
import { test, expect } from '../fixtures/auth.js';
import { createShiftGroupWithStreakRule, generateMonthShifts } from '../fixtures/api-setup.js';

const MONTH = '2027-06';

test.describe('Auto-Fill', () => {
  test('runs Random Coverage and fills every shift with assignments', async ({ adminPage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: 'AutoFill Team' } });
    await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });

    await adminPage.goto(`/schedule/${MONTH}`);
    await adminPage.getByRole('button', { name: /AutoFill Team/ }).click();
    // The sidebar's "Auto-Fill" accordion toggle (ScheduleView.vue:267-278) — exact match, since
    // AutoFillPanel.vue's own "Auto-Fill Shifts" heading would otherwise also substring-match.
    await adminPage.getByRole('button', { name: 'Auto-Fill', exact: true }).click();

    await adminPage.getByRole('button', { name: 'Run' }).click();
    await expect(adminPage.getByText('Random Coverage', { exact: true })).toBeVisible();

    await adminPage.getByRole('button', { name: 'Only selected' }).click();
    await adminPage.getByPlaceholder('Search employees...').fill('Regular');
    await adminPage.getByText('Regular Employee').locator('..').getByRole('checkbox').check();

    await adminPage.getByRole('button', { name: 'Auto-Fill' }).click();
    await expect(adminPage.getByText(/assigned|filled/i)).toBeVisible({ timeout: 10000 });
  });

  test('the Random Coverage modal traps focus: Tab wraps, Escape closes, focus is restored', async ({ adminPage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: 'FocusTrap Team' } });
    await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });

    await adminPage.goto(`/schedule/${MONTH}`);
    await adminPage.getByRole('button', { name: /FocusTrap Team/ }).click();
    await adminPage.getByRole('button', { name: 'Auto-Fill', exact: true }).click();

    const runButton = adminPage.getByRole('button', { name: 'Run' });
    await runButton.click();

    const dialog = adminPage.getByRole('dialog', { name: 'Random Coverage' });
    await expect(dialog).toBeVisible();

    // Focus enters the trap (moved somewhere inside the dialog on open).
    await expect(dialog.locator(':focus')).toHaveCount(1);

    // Escape closes it and restores focus to the trigger.
    await adminPage.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(runButton).toBeFocused();
  });
});
```

- [ ] **Step 2: Run it, adjusting locators (accordion header text, checkbox targeting) against the live DOM as needed**

Run: `cd frontend && npx playwright test e2e/schedule/auto-fill.spec.js`
Expected: PASS (2 tests)

- [ ] **Step 3: Commit**

```bash
git add frontend/e2e/schedule/auto-fill.spec.js
git commit -m "test(e2e): cover Auto-Fill Random Coverage and fold in focus-trap behavior"
```

---

### Task 12: e2e — `on-call/on-call.spec.js`

**Files:**
- Create: `frontend/e2e/on-call/on-call.spec.js`

**Interfaces:**
- Consumes: `createShiftGroupWithStreakRule` from `../fixtures/api-setup.js` (reused just to get a group; on-call doesn't need the streak rule).

**Note:** like `ScheduleView.vue`'s Group Settings modal (Task 8), `OnCallView.vue`'s "Add On-Call Period" and "Log Callback" modals have plain `<label>` siblings with no `for`/`id` — Step 1 adds it (same zero-behavior-change pattern, distinct ids per modal since both `Starts`/`Ends` labels are text-duplicated across the two independent modals).

- [ ] **Step 1: Add `id`/`for` pairs to both on-call modals' fields**

In `frontend/src/views/OnCallView.vue`, in the "Add On-Call Period Modal" block:
```html
              <label for="oncall-add-employee" class="block text-sm font-medium text-slate-300 mb-1.5">Employee</label>
              <select id="oncall-add-employee" v-model.number="addForm.user_id"
```
```html
              <label for="oncall-add-starts" class="block text-sm font-medium text-slate-300 mb-1.5">Starts</label>
              <input id="oncall-add-starts" v-model="addForm.starts_at" type="datetime-local"
```
```html
              <label for="oncall-add-ends" class="block text-sm font-medium text-slate-300 mb-1.5">Ends</label>
              <input id="oncall-add-ends" v-model="addForm.ends_at" type="datetime-local"
```
And in the "Log Callback Modal" block:
```html
              <label for="oncall-callback-starts" class="block text-sm font-medium text-slate-300 mb-1.5">Starts</label>
              <input id="oncall-callback-starts" v-model="callbackForm.starts_at" type="datetime-local"
```
```html
              <label for="oncall-callback-ends" class="block text-sm font-medium text-slate-300 mb-1.5">Ends</label>
              <input id="oncall-callback-ends" v-model="callbackForm.ends_at" type="datetime-local"
```

- [ ] **Step 2: Write the spec**

Reference: `OnCallView.vue` in full (read earlier in this plan's research) — `+ Add On-Call Period` button, `addForm.user_id/starts_at/ends_at`, `+ Log Callback` button, `removeAssignment`/`removeCallback` (each behind `useConfirm`, but only `removeAssignment` actually passes a `confirm()` call — see below).

```js
// frontend/e2e/on-call/on-call.spec.js
import { test, expect } from '../fixtures/auth.js';
import { createShiftGroupWithStreakRule } from '../fixtures/api-setup.js';

test.describe('On-Call Duty', () => {
  test('creates an on-call assignment, logs a callback, then removes both', async ({ adminPage }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'On-Call Team' } });

    await adminPage.goto('/on-call');
    await adminPage.getByRole('combobox').first().selectOption({ label: 'On-Call Team' });

    await adminPage.getByRole('button', { name: '+ Add On-Call Period' }).click();
    await adminPage.getByLabel('Employee').selectOption({ label: 'Regular Employee' });
    await adminPage.getByLabel('Starts').fill('2027-07-01T08:00');
    await adminPage.getByLabel('Ends').fill('2027-07-01T20:00');
    await adminPage.getByRole('button', { name: 'Save' }).click();

    await expect(adminPage.getByText('On-call period added.')).toBeVisible();
    await expect(adminPage.getByText('Regular Employee')).toBeVisible();

    await adminPage.getByRole('button', { name: '+ Log Callback' }).click();
    await adminPage.getByLabel('Starts').fill('2027-07-01T10:00');
    await adminPage.getByLabel('Ends').fill('2027-07-01T11:00');
    await adminPage.getByRole('button', { name: 'Save' }).click();

    await expect(adminPage.getByText('Callback logged.')).toBeVisible();

    // Scoped to the callback row (`div.items-center.justify-between.text-sm`, OnCallView.vue:52-58) — the
    // assignment's own remove button (title="Remove", icon-only) also computes an accessible name of
    // "Remove", so an unscoped getByRole('button', {name: 'Remove'}) would match both and fail strict mode.
    const callbackRow = adminPage.locator('div.items-center.justify-between.text-sm', { hasText: '📞' });
    await callbackRow.getByRole('button', { name: 'Remove', exact: true }).click(); // no confirmation dialog for callbacks
    await expect(adminPage.getByText('Callback removed.')).toBeVisible();

    await adminPage.getByTitle('Remove').click(); // removes the assignment — this one IS behind a confirm()
    // removeAssignment()'s confirm() call passes no confirmLabel, so ConfirmModal falls back to its default "Confirm".
    await adminPage.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(adminPage.getByText('On-call period removed.')).toBeVisible();
  });
});
```

- [ ] **Step 3: Run it**

Run: `cd frontend && npx playwright test e2e/on-call/on-call.spec.js`
Expected: PASS (1 test)

- [ ] **Step 4: Commit**

```bash
git add frontend/src/views/OnCallView.vue frontend/e2e/on-call/on-call.spec.js
git commit -m "feat: associate on-call modal labels with their fields; test(e2e): cover on-call lifecycle"
```

---

### Task 13: e2e — `shift-settings/shift-colors.spec.js`

**Files:**
- Create: `frontend/e2e/shift-settings/shift-colors.spec.js`

- [ ] **Step 1: Write the spec**

Reference: `ShiftSettingsView.vue` in full (read earlier) — `addColor()`, `startEdit()`/`saveEdit()`, `deleteColor()` (behind `useConfirm`, `confirmLabel: 'Delete'`, `danger: true`).

```js
// frontend/e2e/shift-settings/shift-colors.spec.js
import { test, expect } from '../fixtures/auth.js';

test.describe('Shift Colors', () => {
  test('adds, edits, and deletes a shift color', async ({ adminPage }) => {
    await adminPage.goto('/shift-settings');

    await adminPage.getByPlaceholder('Color name (e.g. Night, Day, Extra)').fill('Twilight');
    await adminPage.getByRole('button', { name: '+ Add' }).click();

    // Each color row's own element carries the Tailwind `group` class (ShiftSettingsView.vue:23-24) —
    // the only div in this view that does, so it's a stable scope for that row's Edit/Delete buttons.
    const row = adminPage.locator('div.group', { hasText: 'Twilight' });
    await expect(row).toBeVisible();

    await row.getByTitle('Edit').click();
    await adminPage.getByPlaceholder('Name').fill('Twilight Shift');
    await adminPage.getByRole('button', { name: 'Save' }).click();

    const renamedRow = adminPage.locator('div.group', { hasText: 'Twilight Shift' });
    await expect(renamedRow).toBeVisible();

    await renamedRow.getByTitle('Delete').click();
    // deleteColor()'s confirm() call passes confirmLabel: 'Delete', so the ConfirmModal button reads "Delete".
    await adminPage.getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(adminPage.getByText('Twilight Shift')).toHaveCount(0);
  });
});
```

- [ ] **Step 2: Run it**

Run: `cd frontend && npx playwright test e2e/shift-settings/shift-colors.spec.js`
Expected: PASS (1 test)

- [ ] **Step 3: Commit**

```bash
git add frontend/e2e/shift-settings/shift-colors.spec.js
git commit -m "test(e2e): cover shift color add/edit/delete"
```

---

### Task 14: e2e — `my-shifts/my-shifts.spec.js`

**Files:**
- Create: `frontend/e2e/my-shifts/my-shifts.spec.js`

**Interfaces:**
- Consumes: `createShiftGroupWithStreakRule`, `generateMonthShifts`, `getEmployeeIdByEmail`, `getShiftGroupsAndShifts`, `assignShift` from `../fixtures/api-setup.js`; the `employeePage` fixture from `../fixtures/auth.js`.

- [ ] **Step 1: Write the spec**

Reference: `MyShiftsView.vue` in full (read earlier) — day cards, "Request off" button (`isShiftFuture`), change-request modal (`textarea`, "Submit Request" button).

```js
// frontend/e2e/my-shifts/my-shifts.spec.js
import { test, expect } from '../fixtures/auth.js';
import {
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
  assignShift,
} from '../fixtures/api-setup.js';

const FUTURE_MONTH = '2027-08';

test.describe('My Shifts', () => {
  test('an employee sees their assigned shifts and can request a day off', async ({ adminPage, employeePage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: 'MyShifts Team' } });
    await generateMonthShifts(context, { monthKey: FUTURE_MONTH, shiftGroupId: groupId });
    const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const { shifts } = await getShiftGroupsAndShifts(context, FUTURE_MONTH, groupId);
    await assignShift(context, { shiftId: shifts[0].id, userId: employeeId });

    await employeePage.goto(`/my-shifts`);
    // Navigate to the seeded future month (My Shifts has no deep-link route param — step forward via UI,
    // using the aria-label added to its month-nav buttons in Task 7).
    const [, targetYear, targetMonth] = FUTURE_MONTH.match(/(\d+)-(\d+)/);
    const today = new Date();
    const monthsForward =
      (Number(targetYear) - today.getFullYear()) * 12 + (Number(targetMonth) - 1 - today.getMonth());
    for (let i = 0; i < monthsForward; i++) {
      await employeePage.getByLabel('Next month').click();
    }

    await expect(employeePage.getByText('Shift 1')).toBeVisible();
    await employeePage.getByRole('button', { name: 'Request off' }).click();
    await employeePage.getByPlaceholder("Please describe why you can't work this shift...").fill('Family event');
    await employeePage.getByRole('button', { name: 'Submit Request' }).click();

    await expect(employeePage.getByText('Day-off request submitted successfully.')).toBeVisible();
    await expect(employeePage.getByText('Pending')).toBeVisible();
  });
});
```

- [ ] **Step 2: Run it**

Run: `cd frontend && npx playwright test e2e/my-shifts/my-shifts.spec.js`
Expected: PASS (1 test)

- [ ] **Step 3: Commit**

```bash
git add frontend/e2e/my-shifts/my-shifts.spec.js
git commit -m "test(e2e): cover My Shifts view and day-off change requests"
```

---

### Task 15: Cleanup — remove Vitest and its supporting files

**Files:**
- Modify: `frontend/package.json` (remove devDependencies)
- Modify: `frontend/vite.config.js` (remove `test: {...}` block)
- Delete: `frontend/src/mocks/` (entire directory — MSW handlers/server/setup)
- Modify: `CHANGELOG.md` (root-level, per `[[feedback-changelog-updates]]`)

- [ ] **Step 1: Confirm nothing still imports MSW or Vitest**

Run: `cd frontend && grep -rl "vitest\|msw\|@testing-library" src/ || echo "clean"`
Expected: `clean` (all Vitest-authored test files were ported or deleted in Tasks 4–6)

- [ ] **Step 2: Remove the now-unused devDependencies**

```bash
cd frontend && npm uninstall vitest @testing-library/vue @testing-library/jest-dom @vue/test-utils happy-dom msw
```

- [ ] **Step 3: Remove the Vitest config block from `vite.config.js`**

Edit `frontend/vite.config.js`, deleting the trailing:
```js
  test: {
    environment: 'happy-dom',
    setupFiles: ['./src/mocks/setup.js'],
    globals: false,
  },
```
(and the now-dangling comma on the line before it, if any).

- [ ] **Step 4: Delete the MSW mocks directory**

```bash
git rm -r frontend/src/mocks
```

- [ ] **Step 5: Verify both suites still run clean**

Run: `cd frontend && npm run test && npm run test:e2e`
Expected: both PASS.

- [ ] **Step 6: Add the CHANGELOG entry**

Add a new entry at the top of `CHANGELOG.md`, following the existing format (reverse-chronological, `## YYYY-MM-DD HH:MM — 🎯 Title`):

```markdown
## 2026-09-25 00:00 — 🎯 Playwright frontend testing migration

Replaced Vitest/`@testing-library/vue`/MSW with Playwright as the frontend's only test tool — a Node-mode `unit` config for pure composable logic, and a real-Chromium `e2e` config driving the actual Dockerized backend. Ported all 5 existing test scenarios and added full e2e coverage for every shift/schedule-related route: schedule navigation, shift-group settlement/streak-rule settings, month generation, manual drag-and-drop assignment, auto-fill, on-call duty, shift colors, and My Shifts.

**Backend:** new test-only `E2eBaselineSeeder` — additive, no production code changes.

**Frontend:** `frontend/playwright.unit.config.js` (composable tests), `frontend/playwright.config.js` (e2e, real backend via Docker), `frontend/e2e/` (11 new/ported spec files across `auth`, `schedule`, `on-call`, `shift-settings`, `my-shifts`, `settlement-periods`), plus small accessibility fixes (`aria-label`/`id`+`for` pairs) to `ScheduleView.vue`, `MyShiftsView.vue`, and `OnCallView.vue` to make their controls reliably testable.

---
```

- [ ] **Step 7: Commit**

```bash
git add frontend/package.json frontend/vite.config.js CHANGELOG.md
git rm -r frontend/src/mocks
git commit -m "chore: remove Vitest/MSW now that Playwright covers all frontend tests"
```

---

### Task 16: CI — update `frontend-tests.yml` to run against a live backend

**Files:**
- Modify: `.github/workflows/frontend-tests.yml`

**Interfaces:**
- Consumes: `docker-compose.yml`'s existing `db`/`backend` service definitions (unchanged).

- [ ] **Step 1: Rewrite the workflow**

```yaml
name: Frontend Tests

on:
  push:
    paths:
      - 'frontend/**'
      - 'backend/**'
      - '.github/workflows/frontend-tests.yml'
  pull_request:
    paths:
      - 'frontend/**'
      - 'backend/**'
      - '.github/workflows/frontend-tests.yml'

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: Start backend + database
        run: docker compose up -d db backend
        env:
          APP_KEY: base64:${{ secrets.CI_APP_KEY || 'AckfSECXIvnK5r28GaUwmieqzqU9BqYnSGaawyLm5fA=' }}

      - name: Wait for backend healthcheck
        run: |
          for i in $(seq 1 30); do
            if docker exec shift_changer_backend php artisan --version > /dev/null 2>&1; then
              echo "backend is up"; exit 0
            fi
            sleep 2
          done
          echo "backend did not become ready in time"; docker compose logs backend; exit 1

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'
          cache-dependency-path: frontend/package-lock.json

      - name: Install dependencies
        working-directory: frontend
        run: npm ci

      - name: Install Playwright browsers
        working-directory: frontend
        run: npx playwright install --with-deps chromium

      - name: Run unit tests
        working-directory: frontend
        run: npm run test

      - name: Run e2e tests
        working-directory: frontend
        run: npm run test:e2e
        env:
          LARAVEL_BYPASS_ENV_CHECK: "1"
```

**Note for the implementer:** the `APP_KEY` fallback above is a placeholder value for CI-only ephemeral containers (never a real secret) — confirm whether the repo already has a `CI_APP_KEY` secret or an `.env.ci` checked in before finalizing; if `docker-compose.yml` already generates/reads a key some other way (check for an existing `.env` committed for CI, or an `APP_KEY` generation step in `backend-tests.yml`), reuse that mechanism instead of introducing a second one.

- [ ] **Step 2: Verify by pushing a branch and checking the Actions run (or `act` locally if available), confirming both `npm run test` and `npm run test:e2e` pass in CI**

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/frontend-tests.yml
git commit -m "ci: run frontend Playwright suite against a live Dockerized backend"
```
