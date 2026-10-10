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
import { dragToShiftDropzone } from '../fixtures/drag.js';

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

// The dropped day follows the seeded earlier shift, so the schedule first asks about back-to-back
// shifts; the rest-day question comes only after the manager confirms that one.
async function dropEmployeeOn(page, groupName, month, day) {
  await page.goto(`/schedule/${month}`);
  // Let the group's schedule load before dragging; a drag started mid-render never lands.
  const loaded = page.waitForResponse(
    (res) => res.url().includes('/api/schedule?') && res.url().includes('shift_group_id'),
  );
  await page.getByRole('button', { name: new RegExp(groupName) }).click();
  await loaded;
  const zone = page.locator(`[data-date="${month}-${day}"]`).locator('[data-shift-id]').first();
  await expect(zone).toBeVisible();
  // The roster is sticky and lists every active employee by name, so how far down this chip sits
  // depends on how many users earlier specs created. Sorting by shifts (most first) puts the seeded
  // employee, the only one with shifts in this new group, at the top whatever the run left behind.
  const sortByShifts = page
    .getByText('Sort:')
    .locator('..')
    .getByRole('button', { name: /Shifts/ });
  await sortByShifts.click(); // ascending
  await sortByShifts.click(); // descending
  await expect(sortByShifts).toContainText('▼');
  const chip = page.locator('div.cursor-grab.rounded-xl', { hasText: EMPLOYEE });
  await expect(chip).toBeVisible();
  // An occasional drag only registers as a click on the chip (it opens the availability preview and
  // shifts the layout); re-measure and drag again instead of waiting out the test timeout.
  const anyway = page.getByRole('button', { name: 'Assign Anyway' });
  let assignPosts = 0;
  page.on('request', (req) => {
    if (req.url().includes('/api/schedule/assign') && req.method() === 'POST') assignPosts++;
  });
  let confirmed = false;
  for (let attempt = 0; attempt < 3 && !confirmed; attempt++) {
    const before = assignPosts;
    await dragToShiftDropzone(page, chip, zone);
    confirmed = await anyway
      .waitFor({ timeout: 5000 })
      .then(() => true)
      .catch(() => false);
    // A mis-aimed drop that already hit the API must not be retried: it left an assignment behind.
    if (!confirmed && assignPosts > before) throw new Error('drop was sent to the wrong day');
  }
  await anyway.click();
  return zone;
}

function employeeDays(schedule, employeeId) {
  return Object.entries(schedule)
    .filter(([, shifts]) =>
      shifts.some((sh) => sh.assignments.some((a) => a.user_id === employeeId)),
    )
    .map(([date]) => date)
    .sort();
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
    expect(employeeDays(after, employeeId)).toEqual(['2029-03-02', '2029-03-03', '2029-03-05']);

    // The chip is updated in place, before any reload.
    await expect(
      adminPage.locator('[data-date="2029-03-05"]').getByTitle(/^On a rest day: extends rest$/),
    ).toBeVisible();
    await adminPage.reload();
    await adminPage.getByRole('button', { name: /Retro Prompt Team/ }).click();
    await expect(
      adminPage.locator('[data-date="2029-03-05"]').getByTitle(/^On a rest day: extends rest$/),
    ).toBeVisible();

    // Removing the 03-03 shift ends that rest window: the 03-05 shift keeps its policy but is no
    // longer on a rest day, so its ⏸ goes away — without a reload.
    const day3 = adminPage.locator('[data-date="2029-03-03"]');
    const day5 = adminPage.locator('[data-date="2029-03-05"]');
    await expect(day3.getByText(EMPLOYEE)).toBeVisible();
    await day3.getByRole('button', { name: '×' }).click();
    await expect(day3.getByText(EMPLOYEE)).toHaveCount(0);
    await expect(day5.getByText(EMPLOYEE)).toBeVisible();
    await expect(day5.getByTitle(/^On a rest day/)).toHaveCount(0);
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
    expect(employeeDays(after, employeeId)).toEqual(['2029-03-20', '2029-03-23']);
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
