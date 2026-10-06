import { defineConfig, devices } from '@playwright/test';

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
    baseURL: 'http://localhost:5173',
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'npm run dev:hybrid',
    url: 'http://localhost:5173',
    reuseExistingServer: !process.env.CI,
    timeout: 30_000,
  },
});
