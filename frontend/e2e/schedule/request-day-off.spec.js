// frontend/e2e/schedule/request-day-off.spec.js
import {
  assignShift,
  generateMonthShifts,
  getEmployeeIdByEmail,
  getShiftGroupsAndShifts,
  listShiftGroups,
} from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('schedule/request-day-off');

// Month reserved for this file: 2093-08 (far future, so the server always treats the shift as
// upcoming: requests can only be filed for today or later). The shift lives in the default group:
// an employee cannot list or switch groups, so the Schedule screen shows them that one.
const MONTH = reserve.month('2093-08');
const DAY = '2093-08-05';
const REASON = 'Dentist appointment';

// The default group cannot be archived like a spec's own group: clearing the month removes its
// shifts and assignments and settles the filed request, so a retry (or the next run) starts clean.
async function clearMonth(context, shiftGroupId) {
  const res = await context.request.delete('/api/schedule/clear-month', {
    data: { month: MONTH, shift_group_id: shiftGroupId },
    headers: { Accept: 'application/json', ...(await getXsrfHeader(context)) },
  });
  if (!res.ok()) throw new Error(`clear month failed: ${res.status()} ${await res.text()}`);
}

test.describe('Request Day Off from the Schedule screen', () => {
  test('an employee files a request from their own chip; it shows as pending for them and flagged for the manager', async ({
    adminPage,
    employeePage,
  }) => {
    const admin = adminPage.context();
    const defaultGroup = (await listShiftGroups(admin)).find((g) => g.is_default);
    if (!defaultGroup) throw new Error('no default shift group: the migrations seed one');
    try {
      await generateMonthShifts(admin, { monthKey: MONTH, shiftGroupId: defaultGroup.id });
      const employeeId = await getEmployeeIdByEmail(admin, 'regular@shiftchanger.com');
      const { schedule } = await getShiftGroupsAndShifts(admin, MONTH, defaultGroup.id);
      await assignShift(admin, { shiftId: schedule[DAY][0].id, userId: employeeId });

      const filed = [];
      employeePage.on('request', (req) => {
        if (
          req.method() === 'POST' &&
          new URL(req.url()).pathname === '/api/shift-change-requests'
        ) {
          filed.push(req.postDataJSON());
        }
      });

      const loaded = employeePage.waitForResponse((res) =>
        res.url().includes(`/api/schedule?month=${MONTH}`),
      );
      await employeePage.goto(`/schedule/${MONTH}`);
      await loaded;
      const cell = employeePage.locator(`[data-date="${DAY}"]`);
      await expect(cell).toContainText('Regular Employee');
      const requestButton = cell.getByTitle('Request day off');
      const dialog = employeePage.getByRole('dialog', { name: /Request Day Off/ });
      const reason = dialog.getByPlaceholder("Please describe why you can't work this shift...");
      const submit = dialog.getByRole('button', { name: 'Submit Request' });

      // 1. The dialog names the shift; Submit needs a reason; Cancel sends nothing.
      await requestButton.click();
      await expect(dialog).toBeVisible();
      await expect(dialog).toContainText('Shift 1 on');
      await expect(dialog).toContainText('August 5, 2093');
      await expect(submit).toBeDisabled();
      await reason.fill('Changed my mind');
      await expect(submit).toBeEnabled();
      await dialog.getByRole('button', { name: 'Cancel' }).click();
      await expect(dialog).toBeHidden();
      expect(filed).toEqual([]);
      await expect(requestButton).toBeVisible();

      // 2. Reopened, the reason starts blank; submitting files one request and marks the chip.
      await requestButton.click();
      await expect(reason).toHaveValue('');
      await reason.fill(REASON);
      const response = employeePage.waitForResponse(
        (res) =>
          new URL(res.url()).pathname === '/api/shift-change-requests' &&
          res.request().method() === 'POST',
      );
      await submit.click();
      expect((await response).ok()).toBe(true);
      await expect(employeePage.getByText('Day-off request submitted successfully.')).toBeVisible();
      await expect(dialog).toBeHidden();
      await expect(cell.getByText('Pending')).toBeVisible();
      await expect(requestButton).toHaveCount(0);
      expect(filed).toHaveLength(1);
      expect(filed[0]).toMatchObject({ reason: REASON });

      // 3. Persisted: the chip is still pending after a reload.
      await employeePage.reload();
      await expect(cell).toContainText('Regular Employee');
      await expect(cell.getByText('Pending')).toBeVisible();
      await expect(requestButton).toHaveCount(0);

      // 4. The manager sees the chip flagged with the reason.
      // The Schedule screen opens on the default group for a manager (no tab click: its name is a
      // prefix of other groups' names, e.g. "Main" and "Maintainer ...").
      const managerLoaded = adminPage.waitForResponse(
        (res) =>
          res.url().includes(`/api/schedule?month=${MONTH}`) &&
          res.url().includes(`shift_group_id=${defaultGroup.id}`),
      );
      await adminPage.goto(`/schedule/${MONTH}`);
      await managerLoaded;
      const managerCell = adminPage.locator(`[data-date="${DAY}"]`);
      await expect(managerCell.getByTitle(`⚠ Day-off request: ${REASON}`)).toBeVisible();
      await expect(managerCell.getByTitle(`⚠ Day-off request: ${REASON}`)).toContainText(
        'Regular Employee',
      );
    } finally {
      await clearMonth(admin, defaultGroup.id).catch(() => {});
    }
  });
});
