import { createDelegation, getEmployeeIdByEmail } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('my-availability/my-availability');
reserve.months('2097-01', '2097-02', '2097-03', '2097-04');

// Months 2097-01/02 (delegations) and 2097-03/04 (availability entries) are reserved for this file.
test.describe('My Availability', () => {
  test('a failed load for the previous month does not wipe the delegation shown for the new month', async ({
    adminPage,
    employeePage,
  }) => {
    const regularId = await getEmployeeIdByEmail(adminPage.context(), 'regular@shiftchanger.com');
    await createDelegation(adminPage.context(), {
      name: 'Avail Race Trip',
      destination: 'Avail City',
      start: '2097-02-10',
      end: '2097-02-12',
      userIds: [regularId],
    });

    const january = await holdRequests(
      employeePage,
      (url) =>
        url.pathname === '/api/my-delegations' && url.searchParams.get('month') === '2097-01',
      { mode: 'abort' },
    );

    await employeePage.clock.setFixedTime(new Date('2097-01-15T12:00:00Z'));
    await employeePage.goto('/my-availability');
    await january.seen;
    await employeePage.getByLabel('Next month').click();
    await expect(employeePage.getByText('February 2097', { exact: true })).toBeVisible();
    const trip = employeePage.locator('[data-date="2097-02-10"]').getByText('Avail Race Trip');
    await expect(trip).toBeVisible();

    await january.releaseAndSettle();

    await expect(trip).toBeVisible();
  });

  test('an entry saved while an older reload is in flight stays on the calendar', async ({
    employeePage,
  }) => {
    await employeePage.clock.setFixedTime(new Date('2097-03-15T12:00:00Z'));
    await employeePage.goto('/my-availability');
    await expect(employeePage.getByText('March 2097', { exact: true })).toBeVisible();
    await employeePage.waitForLoadState('networkidle');

    // The month change reloads the entries; the server answers before the save below, but the page
    // only receives that (now stale) list after the save has been shown.
    const reload = await holdRequests(
      employeePage,
      (url) => url.pathname === '/api/user-availabilities',
      { mode: 'snapshot', times: 1 },
    );
    await employeePage.getByLabel('Next month').click();
    await expect(employeePage.getByText('April 2097', { exact: true })).toBeVisible();
    await reload.seen;

    const day = employeePage.locator('[data-date="2097-04-10"]');
    await day.click();
    await employeePage.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(employeePage.getByText('Availability saved.')).toBeVisible();
    await expect(day.getByText('All day')).toBeVisible();

    await reload.releaseAndSettle();

    await expect(day.getByText('All day')).toBeVisible();

    // Persistence: after a reload the entry is still there.
    await employeePage.reload();
    await expect(employeePage.getByText('March 2097', { exact: true })).toBeVisible();
    await employeePage.getByLabel('Next month').click();
    await expect(day.getByText('All day')).toBeVisible();
  });
});
