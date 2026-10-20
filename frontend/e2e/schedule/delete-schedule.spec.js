// frontend/e2e/schedule/delete-schedule.spec.js
import {
  archiveShiftGroup,
  assignShift,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('schedule/delete-schedule');

// Month reserved for this file: 2093-06.
const MONTH = reserve.month('2093-06');
const DAY = '2093-06-03';
const GROUP = reserve.name('Delete Month Team');

test.describe('Delete Schedule', () => {
  test('asks twice; cancelling either step keeps the month, confirming removes every shift and assignment', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: GROUP } });
    try {
      await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });
      const { schedule } = await getShiftGroupsAndShifts(context, MONTH, groupId);
      const shiftCount = Object.values(schedule).flat().length;
      expect(shiftCount).toBe(30);
      await assignShift(context, {
        shiftId: schedule[DAY][0].id,
        userId: await getEmployeeIdByEmail(context, 'regular@shiftchanger.com'),
      });
      const shiftsOnServer = async () =>
        Object.values((await getShiftGroupsAndShifts(context, MONTH, groupId)).schedule).flat();

      const clearRequests = [];
      adminPage.on('request', (req) => {
        if (
          req.method() === 'DELETE' &&
          new URL(req.url()).pathname === '/api/schedule/clear-month'
        ) {
          clearRequests.push(req.url());
        }
      });

      await adminPage.goto(`/schedule/${MONTH}`);
      const loaded = adminPage.waitForResponse(
        (res) =>
          res.url().includes('/api/schedule?') && res.url().includes(`shift_group_id=${groupId}`),
      );
      await adminPage.getByRole('button', { name: new RegExp(GROUP) }).click();
      await loaded;
      const cell = adminPage.locator(`[data-date="${DAY}"]`);
      await expect(cell).toContainText('Regular Employee');
      const zones = adminPage.locator('[data-shift-id]');
      await expect(zones).toHaveCount(shiftCount);

      const deleteButton = adminPage.getByRole('button', { name: 'Delete Schedule' });
      const stepOne = adminPage.getByRole('alertdialog', { name: /Delete .* Schedule\?/ });
      const stepTwo = adminPage.getByRole('alertdialog', { name: 'This cannot be undone!' });

      // 1. Cancel on the first step: nothing happens.
      await deleteButton.click();
      await expect(stepOne).toBeVisible();
      await expect(stepOne).toContainText('remove all shifts and assigned users for this month');
      await stepOne.getByRole('button', { name: 'Cancel' }).click();
      await expect(stepOne).toBeHidden();
      await expect(zones).toHaveCount(shiftCount);

      // 2. Cancel on the final step: still nothing, and no request was sent.
      await deleteButton.click();
      await stepOne.getByRole('button', { name: 'Yes, continue' }).click();
      await expect(stepOne).toBeHidden();
      await expect(stepTwo).toBeVisible();
      await expect(stepTwo).toContainText('permanently deleted');
      await stepTwo.getByRole('button', { name: 'Cancel' }).click();
      await expect(stepTwo).toBeHidden();
      await expect(zones).toHaveCount(shiftCount);
      expect(clearRequests).toEqual([]);
      expect(await shiftsOnServer()).toHaveLength(shiftCount);

      // 3. Confirm both steps: the month is cleared, in one request.
      await deleteButton.click();
      await stepOne.getByRole('button', { name: 'Yes, continue' }).click();
      const cleared = adminPage.waitForResponse(
        (res) =>
          new URL(res.url()).pathname === '/api/schedule/clear-month' &&
          res.request().method() === 'DELETE',
      );
      await stepTwo.getByRole('button', { name: 'Delete Everything' }).click();
      expect((await cleared).ok()).toBe(true);
      await expect(
        adminPage.getByText(`Deleted ${shiftCount} shifts and 1 assignments for ${MONTH}.`),
      ).toBeVisible();
      await expect(stepTwo).toBeHidden();
      await expect(zones).toHaveCount(0);
      await expect(cell).not.toContainText('Regular Employee');
      expect(clearRequests).toHaveLength(1);
      expect(await shiftsOnServer()).toEqual([]);

      // Persisted: a reload shows the empty month.
      await adminPage.reload();
      await adminPage.getByRole('button', { name: new RegExp(GROUP) }).click();
      await expect(adminPage.locator(`[data-date="${DAY}"]`)).toBeVisible();
      await expect(zones).toHaveCount(0);
    } finally {
      await archiveShiftGroup(context, groupId).catch(() => {});
    }
  });
});
