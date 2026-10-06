import { expect, test } from '@playwright/test';

test.describe('Login', () => {
  test('logs in successfully and navigates to the dashboard', async ({ page }) => {
    await page.goto('/login');

    await page.getByLabel('Email Address').fill('admin@shiftchanger.com');
    await page.getByLabel('Password').fill('1234');
    await page.getByRole('button', { name: 'Sign In' }).click();

    await expect(page.getByRole('button', { name: 'Signed In' })).toBeVisible();
    await expect(page).toHaveURL(/\/dashboard$/, { timeout: 3000 });
  });

  test('shows an error and stays on the login page when credentials are invalid', async ({
    page,
  }) => {
    await page.goto('/login');

    await page.getByLabel('Email Address').fill('admin@shiftchanger.com');
    await page.getByLabel('Password').fill('wrong-password');
    await page.getByRole('button', { name: 'Sign In' }).click();

    await expect(
      page.getByText('The provided credentials do not match our records.'),
    ).toBeVisible();
    await expect(page).toHaveURL(/\/login$/);
  });
});
