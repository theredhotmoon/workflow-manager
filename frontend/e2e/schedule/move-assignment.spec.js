// frontend/e2e/schedule/move-assignment.spec.js
import {
  assignShift,
  createDelegation,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { dragToShiftDropzone } from '../fixtures/drag.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('schedule/move-assignment');
reserve.months('2095-01', '2095-02', '2095-03', '2095-04');

// Months reserved for this file (delegations are global, not per group): 2095-01 (cancelled move),
// 2095-02 (move), 2095-03 (work mode), 2095-04 (maintainer delegation warning).
const EMPLOYEE = 'Regular Employee';

const dayZone = (page, date) =>
  page.locator(`[data-date="${date}"]`).locator('[data-shift-id]').first();
const chipIn = (zone) => zone.locator('div.cursor-grab', { hasText: EMPLOYEE });

async function seedGroup(context, name, month) {
  const groupId = await createShiftGroupWithStreakRule(context, { group: { name } });
  await generateMonthShifts(context, { monthKey: month, shiftGroupId: groupId });
  const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
  const { schedule } = await getShiftGroupsAndShifts(context, month, groupId);
  return { groupId, employeeId, schedule };
}

// Opens the month on the group's tab and waits for that group's schedule (and the overlays that load
// with it), so a drag never races the initial loads.
async function openGroup(page, { month, groupId, name }) {
  await page.goto(`/schedule/${month}`);
  const loaded = page.waitForResponse(
    (res) =>
      res.url().includes('/api/schedule?') && res.url().includes(`shift_group_id=${groupId}`),
  );
  await page.getByRole('button', { name: new RegExp(name) }).click();
  await loaded;
  await page.waitForLoadState('networkidle');
}

async function datesOf(context, { month, groupId, employeeId }) {
  const { schedule } = await getShiftGroupsAndShifts(context, month, groupId);
  return Object.entries(schedule)
    .filter(([, shifts]) => shifts.some((s) => s.assignments.some((a) => a.user_id === employeeId)))
    .map(([date]) => date)
    .sort();
}

function recordUnassigns(page) {
  const urls = [];
  page.on('request', (req) => {
    if (req.method() === 'DELETE' && req.url().includes('/api/schedule/unassign/'))
      urls.push(req.url());
  });
  return urls;
}

test.describe('Moving a chip between shifts', () => {
  test.beforeEach(async ({ adminPage }) => {
    // Tall enough for both days of a move and the sticky roster.
    await adminPage.setViewportSize({ width: 1280, height: 1000 });
  });

  test('cancelling a warning on the target leaves the employee on the source shift', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const month = '2095-01';
    const name = 'Move Cancel Team';
    const { groupId, employeeId, schedule } = await seedGroup(context, name, month);
    // 01-10 makes the 01-11 target back-to-back; 01-13 is the chip that moves.
    for (const day of ['10', '13']) {
      await assignShift(context, {
        shiftId: schedule[`${month}-${day}`][0].id,
        userId: employeeId,
      });
    }
    const unassigns = recordUnassigns(adminPage);

    await openGroup(adminPage, { month, groupId, name });
    await dragToShiftDropzone(
      adminPage,
      chipIn(dayZone(adminPage, '2095-01-13')),
      dayZone(adminPage, '2095-01-11'),
    );

    const dialog = adminPage.locator('div.max-w-md', { hasText: 'Back-to-Back Shifts' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await adminPage.waitForLoadState('networkidle');

    // The source chip comes back (a quiet reload) and nothing was unassigned on the server.
    await expect(chipIn(dayZone(adminPage, '2095-01-13'))).toBeVisible();
    await expect(chipIn(dayZone(adminPage, '2095-01-11'))).toHaveCount(0);
    expect(unassigns).toEqual([]);
    expect(await datesOf(context, { month, groupId, employeeId })).toEqual([
      '2095-01-10',
      '2095-01-13',
    ]);

    await adminPage.reload();
    await openGroup(adminPage, { month, groupId, name });
    await expect(chipIn(dayZone(adminPage, '2095-01-13'))).toBeVisible();
    await expect(chipIn(dayZone(adminPage, '2095-01-11'))).toHaveCount(0);
  });

  test('a move is one request that leaves the employee only on the target', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const month = '2095-02';
    const name = 'Move Success Team';
    const { groupId, employeeId, schedule } = await seedGroup(context, name, month);
    const source = await assignShift(context, {
      shiftId: schedule['2095-02-08'][0].id,
      userId: employeeId,
    });
    const unassigns = recordUnassigns(adminPage);

    await openGroup(adminPage, { month, groupId, name });
    const assigned = adminPage.waitForResponse(
      (res) => res.url().includes('/api/schedule/assign') && res.request().method() === 'POST',
    );
    await dragToShiftDropzone(
      adminPage,
      chipIn(dayZone(adminPage, '2095-02-08')),
      dayZone(adminPage, '2095-02-11'),
    );
    const response = await assigned;

    expect(response.status()).toBe(201);
    expect(response.request().postDataJSON().move_from_assignment_id).toBe(source.id);
    expect((await response.json()).moved_from_assignment_id).toBe(source.id);
    await expect(chipIn(dayZone(adminPage, '2095-02-11'))).toBeVisible();
    await expect(chipIn(dayZone(adminPage, '2095-02-08'))).toHaveCount(0);
    expect(unassigns).toEqual([]);
    expect(await datesOf(context, { month, groupId, employeeId })).toEqual(['2095-02-11']);
  });

  test('a remote shift stays remote after a reload and switches back in one click', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const month = '2095-03';
    const name = 'Remote Mode Team';
    const { groupId } = await seedGroup(context, name, month);
    const cell = adminPage.locator('[data-date="2095-03-15"]');
    const toRemote = 'Office shift — click to switch to Remote';
    const toOffice = 'Remote shift — click to switch to Office';

    await openGroup(adminPage, { month, groupId, name });
    const switched = adminPage.waitForResponse((res) => res.url().includes('/work-mode'));
    await cell.getByTitle(toRemote).click();
    expect((await switched).ok()).toBe(true);

    await adminPage.reload();
    await openGroup(adminPage, { month, groupId, name });
    await expect(cell.getByTitle(toOffice)).toBeVisible();

    const back = adminPage.waitForResponse((res) => res.url().includes('/work-mode'));
    await cell.getByTitle(toOffice).click();
    expect((await back).request().postDataJSON().work_mode).toBe('office');
    await expect(cell.getByTitle(toRemote)).toBeVisible();
  });
});

test.describe('Schedule maintainer', () => {
  test('sees the delegation warning when dropping someone who is on a delegation', async ({
    adminPage,
    maintainerPage,
  }) => {
    const adminContext = adminPage.context();
    const month = '2095-04';
    const name = 'Maintainer Delegation Team';
    const { groupId, employeeId } = await seedGroup(adminContext, name, month);
    await createDelegation(adminContext, {
      name: 'Maintainer Check Trip',
      destination: 'Gdansk',
      start: '2095-04-11',
      end: '2095-04-13',
      userIds: [employeeId],
    });

    await maintainerPage.setViewportSize({ width: 1280, height: 1000 });
    await openGroup(maintainerPage, { month, groupId, name });
    const rosterCard = maintainerPage.locator('div.cursor-grab', { hasText: EMPLOYEE }).first();
    await dragToShiftDropzone(maintainerPage, rosterCard, dayZone(maintainerPage, '2095-04-12'));

    const dialog = maintainerPage.locator('div.max-w-md', { hasText: 'On Delegation' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByText('Maintainer Check Trip')).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(chipIn(dayZone(maintainerPage, '2095-04-12'))).toHaveCount(0);
    expect(await datesOf(adminContext, { month, groupId, employeeId })).toEqual([]);
    // Group create/delete/settings are admin-only writes, so a maintainer doesn't get the controls.
    await expect(maintainerPage.getByRole('button', { name: 'New Group' })).toHaveCount(0);
  });
});
