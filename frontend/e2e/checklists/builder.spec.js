import {
  createChecklistTemplate,
  createProcedure,
  getEmployeeIdByEmail,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

test.describe('Checklist templates', () => {
  test('opening one template and then another edits and saves the second one', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const a = await createChecklistTemplate(context, { name: 'Builder Race A' });
    const b = await createChecklistTemplate(context, { name: 'Builder Race B' });

    const slowA = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/checklist-templates/${a.id}`,
    );

    await adminPage.goto('/checklists');
    const card = (name) =>
      adminPage
        .locator('div.rounded-2xl')
        .filter({ has: adminPage.getByRole('heading', { level: 3, name, exact: true }) });
    await card('Builder Race A').getByRole('button', { name: 'Edit' }).click();
    await slowA.seen;
    await card('Builder Race B').getByRole('button', { name: 'Edit' }).click();
    const nameInput = adminPage.getByPlaceholder('e.g. Daily Shift Handover');
    await expect(nameInput).toHaveValue('Builder Race B');

    await slowA.releaseAndSettle();

    await expect(nameInput).toHaveValue('Builder Race B');

    // Saving must write B's own content to B and leave A untouched (verified via the API).
    const saved = adminPage.waitForResponse(
      (res) =>
        res.url().endsWith(`/api/checklist-templates/${b.id}`) && res.request().method() === 'PUT',
    );
    await adminPage.getByRole('button', { name: 'Save Changes' }).click();
    expect((await saved).ok()).toBe(true);
    const savedB = await (await context.request.get(`/api/checklist-templates/${b.id}`)).json();
    expect(savedB.name).toBe('Builder Race B');
    expect(savedB.groups[0].items[0].label).toBe('Builder Race B item');
    const savedA = await (await context.request.get(`/api/checklist-templates/${a.id}`)).json();
    expect(savedA.name).toBe('Builder Race A');
  });

  test('starting a new template while an edit is loading is not overwritten by the late edit', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const a = await createChecklistTemplate(context, { name: 'Builder Create Race A' });

    const slowA = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/checklist-templates/${a.id}`,
    );

    await adminPage.goto('/checklists');
    const card = adminPage.locator('div.rounded-2xl').filter({
      has: adminPage.getByRole('heading', { level: 3, name: 'Builder Create Race A', exact: true }),
    });
    await card.getByRole('button', { name: 'Edit' }).click();
    await slowA.seen;
    await adminPage.getByRole('button', { name: 'New Template' }).click();
    const nameInput = adminPage.getByPlaceholder('e.g. Daily Shift Handover');
    await expect(nameInput).toHaveValue('');

    await slowA.releaseAndSettle();

    await expect(nameInput).toHaveValue('');
    await expect(adminPage.getByRole('heading', { name: 'Create Template' })).toBeVisible();
  });

  test('offers procedures beyond the first 100 for linking', async ({ adminPage }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    // The API returns at most 100 per page. With more than 100 procedures, whatever it puts on
    // page 2 is exactly what a single-page load would never offer — link the template to that one.
    for (let i = 1; i <= 101; i++) {
      await createProcedure(context, {
        title: `Builder Cap ${String(i).padStart(3, '0')}`,
        ownerId: adminId,
      });
    }
    const page2 = await (
      await context.request.get('/api/procedures', { params: { per_page: 100, page: 2 } })
    ).json();
    const beyond = page2.data[0];
    expect(beyond).toBeTruthy();
    await createChecklistTemplate(context, {
      name: 'Builder Cap Template',
      procedureIds: [beyond.id],
    });

    await adminPage.goto('/checklists');
    await adminPage
      .locator('div.rounded-2xl')
      .filter({
        has: adminPage.getByRole('heading', {
          level: 3,
          name: 'Builder Cap Template',
          exact: true,
        }),
      })
      .getByRole('button', { name: 'Edit' })
      .click();
    await expect(adminPage.getByPlaceholder('e.g. Daily Shift Handover')).toHaveValue(
      'Builder Cap Template',
    );

    const linked = adminPage.locator('select[title="Link a procedure"]').first();
    await expect(linked.locator('option:checked')).toContainText(beyond.title);
  });
});
