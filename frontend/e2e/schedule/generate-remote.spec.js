// frontend/e2e/schedule/generate-remote.spec.js
import {
  archiveShiftGroup,
  createOfficeClosure,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getShiftGroupsAndShifts,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

// L21: "Switch to Remote" (generate, then make the closed dates' office shifts remote) stops when
// generating fails and shows the server's reason; when it works, the switch is one request.
// Month 2091-03 is reserved for this file (a closure on the 12th).
const MONTH = '2091-03';
const CLOSED = '2091-03-12';
const OPEN = '2091-03-13';
const GROUP = 'Remote Switch Team';

test.describe('Generate month: switch closed dates to remote', () => {
  test('a failed generate switches nothing and says why; a working one switches in one request', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: GROUP } });
    try {
      // The month already has office shifts, the closed date included.
      await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });
      await createOfficeClosure(context, { startDate: CLOSED, title: 'Remote switch closure' });

      const workModeWrites = [];
      adminPage.on('request', (req) => {
        if (req.method() === 'PUT' && /work-mode$/.test(new URL(req.url()).pathname)) {
          workModeWrites.push(new URL(req.url()).pathname);
        }
      });

      await adminPage.goto(`/schedule/${MONTH}`);
      await adminPage.getByRole('button', { name: new RegExp(GROUP) }).click();
      await adminPage.waitForLoadState('networkidle');

      const generateAndSwitch = async () => {
        await adminPage.getByRole('button', { name: 'Generate Month' }).click();
        await adminPage.getByRole('button', { name: /^Generate March 2091/ }).click();
        const closed = adminPage.getByRole('alertdialog', { name: /Office Closed on Some Dates/ });
        await expect(closed).toBeVisible();
        await closed.getByRole('button', { name: /Switch to Remote/ }).click();
      };

      // 1. Generating fails: the server's message is shown and nothing is switched.
      const message = 'The selected shift group does not exist or is archived.';
      await adminPage.route('**/api/schedule/generate', (route) =>
        route.fulfill({
          status: 422,
          contentType: 'application/json',
          body: JSON.stringify({ message, errors: { shift_group_id: [message] } }),
        }),
      );
      const failed = adminPage.waitForResponse((res) =>
        res.url().includes('/api/schedule/generate'),
      );
      await generateAndSwitch();
      expect((await failed).status()).toBe(422);
      await expect(adminPage.getByText(message)).toBeVisible();
      await adminPage.waitForLoadState('networkidle');
      expect(workModeWrites).toEqual([]);
      let schedule = (await getShiftGroupsAndShifts(context, MONTH, groupId)).schedule;
      expect(schedule[CLOSED].map((s) => s.work_mode)).toEqual(['office']);

      // 2. Generating works but the reload after it fails: what's on screen is the old month, so
      //    nothing is switched and the user is told (M3).
      await adminPage.unroute('**/api/schedule/generate');
      let failReload = true;
      const isScheduleGet = (url) => url.pathname === '/api/schedule';
      await adminPage.route(isScheduleGet, async (route) => {
        if (failReload && route.request().method() === 'GET') {
          failReload = false;
          await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
        } else {
          await route.fallback();
        }
      });
      await generateAndSwitch();
      await expect(
        adminPage.getByText(/the closed dates were not switched to remote/),
      ).toBeVisible();
      await adminPage.waitForLoadState('networkidle');
      expect(workModeWrites).toEqual([]);
      await adminPage.unroute(isScheduleGet);
      // Back to a loaded month for the next step.
      await adminPage.reload();
      await adminPage.getByRole('button', { name: new RegExp(GROUP) }).click();
      await adminPage.waitForLoadState('networkidle');

      // 3. Generating works: the closed date's office shifts go remote in one request.
      const switched = adminPage.waitForResponse(
        (res) => new URL(res.url()).pathname === '/api/schedule/work-mode',
      );
      await generateAndSwitch();
      expect((await switched).status()).toBe(200);
      const toast = adminPage.getByText(/^Switched \d+ shifts to remote on closed dates\.$/);
      await expect(toast).toBeVisible();
      const toastText = await toast.innerText();
      expect(workModeWrites).toEqual(['/api/schedule/work-mode']);

      // The dialog's default fills each day up to its slot count (the new slots are office too);
      // every slot of the closed date is now remote, the next day's stay office.
      schedule = (await getShiftGroupsAndShifts(context, MONTH, groupId)).schedule;
      const closedModes = schedule[CLOSED].map((s) => s.work_mode);
      expect(closedModes.length).toBeGreaterThan(1);
      expect(closedModes.every((m) => m === 'remote')).toBe(true);
      expect(schedule[OPEN].every((s) => s.work_mode === 'office')).toBe(true);
      expect(toastText).toBe(`Switched ${closedModes.length} shifts to remote on closed dates.`);
    } finally {
      await archiveShiftGroup(context, groupId).catch(() => {});
    }
  });
});
