import { expect, test } from '../fixtures/auth.js';

test.describe('Shift Colors', () => {
  test('adds, edits, and deletes a shift color', async ({ adminPage }) => {
    await adminPage.goto('/shift-settings');

    // Add a shift color
    await adminPage.getByPlaceholder('Color name (e.g. Night, Day, Extra)').fill('Twilight');
    await adminPage.getByRole('button', { name: '+ Add' }).click();
    // Toast confirms the POST finished; reloading earlier could abort the request.
    await expect(adminPage.getByText('Color added.')).toBeVisible();

    // After Add: reload and verify the new color is persisted
    await adminPage.reload();
    const row = adminPage.locator('div.group', { hasText: 'Twilight' });
    await expect(row).toBeVisible();

    // Edit the color
    await row.getByTitle('Edit').click();
    // Use a CSS selector to target the exact placeholder "Name" input (distinct from "Color name...")
    await adminPage.locator('input[placeholder="Name"]').fill('Twilight Shift');
    await adminPage.getByRole('button', { name: 'Save' }).click();
    await expect(adminPage.getByText('Color updated.')).toBeVisible();

    // After Save: reload and verify the edit is persisted
    await adminPage.reload();
    const renamedRow = adminPage.locator('div.group', { hasText: 'Twilight Shift' });
    await expect(renamedRow).toBeVisible();

    // Delete the color
    await renamedRow.getByTitle('Delete').click();
    // Scope the Delete button to the confirmation modal dialog
    await adminPage
      .locator('[role="dialog"]')
      .getByRole('button', { name: 'Delete', exact: true })
      .click();
    await expect(adminPage.getByText('Color deleted.')).toBeVisible();

    // After Delete: reload and verify the deletion is persisted
    await adminPage.reload();
    // Positive guard: verify the page rendered
    await expect(adminPage.getByPlaceholder('Color name (e.g. Night, Day, Extra)')).toBeVisible();
    // Verify the color is deleted - use the same row locator to ensure we're checking the same thing
    // that we verified was present after the edit
    await expect(adminPage.locator('div.group', { hasText: 'Twilight Shift' })).toHaveCount(0);
  });
});
