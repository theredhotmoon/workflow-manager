// frontend/e2e/procedures/archive.spec.js
import { createProcedure, getEmployeeIdByEmail } from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, test } from '../fixtures/auth.js';

async function adminProcedure(page, title) {
  const context = page.context();
  const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
  return createProcedure(context, { title, ownerId: adminId });
}

async function archiveViaApi(context, procedureId) {
  const headers = { Accept: 'application/json', ...(await getXsrfHeader(context)) };
  const res = await context.request.delete(`/api/procedures/${procedureId}`, { headers });
  if (!res.ok()) throw new Error(`archive procedure failed: ${res.status()} ${await res.text()}`);
}

async function treeTitles(context) {
  const res = await context.request.get('/api/procedures-tree', {
    headers: { Accept: 'application/json' },
  });
  const titles = [];
  const walk = (nodes) => {
    for (const node of nodes) {
      titles.push(node.title);
      walk(node.children);
    }
  };
  walk(await res.json());
  return titles;
}

test.describe('Procedure archive and restore', () => {
  test('archiving from the editor hides it from the list and tree; the Archived list restores it', async ({
    adminPage,
  }) => {
    const title = `Archive Flow ${Date.now()}`;
    const procedure = await adminProcedure(adminPage, title);

    await adminPage.goto(`/procedures/${procedure.id}`);
    await adminPage.getByRole('button', { name: 'Edit', exact: true }).click();
    const editor = adminPage.getByRole('dialog', { name: 'Edit Procedure' });
    await expect(editor.getByPlaceholder('e.g. Password Reset via Database')).toHaveValue(title);
    await editor.getByRole('button', { name: 'Archive Procedure' }).click();
    const confirmDialog = adminPage.getByRole('dialog', { name: 'Archive Procedure' });
    await expect(confirmDialog).toContainText('Kept: its versions, FAQ, feedback');
    await confirmDialog.getByRole('button', { name: 'Archive', exact: true }).click();

    await expect(adminPage).toHaveURL(/\/procedures$/);
    await expect(adminPage.getByText('Procedure archived')).toBeVisible();
    const searched = adminPage.waitForResponse((res) => {
      const url = new URL(res.url());
      return url.pathname === '/api/procedures' && url.searchParams.get('search') === title;
    });
    await adminPage.getByPlaceholder('Search procedures...').fill(title);
    expect((await searched).ok()).toBe(true);
    await expect(adminPage.locator('main').getByText('No procedures found.')).toBeVisible();
    await expect(adminPage.locator('main').getByText(title, { exact: true })).toHaveCount(0);
    expect(await treeTitles(adminPage.context())).not.toContain(title);

    await adminPage.getByRole('button', { name: 'Archived', exact: true }).click();
    const archivedList = adminPage.getByRole('dialog', { name: 'Archived procedures' });
    await expect(archivedList.getByText(title)).toBeVisible();
    await archivedList.getByRole('button', { name: `Restore ${procedure.identifier}` }).click();
    await expect(adminPage.getByText('Procedure restored.')).toBeVisible();
    await expect(archivedList.getByText(title)).toHaveCount(0);
    await archivedList.getByRole('button', { name: 'Close' }).click();

    await expect(adminPage.locator('main').getByText(title, { exact: true }).first()).toBeVisible();
    expect(await treeTitles(adminPage.context())).toContain(title);
  });

  test('an archived procedure opens for an admin with a banner, and not for an employee', async ({
    adminPage,
    employeePage,
  }) => {
    const title = `Archive Banner ${Date.now()}`;
    const procedure = await adminProcedure(adminPage, title);
    await archiveViaApi(adminPage.context(), procedure.id);

    // An employee's direct link answers 404: the page says "not found" (L29), with a link back.
    const denied = employeePage.waitForResponse(
      (res) => new URL(res.url()).pathname === `/api/procedures/${procedure.id}`,
    );
    await employeePage.goto(`/procedures/${procedure.id}`);
    expect((await denied).status()).toBe(404);
    const notFound = employeePage.locator('main').getByRole('alert');
    await expect(notFound).toContainText('Procedure not found');
    await expect(notFound.getByRole('link', { name: 'Back to procedures' })).toBeVisible();
    await expect(employeePage.getByRole('heading', { name: title })).toHaveCount(0);

    await adminPage.goto(`/procedures/${procedure.id}`);
    const banner = adminPage.locator('main').getByRole('status').filter({ hasText: 'Archived.' });
    await expect(banner).toBeVisible();
    await expect(adminPage.getByRole('heading', { name: title })).toBeVisible();
    await expect(adminPage.getByRole('button', { name: 'Edit', exact: true })).toHaveCount(0);

    await banner.getByRole('button', { name: 'Restore' }).click();
    await expect(adminPage.getByText('Procedure restored.')).toBeVisible();
    await expect(banner).toHaveCount(0);
    await expect(adminPage.getByRole('button', { name: 'Edit', exact: true })).toBeVisible();
  });
});
