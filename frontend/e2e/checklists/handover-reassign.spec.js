// frontend/e2e/checklists/handover-reassign.spec.js
import {
  createChecklistSubmission,
  createChecklistTemplate,
  createUser,
} from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, loginAs, STATEFUL_ORIGIN, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('checklists/handover-reassign');
const HANDOVER_DAY = reserve.day('2092-08-15');

// F7 + PR 20: a pending handover waiting for a deactivated incoming user is stuck; an admin hands
// it to someone active from the archive, and that user is notified with a link that opens it.

async function api(context, method, url, data) {
  const res = await context.request[method](url, {
    data,
    headers: { Accept: 'application/json', ...(await getXsrfHeader(context)) },
  });
  if (!res.ok()) throw new Error(`${method} ${url} -> ${res.status()} ${await res.text()}`);
  return res.json();
}

async function deactivate(admin, userId) {
  await api(admin, 'post', `/api/users/${userId}/deactivate`, { remove_future_assignments: true });
}

/** A throwaway employee, signed in in their own browser context. */
async function throwawayEmployee(admin, browser, label) {
  const stamp = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  const email = `cr-${label}-${stamp}@example.test`;
  const user = await createUser(admin, { name: `CR ${label} ${stamp}`, email });
  const context = await browser.newContext({ extraHTTPHeaders: STATEFUL_ORIGIN });
  await loginAs(context, email, 'password123');
  return { user, context };
}

test.describe('Stuck handover reassignment', () => {
  test('an admin hands a pending handover of a deactivated user to someone active, who is notified', async ({
    adminPage,
    browser,
  }) => {
    const admin = adminPage.context();
    const stamp = Date.now();
    const outgoing = await throwawayEmployee(admin, browser, 'out');
    const gone = await throwawayEmployee(admin, browser, 'gone');
    const replacement = await throwawayEmployee(admin, browser, 'new');
    try {
      const template = await createChecklistTemplate(admin, {
        name: `CR Stuck Handover ${stamp}`,
        requiresConfirmation: true,
      });
      const submission = await createChecklistSubmission(outgoing.context, {
        template,
        date: HANDOVER_DAY,
        outgoingUserId: outgoing.user.id,
        incomingUserId: gone.user.id,
      });
      expect(submission.status).toBe('pending_confirmation');
      await deactivate(admin, gone.user.id);

      await adminPage.goto('/checklist/history');
      await adminPage.getByPlaceholder('Search by template or user...').fill(template.name);
      const row = adminPage.getByRole('row').filter({ hasText: template.name });
      await expect(row).toHaveCount(1);
      await expect(row.getByTestId('incoming-deactivated')).toBeVisible();

      await row.getByRole('button', { name: 'Hand to someone else' }).click();
      const dialog = adminPage.getByRole('dialog', { name: 'Hand to someone else' });
      const picker = dialog.getByLabel('New incoming employee');
      // Active users only, without the outgoing user.
      await expect(picker.locator('option', { hasText: replacement.user.name })).toHaveCount(1);
      await expect(picker.locator('option', { hasText: outgoing.user.name })).toHaveCount(0);
      await expect(picker.locator('option', { hasText: gone.user.name })).toHaveCount(0);
      await picker.selectOption({ label: replacement.user.name });
      await dialog.getByRole('button', { name: 'Hand over' }).click();

      await expect(dialog).toHaveCount(0);
      await expect(row).toContainText(replacement.user.name);
      await expect(row.getByTestId('incoming-deactivated')).toHaveCount(0);
      await expect(row.getByRole('button', { name: 'Hand to someone else' })).toHaveCount(0);

      // The new incoming user sees the alarm, and its link opens the handover to confirm.
      const page = await replacement.context.newPage();
      await page.goto('/dashboard');
      await page.getByRole('button', { name: 'Notifications' }).click();
      const alarm = page.locator('#alarm-dropdown').getByText(/handed you a handover to confirm/);
      await expect(alarm).toBeVisible();
      await alarm.click();
      await expect(page).toHaveURL(new RegExp(`/checklist/fill\\?confirm=${submission.id}$`));
      await expect(page.getByText(`${template.name} item`)).toBeVisible();
    } finally {
      for (const u of [outgoing, gone, replacement]) await u.context.close();
      await deactivate(admin, outgoing.user.id);
      await deactivate(admin, replacement.user.id);
    }
  });
});
