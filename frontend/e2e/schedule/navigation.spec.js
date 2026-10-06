import { expect, test } from '../fixtures/auth.js';

// Every other schedule/on-call spec in this suite runs against a single, never-reset-between-files
// database (playwright.config.js has workers: 1, and global-setup only resets once for the whole run —
// see Task 3), each using its own month key to stay isolated. 2027-03 is Task 6/Task 8's month; this
// file uses 2027-09 (unused elsewhere) so its "no shifts yet" assertion can't be contaminated by
// shifts another task generated for a month this file also happened to touch.
test.describe('Schedule navigation', () => {
  test('deep-links to a month and steps forward/back', async ({ adminPage }) => {
    await adminPage.goto('/schedule/2027-09');
    await expect(adminPage.getByText('September 2027')).toBeVisible();

    await adminPage.getByLabel('Next month').click();
    await expect(adminPage.getByText('October 2027')).toBeVisible();

    await adminPage.getByLabel('Previous month').click();
    await expect(adminPage.getByText('September 2027')).toBeVisible();
  });

  test('shows the empty state for a month with no shifts yet', async ({ adminPage }) => {
    await adminPage.goto('/schedule/2027-09');
    await expect(adminPage.getByText('No shifts').first()).toBeVisible();
  });

  test('creates a new shift group from the tabs row', async ({ adminPage }) => {
    await adminPage.goto('/schedule/2027-09');
    await adminPage.getByRole('button', { name: 'New Group' }).click();
    await adminPage.getByPlaceholder('Group name (e.g. Event Coverage)').fill('Day Crew');
    await adminPage.getByRole('button', { name: 'Create' }).click();

    await expect(adminPage.getByRole('button', { name: /Day Crew/ })).toBeVisible();
  });
});
