import { expect, getXsrfHeader, loginAs, STATEFUL_ORIGIN, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

// L34: the Users screen's main flow (create, edit, change password), on a throwaway user only.
const reserve = reservationsFor('users/crud');
const NAME = reserve.name('Crud Throwaway');
const RENAMED = reserve.name('Crud Throwaway Renamed');
const EMAIL = reserve.name('crud-throwaway@example.test');

test.describe('Users: create, edit, change password', () => {
  test('an admin creates a user, renames them and sets a new password they can sign in with', async ({
    adminPage,
    browser,
  }) => {
    const context = adminPage.context();
    await adminPage.goto('/users');
    await expect(adminPage.getByRole('heading', { name: 'User Management' })).toBeVisible();

    await adminPage.getByRole('button', { name: 'Add User' }).click();
    const dialog = adminPage.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: 'Create New User' })).toBeVisible();
    await dialog.getByPlaceholder('John Doe').fill(NAME);
    await dialog.getByPlaceholder('john@example.com').fill(EMAIL);
    await dialog.getByPlaceholder('Minimum 8 characters').fill('first-password-1');
    await dialog.getByPlaceholder('Re-enter password').fill('first-password-1');
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();

    await expect(adminPage.getByText(`User "${NAME}" created successfully.`)).toBeVisible();
    await expect(dialog).toHaveCount(0);
    const row = adminPage.getByRole('row', { name: new RegExp(EMAIL) });
    await expect(row).toContainText(NAME);

    const users = await (await context.request.get('/api/users')).json();
    const created = (users.data ?? users).find((u) => u.email === EMAIL);
    expect(created).toBeTruthy();

    try {
      // Edit: the new name is saved and shown in the list.
      await row.getByTitle('Edit').click();
      await expect(dialog.getByRole('heading', { name: 'Edit User' })).toBeVisible();
      await expect(dialog.getByPlaceholder('John Doe')).toHaveValue(NAME);
      await dialog.getByPlaceholder('John Doe').fill(RENAMED);
      await dialog.getByRole('button', { name: 'Update', exact: true }).click();
      await expect(adminPage.getByText(`User "${RENAMED}" updated successfully.`)).toBeVisible();
      await expect(row).toContainText(RENAMED);

      // Change password: the user signs in with the new one.
      await row.getByTitle('Change Password').click();
      await dialog.getByPlaceholder('Minimum 8 characters').fill('second-password-2');
      await dialog.getByPlaceholder('Re-enter new password').fill('second-password-2');
      await dialog.getByRole('button', { name: 'Change Password' }).click();
      await expect(
        adminPage.getByText(`Password for "${RENAMED}" changed successfully.`),
      ).toBeVisible();

      const userContext = await browser.newContext({ extraHTTPHeaders: STATEFUL_ORIGIN });
      try {
        await loginAs(userContext, EMAIL, 'second-password-2');
        const me = await (await userContext.request.get('/api/user')).json();
        expect(me.name).toBe(RENAMED);
      } finally {
        await userContext.close();
      }
    } finally {
      // Active users are listed on the Schedule roster, which later specs drag from.
      const res = await context.request.post(`/api/users/${created.id}/deactivate`, {
        headers: await getXsrfHeader(context),
      });
      expect(res.ok()).toBe(true);
    }
  });
});
