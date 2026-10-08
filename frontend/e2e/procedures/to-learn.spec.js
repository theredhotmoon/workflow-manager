import { createProcedure, getEmployeeIdByEmail } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

test.describe('Procedures to learn', () => {
  test('closing a slow procedure and opening another keeps the second one', async ({
    adminPage,
    employeePage,
  }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const first = await createProcedure(context, { title: 'Learn Race First', ownerId: adminId });
    await createProcedure(context, { title: 'Learn Race Second', ownerId: adminId });

    // Both loads of the first procedure (the click and the hashchange it triggers) are held.
    const slowFirst = await holdRequests(
      employeePage,
      (url) => url.pathname === `/api/procedures/${first.id}`,
    );

    await employeePage.goto('/procedures-to-learn');
    await employeePage.getByRole('heading', { level: 2, name: 'Learn Race First' }).click();
    await slowFirst.seen;
    await employeePage.getByLabel('Close procedure').click();
    await employeePage.getByRole('heading', { level: 2, name: 'Learn Race Second' }).click();
    const modalTitle = employeePage
      .locator('div.fixed.inset-0.z-50')
      .getByRole('heading', { level: 2 });
    await expect(modalTitle).toHaveText('Learn Race Second');

    await slowFirst.releaseAndSettle();

    await expect(modalTitle).toHaveText('Learn Race Second');
  });
});
