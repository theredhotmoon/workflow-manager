import { defineConfig, devices } from '@playwright/test';

// The SPA dev server port for the suite. Set E2E_PORT to run e2e next to another dev server that
// already holds 5173 (e.g. `E2E_PORT=5183 npm run test:e2e`). The backend's dev config accepts
// session cookies from any localhost port, so nothing else needs to change.
const port = Number(process.env.E2E_PORT || 5173);
const baseURL = `http://localhost:${port}`;
// Fixtures read this (e.g. the Origin header for API-side logins); workers inherit it.
process.env.E2E_BASE_URL = baseURL;

// NOTE: keep `retries` at 0 and do not use `--repeat-each`. The database is reset (migrate:fresh +
// seed) once per run in globalSetup, not per test, and every spec owns uniquely named groups and
// reserved months. A retry or repeat would re-create that data against a DB that already has it.
export default defineConfig({
  testDir: 'e2e',
  globalSetup: './e2e/global-setup.js',
  fullyParallel: false,
  workers: 1,
  reporter: 'list',
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
