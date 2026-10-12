import { createOfficeClosure, listOfficeClosures } from '../fixtures/api-setup.js';
import { expect, loginAs, STATEFUL_ORIGIN, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('office-availability/closures');
reserve.months('2095-05', '2098-10', '2098-11');

// Months 2098-10/2098-11 and 2095-05 are reserved for this file (office closures are global, not per
// group).
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

  test('clicking a day creates the closure on that day, also east of UTC', async ({ browser }) => {
    // Warsaw: local midnight is the previous day in UTC, which is where toISOString() dates slipped.
    const context = await browser.newContext({
      extraHTTPHeaders: STATEFUL_ORIGIN,
      timezoneId: 'Europe/Warsaw',
    });
    await loginAs(context, 'admin@shiftchanger.com', '1234');
    const page = await context.newPage();
    await page.clock.setFixedTime(new Date('2095-05-10T10:00:00Z'));
    await page.goto('/office-availability');
    await expect(page.getByText('May 2095', { exact: true })).toBeVisible();

    const day15 = page
      .locator('div.cursor-pointer:not(.opacity-40)')
      .filter({ has: page.getByText('15', { exact: true }) });
    await day15.click();
    await expect(page.getByText('2095-05-15', { exact: true })).toBeVisible();
    await page.getByPlaceholder('e.g. Christmas Holiday').fill('Warsaw Click Closure');
    const saved = page.waitForResponse(
      (res) => res.url().includes('/api/office-closures') && res.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Save' }).click();
    expect((await saved).ok()).toBe(true);

    const closures = await listOfficeClosures(context, '2095-05');
    expect(closures.map((c) => [c.date.substring(0, 10), c.title])).toEqual([
      ['2095-05-15', 'Warsaw Click Closure'],
    ]);
    // Rendered on the 15th, not a day late.
    await expect(day15.getByText('Warsaw Click Closure')).toBeVisible();
    await context.close();
  });
});
