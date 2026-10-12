import {
  createAvailability,
  createDelegation,
  createNewbiePeriod,
  createOfficeClosure,
  createShiftGroupWithStreakRule,
  getEmployeeIdByEmail,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('schedule/stale-overlays');
reserve.months(
  '2098-01',
  '2098-02',
  '2098-03',
  '2098-04',
  '2098-05',
  '2098-06',
  '2098-07',
  '2098-12',
);
// Shift-group names: schedule tabs and rosters are found by name across the whole run.
reserve.name('Delegation Overlay Race');
reserve.name('Newbie Overlay Race');
reserve.name('Closure Overlay Race');
reserve.name('Availability Race Team');
reserve.name('Deselect Race Team');

// Months reserved for this file (delegations, newbie periods, closures and availability are global,
// not per group): 2098-01/02 delegations, 2098-03/04 newbies, 2098-05/06 closures, 2098-07 and
// 2098-12 availability.
const UNAVAILABLE = 'rgba(239, 68, 68, 0.18)';

const dayCell = (page, date) => page.locator(`[data-date="${date}"]`);
const poolCard = (page, name) => page.locator('div.cursor-grab').filter({ hasText: name });

async function enableOverlay(page, label) {
  await page.getByRole('button', { name: 'Overlays' }).click();
  await page.getByLabel(label).check();
}

test.describe('Schedule overlays ignore stale responses', () => {
  test('a slow delegations overlay for the previous month does not replace the month on screen', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'Delegation Overlay Race' } });
    const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const marcinId = await getEmployeeIdByEmail(context, 'marcin@shiftchanger.com');
    await createDelegation(context, {
      name: 'January Overlay Trip',
      destination: 'Overlay City',
      start: '2098-01-10',
      end: '2098-01-12',
      userIds: [regularId],
    });
    await createDelegation(context, {
      name: 'February Overlay Trip',
      destination: 'Overlay City',
      start: '2098-02-10',
      end: '2098-02-12',
      userIds: [marcinId],
    });

    // Every January delegations request (after the schedule load and after switching the overlay
    // on) is held until February's has rendered, so January's lands last.
    const january = await holdRequests(
      adminPage,
      (url) =>
        url.pathname === '/api/delegations/month' && url.searchParams.get('month') === '2098-01',
    );

    await adminPage.goto('/schedule/2098-01');
    await expect(adminPage.getByText('January 2098')).toBeVisible();
    await january.seen;
    await enableOverlay(adminPage, 'Show Delegations');
    await adminPage.getByLabel('Next month').click();
    await expect(adminPage.getByText('February 2098')).toBeVisible();
    const februaryChip = dayCell(adminPage, '2098-02-10').getByText('Marcin Kefir');
    await expect(februaryChip).toBeVisible();

    await january.releaseAndSettle();

    await expect(adminPage.getByText('February 2098')).toBeVisible();
    await expect(februaryChip).toBeVisible();
  });

  test('a slow newbie overlay for the previous month does not replace the month on screen', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'Newbie Overlay Race' } });
    const regularId = await getEmployeeIdByEmail(context, 'regular@shiftchanger.com');
    const marcinId = await getEmployeeIdByEmail(context, 'marcin@shiftchanger.com');
    await createNewbiePeriod(context, {
      userId: regularId,
      startDate: '2098-03-10',
      endDate: '2098-03-12',
    });
    await createNewbiePeriod(context, {
      userId: marcinId,
      startDate: '2098-04-10',
      endDate: '2098-04-12',
    });

    const march = await holdRequests(
      adminPage,
      (url) =>
        url.pathname === '/api/newbie-periods/month' && url.searchParams.get('month') === '2098-03',
    );

    await adminPage.goto('/schedule/2098-03');
    await expect(adminPage.getByText('March 2098')).toBeVisible();
    await march.seen;
    await enableOverlay(adminPage, 'Show Newbies');
    await adminPage.getByLabel('Next month').click();
    await expect(adminPage.getByText('April 2098')).toBeVisible();
    const aprilStar = dayCell(adminPage, '2098-04-10').getByText('Marcin Kefir');
    await expect(aprilStar).toBeVisible();

    await march.releaseAndSettle();

    await expect(adminPage.getByText('April 2098')).toBeVisible();
    await expect(aprilStar).toBeVisible();
  });

  test('the office-closure overlay follows month navigation and ignores the previous month', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'Closure Overlay Race' } });
    await createOfficeClosure(context, { startDate: '2098-05-10', title: 'May Overlay Closure' });
    await createOfficeClosure(context, { startDate: '2098-06-10', title: 'June Overlay Closure' });

    const may = await holdRequests(
      adminPage,
      (url) =>
        url.pathname === '/api/office-closures' && url.searchParams.get('month') === '2098-05',
    );

    await adminPage.goto('/schedule/2098-05');
    await expect(adminPage.getByText('May 2098')).toBeVisible();
    await enableOverlay(adminPage, 'Show Office Unavailability');
    await may.seen;
    await adminPage.getByLabel('Next month').click();
    await expect(adminPage.getByText('June 2098')).toBeVisible();
    // Before the fix the overlay was never refetched on month change, so June's closure never showed.
    const juneClosure = dayCell(adminPage, '2098-06-10').getByText('June Overlay Closure');
    await expect(juneClosure).toBeVisible();

    await may.releaseAndSettle();

    await expect(juneClosure).toBeVisible();
    await expect(adminPage.getByText('May Overlay Closure')).toHaveCount(0);
  });

  test('a slow availability load for the previously clicked employee does not paint their days', async ({
    adminPage,
    employeePage,
  }) => {
    const adminContext = adminPage.context();
    await createShiftGroupWithStreakRule(adminContext, {
      group: { name: 'Availability Race Team' },
    });
    const adminId = await getEmployeeIdByEmail(adminContext, 'admin@shiftchanger.com');
    await createAvailability(adminContext, { type: 'unavailable', startDate: '2098-07-10' });
    await createAvailability(employeePage.context(), {
      type: 'unavailable',
      startDate: '2098-07-20',
    });

    const adminAvailability = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/user-availabilities/${adminId}/month`,
    );

    await adminPage.goto('/schedule/2098-07');
    await expect(adminPage.getByText('July 2098')).toBeVisible();
    // Same reason as the deselect test below: no colour fade, so the CSS checks see the final paint.
    await adminPage.addStyleTag({ content: '* { transition: none !important; }' });
    await poolCard(adminPage, 'Admin User').click();
    await adminAvailability.seen;
    await poolCard(adminPage, 'Regular Employee').click();
    await expect(dayCell(adminPage, '2098-07-20')).toHaveCSS('background-color', UNAVAILABLE);

    await adminAvailability.releaseAndSettle();

    await expect(dayCell(adminPage, '2098-07-20')).toHaveCSS('background-color', UNAVAILABLE);
    await expect(dayCell(adminPage, '2098-07-10')).not.toHaveCSS('background-color', UNAVAILABLE);
  });

  // Review Focus 1.
  test('an employee deselected while their availability loads never paints under the next one', async ({
    adminPage,
    employeePage,
  }) => {
    const adminContext = adminPage.context();
    await createShiftGroupWithStreakRule(adminContext, { group: { name: 'Deselect Race Team' } });
    const adminId = await getEmployeeIdByEmail(adminContext, 'admin@shiftchanger.com');
    const regularId = await getEmployeeIdByEmail(adminContext, 'regular@shiftchanger.com');
    await createAvailability(adminContext, { type: 'unavailable', startDate: '2098-12-10' });
    await createAvailability(employeePage.context(), {
      type: 'unavailable',
      startDate: '2098-12-20',
    });

    const adminAvailability = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/user-availabilities/${adminId}/month`,
    );

    await adminPage.goto('/schedule/2098-12');
    await expect(adminPage.getByText('December 2098')).toBeVisible();
    // Day cells fade their background in (transition-colors); without this a "not painted" check
    // would pass on the first frame of a stale paint.
    await adminPage.addStyleTag({ content: '* { transition: none !important; }' });
    const selectedPanel = adminPage.getByText('Availability for');
    await poolCard(adminPage, 'Admin User').click();
    await expect(selectedPanel).toBeVisible();
    await adminAvailability.seen;
    await poolCard(adminPage, 'Admin User').click(); // deselect
    await expect(selectedPanel).toHaveCount(0);
    await adminAvailability.releaseAndSettle();

    // Hold the next employee's load so only a stale result could paint anything right now.
    const regularAvailability = await holdRequests(
      adminPage,
      (url) => url.pathname === `/api/user-availabilities/${regularId}/month`,
    );
    await poolCard(adminPage, 'Regular Employee').click();
    await regularAvailability.seen;
    await expect(selectedPanel).toBeVisible();
    await expect(dayCell(adminPage, '2098-12-10')).not.toHaveCSS('background-color', UNAVAILABLE);

    await regularAvailability.releaseAndSettle();

    await expect(dayCell(adminPage, '2098-12-20')).toHaveCSS('background-color', UNAVAILABLE);
    await expect(dayCell(adminPage, '2098-12-10')).not.toHaveCSS('background-color', UNAVAILABLE);
  });
});
