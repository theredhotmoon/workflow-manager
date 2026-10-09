// frontend/e2e/schedule/rest-day-policy.spec.js
import {
  assignShift,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
  setShiftTypes,
  updateShiftGroup,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { dragToShiftDropzone } from '../fixtures/drag.js';

// Months reserved for this file: 2029-01 (extends rest), 2029-02 (cancel).
const EMPLOYEE = 'Regular Employee';

async function seedRestingEmployee(context, name, month) {
  const groupId = await createShiftGroupWithStreakRule(context, {
    group: { name },
    rule: { shift_type: 'night', streak_length: 2, rest_days: 2 },
  });
  await generateMonthShifts(context, { monthKey: month, shiftGroupId: groupId });
  await setShiftTypes(context, {
    shiftGroupId: groupId,
    month,
    shiftNumber: 1,
    shiftType: 'night',
  });
  const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
  const { schedule } = await getShiftGroupsAndShifts(context, month, groupId);
  for (const day of ['01', '02']) {
    await assignShift(context, { shiftId: schedule[`${month}-${day}`][0].id, userId: employeeId });
  }
  return { groupId, employeeId }; // resting on -03 and -04
}

// Day 3 follows the seeded day-2 shift, so the schedule first asks about back-to-back shifts; the
// rest-day question comes only after the manager confirms that one.
async function confirmBackToBack(page) {
  await page.getByRole('button', { name: 'Assign Anyway' }).click();
}

test.describe('Rest-day policy prompt', () => {
  test.beforeEach(async ({ adminPage }) => {
    await adminPage.setViewportSize({ width: 1280, height: 1000 });
  });

  test('asks how a rest-day shift counts and stores "extends the rest"', async ({ adminPage }) => {
    const context = adminPage.context();
    const { groupId, employeeId } = await seedRestingEmployee(
      context,
      'Rest Prompt Team',
      '2029-01',
    );

    // A weekly period ending inside the rest window, so the report can show the extended rest.
    await updateShiftGroup(context, groupId, {
      settlement_mode: 'weekly',
      settlement_anchor_date: '2028-12-28',
    });

    await adminPage.goto('/schedule/2029-01');
    await adminPage.getByRole('button', { name: /Rest Prompt Team/ }).click();
    const day3 = adminPage.locator('[data-date="2029-01-03"]');
    const zone = day3.locator('[data-shift-id]').first();
    await expect(zone).toBeVisible();
    const chip = adminPage.locator('div.cursor-grab.rounded-xl', { hasText: EMPLOYEE });
    await expect(chip).toBeVisible();
    await dragToShiftDropzone(adminPage, chip, zone);
    await confirmBackToBack(adminPage);

    const dialog = adminPage.getByRole('dialog', { name: /rest day/i });
    await expect(dialog).toContainText('resting until 2029-01-04');
    const assigned = adminPage.waitForResponse(
      (res) =>
        res.url().includes('/api/schedule/assign') &&
        res.request().method() === 'POST' &&
        res.status() === 201,
    );
    await dialog.getByLabel('Extends the rest').check();
    await dialog.getByRole('button', { name: 'Assign' }).click();
    await assigned;

    const { schedule } = await getShiftGroupsAndShifts(context, '2029-01', groupId);
    const a = schedule['2029-01-03'][0].assignments.find((x) => x.user_id === employeeId);
    expect(a.rest_policy).toBe('extends_rest');

    // Settlement report: period 2028-12-28..2029-01-03. "Extends the rest" pushes the rest window to
    // 2 days after the -03 shift (2029-01-05); "doesn't count" would leave it at 2029-01-04.
    const current = (
      await (
        await context.request.get('/api/settlement-periods', {
          params: { shift_group_id: groupId, offset: 0 },
        })
      ).json()
    ).period;
    const dayMs = 24 * 60 * 60 * 1000;
    const offset = Math.round(
      (Date.parse('2028-12-28T12:00:00Z') - Date.parse(`${current.start}T12:00:00Z`)) / (7 * dayMs),
    );
    const report = await (
      await context.request.get('/api/settlement-periods', {
        params: { shift_group_id: groupId, offset },
      })
    ).json();
    expect(report.period.start).toBe('2028-12-28');
    const me = report.employees.find((e) => e.id === employeeId);
    expect(me.streak_status.find((r) => r.shift_type === 'night').resting_until).toBe('2029-01-05');

    await adminPage.reload();
    await adminPage.getByRole('button', { name: /Rest Prompt Team/ }).click();
    await expect(day3.getByTitle(/^On a rest day: extends rest$/)).toBeVisible();
  });

  test('Cancel leaves nothing assigned', async ({ adminPage }) => {
    const context = adminPage.context();
    const { groupId, employeeId } = await seedRestingEmployee(
      context,
      'Rest Cancel Team',
      '2029-02',
    );

    await adminPage.goto('/schedule/2029-02');
    await adminPage.getByRole('button', { name: /Rest Cancel Team/ }).click();
    const day3 = adminPage.locator('[data-date="2029-02-03"]');
    const zone = day3.locator('[data-shift-id]').first();
    await expect(zone).toBeVisible();
    const chip = adminPage.locator('div.cursor-grab.rounded-xl', { hasText: EMPLOYEE });
    await expect(chip).toBeVisible();
    await dragToShiftDropzone(adminPage, chip, zone);
    await confirmBackToBack(adminPage);

    const dialog = adminPage.getByRole('dialog', { name: /rest day/i });
    await expect(dialog).toBeVisible();
    // Positive guard: the optimistic chip is there while the dialog is open.
    await expect(zone.locator('div', { hasText: EMPLOYEE })).not.toHaveCount(0);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
    await expect(adminPage.getByText('Assigned to back-to-back shift.')).toHaveCount(0);
    await expect(zone).toBeVisible();
    await expect(zone.locator('div', { hasText: EMPLOYEE })).toHaveCount(0);

    const { schedule } = await getShiftGroupsAndShifts(context, '2029-02', groupId);
    expect(
      schedule['2029-02-03'][0].assignments.filter((x) => x.user_id === employeeId),
    ).toHaveLength(0);
  });
});
