import {
  createChecklistSubmission,
  createChecklistTemplate,
  createProcedure,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getEmployeeIdByEmail,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('checklists/fill');
reserve.months('2097-06', '2096-01');
reserve.days('2097-05-05', '2097-05-06', '2097-05-12');

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
    employeePage,
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
    // Filed by the outgoing employee themselves (only an admin may file on someone's behalf, and
    // never naming themselves as incoming).
    const submissionA = await createChecklistSubmission(employeePage.context(), {
      template: a,
      date: '2097-05-05',
      outgoingUserId: regularId,
      incomingUserId: adminId,
    });
    await createChecklistSubmission(employeePage.context(), {
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

  test('pending confirmations list only handovers naming you as the incoming user', async ({
    adminPage,
    employeePage,
  }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const name = `Incoming Only ${Date.now()}`;
    const template = await createChecklistTemplate(context, { name, requiresConfirmation: true });
    await createChecklistSubmission(employeePage.context(), {
      template,
      date: '2097-05-06',
      outgoingUserId: regularId,
      incomingUserId: adminId,
    });

    // The outgoing employee files as themselves only, and doesn't get their own handover to confirm...
    await employeePage.goto('/checklist/fill');
    await expect(employeePage.getByLabel('Outgoing Employee')).toBeDisabled();
    await employeePage.getByRole('button', { name: 'View Pending Confirmations' }).click();
    await expect(
      employeePage.getByRole('heading', { level: 1, name: 'Confirm Handover' }),
    ).toBeVisible();
    await expect(employeePage.getByRole('heading', { level: 3, name })).toHaveCount(0);

    // ...the incoming admin does.
    await adminPage.goto('/checklist/fill');
    await adminPage.getByRole('button', { name: 'View Pending Confirmations' }).click();
    await expect(adminPage.getByRole('heading', { level: 3, name })).toBeVisible();
  });

  test('a slow read-status check for the previous template does not gate the next one', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const procA = await createProcedure(context, {
      title: 'Read Race Proc Alpha',
      ownerId: adminId,
    });
    const procB = await createProcedure(context, {
      title: 'Read Race Proc Beta',
      ownerId: adminId,
    });
    await createChecklistTemplate(context, { name: 'Read Race A', procedureIds: [procA.id] });
    await createChecklistTemplate(context, { name: 'Read Race B', procedureIds: [procB.id] });

    const slowA = await holdRequests(
      adminPage,
      (url) => url.pathname === '/api/procedures/check-read-status',
      { method: 'POST', times: 1 },
    );

    await adminPage.goto('/checklist/fill');
    await expect(adminPage.getByRole('heading', { name: 'Fill Shift Checklist' })).toBeVisible();
    await adminPage.waitForLoadState('networkidle');
    await adminPage.locator('input[type="date"]').fill('2097-05-12');
    await adminPage.locator('select').first().selectOption({ label: 'Read Race A' });
    await adminPage.locator('select').nth(2).selectOption({ index: 1 });
    const start = adminPage.getByRole('button', { name: 'Start Filling Checklist' });
    await start.click();
    await slowA.seen;

    await adminPage.getByRole('button', { name: '← Back' }).click();
    await adminPage.locator('select').first().selectOption({ label: 'Read Race B' });
    await start.click();
    await expect(adminPage.getByText('Read Race Proc Beta')).toBeVisible();

    await slowA.releaseAndSettle();

    await expect(adminPage.getByText('Read Race Proc Beta')).toBeVisible();
    await expect(adminPage.getByText('Read Race Proc Alpha')).toHaveCount(0);
  });
});
