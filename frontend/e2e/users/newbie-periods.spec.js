import { createNewbiePeriod, getEmployeeIdByEmail } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

// Newbie-period dates in 2097-07 are reserved for this file.
test.describe('Users: newbie periods', () => {
  test('reopening the dialog for another user is not overwritten by the first user’s slow periods', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const test2Id = await getEmployeeIdByEmail(context, 'test2@shiftchanger.com');
    const marcinId = await getEmployeeIdByEmail(context, 'marcin@shiftchanger.com');
    await createNewbiePeriod(context, {
      userId: test2Id,
      startDate: '2097-07-01',
      endDate: '2097-07-10',
    });
    await createNewbiePeriod(context, {
      userId: marcinId,
      startDate: '2097-07-20',
      endDate: '2097-07-25',
    });

    const slowTest2 = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/newbie-periods/user/${test2Id}`,
    );

    await adminPage.goto('/users');
    await adminPage
      .getByRole('row', { name: /Test Employee 2/ })
      .getByTitle('Mark as Newbie')
      .click();
    await slowTest2.seen;
    await adminPage.getByRole('button', { name: 'Close', exact: true }).click();
    await adminPage
      .getByRole('row', { name: /Marcin Kefir/ })
      .getByTitle('Mark as Newbie')
      .click();
    const marcinPeriod = adminPage.getByText('2097-07-20 → 2097-07-25');
    await expect(marcinPeriod).toBeVisible();

    await slowTest2.releaseAndSettle();

    await expect(marcinPeriod).toBeVisible();
    await expect(adminPage.getByText('2097-07-01 → 2097-07-10')).toHaveCount(0);
  });
});
