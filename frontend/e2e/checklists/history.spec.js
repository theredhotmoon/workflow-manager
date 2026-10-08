import {
  createChecklistSubmission,
  createChecklistTemplate,
  createProcedure,
  getEmployeeIdByEmail,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

// Submission dates in 2097-05 are reserved for the checklist specs.
test.describe('Checklist archive', () => {
  test('a slow unfiltered load does not replace the filtered archive', async ({ adminPage }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const done = await createChecklistTemplate(context, { name: 'Hist Race Done' });
    const pending = await createChecklistTemplate(context, {
      name: 'Hist Race Pending',
      requiresConfirmation: true,
    });
    await createChecklistSubmission(context, {
      template: done,
      date: '2097-05-01',
      outgoingUserId: adminId,
    });
    await createChecklistSubmission(context, {
      template: pending,
      date: '2097-05-01',
      outgoingUserId: adminId,
      incomingUserId: regularId,
    });

    const unfiltered = await holdRequests(
      adminPage,
      (url) => url.pathname === '/api/checklist-submissions' && !url.searchParams.has('status'),
      { times: 1 },
    );

    await adminPage.goto('/checklist/history');
    await unfiltered.seen;
    await adminPage
      .locator('select')
      .filter({ has: adminPage.locator('option[value="pending_confirmation"]') })
      .selectOption('pending_confirmation');
    await adminPage.getByRole('button', { name: 'Apply' }).click();
    const pendingRow = adminPage.getByRole('cell', { name: 'Hist Race Pending' });
    await expect(pendingRow).toBeVisible();

    await unfiltered.releaseAndSettle();

    await expect(pendingRow).toBeVisible();
    await expect(adminPage.getByRole('cell', { name: 'Hist Race Done' })).toHaveCount(0);
  });

  test('clicking a second submission before the first opens shows the second', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const a = await createChecklistTemplate(context, { name: 'Hist Detail A' });
    const b = await createChecklistTemplate(context, { name: 'Hist Detail B' });
    const submissionA = await createChecklistSubmission(context, {
      template: a,
      date: '2097-05-02',
      outgoingUserId: adminId,
    });
    await createChecklistSubmission(context, {
      template: b,
      date: '2097-05-02',
      outgoingUserId: adminId,
    });

    const slowA = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/checklist-submissions/${submissionA.id}`,
    );

    await adminPage.goto('/checklist/history');
    await expect(adminPage.getByRole('cell', { name: 'Hist Detail A' })).toBeVisible();
    await adminPage.getByRole('cell', { name: 'Hist Detail A' }).click();
    await slowA.seen;
    await adminPage.getByRole('cell', { name: 'Hist Detail B' }).click();
    const detailB = adminPage.getByRole('heading', { level: 2, name: 'Hist Detail B' });
    await expect(detailB).toBeVisible();

    await slowA.releaseAndSettle();

    await expect(detailB).toBeVisible();
    await expect(adminPage.getByRole('heading', { level: 2, name: 'Hist Detail A' })).toHaveCount(
      0,
    );
  });

  test('closing a slow procedure preview and opening another keeps the second', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const one = await createProcedure(context, { title: 'Hist Proc One', ownerId: adminId });
    const two = await createProcedure(context, { title: 'Hist Proc Two', ownerId: adminId });
    const template = await createChecklistTemplate(context, {
      name: 'Hist Race Procs',
      procedureIds: [one.id, two.id],
    });
    await createChecklistSubmission(context, {
      template,
      date: '2097-05-03',
      outgoingUserId: adminId,
    });

    const slowOne = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/procedures/${one.id}`,
    );

    await adminPage.goto('/checklist/history');
    await adminPage.getByRole('cell', { name: 'Hist Race Procs' }).click();
    await expect(
      adminPage.getByRole('heading', { level: 2, name: 'Hist Race Procs' }),
    ).toBeVisible();
    await adminPage.getByTitle(/Hist Proc One$/).click();
    await slowOne.seen;
    await adminPage.getByLabel('Close procedure preview').click();
    await adminPage.getByTitle(/Hist Proc Two$/).click();
    const previewTwo = adminPage.getByRole('heading', { level: 2, name: 'Hist Proc Two' });
    await expect(previewTwo).toBeVisible();

    await slowOne.releaseAndSettle();

    await expect(previewTwo).toBeVisible();
    await expect(adminPage.getByRole('heading', { level: 2, name: 'Hist Proc One' })).toHaveCount(
      0,
    );
  });
});
