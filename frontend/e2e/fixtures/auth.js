// frontend/e2e/fixtures/auth.js
//
// Specs must import `test` (and `expect`) from THIS file, not from '@playwright/test': the
// per-test auto fixture below resets the API rate limiter, and a spec using the plain Playwright
// `test` silently skips it and can hit the 60 req/min throttle.
import { execFileSync } from 'node:child_process';
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

// Sanctum only treats a request as stateful (session auth) when Origin/Referer is a configured
// frontend domain; context.request sends neither, so API calls after login would be 401.
const STATEFUL_ORIGIN = { Origin: 'http://localhost:5173' };

export const test = base.extend({
  // The backend hard-codes 60 req/min per user for /api, and the admin user is shared across all
  // spec files, so each test starts with a fresh limiter budget.
  resetRateLimit: [
    // biome-ignore lint/correctness/noEmptyPattern: Playwright requires a destructured first argument
    async ({}, use) => {
      try {
        execFileSync(
          'docker',
          [
            'exec',
            '-u',
            'www-data',
            '-e',
            'APP_ENV=testing',
            'shift_changer_backend',
            'php',
            'artisan',
            'cache:clear',
          ],
          { stdio: 'pipe' },
        );
      } catch (err) {
        throw new Error(
          'e2e rate-limit reset failed via `docker exec shift_changer_backend`. ' +
            'Is the shift_changer_backend container running? (docker ps)\n' +
            (err.stderr?.toString() || err.message),
        );
      }
      await use();
    },
    { auto: true },
  ],
  adminPage: async ({ browser }, use) => {
    const context = await browser.newContext({ extraHTTPHeaders: STATEFUL_ORIGIN });
    await loginAs(context, 'admin@shiftchanger.com', '1234');
    const page = await context.newPage();
    await use(page);
    await context.close();
  },
  employeePage: async ({ browser }, use) => {
    const context = await browser.newContext({ extraHTTPHeaders: STATEFUL_ORIGIN });
    await loginAs(context, 'regular@shiftchanger.com', 'password123');
    const page = await context.newPage();
    await use(page);
    await context.close();
  },
});

export { expect };
