import { createShiftGroupWithStreakRule } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

const GROUP = 'On-Call Team';

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
});
