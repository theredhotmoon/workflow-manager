import { createShiftGroupWithStreakRule } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

test.describe('Shift group settlement + streak-rule settings', () => {
  test('sets settlement mode, weekly cap, and a streak rule from the shared settings modal', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'Support Team' } });

    await adminPage.goto('/schedule/2027-03');
    await adminPage.getByRole('button', { name: /Support Team/ }).click();
    await adminPage.getByTitle('Settlement period settings').click();

    await expect(adminPage.getByText('Settlement Period Settings')).toBeVisible();

    await adminPage.getByLabel('Mode').selectOption('quarterly');
    await adminPage.getByLabel('Weekly Hour Cap').fill('40');
    await adminPage.getByRole('button', { name: '+ Add streak rule' }).click();
    await adminPage.getByPlaceholder('shift type (e.g. night)').last().fill('day');

    await adminPage.getByRole('button', { name: 'Save' }).click();
    await expect(adminPage.getByText('Settlement settings saved.')).toBeVisible();

    // Re-open to confirm persistence
    await adminPage.getByTitle('Settlement period settings').click();
    await expect(adminPage.getByLabel('Mode')).toHaveValue('quarterly');
    await expect(adminPage.getByPlaceholder('shift type (e.g. night)').last()).toHaveValue('day');
  });

  test('removes a streak rule from the same modal', async ({ adminPage }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'Cleanup Team' } });

    await adminPage.goto('/schedule/2027-03');
    await adminPage.getByRole('button', { name: /Cleanup Team/ }).click();
    await adminPage.getByTitle('Settlement period settings').click();

    await expect(adminPage.getByPlaceholder('shift type (e.g. night)')).toHaveValue('night');
    await adminPage.getByTitle('Remove rule').click();
    await adminPage.getByRole('button', { name: 'Save' }).click();
    await expect(adminPage.getByText('Settlement settings saved.')).toBeVisible();

    await adminPage.getByTitle('Settlement period settings').click();
    await expect(adminPage.getByLabel('Mode')).toBeVisible();
    await expect(adminPage.getByPlaceholder('shift type (e.g. night)')).toHaveCount(0);
  });
});
