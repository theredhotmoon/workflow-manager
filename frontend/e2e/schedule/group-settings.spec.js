import { createShiftGroupWithStreakRule } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('schedule/group-settings');
reserve.months('2027-03');

test.describe('Shift group settlement + streak-rule settings', () => {
  test('sets settlement mode, weekly cap, and a streak rule from the shared settings modal', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'Support Team' } });

    await adminPage.goto('/schedule/2027-03');
    // Scope the gear to this group's tab: it only renders on the active tab, so an unscoped click
    // could open whichever group is active at that instant.
    await adminPage.getByRole('button', { name: /Support Team/ }).click();
    await adminPage
      .getByRole('button', { name: /Support Team/ })
      .getByTitle('Settlement period settings')
      .click();

    await expect(adminPage.getByText('Settlement Period Settings')).toBeVisible();

    await adminPage.getByLabel('Mode').selectOption('quarterly');
    await adminPage.getByLabel('Weekly Hour Cap').fill('40');
    await adminPage.getByRole('button', { name: '+ Add streak rule' }).click();
    await adminPage.getByPlaceholder('shift type (e.g. night)').last().fill('day');

    await adminPage.getByRole('button', { name: 'Save' }).click();
    await expect(adminPage.getByText('Settlement settings saved.')).toBeVisible();

    // Modal stays open after Save, so reload to read back what the server persisted
    await adminPage.reload();
    await adminPage.getByRole('button', { name: /Support Team/ }).click();
    await adminPage
      .getByRole('button', { name: /Support Team/ })
      .getByTitle('Settlement period settings')
      .click();
    await expect(adminPage.getByLabel('Mode')).toBeVisible();
    await expect(adminPage.getByLabel('Mode')).toHaveValue('quarterly');
    await expect(adminPage.getByLabel('Weekly Hour Cap')).toHaveValue('40');
    await expect(adminPage.getByPlaceholder('shift type (e.g. night)').last()).toHaveValue('day');
  });

  test('removes a streak rule from the same modal', async ({ adminPage }) => {
    const context = adminPage.context();
    await createShiftGroupWithStreakRule(context, { group: { name: 'Cleanup Team' } });

    await adminPage.goto('/schedule/2027-03');
    await adminPage.getByRole('button', { name: /Cleanup Team/ }).click();
    await adminPage
      .getByRole('button', { name: /Cleanup Team/ })
      .getByTitle('Settlement period settings')
      .click();

    await expect(adminPage.getByPlaceholder('shift type (e.g. night)')).toHaveValue('night');
    await adminPage.getByTitle('Remove rule').click();
    await adminPage.getByRole('button', { name: 'Save' }).click();
    await expect(adminPage.getByText('Settlement settings saved.')).toBeVisible();

    // Modal stays open after Save, so reload to read back what the server persisted
    await adminPage.reload();
    await adminPage.getByRole('button', { name: /Cleanup Team/ }).click();
    await adminPage
      .getByRole('button', { name: /Cleanup Team/ })
      .getByTitle('Settlement period settings')
      .click();
    await expect(adminPage.getByLabel('Mode')).toBeVisible();
    await expect(adminPage.getByPlaceholder('shift type (e.g. night)')).toHaveCount(0);
  });
});
