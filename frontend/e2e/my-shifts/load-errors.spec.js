// frontend/e2e/my-shifts/load-errors.spec.js
import {
  assignShift,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('my-shifts/load-errors');

// Month 2099-11 is reserved for this file.
const MONTH = reserve.month('2099-11');

const fail500 = (route) =>
  route.fulfill({
    status: 500,
    contentType: 'application/json',
    body: JSON.stringify({ message: 'Server Error' }),
  });

const isMyShifts = (url) => url.pathname === '/api/my-shifts';
const isMyDelegations = (url) => url.pathname === '/api/my-delegations';

test.describe('My Shifts: failed loads', () => {
  let shiftCard;
  let seeded = false;

  test.beforeEach(async ({ adminPage, employeePage }) => {
    // One shift on 2099-11-03 for the employee, created once per worker (a failure restarts it).
    if (!seeded) {
      const context = adminPage.context();
      const groupId = await createShiftGroupWithStreakRule(context, {
        group: { name: `MyShifts Errors ${Date.now()}` },
      });
      await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });
      const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
      const { schedule } = await getShiftGroupsAndShifts(context, MONTH, groupId);
      await assignShift(context, { shiftId: schedule[`${MONTH}-03`][0].id, userId: employeeId });
      seeded = true;
    }

    await employeePage.clock.setFixedTime(new Date(`${MONTH}-15T12:00:00Z`));
    shiftCard = employeePage
      .locator('div.rounded-2xl')
      .filter({ hasText: 'November 3, 2099' })
      .filter({ has: employeePage.getByText('Shift 1') });
  });

  test('a failed shifts load shows an error with Retry, not "no shifts"', async ({
    employeePage,
  }) => {
    await employeePage.route(isMyShifts, fail500);
    await employeePage.goto('/my-shifts');

    // Scoped to the page body: the toast's live regions (role alert/status) are always present.
    const error = employeePage.locator('main').getByRole('alert');
    await expect(error).toContainText('Could not load your shifts');
    await expect(employeePage.getByText('No shifts or delegations for this month.')).toHaveCount(0);

    await employeePage.unroute(isMyShifts);
    await error.getByRole('button', { name: 'Retry' }).click();

    await expect(shiftCard).toBeVisible();
    await expect(employeePage.locator('main').getByRole('alert')).toHaveCount(0);
  });

  test('when only delegations fail, shifts still show with a notice', async ({ employeePage }) => {
    await employeePage.route(isMyDelegations, fail500);
    await employeePage.goto('/my-shifts');

    await expect(shiftCard).toBeVisible();
    await expect(employeePage.locator('main').getByRole('status')).toContainText(
      "Delegations couldn't be loaded",
    );
  });
});
