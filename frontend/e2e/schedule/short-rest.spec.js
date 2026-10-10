// frontend/e2e/schedule/short-rest.spec.js
import {
  archiveShiftGroup,
  assignShift,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
  setShiftTimes,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { dragToShiftDropzone } from '../fixtures/drag.js';

// F3: a manual assign that leaves less than 11 h of rest next to the employee's other work (here a
// shift in another group, which the screen's own back-to-back check can't see) asks first; Cancel
// leaves nothing assigned, "Assign anyway" saves it. Month 2093-01 is reserved for this file.
const MONTH = '2093-01';
const TARGET_DAY = '2093-01-11';
const EMPLOYEE = 'Regular Employee';
const GROUP_A = 'Short Rest Target';
const GROUP_B = 'Short Rest Other';

test.describe('Manual assign: short daily rest', () => {
  test('asks before assigning into less than 11 h of rest, then assigns on confirm', async ({
    adminPage,
  }) => {
    await adminPage.setViewportSize({ width: 1280, height: 1000 });
    const context = adminPage.context();
    const created = [];
    try {
      await runScenario(adminPage, context, created);
    } finally {
      // Archive both groups so their tabs don't push later specs' days around.
      for (const id of created) await archiveShiftGroup(context, id).catch(() => {});
    }
  });
});

async function runScenario(adminPage, context, created) {
  const groupA = await createShiftGroupWithStreakRule(context, { group: { name: GROUP_A } });
  created.push(groupA);
  const groupB = await createShiftGroupWithStreakRule(context, { group: { name: GROUP_B } });
  created.push(groupB);
  await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupA });
  await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupB });
  const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');

  // Group B: 08:00-16:00 on the 10th. Group A: 02:00-10:00 on the 11th, 10 h later.
  const scheduleB = (await getShiftGroupsAndShifts(context, MONTH, groupB)).schedule;
  await assignShift(context, { shiftId: scheduleB['2093-01-10'][0].id, userId: employeeId });
  const scheduleA = (await getShiftGroupsAndShifts(context, MONTH, groupA)).schedule;
  const targetId = scheduleA[TARGET_DAY][0].id;
  await setShiftTimes(context, { shiftId: targetId, startTime: '02:00', endTime: '10:00' });

  await adminPage.goto(`/schedule/${MONTH}`);
  const loaded = adminPage.waitForResponse(
    (res) => res.url().includes('/api/schedule?') && res.url().includes(`shift_group_id=${groupA}`),
  );
  await adminPage.getByRole('button', { name: new RegExp(GROUP_A) }).click();
  await loaded;
  await adminPage.waitForLoadState('networkidle');

  const zone = adminPage.locator(`[data-date="${TARGET_DAY}"]`).locator('[data-shift-id]').first();
  const roster = adminPage.locator('div.cursor-grab', { hasText: EMPLOYEE }).first();
  const chip = zone.locator('div.cursor-grab', { hasText: EMPLOYEE });
  const dialog = adminPage.getByRole('dialog', { name: 'Less than 11 hours of rest' });

  // First try: the server asks; Cancel takes the chip back and saves nothing.
  const refused = adminPage.waitForResponse(
    (res) => res.url().includes('/api/schedule/assign') && res.request().method() === 'POST',
  );
  await dragToShiftDropzone(adminPage, roster, zone);
  expect((await refused).status()).toBe(409);
  await expect(dialog).toBeVisible();
  await expect(dialog).toContainText(
    '10 h after shift 1 on 2093-01-10 (08:00–16:00, Short Rest Other)',
  );
  await dialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(chip).toHaveCount(0);

  // Second try: confirmed, the same request goes again with the go-ahead and is saved.
  const saved = adminPage.waitForResponse(
    (res) =>
      res.url().includes('/api/schedule/assign') &&
      res.request().method() === 'POST' &&
      res.request().postDataJSON()?.confirm_short_rest === true,
  );
  await dragToShiftDropzone(adminPage, roster, zone);
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Assign anyway' }).click();
  expect((await saved).status()).toBe(201);
  await expect(chip).toHaveCount(1);

  const after = (await getShiftGroupsAndShifts(context, MONTH, groupA)).schedule;
  expect(after[TARGET_DAY][0].assignments.map((a) => a.user_id)).toEqual([employeeId]);
}
