import { defineConfig } from '@playwright/test';

// Browser-mode unit tests only: `*.browser.test.js` drive a real Chromium page (BaseModal's focus,
// Tab and Escape handling). Every other unit test runs under Vitest (vitest.config.js).
export default defineConfig({
  testDir: 'src',
  testMatch: '**/*.browser.test.js',
  fullyParallel: true,
  reporter: 'list',
});
