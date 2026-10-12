import {
  askFaqQuestion,
  createProcedure,
  getEmployeeIdByEmail,
  reportProcedureIssue,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

test.describe('Notifications', () => {
  test('a slow unfiltered load does not replace the filtered list', async ({
    adminPage,
    employeePage,
  }) => {
    const adminContext = adminPage.context();
    const adminId = await getEmployeeIdByEmail(adminContext, 'admin@shiftchanger.com');
    const procedure = await createProcedure(adminContext, {
      title: 'Alarm Race Proc',
      ownerId: adminId,
    });
    // The employee's question and issue report each raise an alarm for the owner (admin).
    await askFaqQuestion(employeePage.context(), procedure.id, 'How does the alarm race work?');
    await reportProcedureIssue(
      employeePage.context(),
      procedure.id,
      'The alarm race step is wrong.',
    );

    const unfiltered = await holdRequests(
      adminPage,
      (url) => url.pathname === '/api/alarms' && !url.searchParams.has('type'),
      { times: 1 },
    );

    await adminPage.goto('/alarms');
    await unfiltered.seen;
    await adminPage
      .locator('select')
      .filter({ has: adminPage.locator('option[value="PROCEDURE_NEW_QUESTION_ADDED"]') })
      .selectOption('PROCEDURE_NEW_QUESTION_ADDED');
    const question = adminPage.getByText('New question on procedure "Alarm Race Proc"');
    await expect(question).toBeVisible();

    await unfiltered.releaseAndSettle();

    await expect(question).toBeVisible();
    await expect(adminPage.getByText('New issue reported on "Alarm Race Proc"')).toHaveCount(0);
  });

  // L24: the header badge and the Notifications page share their state; a refused mark-read is
  // rolled back and said.
  test('marking an alarm read lowers the bell badge at once, and a failure rolls back', async ({
    adminPage,
    employeePage,
  }) => {
    const adminContext = adminPage.context();
    const adminId = await getEmployeeIdByEmail(adminContext, 'admin@shiftchanger.com');
    const procedure = await createProcedure(adminContext, {
      title: 'Alarm Badge Proc',
      ownerId: adminId,
    });
    await askFaqQuestion(employeePage.context(), procedure.id, 'Badge question one?');
    // Two unread, so the badge still shows a number after one is read.
    await askFaqQuestion(employeePage.context(), procedure.id, 'Badge question two?');

    await adminPage.goto('/alarms');
    const badge = adminPage.getByRole('button', { name: 'Notifications' }).locator('span');
    await expect(badge).toBeVisible();
    const before = Number(await badge.innerText());
    expect(before).toBeGreaterThanOrEqual(2);
    const card = adminPage
      .locator('div.cursor-pointer', { hasText: 'New question on procedure "Alarm Badge Proc"' })
      .first();

    // The server refuses: the badge comes back and the user is told.
    await adminPage.route('**/api/alarms/*/read', (route) =>
      route.fulfill({ status: 500, contentType: 'application/json', body: '{}' }),
    );
    await card.click();
    await expect(adminPage.getByText(/Could not mark the notification as read/)).toBeVisible();
    await expect(badge).toHaveText(String(before));

    // It works: the badge drops without waiting for the next poll.
    await adminPage.unroute('**/api/alarms/*/read');
    const saved = adminPage.waitForResponse((res) => /\/api\/alarms\/[^/]+\/read$/.test(res.url()));
    await card.click();
    await expect(badge).toHaveText(String(before - 1));
    expect((await saved).status()).toBe(200);
    const count = await (await adminContext.request.get('/api/alarms/unread-count')).json();
    expect(count.count).toBe(before - 1);
  });

  // I2: the toast is app-wide, so a failed mark-read from the bell is said on any page.
  test('a failed mark-read from the bell on the dashboard shows a toast', async ({
    adminPage,
    employeePage,
  }) => {
    const adminContext = adminPage.context();
    const adminId = await getEmployeeIdByEmail(adminContext, 'admin@shiftchanger.com');
    const procedure = await createProcedure(adminContext, {
      title: 'Alarm Bell Toast Proc',
      ownerId: adminId,
    });
    await askFaqQuestion(employeePage.context(), procedure.id, 'Bell toast question?');
    await adminPage.route('**/api/alarms/*/read', (route) =>
      route.fulfill({ status: 500, contentType: 'application/json', body: '{}' }),
    );

    await adminPage.goto('/dashboard');
    await adminPage.getByRole('button', { name: 'Notifications' }).click();
    await adminPage
      .locator('#alarm-dropdown')
      .getByText('New question on procedure "Alarm Bell Toast Proc"')
      .first()
      .click();
    await expect(adminPage.getByText(/Could not mark the notification as read/)).toBeVisible();
  });
});
