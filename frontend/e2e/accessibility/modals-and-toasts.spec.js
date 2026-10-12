import { createShiftGroupWithStreakRule, createUser } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('accessibility/modals-and-toasts');

// Keyboard and screen-reader basics for the shared modal shell (BaseModal), the toast live
// regions and the header's notification menu. Nothing here writes data; the month is arbitrary.
const MONTH = reserve.month('2031-05');

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

    // Like the other dialogs, a click on the backdrop closes it.
    await opener.click();
    await expect(dialog).toBeVisible();
    await adminPage.mouse.click(5, 5);
    await expect(dialog).toHaveCount(0);
  });

  test('Schedule "Delete Schedule": step 2 is its own alertdialog and takes focus', async ({
    adminPage,
  }) => {
    await createShiftGroupWithStreakRule(adminPage.context(), {
      group: { name: 'A11y Delete Team' },
    });
    await adminPage.goto(`/schedule/${MONTH}`);
    await adminPage.getByRole('button', { name: /A11y Delete Team/ }).click();

    const opener = adminPage.getByRole('button', { name: 'Delete Schedule' });
    await opener.focus();
    await adminPage.keyboard.press('Enter');
    const step1 = adminPage.getByRole('alertdialog', { name: /Schedule\?$/ });
    await expect(step1).toBeVisible();
    await expect(step1.getByRole('button', { name: 'Cancel' })).toBeFocused();

    await adminPage.keyboard.press('Tab');
    await expect(step1.getByRole('button', { name: 'Yes, continue' })).toBeFocused();
    await adminPage.keyboard.press('Enter');

    const step2 = adminPage.getByRole('alertdialog', { name: 'This cannot be undone!' });
    await expect(step2).toBeVisible();
    await expect(step2).toHaveAccessibleDescription(/permanently deleted/);
    // Focus moved into step 2 instead of dropping to <body> with step 1's buttons.
    await expect(step2.getByRole('button', { name: 'Cancel' })).toBeFocused();

    await adminPage.keyboard.press('Escape');
    await expect(step2).toHaveCount(0);
    await expect(opener).toBeFocused();
  });
});

test.describe('Modals: closing while a save is in flight', () => {
  test('Users "Change Password": Escape waits, Cancel closes, a late failure is a toast', async ({
    adminPage,
  }) => {
    const stamp = Date.now();
    const name = `Busy Pw ${stamp}`;
    await createUser(adminPage.context(), { name, email: `busy-pw-${stamp}@example.test` });

    // Hold the password request until the dialog has been closed, then fail it.
    let releaseSave;
    const released = new Promise((resolve) => {
      releaseSave = resolve;
    });
    await adminPage.route('**/api/users/*/password', async (route) => {
      await released;
      await route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Server exploded.' }),
      });
    });

    await adminPage.goto('/users');
    await adminPage
      .getByRole('row', { name: new RegExp(name) })
      .getByTitle('Change Password')
      .click();
    const dialog = adminPage.getByRole('dialog', { name: 'Change Password' });
    await dialog.getByPlaceholder('Minimum 8 characters').fill('newpassword1');
    await dialog.getByPlaceholder('Re-enter new password').fill('newpassword1');
    await dialog.getByRole('button', { name: 'Change Password' }).click();
    await expect(dialog.getByRole('button', { name: 'Changing...' })).toBeVisible();

    // Busy: Escape and a backdrop click are ignored, the visible Cancel still works.
    await adminPage.keyboard.press('Escape');
    await adminPage.mouse.click(5, 5);
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toHaveCount(0);

    releaseSave();
    await expect(adminPage.locator('[role="alert"][aria-live="assertive"]')).toContainText(
      `Password for "${name}" was not changed: Server exploded.`,
    );
    // Reopening shows a clean form, not the late error.
    await adminPage
      .getByRole('row', { name: new RegExp(name) })
      .getByTitle('Change Password')
      .click();
    await expect(dialog).toBeVisible();
    await expect(dialog).not.toContainText('Server exploded.');
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
    // Only the message is announced: the Dismiss button is outside the region.
    await expect(alertRegion).toHaveText('Failed to load users. Please refresh.');
    await expect(alertRegion.getByRole('button')).toHaveCount(0);
    await expect(adminPage.getByRole('button', { name: 'Dismiss notification' })).toBeVisible();
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
