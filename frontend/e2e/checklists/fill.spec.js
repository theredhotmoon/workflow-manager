import {
  createChecklistSubmission,
  createChecklistTemplate,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

// Dates in 2097-05/2097-06 are reserved for the checklist specs: 2097-05 has no shifts,
// 2097-06 is generated for 'Fill Race Team' only (its test counts the 'Shift 1' options).
// 2096-01 is generated for 'Fill Date Team'.
test.describe('Fill shift checklist', () => {
  test('a slow shift list for the previous date does not replace the chosen date’s shifts', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Fill Race Team' },
    });
    await generateMonthShifts(context, { monthKey: '2097-06', shiftGroupId: groupId });

    const mayDay = await holdRequests(
      adminPage,
      (url) =>
        url.pathname === '/api/shifts-by-date' && url.searchParams.get('date') === '2097-05-12',
    );

    await adminPage.goto('/checklist/fill');
    await expect(adminPage.getByRole('heading', { name: 'Fill Shift Checklist' })).toBeVisible();
    await adminPage.waitForLoadState('networkidle');
    const date = adminPage.locator('input[type="date"]');
    await date.fill('2097-05-12');
    await mayDay.seen;
    await date.fill('2097-06-12');
    const shiftOption = adminPage.locator('option', { hasText: 'Shift 1' });
    await expect(shiftOption).toHaveCount(1);

    await mayDay.releaseAndSettle();

    await expect(shiftOption).toHaveCount(1);
    await expect(date).toHaveValue('2097-06-12');
  });

  test('changing the date drops the previously selected shift', async ({ adminPage }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Fill Date Team' },
    });
    await generateMonthShifts(context, { monthKey: '2096-01', shiftGroupId: groupId });

    await adminPage.goto('/checklist/fill');
    await expect(adminPage.getByRole('heading', { name: 'Fill Shift Checklist' })).toBeVisible();
    await adminPage.waitForLoadState('networkidle');
    const date = adminPage.locator('input[type="date"]');
    await date.fill('2096-01-12');
    const shiftSelect = adminPage.locator('select', { hasText: 'No shift selected' });
    await expect(shiftSelect.locator('option')).not.toHaveCount(1);
    await shiftSelect.selectOption({ index: 1 });
    await expect(shiftSelect.locator('option:checked')).toContainText('Shift 1');

    await date.fill('2096-01-13');
    await expect(shiftSelect.locator('option')).not.toHaveCount(1);
    await expect(shiftSelect.locator('option:checked')).toHaveText('No shift selected');
  });

  test('opening one pending confirmation and then another shows the second', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const a = await createChecklistTemplate(context, {
      name: 'Confirm Race A',
      requiresConfirmation: true,
    });
    const b = await createChecklistTemplate(context, {
      name: 'Confirm Race B',
      requiresConfirmation: true,
    });
    const submissionA = await createChecklistSubmission(context, {
      template: a,
      date: '2097-05-05',
      outgoingUserId: regularId,
      incomingUserId: adminId,
    });
    await createChecklistSubmission(context, {
      template: b,
      date: '2097-05-05',
      outgoingUserId: regularId,
      incomingUserId: adminId,
    });

    const slowA = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/checklist-submissions/${submissionA.id}`,
    );

    await adminPage.goto('/checklist/fill');
    await adminPage.getByRole('button', { name: 'View Pending Confirmations' }).click();
    await adminPage.getByRole('heading', { level: 3, name: 'Confirm Race A' }).click();
    await slowA.seen;
    await adminPage.getByRole('heading', { level: 3, name: 'Confirm Race B' }).click();
    const detailB = adminPage.getByRole('heading', { level: 1, name: 'Confirm Race B' });
    await expect(detailB).toBeVisible();

    await slowA.releaseAndSettle();

    await expect(detailB).toBeVisible();
    await expect(adminPage.getByRole('heading', { level: 1, name: 'Confirm Race A' })).toHaveCount(
      0,
    );
  });
});
