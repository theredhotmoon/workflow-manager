// frontend/e2e/schedule/auto-fill.spec.js
import {
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getShiftGroupsAndShifts,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

const MONTH = '2027-06';
const EMPLOYEE = 'Regular Employee';

// Same selector list as src/composables/useFocusTrap.js, so first/last match the trap's own.
const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ');

async function openAutoFill(page, groupName) {
  await page.getByRole('button', { name: new RegExp(groupName) }).click();
  // The sidebar's "Auto-Fill" accordion toggle (exact, to skip the "Auto-Fill Shifts" heading).
  await page.getByRole('button', { name: 'Auto-Fill', exact: true }).click();
}

test.describe('Auto-Fill', () => {
  test.beforeEach(async ({ adminPage }) => {
    // Wide viewport avoids the known calendar overlap that intercepts clicks at 1280x720.
    await adminPage.setViewportSize({ width: 1920, height: 1080 });
  });

  test('runs Random Coverage restricted to the selected player and fills shifts', async ({
    adminPage,
  }) => {
    const groupName = 'AutoFill Team';
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: groupName } });
    await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });

    await adminPage.goto(`/schedule/${MONTH}`);
    await openAutoFill(adminPage, groupName);

    await adminPage.getByRole('button', { name: 'Run' }).click();
    const dialog = adminPage.getByRole('dialog', { name: 'Random Coverage' });
    await expect(dialog).toBeVisible();

    await dialog.getByRole('button', { name: 'Only selected' }).click();
    // Every employee starts selected, so clear the list first; otherwise .check() is a no-op.
    await dialog.getByRole('button', { name: 'Clear', exact: true }).click();
    await dialog.getByPlaceholder('Search employees...').fill('Regular');
    await dialog.getByText(EMPLOYEE).locator('..').getByRole('checkbox').check();

    await dialog.getByRole('button', { name: 'Auto-Fill' }).click();
    await expect(adminPage.getByText(/assigned|filled/i)).toBeVisible({ timeout: 10000 });

    // Server side: shifts got assigned, and only to the selected player.
    const { schedule } = await getShiftGroupsAndShifts(context, MONTH, groupId);
    const assignments = Object.values(schedule)
      .flat()
      .flatMap((shift) => shift.assignments);
    expect(assignments.length).toBeGreaterThan(0);
    for (const a of assignments) expect(a.user_name).toBe(EMPLOYEE);
  });

  test('the Random Coverage modal traps focus: Tab wraps both ways, Escape closes, focus is restored', async ({
    adminPage,
  }) => {
    const groupName = 'FocusTrap Team';
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: groupName } });
    await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });

    await adminPage.goto(`/schedule/${MONTH}`);
    await openAutoFill(adminPage, groupName);

    const runButton = adminPage.getByRole('button', { name: 'Run' });
    await runButton.click();

    const dialog = adminPage.getByRole('dialog', { name: 'Random Coverage' });
    await expect(dialog).toBeVisible();

    const focusable = dialog.locator(FOCUSABLE);
    const first = focusable.first();
    const last = focusable.last();

    // Focus enters the trap on the first focusable element.
    await expect(first).toBeFocused();

    // Tab from the last wraps to the first.
    await last.focus();
    await expect(last).toBeFocused();
    await adminPage.keyboard.press('Tab');
    await expect(first).toBeFocused();

    // Shift+Tab from the first wraps to the last.
    await adminPage.keyboard.press('Shift+Tab');
    await expect(last).toBeFocused();

    // Escape closes it and restores focus to the trigger.
    await adminPage.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(runButton).toBeFocused();
  });
});
