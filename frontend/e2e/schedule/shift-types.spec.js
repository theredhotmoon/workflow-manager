import {
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getShiftGroupsAndShifts,
  setShiftTypes,
} from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, test } from '../fixtures/auth.js';

// Months reserved for this file: 2028-02 (template), 2028-03 (single edit), 2028-04 (bulk editor), 2028-05 (mixed slot left blank).

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

  test('the bulk editor relabels every shift in a slot for the month', async ({ adminPage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Types Bulk Team' },
    });
    await generateMonthShifts(context, { monthKey: '2028-04', shiftGroupId: groupId });
    // One day pre-labelled differently, so the slot starts as "mixed".
    await setShiftTypes(context, {
      shiftGroupId: groupId,
      month: '2028-04',
      shiftNumber: 1,
      shiftType: 'night',
    });
    const { schedule: before } = await getShiftGroupsAndShifts(context, '2028-04', groupId);
    const firstShift = before['2028-04-01'][0];
    const headers = await getXsrfHeader(context);
    const typeRes = await context.request.put(`/api/schedule/shifts/${firstShift.id}/type`, {
      data: { shift_type: 'day' },
      headers,
    });
    expect(typeRes.ok()).toBe(true);

    await adminPage.goto('/schedule/2028-04');
    await adminPage.getByRole('button', { name: /Types Bulk Team/ }).click();
    await adminPage.getByRole('button', { name: 'Set shift types' }).click();
    const dialog = adminPage.getByRole('dialog', { name: 'Set shift types' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('Slot 1 type')).toHaveAttribute('placeholder', 'mixed');

    await dialog.getByLabel('Slot 1 type').fill('Evening');
    await dialog.getByRole('button', { name: 'Save' }).click();
    await expect(adminPage.getByText(/30 shifts? updated/)).toBeVisible();

    const { schedule: after } = await getShiftGroupsAndShifts(context, '2028-04', groupId);
    const types = Object.values(after).map(
      (shifts) => shifts.find((s) => s.shift_number === 1)?.shift_type,
    );
    expect(types.length).toBe(30);
    expect(new Set(types)).toEqual(new Set(['evening']));

    await adminPage.reload();
    await adminPage.getByRole('button', { name: /Types Bulk Team/ }).click();
    await expect(adminPage.getByText('S1 · evening', { exact: true })).toHaveCount(30);
  });

  test('saving a mixed slot left blank keeps its per-day types', async ({ adminPage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Types Mixed Team' },
    });
    await generateMonthShifts(context, { monthKey: '2028-05', shiftGroupId: groupId });
    await setShiftTypes(context, {
      shiftGroupId: groupId,
      month: '2028-05',
      shiftNumber: 1,
      shiftType: 'night',
    });
    const { schedule: seeded } = await getShiftGroupsAndShifts(context, '2028-05', groupId);
    const headers = await getXsrfHeader(context);
    const typeRes = await context.request.put(
      `/api/schedule/shifts/${seeded['2028-05-01'][0].id}/type`,
      { data: { shift_type: 'day' }, headers },
    );
    expect(typeRes.ok()).toBe(true);
    const slot1Types = async () => {
      const { schedule } = await getShiftGroupsAndShifts(context, '2028-05', groupId);
      return Object.entries(schedule).map(([date, shifts]) => [
        date,
        shifts.find((s) => s.shift_number === 1)?.shift_type,
      ]);
    };
    const expected = await slot1Types();
    expect(new Set(expected.map(([, t]) => t))).toEqual(new Set(['day', 'night']));

    await adminPage.goto('/schedule/2028-05');
    await adminPage.getByRole('button', { name: /Types Mixed Team/ }).click();
    await adminPage.getByRole('button', { name: 'Set shift types' }).click();
    const dialog = adminPage.getByRole('dialog', { name: 'Set shift types' });
    await expect(dialog.getByLabel('Slot 1 type')).toHaveAttribute('placeholder', 'mixed');
    await dialog.getByLabel('Slot 1 type').fill('evening');
    await dialog.getByLabel('Slot 1 type').fill('');
    await dialog.getByRole('button', { name: 'Save' }).click();
    await expect(dialog).toBeHidden();

    expect(await slot1Types()).toEqual(expected);
  });
});
