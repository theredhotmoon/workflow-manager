import { expect, test } from '../fixtures/auth.js';

// M21: the dashboard showed hard-coded statistics (12 active shifts, 24 employees, 168 hours,
// 3 pending swaps) and a Reports button that did nothing. Only real content stays.
const FAKE_STATS = ['Active Shifts', 'Employees', 'Hours This Week', 'Pending Swaps'];

test.describe('Dashboard', () => {
  test('an admin sees no made-up statistics and no dead Reports action', async ({ adminPage }) => {
    await adminPage.goto('/dashboard');
    await expect(adminPage.getByRole('heading', { name: /Welcome back/ })).toBeVisible();
    await expect(adminPage.getByRole('heading', { name: "Today's Schedule" })).toBeVisible();

    for (const label of FAKE_STATS) {
      await expect(adminPage.getByText(label, { exact: true })).toHaveCount(0);
    }
    await expect(adminPage.getByRole('button', { name: /Reports/ })).toHaveCount(0);
    await expect(adminPage.getByText('View shift analytics')).toHaveCount(0);

    // The real quick actions stay.
    await expect(adminPage.getByRole('link', { name: /Shift Schedule/ })).toBeVisible();
    await expect(adminPage.getByRole('link', { name: /Manage Users/ })).toBeVisible();
  });

  test('an employee sees no statistics and no empty Quick Actions panel', async ({
    employeePage,
  }) => {
    await employeePage.goto('/dashboard');
    await expect(employeePage.getByRole('heading', { name: /Welcome back/ })).toBeVisible();
    await expect(employeePage.getByRole('heading', { name: "Today's Schedule" })).toBeVisible();

    for (const label of FAKE_STATS) {
      await expect(employeePage.getByText(label, { exact: true })).toHaveCount(0);
    }
    await expect(employeePage.getByRole('button', { name: /Reports/ })).toHaveCount(0);
    await expect(employeePage.getByRole('heading', { name: 'Quick Actions' })).toHaveCount(0);
  });
});
