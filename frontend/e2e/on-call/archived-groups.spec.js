// frontend/e2e/on-call/archived-groups.spec.js
import {
  archiveShiftGroup,
  createOnCallAssignment,
  createShiftGroupWithStreakRule,
  getEmployeeIdByEmail,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('on-call/archived-groups');
reserve.months('2025-03');

// F-e: an archived group's past on-call duties stay reachable for corrections, on request.
// March 2025 (in the past, so archiving keeps the duty) is reserved for this file.
const GROUP = reserve.name('On-Call Archived Team');

test.describe('On-call: archived groups', () => {
  test('a maintainer can list an archived group and log a callback on its past duty', async ({
    adminPage,
    maintainerPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: GROUP } });
    const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    await createOnCallAssignment(context, {
      shiftGroupId: groupId,
      userId: regularId,
      startsAt: '2025-03-10 08:00:00',
      endsAt: '2025-03-10 20:00:00',
    });
    await archiveShiftGroup(context, groupId);

    await maintainerPage.clock.setFixedTime(new Date('2025-03-15T12:00:00Z'));
    await maintainerPage.goto('/on-call');
    await expect(maintainerPage.getByText('March 2025', { exact: true })).toBeVisible();
    const groupSelect = maintainerPage.getByRole('combobox', { name: 'Shift group' });
    await expect(groupSelect.locator('option', { hasText: GROUP })).toHaveCount(0);

    await maintainerPage.getByLabel('Include archived groups').check();
    await groupSelect.selectOption({ label: `${GROUP} (archived)` });
    await expect(maintainerPage.getByTestId('archived-group-note')).toContainText(
      'Archived group.',
    );
    await expect(maintainerPage.locator('p', { hasText: 'Regular Employee' })).toBeVisible();
    // New duties can't be added to an archived group (the server refuses them too).
    await expect(maintainerPage.getByRole('button', { name: '+ Add On-Call Period' })).toHaveCount(
      0,
    );

    await maintainerPage.getByRole('button', { name: '+ Log Callback' }).click();
    await maintainerPage.getByLabel('Starts').fill('2025-03-10T09:00');
    await maintainerPage.getByLabel('Ends').fill('2025-03-10T10:30');
    await maintainerPage.getByRole('button', { name: 'Save' }).click();
    await expect(maintainerPage.getByText('Callback logged.')).toBeVisible();
    await expect(maintainerPage.getByText(/📞/)).toHaveCount(1);
  });
});
