// frontend/e2e/schedule/drop-and-generate-warnings.spec.js
//
// Two warning dialogs of the Schedule screen that other specs only pass through:
// - "Unavailable Period": dropping an employee onto a day they marked unavailable.
// - "Office Closed on Some Dates" before Generate Month: its Cancel and "Generate as Office"
//   answers (generate-remote.spec.js covers "Switch to Remote").
import {
  archiveShiftGroup,
  createAvailability,
  createOfficeClosure,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getShiftGroupsAndShifts,
} from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, test } from '../fixtures/auth.js';
import { dragToShiftDropzone } from '../fixtures/drag.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('schedule/drop-and-generate-warnings');

// Months reserved for this file: 2093-09 (unavailable drop), 2093-10 (closure before generating).
const UNAVAILABLE_MONTH = reserve.month('2093-09');
const UNAVAILABLE_DAY = '2093-09-09';
const UNAVAILABLE_GROUP = reserve.name('Unavailable Drop Team');
const CLOSURE_MONTH = reserve.month('2093-10');
const CLOSED_DAY = '2093-10-14';
const CLOSURE_GROUP = reserve.name('Closure Cancel Team');
const EMPLOYEE = 'Regular Employee';

// Availability entries belong to the logged-in user, so the employee removes their own.
async function deleteAvailability(context, id) {
  const res = await context.request.delete(`/api/user-availabilities/${id}`, {
    headers: { Accept: 'application/json', ...(await getXsrfHeader(context)) },
  });
  if (!res.ok()) throw new Error(`delete availability failed: ${res.status()}`);
}

async function openGroup(page, name, groupId) {
  const loaded = page.waitForResponse(
    (res) =>
      res.url().includes('/api/schedule?') && res.url().includes(`shift_group_id=${groupId}`),
  );
  await page.getByRole('button', { name: new RegExp(name) }).click();
  await loaded;
}

test.describe('Unavailable Period warning on drop', () => {
  test('cancelling takes the chip back without assigning; "Assign Anyway" assigns', async ({
    adminPage,
    employeePage,
  }) => {
    // Tall enough for the roster and the target day to be on screen together (see drag.js).
    await adminPage.setViewportSize({ width: 1280, height: 1000 });
    const context = adminPage.context();
    // The employee marks the day themselves (entries belong to the logged-in user). The group is
    // created inside the try, so whatever fails, the finally below removes both.
    const employee = employeePage.context();
    const unavailable = await createAvailability(employee, {
      type: 'unavailable',
      startDate: UNAVAILABLE_DAY,
    });
    let groupId = null;
    try {
      groupId = await createShiftGroupWithStreakRule(context, {
        group: { name: UNAVAILABLE_GROUP },
      });
      await generateMonthShifts(context, { monthKey: UNAVAILABLE_MONTH, shiftGroupId: groupId });
      const assignedOnServer = async () =>
        (await getShiftGroupsAndShifts(context, UNAVAILABLE_MONTH, groupId)).schedule[
          UNAVAILABLE_DAY
        ][0].assignments.map((a) => a.user_name);

      const assigns = [];
      adminPage.on('request', (req) => {
        if (req.method() === 'POST' && new URL(req.url()).pathname === '/api/schedule/assign') {
          assigns.push(req.postDataJSON());
        }
      });

      await adminPage.goto(`/schedule/${UNAVAILABLE_MONTH}`);
      await openGroup(adminPage, UNAVAILABLE_GROUP, groupId);
      const dropzone = adminPage
        .locator(`[data-date="${UNAVAILABLE_DAY}"]`)
        .locator('[data-shift-id]')
        .first();
      await expect(dropzone).toBeVisible();
      const rosterCard = adminPage.locator('div.cursor-grab', { hasText: EMPLOYEE });
      const assignedChip = dropzone.locator('div', { hasText: EMPLOYEE });
      const warning = adminPage.getByRole('alertdialog', { name: /Unavailable Period/ });

      // 1. Cancel: the dropped chip is taken back off and nothing is sent.
      await dragToShiftDropzone(adminPage, rosterCard, dropzone);
      await expect(warning).toBeVisible();
      await expect(warning).toContainText(EMPLOYEE);
      await expect(warning).toContainText(UNAVAILABLE_DAY);
      await expect(warning).toContainText('All day');
      await warning.getByRole('button', { name: 'Cancel' }).click();
      await expect(warning).toBeHidden();
      await expect(assignedChip).toHaveCount(0);
      await adminPage.waitForLoadState('networkidle');
      expect(assigns).toEqual([]);
      expect(await assignedOnServer()).toEqual([]);

      // 2. Assign Anyway: one assign request, the chip stays and is persisted.
      const saved = adminPage.waitForResponse(
        (res) =>
          new URL(res.url()).pathname === '/api/schedule/assign' &&
          res.request().method() === 'POST',
      );
      await dragToShiftDropzone(adminPage, rosterCard, dropzone);
      await expect(warning).toBeVisible();
      await warning.getByRole('button', { name: 'Assign Anyway' }).click();
      await expect(warning).toBeHidden();
      expect((await saved).ok()).toBe(true);
      await expect(assignedChip.first()).toBeVisible();
      expect(assigns).toHaveLength(1);
      expect(await assignedOnServer()).toEqual([EMPLOYEE]);

      await adminPage.reload();
      await openGroup(adminPage, UNAVAILABLE_GROUP, groupId);
      await expect(assignedChip.first()).toBeVisible();
    } finally {
      if (groupId) await archiveShiftGroup(context, groupId).catch(() => {});
      await deleteAvailability(employee, unavailable.id).catch(() => {});
    }
  });
});

test.describe('Office Closed warning before Generate Month', () => {
  test('Cancel generates nothing; "Generate as Office" generates the month with office shifts on the closed day', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: CLOSURE_GROUP },
    });
    try {
      await createOfficeClosure(context, { startDate: CLOSED_DAY, title: 'Closure cancel day' });
      const scheduleOnServer = async () =>
        (await getShiftGroupsAndShifts(context, CLOSURE_MONTH, groupId)).schedule;

      const generates = [];
      adminPage.on('request', (req) => {
        if (req.method() === 'POST' && new URL(req.url()).pathname === '/api/schedule/generate') {
          generates.push(req.url());
        }
      });

      await adminPage.goto(`/schedule/${CLOSURE_MONTH}`);
      await openGroup(adminPage, CLOSURE_GROUP, groupId);
      const zones = adminPage.locator('[data-shift-id]');
      await expect(zones).toHaveCount(0);
      const warning = adminPage.getByRole('alertdialog', { name: /Office Closed on Some Dates/ });

      const startGenerate = async () => {
        await adminPage.getByRole('button', { name: 'Generate Month' }).click();
        const modal = adminPage.locator('div.fixed', {
          has: adminPage.getByText(/Generate Shifts/),
        });
        await expect(modal).toBeVisible();
        await modal.getByRole('button', { name: /^Generate / }).click();
        await expect(warning).toBeVisible();
        await expect(warning).toContainText('Closure cancel day');
      };

      // 1. Cancel: no generate request, the month stays empty.
      await startGenerate();
      await warning.getByRole('button', { name: 'Cancel' }).click();
      await expect(warning).toBeHidden();
      await adminPage.waitForLoadState('networkidle');
      expect(generates).toEqual([]);
      await expect(zones).toHaveCount(0);
      // An empty month serialises as [] (an empty PHP array), a filled one as an object.
      expect(Object.values(await scheduleOnServer())).toEqual([]);

      // 2. Generate as Office: the month is generated, the closed day's shifts included, all office.
      const generated = adminPage.waitForResponse(
        (res) =>
          new URL(res.url()).pathname === '/api/schedule/generate' &&
          res.request().method() === 'POST',
      );
      await startGenerate();
      await warning.getByRole('button', { name: 'Generate as Office' }).click();
      expect((await generated).ok()).toBe(true);
      await expect(warning).toBeHidden();
      await expect(adminPage.getByText(/generated|created/i).first()).toBeVisible();
      await expect(
        adminPage.locator(`[data-date="${CLOSED_DAY}"]`).locator('[data-shift-id]').first(),
      ).toBeVisible();
      expect(generates).toHaveLength(1);
      const schedule = await scheduleOnServer();
      expect(Object.keys(schedule)).toHaveLength(31);
      expect(schedule[CLOSED_DAY].length).toBeGreaterThan(0);
      expect(schedule[CLOSED_DAY].every((s) => s.work_mode === 'office')).toBe(true);
    } finally {
      await archiveShiftGroup(context, groupId).catch(() => {});
    }
  });
});
