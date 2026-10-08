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
});
