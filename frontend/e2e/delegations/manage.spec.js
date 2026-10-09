import { createDelegation } from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, test } from '../fixtures/auth.js';

// Delegation dates in 2098-02 are reserved for this file.

async function selfRegister(context, delegationId) {
  const headers = { Accept: 'application/json', ...(await getXsrfHeader(context)) };
  const res = await context.request.post(`/api/delegations/${delegationId}/self-register`, {
    headers,
  });
  if (!res.ok()) throw new Error(`self-register failed: ${res.status()} ${await res.text()}`);
}

test.describe('Delegations: create, act, withdraw', () => {
  test('an admin can create a one-day delegation', async ({ adminPage }) => {
    await adminPage.goto('/delegations-manage');
    await adminPage.getByRole('button', { name: '+ New Delegation' }).click();

    await adminPage.getByLabel('Name').fill('Deleg One Day');
    await adminPage.getByLabel('Destination').fill('Gdańsk');
    await adminPage.getByLabel('Start Date').fill('2098-02-10');
    await adminPage.getByLabel('End Date').fill('2098-02-10');
    const created = adminPage.waitForResponse(
      (res) => res.url().endsWith('/api/delegations') && res.request().method() === 'POST',
    );
    await adminPage.getByRole('button', { name: 'Create', exact: true }).click();

    expect((await created).status()).toBe(201);
    const card = adminPage.locator('div.rounded-2xl').filter({
      has: adminPage.getByRole('heading', { name: 'Deleg One Day' }),
    });
    await expect(card).toBeVisible();
    await expect(card.getByText('Feb 10, 2098 → Feb 10, 2098')).toBeVisible();
  });

  test('a failed approve shows why instead of failing silently', async ({
    adminPage,
    employeePage,
  }) => {
    const delegation = await createDelegation(adminPage.context(), {
      name: 'Deleg Approve Fails',
      destination: 'Poznań',
      start: '2098-02-12',
      end: '2098-02-13',
    });
    await selfRegister(employeePage.context(), delegation.id);

    await adminPage.route(`**/api/delegations/${delegation.id}/approve-user`, (route) =>
      route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Server Error' }),
      }),
    );

    await adminPage.goto('/delegations-manage');
    await adminPage.getByRole('heading', { name: 'Deleg Approve Fails' }).click();
    await adminPage.getByRole('button', { name: 'Approve' }).click();

    await expect(adminPage.getByText(/Could not approve .+ \(500\)\./)).toBeVisible();
    // Still pending: nothing pretended to succeed.
    await expect(adminPage.getByRole('button', { name: 'Approve' })).toBeVisible();
  });

  test('withdrawing a request asks first', async ({ adminPage, employeePage }) => {
    const delegation = await createDelegation(adminPage.context(), {
      name: 'Deleg Withdraw',
      destination: 'Wrocław',
      start: '2098-02-15',
      end: '2098-02-16',
    });
    await selfRegister(employeePage.context(), delegation.id);

    await employeePage.goto('/delegations');
    const card = employeePage.locator('div.rounded-2xl').filter({
      has: employeePage.getByRole('heading', { name: 'Deleg Withdraw' }),
    });
    await expect(card.getByText('Awaiting approval')).toBeVisible();

    // Cancel keeps the request.
    await card.getByRole('button', { name: 'Withdraw request' }).click();
    const dialog = employeePage.getByRole('dialog');
    await expect(dialog).toContainText('Withdraw your request to join "Deleg Withdraw"?');
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toHaveCount(0);
    await expect(card.getByText('Awaiting approval')).toBeVisible();

    // Confirm withdraws it.
    await card.getByRole('button', { name: 'Withdraw request' }).click();
    await employeePage.getByRole('dialog').getByRole('button', { name: 'Withdraw' }).click();
    await expect(card.getByRole('button', { name: /I want to take part/ })).toBeVisible();
  });
});
