// frontend/e2e/schedule/shift-groups.spec.js
import { createShiftGroupWithStreakRule, listShiftGroups } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('schedule/shift-groups');

const MONTH = reserve.month('2027-10');

test.describe('Shift group rename, archive and restore', () => {
  test('renames a group inline (double-click) and the new name survives a reload', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Rename Me Team' },
    });

    await adminPage.goto(`/schedule/${MONTH}`);
    const tab = adminPage.getByRole('button', { name: /Rename Me Team/ });
    await tab.click();
    await tab.getByText('Rename Me Team', { exact: true }).dblclick();
    // The tab's accessible name changes while typing, so locate the input independently of it.
    const renameInput = adminPage.locator('button input[type="text"]');
    await expect(renameInput).toHaveValue('Rename Me Team');
    await renameInput.fill('Renamed Team');
    // While the input is open the tab's accessible name already contains the typed text, so wait
    // for the PUT itself and for the input to close before trusting the UI.
    const saved = adminPage.waitForResponse(
      (res) =>
        res.request().method() === 'PUT' && res.url().endsWith(`/api/shift-groups/${groupId}`),
    );
    await renameInput.press('Enter');
    expect((await saved).ok()).toBe(true);
    await expect(renameInput).toHaveCount(0);
    await expect(adminPage.getByRole('button', { name: /Renamed Team/ })).toBeVisible();

    // Server side: the rename was persisted, not only shown.
    const groups = await listShiftGroups(context);
    expect(groups.find((g) => g.id === groupId)?.name).toBe('Renamed Team');

    await adminPage.reload();
    await expect(adminPage.getByRole('button', { name: /Renamed Team/ })).toBeVisible();
    await expect(adminPage.getByRole('button', { name: /Rename Me Team/ })).toHaveCount(0);
  });

  test('archives a group (gone from the tabs) and restores it (back again)', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, {
      group: { name: 'Archive Me Team' },
    });
    await createShiftGroupWithStreakRule(context, { group: { name: 'Keep Me Team' } });

    await adminPage.goto(`/schedule/${MONTH}`);
    await adminPage.getByRole('button', { name: /Archive Me Team/ }).click();
    await adminPage.getByTitle('Archive group').click();

    const dialog = adminPage.getByRole('dialog');
    await expect(dialog).toContainText('Archive group "Archive Me Team"?');
    await expect(dialog).toContainText('Kept: its past shifts');
    await dialog.getByRole('button', { name: 'Archive', exact: true }).click();
    await expect(adminPage.getByText('Shift group "Archive Me Team" archived.')).toBeVisible();
    await expect(adminPage.getByRole('button', { name: /Archive Me Team/ })).toHaveCount(0);

    // Server side: archived, not deleted; the other group is untouched.
    const groups = await listShiftGroups(context);
    expect(groups.some((g) => g.id === groupId)).toBe(false);
    expect(groups.some((g) => g.name === 'Keep Me Team')).toBe(true);

    await adminPage.reload();
    await expect(adminPage.getByRole('button', { name: /Keep Me Team/ })).toBeVisible();
    await expect(adminPage.getByRole('button', { name: /^Archive Me Team/ })).toHaveCount(0);

    // Restore from the Archived list: the tab comes back.
    await adminPage.getByRole('button', { name: /^Archived \(\d+\)$/ }).click();
    await adminPage.getByRole('button', { name: 'Restore Archive Me Team' }).click();
    await expect(adminPage.getByText('Shift group "Archive Me Team" restored.')).toBeVisible();
    await expect(adminPage.getByRole('button', { name: /^Archive Me Team/ })).toBeVisible();
    expect((await listShiftGroups(context)).some((g) => g.id === groupId)).toBe(true);
  });
});
