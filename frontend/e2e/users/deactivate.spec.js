import { createUser } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

// Works on a throwaway user only: other specs log in as the seeded admin and employee.
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
    const dialog = adminPage.getByRole('dialog', { name: 'Deactivate User' });
    await expect(dialog).toContainText(name);
    await expect(dialog).toContainText('They have no shifts from today on.');
    await dialog.getByRole('button', { name: 'Deactivate', exact: true }).click();

    await expect(adminPage.getByText(`User "${name}" deactivated.`)).toBeVisible();
    await expect(row).toHaveCount(0);

    await adminPage.getByLabel('Show deactivated').check();
    await expect(row).toBeVisible();
    await expect(row.getByText('Deactivated', { exact: true })).toBeVisible();

    await row.getByRole('button', { name: 'Reactivate' }).click();
    await expect(adminPage.getByText(`User "${name}" reactivated.`)).toBeVisible();
    await expect(row.getByText('Deactivated', { exact: true })).toHaveCount(0);
    await adminPage.getByLabel('Show deactivated').uncheck();
    await expect(row).toBeVisible();
    await expect(row.getByRole('button', { name: 'Deactivate' })).toBeVisible();
  });
});
