// frontend/e2e/my-shifts/my-shifts.spec.js
import {
  assignShift,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

const FUTURE_MONTH = '2099-08';

test.describe('My Shifts', () => {
  // Months 2099-09/2099-10 are reserved for this test.
  test('a slow load of the previous month does not replace the month the employee moved to', async ({
    adminPage,
    employeePage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'MyShifts Race Team' },
    });
    await generateMonthShifts(context, { monthKey: '2099-10', shiftGroupId: groupId });
    const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const { schedule } = await getShiftGroupsAndShifts(context, '2099-10', groupId);
    await assignShift(context, { shiftId: schedule['2099-10-01'][0].id, userId: employeeId });

    // The view loads the current month (September, via the pinned clock) on open. Hold that load
    // until the employee has moved to October and October's shifts are shown, so September's
    // (empty) result arrives last.
    const isSeptember = (url) =>
      url.pathname.startsWith('/api/my-') && url.searchParams.get('month') === '2099-09';
    let release;
    const held = new Promise((resolve) => {
      release = resolve;
    });
    await employeePage.route(isSeptember, async (route) => {
      await held;
      await route.continue();
    });

    await employeePage.clock.setFixedTime(new Date('2099-09-15T12:00:00Z'));
    await employeePage.goto('/my-shifts');
    await employeePage.getByLabel('Next month').click();
    await expect(employeePage.getByText('October 2099', { exact: true })).toBeVisible();
    const octoberShift = employeePage
      .locator('div.rounded-2xl')
      .filter({ hasText: 'October 1, 2099' })
      .filter({ has: employeePage.getByText('Shift 1') });
    await expect(octoberShift).toBeVisible();

    const septemberDone = employeePage.waitForResponse(
      (res) => isSeptember(new URL(res.url())) && res.url().includes('/api/my-delegations'),
    );
    release();
    await septemberDone;
    await employeePage.waitForLoadState('networkidle');

    await expect(employeePage.getByText('October 2099', { exact: true })).toBeVisible();
    await expect(octoberShift).toBeVisible();
  });

  test('an employee sees their assigned shifts and can request a day off', async ({
    adminPage,
    employeePage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'MyShifts Team' },
    });
    await generateMonthShifts(context, { monthKey: FUTURE_MONTH, shiftGroupId: groupId });
    const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const { schedule } = await getShiftGroupsAndShifts(context, FUTURE_MONTH, groupId);
    const date = Object.keys(schedule).sort()[0];
    const shift = schedule[date][0];
    await assignShift(context, { shiftId: shift.id, userId: employeeId });

    // Far-future month: BOTH sides must see it as future. The browser clock is pinned (below) so the
    // view opens on July 2099, and the server (ShiftChangeRequestController rejects past shifts by
    // server time) will always consider August 2099 upcoming, however long this suite lives.
    await employeePage.clock.setFixedTime(new Date('2099-07-15T12:00:00Z'));
    await employeePage.goto('/my-shifts');
    await expect(employeePage.getByText('July 2099', { exact: true })).toBeVisible();
    await employeePage.getByLabel('Next month').click();
    await expect(employeePage.getByText('August 2099', { exact: true })).toBeVisible();

    const dateLabel = new Date(`${date}T12:00:00`).toLocaleDateString('en-US', {
      month: 'long',
      day: 'numeric',
      year: 'numeric',
    });
    const dayCard = () =>
      employeePage
        .locator('div.rounded-2xl')
        .filter({ hasText: dateLabel })
        .filter({ has: employeePage.getByText('Shift 1') });

    await expect(dayCard().getByText('Shift 1')).toBeVisible();
    await dayCard().getByRole('button', { name: 'Request off' }).click();
    await employeePage
      .getByPlaceholder("Please describe why you can't work this shift...")
      .fill('Family event');
    await employeePage.getByRole('button', { name: 'Submit Request' }).click();

    await expect(employeePage.getByText('Day-off request submitted successfully.')).toBeVisible();
    await expect(dayCard().getByText('Pending')).toBeVisible();

    // Persistence: after a reload the request must still be shown as pending.
    await employeePage.reload();
    await expect(employeePage.getByText('July 2099', { exact: true })).toBeVisible();
    await employeePage.getByLabel('Next month').click();
    await expect(employeePage.getByText('August 2099', { exact: true })).toBeVisible();
    await expect(dayCard().getByText('Shift 1')).toBeVisible();
    await expect(dayCard().getByText('Pending')).toBeVisible();
    await expect(dayCard().getByRole('button', { name: 'Request off' })).toHaveCount(0);
  });
});
