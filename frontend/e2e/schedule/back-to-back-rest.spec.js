// frontend/e2e/schedule/back-to-back-rest.spec.js
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
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('schedule/back-to-back-rest');

// A drop next to the employee's shift in the same group asks once: the screen's Back-to-Back
// dialog. The server's short-rest check (409 short_daily_rest) names that same shift, so the
// manager's answer there stands and the "Less than 11 hours of rest" dialog doesn't follow.
// (A short rest the screen can't see, e.g. in another group, is still asked: short-rest.spec.js.)
const CONFIRM_MONTH = reserve.month('2093-02');
const CANCEL_MONTH = reserve.month('2093-03');
const CONFIRM_GROUP = reserve.name('B2B Rest Confirm');
const CANCEL_GROUP = reserve.name('B2B Rest Cancel');
const EMPLOYEE = 'Regular Employee';

const isAssignPost = (req) => req.url().includes('/api/schedule/assign') && req.method() === 'POST';

// The employee works 14:00-22:00 on the 10th; the target is 06:00-14:00 on the 11th, 8 h later.
async function seed(context, created, { month, name }) {
  const groupId = await createShiftGroupWithStreakRule(context, { group: { name } });
  created.push(groupId);
  await generateMonthShifts(context, { monthKey: month, shiftGroupId: groupId });
  const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
  const { schedule } = await getShiftGroupsAndShifts(context, month, groupId);
  const before = schedule[`${month}-10`][0].id;
  const target = schedule[`${month}-11`][0].id;
  await setShiftTimes(context, { shiftId: before, startTime: '14:00', endTime: '22:00' });
  await setShiftTimes(context, { shiftId: target, startTime: '06:00', endTime: '14:00' });
  await assignShift(context, { shiftId: before, userId: employeeId });
  return { groupId, employeeId };
}

async function openAndDrop(page, { month, groupId, name }) {
  await page.goto(`/schedule/${month}`);
  const loaded = page.waitForResponse(
    (res) =>
      res.url().includes('/api/schedule?') && res.url().includes(`shift_group_id=${groupId}`),
  );
  await page.getByRole('button', { name: new RegExp(name) }).click();
  await loaded;
  await page.waitForLoadState('networkidle');
  const zone = page.locator(`[data-date="${month}-11"]`).locator('[data-shift-id]').first();
  const roster = page.locator('div.cursor-grab', { hasText: EMPLOYEE }).first();
  await dragToShiftDropzone(page, roster, zone);
  return zone;
}

async function assignedOn(context, { month, groupId }) {
  const { schedule } = await getShiftGroupsAndShifts(context, month, groupId);
  return schedule[`${month}-11`][0].assignments.map((a) => a.user_id);
}

test.describe('Back-to-back drop: one rest question', () => {
  test.beforeEach(async ({ adminPage }) => {
    await adminPage.setViewportSize({ width: 1280, height: 1000 });
  });

  test('confirmed in the Back-to-Back dialog, it is assigned without a second question', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const created = [];
    try {
      const { groupId, employeeId } = await seed(context, created, {
        month: CONFIRM_MONTH,
        name: CONFIRM_GROUP,
      });
      const statuses = [];
      adminPage.on('response', (res) => {
        if (isAssignPost(res.request())) {
          statuses.push([res.status(), res.request().postDataJSON()?.confirm_short_rest === true]);
        }
      });
      const saved = adminPage.waitForResponse(
        (res) => isAssignPost(res.request()) && res.status() === 201,
      );

      const zone = await openAndDrop(adminPage, {
        month: CONFIRM_MONTH,
        groupId,
        name: CONFIRM_GROUP,
      });
      const backToBack = adminPage.getByRole('alertdialog', { name: /Back-to-Back Shifts/ });
      await expect(backToBack).toBeVisible();
      await backToBack.getByRole('button', { name: 'Assign Anyway' }).click();
      await saved;

      await expect(adminPage.getByText('Assigned to back-to-back shift.')).toBeVisible();
      await expect(
        adminPage.getByRole('dialog', { name: 'Less than 11 hours of rest' }),
      ).toHaveCount(0);
      // The server asked (409), and the same request went again with the go-ahead.
      expect(statuses).toEqual([
        [409, false],
        [201, true],
      ]);
      await expect(zone.locator('div.cursor-grab', { hasText: EMPLOYEE })).toHaveCount(1);
      expect(await assignedOn(context, { month: CONFIRM_MONTH, groupId })).toEqual([employeeId]);
    } finally {
      // Archived so its tab doesn't push later specs' days around.
      for (const id of created) await archiveShiftGroup(context, id).catch(() => {});
    }
  });

  test('cancelled in the Back-to-Back dialog, nothing is sent or assigned', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const created = [];
    try {
      const { groupId } = await seed(context, created, { month: CANCEL_MONTH, name: CANCEL_GROUP });
      let posts = 0;
      adminPage.on('request', (req) => {
        if (isAssignPost(req)) posts++;
      });

      const zone = await openAndDrop(adminPage, {
        month: CANCEL_MONTH,
        groupId,
        name: CANCEL_GROUP,
      });
      const backToBack = adminPage.getByRole('alertdialog', { name: /Back-to-Back Shifts/ });
      await expect(backToBack).toBeVisible();
      await backToBack.getByRole('button', { name: 'Cancel' }).click();
      await expect(backToBack).toHaveCount(0);

      await expect(zone.locator('div.cursor-grab', { hasText: EMPLOYEE })).toHaveCount(0);
      await expect(
        adminPage.getByRole('dialog', { name: 'Less than 11 hours of rest' }),
      ).toHaveCount(0);
      expect(posts).toBe(0);
      expect(await assignedOn(context, { month: CANCEL_MONTH, groupId })).toEqual([]);
    } finally {
      for (const id of created) await archiveShiftGroup(context, id).catch(() => {});
    }
  });
});
