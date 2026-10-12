import { defineConfig, devices } from '@playwright/test';

// The SPA dev server port for the suite. Set E2E_PORT to run e2e next to another dev server that
// already holds 5173 (e.g. `E2E_PORT=5183 npm run test:e2e`). The backend's dev config accepts
// session cookies from any localhost port, so nothing else needs to change.
const port = Number(process.env.E2E_PORT || 5173);
const baseURL = `http://localhost:${port}`;
// Fixtures read this (e.g. the Origin header for API-side logins); workers inherit it.
process.env.E2E_BASE_URL = baseURL;

// The database is reset (migrate:fresh + seed) once per run in globalSetup, not per test, and every
// spec owns the months, days and names it writes data into (claimed in e2e/fixtures/reserve.js, which
// throws on a second claim). So do not use `--repeat-each` for whole-suite runs: a repeat re-creates
// that data against a DB that already has it.
// CI retries a failed test once so that a single flake does not fail the job; a retry runs against
// the data its first attempt left behind, so a real failure still fails. Flaky tests (failed, then
// passed on retry) are listed in the job summary by e2e/reporters/flaky-summary.js. Locally there are
// no retries, so a flake shows up as a failure.
export default defineConfig({
  testDir: 'e2e',
  globalSetup: './e2e/global-setup.js',
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['./e2e/reporters/flaky-summary.js']] : 'list',
  use: {
    baseURL,
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'npm run dev:hybrid',
    url: baseURL,
    env: { VITE_PORT: String(port) },
    reuseExistingServer: !process.env.CI,
    timeout: 30_000,
  },
});
