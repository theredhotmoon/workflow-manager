import { createServer as createHttpServer } from 'node:http';
import { resolve as resolvePath } from 'node:path';
import { expect, test } from '@playwright/test';

// BaseModal runs in a real browser: focus, Tab and Escape are what is under test. A throwaway
// Vite server (middleware mode, its own cache dir) compiles BaseModal.harness.js for the page.
const root = resolvePath(__dirname, '../..');
const HARNESS =
  '<!doctype html><html><body><div id="app"></div>' +
  '<script type="module" src="/src/components/BaseModal.harness.js"></script></body></html>';

let vite;
let http;
let baseURL;

test.describe.configure({ mode: 'serial' });

test.beforeAll(async () => {
  // Vite and its Vue plugin are ESM-only: import() them rather than let the runner require() them.
  const { createServer: createViteServer } = await import('vite');
  const { default: vue } = await import('@vitejs/plugin-vue');
  vite = await createViteServer({
    configFile: false,
    root,
    logLevel: 'silent',
    appType: 'custom',
    cacheDir: 'node_modules/.vite-unit-base-modal',
    plugins: [vue()],
    server: { middlewareMode: true, hmr: false },
  });
  http = createHttpServer((req, res) => {
    if (req.url === '/') {
      res.setHeader('Content-Type', 'text/html');
      res.end(HARNESS);
      return;
    }
    vite.middlewares(req, res);
  });
  await new Promise((resolve) => http.listen(0, '127.0.0.1', resolve));
  baseURL = `http://127.0.0.1:${http.address().port}/`;
});

test.afterAll(async () => {
  await new Promise((resolve) => (http ? http.close(resolve) : resolve()));
  await vite?.close();
});

const focusedId = (page) => page.evaluate(() => document.activeElement?.id || '');

async function openOuterWithKeyboard(page) {
  await page.goto(baseURL);
  await page.locator('#open-outer').focus();
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog', { name: 'Outer dialog' });
  await expect(dialog).toBeVisible();
  return dialog;
}

test('the dialog is modal, named by its title, described, and takes focus on open', async ({
  page,
}) => {
  const dialog = await openOuterWithKeyboard(page);
  await expect(dialog).toHaveAttribute('aria-modal', 'true');
  await expect(dialog).toHaveAccessibleDescription('Outer description');
  await expect.poll(() => focusedId(page)).toBe('outer-first');
});

test('Tab wraps from the last element to the first, Shift+Tab from the first to the last', async ({
  page,
}) => {
  await openOuterWithKeyboard(page);
  await expect.poll(() => focusedId(page)).toBe('outer-first');

  await page.keyboard.press('Shift+Tab');
  expect(await focusedId(page)).toBe('outer-last');

  await page.keyboard.press('Tab');
  expect(await focusedId(page)).toBe('outer-first');

  // A full lap stays inside and skips the hidden button.
  const seen = [];
  for (let i = 0; i < 4; i++) {
    await page.keyboard.press('Tab');
    seen.push(await focusedId(page));
  }
  expect(seen).toEqual(['open-inner', 'outer-last', 'outer-first', 'open-inner']);
});

test('focus that left the dialog comes back in on the next Tab', async ({ page }) => {
  await openOuterWithKeyboard(page);
  await page.locator('#before').evaluate((el) => el.focus());
  await page.keyboard.press('Tab');
  expect(await focusedId(page)).toBe('outer-first');
});

test('Escape closes only the top of two stacked dialogs; focus returns to each opener', async ({
  page,
}) => {
  const outer = await openOuterWithKeyboard(page);
  await page.locator('#open-inner').focus();
  await page.keyboard.press('Enter');
  const inner = page.getByRole('alertdialog', { name: 'Inner dialog' });
  await expect(inner).toBeVisible();
  await expect(inner).toHaveAttribute('aria-modal', 'true');
  // initial-focus picks OK over the first focusable element (Cancel).
  await expect.poll(() => focusedId(page)).toBe('inner-ok');

  // Tab cycles in the top dialog, not the one under it.
  await page.keyboard.press('Tab');
  expect(await focusedId(page)).toBe('inner-cancel');

  await page.keyboard.press('Escape');
  await expect(inner).toHaveCount(0);
  await expect(outer).toBeVisible();
  expect(await focusedId(page)).toBe('open-inner');

  await page.keyboard.press('Escape');
  await expect(outer).toHaveCount(0);
  expect(await focusedId(page)).toBe('open-outer');
});

test('a backdrop click closes; with close-on-backdrop and close-on-escape off neither does', async ({
  page,
}) => {
  const outer = await openOuterWithKeyboard(page);
  await page.locator('.backdrop').dispatchEvent('click');
  await expect(outer).toHaveCount(0);

  await page.locator('#open-sticky').focus();
  await page.keyboard.press('Enter');
  const sticky = page.getByRole('dialog', { name: 'Sticky dialog' });
  await expect(sticky).toBeVisible();
  await page.keyboard.press('Escape');
  await sticky.evaluate((el) => el.parentElement.click());
  await expect(sticky).toBeVisible();

  await expect.poll(() => focusedId(page)).toBe('sticky-close');
  await page.keyboard.press('Enter');
  await expect(sticky).toHaveCount(0);
  expect(await focusedId(page)).toBe('open-sticky');
});
