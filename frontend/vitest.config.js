import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

// Unit tests for composables and helpers, in Node by default; a file that needs a DOM says so with
// `// @vitest-environment jsdom` at its top. The one test that needs a real browser
// (BaseModal: real focus, Tab and Escape) is `*.browser.test.js` and stays on
// Playwright (playwright.unit.config.js). Coverage is V8.
export default defineConfig({
  // The router test imports the views, so .vue files must compile.
  plugins: [vue()],
  test: {
    include: ['src/**/*.test.js'],
    exclude: ['src/**/*.browser.test.js', 'node_modules/**'],
    environment: 'node',
    coverage: {
      provider: 'v8',
      // Every source module is reported, tested or not, so an untested composable shows as 0%.
      include: ['src/**/*.js'],
      exclude: ['src/**/*.test.js', 'src/**/*.harness.js', 'src/testing/**', 'src/app.js'],
      reporter: ['text-summary', 'text', 'html', 'lcov'],
      reportsDirectory: 'coverage',
    },
  },
});
