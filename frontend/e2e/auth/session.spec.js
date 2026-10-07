import { expect, test } from '../fixtures/auth.js';

// A rate-limited (429) or failing (5xx) /api/user says nothing about whether the session is valid,
// so it must never send the user to /login. Only 401/419 mean "logged out".
function throttleUserCall(page, throttledCalls) {
  const state = { calls: 0 };
  // Record every path pushed/replaced in the history API: after a wrongful redirect to /login the
  // guest guard bounces a still-valid session straight back, so the final URL alone cannot reveal
  // the logout.
  page.addInitScript(() => {
    window.__visited = [location.pathname];
    for (const method of ['pushState', 'replaceState']) {
      const original = history[method];
      history[method] = function (state, title, url) {
        if (url) window.__visited.push(new URL(url, location.href).pathname);
        return original.call(this, state, title, url);
      };
    }
  });
  return page
    .route('**/api/user', async (route) => {
      state.calls += 1;
      if (throttledCalls.includes(state.calls)) {
        await route.fulfill({
          status: 429,
          headers: { 'Retry-After': '1' },
          contentType: 'application/json',
          body: JSON.stringify({ message: 'Too Many Attempts.' }),
        });
      } else {
        await route.continue();
      }
    })
    .then(() => state);
}

const visitedPaths = (page) => page.evaluate(() => window.__visited);

test.describe('Session survives rate limiting', () => {
  test('a 429 from the router guard check retries and keeps the user on the page', async ({
    adminPage,
  }) => {
    const state = await throttleUserCall(adminPage, [1]);

    await adminPage.goto('/alarms');

    await expect(adminPage.getByRole('heading', { name: 'Notifications' })).toBeVisible();
    expect(await visitedPaths(adminPage)).not.toContain('/login');
    expect(state.calls).toBeGreaterThanOrEqual(2);
  });

  test('a 429 on the dashboard user fetch does not redirect to login', async ({ adminPage }) => {
    // Call 1 is the router guard; call 2 is the dashboard's own fetch.
    await throttleUserCall(adminPage, [2]);

    await adminPage.goto('/dashboard');

    // The user's name only renders when the dashboard's own fetch recovered from the 429.
    await expect(adminPage.getByRole('heading', { name: /Welcome back, \w+/ })).toBeVisible();
    await expect(adminPage).toHaveURL(/\/dashboard$/);
    expect(await visitedPaths(adminPage)).not.toContain('/login');
  });
});
