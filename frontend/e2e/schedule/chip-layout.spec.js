import {
  assignShift,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('schedule/chip-layout');
reserve.months('2028-08');

// Month 2028-08 is reserved for this file. Runs at Playwright's default 1280×720 viewport, where a
// day column is ~133 px wide — narrower than "Regular Employee" plus its initials and × button.
test.describe('Assigned employee chip layout', () => {
  test('stays inside its day cell at 1280 px and its × button is clickable', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Chip Layout Team' },
    });
    await generateMonthShifts(context, { monthKey: '2028-08', shiftGroupId: groupId });
    const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const { schedule } = await getShiftGroupsAndShifts(context, '2028-08', groupId);
    await assignShift(context, { shiftId: schedule['2028-08-09'][0].id, userId: employeeId });

    await adminPage.goto('/schedule/2028-08');
    await adminPage.getByRole('button', { name: /Chip Layout Team/ }).click();
    const cell = adminPage.locator('[data-date="2028-08-09"]');
    const chip = cell.locator('[data-shift-id] > div', { hasText: 'Regular' }).first();
    await expect(chip).toBeVisible();

    const cellBox = await cell.boundingBox();
    const chipBox = await chip.boundingBox();
    expect(chipBox.x + chipBox.width).toBeLessThanOrEqual(cellBox.x + cellBox.width);

    // The × must be the element actually under the pointer, not the next day's drop-zone.
    const remove = chip.getByRole('button', { name: '×' });
    const removeIsOnTop = await remove.evaluate((button) => {
      const r = button.getBoundingClientRect();
      return document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2) === button;
    });
    expect(removeIsOnTop).toBe(true);

    // A truncated name stays readable via its tooltip.
    await expect(chip.getByTitle('Regular Employee')).toHaveCount(1);
  });
});
