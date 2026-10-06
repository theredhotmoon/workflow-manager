import {
  assignShift,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
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
    await expect(
      adminPage.getByText(`${current.start} – ${current.end}`, { exact: true }).first(),
    ).toBeVisible();

    await adminPage.getByRole('button', { name: 'Previous period' }).click();
    await expect(
      adminPage.getByText(`${previous.start} – ${previous.end}`, { exact: true }).first(),
    ).toBeVisible();
  });

  test('renders the streak status column for a mid-streak employee', async ({ adminPage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Streak Report Team' },
      rule: { shift_type: 'night', streak_length: 3, rest_days: 2 },
    });
    const monthKey = (await getPeriod(context, groupId, 0)).start.slice(0, 7);
    await generateMonthShifts(context, { monthKey, shiftGroupId: groupId });
    const employeeId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const { schedule } = await getShiftGroupsAndShifts(context, monthKey, groupId);

    // Two night shifts on consecutive days: a mid-streak, below the 3-shift rest threshold.
    const days = Object.keys(schedule).sort();
    let pair = null;
    for (let i = 0; i < days.length - 1 && !pair; i++) {
      const first = schedule[days[i]].find((s) => s.shift_type === 'night');
      const second = schedule[days[i + 1]].find((s) => s.shift_type === 'night');
      if (first && second) pair = [first, second];
    }
    // No API endpoint sets Shift.shift_type (schedule/generate leaves it null and the schedule
    // payload does not even expose it), so the streak counter can't be driven from e2e yet.
    test.fixme(!pair, 'no API path creates night-typed shifts; streak counter cannot be seeded');
    for (const shift of pair) await assignShift(context, { shiftId: shift.id, userId: employeeId });

    await adminPage.goto('/settlement-periods');
    await adminPage.getByRole('combobox').selectOption({ label: 'Streak Report Team' });

    await expect(adminPage.getByText('night: 2/3', { exact: true })).toBeVisible();
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
