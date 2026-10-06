// frontend/e2e/my-shifts/my-shifts.spec.js
import {
  assignShift,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

const FUTURE_MONTH = '2027-08';

test.describe('My Shifts', () => {
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

    // Pin the browser clock so the view opens on July 2027 regardless of the real date.
    await employeePage.clock.setFixedTime(new Date('2027-07-15T12:00:00Z'));
    await employeePage.goto('/my-shifts');
    await expect(employeePage.getByText('July 2027', { exact: true })).toBeVisible();
    await employeePage.getByLabel('Next month').click();
    await expect(employeePage.getByText('August 2027', { exact: true })).toBeVisible();

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
    await expect(employeePage.getByText('July 2027', { exact: true })).toBeVisible();
    await employeePage.getByLabel('Next month').click();
    await expect(employeePage.getByText('August 2027', { exact: true })).toBeVisible();
    await expect(dayCard().getByText('Shift 1')).toBeVisible();
    await expect(dayCard().getByText('Pending')).toBeVisible();
    await expect(dayCard().getByRole('button', { name: 'Request off' })).toHaveCount(0);
  });
});
