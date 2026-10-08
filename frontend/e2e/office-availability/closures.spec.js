import { createOfficeClosure } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

// Months 2098-10/2098-11 are reserved for this file (office closures are global, not per group).
test.describe('Office Availability', () => {
  test('a slow load of the previous month does not show its closures under the next month', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    await createOfficeClosure(context, { startDate: '2098-10-10', title: 'October Race Closure' });
    await createOfficeClosure(context, { startDate: '2098-11-10', title: 'November Race Closure' });

    const october = await holdRequests(
      adminPage,
      (url) =>
        url.pathname === '/api/office-closures' && url.searchParams.get('month') === '2098-10',
    );

    await adminPage.clock.setFixedTime(new Date('2098-10-15T12:00:00Z'));
    await adminPage.goto('/office-availability');
    await october.seen;
    await adminPage.getByLabel('Next month').click();
    await expect(adminPage.getByText('November 2098', { exact: true })).toBeVisible();
    const november = adminPage.getByText('November Race Closure').first();
    await expect(november).toBeVisible();

    await october.releaseAndSettle();

    await expect(november).toBeVisible();
    await expect(adminPage.getByText('October Race Closure')).toHaveCount(0);
  });

  test('a failed load of the previous month does not blank the next month', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    await createOfficeClosure(context, {
      startDate: '2098-11-20',
      title: 'November Abort Closure',
    });

    const october = await holdRequests(
      adminPage,
      (url) =>
        url.pathname === '/api/office-closures' && url.searchParams.get('month') === '2098-10',
      { mode: 'abort' },
    );

    await adminPage.clock.setFixedTime(new Date('2098-10-15T12:00:00Z'));
    await adminPage.goto('/office-availability');
    await october.seen;
    await adminPage.getByLabel('Next month').click();
    await expect(adminPage.getByText('November 2098', { exact: true })).toBeVisible();
    const november = adminPage.getByText('November Abort Closure').first();
    await expect(november).toBeVisible();

    await october.releaseAndSettle();

    await expect(november).toBeVisible();
  });
});
