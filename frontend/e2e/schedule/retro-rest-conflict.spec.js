// frontend/e2e/schedule/retro-rest-conflict.spec.js
import {
  assignShift,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
  setShiftType,
  setShiftTypes,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

// Months reserved for this file: 2029-03 (later-shift prompt), 2029-04 (marker after retype).
const EMPLOYEE = 'Regular Employee';

async function seedGroup(context, name, month) {
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
  return { groupId, employeeId, schedule };
}

async function dragToShiftDropzone(page, sourceLocator, targetLocator) {
  const source = await sourceLocator.boundingBox();
  const target = await targetLocator.boundingBox();
  if (!source || !target) throw new Error('drag source/target not visible');
  // A mouse drag can't scroll the page, so both ends must already be on screen.
  const viewport = page.viewportSize();
  for (const [name, box] of [
    ['source', source],
    ['target', target],
  ]) {
    if (box.y < 0 || box.y + box.height > viewport.height) {
      throw new Error(
        `drag ${name} is outside the ${viewport.width}x${viewport.height} viewport (y=${Math.round(box.y)}); use a taller viewport`,
      );
    }
  }
  await page.mouse.move(source.x + source.width / 2, source.y + source.height / 2);
  await page.mouse.down();
  const steps = 8;
  for (let i = 1; i <= steps; i++) {
    const x = source.x + ((target.x - source.x) * i) / steps;
    const y = source.y + ((target.y - source.y) * i) / steps;
    await page.mouse.move(x, y, { steps: 3 });
  }
  await page.mouse.move(target.x + target.width / 2, target.y + target.height / 2, { steps: 3 });
  // Hovering other cells moves the dragged chip and reflows the grid, so the drop-zone can have
  // shifted under the cursor: re-aim at where it is now before releasing.
  for (let i = 0; i < 2; i++) {
    const now = await targetLocator.boundingBox();
    if (!now) break;
    await page.mouse.move(now.x + now.width / 2 - 4 + i * 8, now.y + now.height / 2, { steps: 4 });
  }
  await page.mouse.up();
}

// The dropped day follows the seeded earlier shift, so the schedule first asks about back-to-back
// shifts; the rest-day question comes only after the manager confirms that one.
async function dropEmployeeOn(page, groupName, month, day) {
  await page.goto(`/schedule/${month}`);
  await page.getByRole('button', { name: new RegExp(groupName) }).click();
  // Let the group's schedule and roster settle; a drag started mid-render never lands.
  await page.waitForLoadState('networkidle');
  const zone = page.locator(`[data-date="${month}-${day}"]`).locator('[data-shift-id]').first();
  await expect(zone).toBeVisible();
  const chip = page.locator('div.cursor-grab.rounded-xl', { hasText: EMPLOYEE });
  await expect(chip).toBeVisible();
  // An occasional drag only registers as a click on the chip (it opens the availability preview and
  // shifts the layout); re-measure and drag again instead of waiting out the test timeout.
  const anyway = page.getByRole('button', { name: 'Assign Anyway' });
  let confirmed = false;
  for (let attempt = 0; attempt < 3 && !confirmed; attempt++) {
    await dragToShiftDropzone(page, chip, zone);
    confirmed = await anyway
      .waitFor({ timeout: 5000 })
      .then(() => true)
      .catch(() => false);
  }
  await anyway.click();
  return zone;
}

test.describe('Retroactive rest conflicts', () => {
  test.beforeEach(async ({ adminPage }) => {
    await adminPage.setViewportSize({ width: 1280, height: 1000 });
  });

  test('asks how a later shift counts once the drop makes it a rest day', async ({ adminPage }) => {
    const context = adminPage.context();
    const { groupId, employeeId, schedule } = await seedGroup(
      context,
      'Retro Prompt Team',
      '2029-03',
    );
    for (const day of ['02', '05']) {
      await assignShift(context, { shiftId: schedule[`2029-03-${day}`][0].id, userId: employeeId });
    }

    await dropEmployeeOn(adminPage, 'Retro Prompt Team', '2029-03', '03');

    const dialog = adminPage.getByRole('dialog', { name: /later shift a rest day/i });
    await expect(dialog).toContainText('2029-03-05');
    const assigned = adminPage.waitForResponse(
      (res) =>
        res.url().includes('/api/schedule/assign') &&
        res.request().method() === 'POST' &&
        res.status() === 201,
    );
    await dialog.getByLabel('Extends the rest').check();
    await dialog.getByRole('button', { name: 'Assign' }).click();
    await assigned;

    const { schedule: after } = await getShiftGroupsAndShifts(context, '2029-03', groupId);
    expect(after['2029-03-03'][0].assignments.filter((x) => x.user_id === employeeId)).toHaveLength(
      1,
    );
    const later = after['2029-03-05'][0].assignments.find((x) => x.user_id === employeeId);
    expect(later.rest_policy).toBe('extends_rest');

    await adminPage.reload();
    await adminPage.getByRole('button', { name: /Retro Prompt Team/ }).click();
    await expect(
      adminPage
        .locator('[data-date="2029-03-05"]')
        .getByTitle(/Assigned during rest: extends rest/),
    ).toBeVisible();
  });

  test('Cancel leaves the drop and the later shift untouched', async ({ adminPage }) => {
    const context = adminPage.context();
    const { groupId, employeeId, schedule } = await seedGroup(
      context,
      'Retro Cancel Team',
      '2029-03',
    );
    for (const day of ['20', '23']) {
      await assignShift(context, { shiftId: schedule[`2029-03-${day}`][0].id, userId: employeeId });
    }

    const zone = await dropEmployeeOn(adminPage, 'Retro Cancel Team', '2029-03', '21');

    const dialog = adminPage.getByRole('dialog', { name: /later shift a rest day/i });
    await expect(dialog).toContainText('2029-03-23');
    // Positive guard: the optimistic chip is there while the dialog is open.
    await expect(zone.locator('div', { hasText: EMPLOYEE })).not.toHaveCount(0);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
    await expect(zone).toBeVisible();
    await expect(zone.locator('div', { hasText: EMPLOYEE })).toHaveCount(0);

    const { schedule: after } = await getShiftGroupsAndShifts(context, '2029-03', groupId);
    expect(after['2029-03-21'][0].assignments.filter((x) => x.user_id === employeeId)).toHaveLength(
      0,
    );
    const later = after['2029-03-23'][0].assignments.find((x) => x.user_id === employeeId);
    expect(later.rest_policy).toBeNull();
  });

  test('marks a shift that became a rest day after a retype', async ({ adminPage }) => {
    const context = adminPage.context();
    const { employeeId, schedule } = await seedGroup(context, 'Retro Marker Team', '2029-04');
    await setShiftType(context, { shiftId: schedule['2029-04-03'][0].id, shiftType: 'day' });
    for (const day of ['02', '03', '05']) {
      await assignShift(context, { shiftId: schedule[`2029-04-${day}`][0].id, userId: employeeId });
    }
    await setShiftType(context, { shiftId: schedule['2029-04-03'][0].id, shiftType: 'night' });

    await adminPage.goto('/schedule/2029-04');
    await adminPage.getByRole('button', { name: /Retro Marker Team/ }).click();
    const day2 = adminPage.locator('[data-date="2029-04-02"]');
    const day5 = adminPage.locator('[data-date="2029-04-05"]');
    await expect(day2.getByText(EMPLOYEE)).toBeVisible();
    await expect(day5.getByTitle(/Now on a rest day/)).toBeVisible();
    await expect(day2.getByTitle(/Now on a rest day/)).toHaveCount(0);
  });
});
