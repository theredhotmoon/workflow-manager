import { createDelegation, getEmployeeIdByEmail } from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('delegations/manage');
reserve.months('2096-02');

// Delegation dates in 2096-02 are reserved for this file (it used 2098-02, which stale-overlays
// also writes delegations into).

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
    await adminPage.getByLabel('Start Date').fill('2096-02-10');
    await adminPage.getByLabel('End Date').fill('2096-02-10');
    const created = adminPage.waitForResponse(
      (res) => res.url().endsWith('/api/delegations') && res.request().method() === 'POST',
    );
    await adminPage.getByRole('button', { name: 'Create', exact: true }).click();

    expect((await created).status()).toBe(201);
    const card = adminPage.locator('div.rounded-2xl').filter({
      has: adminPage.getByRole('heading', { name: 'Deleg One Day' }),
    });
    await expect(card).toBeVisible();
    await expect(card.getByText('Feb 10, 2096 → Feb 10, 2096')).toBeVisible();
  });

  test('a failed approve shows why instead of failing silently', async ({
    adminPage,
    employeePage,
  }) => {
    const delegation = await createDelegation(adminPage.context(), {
      name: 'Deleg Approve Fails',
      destination: 'Poznań',
      start: '2096-02-12',
      end: '2096-02-13',
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
      start: '2096-02-15',
      end: '2096-02-16',
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

  test("a field's server error clears once that field is changed", async ({ adminPage }) => {
    await adminPage.route('**/api/delegations', (route) =>
      route.request().method() === 'POST'
        ? route.fulfill({
            status: 422,
            contentType: 'application/json',
            body: JSON.stringify({
              message: 'The end date is not allowed.',
              errors: { end_datetime: ['The end date is not allowed.'] },
            }),
          })
        : route.continue(),
    );

    await adminPage.goto('/delegations-manage');
    await adminPage.getByRole('button', { name: '+ New Delegation' }).click();
    await adminPage.getByLabel('Name').fill('Deleg Server Error');
    await adminPage.getByLabel('Destination').fill('Łódź');
    await adminPage.getByLabel('Start Date').fill('2096-02-20');
    await adminPage.getByLabel('End Date').fill('2096-02-21');
    await adminPage.getByRole('button', { name: 'Create', exact: true }).click();

    const fieldError = adminPage.locator('form').getByText('The end date is not allowed.');
    await expect(fieldError).toBeVisible();
    // Another field changing leaves it; changing the end date clears it.
    await adminPage.getByLabel('Name').fill('Deleg Server Error 2');
    await expect(fieldError).toBeVisible();
    await adminPage.getByLabel('End Date').fill('2096-02-22');
    await expect(fieldError).toHaveCount(0);
  });

  test('Users: what was typed in Create & Assign survives a look at Assign Existing', async ({
    adminPage,
  }) => {
    await adminPage.goto('/users');
    const row = adminPage.getByRole('row', { name: /Regular Employee/ });
    await row.getByTitle('Delegation').click();

    await adminPage.getByLabel('Name').fill('Deleg Draft Kept');
    await adminPage.getByLabel('Start Date').fill('2096-02-25');
    await adminPage.getByRole('button', { name: 'Assign Existing' }).click();
    await adminPage.getByRole('button', { name: 'Create New' }).click();

    await expect(adminPage.getByLabel('Name')).toHaveValue('Deleg Draft Kept');
    await expect(adminPage.getByLabel('Start Date')).toHaveValue('2096-02-25');
  });
});

test.describe('Delegations: delete only while nobody has joined', () => {
  test('a delegation with a member cannot be deleted: the server says why, and Delete is disabled', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const delegation = await createDelegation(context, {
      name: 'Deleg Has Member',
      destination: 'Lublin',
      start: '2096-02-27',
      end: '2096-02-28',
    });

    await adminPage.goto('/delegations-manage');
    const card = adminPage.locator('div.rounded-2xl').filter({
      has: adminPage.getByRole('heading', { name: 'Deleg Has Member' }),
    });
    const deleteButton = card.getByRole('button', { name: 'Delete', exact: true });
    await expect(deleteButton).toBeEnabled();

    // Someone joins while the page is open (the list on screen still shows no members).
    const memberId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const headers = { Accept: 'application/json', ...(await getXsrfHeader(context)) };
    const added = await context.request.post(`/api/delegations/${delegation.id}/add-user`, {
      data: { user_id: memberId },
      headers,
    });
    expect(added.ok()).toBe(true);

    await deleteButton.click();
    await adminPage
      .getByRole('dialog')
      .getByRole('button', { name: 'Delete', exact: true })
      .click();
    await expect(
      adminPage.getByText(
        /This delegation has members \(.+\), so it can't be deleted\. Archive it instead\./,
      ),
    ).toBeVisible();
    await expect(card).toBeVisible();

    // Reloaded, the list knows about the member: Delete is disabled and says to archive instead.
    await adminPage.reload();
    const disabled = card.getByRole('button', {
      name: 'Can’t delete: this delegation has members. Archive it instead.',
    });
    await expect(disabled).toBeDisabled();
  });
});
