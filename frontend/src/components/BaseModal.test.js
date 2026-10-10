import { createServer as createHttpServer } from 'node:http';
import { resolve as resolvePath } from 'node:path';
import { expect, test } from '@playwright/test';

// BaseModal runs in a real browser: focus, Tab and Escape are what is under test. A throwaway
// Vite server (middleware mode, its own cache dir, no file watcher, dependency scan limited to the
// harness) compiles BaseModal.harness.js for the page.
const root = resolvePath(__dirname, '../..');
// Just enough layout for real mouse clicks: full-screen overlay and backdrop, a panel on top of
// them away from the corner, so a click at (5, 5) lands on the backdrop (or the bare overlay).
const STYLE =
  '<style>.fixed{position:fixed}.inset-0{inset:0}.backdrop{position:fixed;inset:0}' +
  '.panel{position:relative;width:320px;margin:120px auto;background:#fff}</style>';
const HARNESS =
  `<!doctype html><html><head>${STYLE}</head><body><div id="app"></div>` +
  '<script type="module" src="/src/components/BaseModal.harness.js"></script></body></html>';

let vite;
let http;
let baseURL;

test.describe.configure({ mode: 'serial' });

async function closeServers() {
  const server = http;
  http = null;
  if (server?.listening) await new Promise((resolve) => server.close(() => resolve()));
  const devServer = vite;
  vite = null;
  await devServer?.close();
}

test.beforeAll(async () => {
  // A cold cache (fresh `npm ci`) pre-bundles vue first: allow this hook more than the default
  // 30 s (test.setTimeout inside a hook sets the hook's timeout).
  test.setTimeout(120_000);
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
    server: { middlewareMode: true, hmr: false, watch: null },
    // Scan only the harness, not index.html and the whole app; vue is the one dependency.
    optimizeDeps: { entries: ['src/components/BaseModal.harness.js'], include: ['vue'] },
  });
  http = createHttpServer((req, res) => {
    if (req.url === '/') {
      res.setHeader('Content-Type', 'text/html');
      res.end(HARNESS);
      return;
    }
    vite.middlewares(req, res);
  });
  try {
    await new Promise((resolve, reject) => {
      http.once('error', reject);
      http.listen(0, '127.0.0.1', resolve);
    });
  } catch (error) {
    await closeServers();
    throw error;
  }
  baseURL = `http://127.0.0.1:${http.address().port}/`;
});

test.afterAll(closeServers);

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
  for (let i = 0; i < 7; i++) {
    await page.keyboard.press('Tab');
    seen.push(await focusedId(page));
  }
  expect(seen).toEqual([
    'open-inner',
    'open-sticky-over',
    'outer-extra-toggle',
    'outer-busy',
    'outer-last',
    'outer-first',
    'open-inner',
  ]);
});

test('a control that appears while the dialog is open joins the Tab cycle', async ({ page }) => {
  await openOuterWithKeyboard(page);
  await page.locator('#outer-extra-toggle').check();
  await expect(page.locator('#outer-extra')).toBeVisible();

  await page.locator('#outer-last').focus();
  await page.keyboard.press('Tab');
  expect(await focusedId(page)).toBe('outer-extra');
  await page.keyboard.press('Tab');
  expect(await focusedId(page)).toBe('outer-first');
  await page.keyboard.press('Shift+Tab');
  expect(await focusedId(page)).toBe('outer-extra');
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
  await page.mouse.click(5, 5);
  await expect(outer).toHaveCount(0);

  await page.locator('#open-sticky').focus();
  await page.keyboard.press('Enter');
  const sticky = page.getByRole('dialog', { name: 'Sticky dialog' });
  await expect(sticky).toBeVisible();
  await page.keyboard.press('Escape');
  await page.mouse.click(5, 5);
  await expect(sticky).toBeVisible();

  // The click left focus on the page; the next Tab brings it back into the dialog.
  await page.keyboard.press('Tab');
  expect(await focusedId(page)).toBe('sticky-close');
  await page.keyboard.press('Enter');
  await expect(sticky).toHaveCount(0);
  expect(await focusedId(page)).toBe('open-sticky');
});

test('Escape on a top dialog that ignores it is swallowed: the dialog below stays open', async ({
  page,
}) => {
  const outer = await openOuterWithKeyboard(page);
  await page.locator('#open-sticky-over').focus();
  await page.keyboard.press('Enter');
  const sticky = page.getByRole('dialog', { name: 'Sticky dialog' });
  await expect(sticky).toBeVisible();
  await expect.poll(() => focusedId(page)).toBe('sticky-close');

  await page.keyboard.press('Escape');
  await page.keyboard.press('Escape');
  await expect(sticky).toBeVisible();
  await expect(outer).toBeVisible();

  await page.keyboard.press('Enter');
  await expect(sticky).toHaveCount(0);
  expect(await focusedId(page)).toBe('open-sticky-over');
  // Now the outer dialog is on top again and Escape reaches it.
  await page.keyboard.press('Escape');
  await expect(outer).toHaveCount(0);
});

test('while busy, neither Escape nor a backdrop click closes the dialog', async ({ page }) => {
  const outer = await openOuterWithKeyboard(page);
  await page.locator('#outer-busy').check();
  await page.keyboard.press('Escape');
  await page.mouse.click(5, 5);
  await expect(outer).toBeVisible();

  await page.locator('#outer-busy').uncheck();
  await page.keyboard.press('Escape');
  await expect(outer).toHaveCount(0);
});

test('when the opener is gone, focus goes to return-focus, else to <main>, not <body>', async ({
  page,
}) => {
  await page.goto(baseURL);
  const dialog = page.getByRole('dialog', { name: 'Vanish dialog' });

  // No return-focus: the page's <main> takes it.
  await page.locator('#vanish-default').focus();
  await page.keyboard.press('Enter');
  await expect(dialog).toBeVisible();
  await expect(page.locator('#vanish-default')).toHaveCount(0);
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
  expect(await page.evaluate(() => document.activeElement?.tagName)).toBe('MAIN');

  // return-focus="#after" wins.
  await page.locator('#vanish-custom').focus();
  await page.keyboard.press('Enter');
  await expect(dialog).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
  expect(await focusedId(page)).toBe('after');
});

test('dragging a selection out of a field onto the backdrop does not close the dialog', async ({
  page,
}) => {
  const outer = await openOuterWithKeyboard(page);
  const field = page.locator('#outer-first');
  await field.fill('typed text that must survive');
  const box = await field.boundingBox();

  // Press in the field, release over the backdrop: the browser fires click on the overlay.
  await page.mouse.move(box.x + 5, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(5, 5, { steps: 5 });
  await page.mouse.up();
  await expect(outer).toBeVisible();
  await expect(field).toHaveValue('typed text that must survive');

  // The reverse (press on the backdrop, release in the panel) does not close it either.
  await page.mouse.move(5, 5);
  await page.mouse.down();
  await page.mouse.move(box.x + 5, box.y + box.height / 2, { steps: 5 });
  await page.mouse.up();
  await expect(outer).toBeVisible();

  // A real click on the backdrop still does.
  await page.mouse.click(5, 5);
  await expect(outer).toHaveCount(0);
});

test('the stand-in focus target is only temporary: <main> loses tabindex once focus moves on', async ({
  page,
}) => {
  await page.goto(baseURL);
  await page.locator('#vanish-default').focus();
  await page.keyboard.press('Enter');
  await page.keyboard.press('Escape');
  const main = page.locator('main');
  await expect(main).toBeFocused();
  await expect(main).toHaveAttribute('tabindex', '-1');
  await expect(main).toHaveAttribute('data-focus-fallback', '');

  await page.keyboard.press('Tab');
  await expect(main).not.toBeFocused();
  await expect(main).not.toHaveAttribute('tabindex');
  await expect(main).not.toHaveAttribute('data-focus-fallback');
});
