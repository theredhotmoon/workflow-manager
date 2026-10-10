import { createDelegation, createProcedure, createUser } from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, loginAs, STATEFUL_ORIGIN, test } from '../fixtures/auth.js';

// L1-L4 "need-to-know": colleagues' emails, delegation applicants and procedure access logs are not
// shown to employees, and the employee's screens still render without them.
// Delegation dates in 2097-03 are reserved for this file.

async function apiGet(context, url) {
  const res = await context.request.get(url, {
    headers: { Accept: 'application/json', ...(await getXsrfHeader(context)) },
  });
  expect(res.ok(), `${url} -> ${res.status()}`).toBeTruthy();
  return res.json();
}

test.describe('Need-to-know privacy', () => {
  test('emails: an admin sees them on Users; an employee gets only their own', async ({
    adminPage,
    employeePage,
  }) => {
    await adminPage.goto('/users');
    await expect(adminPage.getByRole('heading', { name: 'User Management' })).toBeVisible();
    await expect(
      adminPage.locator('main').getByText('regular@shiftchanger.com').first(),
    ).toBeVisible();

    const context = employeePage.context();
    const me = await apiGet(context, '/api/user');
    expect(me.email).toBe('regular@shiftchanger.com');
    const employees = await apiGet(context, '/api/employees');
    expect(employees.length).toBeGreaterThan(1);
    for (const e of employees) {
      expect(e.name).toBeTruthy();
      if (e.id === me.id) expect(e.email).toBe(me.email);
      else expect(e).not.toHaveProperty('email');
    }
  });

  test("an employee's screens render without colleagues' private fields", async ({
    employeePage,
  }) => {
    await employeePage.goto('/dashboard');
    await expect(employeePage.locator('main').getByRole('heading').first()).toBeVisible();

    await employeePage.goto('/schedule');
    await expect(employeePage.getByRole('heading', { name: 'Shift Schedule' })).toBeVisible();

    await employeePage.goto('/delegations');
    await expect(employeePage.getByRole('heading', { name: 'Delegations' })).toBeVisible();

    await employeePage.goto('/procedures');
    await expect(
      employeePage.getByRole('heading', { name: 'Procedures', exact: true }),
    ).toBeVisible();
  });

  test('delegations: an employee sees confirmed participants, not other applicants', async ({
    adminPage,
    employeePage,
    browser,
  }) => {
    const admin = adminPage.context();
    const stamp = Date.now();
    const approved = await createUser(admin, {
      name: `NTK Approved ${stamp}`,
      email: `ntk-approved-${stamp}@example.test`,
    });
    const applicantEmail = `ntk-pending-${stamp}@example.test`;
    await createUser(admin, { name: `NTK Pending ${stamp}`, email: applicantEmail });
    const name = `NTK Trip ${stamp}`;
    const delegation = await createDelegation(admin, {
      name,
      destination: 'Kraków',
      start: '2097-03-10',
      end: '2097-03-11',
      userIds: [approved.id],
    });

    // A colleague applies (pending).
    const applicant = await browser.newContext({ extraHTTPHeaders: STATEFUL_ORIGIN });
    await loginAs(applicant, applicantEmail, 'password123');
    const reg = await applicant.request.post(`/api/delegations/${delegation.id}/self-register`, {
      headers: { Accept: 'application/json', ...(await getXsrfHeader(applicant)) },
    });
    expect(reg.ok()).toBeTruthy();
    await applicant.close();

    await employeePage.goto('/delegations');
    const card = employeePage.locator('div.rounded-2xl').filter({
      has: employeePage.getByRole('heading', { name }),
    });
    await expect(card).toBeVisible();
    await expect(card.getByText('1 confirmed')).toBeVisible();
    await expect(card.getByText(`NTK Approved ${stamp}`)).toBeVisible();
    await expect(card.getByText(`NTK Pending ${stamp}`)).toHaveCount(0);

    // The admin's management screen still lists the pending applicant.
    await adminPage.goto('/delegations-manage');
    const manageCard = adminPage.locator('div.rounded-2xl').filter({
      has: adminPage.getByRole('heading', { name }),
    });
    await manageCard.getByRole('heading', { name }).click(); // expands the card
    await expect(manageCard.getByText(`NTK Pending ${stamp}`)).toBeVisible();
  });

  test('procedure access log: offered to admins, not to an employee who does not own it', async ({
    adminPage,
    employeePage,
  }) => {
    const admin = adminPage.context();
    const adminUser = await apiGet(admin, '/api/user');
    const title = `NTK Access Log ${Date.now()}`;
    const procedure = await createProcedure(admin, { title, ownerId: adminUser.id });

    await employeePage.goto(`/procedures/${procedure.id}`);
    await expect(employeePage.getByRole('heading', { name: title })).toBeVisible();
    await expect(employeePage.getByRole('button', { name: 'FAQ' })).toBeVisible();
    await expect(employeePage.getByRole('button', { name: 'Access Log' })).toHaveCount(0);
    const denied = await employeePage
      .context()
      .request.get(`/api/procedures/${procedure.id}/access-log`, {
        headers: { Accept: 'application/json' },
      });
    expect(denied.status()).toBe(403);

    await adminPage.goto(`/procedures/${procedure.id}`);
    await expect(adminPage.getByRole('heading', { name: title })).toBeVisible();
    await expect(adminPage.getByRole('button', { name: 'Access Log' })).toBeVisible();
  });
});
