// frontend/e2e/checklists/fill-shift-picker.spec.js
import {
  archiveShiftGroup,
  assignShift,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
  setDayShiftCount,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('checklists/fill-shift-picker');

// F-c: the shift picker offers the outgoing employee's own shifts first; someone filing for
// themselves sees only theirs. Month 2091-05 is reserved for this file.
const MONTH = reserve.month('2091-05');
const DAY = '2091-05-12';
const GROUP = reserve.name('Fill Picker Team');

test.describe('Fill checklist: shift picker', () => {
  test("lists the outgoing employee's own shifts (only those for an employee)", async ({
    adminPage,
    employeePage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: GROUP } });
    try {
      await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });
      await setDayShiftCount(context, { date: DAY, count: 2, shiftGroupId: groupId });
      const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
      const day = (await getShiftGroupsAndShifts(context, MONTH, groupId)).schedule[DAY];
      const second = day.find((s) => s.shift_number === 2);
      await assignShift(context, { shiftId: second.id, userId: regularId });

      const optionsOf = (page) =>
        page.locator('select', { hasText: 'No shift selected' }).locator('option');

      // The employee files as themselves: only their shift is offered.
      await employeePage.goto('/checklist/fill');
      await employeePage.waitForLoadState('networkidle');
      await employeePage.locator('input[type="date"]').fill(DAY);
      await expect(optionsOf(employeePage)).toHaveCount(2);
      await expect(optionsOf(employeePage).nth(1)).toContainText('Shift 2');

      // An admin sees every shift; once the outgoing employee is chosen, theirs comes first.
      await adminPage.goto('/checklist/fill');
      await adminPage.waitForLoadState('networkidle');
      await adminPage.locator('input[type="date"]').fill(DAY);
      await expect(optionsOf(adminPage)).toHaveCount(3);
      await expect(optionsOf(adminPage).nth(1)).toContainText('Shift 1');
      await adminPage.getByLabel('Outgoing Employee').selectOption({ label: 'Regular Employee' });
      await expect(optionsOf(adminPage).nth(1)).toContainText('Shift 2');
      await expect(optionsOf(adminPage)).toHaveCount(3);
    } finally {
      await archiveShiftGroup(context, groupId).catch(() => {});
    }
  });
});
