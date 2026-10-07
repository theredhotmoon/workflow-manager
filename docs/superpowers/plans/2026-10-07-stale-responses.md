# Stale-Response Guards Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop every HIGH and MEDIUM stale-response race found by the audit: an older response that arrives last may no longer overwrite newer state, clear `loading`, blank data, or snap a selection back.

**Architecture:** One tiny shared helper, `useLatestRequest()` (`frontend/src/composables/useLatestRequest.js`), replaces the three hand-rolled request counters and is applied once per piece of state ("channel") in every flagged view. Each call site does `const token = xRequests.begin()` before the request, `if (!xRequests.isCurrent(token)) return;` after each await, and guards `catch`/`finally` side effects (loading, toasts, blanking) the same way; `xRequests.invalidate()` drops an in-flight response when state is set without a request (deselect, close, local mutation, cache hit). Every HIGH finding gets a deterministic Playwright test built on a new `holdRequests()` e2e fixture that holds the older request until the newer one has rendered.

**Why no AbortController:** (1) several races have no request to abort — cache hits (`loadVersionData`), deselect, local mutations (`saveEntry`) — so an ordering guard is needed anyway; (2) aborting makes axios reject with `CanceledError`, so every `catch` would have to tell "cancelled" from "failed" or it would toast/blank on cancel — more code per site, not less; (3) the GETs are cheap and the server finishes them regardless; (4) the e2e hold pattern (`route.continue()` after release) breaks on a request the page already aborted. The helper stays three methods.

**Tech Stack:** Vue 3 `<script setup>`, axios (`frontend/src/axios.js`), Playwright 1.63 (e2e in `frontend/e2e`, Node-mode unit tests via `frontend/playwright.unit.config.js`), Biome 2.5, Laravel API in Docker (`shift_changer_backend`).

**Spec:** [docs/superpowers/specs/2026-10-07-stale-response-audit.md](../specs/2026-10-07-stale-response-audit.md) (the read-only audit: findings H1–H14, M1–M10 with file:line evidence, fixes and tests). There is no separate spec: the audit plus the user-approved approach below are the requirements.
Approved approach: ONE shared helper; the three already-fixed views (ScheduleView `fetchSchedule`, SettlementPeriodsView `fetchReport`, MyShiftsView `fetchMyShifts`) migrate to it; it is applied to every HIGH and MEDIUM finding; every HIGH finding gets a deterministic e2e test proven to fail first; MEDIUM findings get a test where cheap, otherwise a stated reason; the helper gets Node-mode unit tests; LOW findings are not touched (YAGNI).

## Global Constraints

- Helper: always `useLatestRequest()` from `frontend/src/composables/useLatestRequest.js`; never a hand-rolled `let xSeq = 0` counter. One instance per piece of state, named `<state>Requests` (e.g. `delegationRequests`). Independent states never share an instance; two functions that write the same state share one (audit §6).
- Do not touch code the audit did not flag (LOW items, `fetchShiftColors`, `fetchTemplates`, `PlayerSelectionPanel`, `AppLayout` poll, per-id `fetchLogs`).
- e2e specs import `test`/`expect` from `frontend/e2e/fixtures/auth.js` (never from `@playwright/test`), and `holdRequests` from `frontend/e2e/fixtures/hold.js` (created in Task 3).
- Every group, template, procedure, delegation and closure name a test creates is unique across the suite (the names in this plan are; keep them).
- Months already reserved by existing specs (grep `frontend/e2e` for `20[0-9][0-9]-[0-9][0-9]` before adding one): 2027-03…2027-12, 2028-01…2028-08, 2099-07…2099-10. This plan reserves: **2098-01/02** schedule delegations overlay, **2098-03/04** schedule newbie overlay, **2098-05/06** schedule closures overlay, **2098-07** schedule availability, **2098-08/09** on-call, **2098-10/11** office availability, **2098-12** schedule deselect race, **2097-01/02** my-availability delegations, **2097-03/04** my-availability entries, **2097-05/06** checklist fill + history dates, **2097-07** users newbie periods, **2097-08** delegation archive. Re-grep before writing each spec in case a parallel branch took one; if so, pick an unused month and update the spec's reservation comment.
- Verify persisted outcomes via the API or after a reload (Tasks 6 and 10 do; the rest assert UI state, which is the bug).
- Positive guard before every absence assertion: first prove the page rendered the newer state, then assert the stale state is absent.
- Sabotage-prove every new assertion: the race assertions are proven by the "run it, see it fail" step before the fix; after the fix, do the task's listed sabotage (delete one guard line, see the named test fail, re-insert the identical line). Never restore a file with `git checkout`/`git restore` during a sabotage check — undo the exact edit by hand.
- Running any e2e command (`npm run test:e2e` or `npx playwright test e2e/...`) runs `e2e/global-setup.js`, which does `migrate:fresh` + seed on the shared dev DB in the `shift_changer_backend` container. Make sure nobody needs that data, and that the container is running (`docker ps`).
- Unit tests: `cd frontend && npx playwright test --config=playwright.unit.config.js <path>` (Node mode, `*.test.js` next to the source).
- Biome: `cd frontend && npx biome check <changed files>` must pass before every commit (fix with `npx biome check --write <files>`).
- Run all commands from the repo root `C:/Projects/ShiftChanger/workflow-manager` unless a step says `cd frontend`.
- Every commit message ends with the trailer line `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>` (passed as a second `-m`).

## Review Focus

1. Deselecting an employee on the schedule while their availability is still loading, then selecting another: the first employee's unavailable days must never be painted under the second (ScheduleView `deselectEmployee` must invalidate). Pinned in Task 3 ("an employee deselected while their availability loads…").
2. Stepping months twice on On-Call: an older response must not hide "Loading…" while the newest month is still loading (the `finally` guard). Pinned in Task 4 ("the loading state stays until the newest month has loaded").
3. Clicking back to the latest procedure version while an older version is still loading: the page stays on the latest version (watcher's latest branch must invalidate). Pinned in Task 13 ("going back to the latest version while an older one loads…").
4. Moving to a version that is already cached while an uncached one is loading: the cached version stays (the token must be taken before the cache check). Pinned in Task 13 ("a cached version shown while an older one loads…").
5. Refining a procedure search before the broader search answers (the `filtering` path, not the initial `loading` path): the refined results stay. Pinned in Task 12 ("refining the search before the broader search answers…").

---

### Task 1: `useLatestRequest` helper

**Files:**
- Create: `frontend/src/composables/useLatestRequest.js`
- Test: `frontend/src/composables/useLatestRequest.test.js`

**Interfaces:**
- Consumes: nothing.
- Produces: `useLatestRequest(): { begin(): symbol, isCurrent(token: symbol): boolean, invalidate(): void }`. `begin()` starts a request and returns its token (a fresh `Symbol`); `isCurrent(token)` is true only for the token of the last `begin()` on the same instance and only until the next `begin()`/`invalidate()`; `invalidate()` makes every outstanding token stale without starting a request. Tokens of one instance are never current in another.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/composables/useLatestRequest.test.js`:

```js
import { expect, test } from '@playwright/test';
import { useLatestRequest } from './useLatestRequest.js';

function deferred() {
  let resolve;
  const promise = new Promise((r) => {
    resolve = r;
  });
  return { promise, resolve };
}

// The call-site pattern every view uses: apply, error handling and `loading` only for the latest.
function makeLoader(latest, state) {
  return async function load(response) {
    const token = latest.begin();
    state.loading = true;
    try {
      const data = await response;
      if (!latest.isCurrent(token)) return;
      state.data = data;
    } finally {
      if (latest.isCurrent(token)) state.loading = false;
    }
  };
}

test.describe('useLatestRequest', () => {
  test('a request that was just begun is current', () => {
    const latest = useLatestRequest();
    const token = latest.begin();
    expect(latest.isCurrent(token)).toBe(true);
  });

  test('a newer request supersedes an older one', () => {
    const latest = useLatestRequest();
    const older = latest.begin();
    const newer = latest.begin();
    expect(latest.isCurrent(older)).toBe(false);
    expect(latest.isCurrent(newer)).toBe(true);
  });

  test('invalidate() makes the in-flight request stale without starting a new one', () => {
    const latest = useLatestRequest();
    const token = latest.begin();
    latest.invalidate();
    expect(latest.isCurrent(token)).toBe(false);
    const next = latest.begin();
    expect(latest.isCurrent(next)).toBe(true);
  });

  test('channels are independent and never accept each other’s tokens', () => {
    const schedule = useLatestRequest();
    const overlay = useLatestRequest();
    const scheduleToken = schedule.begin();
    const overlayToken = overlay.begin();
    schedule.begin();
    expect(schedule.isCurrent(scheduleToken)).toBe(false);
    expect(overlay.isCurrent(overlayToken)).toBe(true);
    expect(schedule.isCurrent(overlayToken)).toBe(false);
  });

  test('an older response that resolves last is not applied and does not touch loading', async () => {
    const state = { data: null, loading: false };
    const load = makeLoader(useLatestRequest(), state);
    const slow = deferred();
    const fast = deferred();
    const first = load(slow.promise);
    const second = load(fast.promise);

    fast.resolve('newer');
    await second;
    expect(state).toEqual({ data: 'newer', loading: false });

    slow.resolve('older');
    await first;
    expect(state).toEqual({ data: 'newer', loading: false });
  });

  test('loading stays on while the latest request is still pending', async () => {
    const state = { data: null, loading: false };
    const load = makeLoader(useLatestRequest(), state);
    const slow = deferred();
    const fast = deferred();
    const first = load(fast.promise);
    load(slow.promise);

    fast.resolve('older');
    await first;
    expect(state).toEqual({ data: null, loading: true });
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx playwright test --config=playwright.unit.config.js src/composables/useLatestRequest.test.js`
Expected: FAIL — the file cannot be loaded: `Cannot find module './useLatestRequest.js'` (Error: … `src/composables/useLatestRequest.test.js` … no tests run).

- [ ] **Step 3: Write minimal implementation**

Create `frontend/src/composables/useLatestRequest.js`:

```js
// frontend/src/composables/useLatestRequest.js
//
// Guards a piece of state against stale responses. When a view fires overlapping requests for the
// same state (month, group, filter or page changes), only the latest one may apply its result,
// clear `loading` or report an error — otherwise a slower, older response that arrives last
// overwrites what the user is looking at.
//
//   const scheduleRequests = useLatestRequest();
//   async function fetchSchedule() {
//     const token = scheduleRequests.begin();
//     loading.value = true;
//     try {
//       const res = await api.get('/api/schedule', { params });
//       if (!scheduleRequests.isCurrent(token)) return;
//       schedule.value = res.data;
//     } catch {
//       if (scheduleRequests.isCurrent(token)) showToast('Failed to load schedule.', 'error');
//     } finally {
//       if (scheduleRequests.isCurrent(token)) loading.value = false;
//     }
//   }
//
// Use one instance per piece of state: independent loads (the schedule and a delegation overlay)
// each get their own, so one never discards the other. Call invalidate() when the state is set
// without a request (deselect, close, a local save, a cache hit) to drop an in-flight response.
// No AbortController on purpose: the ordering guard is needed anyway, and an abort would make every
// catch block tell "cancelled" from "failed".
export function useLatestRequest() {
  let current = null;
  return {
    begin() {
      current = Symbol('request');
      return current;
    },
    isCurrent(token) {
      return token === current;
    },
    invalidate() {
      current = null;
    },
  };
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx playwright test --config=playwright.unit.config.js src/composables/useLatestRequest.test.js`
Expected: PASS — 6 passed.
Sabotage: change `return token === current;` to `return true;`, re-run, expect the "supersedes", "invalidate", "independent", "resolves last" and "loading stays on" tests to FAIL; restore the exact line `return token === current;` and re-run to PASS.

- [ ] **Step 5: Lint and commit**

```bash
cd frontend && npx biome check src/composables/useLatestRequest.js src/composables/useLatestRequest.test.js && cd ..
git add frontend/src/composables/useLatestRequest.js frontend/src/composables/useLatestRequest.test.js
git commit -m "feat: add useLatestRequest helper for stale-response guards" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Migrate the three reference guards to the helper

**Files:**
- Modify: `frontend/src/views/ScheduleView.vue:1308-1309` (imports), `:2047-2078` (`fetchSchedule`)
- Modify: `frontend/src/views/SettlementPeriodsView.vue:63-65` (imports), `:85-108` (`fetchReport`)
- Modify: `frontend/src/views/MyShiftsView.vue:206-210` (imports), `:318-342` (`fetchMyShifts`)
- Test (existing, regression): `frontend/e2e/schedule/navigation.spec.js` ("…slower initial load"), `frontend/e2e/settlement-periods/report.spec.js` ("a slow initial report…"), `frontend/e2e/my-shifts/my-shifts.spec.js` ("a slow load of the previous month…")

**Interfaces:**
- Consumes: `useLatestRequest()` (Task 1).
- Produces: `ScheduleView.vue` now imports `useLatestRequest` and has `const scheduleRequests = useLatestRequest();` — Task 3 relies on the import being present and on `fetchSchedule()`'s success path (it adds one line there). The counters `scheduleRequestSeq`, `reportRequestSeq`, `shiftsRequestSeq` no longer exist.

- [ ] **Step 1: Run the existing race specs as a baseline**

Run: `cd frontend && npx playwright test e2e/schedule/navigation.spec.js e2e/settlement-periods/report.spec.js e2e/my-shifts/my-shifts.spec.js -g "slower initial load|slow initial report|slow load of the previous month"`
Expected: PASS — 3 passed (this is a pure refactor; these specs are the safety net).

- [ ] **Step 2: Migrate ScheduleView**

In `frontend/src/views/ScheduleView.vue`, add the import between the `useConfirm` and `useToast` imports:

```js
import { confirm } from '../composables/useConfirm.js';
import { useLatestRequest } from '../composables/useLatestRequest.js';
import { useToast } from '../composables/useToast.js';
```

Replace the counter and `fetchSchedule()` (currently `let scheduleRequestSeq = 0;` through the end of the function) with:

```js
// Several fetches can overlap (e.g. a deep link fires one before the groups have loaded, then the
// user switches tabs). Only the latest one may apply its response — otherwise a slower, older
// response (which also reports a shift_group_id) snaps the view back to the wrong group.
const scheduleRequests = useLatestRequest();

async function fetchSchedule() {
  const token = scheduleRequests.begin();
  loading.value = true;
  try {
    const params = { month: monthKey.value };
    if (activeGroupId.value) params.shift_group_id = activeGroupId.value;
    const response = await api.get('/api/schedule', { params });
    if (!scheduleRequests.isCurrent(token)) return;
    schedule.value = response.data.schedule;
    employees.value = response.data.employees;
    employeePool.value = response.data.employees.map((e) => ({
      ...e,
      user_id: e.id,
      user_name: e.name,
    }));
    // Update activeGroupId from server response if available (but not when in all-groups mode)
    if (response.data.shift_group_id && !isAllGroupsMode.value)
      activeGroupId.value = response.data.shift_group_id;
    fetchDelegations(); // Always fetch for conflict checks
    fetchNewbies(); // Always fetch for newbie star display
    fetchShiftColors(); // Load global color definitions
  } catch (err) {
    if (scheduleRequests.isCurrent(token)) showToast('Failed to load schedule.', 'error');
  } finally {
    if (scheduleRequests.isCurrent(token)) loading.value = false;
  }
}
```

- [ ] **Step 3: Migrate SettlementPeriodsView**

In `frontend/src/views/SettlementPeriodsView.vue`, extend the imports:

```js
import { onMounted, ref } from 'vue';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import { useLatestRequest } from '../composables/useLatestRequest.js';
```

Replace `let reportRequestSeq = 0;` and `fetchReport()` with:

```js
// Fetches overlap (the on-load fetch for the default group, a group change, period stepping).
// Only the latest one may apply its response — otherwise a slower, older response replaces the
// report the user is looking at with another group's or period's.
const reportRequests = useLatestRequest();

async function fetchReport() {
  const token = reportRequests.begin();
  if (!selectedGroupId.value) {
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const res = await api.get('/api/settlement-periods', {
      params: { shift_group_id: selectedGroupId.value, offset: offset.value },
    });
    if (!reportRequests.isCurrent(token)) return;
    report.value = res.data;
  } catch {
    if (reportRequests.isCurrent(token)) report.value = null;
  } finally {
    if (reportRequests.isCurrent(token)) loading.value = false;
  }
}
```

(Keep the existing comment block above it; the snippet repeats it so it is not lost.)

- [ ] **Step 4: Migrate MyShiftsView**

In `frontend/src/views/MyShiftsView.vue`, extend the imports:

```js
import { computed, onMounted, ref, watch } from 'vue';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import AppToast from '../components/AppToast.vue';
import { useLatestRequest } from '../composables/useLatestRequest.js';
import { useToast } from '../composables/useToast';
```

Replace `let shiftsRequestSeq = 0;` and `fetchMyShifts()` with:

```js
// Switching months while a load is in flight overlaps fetches. Only the latest one may apply its
// result — otherwise a slower load of the previous month shows its shifts under the new month's
// heading. Both requests belong to one load, so one token covers the Promise.all.
const shiftsRequests = useLatestRequest();

async function fetchMyShifts() {
  const token = shiftsRequests.begin();
  loading.value = true;
  try {
    const [shiftRes, delRes] = await Promise.all([
      api.get('/api/my-shifts', { params: { month: monthKey.value } }),
      api.get('/api/my-delegations', { params: { month: monthKey.value } }),
    ]);
    if (!shiftsRequests.isCurrent(token)) return;
    shifts.value = shiftRes.data;
    delegations.value = delRes.data;
  } catch {
    if (!shiftsRequests.isCurrent(token)) return;
    shifts.value = {};
    delegations.value = [];
  } finally {
    if (shiftsRequests.isCurrent(token)) loading.value = false;
  }
}
```

- [ ] **Step 5: Run the race specs again and sabotage-check each view**

Run: `cd frontend && npx playwright test e2e/schedule/navigation.spec.js e2e/settlement-periods/report.spec.js e2e/my-shifts/my-shifts.spec.js -g "slower initial load|slow initial report|slow load of the previous month"`
Expected: PASS — 3 passed.
Sabotage (one at a time, re-inserting the identical line after each):
- ScheduleView: delete `if (!scheduleRequests.isCurrent(token)) return;` → "switching group right after a deep link…" FAILS (`toBeVisible` on the tab's settings button / `toHaveCount(1)`).
- SettlementPeriodsView: delete `if (!reportRequests.isCurrent(token)) return;` → "a slow initial report…" FAILS (`toHaveValue(String(groupId))` or the row assertion).
- MyShiftsView: delete the first `if (!shiftsRequests.isCurrent(token)) return;` → "a slow load of the previous month…" FAILS (`octoberShift` not visible).
Re-run the command: 3 passed.
Also confirm no counter is left: `grep -rn "RequestSeq" frontend/src` prints nothing.

- [ ] **Step 6: Lint and commit**

```bash
cd frontend && npx biome check src/views/ScheduleView.vue src/views/SettlementPeriodsView.vue src/views/MyShiftsView.vue && cd ..
git add frontend/src/views/ScheduleView.vue frontend/src/views/SettlementPeriodsView.vue frontend/src/views/MyShiftsView.vue
git commit -m "refactor: use useLatestRequest in schedule, settlement and my-shifts views" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: ScheduleView overlays and availability (H1, H2, M1) + hold fixture

**Files:**
- Create: `frontend/e2e/fixtures/hold.js`
- Modify: `frontend/e2e/fixtures/api-setup.js` (append four helpers)
- Modify: `frontend/src/views/ScheduleView.vue:1549-1626` (`fetchDelegations`, `fetchNewbies`, `fetchClosures`), `fetchSchedule` success path (Task 2 version), `:2791-2815` (`toggleEmployeeSelect`/`deselectEmployee`/`fetchAvailability`)
- Test: `frontend/e2e/schedule/stale-overlays.spec.js` (create)

**Interfaces:**
- Consumes: `useLatestRequest()` (Task 1); the `useLatestRequest` import and `fetchSchedule()` from Task 2; `createShiftGroupWithStreakRule`, `getEmployeeIdByEmail` (existing in `api-setup.js`).
- Produces:
  - `holdRequests(page, matcher: (url: URL) => boolean, options?: { mode?: 'continue' | 'snapshot' | 'abort', method?: string, skip?: number, times?: number }): Promise<{ seen: Promise<void>, release: () => void, releaseAndSettle: () => Promise<void> }>` in `frontend/e2e/fixtures/hold.js` — used by Tasks 4–16.
  - In `api-setup.js`: `createDelegation(context, { name, destination, start, end, userIds? }) → delegation JSON` (start/end are `YYYY-MM-DD`); `createNewbiePeriod(context, { userId, startDate, endDate }) → period JSON`; `createOfficeClosure(context, { startDate, endDate?, title }) → JSON`; `createAvailability(context, { type, startDate, endDate? }) → entry JSON` (creates for the context's logged-in user). Used by Tasks 5, 6, 11, 16.
  - ScheduleView channels: `delegationRequests`, `newbieRequests`, `closureRequests`, `availabilityRequests`.

- [ ] **Step 1: Write the hold fixture**

Create `frontend/e2e/fixtures/hold.js`:

```js
// frontend/e2e/fixtures/hold.js
//
// Deterministic stale-response races: hold the matching (older) request until the newer one has
// rendered, then let the older one finish last.
//
//   mode 'continue' (default): the request reaches the server only after release.
//   mode 'snapshot': the server answers at once, but the browser only gets that (now stale) answer
//     after release — use when a mutation happens between the read and its delivery.
//   mode 'abort': the request fails after release (a superseded request that errors).
//
// Only requests with `method` (default GET) are held; `skip` lets the first N matches through and
// `times` caps how many are held. `seen` resolves when the first request is held (for 'snapshot':
// once the server has answered it).
export async function holdRequests(
  page,
  matcher,
  { mode = 'continue', method = 'GET', skip = 0, times = Number.POSITIVE_INFINITY } = {},
) {
  let release;
  const released = new Promise((resolve) => {
    release = resolve;
  });
  let markSeen;
  const seen = new Promise((resolve) => {
    markSeen = resolve;
  });
  let matched = 0;

  await page.route(matcher, async (route) => {
    if (route.request().method() !== method) return route.fallback();
    matched += 1;
    if (matched <= skip || matched > skip + times) return route.fallback();
    if (mode === 'snapshot') {
      const response = await route.fetch();
      markSeen();
      await released;
      await route.fulfill({ response });
      return;
    }
    markSeen();
    await released;
    if (mode === 'abort') await route.abort();
    else await route.continue();
  });

  const isHeld = (url, requestMethod) => requestMethod === method && matcher(new URL(url));

  // Release, wait until the held request has finished (response or failure), then until the page
  // is idle, so whatever the view does with the stale result has happened before assertions run.
  async function releaseAndSettle() {
    const finished =
      mode === 'abort'
        ? page.waitForEvent('requestfailed', (req) => isHeld(req.url(), req.method()))
        : page.waitForResponse((res) => isHeld(res.url(), res.request().method()));
    release();
    await finished;
    await page.waitForLoadState('networkidle');
  }

  return { seen, release, releaseAndSettle };
}
```

- [ ] **Step 2: Add the API helpers**

Append to `frontend/e2e/fixtures/api-setup.js` (they use the file's private `send()` and the imported `getXsrfHeader`):

```js
export async function createDelegation(context, { name, destination, start, end, userIds = [] }) {
  const headers = await getXsrfHeader(context);
  const data = {
    name,
    destination,
    start_datetime: `${start} 08:00:00`,
    end_datetime: `${end} 18:00:00`,
  };
  if (userIds.length) data.user_ids = userIds;
  const res = await send(context, 'post', '/api/delegations', { data, headers });
  if (!res.ok()) throw new Error(`create delegation failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

export async function createNewbiePeriod(context, { userId, startDate, endDate }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', '/api/newbie-periods', {
    data: { user_id: userId, start_date: startDate, end_date: endDate },
    headers,
  });
  if (!res.ok()) throw new Error(`create newbie period failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

export async function createOfficeClosure(context, { startDate, endDate = startDate, title }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', '/api/office-closures', {
    data: { start_date: startDate, end_date: endDate, title },
    headers,
  });
  if (!res.ok()) throw new Error(`create office closure failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

// Availability entries always belong to the logged-in user of `context`.
export async function createAvailability(context, { type, startDate, endDate = startDate }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', '/api/user-availabilities', {
    data: { type, start_date: startDate, end_date: endDate },
    headers,
  });
  if (!res.ok()) throw new Error(`create availability failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}
```

- [ ] **Step 3: Write the failing tests**

Create `frontend/e2e/schedule/stale-overlays.spec.js`:

```js
import {
  createAvailability,
  createDelegation,
  createNewbiePeriod,
  createOfficeClosure,
  createShiftGroupWithStreakRule,
  getEmployeeIdByEmail,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

// Months reserved for this file (delegations, newbie periods, closures and availability are global,
// not per group): 2098-01/02 delegations, 2098-03/04 newbies, 2098-05/06 closures, 2098-07 and
// 2098-12 availability.
const UNAVAILABLE = 'rgba(239, 68, 68, 0.18)';

const dayCell = (page, date) => page.locator(`[data-date="${date}"]`);
const poolCard = (page, name) => page.locator('div.cursor-grab').filter({ hasText: name });

async function enableOverlay(page, label) {
  await page.getByRole('button', { name: 'Overlays' }).click();
  await page.getByLabel(label).check();
}

test.describe('Schedule overlays ignore stale responses', () => {
  test('a slow delegations overlay for the previous month does not replace the month on screen', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'Delegation Overlay Race' } });
    const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const marcinId = await getEmployeeIdByEmail(context, 'marcin@shiftchanger.com');
    await createDelegation(context, {
      name: 'January Overlay Trip',
      destination: 'Overlay City',
      start: '2098-01-10',
      end: '2098-01-12',
      userIds: [regularId],
    });
    await createDelegation(context, {
      name: 'February Overlay Trip',
      destination: 'Overlay City',
      start: '2098-02-10',
      end: '2098-02-12',
      userIds: [marcinId],
    });

    // Every January delegations request (after the schedule load and after switching the overlay
    // on) is held until February's has rendered, so January's lands last.
    const january = await holdRequests(
      adminPage,
      (url) =>
        url.pathname === '/api/delegations/month' && url.searchParams.get('month') === '2098-01',
    );

    await adminPage.goto('/schedule/2098-01');
    await expect(adminPage.getByText('January 2098')).toBeVisible();
    await january.seen;
    await enableOverlay(adminPage, 'Show Delegations');
    await adminPage.getByLabel('Next month').click();
    await expect(adminPage.getByText('February 2098')).toBeVisible();
    const februaryChip = dayCell(adminPage, '2098-02-10').getByText('Marcin Kefir');
    await expect(februaryChip).toBeVisible();

    await january.releaseAndSettle();

    await expect(adminPage.getByText('February 2098')).toBeVisible();
    await expect(februaryChip).toBeVisible();
  });

  test('a slow newbie overlay for the previous month does not replace the month on screen', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'Newbie Overlay Race' } });
    const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const marcinId = await getEmployeeIdByEmail(context, 'marcin@shiftchanger.com');
    await createNewbiePeriod(context, {
      userId: regularId,
      startDate: '2098-03-10',
      endDate: '2098-03-12',
    });
    await createNewbiePeriod(context, {
      userId: marcinId,
      startDate: '2098-04-10',
      endDate: '2098-04-12',
    });

    const march = await holdRequests(
      adminPage,
      (url) =>
        url.pathname === '/api/newbie-periods/month' && url.searchParams.get('month') === '2098-03',
    );

    await adminPage.goto('/schedule/2098-03');
    await expect(adminPage.getByText('March 2098')).toBeVisible();
    await march.seen;
    await enableOverlay(adminPage, 'Show Newbies');
    await adminPage.getByLabel('Next month').click();
    await expect(adminPage.getByText('April 2098')).toBeVisible();
    const aprilStar = dayCell(adminPage, '2098-04-10').getByText('Marcin Kefir');
    await expect(aprilStar).toBeVisible();

    await march.releaseAndSettle();

    await expect(adminPage.getByText('April 2098')).toBeVisible();
    await expect(aprilStar).toBeVisible();
  });

  test('the office-closure overlay follows month navigation and ignores the previous month', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'Closure Overlay Race' } });
    await createOfficeClosure(context, { startDate: '2098-05-10', title: 'May Overlay Closure' });
    await createOfficeClosure(context, { startDate: '2098-06-10', title: 'June Overlay Closure' });

    const may = await holdRequests(
      adminPage,
      (url) => url.pathname === '/api/office-closures' && url.searchParams.get('month') === '2098-05',
    );

    await adminPage.goto('/schedule/2098-05');
    await expect(adminPage.getByText('May 2098')).toBeVisible();
    await enableOverlay(adminPage, 'Show Office Unavailability');
    await may.seen;
    await adminPage.getByLabel('Next month').click();
    await expect(adminPage.getByText('June 2098')).toBeVisible();
    // Before the fix the overlay was never refetched on month change, so June's closure never showed.
    const juneClosure = dayCell(adminPage, '2098-06-10').getByText('June Overlay Closure');
    await expect(juneClosure).toBeVisible();

    await may.releaseAndSettle();

    await expect(juneClosure).toBeVisible();
    await expect(adminPage.getByText('May Overlay Closure')).toHaveCount(0);
  });

  test('a slow availability load for the previously clicked employee does not paint their days', async ({
    adminPage,
    employeePage,
  }) => {
    const adminContext = adminPage.context();
    await createShiftGroupWithStreakRule(adminContext, { group: { name: 'Availability Race Team' } });
    const adminId = await getEmployeeIdByEmail(adminContext, 'admin@shiftchanger.com');
    await createAvailability(adminContext, { type: 'unavailable', startDate: '2098-07-10' });
    await createAvailability(employeePage.context(), {
      type: 'unavailable',
      startDate: '2098-07-20',
    });

    const adminAvailability = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/user-availabilities/${adminId}/month`,
    );

    await adminPage.goto('/schedule/2098-07');
    await expect(adminPage.getByText('July 2098')).toBeVisible();
    await poolCard(adminPage, 'Admin User').click();
    await adminAvailability.seen;
    await poolCard(adminPage, 'Regular Employee').click();
    await expect(dayCell(adminPage, '2098-07-20')).toHaveCSS('background-color', UNAVAILABLE);

    await adminAvailability.releaseAndSettle();

    await expect(dayCell(adminPage, '2098-07-20')).toHaveCSS('background-color', UNAVAILABLE);
    await expect(dayCell(adminPage, '2098-07-10')).not.toHaveCSS('background-color', UNAVAILABLE);
  });

  // Review Focus 1.
  test('an employee deselected while their availability loads never paints under the next one', async ({
    adminPage,
    employeePage,
  }) => {
    const adminContext = adminPage.context();
    await createShiftGroupWithStreakRule(adminContext, { group: { name: 'Deselect Race Team' } });
    const adminId = await getEmployeeIdByEmail(adminContext, 'admin@shiftchanger.com');
    const regularId = await getEmployeeIdByEmail(adminContext, 'regular@shiftchanger.com');
    await createAvailability(adminContext, { type: 'unavailable', startDate: '2098-12-10' });
    await createAvailability(employeePage.context(), {
      type: 'unavailable',
      startDate: '2098-12-20',
    });

    const adminAvailability = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/user-availabilities/${adminId}/month`,
    );

    await adminPage.goto('/schedule/2098-12');
    await expect(adminPage.getByText('December 2098')).toBeVisible();
    const selectedPanel = adminPage.getByText('Availability for');
    await poolCard(adminPage, 'Admin User').click();
    await expect(selectedPanel).toBeVisible();
    await adminAvailability.seen;
    await poolCard(adminPage, 'Admin User').click(); // deselect
    await expect(selectedPanel).toHaveCount(0);
    await adminAvailability.releaseAndSettle();

    // Hold the next employee's load so only a stale result could paint anything right now.
    const regularAvailability = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/user-availabilities/${regularId}/month`,
    );
    await poolCard(adminPage, 'Regular Employee').click();
    await regularAvailability.seen;
    await expect(selectedPanel).toBeVisible();
    await expect(dayCell(adminPage, '2098-12-10')).not.toHaveCSS('background-color', UNAVAILABLE);

    await regularAvailability.releaseAndSettle();

    await expect(dayCell(adminPage, '2098-12-20')).toHaveCSS('background-color', UNAVAILABLE);
    await expect(dayCell(adminPage, '2098-12-10')).not.toHaveCSS('background-color', UNAVAILABLE);
  });
});
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `cd frontend && npx playwright test e2e/schedule/stale-overlays.spec.js`
Expected: 5 FAIL:
- delegations: `expect(februaryChip).toBeVisible()` after `releaseAndSettle()` fails (element not found — January's list replaced February's).
- newbies: `expect(aprilStar).toBeVisible()` after release fails.
- closures: the first `expect(juneClosure).toBeVisible()` (before release) fails — the overlay is never refetched on month change (M1).
- availability: `toHaveCSS('background-color', 'rgba(239, 68, 68, 0.18)')` on 2098-07-20 after release fails (received `rgba(0, 0, 0, 0)`).
- deselect: `not.toHaveCSS(...)` on 2098-12-10 before the second release fails (received the red background).
If any test passes here, stop: the test does not reproduce the race and must be fixed before touching the view.

- [ ] **Step 5: Implement the guards**

In `frontend/src/views/ScheduleView.vue` replace `fetchDelegations()` (and its comment) with:

```js
// Fetch delegations for the schedule overlay (also read by the drop-time delegation-conflict check).
// It runs after every schedule load and when the overlay is switched on; only the latest call may
// apply, so a slow previous month's list can't replace the month on screen.
const delegationRequests = useLatestRequest();

async function fetchDelegations() {
  const token = delegationRequests.begin();
  try {
    const res = await api.get('/api/delegations/month', { params: { month: monthKey.value } });
    if (!delegationRequests.isCurrent(token)) return;
    monthDelegations.value = res.data;
  } catch {
    if (delegationRequests.isCurrent(token)) monthDelegations.value = [];
  }
}
```

Replace `fetchNewbies()` with:

```js
const newbieRequests = useLatestRequest();

async function fetchNewbies() {
  const token = newbieRequests.begin();
  try {
    const res = await api.get('/api/newbie-periods/month', { params: { month: monthKey.value } });
    if (!newbieRequests.isCurrent(token)) return;
    monthNewbies.value = res.data;
  } catch {
    if (newbieRequests.isCurrent(token)) monthNewbies.value = [];
  }
}
```

Replace `fetchClosures()` (under `// Office closures fetch + helpers`) with:

```js
// Office closures fetch + helpers
const closureRequests = useLatestRequest();

async function fetchClosures() {
  const token = closureRequests.begin();
  try {
    const res = await api.get('/api/office-closures', { params: { month: monthKey.value } });
    if (!closureRequests.isCurrent(token)) return;
    monthClosures.value = res.data;
  } catch {
    if (closureRequests.isCurrent(token)) monthClosures.value = [];
  }
}
```

In `fetchSchedule()`'s success path (Task 2 version), add one line after `fetchShiftColors();`:

```js
    fetchDelegations(); // Always fetch for conflict checks
    fetchNewbies(); // Always fetch for newbie star display
    fetchShiftColors(); // Load global color definitions
    if (showClosures.value) fetchClosures(); // Keep the closure overlay on the displayed month
```

Replace `toggleEmployeeSelect`, `deselectEmployee` and `fetchAvailability` (under `// Availability highlighting`) with:

```js
// Availability highlighting. Clicking employees (or changing month) faster than their availability
// loads overlaps requests; only the latest may paint the calendar. Deselecting drops the in-flight
// one so it can't repopulate the highlight for whoever is selected next.
const availabilityRequests = useLatestRequest();

function toggleEmployeeSelect(emp) {
  if (selectedEmployee.value?.id === emp.id) {
    deselectEmployee();
  } else {
    selectedEmployee.value = emp;
    fetchAvailability(emp.id);
  }
}

function deselectEmployee() {
  availabilityRequests.invalidate();
  selectedEmployee.value = null;
  selectedAvailability.value = [];
}

async function fetchAvailability(userId) {
  const token = availabilityRequests.begin();
  try {
    const res = await api.get(`/api/user-availabilities/${userId}/month`, {
      params: { month: monthKey.value },
    });
    if (!availabilityRequests.isCurrent(token)) return;
    selectedAvailability.value = res.data;
  } catch {
    if (availabilityRequests.isCurrent(token)) selectedAvailability.value = [];
  }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd frontend && npx playwright test e2e/schedule/stale-overlays.spec.js e2e/schedule/navigation.spec.js`
Expected: PASS — 9 passed (5 new + 4 existing navigation tests).
Sabotage (re-insert the identical line after each):
- delete `if (!delegationRequests.isCurrent(token)) return;` → delegations test FAILS;
- delete `if (!closureRequests.isCurrent(token)) return;` → closures test FAILS at the post-release `juneClosure` assertion;
- delete `availabilityRequests.invalidate();` in `deselectEmployee` → deselect test FAILS;
- change the positive guard `'Marcin Kefir'` in the delegations test to `'Marcin Kefirr'` → FAILS; undo.

- [ ] **Step 7: Lint and commit**

```bash
cd frontend && npx biome check src/views/ScheduleView.vue e2e/fixtures/hold.js e2e/fixtures/api-setup.js e2e/schedule/stale-overlays.spec.js && cd ..
git add frontend/src/views/ScheduleView.vue frontend/e2e/fixtures/hold.js frontend/e2e/fixtures/api-setup.js frontend/e2e/schedule/stale-overlays.spec.js
git commit -m "fix: schedule overlays and availability ignore stale responses" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: OnCallView month and group switching (H3)

**Files:**
- Modify: `frontend/src/views/OnCallView.vue:147-152` (imports), `:217-232` (`fetchAssignments`)
- Modify: `frontend/e2e/fixtures/api-setup.js` (append `createOnCallAssignment`)
- Test: `frontend/e2e/on-call/on-call.spec.js` (add three tests)

**Interfaces:**
- Consumes: `useLatestRequest()` (Task 1); `holdRequests()` (Task 3); `createShiftGroupWithStreakRule`, `getEmployeeIdByEmail`.
- Produces: `createOnCallAssignment(context, { shiftGroupId, userId, startsAt, endsAt }) → assignment JSON` (datetimes as `YYYY-MM-DD HH:MM:SS`); OnCallView channel `assignmentRequests`.

- [ ] **Step 1: Add the API helper**

Append to `frontend/e2e/fixtures/api-setup.js`:

```js
export async function createOnCallAssignment(context, { shiftGroupId, userId, startsAt, endsAt }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', '/api/on-call-assignments', {
    data: { shift_group_id: shiftGroupId, user_id: userId, starts_at: startsAt, ends_at: endsAt },
    headers,
  });
  if (!res.ok()) throw new Error(`create on-call failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}
```

- [ ] **Step 2: Write the failing tests**

In `frontend/e2e/on-call/on-call.spec.js` replace the two import lines with:

```js
import {
  createOnCallAssignment,
  createShiftGroupWithStreakRule,
  getEmployeeIdByEmail,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';
```

Below `const GROUP = 'On-Call Team';` add:

```js
// Months 2098-08/2098-09 are reserved for the stale-response tests below (each uses its own group).
const isAssignments = (groupId, month) => (url) =>
  url.pathname === '/api/on-call-assignments' &&
  url.searchParams.get('shift_group_id') === String(groupId) &&
  url.searchParams.get('month') === month;

async function openOnCallInAugust2098(page, groupName) {
  await page.clock.setFixedTime(new Date('2098-08-15T12:00:00Z'));
  await page.goto('/on-call');
  await expect(page.getByText('August 2098', { exact: true })).toBeVisible();
  await page.getByRole('combobox').first().selectOption({ label: groupName });
}
```

Inside `test.describe('On-Call Duty', …)`, after the existing test, add:

```js
  test('a slow response for the previous month does not replace the month the user moved to', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'On-Call Month Race' },
    });
    const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    await createOnCallAssignment(context, {
      shiftGroupId: groupId,
      userId: regularId,
      startsAt: '2098-08-05 08:00:00',
      endsAt: '2098-08-05 20:00:00',
    });

    const august = await holdRequests(adminPage, isAssignments(groupId, '2098-08'));
    await openOnCallInAugust2098(adminPage, 'On-Call Month Race');
    await august.seen;
    await adminPage.getByLabel('Next month').click();
    const septemberEmpty = adminPage.getByText(
      'No on-call periods for this group in September 2098.',
    );
    await expect(septemberEmpty).toBeVisible();

    await august.releaseAndSettle();

    await expect(septemberEmpty).toBeVisible();
    await expect(adminPage.locator('p', { hasText: 'Regular Employee' })).toHaveCount(0);
  });

  test('a slow response for the previous group does not replace the group the user picked', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupA = await createShiftGroupWithStreakRule(context, {
      group: { name: 'On-Call Group Race A' },
    });
    const groupB = await createShiftGroupWithStreakRule(context, {
      group: { name: 'On-Call Group Race B' },
    });
    const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    await createOnCallAssignment(context, {
      shiftGroupId: groupA,
      userId: regularId,
      startsAt: '2098-08-06 08:00:00',
      endsAt: '2098-08-06 20:00:00',
    });

    const groupALoad = await holdRequests(adminPage, isAssignments(groupA, '2098-08'));
    await openOnCallInAugust2098(adminPage, 'On-Call Group Race A');
    await groupALoad.seen;
    await adminPage.getByRole('combobox').first().selectOption({ label: 'On-Call Group Race B' });
    const groupBEmpty = adminPage.getByText('No on-call periods for this group in August 2098.');
    await expect(groupBEmpty).toBeVisible();

    await groupALoad.releaseAndSettle();

    await expect(groupBEmpty).toBeVisible();
    await expect(adminPage.locator('p', { hasText: 'Regular Employee' })).toHaveCount(0);
    await expect(adminPage.getByRole('combobox').first()).toHaveValue(String(groupB));
  });

  // Review Focus 2.
  test('the loading state stays until the newest month has loaded', async ({ adminPage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'On-Call Loading Race' },
    });
    const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    await createOnCallAssignment(context, {
      shiftGroupId: groupId,
      userId: regularId,
      startsAt: '2098-08-07 08:00:00',
      endsAt: '2098-08-07 20:00:00',
    });

    const august = await holdRequests(adminPage, isAssignments(groupId, '2098-08'));
    const september = await holdRequests(adminPage, isAssignments(groupId, '2098-09'));
    await openOnCallInAugust2098(adminPage, 'On-Call Loading Race');
    await august.seen;
    await adminPage.getByLabel('Next month').click();
    await september.seen;
    const loadingText = adminPage.getByText('Loading…');
    await expect(loadingText).toBeVisible();

    // Let only August (older) finish; September is still in flight.
    const augustDone = adminPage.waitForResponse((res) =>
      isAssignments(groupId, '2098-08')(new URL(res.url())),
    );
    august.release();
    await (await augustDone).finished();
    await adminPage.evaluate(() => new Promise((resolve) => setTimeout(resolve, 100)));
    await expect(loadingText).toBeVisible();

    await september.releaseAndSettle();
    await expect(
      adminPage.getByText('No on-call periods for this group in September 2098.'),
    ).toBeVisible();
  });
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `cd frontend && npx playwright test e2e/on-call/on-call.spec.js`
Expected: the existing test PASSES; the three new tests FAIL — month and group tests at the post-release `toBeVisible()` of the empty-state text (August's/group A's list replaced it; `Regular Employee` row shown); the loading test at the second `expect(loadingText).toBeVisible()` (August's `finally` cleared it).

- [ ] **Step 4: Implement the guard**

In `frontend/src/views/OnCallView.vue` add the import after the `useConfirm` import:

```js
import { confirm } from '../composables/useConfirm.js';
import { useLatestRequest } from '../composables/useLatestRequest.js';
import { useToast } from '../composables/useToast.js';
```

Replace `fetchAssignments()` with:

```js
// Group changes and month steps overlap fetches. Only the latest may apply its list or clear
// `loading` — otherwise an older response shows another month's or group's on-call periods under
// the current label. The early return also takes a token so it supersedes older requests.
const assignmentRequests = useLatestRequest();

async function fetchAssignments() {
  const token = assignmentRequests.begin();
  if (!selectedGroupId.value) {
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const res = await api.get('/api/on-call-assignments', {
      params: { shift_group_id: selectedGroupId.value, month: monthKey.value },
    });
    if (!assignmentRequests.isCurrent(token)) return;
    assignments.value = res.data;
  } catch {
    if (assignmentRequests.isCurrent(token)) assignments.value = [];
  } finally {
    if (assignmentRequests.isCurrent(token)) loading.value = false;
  }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd frontend && npx playwright test e2e/on-call/on-call.spec.js`
Expected: PASS — 4 passed.
Sabotage: delete `if (!assignmentRequests.isCurrent(token)) return;` → month and group tests FAIL; re-insert. Change the `finally` line to `loading.value = false;` → loading test FAILS; restore `if (assignmentRequests.isCurrent(token)) loading.value = false;`.

- [ ] **Step 6: Lint and commit**

```bash
cd frontend && npx biome check src/views/OnCallView.vue e2e/on-call/on-call.spec.js e2e/fixtures/api-setup.js && cd ..
git add frontend/src/views/OnCallView.vue frontend/e2e/on-call/on-call.spec.js frontend/e2e/fixtures/api-setup.js
git commit -m "fix: on-call view ignores stale month and group responses" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: OfficeAvailabilityView closures (H4)

**Files:**
- Modify: `frontend/src/views/OfficeAvailabilityView.vue:13,17` (month buttons get `aria-label`), `:155-160` (imports), `:258-265` (`fetchClosures`)
- Test: `frontend/e2e/office-availability/closures.spec.js` (create)

**Interfaces:**
- Consumes: `useLatestRequest()` (Task 1); `holdRequests()`, `createOfficeClosure()` (Task 3).
- Produces: OfficeAvailabilityView channel `closureRequests`; the month buttons are reachable as `getByLabel('Previous month')` / `getByLabel('Next month')`.

- [ ] **Step 1: Write the failing test**

Create `frontend/e2e/office-availability/closures.spec.js`:

```js
import { createOfficeClosure } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

// Months 2098-10/2098-11 are reserved for this file (office closures are global, not per group).
test.describe('Office Availability', () => {
  test('a slow load of the previous month does not show its closures under the next month', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    await createOfficeClosure(context, { startDate: '2098-10-10', title: 'October Race Closure' });
    await createOfficeClosure(context, { startDate: '2098-11-10', title: 'November Race Closure' });

    const october = await holdRequests(
      adminPage,
      (url) => url.pathname === '/api/office-closures' && url.searchParams.get('month') === '2098-10',
    );

    await adminPage.clock.setFixedTime(new Date('2098-10-15T12:00:00Z'));
    await adminPage.goto('/office-availability');
    await october.seen;
    await adminPage.getByLabel('Next month').click();
    await expect(adminPage.getByText('November 2098', { exact: true })).toBeVisible();
    const november = adminPage.getByText('November Race Closure').first();
    await expect(november).toBeVisible();

    await october.releaseAndSettle();

    await expect(november).toBeVisible();
    await expect(adminPage.getByText('October Race Closure')).toHaveCount(0);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx playwright test e2e/office-availability/closures.spec.js`
Expected: FAIL at `getByLabel('Next month')` (timeout, no element with that label). Add the labels now (Step 3, first part) and re-run: it then FAILS at the post-release `expect(november).toBeVisible()` — October's closures replaced November's. Only that second failure proves the race; continue only once you have seen it.

- [ ] **Step 3: Implement the labels and the guard**

In `frontend/src/views/OfficeAvailabilityView.vue` give the two month buttons labels:

```html
        <button @click="prevMonth" aria-label="Previous month" class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
```

```html
        <button @click="nextMonth" aria-label="Next month" class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
```

Add the import:

```js
import { ref, computed, watch, onMounted } from 'vue';
import { confirm } from '../composables/useConfirm.js';
import { useLatestRequest } from '../composables/useLatestRequest.js';
import { useToast } from '../composables/useToast.js';
```

Replace `fetchClosures()` with:

```js
// Month steps and the refetch after a save/delete overlap; only the latest may apply, so an older
// month's closures never show under the current month's label.
const closureRequests = useLatestRequest();

async function fetchClosures() {
  const token = closureRequests.begin();
  try {
    const res = await api.get('/api/office-closures', { params: { month: monthKey.value } });
    if (!closureRequests.isCurrent(token)) return;
    closures.value = res.data;
  } catch {
    if (closureRequests.isCurrent(token)) closures.value = [];
  }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd frontend && npx playwright test e2e/office-availability/closures.spec.js`
Expected: PASS — 1 passed.
Sabotage: delete `if (!closureRequests.isCurrent(token)) return;` → FAIL; re-insert. Change the guard text `'November Race Closure'` to `'November Race Closures'` → FAIL; undo.

- [ ] **Step 5: Lint and commit**

```bash
cd frontend && npx biome check src/views/OfficeAvailabilityView.vue e2e/office-availability/closures.spec.js && cd ..
git add frontend/src/views/OfficeAvailabilityView.vue frontend/e2e/office-availability/closures.spec.js
git commit -m "fix: office availability ignores a stale month's closures" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: MyAvailabilityView delegations and entries (H5, M9)

**Files:**
- Modify: `frontend/src/views/MyAvailabilityView.vue:13,17` (month buttons `aria-label`), `:29` (day cell `data-date`), `:152-157` (imports), `:253-299` (`fetchEntries`, `saveEntry`, `deleteEntry`), `:306-313` (`fetchDelegations`)
- Test: `frontend/e2e/my-availability/my-availability.spec.js` (create)

**Interfaces:**
- Consumes: `useLatestRequest()` (Task 1); `holdRequests()`, `createDelegation()` (Task 3); `getEmployeeIdByEmail`.
- Produces: channels `entriesRequests`, `delegationRequests`; day cells carry `data-date="YYYY-MM-DD"`; month buttons labelled `Previous month` / `Next month`.

Note on H5: `/api/my-delegations` ignores `month` and returns the user's delegations of all time, so an older *successful* response carries the same list. The observable stale path is a superseded request that *fails*: its `catch` blanks the delegations the user is looking at. The test therefore aborts the held request.

- [ ] **Step 1: Write the failing tests**

Create `frontend/e2e/my-availability/my-availability.spec.js`:

```js
import { createDelegation, getEmployeeIdByEmail } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

// Months 2097-01/02 (delegations) and 2097-03/04 (availability entries) are reserved for this file.
test.describe('My Availability', () => {
  test('a failed load for the previous month does not wipe the delegation shown for the new month', async ({
    adminPage,
    employeePage,
  }) => {
    const regularId = await getEmployeeIdByEmail(adminPage.context(), 'regular@shiftchanger.com');
    await createDelegation(adminPage.context(), {
      name: 'Avail Race Trip',
      destination: 'Avail City',
      start: '2097-02-10',
      end: '2097-02-12',
      userIds: [regularId],
    });

    const january = await holdRequests(
      employeePage,
      (url) =>
        url.pathname === '/api/my-delegations' && url.searchParams.get('month') === '2097-01',
      { mode: 'abort' },
    );

    await employeePage.clock.setFixedTime(new Date('2097-01-15T12:00:00Z'));
    await employeePage.goto('/my-availability');
    await january.seen;
    await employeePage.getByLabel('Next month').click();
    await expect(employeePage.getByText('February 2097', { exact: true })).toBeVisible();
    const trip = employeePage.locator('[data-date="2097-02-10"]').getByText('Avail Race Trip');
    await expect(trip).toBeVisible();

    await january.releaseAndSettle();

    await expect(trip).toBeVisible();
  });

  test('an entry saved while an older reload is in flight stays on the calendar', async ({
    employeePage,
  }) => {
    await employeePage.clock.setFixedTime(new Date('2097-03-15T12:00:00Z'));
    await employeePage.goto('/my-availability');
    await expect(employeePage.getByText('March 2097', { exact: true })).toBeVisible();
    await employeePage.waitForLoadState('networkidle');

    // The month change reloads the entries; the server answers before the save below, but the page
    // only receives that (now stale) list after the save has been shown.
    const reload = await holdRequests(
      employeePage,
      (url) => url.pathname === '/api/user-availabilities',
      { mode: 'snapshot', times: 1 },
    );
    await employeePage.getByLabel('Next month').click();
    await expect(employeePage.getByText('April 2097', { exact: true })).toBeVisible();
    await reload.seen;

    const day = employeePage.locator('[data-date="2097-04-10"]');
    await day.click();
    await employeePage.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(employeePage.getByText('Availability saved.')).toBeVisible();
    await expect(day.getByText('All day')).toBeVisible();

    await reload.releaseAndSettle();

    await expect(day.getByText('All day')).toBeVisible();

    // Persistence: after a reload the entry is still there.
    await employeePage.reload();
    await expect(employeePage.getByText('March 2097', { exact: true })).toBeVisible();
    await employeePage.getByLabel('Next month').click();
    await expect(day.getByText('All day')).toBeVisible();
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd frontend && npx playwright test e2e/my-availability/my-availability.spec.js`
Expected: both FAIL at `getByLabel('Next month')` (no labels yet). Add the two `aria-label`s and the `data-date` attribute now (first part of Step 3) and re-run: the delegation test then FAILS at the post-release `expect(trip).toBeVisible()` (the stale `catch` blanked the list), and the entry test FAILS at the post-release `expect(day.getByText('All day')).toBeVisible()` (the stale list dropped the saved entry). Continue only once you have seen those two failures.

- [ ] **Step 3: Implement the hooks and the guards**

In `frontend/src/views/MyAvailabilityView.vue` label the month buttons:

```html
        <button @click="prevMonth" aria-label="Previous month" class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
```

```html
        <button @click="nextMonth" aria-label="Next month" class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
```

and give each day cell its date (the `<div v-for="(day, di) in week" :key="di"` line):

```html
          <div v-for="(day, di) in week" :key="di" :data-date="day.dateStr"
```

Add the import:

```js
import { confirm } from '../composables/useConfirm.js';
import { useLatestRequest } from '../composables/useLatestRequest.js';
import { useToast } from '../composables/useToast.js';
```

Replace `fetchEntries()`, `saveEntry()` and `deleteEntry()` with:

```js
// The entries list is reloaded on every month change. A reload that started before a save/delete
// would resurrect a deleted entry or drop an added one, so local mutations invalidate it, and only
// the latest reload may apply or report an error.
const entriesRequests = useLatestRequest();

async function fetchEntries() {
  const token = entriesRequests.begin();
  try {
    const res = await api.get('/api/user-availabilities');
    if (!entriesRequests.isCurrent(token)) return;
    entries.value = res.data;
  } catch {
    if (entriesRequests.isCurrent(token)) showToast('Failed to load availability.', 'error');
  }
}

async function saveEntry() {
  saving.value = true;
  try {
    const payload = {
      type: form.value.type,
      start_date: form.value.start_date,
      end_date: form.value.end_date,
      start_time: useHours.value ? form.value.start_time : null,
      end_time: useHours.value ? form.value.end_time : null,
      note: form.value.note || null,
    };
    const res = await api.post('/api/user-availabilities', payload);
    entriesRequests.invalidate();
    entries.value.push(res.data);
    showModal.value = false;
    showToast('Availability saved.');
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to save.', 'error');
  } finally {
    saving.value = false;
  }
}

async function deleteEntry(entry) {
  const ok = await confirm({
    title: 'Remove Entry',
    message: 'Remove this availability entry?',
    confirmLabel: 'Remove',
    danger: true,
  });
  if (!ok) return;
  try {
    await api.delete(`/api/user-availabilities/${entry.id}`);
    entriesRequests.invalidate();
    entries.value = entries.value.filter((e) => e.id !== entry.id);
    showToast('Entry removed.');
  } catch {
    showToast('Failed to remove.', 'error');
  }
}
```

Replace `fetchDelegations()` with:

```js
// Month changes overlap delegation loads; only the latest may apply or blank the list (a failing
// superseded request must not wipe the month on screen).
const delegationRequests = useLatestRequest();

async function fetchDelegations() {
  const token = delegationRequests.begin();
  try {
    const res = await api.get('/api/my-delegations', { params: { month: monthKey.value } });
    if (!delegationRequests.isCurrent(token)) return;
    delegations.value = res.data;
  } catch {
    if (delegationRequests.isCurrent(token)) delegations.value = [];
  }
}
```

(`entriesRequests` and `delegationRequests` are `const`s declared before `onMounted`/`watch` call them; `fetchDelegations` is defined after `onMounted(...)` in this file, which is fine because the mount hook runs after setup.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd frontend && npx playwright test e2e/my-availability/my-availability.spec.js`
Expected: PASS — 2 passed.
Sabotage: change the delegation `catch` body to `delegations.value = [];` → delegation test FAILS; restore `if (delegationRequests.isCurrent(token)) delegations.value = [];`. Delete `entriesRequests.invalidate();` in `saveEntry` → entry test FAILS; re-insert.

- [ ] **Step 5: Lint and commit**

```bash
cd frontend && npx biome check src/views/MyAvailabilityView.vue e2e/my-availability/my-availability.spec.js && cd ..
git add frontend/src/views/MyAvailabilityView.vue frontend/e2e/my-availability/my-availability.spec.js
git commit -m "fix: my availability keeps the current month's delegations and saved entries" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: AlarmsView filters and paging (H6)

**Files:**
- Modify: `frontend/src/views/AlarmsView.vue:123-125` (imports), `:144-161` (`fetchAlarms`)
- Modify: `frontend/e2e/fixtures/api-setup.js` (append `createProcedure`, `askFaqQuestion`, `reportProcedureIssue`)
- Test: `frontend/e2e/alarms/alarms.spec.js` (create)

**Interfaces:**
- Consumes: `useLatestRequest()` (Task 1); `holdRequests()` (Task 3); `getEmployeeIdByEmail`.
- Produces: `createProcedure(context, { title, ownerId, content? }) → procedure JSON` (used by Tasks 8, 12–15); `askFaqQuestion(context, procedureId, question) → item JSON`; `reportProcedureIssue(context, procedureId, description) → feedback JSON`; AlarmsView channel `alarmRequests`.

- [ ] **Step 1: Add the API helpers**

Append to `frontend/e2e/fixtures/api-setup.js`:

```js
export async function createProcedure(context, { title, ownerId, content = `<p>${title}</p>` }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', '/api/procedures', {
    data: { title, owner_id: ownerId, content },
    headers,
  });
  if (!res.ok()) throw new Error(`create procedure failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

// Notifies the procedure owner (alarm type PROCEDURE_NEW_QUESTION_ADDED) when asked by someone else.
export async function askFaqQuestion(context, procedureId, question) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', `/api/procedures/${procedureId}/faq`, {
    data: { question },
    headers,
  });
  if (!res.ok()) throw new Error(`ask FAQ question failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

// Notifies the procedure owner (alarm type PROCEDURE_NEW_ISSUE_REPORTED) when reported by someone else.
export async function reportProcedureIssue(context, procedureId, description) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', `/api/procedures/${procedureId}/feedback`, {
    data: { type: 'error', description },
    headers,
  });
  if (!res.ok()) throw new Error(`report issue failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}
```

- [ ] **Step 2: Write the failing test**

Create `frontend/e2e/alarms/alarms.spec.js`:

```js
import {
  askFaqQuestion,
  createProcedure,
  getEmployeeIdByEmail,
  reportProcedureIssue,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

test.describe('Notifications', () => {
  test('a slow unfiltered load does not replace the filtered list', async ({
    adminPage,
    employeePage,
  }) => {
    const adminContext = adminPage.context();
    const adminId = await getEmployeeIdByEmail(adminContext, 'admin@shiftchanger.com');
    const procedure = await createProcedure(adminContext, {
      title: 'Alarm Race Proc',
      ownerId: adminId,
    });
    // The employee's question and issue report each raise an alarm for the owner (admin).
    await askFaqQuestion(employeePage.context(), procedure.id, 'How does the alarm race work?');
    await reportProcedureIssue(employeePage.context(), procedure.id, 'The alarm race step is wrong.');

    const unfiltered = await holdRequests(
      adminPage,
      (url) => url.pathname === '/api/alarms' && !url.searchParams.has('type'),
      { times: 1 },
    );

    await adminPage.goto('/alarms');
    await unfiltered.seen;
    await adminPage
      .locator('select')
      .filter({ has: adminPage.locator('option[value="PROCEDURE_NEW_QUESTION_ADDED"]') })
      .selectOption('PROCEDURE_NEW_QUESTION_ADDED');
    const question = adminPage.getByText('New question on procedure "Alarm Race Proc"');
    await expect(question).toBeVisible();

    await unfiltered.releaseAndSettle();

    await expect(question).toBeVisible();
    await expect(adminPage.getByText('New issue reported on "Alarm Race Proc"')).toHaveCount(0);
  });
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd frontend && npx playwright test e2e/alarms/alarms.spec.js`
Expected: FAIL at the final `toHaveCount(0)` — received 1: the unfiltered page landed last and shows the issue alarm under the "New Question" filter.

- [ ] **Step 4: Implement the guard**

In `frontend/src/views/AlarmsView.vue` extend the imports:

```js
import { ref, computed, watch, onMounted } from 'vue';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import { useLatestRequest } from '../composables/useLatestRequest.js';
```

Replace `fetchAlarms()` with:

```js
// Filter and page changes overlap fetches (a filter change on page > 1 even fires two). Only the
// latest may apply its page, total or clear `loading`, so the list always matches the filter bar.
const alarmRequests = useLatestRequest();

async function fetchAlarms() {
  const token = alarmRequests.begin();
  loading.value = true;
  try {
    const params = { page: page.value };
    if (filterType.value) params.type = filterType.value;
    if (filterStatus.value) params.status = filterStatus.value;
    if (filterDateFrom.value) params.date_from = filterDateFrom.value;
    if (filterDateTo.value) params.date_to = filterDateTo.value;
    const res = await api.get('/api/alarms', { params });
    if (!alarmRequests.isCurrent(token)) return;
    alarms.value = res.data.data;
    totalPages.value = res.data.last_page;
  } catch {
    if (alarmRequests.isCurrent(token)) alarms.value = [];
  } finally {
    if (alarmRequests.isCurrent(token)) loading.value = false;
  }
}
```

(The audit's optional "skip the duplicate fetch on filter change at page > 1" is not done: with the guard the duplicate is harmless, and removing it changes watcher behaviour — YAGNI.)

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd frontend && npx playwright test e2e/alarms/alarms.spec.js`
Expected: PASS — 1 passed.
Sabotage: delete `if (!alarmRequests.isCurrent(token)) return;` → FAIL; re-insert. Change the guard text `"Alarm Race Proc"` in `question` to `"Alarm Race Proc2"` → FAIL; undo.

- [ ] **Step 6: Lint and commit**

```bash
cd frontend && npx biome check src/views/AlarmsView.vue e2e/alarms/alarms.spec.js e2e/fixtures/api-setup.js && cd ..
git add frontend/src/views/AlarmsView.vue frontend/e2e/alarms/alarms.spec.js frontend/e2e/fixtures/api-setup.js
git commit -m "fix: notifications list ignores stale filter and page responses" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: ChecklistHistoryView filters, detail and procedure preview (H7, M2, M3 history half)

**Files:**
- Modify: `frontend/src/views/ChecklistHistoryView.vue:425` (preview close button `aria-label`), `:451-455` (imports), `:597-646` (`fetchData`, `viewDetail`), `:750-759` (`openProcedureModal`)
- Modify: `frontend/e2e/fixtures/api-setup.js` (append `createChecklistTemplate`, `createChecklistSubmission`)
- Test: `frontend/e2e/checklists/history.spec.js` (create)

**Interfaces:**
- Consumes: `useLatestRequest()` (Task 1); `holdRequests()` (Task 3); `createProcedure()` (Task 7); `getEmployeeIdByEmail`.
- Produces: `createChecklistTemplate(context, { name, requiresConfirmation?, procedureIds? }) → template JSON with groups[].items[]` (one group; one checkbox item, or one item per procedure id); `createChecklistSubmission(context, { template, date, outgoingUserId, incomingUserId? }) → submission JSON` (status `pending_confirmation` when `incomingUserId` is set and the template has a confirmation item, else `completed`). Used by Tasks 9 and 10. Channels `submissionsRequests`, `detailRequests`, `previewRequests`.

- [ ] **Step 1: Add the API helpers**

Append to `frontend/e2e/fixtures/api-setup.js`:

```js
export async function createChecklistTemplate(
  context,
  { name, requiresConfirmation = false, procedureIds = [] },
) {
  const headers = await getXsrfHeader(context);
  const items = procedureIds.length
    ? procedureIds.map((procedureId, i) => ({
        label: `${name} item ${i + 1}`,
        type: 'checkbox',
        requires_confirmation: requiresConfirmation,
        procedure_id: procedureId,
      }))
    : [{ label: `${name} item`, type: 'checkbox', requires_confirmation: requiresConfirmation }];
  const res = await send(context, 'post', '/api/checklist-templates', {
    data: { name, is_active: true, groups: [{ name: `${name} group`, items }] },
    headers,
  });
  if (!res.ok()) throw new Error(`create template failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

export async function createChecklistSubmission(
  context,
  { template, date, outgoingUserId, incomingUserId = null },
) {
  const headers = await getXsrfHeader(context);
  const responses = template.groups
    .flatMap((group) => group.items)
    .map((item) => ({ checklist_item_id: item.id, value: 'true' }));
  const res = await send(context, 'post', '/api/checklist-submissions', {
    data: {
      checklist_template_id: template.id,
      date,
      outgoing_user_id: outgoingUserId,
      incoming_user_id: incomingUserId,
      responses,
    },
    headers,
  });
  if (!res.ok()) throw new Error(`create submission failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}
```

- [ ] **Step 2: Write the failing tests**

Create `frontend/e2e/checklists/history.spec.js`:

```js
import {
  createChecklistSubmission,
  createChecklistTemplate,
  createProcedure,
  getEmployeeIdByEmail,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

// Submission dates in 2097-05 are reserved for the checklist specs.
test.describe('Checklist archive', () => {
  test('a slow unfiltered load does not replace the filtered archive', async ({ adminPage }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const done = await createChecklistTemplate(context, { name: 'Hist Race Done' });
    const pending = await createChecklistTemplate(context, {
      name: 'Hist Race Pending',
      requiresConfirmation: true,
    });
    await createChecklistSubmission(context, {
      template: done,
      date: '2097-05-01',
      outgoingUserId: adminId,
    });
    await createChecklistSubmission(context, {
      template: pending,
      date: '2097-05-01',
      outgoingUserId: adminId,
      incomingUserId: regularId,
    });

    const unfiltered = await holdRequests(
      adminPage,
      (url) => url.pathname === '/api/checklist-submissions' && !url.searchParams.has('status'),
      { times: 1 },
    );

    await adminPage.goto('/checklist/history');
    await unfiltered.seen;
    await adminPage
      .locator('select')
      .filter({ has: adminPage.locator('option[value="pending_confirmation"]') })
      .selectOption('pending_confirmation');
    await adminPage.getByRole('button', { name: 'Apply' }).click();
    const pendingRow = adminPage.getByRole('cell', { name: 'Hist Race Pending' });
    await expect(pendingRow).toBeVisible();

    await unfiltered.releaseAndSettle();

    await expect(pendingRow).toBeVisible();
    await expect(adminPage.getByRole('cell', { name: 'Hist Race Done' })).toHaveCount(0);
  });

  test('clicking a second submission before the first opens shows the second', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const a = await createChecklistTemplate(context, { name: 'Hist Detail A' });
    const b = await createChecklistTemplate(context, { name: 'Hist Detail B' });
    const submissionA = await createChecklistSubmission(context, {
      template: a,
      date: '2097-05-02',
      outgoingUserId: adminId,
    });
    await createChecklistSubmission(context, {
      template: b,
      date: '2097-05-02',
      outgoingUserId: adminId,
    });

    const slowA = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/checklist-submissions/${submissionA.id}`,
    );

    await adminPage.goto('/checklist/history');
    await expect(adminPage.getByRole('cell', { name: 'Hist Detail A' })).toBeVisible();
    await adminPage.getByRole('cell', { name: 'Hist Detail A' }).click();
    await slowA.seen;
    await adminPage.getByRole('cell', { name: 'Hist Detail B' }).click();
    const detailB = adminPage.getByRole('heading', { level: 2, name: 'Hist Detail B' });
    await expect(detailB).toBeVisible();

    await slowA.releaseAndSettle();

    await expect(detailB).toBeVisible();
    await expect(adminPage.getByRole('heading', { level: 2, name: 'Hist Detail A' })).toHaveCount(0);
  });

  test('closing a slow procedure preview and opening another keeps the second', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const one = await createProcedure(context, { title: 'Hist Proc One', ownerId: adminId });
    const two = await createProcedure(context, { title: 'Hist Proc Two', ownerId: adminId });
    const template = await createChecklistTemplate(context, {
      name: 'Hist Race Procs',
      procedureIds: [one.id, two.id],
    });
    await createChecklistSubmission(context, {
      template,
      date: '2097-05-03',
      outgoingUserId: adminId,
    });

    const slowOne = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/procedures/${one.id}`,
    );

    await adminPage.goto('/checklist/history');
    await adminPage.getByRole('cell', { name: 'Hist Race Procs' }).click();
    await expect(adminPage.getByRole('heading', { level: 2, name: 'Hist Race Procs' })).toBeVisible();
    await adminPage.getByTitle(/Hist Proc One$/).click();
    await slowOne.seen;
    await adminPage.getByLabel('Close procedure preview').click();
    await adminPage.getByTitle(/Hist Proc Two$/).click();
    const previewTwo = adminPage.getByRole('heading', { level: 2, name: 'Hist Proc Two' });
    await expect(previewTwo).toBeVisible();

    await slowOne.releaseAndSettle();

    await expect(previewTwo).toBeVisible();
    await expect(adminPage.getByRole('heading', { level: 2, name: 'Hist Proc One' })).toHaveCount(0);
  });
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `cd frontend && npx playwright test e2e/checklists/history.spec.js`
Expected: the filter test FAILS at the final `toHaveCount(0)` (received 1); the detail test FAILS at the post-release `expect(detailB).toBeVisible()` (the heading now says Hist Detail A); the preview test FAILS at `getByLabel('Close procedure preview')` (no such label yet). Add the `aria-label` (first part of Step 4), re-run, and see the preview test FAIL at the post-release `expect(previewTwo).toBeVisible()`. Continue only once all three race failures were seen.

- [ ] **Step 4: Implement the label and the guards**

In `frontend/src/views/ChecklistHistoryView.vue`, the preview header's close button (the `<button @click="showProcedureModal = false"` next to the "Open full page" link) gets a label:

```html
                <button @click="showProcedureModal = false" aria-label="Close procedure preview" class="p-2 text-slate-400 hover:text-white transition-colors">
```

Extend the imports:

```js
import ProcedureContent from '../components/procedure/ProcedureContent.vue';
import { useLatestRequest } from '../composables/useLatestRequest.js';
```

Replace `fetchData()` and `viewDetail()` with:

```js
// Apply / Reset overlap fetches (and the unfiltered mount fetch can land after an early Apply).
// Only the latest may apply its rows, clear them on error or clear `loading`.
const submissionsRequests = useLatestRequest();

// Fetch data
async function fetchData() {
  const token = submissionsRequests.begin();
  loading.value = true;
  try {
    const params = { per_page: 1000 };
    if (filters.date_from) params.date_from = filters.date_from;
    if (filters.date_to) params.date_to = filters.date_to;
    if (filters.status) params.status = filters.status;
    if (filters.template_id) params.template_id = filters.template_id;
    if (filters.user_id) params.user_id = filters.user_id;

    const [subRes, tplRes, empRes] = await Promise.all([
      api.get('/api/checklist-submissions', { params }),
      api.get('/api/checklist-templates'),
      api.get('/api/employees'),
    ]);
    if (!submissionsRequests.isCurrent(token)) return;
    submissions.value = subRes.data.data || subRes.data || [];
    templates.value = tplRes.data || [];
    employees.value = empRes.data || [];
  } catch {
    if (submissionsRequests.isCurrent(token)) submissions.value = [];
  } finally {
    if (submissionsRequests.isCurrent(token)) loading.value = false;
  }
}
```

```js
// Detail: clicking a second row before the first detail arrives must show the second.
const detailRequests = useLatestRequest();

async function viewDetail(id) {
  const token = detailRequests.begin();
  try {
    const response = await api.get(`/api/checklist-submissions/${id}`);
    if (detailRequests.isCurrent(token)) detailData.value = response.data;
  } catch {}
}
```

Replace `openProcedureModal()` (at the end of the script) with:

```js
// Opening another procedure before the previous preview arrived supersedes it; a stale failure
// must not close the preview the user is now looking at.
const previewRequests = useLatestRequest();

async function openProcedureModal(procedureId) {
  const token = previewRequests.begin();
  showProcedureModal.value = true;
  procedurePreview.value = null;
  try {
    const res = await api.get(`/api/procedures/${procedureId}`);
    if (!previewRequests.isCurrent(token)) return;
    procedurePreview.value = res.data;
    setTimeout(() => procedureContentRef.value?.resetTab(), 0);
  } catch {
    if (previewRequests.isCurrent(token)) showProcedureModal.value = false;
  }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd frontend && npx playwright test e2e/checklists/history.spec.js`
Expected: PASS — 3 passed.
Sabotage (re-insert each exact line afterwards): delete `if (!submissionsRequests.isCurrent(token)) return;` → filter test FAILS; change `if (detailRequests.isCurrent(token)) detailData.value = response.data;` to `detailData.value = response.data;` → detail test FAILS; delete `if (!previewRequests.isCurrent(token)) return;` → preview test FAILS.

- [ ] **Step 6: Lint and commit**

```bash
cd frontend && npx biome check src/views/ChecklistHistoryView.vue e2e/checklists/history.spec.js e2e/fixtures/api-setup.js && cd ..
git add frontend/src/views/ChecklistHistoryView.vue frontend/e2e/checklists/history.spec.js frontend/e2e/fixtures/api-setup.js
git commit -m "fix: checklist archive ignores stale filter, detail and preview responses" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: ChecklistFillView shifts, confirmations, shift info and preview (H10, H11, M8, M3 fill half)

**Files:**
- Modify: `frontend/src/views/ChecklistFillView.vue:23` (date input `@change`), `:522-529` (imports), `:646-658` (`fetchShiftsForDate`), `:685-722` (`startFilling`), `:849-860` (`openConfirmation`), `:930-940` (`openProcedureModal`)
- Test: `frontend/e2e/checklists/fill.spec.js` (create)

**Interfaces:**
- Consumes: `useLatestRequest()` (Task 1); `holdRequests()` (Task 3); `createChecklistTemplate()`, `createChecklistSubmission()` (Task 8); `createShiftGroupWithStreakRule`, `generateMonthShifts`, `getEmployeeIdByEmail`.
- Produces: channels `shiftsRequests`, `shiftInfoRequests`, `confirmationRequests`, `previewRequests`.

No e2e test for M8 (shift info in `startFilling`) or the Fill half of M3 (`openProcedureModal`). Reason (not cheap): reaching either needs a full setup → fill → back → setup → fill round trip with a procedure-linked, shift-bound template, while the change is the same guard shape as the tested H10/H11 here and the identical, tested History `openProcedureModal` (Task 8). The reviewer checks these two by reading the diff.

- [ ] **Step 1: Write the failing tests**

Create `frontend/e2e/checklists/fill.spec.js`:

```js
import {
  createChecklistSubmission,
  createChecklistTemplate,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

// Dates in 2097-05/2097-06 are reserved for the checklist specs: 2097-05 has no shifts,
// 2097-06 is generated for 'Fill Race Team'.
test.describe('Fill shift checklist', () => {
  test('a slow shift list for the previous date does not replace the chosen date’s shifts', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Fill Race Team' },
    });
    await generateMonthShifts(context, { monthKey: '2097-06', shiftGroupId: groupId });

    const mayDay = await holdRequests(
      adminPage,
      (url) =>
        url.pathname === '/api/shifts-by-date' && url.searchParams.get('date') === '2097-05-12',
    );

    await adminPage.goto('/checklist/fill');
    await expect(adminPage.getByRole('heading', { name: 'Fill Shift Checklist' })).toBeVisible();
    await adminPage.waitForLoadState('networkidle');
    const date = adminPage.locator('input[type="date"]');
    await date.fill('2097-05-12');
    await mayDay.seen;
    await date.fill('2097-06-12');
    const shiftOption = adminPage.locator('option', { hasText: 'Shift 1' });
    await expect(shiftOption).toHaveCount(1);

    await mayDay.releaseAndSettle();

    await expect(shiftOption).toHaveCount(1);
    await expect(date).toHaveValue('2097-06-12');
  });

  test('opening one pending confirmation and then another shows the second', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const a = await createChecklistTemplate(context, {
      name: 'Confirm Race A',
      requiresConfirmation: true,
    });
    const b = await createChecklistTemplate(context, {
      name: 'Confirm Race B',
      requiresConfirmation: true,
    });
    const submissionA = await createChecklistSubmission(context, {
      template: a,
      date: '2097-05-04',
      outgoingUserId: regularId,
      incomingUserId: adminId,
    });
    await createChecklistSubmission(context, {
      template: b,
      date: '2097-05-04',
      outgoingUserId: regularId,
      incomingUserId: adminId,
    });

    const slowA = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/checklist-submissions/${submissionA.id}`,
    );

    await adminPage.goto('/checklist/fill');
    await adminPage.getByRole('button', { name: 'View Pending Confirmations' }).click();
    await adminPage.getByRole('heading', { level: 3, name: 'Confirm Race A' }).click();
    await slowA.seen;
    await adminPage.getByRole('heading', { level: 3, name: 'Confirm Race B' }).click();
    const detailB = adminPage.getByRole('heading', { level: 1, name: 'Confirm Race B' });
    await expect(detailB).toBeVisible();

    await slowA.releaseAndSettle();

    await expect(detailB).toBeVisible();
    await expect(adminPage.getByRole('heading', { level: 1, name: 'Confirm Race A' })).toHaveCount(0);
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd frontend && npx playwright test e2e/checklists/fill.spec.js`
Expected: both FAIL — the shifts test at the post-release `toHaveCount(1)` (received 0: May's empty list replaced June's), the confirmation test at the post-release `expect(detailB).toBeVisible()` (the detail now shows Confirm Race A).

- [ ] **Step 3: Implement the guards**

In `frontend/src/views/ChecklistFillView.vue`, call the date handler without the DOM event (it was passed in as `preserveShiftId`, which kept a previous date's shift selected):

```html
            <input v-model="setup.date" type="date" @change="fetchShiftsForDate()"
```

Add the import:

```js
import { useToast } from '../composables/useToast.js';
import { useLatestRequest } from '../composables/useLatestRequest.js';
```

Replace `fetchShiftsForDate()` with:

```js
// Changing the date twice quickly overlaps loads; only the latest date's shifts may be offered
// (otherwise the user can start a checklist for the wrong day's shift).
const shiftsRequests = useLatestRequest();

async function fetchShiftsForDate(preserveShiftId = null) {
  const token = shiftsRequests.begin();
  availableShifts.value = [];
  if (!preserveShiftId) setup.shiftId = null;
  if (!setup.date) return;
  try {
    const res = await api.get('/api/shifts-by-date', { params: { date: setup.date } });
    if (!shiftsRequests.isCurrent(token)) return;
    availableShifts.value = res.data;
    // Restore shiftId if preserved from query params
    if (preserveShiftId && res.data.some((s) => s.id === preserveShiftId)) {
      setup.shiftId = preserveShiftId;
    }
  } catch {}
}
```

Replace `startFilling()` with:

```js
// Going back and starting again before the shift info arrived supersedes the earlier start.
const shiftInfoRequests = useLatestRequest();

async function startFilling() {
  const tpl = activeTemplates.value.find((t) => t.id === Number(setup.templateId));
  if (!tpl) return;
  const token = shiftInfoRequests.begin();
  selectedTemplate.value = tpl;

  // Initialize responses
  const r = {};
  const gc = {};
  for (const group of tpl.groups) {
    gc[group.id] = '';
    for (const item of group.items) {
      r[item.id] = {
        checklist_item_id: item.id,
        value: item.type === 'checkbox' ? 'false' : '',
        group_comment: null,
      };
    }
  }
  responses.value = r;
  groupComments.value = gc;
  generalComments.value = '';
  step.value = 'fill';

  // Check procedure reads
  await checkProcedureReads(tpl);
  if (!shiftInfoRequests.isCurrent(token)) return;

  // Fetch shift info if available
  if (setup.shiftId) {
    try {
      const res = await api.get(`/api/shifts/${setup.shiftId}`);
      if (shiftInfoRequests.isCurrent(token)) shiftInfo.value = res.data;
    } catch {
      if (shiftInfoRequests.isCurrent(token)) shiftInfo.value = null;
    }
  } else {
    shiftInfo.value = null;
  }
}
```

Replace `openConfirmation()` with:

```js
// The pending list stays clickable while a detail loads; only the last clicked submission may open
// (the confirm PUT uses confirmSubmission.id, so a stale one would confirm the wrong checklist).
const confirmationRequests = useLatestRequest();

async function openConfirmation(sub) {
  const token = confirmationRequests.begin();
  try {
    const response = await api.get(`/api/checklist-submissions/${sub.id}`);
    if (!confirmationRequests.isCurrent(token)) return;
    confirmSubmission.value = response.data;
    confirmIds.value = new Set();
    Object.keys(confirmValues).forEach((k) => {
      delete confirmValues[k];
    });
    step.value = 'confirm-detail';
  } catch {
    if (confirmationRequests.isCurrent(token)) showToast('Failed to load submission.', 'error');
  }
}
```

Replace `openProcedureModal()` with:

```js
// Opening another procedure before the previous preview arrived supersedes it; a stale failure
// must not toast or close the preview the user is now looking at.
const previewRequests = useLatestRequest();

async function openProcedureModal(procedureId) {
  const token = previewRequests.begin();
  showProcedureModal.value = true;
  procedurePreview.value = null;
  try {
    const res = await api.get(`/api/procedures/${procedureId}`);
    if (!previewRequests.isCurrent(token)) return;
    procedurePreview.value = res.data;
    // Reset tab in ProcedureContent
    setTimeout(() => procedureContentRef.value?.resetTab(), 0);
  } catch {
    if (!previewRequests.isCurrent(token)) return;
    showToast('Failed to load procedure.', 'error');
    showProcedureModal.value = false;
  }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd frontend && npx playwright test e2e/checklists/fill.spec.js`
Expected: PASS — 2 passed.
Sabotage: delete `if (!shiftsRequests.isCurrent(token)) return;` → shifts test FAILS; re-insert. Delete `if (!confirmationRequests.isCurrent(token)) return;` → confirmation test FAILS; re-insert.

- [ ] **Step 5: Lint and commit**

```bash
cd frontend && npx biome check src/views/ChecklistFillView.vue e2e/checklists/fill.spec.js && cd ..
git add frontend/src/views/ChecklistFillView.vue frontend/e2e/checklists/fill.spec.js
git commit -m "fix: checklist fill ignores stale shift, confirmation and preview responses" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: ChecklistBuilderView edit loading (H13)

**Files:**
- Modify: `frontend/src/views/ChecklistBuilderView.vue:282-289` (imports), `:352-387` (`openCreateModal`, `editTemplate`)
- Test: `frontend/e2e/checklists/builder.spec.js` (create)

**Interfaces:**
- Consumes: `useLatestRequest()` (Task 1); `holdRequests()` (Task 3); `createChecklistTemplate()` (Task 8).
- Produces: channel `editRequests`.

- [ ] **Step 1: Write the failing test**

Create `frontend/e2e/checklists/builder.spec.js`:

```js
import { createChecklistTemplate } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

test.describe('Checklist templates', () => {
  test('opening one template and then another edits and saves the second one', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const a = await createChecklistTemplate(context, { name: 'Builder Race A' });
    const b = await createChecklistTemplate(context, { name: 'Builder Race B' });

    const slowA = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/checklist-templates/${a.id}`,
    );

    await adminPage.goto('/checklists');
    const card = (name) =>
      adminPage
        .locator('div.rounded-2xl')
        .filter({ has: adminPage.getByRole('heading', { level: 3, name, exact: true }) });
    await card('Builder Race A').getByRole('button', { name: 'Edit' }).click();
    await slowA.seen;
    await card('Builder Race B').getByRole('button', { name: 'Edit' }).click();
    const nameInput = adminPage.getByPlaceholder('e.g. Daily Shift Handover');
    await expect(nameInput).toHaveValue('Builder Race B');

    await slowA.releaseAndSettle();

    await expect(nameInput).toHaveValue('Builder Race B');

    // Saving must write B's own content to B and leave A untouched (verified via the API).
    const saved = adminPage.waitForResponse(
      (res) =>
        res.url().endsWith(`/api/checklist-templates/${b.id}`) && res.request().method() === 'PUT',
    );
    await adminPage.getByRole('button', { name: 'Save Changes' }).click();
    expect((await saved).ok()).toBe(true);
    const savedB = await (await context.request.get(`/api/checklist-templates/${b.id}`)).json();
    expect(savedB.name).toBe('Builder Race B');
    expect(savedB.groups[0].items[0].label).toBe('Builder Race B item');
    const savedA = await (await context.request.get(`/api/checklist-templates/${a.id}`)).json();
    expect(savedA.name).toBe('Builder Race A');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx playwright test e2e/checklists/builder.spec.js`
Expected: FAIL at the post-release `expect(nameInput).toHaveValue('Builder Race B')` — received `Builder Race A` (A's late response filled the form while `editingTemplate` is B; saving would overwrite B).

- [ ] **Step 3: Implement the guard**

In `frontend/src/views/ChecklistBuilderView.vue` add the import after `useFocusTrap`:

```js
import { useFocusTrap } from '../composables/useFocusTrap.js';
import { useLatestRequest } from '../composables/useLatestRequest.js';
```

Replace `openCreateModal()` and `editTemplate()` with:

```js
// Opening a template for edit loads it first. Clicking Edit on another template (or Create) before
// it arrives supersedes the load — otherwise the late form content would be saved over the template
// now being edited.
const editRequests = useLatestRequest();

// Modal helpers
function openCreateModal() {
  editRequests.invalidate();
  editingTemplate.value = null;
  form.value = defaultForm();
  touchedName.value = false;
  collapsedGroups.clear();
  showModal.value = true;
}

async function editTemplate(tpl) {
  const token = editRequests.begin();
  editingTemplate.value = tpl;
  try {
    const response = await api.get(`/api/checklist-templates/${tpl.id}`);
    if (!editRequests.isCurrent(token)) return;
    const data = response.data;
    touchedName.value = false;
    collapsedGroups.clear();
    form.value = {
      name: data.name,
      description: data.description || '',
      is_active: data.is_active,
      groups: data.groups.map((g) => ({
        name: g.name,
        items: g.items.map((i) => ({
          label: i.label,
          type: i.type,
          requires_confirmation: i.requires_confirmation,
          procedure_id: i.procedure_id || null,
          min_value: i.min_value ?? null,
          max_value: i.max_value ?? null,
        })),
      })),
    };
    showModal.value = true;
  } catch {
    if (editRequests.isCurrent(token)) showToast('Failed to load template.', 'error');
  }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd frontend && npx playwright test e2e/checklists/builder.spec.js`
Expected: PASS — 1 passed.
Sabotage: delete `if (!editRequests.isCurrent(token)) return;` → FAIL; re-insert. Change `'Builder Race B item'` in the API check to `'Builder Race A item'` → FAIL; undo.

- [ ] **Step 5: Lint and commit**

```bash
cd frontend && npx biome check src/views/ChecklistBuilderView.vue e2e/checklists/builder.spec.js && cd ..
git add frontend/src/views/ChecklistBuilderView.vue frontend/e2e/checklists/builder.spec.js
git commit -m "fix: checklist builder edits the template that was clicked last" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 11: DelegationArchiveView filters (H12)

**Files:**
- Modify: `frontend/src/views/DelegationArchiveView.vue:179-182` (imports), `:286-307` (`fetchData`)
- Modify: `frontend/e2e/fixtures/api-setup.js` (append `archiveDelegation`)
- Test: `frontend/e2e/delegations/archive.spec.js` (create)

**Interfaces:**
- Consumes: `useLatestRequest()` (Task 1); `holdRequests()`, `createDelegation()` (Task 3).
- Produces: `archiveDelegation(context, delegationId) → JSON`; channel `archiveRequests`.

(The audit's "ideally split the static destinations/users load out of the filter path" is not done: the guard alone fixes the race; moving loads is unflagged restructuring — YAGNI.)

- [ ] **Step 1: Add the API helper**

Append to `frontend/e2e/fixtures/api-setup.js`:

```js
export async function archiveDelegation(context, delegationId) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', `/api/delegations/${delegationId}/archive`, { headers });
  if (!res.ok()) throw new Error(`archive delegation failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}
```

- [ ] **Step 2: Write the failing test**

Create `frontend/e2e/delegations/archive.spec.js`:

```js
import { archiveDelegation, createDelegation } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

// Delegation dates in 2097-08 are reserved for this file.
test.describe('Delegation archive', () => {
  test('a slow result for the previous destination does not replace the chosen one', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const north = await createDelegation(context, {
      name: 'Arch Race North',
      destination: 'Archtown North',
      start: '2097-08-05',
      end: '2097-08-06',
    });
    const south = await createDelegation(context, {
      name: 'Arch Race South',
      destination: 'Archtown South',
      start: '2097-08-12',
      end: '2097-08-13',
    });
    await archiveDelegation(context, north.id);
    await archiveDelegation(context, south.id);

    await adminPage.goto('/delegation-archive');
    const northCard = adminPage.getByRole('heading', { name: 'Arch Race North' });
    const southCard = adminPage.getByRole('heading', { name: 'Arch Race South' });
    await expect(northCard).toBeVisible();
    await adminPage.waitForLoadState('networkidle');

    const northLoad = await holdRequests(
      adminPage,
      (url) =>
        url.pathname === '/api/delegation-archive' &&
        url.searchParams.get('destination') === 'Archtown North',
    );
    const destination = adminPage
      .locator('select')
      .filter({ has: adminPage.locator('option', { hasText: 'All destinations' }) });
    await destination.selectOption('Archtown North');
    await northLoad.seen;
    await destination.selectOption('Archtown South');
    await expect(northCard).toHaveCount(0); // was visible above; the South result has rendered
    await expect(southCard).toBeVisible();

    await northLoad.releaseAndSettle();

    await expect(southCard).toBeVisible();
    await expect(northCard).toHaveCount(0);
  });
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd frontend && npx playwright test e2e/delegations/archive.spec.js`
Expected: FAIL at the post-release `expect(southCard).toBeVisible()` — North's rows replaced South's under the South filter.

- [ ] **Step 4: Implement the guard**

In `frontend/src/views/DelegationArchiveView.vue` extend the imports:

```js
import { ref, reactive, computed, onMounted } from 'vue';
import { confirm } from '../composables/useConfirm.js';
import { useLatestRequest } from '../composables/useLatestRequest.js';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
```

Replace `fetchData()` with:

```js
// Every filter change refetches; only the latest may apply its rows or clear `loading`, so the list
// always matches the filters shown.
const archiveRequests = useLatestRequest();

async function fetchData() {
  const token = archiveRequests.begin();
  loading.value = true;
  try {
    const params = {};
    Object.entries(filters.value).forEach(([k, v]) => {
      if (v) params[k] = v;
    });

    const [archRes, destRes, usersRes] = await Promise.all([
      api.get('/api/delegation-archive', { params }),
      api.get('/api/delegation-archive/destinations'),
      api.get('/api/users'),
    ]);
    if (!archiveRequests.isCurrent(token)) return;
    archived.value = archRes.data;
    destinations.value = destRes.data;
    allUsers.value = usersRes.data;
  } catch (e) {
    if (archiveRequests.isCurrent(token)) console.error(e);
  } finally {
    if (archiveRequests.isCurrent(token)) loading.value = false;
  }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd frontend && npx playwright test e2e/delegations/archive.spec.js`
Expected: PASS — 1 passed.
Sabotage: delete `if (!archiveRequests.isCurrent(token)) return;` → FAIL; re-insert.

- [ ] **Step 6: Lint and commit**

```bash
cd frontend && npx biome check src/views/DelegationArchiveView.vue e2e/delegations/archive.spec.js e2e/fixtures/api-setup.js && cd ..
git add frontend/src/views/DelegationArchiveView.vue frontend/e2e/delegations/archive.spec.js frontend/e2e/fixtures/api-setup.js
git commit -m "fix: delegation archive ignores stale filter responses" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 12: ProceduresListView search, filters, sort and paging (H8)

**Files:**
- Modify: `frontend/src/views/ProceduresListView.vue:331-338` (imports), `:487-538` (`fetchProcedures`, `fetchInitial`)
- Test: `frontend/e2e/procedures/list.spec.js` (create)

**Interfaces:**
- Consumes: `useLatestRequest()` (Task 1); `holdRequests()` (Task 3); `createProcedure()` (Task 7); `getEmployeeIdByEmail`.
- Produces: channel `procedureRequests`. `fetchInitial()` no longer clears `loading` itself — `fetchProcedures()` owns it.

- [ ] **Step 1: Write the failing tests**

Create `frontend/e2e/procedures/list.spec.js`:

```js
import { createProcedure, getEmployeeIdByEmail } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

const tile = (page, title) => page.getByRole('heading', { level: 3, name: title, exact: true });

test.describe('Procedures list', () => {
  test('a slow initial load does not replace the search the user typed', async ({ adminPage }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    await createProcedure(context, { title: 'RaceList Alpha', ownerId: adminId });
    await createProcedure(context, { title: 'RaceList Beta', ownerId: adminId });

    const initial = await holdRequests(
      adminPage,
      (url) => url.pathname === '/api/procedures' && !url.searchParams.has('search'),
      { times: 1 },
    );

    await adminPage.goto('/procedures');
    await initial.seen;
    await adminPage.getByPlaceholder('Search procedures...').fill('RaceList Alpha');
    await expect(tile(adminPage, 'RaceList Alpha')).toBeVisible();

    await initial.releaseAndSettle();

    await expect(tile(adminPage, 'RaceList Alpha')).toBeVisible();
    await expect(tile(adminPage, 'RaceList Beta')).toHaveCount(0);
    await expect(adminPage.getByPlaceholder('Search procedures...')).toHaveValue('RaceList Alpha');
  });

  // Review Focus 5.
  test('refining the search before the broader search answers keeps the refined results', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    await createProcedure(context, { title: 'Refine Alpha', ownerId: adminId });
    await createProcedure(context, { title: 'Refine Beta', ownerId: adminId });

    await adminPage.goto('/procedures');
    await expect(tile(adminPage, 'Refine Beta')).toBeVisible();
    await adminPage.waitForLoadState('networkidle');

    const broad = await holdRequests(
      adminPage,
      (url) => url.pathname === '/api/procedures' && url.searchParams.get('search') === 'Refine',
    );
    const search = adminPage.getByPlaceholder('Search procedures...');
    await search.fill('Refine');
    await broad.seen;
    await search.fill('Refine Alpha');
    await expect(tile(adminPage, 'Refine Beta')).toHaveCount(0); // was visible above
    await expect(tile(adminPage, 'Refine Alpha')).toBeVisible();

    await broad.releaseAndSettle();

    await expect(tile(adminPage, 'Refine Alpha')).toBeVisible();
    await expect(tile(adminPage, 'Refine Beta')).toHaveCount(0);
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd frontend && npx playwright test e2e/procedures/list.spec.js`
Expected: both FAIL at the post-release `expect(tile(… 'Beta')).toHaveCount(0)` — received 1 (the unfiltered / broader response landed last).

- [ ] **Step 3: Implement the guard**

In `frontend/src/views/ProceduresListView.vue` add the import after the `ProcedureEditorModal` import:

```js
import ProcedureEditorModal from '../components/procedure/ProcedureEditorModal.vue';
import { useLatestRequest } from '../composables/useLatestRequest.js';
```

Replace everything from `let fetchTimeout = null;` through the end of `fetchInitial()` with:

```js
// Search (debounced), filters, sort and paging overlap fetches. Only the latest may apply its list
// and pager (including current_page, which would otherwise snap back) or clear the loading /
// "Updating results…" indicators.
const procedureRequests = useLatestRequest();

let fetchTimeout = null;
async function fetchProcedures() {
  const token = procedureRequests.begin();
  // Only show the full-page loading skeleton on the initial load.
  // Subsequent filter/sort/page fetches use the inline `filtering` indicator
  // so existing results stay visible instead of blinking to a blank state.
  if (!loading.value) {
    filtering.value = true;
  }
  try {
    const params = {
      page: currentPage.value,
      per_page: perPage.value,
    };
    if (searchQuery.value) params.search = searchQuery.value;
    if (selectedTags.value.length) params.tags = selectedTags.value.join(',');
    if (createdAfter.value) params.created_after = createdAfter.value;
    if (createdBefore.value) params.created_before = createdBefore.value;
    if (updatedAfter.value) params.updated_after = updatedAfter.value;
    if (updatedBefore.value) params.updated_before = updatedBefore.value;
    if (sortBy.value) {
      params.sort_by = sortBy.value;
      params.sort_dir = sortDir.value;
    }
    if (selectedNodeId.value) {
      params.node_id = selectedNodeId.value;
    }

    const res = await api.get('/api/procedures', { params });
    if (!procedureRequests.isCurrent(token)) return;
    procedures.value = res.data.data;
    totalProcedures.value = res.data.total;
    currentPage.value = res.data.current_page;
    lastPage.value = res.data.last_page;
  } catch {
  } finally {
    if (procedureRequests.isCurrent(token)) {
      loading.value = false;
      filtering.value = false;
    }
  }
}

async function fetchInitial() {
  loading.value = true;
  try {
    const tagRes = await api.get('/api/procedure-tags');
    allTags.value = tagRes.data;
  } catch {}
  await fetchTree();
  // fetchProcedures owns `loading` from here: only the latest list request may clear it, so a
  // search typed during the initial load isn't followed by an early "loaded" state.
  await fetchProcedures();
}
```

(`fetchTree` keeps its own `try/catch`. Behaviour change worth noting: previously a failed tags request skipped the list load entirely; now the list still loads.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd frontend && npx playwright test e2e/procedures/list.spec.js`
Expected: PASS — 2 passed.
Sabotage: delete `if (!procedureRequests.isCurrent(token)) return;` → both FAIL; re-insert. Change `tile(adminPage, 'RaceList Alpha')`'s first positive guard to `'RaceList Alphaa'` → FAIL; undo.

- [ ] **Step 5: Lint and commit**

```bash
cd frontend && npx biome check src/views/ProceduresListView.vue e2e/procedures/list.spec.js && cd ..
git add frontend/src/views/ProceduresListView.vue frontend/e2e/procedures/list.spec.js
git commit -m "fix: procedures list ignores stale search, filter and page responses" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 13: ProcedureDetailView version browsing and compare dialog (H9, M10)

**Files:**
- Modify: `frontend/src/views/ProcedureDetailView.vue:162-170` (imports), `:190-192` (version state), `:251-372` (`fetchProcedure`, `loadVersionData`, `openDiffModal`, route watcher)
- Modify: `frontend/e2e/fixtures/api-setup.js` (append `publishProcedureVersion`)
- Test: `frontend/e2e/procedures/detail.spec.js` (create)

**Interfaces:**
- Consumes: `useLatestRequest()` (Task 1); `holdRequests()` (Task 3); `createProcedure()` (Task 7); `getEmployeeIdByEmail`.
- Produces: `publishProcedureVersion(context, procedureId, { title, content?, changeReason }) → procedure JSON` (draft + publish = a new version snapshot); `createVersionedProcedure(context, base)` local to `detail.spec.js` (Task 14 adds tests to this file and reuses its imports); channels `versionRequests`, `diffRequests`; helper `showLatestVersion()`.

- [ ] **Step 1: Add the API helper**

Append to `frontend/e2e/fixtures/api-setup.js`:

```js
// Publishes a new version through the draft flow, which also stores a version snapshot.
export async function publishProcedureVersion(
  context,
  procedureId,
  { title, content = `<p>${title}</p>`, changeReason },
) {
  const headers = await getXsrfHeader(context);
  const draft = await send(context, 'put', `/api/procedures/${procedureId}/draft`, {
    data: { title, content },
    headers,
  });
  if (!draft.ok()) throw new Error(`save draft failed: ${draft.status()} ${await draft.text()}`);
  const res = await send(context, 'post', `/api/procedures/${procedureId}/publish`, {
    data: { change_reason: changeReason },
    headers,
  });
  if (!res.ok()) throw new Error(`publish failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}
```

- [ ] **Step 2: Write the failing tests**

Create `frontend/e2e/procedures/detail.spec.js`:

```js
import {
  createProcedure,
  getEmployeeIdByEmail,
  publishProcedureVersion,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

// A procedure titled `${base} v1`, `${base} v2`, `${base} v3` across three published versions.
async function createVersionedProcedure(context, base) {
  const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
  const procedure = await createProcedure(context, { title: `${base} v1`, ownerId: adminId });
  await publishProcedureVersion(context, procedure.id, { title: `${base} v2`, changeReason: 'v2' });
  await publishProcedureVersion(context, procedure.id, { title: `${base} v3`, changeReason: 'v3' });
  return procedure;
}

const title = (page, name) => page.getByRole('heading', { level: 1, name, exact: true });
const isVersion = (procedureId, version) => (url) =>
  url.pathname === `/api/procedures/${procedureId}/versions/${version}`;

test.describe('Procedure detail', () => {
  test('stepping back two versions quickly shows the version the user stopped on', async ({
    adminPage,
  }) => {
    const procedure = await createVersionedProcedure(adminPage.context(), 'Versions Race');
    const v2 = await holdRequests(adminPage, isVersion(procedure.id, 2));

    await adminPage.goto(`/procedures/${procedure.id}`);
    await expect(title(adminPage, 'Versions Race v3')).toBeVisible();
    await adminPage.getByTitle('Previous version').click();
    await v2.seen;
    await adminPage.getByTitle('Previous version').click();
    await expect(title(adminPage, 'Versions Race v1')).toBeVisible();

    await v2.releaseAndSettle();

    await expect(title(adminPage, 'Versions Race v1')).toBeVisible();
    await expect(title(adminPage, 'Versions Race v2')).toHaveCount(0);
  });

  // Review Focus 3.
  test('going back to the latest version while an older one loads stays on the latest', async ({
    adminPage,
  }) => {
    const procedure = await createVersionedProcedure(adminPage.context(), 'Latest Race');
    const v2 = await holdRequests(adminPage, isVersion(procedure.id, 2));

    await adminPage.goto(`/procedures/${procedure.id}`);
    await expect(title(adminPage, 'Latest Race v3')).toBeVisible();
    await adminPage.getByTitle('Previous version').click();
    await v2.seen;
    await adminPage.getByTitle('Next version').click();
    await expect(adminPage.getByTitle('Next version')).toBeDisabled(); // back on the latest

    await v2.releaseAndSettle();

    await expect(title(adminPage, 'Latest Race v3')).toBeVisible();
    await expect(title(adminPage, 'Latest Race v2')).toHaveCount(0);
  });

  // Review Focus 4.
  test('a cached version shown while an older one loads stays on screen', async ({
    adminPage,
  }) => {
    const procedure = await createVersionedProcedure(adminPage.context(), 'Cache Race');

    await adminPage.goto(`/procedures/${procedure.id}`);
    await expect(title(adminPage, 'Cache Race v3')).toBeVisible();
    await adminPage.getByTitle('Previous version').click();
    await expect(title(adminPage, 'Cache Race v2')).toBeVisible(); // v2 is now cached

    const v1 = await holdRequests(adminPage, isVersion(procedure.id, 1));
    await adminPage.getByTitle('Previous version').click();
    await v1.seen;
    await adminPage.getByTitle('Next version').click(); // back to v2, served from the cache
    await expect(adminPage.getByText('Viewing v2')).toBeVisible();

    await v1.releaseAndSettle();

    await expect(title(adminPage, 'Cache Race v2')).toBeVisible();
    await expect(title(adminPage, 'Cache Race v1')).toHaveCount(0);
  });

  test('a compare dialog still loading does not open after the user switched versions', async ({
    adminPage,
  }) => {
    const procedure = await createVersionedProcedure(adminPage.context(), 'Diff Race');
    const firstV2 = await holdRequests(adminPage, isVersion(procedure.id, 2), { times: 1 });

    await adminPage.goto(`/procedures/${procedure.id}`);
    await expect(title(adminPage, 'Diff Race v3')).toBeVisible();
    await adminPage.getByTitle('Compare v2 → v3').click();
    await firstV2.seen;
    await adminPage.getByTitle('Previous version').click();
    await expect(title(adminPage, 'Diff Race v2')).toBeVisible();
    await expect(adminPage.getByText('Viewing v2')).toBeVisible();

    await firstV2.releaseAndSettle();

    await expect(title(adminPage, 'Diff Race v2')).toBeVisible();
    await expect(adminPage.getByRole('dialog', { name: /Changes: v2 → v3/ })).toHaveCount(0);
  });
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `cd frontend && npx playwright test e2e/procedures/detail.spec.js`
Expected: 4 FAIL — "stepping back" at the post-release `title(… 'Versions Race v1')` (shows v2); "latest" at the post-release `title(… 'Latest Race v3')` (shows v2); "cached" at the post-release `title(… 'Cache Race v2')` (shows v1); "compare" at the final `toHaveCount(0)` (the v2 → v3 dialog opened while viewing v2).

- [ ] **Step 4: Implement the guards**

In `frontend/src/views/ProcedureDetailView.vue` add the import after the `ProcedureEditorModal` import:

```js
import ProcedureEditorModal from '../components/procedure/ProcedureEditorModal.vue';
import { useLatestRequest } from '../composables/useLatestRequest.js';
```

After `const versionCache = {};` add:

```js
// Clicking through versions faster than they load overlaps loads; only the version the user asked
// for last may be shown — including when it came from the cache or is the latest (no request).
const versionRequests = useLatestRequest();
// The compare dialog loads snapshots before opening; switching versions meanwhile supersedes it.
const diffRequests = useLatestRequest();

function showLatestVersion() {
  versionRequests.invalidate();
  versionData.value = null;
}
```

In `fetchProcedure()` replace the two `versionData.value = null;` lines with `showLatestVersion();`, so the block reads:

```js
    if (urlVersion && urlVersion >= 1 && urlVersion <= procedure.value.version) {
      viewingVersion.value = urlVersion;
      if (urlVersion < procedure.value.version) {
        await loadVersionData(urlVersion);
      } else {
        showLatestVersion();
      }
    } else {
      viewingVersion.value = procedure.value.version;
      showLatestVersion();
    }
```

Replace `loadVersionData()` with:

```js
async function loadVersionData(v) {
  // Taken before the cache check: a cache hit also supersedes an older request still in flight.
  const token = versionRequests.begin();
  // Check cache
  if (versionCache[v]) {
    versionData.value = versionCache[v];
    return;
  }
  // Fetch from API
  try {
    const res = await api.get(`/api/procedures/${procedure.value.id}/versions/${v}`);
    versionCache[v] = res.data;
    if (!versionRequests.isCurrent(token)) return;
    versionData.value = res.data;
  } catch {
    if (versionRequests.isCurrent(token)) versionData.value = null;
  }
}
```

In `openDiffModal()`, take a token right after the early return and check it before any dialog state is set:

```js
async function openDiffModal() {
  const newV = viewingVersion.value;
  const oldV = newV - 1;
  if (oldV < 1) return;
  const token = diffRequests.begin();
```

and, after the "Get old version data" block, immediately before `diffOldVersion.value = oldV;`:

```js
  if (!diffRequests.isCurrent(token)) return;
  diffOldVersion.value = oldV;
```

Replace the route watcher with:

```js
// Watch for route version changes (when navigating via URL)
watch(
  () => route.params.version,
  async (newVer) => {
    if (!procedure.value) return;
    const v = newVer ? parseInt(newVer) : procedure.value.version;
    if (v === viewingVersion.value) return;
    diffRequests.invalidate();
    viewingVersion.value = v;
    if (v < procedure.value.version) {
      await loadVersionData(v);
    } else {
      showLatestVersion();
    }
  },
);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd frontend && npx playwright test e2e/procedures/detail.spec.js`
Expected: PASS — 4 passed.
Sabotage (re-insert each exact line afterwards): delete `if (!versionRequests.isCurrent(token)) return;` → "stepping back" FAILS; in `showLatestVersion` delete `versionRequests.invalidate();` → "latest" FAILS; move `const token = versionRequests.begin();` below the cache-check block → "cached" FAILS (move it back); delete `diffRequests.invalidate();` → "compare" FAILS.

- [ ] **Step 6: Lint and commit**

```bash
cd frontend && npx biome check src/views/ProcedureDetailView.vue e2e/procedures/detail.spec.js e2e/fixtures/api-setup.js && cd ..
git add frontend/src/views/ProcedureDetailView.vue frontend/e2e/procedures/detail.spec.js frontend/e2e/fixtures/api-setup.js
git commit -m "fix: procedure version browsing and compare ignore stale responses" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 14: Procedure access log and editor modal (H14, M6)

**Files:**
- Modify: `frontend/src/components/procedure/ProcedureAccessLogTab.vue:56-131` (script: imports, `fetchLogs`, `onMounted`)
- Modify: `frontend/src/components/procedure/ProcedureEditorModal.vue:263-274` (imports), `:599-637` (`loadData`, `watch(show)`)
- Test: `frontend/e2e/procedures/detail.spec.js` (add two tests)

**Interfaces:**
- Consumes: `useLatestRequest()` (Task 1); `holdRequests()` (Task 3); `createProcedure()` (Task 7); the imports and `title` helper already in `detail.spec.js` (Task 13).
- Produces: channels `logsRequests` (shared by `fetchLogs` and the mount load — they write the same `logs`/`loading`) and `loadRequests` (editor).

- [ ] **Step 1: Write the failing tests**

Append inside `test.describe('Procedure detail', …)` in `frontend/e2e/procedures/detail.spec.js`:

```js
  test('a slow unfiltered access log does not replace the filtered one', async ({ adminPage }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const procedure = await createProcedure(context, { title: 'Access Log Race', ownerId: adminId });
    const unfiltered = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/procedures/${procedure.id}/access-log` && url.search === '',
    );

    await adminPage.goto(`/procedures/${procedure.id}`);
    await expect(title(adminPage, 'Access Log Race')).toBeVisible();
    await adminPage.getByRole('button', { name: 'Access Log' }).click();
    await unfiltered.seen;
    // "To" date filter: nothing was accessed before 2000, so the filtered log is empty.
    await adminPage.locator('input[type="date"]').last().fill('2000-01-01');
    const empty = adminPage.getByText('No access logs found.');
    await expect(empty).toBeVisible();

    await unfiltered.releaseAndSettle();

    await expect(empty).toBeVisible();
    await expect(adminPage.getByText(/^\d+ entries$/)).toHaveCount(0);
  });

  test('a slow editor load from before reopening does not overwrite what the user typed', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const procedure = await createProcedure(context, { title: 'Editor Race', ownerId: adminId });
    // The page's own load is the first GET of the procedure; the editor's first load is the second.
    const firstEditorLoad = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/procedures/${procedure.id}`,
      { skip: 1, times: 1 },
    );

    await adminPage.goto(`/procedures/${procedure.id}`);
    await expect(title(adminPage, 'Editor Race')).toBeVisible();
    const edit = adminPage.getByRole('button', { name: 'Edit', exact: true });
    await edit.click();
    await firstEditorLoad.seen;
    await adminPage.getByLabel('Close editor').click();
    await edit.click();
    const titleInput = adminPage.getByPlaceholder('e.g. Password Reset via Database');
    await expect(titleInput).toHaveValue('Editor Race');
    await titleInput.fill('Editor Race (typed)');

    await firstEditorLoad.releaseAndSettle();

    await expect(titleInput).toHaveValue('Editor Race (typed)');
  });
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd frontend && npx playwright test e2e/procedures/detail.spec.js -g "access log|editor load"`
Expected: both FAIL — access log at the post-release `expect(empty).toBeVisible()` (the unfiltered list replaced it; "N entries" shown); editor at the post-release `toHaveValue('Editor Race (typed)')` (received `Editor Race`).

- [ ] **Step 3: Implement the access-log guard**

In `frontend/src/components/procedure/ProcedureAccessLogTab.vue` extend the imports:

```js
import { ref, reactive, computed, onMounted } from 'vue';
import api from '../../axios.js';
import { useLatestRequest } from '../../composables/useLatestRequest.js';
```

Replace `fetchLogs()` and the `onMounted(...)` block with:

```js
// The unfiltered mount load and every filter change write the same list, so they share one
// channel: a slow unfiltered load must not replace a filter the user already picked.
const logsRequests = useLatestRequest();

async function fetchLogs() {
  const token = logsRequests.begin();
  loading.value = true;
  try {
    const params = {};
    if (filters.user_id) params.user_id = filters.user_id;
    if (filters.version) params.version = filters.version;
    if (filters.date_from) params.date_from = filters.date_from;
    if (filters.date_to) params.date_to = filters.date_to;
    const res = await api.get(`/api/procedures/${props.procedureId}/access-log`, { params });
    if (!logsRequests.isCurrent(token)) return;
    logs.value = res.data;
  } catch {
  } finally {
    if (logsRequests.isCurrent(token)) loading.value = false;
  }
}

function clearFilters() {
  filters.user_id = '';
  filters.version = '';
  filters.date_from = '';
  filters.date_to = '';
  fetchLogs();
}

onMounted(async () => {
  // Fetch initial unfiltered data to populate filter dropdowns
  const token = logsRequests.begin();
  try {
    const data = (await api.get(`/api/procedures/${props.procedureId}/access-log`)).data;
    // The list only if no filter was picked meanwhile; the dropdown options always.
    if (logsRequests.isCurrent(token)) logs.value = data;

    // Extract unique users and versions for dropdowns
    const userMap = {};
    const versionSet = new Set();
    data.forEach((log) => {
      if (log.user) userMap[log.user.id] = log.user;
      if (log.version_read) versionSet.add(log.version_read);
    });
    uniqueUsers.value = Object.values(userMap).sort((a, b) => a.name.localeCompare(b.name));
    uniqueVersions.value = [...versionSet].sort((a, b) => b - a);
  } catch {
  } finally {
    if (logsRequests.isCurrent(token)) loading.value = false;
  }
});
```

(`clearFilters` is unchanged; it is repeated only because it sits between the two replaced blocks.)

- [ ] **Step 4: Implement the editor guard**

In `frontend/src/components/procedure/ProcedureEditorModal.vue` add the import after the `useFocusTrap` import:

```js
import { useFocusTrap } from '../../composables/useFocusTrap.js';
import { useLatestRequest } from '../../composables/useLatestRequest.js';
```

Replace `loadData()` and the `watch(() => props.show, …)` block with:

```js
// Closing and reopening the editor mid-load starts a second load. Only the latest may fill the
// form, mark it loaded or close the modal on error — otherwise a stale response overwrites what the
// user already typed, or a stale failure closes the freshly opened editor.
const loadRequests = useLatestRequest();

async function loadData() {
  if (loaded.value) return;
  const token = loadRequests.begin();
  try {
    const [usersRes, tagsRes] = await Promise.all([
      api.get('/api/users'),
      api.get('/api/procedure-tags'),
    ]);
    if (!loadRequests.isCurrent(token)) return;
    users.value = usersRes.data;
    allTags.value = tagsRes.data;
  } catch {}
  if (!loadRequests.isCurrent(token)) return;

  if (isEdit.value) {
    try {
      const res = await api.get(`/api/procedures/${props.procedureId}`);
      if (!loadRequests.isCurrent(token)) return;
      // Owner check
      if (res.data.owner_id !== authUser.value?.id && !authUser.value?.is_admin) {
        emit('close');
        return;
      }
      loadFromResponse(res.data);
    } catch {
      if (!loadRequests.isCurrent(token)) return;
      emit('close');
    }
  } else {
    form.owner_id = authUser.value?.id || '';
  }
  loaded.value = true;
}

// Watch show prop to load data when modal opens and reset when it closes
watch(
  () => props.show,
  async (newVal) => {
    if (newVal) {
      resetForm();
      await loadData();
    } else {
      loadRequests.invalidate();
    }
  },
);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd frontend && npx playwright test e2e/procedures/detail.spec.js`
Expected: PASS — 6 passed.
Sabotage: in the mount block change `if (logsRequests.isCurrent(token)) logs.value = data;` to `logs.value = data;` → access-log test FAILS; restore. Delete the `if (!loadRequests.isCurrent(token)) return;` directly after the procedure GET → editor test FAILS; re-insert.

- [ ] **Step 6: Lint and commit**

```bash
cd frontend && npx biome check src/components/procedure/ProcedureAccessLogTab.vue src/components/procedure/ProcedureEditorModal.vue e2e/procedures/detail.spec.js && cd ..
git add frontend/src/components/procedure/ProcedureAccessLogTab.vue frontend/src/components/procedure/ProcedureEditorModal.vue frontend/e2e/procedures/detail.spec.js
git commit -m "fix: procedure access log and editor ignore stale loads" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 15: ProceduresToLearnView preview and ProcedureTreeEditorView tree (M4, M7)

**Files:**
- Modify: `frontend/src/views/ProceduresToLearnView.vue:60` (close button `aria-label`), `:91-97` (imports), `:118-171` (script body)
- Modify: `frontend/src/views/ProcedureTreeEditorView.vue:109-112` (imports), `:163-202` (`fetchTree`, `fetchData`)
- Test: `frontend/e2e/procedures/to-learn.spec.js` (create)

**Interfaces:**
- Consumes: `useLatestRequest()` (Task 1); `holdRequests()` (Task 3); `createProcedure()` (Task 7); `getEmployeeIdByEmail`.
- Produces: channels `previewRequests` (shared by `openProcedure` and `checkHash` via `loadPreview(id)`), `treeRequests` (shared by `fetchTree` and `fetchData`, both write `treeData`).

No e2e test for M7 (tree editor): forcing it needs two HTML5 drag-and-drops completed inside one in-flight window, and the view's unassigned list currently reads the paginated `/api/procedures` response (`procRes.data`, an object) as an array — a pre-existing, unflagged bug that may keep the drag sources from rendering at all. Report that bug separately; do not fix it here. The M7 guard is the same shape as the tested guards.

- [ ] **Step 1: Write the failing test**

Create `frontend/e2e/procedures/to-learn.spec.js`:

```js
import { createProcedure, getEmployeeIdByEmail } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

test.describe('Procedures to learn', () => {
  test('closing a slow procedure and opening another keeps the second one', async ({
    adminPage,
    employeePage,
  }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const first = await createProcedure(context, { title: 'Learn Race First', ownerId: adminId });
    await createProcedure(context, { title: 'Learn Race Second', ownerId: adminId });

    // Both loads of the first procedure (the click and the hashchange it triggers) are held.
    const slowFirst = await holdRequests(
      employeePage,
      (url) => url.pathname === `/api/procedures/${first.id}`,
    );

    await employeePage.goto('/procedures-to-learn');
    await employeePage.getByRole('heading', { level: 2, name: 'Learn Race First' }).click();
    await slowFirst.seen;
    await employeePage.getByLabel('Close procedure').click();
    await employeePage.getByRole('heading', { level: 2, name: 'Learn Race Second' }).click();
    const modalTitle = employeePage
      .locator('div.fixed.inset-0.z-50')
      .getByRole('heading', { level: 2 });
    await expect(modalTitle).toHaveText('Learn Race Second');

    await slowFirst.releaseAndSettle();

    await expect(modalTitle).toHaveText('Learn Race Second');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx playwright test e2e/procedures/to-learn.spec.js`
Expected: FAIL at `getByLabel('Close procedure')` (no label yet). Add the `aria-label` (first part of Step 3), re-run, and see it FAIL at the post-release `toHaveText('Learn Race Second')` (received `Learn Race First`). Continue only once you have seen the second failure.

- [ ] **Step 3: Implement the label and the M4 guard**

In `frontend/src/views/ProceduresToLearnView.vue` label the modal close button (the `<button @click="closeModal"` in the modal header):

```html
                <button @click="closeModal" aria-label="Close procedure" class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
```

Replace the imports with:

```js
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import ProcedureContent from '../components/procedure/ProcedureContent.vue';
import { useLatestRequest } from '../composables/useLatestRequest.js';
import { currentUser } from '../router/index.js';
```

(`watch` was imported but unused; the second `computed` import is merged into the first.)

Replace everything from `async function openProcedure(p)` to the end of the script with:

```js
// Opening a procedure sets the hash, which fires `hashchange` -> checkHash, so loads overlap even
// for one click; closing and opening another adds more. Both paths share one channel: only the
// latest load may fill the modal, close it on error or clear `modalLoading`.
const previewRequests = useLatestRequest();

async function loadPreview(id) {
  const token = previewRequests.begin();
  showModal.value = true;
  modalLoading.value = true;
  try {
    const res = await api.get(`/api/procedures/${id}`);
    if (!previewRequests.isCurrent(token)) return;
    modalProcedure.value = res.data;
  } catch {
    if (previewRequests.isCurrent(token)) showModal.value = false;
  } finally {
    if (previewRequests.isCurrent(token)) modalLoading.value = false;
  }
}

async function openProcedure(p) {
  modalProcedure.value = null;
  // Update hash
  window.location.hash = `procedure-${p.id}`;
  await loadPreview(p.id);
}

function closeModal() {
  previewRequests.invalidate();
  showModal.value = false;
  modalProcedure.value = null;
  window.location.hash = '';
}

// Open modal from hash URL on mount and on back/forward
async function checkHash() {
  const match = window.location.hash.match(/^#procedure-(\d+)$/);
  if (match) await loadPreview(parseInt(match[1]));
}

onMounted(async () => {
  window.addEventListener('hashchange', checkHash);
  try {
    procedures.value = (await api.get('/api/procedures-to-learn')).data;
  } catch {
  } finally {
    loading.value = false;
  }
  await checkHash();
});

onUnmounted(() => {
  window.removeEventListener('hashchange', checkHash);
});
```

- [ ] **Step 4: Implement the M7 guard**

In `frontend/src/views/ProcedureTreeEditorView.vue` extend the imports:

```js
import { ref, computed, onMounted } from 'vue';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import TreeEditNode from '../components/procedure/TreeEditNode.vue';
import { useLatestRequest } from '../composables/useLatestRequest.js';
```

Replace `fetchTree()` and `fetchData()` with:

```js
// Two quick drops each refetch the tree; the older GET landing last would show a pre-move tree.
// The mount load writes the same tree, so it shares the channel.
const treeRequests = useLatestRequest();

async function fetchTree() {
  const token = treeRequests.begin();
  try {
    const res = await api.get('/api/procedures-tree');
    if (treeRequests.isCurrent(token)) treeData.value = res.data;
  } catch {}
}

async function fetchData() {
  const token = treeRequests.begin();
  loadingTree.value = true;
  try {
    const [treeRes, procRes] = await Promise.all([
      api.get('/api/procedures-tree'),
      api.get('/api/procedures'),
    ]);
    if (treeRequests.isCurrent(token)) treeData.value = treeRes.data;
    allProcedures.value = procRes.data;
  } catch {
  } finally {
    loadingTree.value = false;
  }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd frontend && npx playwright test e2e/procedures/to-learn.spec.js`
Expected: PASS — 1 passed.
Sabotage: delete `if (!previewRequests.isCurrent(token)) return;` in `loadPreview` → FAIL; re-insert.

- [ ] **Step 6: Lint and commit**

```bash
cd frontend && npx biome check src/views/ProceduresToLearnView.vue src/views/ProcedureTreeEditorView.vue e2e/procedures/to-learn.spec.js && cd ..
git add frontend/src/views/ProceduresToLearnView.vue frontend/src/views/ProcedureTreeEditorView.vue frontend/e2e/procedures/to-learn.spec.js
git commit -m "fix: procedures to learn and tree editor ignore stale loads" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 16: UsersView newbie dialog (M5)

**Files:**
- Modify: `frontend/src/views/UsersView.vue:706-710` (imports), `:1157-1185` (`openNewbieModal`, `createNewbiePeriod`)
- Test: `frontend/e2e/users/newbie-periods.spec.js` (create)

**Interfaces:**
- Consumes: `useLatestRequest()` (Task 1); `holdRequests()`, `createNewbiePeriod()` (Task 3); `getEmployeeIdByEmail`.
- Produces: channel `newbieRequests` (both the dialog open and the post-create refetch write `userNewbiePeriods`).

- [ ] **Step 1: Write the failing test**

Create `frontend/e2e/users/newbie-periods.spec.js`:

```js
import { createNewbiePeriod, getEmployeeIdByEmail } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

// Newbie-period dates in 2097-07 are reserved for this file.
test.describe('Users: newbie periods', () => {
  test('reopening the dialog for another user is not overwritten by the first user’s slow periods', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const test2Id = await getEmployeeIdByEmail(context, 'test2@shiftchanger.com');
    const marcinId = await getEmployeeIdByEmail(context, 'marcin@shiftchanger.com');
    await createNewbiePeriod(context, {
      userId: test2Id,
      startDate: '2097-07-01',
      endDate: '2097-07-10',
    });
    await createNewbiePeriod(context, {
      userId: marcinId,
      startDate: '2097-07-20',
      endDate: '2097-07-25',
    });

    const slowTest2 = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/newbie-periods/user/${test2Id}`,
    );

    await adminPage.goto('/users');
    await adminPage
      .getByRole('row', { name: /Test Employee 2/ })
      .getByTitle('Mark as Newbie')
      .click();
    await slowTest2.seen;
    await adminPage.getByRole('button', { name: 'Close', exact: true }).click();
    await adminPage.getByRole('row', { name: /Marcin Kefir/ }).getByTitle('Mark as Newbie').click();
    const marcinPeriod = adminPage.getByText('2097-07-20 → 2097-07-25');
    await expect(marcinPeriod).toBeVisible();

    await slowTest2.releaseAndSettle();

    await expect(marcinPeriod).toBeVisible();
    await expect(adminPage.getByText('2097-07-01 → 2097-07-10')).toHaveCount(0);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx playwright test e2e/users/newbie-periods.spec.js`
Expected: FAIL at the post-release `expect(marcinPeriod).toBeVisible()` — Test Employee 2's periods replaced Marcin's in Marcin's dialog.

- [ ] **Step 3: Implement the guard**

In `frontend/src/views/UsersView.vue` extend the imports:

```js
import AppToast from '../components/AppToast.vue';
import { useLatestRequest } from '../composables/useLatestRequest.js';
import { useToast } from '../composables/useToast.js';
```

Replace `openNewbieModal()` and `createNewbiePeriod()` with:

```js
// ── Newbie ─────────────────────────────────────────────
// Closing the dialog and opening it for another user before the first user's periods arrived
// overlaps loads; only the latest may fill the list (the post-create refetch shares the channel).
const newbieRequests = useLatestRequest();

async function openNewbieModal(user) {
  const token = newbieRequests.begin();
  newbieUser.value = user;
  newbieForm.start_date = '';
  newbieForm.end_date = '';
  userNewbiePeriods.value = [];
  showNewbieModal.value = true;
  try {
    const res = await api.get(`/api/newbie-periods/user/${user.id}`);
    if (newbieRequests.isCurrent(token)) userNewbiePeriods.value = res.data;
  } catch {}
}

async function createNewbiePeriod() {
  savingNewbie.value = true;
  try {
    await api.post('/api/newbie-periods', {
      user_id: newbieUser.value.id,
      start_date: newbieForm.start_date,
      end_date: newbieForm.end_date,
    });
    showToast(`${newbieUser.value.name} marked as newbie.`);
    const token = newbieRequests.begin();
    const res = await api.get(`/api/newbie-periods/user/${newbieUser.value.id}`);
    if (newbieRequests.isCurrent(token)) userNewbiePeriods.value = res.data;
    newbieForm.start_date = '';
    newbieForm.end_date = '';
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to create newbie period.', 'error');
  } finally {
    savingNewbie.value = false;
  }
}
```

(Keep the existing `// ── Newbie ──…` banner only once.)

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd frontend && npx playwright test e2e/users/newbie-periods.spec.js`
Expected: PASS — 1 passed.
Sabotage: change `if (newbieRequests.isCurrent(token)) userNewbiePeriods.value = res.data;` in `openNewbieModal` to `userNewbiePeriods.value = res.data;` → FAIL; restore.

- [ ] **Step 5: Lint and commit**

```bash
cd frontend && npx biome check src/views/UsersView.vue e2e/users/newbie-periods.spec.js && cd ..
git add frontend/src/views/UsersView.vue frontend/e2e/users/newbie-periods.spec.js
git commit -m "fix: newbie dialog shows the periods of the user it was opened for" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 17: CHANGELOG and full verification

**Files:**
- Modify: `CHANGELOG.md` (new top entry, directly under the `---` that follows the intro)

**Interfaces:**
- Consumes: everything above.
- Produces: the release note.

- [ ] **Step 1: Write the CHANGELOG entry**

Insert directly after the first `---` line in `CHANGELOG.md` (above the current top entry "My Shifts no longer shows the previous month's shifts after switching"), using the ship date (today: 2026-10-07; use the real date if later):

```markdown
## 2026-10-07 — 🐛 Views no longer show stale data after fast clicks

Across the app, clicking faster than a list loads — stepping months, switching groups or filters, opening one item and then another — could end with an older, slower response replacing what the user had just asked for: another month's on-call periods or closures, a filter's results that didn't match the filter bar, the wrong submission in the confirm screen, or a checklist template's form filled with a different template's content (and saved over it). A read-only audit found 14 high and 10 medium cases; all are fixed with one shared guard.

### Frontend
| What | Details |
|------|---------|
| Added | `composables/useLatestRequest.js`: `begin()` / `isCurrent(token)` / `invalidate()` — only the latest request for a piece of state may apply its result, clear `loading` or report an error; unit-tested (`useLatestRequest.test.js`) |
| Changed | Schedule, Settlement Periods and My Shifts use the helper instead of their inline request counters |
| Fixed | Schedule: delegation/newbie/closure overlays and the availability highlight follow the latest month and employee; the closure overlay now refetches on month change; deselecting an employee drops their pending availability |
| Fixed | On-Call, Office Availability, My Availability (delegations; entries no longer lost or resurrected by a reload racing a save/delete), Notifications, Checklist archive (filters, detail, procedure preview), Fill checklist (shifts per date, pending confirmation, shift info, procedure preview), Checklist templates (edit), Delegation archive, Procedures list (search/filter/sort/page, incl. the pager and "Updating results…"), Procedure detail (version browsing, compare dialog, access log, editor reopen), Procedures to learn (preview; `hashchange` listener now removed on leave), Procedure tree editor, Users (newbie dialog) |
| Fixed | Fill checklist: changing the date no longer keeps the previous date's shift selected (the date input passed its DOM event as the shift to keep) |
| Tests | New `e2e/fixtures/hold.js` (`holdRequests`) holds the older request until the newer one has rendered; new race tests for every high-severity case and most medium ones in `schedule/stale-overlays`, `on-call`, `office-availability/closures`, `my-availability`, `alarms`, `checklists/{history,fill,builder}`, `delegations/archive`, `procedures/{list,detail,to-learn}`, `users/newbie-periods` — each failed before its fix |

---
```

- [ ] **Step 2: Run the unit tests**

Run: `cd frontend && npm test`
Expected: PASS — all unit tests, including the 6 in `useLatestRequest.test.js`.

- [ ] **Step 3: Run the full e2e suite (resets the shared dev DB)**

Run: `cd frontend && npm run test:e2e`
Expected: PASS — every spec, old and new (no retries are configured; a failure is real). If a new test fails only in the full run, look for a month or name collision with another spec before touching code.

- [ ] **Step 4: Lint everything that changed on the branch**

Run: `cd frontend && npx biome check src e2e`
Expected: no errors.
Then: `grep -rn "RequestSeq\|let [a-zA-Z]*Seq = 0" frontend/src` prints nothing (no hand-rolled counters left).

- [ ] **Step 5: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: changelog for stale-response guards" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

## Coverage map (audit finding → task)

| Finding | Task | e2e test |
|---|---|---|
| H1 Schedule delegations/newbies | 3 | delegations + newbies tests |
| H2 Schedule availability | 3 | availability test (+ Review Focus 1 deselect) |
| H3 On-Call | 4 | month + group tests (+ Review Focus 2) |
| H4 Office Availability | 5 | closures test |
| H5 My Availability delegations | 6 | abort-mode test (server ignores `month`; see Task 6 note) |
| H6 Alarms | 7 | filter test |
| H7 Checklist history filters | 8 | filter test |
| H8 Procedures list | 12 | initial-vs-search test (+ Review Focus 5) |
| H9 Procedure versions | 13 | stepping test (+ Review Focus 3, 4) |
| H10 Fill shifts by date | 9 | shifts test |
| H11 Fill confirmation | 9 | confirmation test |
| H12 Delegation archive | 11 | destination test |
| H13 Checklist builder edit | 10 | edit + save test (API-verified) |
| H14 Access log | 14 | filtered-vs-mount test |
| M1 Schedule closures (no refetch + race) | 3 | closures test |
| M2 History detail | 8 | detail test |
| M3 Procedure preview (History, Fill) | 8, 9 | History test; Fill: no test (reason in Task 9) |
| M4 Procedures to learn | 15 | preview test |
| M5 Users newbie dialog | 16 | dialog test |
| M6 Editor modal reopen | 14 | editor test |
| M7 Tree editor | 15 | no test (reason in Task 15) |
| M8 Fill shift info | 9 | no test (reason in Task 9) |
| M9 My Availability entries | 6 | snapshot-mode save test (persistence via reload) |
| M10 Compare dialog | 13 | compare test |
