import { createUser } from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, loginAs, STATEFUL_ORIGIN, test } from '../fixtures/auth.js';

// The API calls /my-shifts makes when it opens (the view's data and the layout's alarms).
const INITIAL_LOAD = [
  '/api/my-shifts',
  '/api/my-delegations',
  '/api/alarms/latest',
  '/api/alarms/unread-count',
];

// Works on throwaway users only: other specs log in as the seeded admin and employee.
test.describe('Users: deactivate instead of delete', () => {
  test('deactivating hides the user, "Show deactivated" brings them back, reactivate restores', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const stamp = Date.now();
    const name = `Deact Throwaway ${stamp}`;
    await createUser(context, { name, email: `deact-${stamp}@example.test` });

    await adminPage.goto('/users');
    const row = adminPage.getByRole('row', { name: new RegExp(name) });
    await row.getByRole('button', { name: 'Deactivate' }).click();
    const dialog = adminPage.getByRole('alertdialog', { name: 'Deactivate User' });
    await expect(dialog).toContainText(name);
    await expect(dialog).toContainText('They have no shifts or on-call duties from tomorrow on.');
    await dialog.getByRole('button', { name: 'Deactivate', exact: true }).click();

    await expect(adminPage.getByText(`User "${name}" deactivated.`)).toBeVisible();
    await expect(row).toHaveCount(0);

    await adminPage.getByLabel('Show deactivated').check();
    await expect(row).toBeVisible();
    await expect(row.getByText('Deactivated', { exact: true })).toBeVisible();
    // A deactivated user can only be reactivated.
    await expect(row.getByTitle('Edit')).toHaveCount(0);
    await expect(row.getByTitle('Change Password')).toHaveCount(0);

    await row.getByRole('button', { name: 'Reactivate' }).click();
    await expect(adminPage.getByText(`User "${name}" reactivated.`)).toBeVisible();
    await expect(row.getByText('Deactivated', { exact: true })).toHaveCount(0);
    await adminPage.getByLabel('Show deactivated').uncheck();
    await expect(row).toBeVisible();
    await expect(row.getByRole('button', { name: 'Deactivate' })).toBeVisible();
  });

  test('a user deactivated while logged in is sent to the login page on their next action', async ({
    adminPage,
    browser,
  }) => {
    const admin = adminPage.context();
    const stamp = Date.now();
    const email = `deact-live-${stamp}@example.test`;
    const user = await createUser(admin, { name: `Deact Live ${stamp}`, email });

    const userContext = await browser.newContext({ extraHTTPHeaders: STATEFUL_ORIGIN });
    try {
      await loginAs(userContext, email, 'password123');
      const page = await userContext.newPage();
      // Deactivate only once the page has finished loading: a load request still in flight would
      // get the 401 itself and sign them out before the "next action" below (the Next month button
      // renders before the page's data arrives).
      const loaded = Promise.all(
        INITIAL_LOAD.map((path) => page.waitForResponse((r) => new URL(r.url()).pathname === path)),
      );
      await page.goto('/my-shifts');
      await loaded;
      await expect(page.getByRole('button', { name: 'Next month' })).toBeVisible();

      const res = await admin.request.post(`/api/users/${user.id}/deactivate`, {
        headers: await getXsrfHeader(admin),
      });
      expect(res.ok()).toBe(true);

      // An in-page API call (not a navigation) answers 401: the SPA signs them out with the reason.
      await page.getByRole('button', { name: 'Next month' }).click();
      await expect(page).toHaveURL(/\/login\?redirect=\/my-shifts$/);
      await expect(page.getByText('This account is deactivated.')).toBeVisible();
    } finally {
      await userContext.close();
    }
  });
});
