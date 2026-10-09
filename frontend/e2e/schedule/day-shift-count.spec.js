// frontend/e2e/schedule/day-shift-count.spec.js
import {
  assignShift,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
  setDayShiftCount,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

// Month reserved for this file: 2094-03.
const MONTH = '2094-03';
const DAY = '2094-03-10';
const GROUP_NAME = 'Slot Count Team';

test.describe("Reducing a day's shift count", () => {
  test('asks before removing a staffed shift; cancel keeps it, confirm removes it', async ({
    adminPage,
  }) => {
    await adminPage.setViewportSize({ width: 1280, height: 1000 });
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: GROUP_NAME } });
    await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });
    await setDayShiftCount(context, { date: DAY, count: 2, shiftGroupId: groupId });
    const { schedule } = await getShiftGroupsAndShifts(context, MONTH, groupId);
    const [slot1, slot2] = schedule[DAY];
    // Both slots staffed, so dropping to one has to take a staffed shift.
    await assignShift(context, {
      shiftId: slot1.id,
      userId: await getEmployeeIdByEmail(context, 'marcin@shiftchanger.com'),
    });
    await assignShift(context, {
      shiftId: slot2.id,
      userId: await getEmployeeIdByEmail(context, 'regular@shiftchanger.com'),
    });

    await adminPage.goto(`/schedule/${MONTH}`);
    const loaded = adminPage.waitForResponse(
      (res) =>
        res.url().includes('/api/schedule?') && res.url().includes(`shift_group_id=${groupId}`),
    );
    await adminPage.getByRole('button', { name: new RegExp(GROUP_NAME) }).click();
    await loaded;

    const cell = adminPage.locator(`[data-date="${DAY}"]`);
    const zones = cell.locator('[data-shift-id]');
    await expect(zones).toHaveCount(2);
    const minus = adminPage.getByRole('button', { name: `Remove a shift slot on ${DAY}` });

    // Cancel: the dialog lists the staffed shift, and nothing changes.
    await minus.click();
    const dialog = adminPage.getByRole('dialog');
    await expect(dialog).toContainText('Remove a staffed shift?');
    await expect(dialog).toContainText(`${DAY} shift 2`);
    await expect(dialog).toContainText('Regular Employee');
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toHaveCount(0);
    await expect(zones).toHaveCount(2);
    await adminPage.reload();
    await adminPage.getByRole('button', { name: new RegExp(GROUP_NAME) }).click();
    await expect(zones).toHaveCount(2);
    await expect(cell).toContainText('Regular Employee');

    // Confirm, but the list went stale meanwhile: the first confirmed PUT answers 409 again (as when
    // someone staffs another slot in between; simulated here with the same list), so the dialog
    // asks again instead of ending in a toast.
    let staleAnswered = false;
    await adminPage.route('**/api/schedule/day-shifts', async (route) => {
      if (!staleAnswered && route.request().postDataJSON()?.confirm_remove_staffed === true) {
        staleAnswered = true;
        await route.fulfill({
          status: 409,
          contentType: 'application/json',
          body: JSON.stringify({
            code: 'staffed_shifts_removal',
            message: 'Removing this slot would delete a staffed shift.',
            shifts: [
              {
                id: slot2.id,
                date: DAY,
                shift_number: 2,
                start_time: null,
                end_time: null,
                assigned_users: ['Regular Employee', 'Someone Else'],
                pending_change_requests: 0,
              },
            ],
          }),
        });
        return;
      }
      await route.continue();
    });
    await minus.click();
    await expect(dialog).toContainText('Regular Employee');
    await dialog.getByRole('button', { name: 'Remove' }).click();
    await expect(dialog).toContainText('Someone Else');

    // Confirm the second round: the staffed slot 2 goes, slot 1 stays.
    const removed = adminPage.waitForResponse(
      (res) =>
        res.url().includes('/api/schedule/day-shifts') &&
        res.request().method() === 'PUT' &&
        res.status() !== 409 &&
        res.request().postDataJSON()?.confirm_remove_staffed === true,
    );
    await dialog.getByRole('button', { name: 'Remove' }).click();
    expect((await removed).ok()).toBe(true);
    expect(staleAnswered).toBe(true);
    await expect(zones).toHaveCount(1);
    await expect(cell).not.toContainText('Regular Employee');
  });
});
