import { expect, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

// L34: the Procedure Tags screen's main flow (create, rename, recolour, delete).
const reserve = reservationsFor('procedures/tags');
const TAG = reserve.name('Tag Crud Alpha');
const RENAMED = reserve.name('Tag Crud Beta');

test.describe('Procedure tags: create, edit, delete', () => {
  test('an admin creates a tag, edits its name and colour, then deletes it after confirming', async ({
    adminPage,
  }) => {
    await adminPage.goto('/procedure-tags');
    await expect(adminPage.getByRole('heading', { name: 'Procedure Tags' })).toBeVisible();

    await adminPage.getByRole('button', { name: '+ New Tag' }).click();
    const dialog = adminPage.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: 'New Tag' })).toBeVisible();
    await dialog.getByPlaceholder('e.g. Security').fill(TAG);
    await dialog.locator('input[type="color"]').fill('#10b981');
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();

    await expect(adminPage.getByText('Tag created')).toBeVisible();
    await expect(dialog).toHaveCount(0);
    const row = adminPage.getByRole('row', { name: new RegExp(TAG) });
    await expect(row).toContainText('#10b981');

    await row.getByTitle('Edit').click();
    await expect(dialog.getByRole('heading', { name: 'Edit Tag' })).toBeVisible();
    await expect(dialog.getByPlaceholder('e.g. Security')).toHaveValue(TAG);
    await dialog.getByPlaceholder('e.g. Security').fill(RENAMED);
    await dialog.locator('input[type="color"]').fill('#f59e0b');
    await dialog.getByRole('button', { name: 'Update', exact: true }).click();

    await expect(adminPage.getByText('Tag updated')).toBeVisible();
    const renamed = adminPage.getByRole('row', { name: new RegExp(RENAMED) });
    await expect(renamed).toContainText('#f59e0b');
    await expect(adminPage.getByRole('row', { name: new RegExp(`${TAG}\\b`) })).toHaveCount(0);

    // Delete asks first; cancelling keeps the tag.
    await renamed.getByTitle('Delete').click();
    const prompt = adminPage.getByRole('dialog', { name: 'Delete Tag' });
    await expect(prompt).toContainText(`Delete tag "${RENAMED}"?`);
    await prompt.getByRole('button', { name: 'Cancel' }).click();
    await expect(renamed).toBeVisible();

    await renamed.getByTitle('Delete').click();
    await prompt.getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(adminPage.getByText('Tag deleted')).toBeVisible();
    await expect(renamed).toHaveCount(0);
  });
});
