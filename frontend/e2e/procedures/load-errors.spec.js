// frontend/e2e/procedures/load-errors.spec.js
import { createProcedure, getEmployeeIdByEmail } from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, test } from '../fixtures/auth.js';

// L29: a procedure that can't be loaded says so instead of bouncing to the list; a failed list
// load drops the previous search's results and offers a retry.

const tile = (page, title) => page.getByRole('heading', { level: 3, name: title, exact: true });

async function adminProcedure(page, title) {
  const context = page.context();
  const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
  return createProcedure(context, { title, ownerId: adminId });
}

const isProcedureGet = (id) => (url) => url.pathname === `/api/procedures/${id}`;

test.describe('Procedure load errors', () => {
  test('a missing procedure shows "not found" with a way back to the list', async ({
    employeePage,
  }) => {
    await employeePage.goto('/procedures/99999999');
    const error = employeePage.locator('main').getByRole('alert');
    await expect(error).toContainText('Procedure not found');
    await expect(employeePage).toHaveURL(/\/procedures\/99999999$/);
    await expect(error.getByRole('button', { name: 'Retry' })).toHaveCount(0);
    await error.getByRole('link', { name: 'Back to procedures' }).click();
    await expect(employeePage).toHaveURL(/\/procedures$/);
  });

  test('an archived procedure is "not found" for an employee', async ({
    adminPage,
    employeePage,
  }) => {
    const procedure = await adminProcedure(adminPage, 'Load Error Archived');
    const context = adminPage.context();
    const headers = { Accept: 'application/json', ...(await getXsrfHeader(context)) };
    const res = await context.request.delete(`/api/procedures/${procedure.id}`, { headers });
    expect(res.ok()).toBe(true);

    await employeePage.goto(`/procedures/${procedure.id}`);
    await expect(employeePage.locator('main').getByRole('alert')).toContainText(
      'Procedure not found',
    );
  });

  test('a failed load can be retried', async ({ adminPage }) => {
    const procedure = await adminProcedure(adminPage, 'Load Error Retry');
    let failures = 0;
    await adminPage.route(
      (url) => isProcedureGet(procedure.id)(url),
      async (route) => {
        if (failures === 0) {
          failures += 1;
          await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
        } else {
          await route.fallback();
        }
      },
    );

    await adminPage.goto(`/procedures/${procedure.id}`);
    const error = adminPage.locator('main').getByRole('alert');
    await expect(error).toContainText('Could not load the procedure');
    await expect(adminPage).toHaveURL(new RegExp(`/procedures/${procedure.id}$`));

    await error.getByRole('button', { name: 'Retry' }).click();
    await expect(
      adminPage.getByRole('heading', { level: 1, name: 'Load Error Retry' }),
    ).toBeVisible();
    await expect(error).toHaveCount(0);
  });
});

test.describe('Procedure list load errors', () => {
  test('a failed search clears the earlier results and offers a retry', async ({ adminPage }) => {
    await adminProcedure(adminPage, 'ListErr Alpha');
    await adminProcedure(adminPage, 'ListErr Beta');

    await adminPage.goto('/procedures');
    const search = adminPage.getByPlaceholder('Search procedures...');
    await search.fill('ListErr');
    await expect(tile(adminPage, 'ListErr Alpha')).toBeVisible();
    await expect(tile(adminPage, 'ListErr Beta')).toBeVisible();

    // The next search fails.
    let fail = true;
    await adminPage.route(
      (url) =>
        url.pathname === '/api/procedures' && url.searchParams.get('search') === 'ListErr Beta',
      async (route) => {
        if (fail) await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
        else await route.fallback();
      },
    );
    await search.fill('ListErr Beta');

    const error = adminPage.locator('main').getByRole('alert');
    await expect(error).toContainText('Could not load procedures');
    // The results of the previous search ('ListErr') are gone, not passed off as this one's.
    await expect(tile(adminPage, 'ListErr Alpha')).toHaveCount(0);
    await expect(tile(adminPage, 'ListErr Beta')).toHaveCount(0);

    fail = false;
    await error.getByRole('button', { name: 'Retry' }).click();
    await expect(tile(adminPage, 'ListErr Beta')).toBeVisible();
    await expect(tile(adminPage, 'ListErr Alpha')).toHaveCount(0);
    await expect(error).toHaveCount(0);
  });
});
