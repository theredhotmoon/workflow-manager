import {
  createOnCallAssignment,
  createShiftGroupWithStreakRule,
  getEmployeeIdByEmail,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

const GROUP = 'On-Call Team';

// Months 2098-08/2098-09 are reserved for the stale-response tests below (each uses its own group).
const isAssignments = (groupId, month) => (url) =>
  url.pathname === '/api/on-call-assignments' &&
  url.searchParams.get('shift_group_id') === String(groupId) &&
  url.searchParams.get('month') === month;

async function openOnCallInAugust2098(page, groupName) {
  await page.clock.setFixedTime(new Date('2098-08-15T12:00:00Z'));
  await page.goto('/on-call');
  await expect(page.getByText('August 2098', { exact: true })).toBeVisible();
  await page.getByRole('combobox').first().selectOption({ label: groupName });
}

test.describe('On-Call Duty', () => {
  test('creates an on-call assignment, logs a callback, then removes both', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: GROUP } });

    // OnCallView derives its month from the browser's new Date(); pin it so July 2027 is the listed month.
    await adminPage.clock.setFixedTime(new Date('2027-07-15T12:00:00Z'));
    await adminPage.goto('/on-call');
    await expect(adminPage.getByText('July 2027', { exact: true })).toBeVisible();

    // The employee <option> also matches the name, so target the assignment row's name paragraph.
    const assignedEmployee = adminPage.locator('p', { hasText: 'Regular Employee' });
    const selectGroup = async () => {
      await adminPage.getByRole('combobox').first().selectOption({ label: GROUP });
    };
    await selectGroup();
    await expect(
      adminPage.getByText('No on-call periods for this group in July 2027.'),
    ).toBeVisible();

    await adminPage.getByRole('button', { name: '+ Add On-Call Period' }).click();
    await adminPage.getByLabel('Employee').selectOption({ label: 'Regular Employee' });
    await adminPage.getByLabel('Starts').fill('2027-07-01T08:00');
    await adminPage.getByLabel('Ends').fill('2027-07-01T20:00');
    await adminPage.getByRole('button', { name: 'Save' }).click();

    await expect(adminPage.getByText('On-call period added.')).toBeVisible();
    await expect(assignedEmployee).toBeVisible();

    await adminPage.getByRole('button', { name: '+ Log Callback' }).click();
    await adminPage.getByLabel('Starts').fill('2027-07-01T10:00');
    await adminPage.getByLabel('Ends').fill('2027-07-01T11:00');
    await adminPage.getByRole('button', { name: 'Save' }).click();

    await expect(adminPage.getByText('Callback logged.')).toBeVisible();

    // Persistence: after a reload the assignment and its callback are still listed.
    await adminPage.reload();
    await expect(adminPage.getByText('July 2027', { exact: true })).toBeVisible();
    await selectGroup();
    await expect(assignedEmployee).toBeVisible();
    await expect(adminPage.getByText('📞')).toBeVisible();

    // Scoped to the callback row (`div.items-center.justify-between.text-sm`, OnCallView.vue:52-58) — the
    // assignment's own remove button (title="Remove", icon-only) also computes an accessible name of
    // "Remove", so an unscoped getByRole('button', {name: 'Remove'}) would match both and fail strict mode.
    const callbackRow = adminPage.locator('div.items-center.justify-between.text-sm', {
      hasText: '📞',
    });
    await callbackRow.getByRole('button', { name: 'Remove', exact: true }).click();
    // removeCallback() is also behind confirm(); like removeAssignment() it uses the default "Confirm" label.
    await adminPage.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(adminPage.getByText('Callback removed.')).toBeVisible();

    await adminPage.getByTitle('Remove').click(); // removes the assignment
    await adminPage.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(adminPage.getByText('On-call period removed.')).toBeVisible();

    // Persistence: after a reload the month is empty again (positive render of the empty state).
    await adminPage.reload();
    await expect(adminPage.getByText('July 2027', { exact: true })).toBeVisible();
    await selectGroup();
    await expect(
      adminPage.getByText('No on-call periods for this group in July 2027.'),
    ).toBeVisible();
  });

  test('a slow response for the previous month does not replace the month the user moved to', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'On-Call Month Race' },
    });
    const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    await createOnCallAssignment(context, {
      shiftGroupId: groupId,
      userId: regularId,
      startsAt: '2098-08-05 08:00:00',
      endsAt: '2098-08-05 20:00:00',
    });

    const august = await holdRequests(adminPage, isAssignments(groupId, '2098-08'));
    await openOnCallInAugust2098(adminPage, 'On-Call Month Race');
    await august.seen;
    await adminPage.getByLabel('Next month').click();
    const septemberEmpty = adminPage.getByText(
      'No on-call periods for this group in September 2098.',
    );
    await expect(septemberEmpty).toBeVisible();

    await august.releaseAndSettle();

    await expect(septemberEmpty).toBeVisible();
    await expect(adminPage.locator('p', { hasText: 'Regular Employee' })).toHaveCount(0);
  });

  test('a slow response for the previous group does not replace the group the user picked', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupA = await createShiftGroupWithStreakRule(context, {
      group: { name: 'On-Call Group Race A' },
    });
    const groupB = await createShiftGroupWithStreakRule(context, {
      group: { name: 'On-Call Group Race B' },
    });
    const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    await createOnCallAssignment(context, {
      shiftGroupId: groupA,
      userId: regularId,
      startsAt: '2098-08-06 08:00:00',
      endsAt: '2098-08-06 20:00:00',
    });

    const groupALoad = await holdRequests(adminPage, isAssignments(groupA, '2098-08'));
    await openOnCallInAugust2098(adminPage, 'On-Call Group Race A');
    await groupALoad.seen;
    await adminPage.getByRole('combobox').first().selectOption({ label: 'On-Call Group Race B' });
    const groupBEmpty = adminPage.getByText('No on-call periods for this group in August 2098.');
    await expect(groupBEmpty).toBeVisible();

    await groupALoad.releaseAndSettle();

    await expect(groupBEmpty).toBeVisible();
    await expect(adminPage.locator('p', { hasText: 'Regular Employee' })).toHaveCount(0);
    await expect(adminPage.getByRole('combobox').first()).toHaveValue(String(groupB));
  });

  // Review Focus 2.
  test('the loading state stays until the newest month has loaded', async ({ adminPage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'On-Call Loading Race' },
    });
    const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    await createOnCallAssignment(context, {
      shiftGroupId: groupId,
      userId: regularId,
      startsAt: '2098-08-07 08:00:00',
      endsAt: '2098-08-07 20:00:00',
    });

    const august = await holdRequests(adminPage, isAssignments(groupId, '2098-08'));
    const september = await holdRequests(adminPage, isAssignments(groupId, '2098-09'));
    await openOnCallInAugust2098(adminPage, 'On-Call Loading Race');
    await august.seen;
    await adminPage.getByLabel('Next month').click();
    await september.seen;
    const loadingText = adminPage.getByText('Loading…');
    await expect(loadingText).toBeVisible();

    // Let only August (older) finish; September is still in flight.
    const augustDone = adminPage.waitForResponse((res) =>
      isAssignments(groupId, '2098-08')(new URL(res.url())),
    );
    august.release();
    await (await augustDone).finished();
    await adminPage.evaluate(() => new Promise((resolve) => setTimeout(resolve, 100)));
    await expect(loadingText).toBeVisible();

    await september.releaseAndSettle();
    await expect(
      adminPage.getByText('No on-call periods for this group in September 2098.'),
    ).toBeVisible();
  });
});
