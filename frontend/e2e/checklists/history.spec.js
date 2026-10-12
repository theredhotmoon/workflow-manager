import {
  createChecklistSubmission,
  createChecklistTemplate,
  createProcedure,
  getEmployeeIdByEmail,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('checklists/history');
reserve.months('2096-12');
reserve.days('2097-05-01', '2097-05-02', '2097-05-03', '2097-05-04');

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

  test('closing a slow procedure preview keeps it closed, and the next one shows its own', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const one = await createProcedure(context, { title: 'Hist Close One', ownerId: adminId });
    const two = await createProcedure(context, { title: 'Hist Close Two', ownerId: adminId });
    const template = await createChecklistTemplate(context, {
      name: 'Hist Race Close',
      procedureIds: [one.id, two.id],
    });
    await createChecklistSubmission(context, {
      template,
      date: '2097-05-04',
      outgoingUserId: adminId,
    });

    const slowOne = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/procedures/${one.id}`,
    );

    await adminPage.goto('/checklist/history');
    await adminPage.getByRole('cell', { name: 'Hist Race Close' }).click();
    await adminPage.getByTitle(/Hist Close One$/).click();
    await slowOne.seen;
    await adminPage.getByLabel('Close procedure preview').click();
    await expect(adminPage.getByLabel('Close procedure preview')).toHaveCount(0);

    await slowOne.releaseAndSettle();

    await expect(adminPage.getByLabel('Close procedure preview')).toHaveCount(0);
    await adminPage.getByTitle(/Hist Close Two$/).click();
    await expect(
      adminPage.getByRole('heading', { level: 2, name: 'Hist Close Two' }),
    ).toBeVisible();
    await expect(adminPage.getByRole('heading', { level: 2, name: 'Hist Close One' })).toHaveCount(
      0,
    );
  });

  // Dates in 2096-12 (older than every other archive row, so they never push another spec's rows
  // off the first page) are reserved for the two tests below.
  test('the archive is paginated on the server: page 2 shows the rest', async ({ adminPage }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const template = await createChecklistTemplate(context, { name: 'Hist Paging' });
    for (let day = 1; day <= 27; day++) {
      await createChecklistSubmission(context, {
        template,
        date: `2096-12-${String(day).padStart(2, '0')}`,
        outgoingUserId: adminId,
      });
    }

    await adminPage.goto('/checklist/history');
    await adminPage
      .locator('select')
      .filter({ has: adminPage.locator('option', { hasText: 'All templates' }) })
      .selectOption({ label: 'Hist Paging' });
    await adminPage.getByRole('button', { name: 'Apply' }).click();

    const count = adminPage.getByTestId('results-count');
    await expect(count).toHaveText('Showing 1–25 of 27 results');
    await expect(adminPage.getByRole('cell', { name: 'Hist Paging' })).toHaveCount(25);

    const page2 = adminPage.waitForRequest(
      (r) =>
        new URL(r.url()).pathname === '/api/checklist-submissions' &&
        new URL(r.url()).searchParams.get('page') === '2' &&
        new URL(r.url()).searchParams.get('template_id') === String(template.id),
    );
    await adminPage.getByRole('button', { name: 'Page 2' }).click();
    await page2;

    await expect(count).toHaveText('Showing 26–27 of 27 results');
    await expect(adminPage.getByRole('cell', { name: 'Hist Paging' })).toHaveCount(2);
    // Newest first: page 2 holds the two oldest days.
    await expect(adminPage.getByRole('cell', { name: /^02 Dec 2096/ })).toBeVisible();
    await expect(adminPage.getByRole('cell', { name: /^01 Dec 2096/ })).toBeVisible();
  });

  test('Export CSV downloads every handover matching the filters', async ({ adminPage }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const template = await createChecklistTemplate(context, { name: 'Hist Export, "CSV"' });
    for (const date of ['2096-12-28', '2096-12-29', '2096-12-30']) {
      await createChecklistSubmission(context, { template, date, outgoingUserId: adminId });
    }

    await adminPage.goto('/checklist/history');
    await adminPage
      .locator('select')
      .filter({ has: adminPage.locator('option', { hasText: 'All templates' }) })
      .selectOption({ label: 'Hist Export, "CSV"' });
    await adminPage.getByRole('button', { name: 'Apply' }).click();
    await expect(adminPage.getByTestId('results-count')).toHaveText('Showing 1–3 of 3 results');

    const downloadEvent = adminPage.waitForEvent('download');
    await adminPage.getByRole('button', { name: 'Export CSV' }).click();
    const download = await downloadEvent;
    expect(download.suggestedFilename()).toMatch(/^checklist-archive-\d{4}-\d{2}-\d{2}\.csv$/);

    const fs = await import('node:fs/promises');
    const lines = (await fs.readFile(await download.path(), 'utf8')).trimEnd().split('\n');
    // Starts with a UTF-8 BOM, so Excel reads Polish names right (trim() would drop it: trimEnd).
    expect(lines[0]).toBe(
      '﻿Date,Template,"Outgoing Employee","Incoming Employee",Status,Timing,"Submitted At"',
    );
    expect(lines).toHaveLength(4);
    expect(lines[1]).toMatch(/^2096-12-30,"Hist Export, ""CSV""",/);
    expect(lines[3]).toMatch(/^2096-12-28,/);
  });

  test("a refused export shows the server's reason", async ({ adminPage }) => {
    await adminPage.route('**/api/checklist-submissions/export**', (route) =>
      route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'The selected sort is invalid.', errors: {} }),
      }),
    );

    await adminPage.goto('/checklist/history');
    await adminPage.getByRole('button', { name: 'Export CSV' }).click();

    await expect(adminPage.getByText('The selected sort is invalid.')).toBeVisible();
  });
});
