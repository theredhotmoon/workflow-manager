import { createUser } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

// Changes roles on a throwaway user only: other specs log in as the seeded admin and employee.
test.describe('Users: role select', () => {
  test("changing a user's role in the edit dialog saves it and shows a role badge", async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const stamp = Date.now();
    const name = `Role Throwaway ${stamp}`;
    const user = await createUser(context, { name, email: `role-${stamp}@example.test` });
    expect(user.role).toBe('employee');

    const roleOf = async () =>
      (await (await context.request.get(`/api/users/${user.id}`)).json()).role;

    await adminPage.goto('/users');
    const row = adminPage.getByRole('row', { name: new RegExp(name) });
    await expect(row.getByTestId('role-badge')).toHaveCount(0);

    for (const [label, role] of [
      ['Schedule maintainer', 'schedule_maintainer'],
      ['Admin', 'admin'],
      ['Employee', 'employee'],
    ]) {
      await row.getByTitle('Edit').click();
      await adminPage.getByLabel('Role').selectOption({ label });
      await adminPage.getByRole('button', { name: 'Update' }).click();
      await expect(
        adminPage.getByText(`User "${name}" updated successfully.`).last(),
      ).toBeVisible();
      await expect.poll(roleOf).toBe(role);
      if (role === 'employee') {
        await expect(row.getByTestId('role-badge')).toHaveCount(0);
      } else {
        await expect(row.getByTestId('role-badge')).toHaveText(label);
      }
    }
  });

  test('an admin cannot change their own role', async ({ adminPage }) => {
    await adminPage.goto('/users');
    const row = adminPage.getByRole('row', { name: /admin@shiftchanger\.com/ });
    await row.getByTitle('Edit').click();
    await expect(adminPage.getByLabel('Role')).toBeDisabled();
    await expect(adminPage.getByLabel('Role')).toHaveValue('admin');
    await expect(adminPage.getByText('You cannot change your own role.')).toBeVisible();
  });
});
