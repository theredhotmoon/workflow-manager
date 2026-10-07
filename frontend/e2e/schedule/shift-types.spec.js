import {
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getShiftGroupsAndShifts,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

// Months reserved for this file: 2028-02 (template), 2028-03 (single edit), 2028-04 (bulk editor).

test.describe('Shift types', () => {
  test('a type set in the Generate Month template shows on every generated chip', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Types Template Team' },
    });

    await adminPage.goto('/schedule/2028-02');
    await adminPage.getByRole('button', { name: /Types Template Team/ }).click();
    await adminPage.getByRole('button', { name: 'Generate Month' }).click();
    const modal = adminPage.locator('div.fixed', { has: adminPage.getByText(/Generate Shifts/) });
    await expect(modal).toBeVisible();

    // Only slot 1 gets a type; the assertions below look at slot 1 alone, whatever the slot count.
    await modal.getByLabel('Shift 1 type').fill(' Night ');
    await modal.getByRole('button', { name: /^Generate / }).click();
    await expect(adminPage.getByText(/generated|created/i).first()).toBeVisible();

    const { schedule } = await getShiftGroupsAndShifts(context, '2028-02', groupId);
    const slot1Types = Object.values(schedule).map(
      (shifts) => shifts.find((s) => s.shift_number === 1)?.shift_type,
    );
    expect(slot1Types.length).toBe(29);
    expect(new Set(slot1Types)).toEqual(new Set(['night']));

    await adminPage.reload();
    await adminPage.getByRole('button', { name: /Types Template Team/ }).click();
    await expect(adminPage.getByText('S1 · night', { exact: true })).toHaveCount(29);
  });

  test("editing one shift's type persists across a reload", async ({ adminPage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Types Single Team' },
    });
    await generateMonthShifts(context, { monthKey: '2028-03', shiftGroupId: groupId });

    await adminPage.goto('/schedule/2028-03');
    await adminPage.getByRole('button', { name: /Types Single Team/ }).click();
    const day = adminPage.locator('[data-date="2028-03-15"]');
    await expect(day.getByText('S1', { exact: true })).toBeVisible();

    // The chip's time label opens the shift edit modal (openTimeEditor); fixture shifts are 08:00–16:00.
    await day.getByTitle('Edit shift times').first().click();
    await adminPage.getByLabel('Shift type').fill('day');
    await adminPage.getByRole('button', { name: 'Save' }).click();
    await expect(adminPage.getByText('Shift updated.')).toBeVisible();

    await adminPage.reload();
    await adminPage.getByRole('button', { name: /Types Single Team/ }).click();
    await expect(day.getByText('S1 · day', { exact: true })).toBeVisible();
    await expect(
      adminPage.locator('[data-date="2028-03-16"]').getByText('S1', { exact: true }),
    ).toBeVisible();
  });
});
