import { createShiftGroupWithStreakRule } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('schedule/generate-month');
reserve.months('2027-04');

test.describe('Generate Month', () => {
  test('generates a month of shifts with the default single-shift template', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'Generate Test Team' } });

    // 2027-04 is reserved for this spec; April has 30 days.
    // "No shifts" renders once per empty day cell, only in All Groups mode.
    const emptyDays = adminPage.getByText('No shifts', { exact: true });

    await adminPage.goto('/schedule/2027-04');
    await adminPage.getByRole('button', { name: 'All Groups' }).click();
    await expect(emptyDays).toHaveCount(30);

    await adminPage.getByRole('button', { name: /Generate Test Team/ }).click();
    await adminPage.getByRole('button', { name: 'Generate Month' }).click();

    const modal = adminPage
      .locator('div.fixed')
      .filter({ has: adminPage.getByRole('heading', { name: /Generate Shifts/ }) });
    await expect(modal).toBeVisible();
    await modal.getByRole('button', { name: /^Generate / }).click();

    await expect(adminPage.getByText(/Generated \d+ shift slots/)).toBeVisible();

    await adminPage.getByRole('button', { name: 'All Groups' }).click();
    // Positive evidence first: an empty count alone also passes while the calendar is still loading.
    await expect(adminPage.getByText('S1', { exact: true })).toHaveCount(30);
    await expect(emptyDays).toHaveCount(0);
  });
});
