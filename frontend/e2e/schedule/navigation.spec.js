import { createShiftGroupWithStreakRule } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('schedule/navigation');
reserve.months('2027-09', '2028-07');

// Every other schedule/on-call spec in this suite runs against a single, never-reset-between-files
// database (playwright.config.js has workers: 1, and global-setup only resets once for the whole run —
// see Task 3), each using its own month key to stay isolated. 2027-03 is Task 6/Task 8's month; this
// file uses 2027-09 (unused elsewhere) so its "no shifts yet" assertion can't be contaminated by
// shifts another task generated for a month this file also happened to touch.
test.describe('Schedule navigation', () => {
  // Month 2028-07 is reserved for this test.
  test('switching group right after a deep link is not undone by the slower initial load', async ({
    adminPage,
  }) => {
    await createShiftGroupWithStreakRule(adminPage.context(), {
      group: { name: 'Quick Switch Team' },
    });

    // On a deep link the page's first /api/schedule request goes out before the groups have loaded,
    // so it has no shift_group_id and the server answers with the default group. Hold it until the
    // user has switched tabs, so its response lands last — the race that made CI flaky.
    const isUnscoped = (url) =>
      url.pathname === '/api/schedule' && !url.searchParams.has('shift_group_id');
    let release;
    const held = new Promise((resolve) => {
      release = resolve;
    });
    await adminPage.route(isUnscoped, async (route) => {
      await held;
      await route.continue();
    });

    await adminPage.goto('/schedule/2028-07');
    const tab = adminPage.getByRole('button', { name: /Quick Switch Team/ });
    await tab.click();
    await expect(tab.getByTitle('Settlement period settings')).toBeVisible();

    const lateResponse = adminPage.waitForResponse((res) => isUnscoped(new URL(res.url())));
    release();
    await lateResponse;
    await adminPage.waitForLoadState('networkidle');

    // The group the user picked must still be the active one.
    await expect(tab.getByTitle('Settlement period settings')).toBeVisible();
    await expect(adminPage.getByTitle('Settlement period settings')).toHaveCount(1);
  });

  test('deep-links to a month and steps forward/back', async ({ adminPage }) => {
    await adminPage.goto('/schedule/2027-09');
    await expect(adminPage.getByText('September 2027')).toBeVisible();

    await adminPage.getByLabel('Next month').click();
    await expect(adminPage.getByText('October 2027')).toBeVisible();

    await adminPage.getByLabel('Previous month').click();
    await expect(adminPage.getByText('September 2027')).toBeVisible();
  });

  test('shows the empty state for a month with no shifts yet', async ({ adminPage }) => {
    // "No shifts" only renders in All Groups mode, and 2027-09 is reserved for this file
    // so no other spec seeds shifts there. Create a group to guarantee the tabs render.
    await createShiftGroupWithStreakRule(adminPage.context(), {
      group: { name: 'Navigation Empty Group' },
    });

    await adminPage.goto('/schedule/2027-09');
    await adminPage.getByRole('button', { name: 'All Groups' }).click();
    await expect(adminPage.getByText('No shifts', { exact: true })).toHaveCount(30);
  });

  test('creates a new shift group from the tabs row', async ({ adminPage }) => {
    await adminPage.goto('/schedule/2027-09');
    await adminPage.getByRole('button', { name: 'New Group' }).click();
    await adminPage.getByPlaceholder('Group name (e.g. Event Coverage)').fill('Day Crew');
    await adminPage.getByRole('button', { name: 'Create' }).click();

    await expect(adminPage.getByRole('button', { name: /Day Crew/ })).toBeVisible();
  });
});
