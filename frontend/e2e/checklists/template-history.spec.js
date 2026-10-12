import {
  createChecklistSubmission,
  createChecklistTemplate,
  getEmployeeIdByEmail,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('checklists/template-history');
reserve.days('2097-05-10', '2097-05-11');

// Submission dates in 2097-05 are reserved for the checklist specs (10–11 for this file).
test.describe('Checklist templates keep their handover history', () => {
  const card = (page, name) =>
    page
      .locator('div.rounded-2xl')
      .filter({ has: page.getByRole('heading', { level: 3, name, exact: true }) });

  test('removing an item in the builder keeps it in past handovers', async ({ adminPage }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const template = await createChecklistTemplate(context, {
      name: 'Hist Keep Edit',
      itemLabels: ['Hist Keep Stays', 'Hist Keep Removed'],
    });
    await createChecklistSubmission(context, {
      template,
      date: '2097-05-10',
      outgoingUserId: adminId,
    });

    await adminPage.goto('/checklists');
    await card(adminPage, 'Hist Keep Edit').getByRole('button', { name: 'Edit' }).click();
    const removedRow = adminPage.locator('input[placeholder="Item 2..."]').locator('xpath=..');
    await expect(adminPage.locator('input[placeholder="Item 2..."]')).toHaveValue(
      'Hist Keep Removed',
    );
    await removedRow.locator('button').last().click();
    await expect(adminPage.locator('input[placeholder="Item 2..."]')).toHaveCount(0);
    const saved = adminPage.waitForResponse(
      (res) =>
        res.url().endsWith(`/api/checklist-templates/${template.id}`) &&
        res.request().method() === 'PUT',
    );
    await adminPage.getByRole('button', { name: 'Save Changes' }).click();
    expect((await saved).ok()).toBe(true);

    // The template as filled from now on no longer has the item…
    const current = await (
      await context.request.get(`/api/checklist-templates/${template.id}`)
    ).json();
    expect(current.groups[0].items.map((i) => i.label)).toEqual(['Hist Keep Stays']);

    // …but the past handover still shows it, with its answer.
    await adminPage.goto('/checklist/history');
    await adminPage.getByRole('cell', { name: 'Hist Keep Edit' }).click();
    await expect(
      adminPage.getByRole('heading', { level: 2, name: 'Hist Keep Edit' }),
    ).toBeVisible();
    await expect(adminPage.getByText('Hist Keep Removed')).toBeVisible();
    await expect(adminPage.getByText('Hist Keep Stays')).toBeVisible();
  });

  test('archiving a template removes it from the builder and keeps its handovers', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    const template = await createChecklistTemplate(context, { name: 'Hist Keep Archive' });
    await createChecklistSubmission(context, {
      template,
      date: '2097-05-11',
      outgoingUserId: adminId,
    });

    await adminPage.goto('/checklists');
    await card(adminPage, 'Hist Keep Archive').getByRole('button', { name: 'Archive' }).click();
    const dialog = adminPage.getByRole('dialog', { name: 'Archive Template' });
    await expect(dialog).toContainText('Handovers already submitted with it are kept');
    await dialog.getByRole('button', { name: 'Archive' }).click();
    await expect(adminPage.getByText('Template archived.')).toBeVisible();
    await expect(
      adminPage.getByRole('heading', { level: 3, name: 'Hist Keep Archive', exact: true }),
    ).toHaveCount(0);

    await adminPage.goto('/checklist/history');
    await adminPage.getByRole('cell', { name: 'Hist Keep Archive' }).click();
    await expect(
      adminPage.getByRole('heading', { level: 2, name: 'Hist Keep Archive' }),
    ).toBeVisible();
    await expect(adminPage.getByText('Hist Keep Archive item')).toBeVisible();
  });
});
