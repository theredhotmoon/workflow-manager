// frontend/e2e/procedures/tree-editor.spec.js
import { createProcedure, getEmployeeIdByEmail } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

test.describe('Procedure tree editor', () => {
  test('renders the tree and the side panels without a page error', async ({ adminPage }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const title = 'Tree Editor Render Check';
    await createProcedure(context, { title, ownerId: adminId });

    const pageErrors = [];
    adminPage.on('pageerror', (err) => pageErrors.push(err.message));

    await adminPage.goto('/procedures/tree-editor');
    await expect(adminPage.getByRole('heading', { name: 'Unassigned Procedures' })).toBeVisible();
    // Every procedure is part of the tree (no parent = a root node), so it shows in "All Assigned".
    const allAssigned = adminPage.locator('div', {
      has: adminPage.getByRole('heading', { name: 'All Assigned' }),
    });
    await expect(allAssigned.getByText(title, { exact: true }).first()).toBeVisible();
    await expect(adminPage.getByText('All procedures are assigned!')).toBeVisible();

    expect(pageErrors).toEqual([]);
  });
});
