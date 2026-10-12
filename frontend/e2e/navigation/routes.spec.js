import { expect, STATEFUL_ORIGIN, test } from '../fixtures/auth.js';

// L23: schedule-manager pages are guarded in the router, not only hidden in the nav.
// L25: an unknown address shows a "not found" page inside the layout (signed out: login first).
// F-d: on a phone the nav links live in the account menu instead of a sideways-scrolling strip.

const welcome = (page) => page.getByRole('heading', { name: /Welcome back/ });
// The top bar (the first <nav>, before the page's own content).
const header = (page) => page.locator('nav').first();

test.describe('Route guards', () => {
  test('an employee opening on-call or settlement periods lands on the dashboard', async ({
    employeePage,
  }) => {
    await employeePage.goto('/dashboard');
    await expect(welcome(employeePage)).toBeVisible();
    await expect(employeePage.getByRole('link', { name: 'On-Call' })).toHaveCount(0);
    await expect(employeePage.getByRole('link', { name: 'Settlement Periods' })).toHaveCount(0);

    for (const path of ['/on-call', '/settlement-periods']) {
      await employeePage.goto(path);
      await expect(employeePage).toHaveURL(/\/dashboard$/);
      await expect(welcome(employeePage)).toBeVisible();
    }
    await expect(employeePage.getByRole('heading', { name: 'On-Call Duty' })).toHaveCount(0);
  });

  test('a schedule maintainer still opens them', async ({ maintainerPage }) => {
    await maintainerPage.goto('/on-call');
    await expect(maintainerPage.getByRole('heading', { name: 'On-Call Duty' })).toBeVisible();
    await maintainerPage.goto('/settlement-periods');
    await expect(maintainerPage.getByRole('heading', { name: 'Settlement Periods' })).toBeVisible();
  });
});

test.describe('Unknown addresses', () => {
  test('show "Page not found" inside the layout, with a way home', async ({ employeePage }) => {
    await employeePage.goto('/no-such-page/at-all');
    await expect(employeePage.getByRole('heading', { name: 'Page not found' })).toBeVisible();
    // Inside the app's layout: the header is there.
    await expect(employeePage.getByRole('button', { name: 'Notifications' })).toBeVisible();

    await employeePage.getByRole('link', { name: 'Go to the dashboard' }).click();
    await expect(employeePage).toHaveURL(/\/dashboard$/);
    await expect(welcome(employeePage)).toBeVisible();
  });

  test('send a signed-out visitor to login first', async ({ browser }) => {
    const context = await browser.newContext({ extraHTTPHeaders: STATEFUL_ORIGIN });
    const page = await context.newPage();
    try {
      await page.goto('/no-such-page');
      await expect(page).toHaveURL(/\/login\?redirect=\/no-such-page$/);
      await expect(page.getByRole('heading', { name: 'Page not found' })).toHaveCount(0);
    } finally {
      await context.close();
    }
  });
});

test.describe('Phone header', () => {
  test('the nav links move into the account menu below 640 px', async ({ employeePage }) => {
    await employeePage.setViewportSize({ width: 390, height: 844 });
    await employeePage.goto('/dashboard');
    await expect(welcome(employeePage)).toBeVisible();

    // No sideways strip: the header's Procedures link isn't shown until the menu opens.
    const pages = employeePage.getByRole('navigation', { name: 'Pages' });
    await expect(pages).toHaveCount(0);
    await expect(header(employeePage).locator('a[href="/procedures"]')).toBeHidden();

    await employeePage.getByRole('button', { name: /account menu/ }).click();
    await expect(pages.getByRole('link', { name: 'Dashboard' })).toHaveAttribute(
      'aria-current',
      'page',
    );
    await pages.getByRole('link', { name: 'Procedures', exact: true }).click();
    await expect(employeePage).toHaveURL(/\/procedures$/);
  });

  test('at desktop width the links stay in the header and not in the menu', async ({
    employeePage,
  }) => {
    await employeePage.goto('/dashboard');
    await expect(header(employeePage).locator('a[href="/procedures"]')).toBeVisible();
    await employeePage.getByRole('button', { name: /account menu/ }).click();
    await expect(employeePage.getByRole('navigation', { name: 'Pages' })).toBeHidden();
  });
});
