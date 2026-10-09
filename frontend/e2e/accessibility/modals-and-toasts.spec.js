import { createShiftGroupWithStreakRule } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

// Keyboard and screen-reader basics for the shared modal shell (BaseModal), the toast live
// regions and the header's notification menu. Nothing here writes data; the month is arbitrary.
const MONTH = '2031-05';

const focusIsInside = (locator) => locator.evaluate((el) => el.contains(document.activeElement));

// From the dialog's first control: Shift+Tab `presses` times (the first press wraps to the last
// control), then Tab as many times (wrapping forward past the last). Focus must never leave.
async function expectTabStaysInside(page, dialog, presses) {
  for (const key of ['Shift+Tab', 'Tab']) {
    for (let i = 0; i < presses; i++) {
      await page.keyboard.press(key);
      expect(await focusIsInside(dialog), `${key} #${i + 1} left the dialog`).toBe(true);
    }
  }
}

test.describe('Modals: keyboard', () => {
  test('Schedule "Generate Month": opens from the keyboard, traps Tab, Escape closes, focus returns', async ({
    adminPage,
  }) => {
    await createShiftGroupWithStreakRule(adminPage.context(), {
      group: { name: 'A11y Modal Team' },
    });
    await adminPage.goto(`/schedule/${MONTH}`);
    await adminPage.getByRole('button', { name: /A11y Modal Team/ }).click();

    const opener = adminPage.getByRole('button', { name: 'Generate Month' });
    await opener.focus();
    await adminPage.keyboard.press('Enter');

    const dialog = adminPage.getByRole('dialog', { name: /Generate Shifts/ });
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('aria-modal', 'true');
    await expect.poll(() => focusIsInside(dialog)).toBe(true);

    // The modal has well over a dozen controls: a few laps both ways cover the wrap points.
    await expectTabStaysInside(adminPage, dialog, 40);

    await adminPage.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect(opener).toBeFocused();
  });

  test('Users "Add User": opens from the keyboard, traps Tab, Escape closes, focus returns', async ({
    adminPage,
  }) => {
    await adminPage.goto('/users');
    const opener = adminPage.getByRole('button', { name: 'Add User' });
    await opener.focus();
    await adminPage.keyboard.press('Enter');

    const dialog = adminPage.getByRole('dialog', { name: 'Create New User' });
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('aria-modal', 'true');
    // Focus starts on the dialog's first control (its close button), not behind it.
    await expect(dialog.getByRole('button', { name: 'Close' })).toBeFocused();

    await expectTabStaysInside(adminPage, dialog, 12);

    await adminPage.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect(opener).toBeFocused();
  });
});

test.describe('Toasts: live regions', () => {
  test('an error toast lands in an assertive alert region that was already on the page', async ({
    adminPage,
  }) => {
    let failUsers = false;
    await adminPage.route('**/api/users?*', (route) =>
      failUsers ? route.fulfill({ status: 500, body: '{}' }) : route.fallback(),
    );
    await adminPage.goto('/users');
    await expect(adminPage.getByRole('button', { name: 'Add User' })).toBeVisible();

    // Before any message: both regions exist, empty, so a screen reader is already listening.
    const alertRegion = adminPage.locator('[role="alert"][aria-live="assertive"]');
    const statusRegion = adminPage.locator('[role="status"][aria-live="polite"]');
    await expect(alertRegion).toHaveCount(1);
    await expect(alertRegion).toHaveText('');
    await expect(statusRegion).toHaveCount(1);

    // This time the users list fails to load.
    failUsers = true;
    await adminPage.reload();

    await expect(alertRegion).toContainText('Failed to load users. Please refresh.');
    await expect(statusRegion).not.toContainText('Failed to load users');
    // Announcing does not move focus into the toast.
    expect(await focusIsInside(alertRegion)).toBe(false);
  });
});

test.describe('Header notification menu', () => {
  test('closes on Escape (focus back on the bell) and on a click elsewhere', async ({
    adminPage,
  }) => {
    await adminPage.goto('/dashboard');
    const bell = adminPage.getByRole('button', { name: 'Notifications' });
    const menu = adminPage.locator('#alarm-dropdown');

    await bell.click();
    await expect(menu).toBeVisible();
    await expect(bell).toHaveAttribute('aria-expanded', 'true');
    await expect(bell).toHaveAttribute('aria-controls', 'alarm-dropdown');

    await adminPage.keyboard.press('Escape');
    await expect(menu).toHaveCount(0);
    await expect(bell).toHaveAttribute('aria-expanded', 'false');
    await expect(bell).toBeFocused();

    await bell.click();
    await expect(menu).toBeVisible();
    await adminPage.getByRole('heading', { name: /Welcome back/ }).click();
    await expect(menu).toHaveCount(0);
  });
});
