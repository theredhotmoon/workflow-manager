import { expect, test } from '../fixtures/auth.js';

// M16: a session that ends after the page loaded, and logging out when the first attempt fails.

// The page's /api/ requests that have not finished (or failed) yet.
function trackApiRequests(page) {
  const pending = new Set();
  const isApi = (request) => new URL(request.url()).pathname.startsWith('/api/');
  page.on('request', (request) => isApi(request) && pending.add(request));
  page.on('requestfinished', (request) => pending.delete(request));
  page.on('requestfailed', (request) => pending.delete(request));
  return pending;
}

async function openAccountMenu(page) {
  await page.getByRole('button', { name: /account menu/ }).click();
}

test.describe('Session expiry and logout', () => {
  test('an expired session sends the next action to login, and signing in returns there', async ({
    adminPage,
  }) => {
    // Clearing cookies only simulates an expired session if no request of the signed-in page is
    // still in flight: its response carries Set-Cookie and would put the (still valid) session
    // cookie back, so the next click succeeds and the page stays put. The page fires more than the
    // view's own loads (the header bell's /api/alarms/latest and /api/alarms/unread-count), so wait
    // for every API request to settle, not one named response (on a slow CI runner the bell's
    // responses landed after clearCookies() and the redirect never came).
    const inFlight = trackApiRequests(adminPage);
    await adminPage.goto('/my-shifts');
    await expect(adminPage.getByRole('button', { name: 'Next month' })).toBeVisible();
    await expect.poll(() => inFlight.size).toBe(0);

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

    // The app-wide toast keeps an (empty) alert region on every page: pick the logout error.
    await expect(
      adminPage.getByRole('alert').filter({ hasText: 'You are still signed in.' }),
    ).toBeVisible();
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

    await expect(
      adminPage.getByRole('alert').filter({ hasText: 'You are still signed in.' }),
    ).toHaveText(
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
