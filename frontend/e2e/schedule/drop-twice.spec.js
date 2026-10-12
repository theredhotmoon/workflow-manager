// frontend/e2e/schedule/drop-twice.spec.js
import {
  archiveShiftGroup,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { dragToShiftDropzone } from '../fixtures/drag.js';
import { holdRequests } from '../fixtures/hold.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('schedule/drop-twice');

// F-b: while a drop is still being saved, dropping the same person on that shift again doesn't add
// a second chip (or a second assignment), and the not-yet-saved chip can't be dragged elsewhere.
// Month 2091-04 is reserved for this file.
const MONTH = reserve.month('2091-04');
const DAY = '2091-04-11';
const NEXT_DAY = '2091-04-12';
const EMPLOYEE = 'Regular Employee';
const GROUP = reserve.name('Drop Twice Team');

test.describe('Schedule: dropping again before the save answers', () => {
  test('keeps one chip and sends one assign', async ({ adminPage }) => {
    await adminPage.setViewportSize({ width: 1280, height: 1000 });
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: GROUP } });
    try {
      await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });
      const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');

      const assigns = [];
      adminPage.on('request', (req) => {
        if (req.method() === 'POST' && new URL(req.url()).pathname === '/api/schedule/assign') {
          assigns.push(req);
        }
      });

      await adminPage.goto(`/schedule/${MONTH}`);
      const loaded = adminPage.waitForResponse(
        (res) =>
          res.url().includes('/api/schedule?') && res.url().includes(`shift_group_id=${groupId}`),
      );
      await adminPage.getByRole('button', { name: new RegExp(GROUP) }).click();
      await loaded;
      await adminPage.waitForLoadState('networkidle');

      const zone = adminPage.locator(`[data-date="${DAY}"]`).locator('[data-shift-id]').first();
      const nextZone = adminPage
        .locator(`[data-date="${NEXT_DAY}"]`)
        .locator('[data-shift-id]')
        .first();
      const roster = adminPage.locator('div.cursor-grab', { hasText: EMPLOYEE }).first();
      const chips = zone.locator('div.cursor-grab', { hasText: EMPLOYEE });

      const held = await holdRequests(adminPage, (url) => url.pathname === '/api/schedule/assign', {
        method: 'POST',
        times: 1,
      });

      await dragToShiftDropzone(adminPage, roster, zone);
      await held.seen;
      await expect(chips).toHaveCount(1);
      await expect(chips).toHaveAttribute('data-pending', 'true');

      // Same person, same shift, while the first is still being saved.
      await dragToShiftDropzone(adminPage, roster, zone);
      await expect(adminPage.getByText(`${EMPLOYEE} is already on this shift.`)).toBeVisible();
      await expect(chips).toHaveCount(1);

      // The unsaved chip can't be moved on to another day.
      await dragToShiftDropzone(adminPage, chips.first(), nextZone);
      await expect(nextZone.locator('div.cursor-grab', { hasText: EMPLOYEE })).toHaveCount(0);
      await expect(chips).toHaveCount(1);

      await held.releaseAndSettle();
      await expect(chips).toHaveCount(1);
      await expect(chips).not.toHaveAttribute('data-pending', 'true');
      expect(assigns).toHaveLength(1);

      const schedule = (await getShiftGroupsAndShifts(context, MONTH, groupId)).schedule;
      expect(schedule[DAY][0].assignments.map((a) => a.user_id)).toEqual([employeeId]);
      expect(schedule[NEXT_DAY][0].assignments).toEqual([]);
    } finally {
      await archiveShiftGroup(context, groupId).catch(() => {});
    }
  });
});
