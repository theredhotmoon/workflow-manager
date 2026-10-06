import {
  assignShift,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
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

// Hours between HH:MM times, rolling an overnight shift (end <= start) over midnight.
function shiftHours({ start_time, end_time }) {
  const toMinutes = (t) => Number(t.slice(0, 2)) * 60 + Number(t.slice(3, 5));
  let minutes = toMinutes(end_time) - toMinutes(start_time);
  if (minutes <= 0) minutes += 24 * 60;
  return minutes / 60;
}

test.describe('Settlement Periods report', () => {
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

    await adminPage.goto('/settlement-periods');
    await adminPage.getByRole('combobox').selectOption({ label: 'Report Team' });

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

    await adminPage.goto('/settlement-periods');
    await adminPage.getByRole('combobox').selectOption({ label: 'OverCap Team' });

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

  test('renders the streak status column for an employee under a night streak rule', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Streak Report Team' },
      rule: { shift_type: 'night', streak_length: 3, rest_days: 2 },
    });
    const current = await getPeriod(context, groupId, 0);
    const monthKey = current.start.slice(0, 7);
    await generateMonthShifts(context, { monthKey, shiftGroupId: groupId });
    const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const { schedule } = await getShiftGroupsAndShifts(context, monthKey, groupId);
    await assignShift(context, { shiftId: schedule[current.start][0].id, userId: employeeId });

    await adminPage.goto('/settlement-periods');
    await adminPage.getByRole('combobox').selectOption({ label: 'Streak Report Team' });

    // A mid-streak "night: 2/3" is unreachable end-to-end because no API sets shifts.shift_type,
    // so this only guards the column rendering / rule wiring. formatStreakStatus() renders nothing
    // for a count of 0 (never "night: 0/3"), so the employee's Streak cell must be empty.
    const row = adminPage.getByRole('row', { name: /Regular Employee/ });
    await expect(row).toBeVisible();
    await expect(row.getByRole('cell').last()).toHaveText('');
  });

  test('shows an empty-assignments message for a period with no assignments yet', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'Empty Group' } });

    await adminPage.goto('/settlement-periods');
    await adminPage.getByRole('combobox').selectOption({ label: 'Empty Group' });

    await expect(adminPage.getByText('No assignments in this period yet.')).toBeVisible();
  });
});
