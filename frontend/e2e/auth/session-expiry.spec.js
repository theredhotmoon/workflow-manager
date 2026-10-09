import { expect, test } from '../fixtures/auth.js';

// M16: a session that ends after the page loaded, and logging out when the first attempt fails.

async function openAccountMenu(page) {
  await page.getByRole('button', { name: /account menu/ }).click();
}

test.describe('Session expiry and logout', () => {
  test('an expired session sends the next action to login, and signing in returns there', async ({
    adminPage,
  }) => {
    const loaded = adminPage.waitForResponse(
      (r) => new URL(r.url()).pathname === '/api/my-delegations',
    );
    await adminPage.goto('/my-shifts');
    await loaded;
    await expect(adminPage.getByRole('button', { name: 'Next month' })).toBeVisible();

    // The session is gone (expired, or ended elsewhere) while the page stays open.
    await adminPage.context().clearCookies();
    await adminPage.getByRole('button', { name: 'Next month' }).click();

    await expect(adminPage).toHaveURL(/\/login\?redirect=\/my-shifts$/);

    await adminPage.getByLabel('Email Address').fill('admin@shiftchanger.com');
    await adminPage.getByLabel('Password').fill('1234');
    await adminPage.getByRole('button', { name: 'Sign In' }).click();

    await expect(adminPage).toHaveURL(/\/my-shifts$/);
    await expect(adminPage.getByRole('heading', { name: 'My Shifts' })).toBeVisible();
  });

  test('logging out survives a stale CSRF token (419) on the first attempt', async ({
    adminPage,
  }) => {
    let attempts = 0;
    await adminPage.route('**/api/logout', async (route) => {
      attempts += 1;
      if (attempts === 1) {
        await route.fulfill({
          status: 419,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'CSRF token mismatch.' }),
        });
      } else {
        await route.continue();
      }
    });
    const csrfRefreshed = adminPage.waitForRequest((r) => r.url().endsWith('/sanctum/csrf-cookie'));

    await adminPage.goto('/dashboard');
    await expect(adminPage.getByRole('heading', { name: /Welcome back/ })).toBeVisible();
    await openAccountMenu(adminPage);
    await adminPage.getByRole('button', { name: 'Logout' }).click();

    await csrfRefreshed;
    await expect(adminPage).toHaveURL(/\/login$/);
    expect(attempts).toBe(2);

    // Really signed out on the server: a protected page asks for login again.
    await adminPage.goto('/dashboard');
    await expect(adminPage).toHaveURL(/\/login\?redirect=\/dashboard$/);
  });

  test('a logout that keeps failing says so and does not pretend to sign out', async ({
    adminPage,
  }) => {
    await adminPage.route('**/api/logout', (route) =>
      route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Server Error' }),
      }),
    );

    await adminPage.goto('/dashboard');
    await expect(adminPage.getByRole('heading', { name: /Welcome back/ })).toBeVisible();
    await openAccountMenu(adminPage);
    await adminPage.getByRole('button', { name: 'Logout' }).click();

    await expect(adminPage.getByRole('alert')).toContainText('You are still signed in.');
    await expect(adminPage).toHaveURL(/\/dashboard$/);
  });

  test('a logout refused twice for a stale token (419) says why', async ({ adminPage }) => {
    await adminPage.route('**/api/logout', (route) =>
      route.fulfill({
        status: 419,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'CSRF token mismatch.' }),
      }),
    );

    await adminPage.goto('/dashboard');
    await expect(adminPage.getByRole('heading', { name: /Welcome back/ })).toBeVisible();
    await openAccountMenu(adminPage);
    await adminPage.getByRole('button', { name: 'Logout' }).click();

    await expect(adminPage.getByRole('alert')).toHaveText(
      /^Your session token expired; reload the page and try again\. You are still signed in\.\s*✕$/,
    );
    await expect(adminPage).toHaveURL(/\/dashboard$/);
  });

  test('the login page sends a still signed-in user on to its redirect', async ({ adminPage }) => {
    // E.g. a stale XSRF token gave a 419 while the session itself is fine.
    await adminPage.goto('/login?redirect=/alarms');
    await expect(adminPage).toHaveURL(/\/alarms$/);
    await expect(adminPage.getByRole('heading', { name: 'Notifications' })).toBeVisible();

    // Never to another origin.
    await adminPage.goto('/login?redirect=//evil.example/');
    await expect(adminPage).toHaveURL(/\/dashboard$/);
  });
});
