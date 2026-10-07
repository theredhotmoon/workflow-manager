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

// The report opens on the settlement period containing the server's "today", so the month is
// read from the API rather than hardcoded (a fixed month would drift out of the default view).
async function getPeriod(context, groupId, offset) {
  const res = await context.request.get('/api/settlement-periods', {
    params: { shift_group_id: groupId, offset },
  });
  if (!res.ok()) throw new Error(`fetch period failed: ${res.status()} ${await res.text()}`);
  return (await res.json()).period;
}

// Opens the report and selects a group, waiting for that group's report to arrive. (The view ignores
// stale responses, so a slower on-load fetch for the default group can't overwrite it — see the
// "slow initial report" test.)
async function openReportFor(page, groupName, groupId) {
  await page.goto('/settlement-periods');
  const selected = page.waitForResponse(
    (res) =>
      res.url().includes('/api/settlement-periods') &&
      res.url().includes(`shift_group_id=${groupId}`),
  );
  await page.getByRole('combobox').selectOption({ label: groupName });
  await selected;
}

// Hours between HH:MM times, rolling an overnight shift (end <= start) over midnight.
function shiftHours({ start_time, end_time }) {
  const toMinutes = (t) => Number(t.slice(0, 2)) * 60 + Number(t.slice(3, 5));
  let minutes = toMinutes(end_time) - toMinutes(start_time);
  if (minutes <= 0) minutes += 24 * 60;
  return minutes / 60;
}

test.describe('Settlement Periods report', () => {
  test('a slow initial report for the default group does not overwrite the group the user picked', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Race Report Team' },
    });
    const current = await getPeriod(context, groupId, 0);
    const monthKey = current.start.slice(0, 7);
    await generateMonthShifts(context, { monthKey, shiftGroupId: groupId });
    const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const { schedule } = await getShiftGroupsAndShifts(context, monthKey, groupId);
    await assignShift(context, { shiftId: schedule[current.start][0].id, userId: employeeId });

    // The page fetches the default group's report on load. Hold that first request until the user
    // has picked another group and its report has arrived, so the default group's response lands last.
    const isReport = (url) => url.pathname === '/api/settlement-periods';
    let release;
    const held = new Promise((resolve) => {
      release = resolve;
    });
    let firstSeen = false;
    await adminPage.route(isReport, async (route) => {
      if (!firstSeen) {
        firstSeen = true;
        await held;
      }
      await route.continue();
    });

    await adminPage.goto('/settlement-periods');
    const picked = adminPage.waitForResponse(
      (res) => isReport(new URL(res.url())) && res.url().includes(`shift_group_id=${groupId}`),
    );
    await adminPage.getByRole('combobox').selectOption({ label: 'Race Report Team' });
    await picked;
    const row = adminPage.getByRole('row', { name: /Regular Employee/ });
    await expect(row).toBeVisible();

    const late = adminPage.waitForResponse(
      (res) => isReport(new URL(res.url())) && !res.url().includes(`shift_group_id=${groupId}`),
    );
    release();
    await late;
    await adminPage.waitForLoadState('networkidle');

    // Still the picked group's report: its employee row is shown and the select still says so.
    await expect(row).toBeVisible();
    await expect(adminPage.getByRole('combobox')).toHaveValue(String(groupId));
  });

  test('loads groups and shows the report for the selected group, with prev-period navigation', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Report Team' },
    });
    const current = await getPeriod(context, groupId, 0);
    const previous = await getPeriod(context, groupId, -1);
    const monthKey = current.start.slice(0, 7);
    await generateMonthShifts(context, { monthKey, shiftGroupId: groupId });
    const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const { schedule } = await getShiftGroupsAndShifts(context, monthKey, groupId);
    // The period is anchored on the group's creation date (not the 1st), so assign the shift on
    // the period's first day. One assignment also keeps us under the API throttle.
    const [periodStartShift] = schedule[current.start];
    await assignShift(context, { shiftId: periodStartShift.id, userId: employeeId });

    await openReportFor(adminPage, 'Report Team', groupId);

    await expect(adminPage.getByText('Regular Employee')).toBeVisible();
    // Cells: Employee, Week 1..n, Total, Streak. The shift sits on the period's first day (week 1).
    // The view prints the number as-is (8, not 8.0 or 8h).
    const hours = String(shiftHours(periodStartShift));
    const cells = adminPage.getByRole('row', { name: /Regular Employee/ }).getByRole('cell');
    await expect(cells.nth(1)).toHaveText(hours);
    await expect(cells.nth(-2)).toHaveText(hours);
    await expect(
      adminPage.getByText(`${current.start} – ${current.end}`, { exact: true }).first(),
    ).toBeVisible();

    await adminPage.getByRole('button', { name: 'Previous period' }).click();
    await expect(
      adminPage.getByText(`${previous.start} – ${previous.end}`, { exact: true }).first(),
    ).toBeVisible();
  });

  test('highlights a week in which an employee exceeds the group weekly hour cap', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'OverCap Team' },
    });
    // The generated shift is 8h, so a 4h cap is exceeded by a single assignment.
    const CAP = 4;
    await updateShiftGroup(context, groupId, { weekly_hour_cap: CAP });
    const current = await getPeriod(context, groupId, 0);
    const monthKey = current.start.slice(0, 7);
    await generateMonthShifts(context, { monthKey, shiftGroupId: groupId });
    const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const { schedule } = await getShiftGroupsAndShifts(context, monthKey, groupId);
    const [periodStartShift] = schedule[current.start];
    await assignShift(context, { shiftId: periodStartShift.id, userId: employeeId });
    const hours = shiftHours(periodStartShift);
    expect(hours).toBeGreaterThan(CAP);

    await openReportFor(adminPage, 'OverCap Team', groupId);

    // Positive guard: the report rendered with the persisted cap and the employee's hours.
    const row = adminPage.getByRole('row', { name: /Regular Employee/ });
    await expect(row).toBeVisible();
    await expect(adminPage.getByText(`cap ${CAP}h`).first()).toBeVisible();

    // Week 1 holds the shift; it is over the cap, so it is flagged.
    const week1 = row.getByRole('cell').nth(1);
    await expect(week1).toHaveText(`${hours} ⚠️`);
    await expect(week1).toHaveClass(/text-red-400/);
    // The Total cell is never flagged.
    await expect(row.getByRole('cell').nth(-2)).not.toHaveClass(/text-red-400/);
  });

  test('renders mid-streak and resting streak status for an employee on night shifts', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Streak Report Team' },
      rule: { shift_type: 'night', streak_length: 3, rest_days: 2 },
    });
    const current = await getPeriod(context, groupId, 0);

    // Three consecutive days from the period start; they may cross a month boundary.
    const days = [0, 1, 2].map((offset) => {
      const d = new Date(`${current.start}T12:00:00Z`);
      d.setUTCDate(d.getUTCDate() + offset);
      return d.toISOString().slice(0, 10);
    });
    const months = [...new Set(days.map((d) => d.slice(0, 7)))];
    const shiftByDay = {};
    for (const month of months) {
      await generateMonthShifts(context, { monthKey: month, shiftGroupId: groupId });
      await setShiftTypes(context, {
        shiftGroupId: groupId,
        month,
        shiftNumber: 1,
        shiftType: 'night',
      });
      const { schedule } = await getShiftGroupsAndShifts(context, month, groupId);
      for (const day of days) if (schedule[day]) shiftByDay[day] = schedule[day][0];
    }
    const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');

    await assignShift(context, { shiftId: shiftByDay[days[0]].id, userId: employeeId });
    await assignShift(context, { shiftId: shiftByDay[days[1]].id, userId: employeeId });
    await openReportFor(adminPage, 'Streak Report Team', groupId);
    const row = adminPage.getByRole('row', { name: /Regular Employee/ });
    await expect(row).toBeVisible();
    await expect(row.getByRole('cell').last()).toHaveText('night: 2/3');

    await assignShift(context, { shiftId: shiftByDay[days[2]].id, userId: employeeId });
    const restEnd = new Date(`${days[2]}T12:00:00Z`);
    restEnd.setUTCDate(restEnd.getUTCDate() + 2); // rest_days
    const restingUntil = restEnd.toISOString().slice(0, 10); // resting_until is serialised as Y-m-d
    await openReportFor(adminPage, 'Streak Report Team', groupId);
    await expect(row.getByRole('cell').last()).toHaveText(`night: resting until ${restingUntil}`);
  });

  test('shows an empty-assignments message for a period with no assignments yet', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Empty Group' },
    });

    await openReportFor(adminPage, 'Empty Group', groupId);

    await expect(adminPage.getByText('No assignments in this period yet.')).toBeVisible();
  });
});
