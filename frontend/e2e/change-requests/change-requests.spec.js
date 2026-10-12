// frontend/e2e/change-requests/change-requests.spec.js
import {
  archiveShiftGroup,
  assignShift,
  createShiftGroupWithStreakRule,
  createUser,
  generateMonthShifts,
  getShiftGroupsAndShifts,
  setDayShiftCount,
} from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, loginAs, STATEFUL_ORIGIN, test } from '../fixtures/auth.js';
import { cleanupStack } from '../fixtures/cleanup.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('change-requests/change-requests');
// Far-future months, so the server always sees their shifts as upcoming (requests can only be
// filed for today or later).
const ACK_MONTH = reserve.month('2092-07');
const REMOVED_MONTH = reserve.month('2092-06');
const GROUP = reserve.name('Change Requests Team');
const REMOVED_GROUP = reserve.name('Change Requests Removed Team');

async function api(context, method, url, data) {
  const res = await context.request[method](url, {
    data,
    headers: { Accept: 'application/json', ...(await getXsrfHeader(context)) },
  });
  if (!res.ok()) throw new Error(`${method} ${url} -> ${res.status()} ${await res.text()}`);
  return res.json();
}

async function deactivate(admin, userId) {
  await api(admin, 'post', `/api/users/${userId}/deactivate`, { remove_future_assignments: true });
}

/** A throwaway employee, signed in in their own browser context. */
async function throwawayEmployee(admin, browser, label) {
  const stamp = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  const email = `cr-${label}-${stamp}@example.test`;
  const user = await createUser(admin, { name: `CR ${label} ${stamp}`, email });
  const context = await browser.newContext({ extraHTTPHeaders: STATEFUL_ORIGIN });
  await loginAs(context, email, 'password123');
  return { user, context };
}

async function fileRequest(context, assignmentId, reason) {
  return api(context, 'post', '/api/shift-change-requests', {
    shift_assignment_id: assignmentId,
    reason,
  });
}

/** The number on a nav link's badge (its aria-hidden digits, not the sr-only "(N pending)"). */
function badgeNumber(link) {
  return link.getByTestId('nav-badge').locator('[aria-hidden="true"]');
}

function changeRequestsLink(page) {
  return page.getByRole('navigation').getByRole('link', { name: /^Change Requests/ });
}

test.describe('Change requests (managers)', () => {
  test('a schedule maintainer opens a request from its alarm and acknowledges it; the badge drops and the employee is told', async ({
    adminPage,
    maintainerPage,
    browser,
  }) => {
    const admin = adminPage.context();
    const cleanup = cleanupStack();
    try {
      const groupId = await createShiftGroupWithStreakRule(admin, { group: { name: GROUP } });
      cleanup.add(() => archiveShiftGroup(admin, groupId));
      const requester = await throwawayEmployee(admin, browser, 'ack');
      cleanup.add(() => deactivate(admin, requester.user.id));
      cleanup.add(() => requester.context.close());

      await generateMonthShifts(admin, { monthKey: ACK_MONTH, shiftGroupId: groupId });
      const { schedule } = await getShiftGroupsAndShifts(admin, ACK_MONTH, groupId);
      const assignment = await assignShift(admin, {
        shiftId: schedule[`${ACK_MONTH}-10`][0].id,
        userId: requester.user.id,
      });
      const request = await fileRequest(requester.context, assignment.id, 'Wedding in the family');

      const before = (
        await api(maintainerPage.context(), 'get', '/api/shift-change-requests/pending-count')
      ).count;
      expect(before).toBeGreaterThan(0);
      expect(before).toBeLessThan(100); // the badge shows "99+" above

      // The filing alarm opens the screen on the request's group and month.
      await maintainerPage.goto('/dashboard');
      await maintainerPage.getByRole('button', { name: 'Notifications' }).click();
      await maintainerPage
        .locator('#alarm-dropdown')
        .getByText(`${requester.user.name} cannot attend shift #1 on ${ACK_MONTH}-10`)
        .click();
      await expect(maintainerPage).toHaveURL(/\/change-requests$/);
      await expect(maintainerPage.getByRole('heading', { name: 'Change Requests' })).toBeVisible();
      await expect(maintainerPage.getByLabel('Group')).toHaveValue(String(groupId));
      await expect(maintainerPage.getByLabel('Month')).toHaveValue(ACK_MONTH);
      await expect(maintainerPage.getByLabel('Status')).toHaveValue('pending');

      const badge = badgeNumber(changeRequestsLink(maintainerPage));
      await expect(badge).toHaveText(String(before));

      const row = maintainerPage.locator(`[data-request-id="${request.id}"]`);
      await expect(row).toContainText(requester.user.name);
      await expect(row).toContainText('Wedding in the family');
      await expect(row).toContainText('Shift #1 · 08:00–16:00');
      await expect(row).toContainText(GROUP);
      await expect(row.getByTestId('cr-status')).toHaveText('Pending');
      await expect(row.getByRole('link', { name: 'View in schedule' })).toHaveAttribute(
        'href',
        new RegExp(
          `/schedule/${ACK_MONTH}\\?scroll_date=${ACK_MONTH}-10&highlight_user=${requester.user.id}&group=${groupId}$`,
        ),
      );

      // On a phone the nav strip is hidden: the menu button carries a dot and says what waits.
      await maintainerPage.setViewportSize({ width: 390, height: 800 });
      const menuButton = maintainerPage.getByRole('button', { name: /^Menu and account/ });
      await expect(maintainerPage.getByTestId('menu-dot')).toBeVisible();
      await expect(menuButton).toHaveAccessibleName(
        new RegExp(`${before} change requests? pending`),
      );
      await menuButton.click();
      await expect(
        badgeNumber(
          maintainerPage.locator('#user-menu').getByRole('link', { name: /^Change Requests/ }),
        ),
      ).toHaveText(String(before));
      await menuButton.click();
      await maintainerPage.setViewportSize({ width: 1280, height: 720 });

      await row.getByRole('button', { name: /^Acknowledge/ }).click();
      await expect(
        maintainerPage.getByText(`Acknowledged the request from ${requester.user.name}.`),
      ).toBeVisible();
      // Under the Pending filter the page is re-read: the acknowledged request leaves it.
      await expect(row).toHaveCount(0);
      if (before > 1) await expect(badge).toHaveText(String(before - 1));
      else await expect(changeRequestsLink(maintainerPage).getByTestId('nav-badge')).toHaveCount(0);

      await maintainerPage.getByLabel('Status').selectOption('all');
      await expect(row.getByTestId('cr-status')).toHaveText(/^Acknowledged by /);
      await expect(row.getByRole('button', { name: /^Acknowledge/ })).toHaveCount(0);

      // The requester gets a notification.
      const latest = await api(requester.context, 'get', '/api/alarms/latest');
      expect(latest.map((a) => a.type)).toContain('SHIFT_CHANGE_REQUEST_ACKNOWLEDGED');

      // The link opens the Schedule on the request's month and group.
      await row.getByRole('link', { name: 'View in schedule' }).click();
      await expect(maintainerPage).toHaveURL(new RegExp(`/schedule/${ACK_MONTH}$`));
      // The group's tab is the active one (the default group would be otherwise).
      await expect(maintainerPage.getByRole('button', { name: GROUP })).toHaveClass(
        /bg-indigo-500\/20/,
      );
    } finally {
      await cleanup.run();
    }
  });

  test('a request whose shift was removed shows the removed state', async ({
    adminPage,
    browser,
  }) => {
    const admin = adminPage.context();
    const cleanup = cleanupStack();
    const day = `${REMOVED_MONTH}-12`;
    try {
      const groupId = await createShiftGroupWithStreakRule(admin, {
        group: { name: REMOVED_GROUP },
      });
      cleanup.add(() => archiveShiftGroup(admin, groupId));
      const requester = await throwawayEmployee(admin, browser, 'removed');
      cleanup.add(() => deactivate(admin, requester.user.id));
      cleanup.add(() => requester.context.close());
      const colleague = await throwawayEmployee(admin, browser, 'colleague');
      cleanup.add(() => deactivate(admin, colleague.user.id));
      cleanup.add(() => colleague.context.close());

      await generateMonthShifts(admin, { monthKey: REMOVED_MONTH, shiftGroupId: groupId });
      await setDayShiftCount(admin, { date: day, count: 2, shiftGroupId: groupId });
      const { schedule } = await getShiftGroupsAndShifts(admin, REMOVED_MONTH, groupId);
      const [first, second] = [1, 2].map((n) => schedule[day].find((s) => s.shift_number === n));
      // Both slots staffed, so the reduction below removes the last one (an empty slot would go first).
      await assignShift(admin, { shiftId: first.id, userId: colleague.user.id });
      const assignment = await assignShift(admin, {
        shiftId: second.id,
        userId: requester.user.id,
      });
      const request = await fileRequest(requester.context, assignment.id, 'Moving house');
      // Slot 2 goes, with its (staffed) assignment: the request is kept, settled.
      await api(admin, 'put', '/api/schedule/day-shifts', {
        date: day,
        count: 1,
        shift_group_id: groupId,
        confirm_remove_staffed: true,
      });

      await adminPage.goto('/change-requests');
      await adminPage.getByLabel('Status').selectOption('all');
      await adminPage.getByLabel('Month').fill(REMOVED_MONTH);
      const row = adminPage.locator(`[data-request-id="${request.id}"]`);
      await expect(row.getByTestId('cr-removed')).toHaveText('Shift removed');
      await expect(row).toContainText('Shift #2');
      await expect(row).toContainText('Moving house');
      await expect(row.getByTestId('cr-status')).toHaveText('Settled');
      await expect(row.getByRole('button', { name: /^Acknowledge/ })).toHaveCount(0);
      // Still links to the day (no group: it went with the shift).
      await expect(row.getByRole('link', { name: 'View in schedule' })).toHaveAttribute(
        'href',
        new RegExp(`/schedule/${REMOVED_MONTH}\\?scroll_date=${day}&highlight_user=\\d+$`),
      );
    } finally {
      await cleanup.run();
    }
  });

  test('an employee has no Change Requests entry and cannot open the screen', async ({
    employeePage,
  }) => {
    await employeePage.goto('/dashboard');
    await expect(
      employeePage.getByRole('navigation').getByRole('link', { name: 'Schedule' }),
    ).toBeVisible();
    await expect(changeRequestsLink(employeePage)).toHaveCount(0);

    await employeePage.goto('/change-requests');
    await expect(employeePage).toHaveURL(/\/dashboard$/);
    await expect(employeePage.getByRole('heading', { name: 'Change Requests' })).toHaveCount(0);
  });
});
