// frontend/e2e/checklists/handover-reassign.spec.js
import {
  createChecklistSubmission,
  createChecklistTemplate,
  createUser,
} from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, loginAs, STATEFUL_ORIGIN, test } from '../fixtures/auth.js';
import { cleanupStack } from '../fixtures/cleanup.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('checklists/handover-reassign');
const HANDOVER_DAY = reserve.day('2092-08-15');
const STALE_DAY = reserve.day('2092-08-16');

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
  const email = `ho-${label}-${stamp}@example.test`;
  const user = await createUser(admin, { name: `HO ${label} ${stamp}`, email });
  const context = await browser.newContext({ extraHTTPHeaders: STATEFUL_ORIGIN });
  await loginAs(context, email, 'password123');
  return { user, context };
}

/** A pending handover from a new outgoing user to a new incoming user, who is then deactivated. */
async function stuckHandover(admin, browser, cleanup, { date, label }) {
  const outgoing = await throwawayEmployee(admin, browser, `${label}-out`);
  cleanup.add(() => deactivate(admin, outgoing.user.id));
  cleanup.add(() => outgoing.context.close());
  const gone = await throwawayEmployee(admin, browser, `${label}-gone`);
  cleanup.add(() => deactivate(admin, gone.user.id)); // idempotent: also when the test got that far
  cleanup.add(() => gone.context.close());
  const template = await createChecklistTemplate(admin, {
    name: `HO Stuck ${label} ${Date.now()}`,
    requiresConfirmation: true,
  });
  const submission = await createChecklistSubmission(outgoing.context, {
    template,
    date,
    outgoingUserId: outgoing.user.id,
    incomingUserId: gone.user.id,
  });
  expect(submission.status).toBe('pending_confirmation');
  await deactivate(admin, gone.user.id);
  return { outgoing, gone, template, submission };
}

async function openArchiveRow(adminPage, templateName) {
  await adminPage.goto('/checklist/history');
  await adminPage.getByPlaceholder('Search by template or user...').fill(templateName);
  const row = adminPage.getByRole('row').filter({ hasText: templateName });
  await expect(row).toHaveCount(1);
  await expect(row.getByTestId('incoming-deactivated')).toBeVisible();
  return row;
}

test.describe('Stuck handover reassignment', () => {
  test('an admin hands a pending handover of a deactivated user to someone active, who is notified', async ({
    adminPage,
    browser,
  }) => {
    const admin = adminPage.context();
    const cleanup = cleanupStack();
    try {
      const { outgoing, gone, template } = await stuckHandover(admin, browser, cleanup, {
        date: HANDOVER_DAY,
        label: 'ok',
      });
      const replacement = await throwawayEmployee(admin, browser, 'ok-new');
      cleanup.add(() => deactivate(admin, replacement.user.id));
      cleanup.add(() => replacement.context.close());

      const row = await openArchiveRow(adminPage, template.name);
      // Named after its handover, so several stuck rows don't share one button name.
      await row.getByRole('button', { name: `Hand to someone else: ${template.name} of ` }).click();
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
      await expect(row.getByRole('button', { name: /^Hand to someone else/ })).toHaveCount(0);

      // The new incoming user is already on Fill Checklist (the view is reused, not remounted)
      // when they follow the alarm: its link still opens the handover to confirm.
      const page = await replacement.context.newPage();
      await page.goto('/checklist/fill');
      await page.getByRole('button', { name: 'Notifications' }).click();
      const alarm = page.locator('#alarm-dropdown').getByText(/handed you a handover to confirm/);
      await expect(alarm).toBeVisible();
      await alarm.click();
      await expect(page.getByText(`${template.name} item`)).toBeVisible();
      // Handled: the parameter leaves the address, so a reload doesn't reopen it or warn.
      await expect(page).toHaveURL(/\/checklist\/fill$/);
    } finally {
      await cleanup.run();
    }
  });

  test('a handover handed over meanwhile by someone else is re-read instead of failing again', async ({
    adminPage,
    browser,
  }) => {
    const admin = adminPage.context();
    const cleanup = cleanupStack();
    try {
      const { template, submission } = await stuckHandover(admin, browser, cleanup, {
        date: STALE_DAY,
        label: 'stale',
      });
      const mine = await throwawayEmployee(admin, browser, 'stale-mine');
      cleanup.add(() => deactivate(admin, mine.user.id));
      cleanup.add(() => mine.context.close());
      const theirs = await throwawayEmployee(admin, browser, 'stale-theirs');
      cleanup.add(() => deactivate(admin, theirs.user.id));
      cleanup.add(() => theirs.context.close());

      const row = await openArchiveRow(adminPage, template.name);
      await row.getByRole('button', { name: /^Hand to someone else/ }).click();
      const dialog = adminPage.getByRole('dialog', { name: 'Hand to someone else' });
      await dialog.getByLabel('New incoming employee').selectOption({ label: mine.user.name });

      // Another admin (here: the API) hands it over first.
      await api(admin, 'put', `/api/checklist-submissions/${submission.id}/incoming`, {
        incoming_user_id: theirs.user.id,
      });
      await dialog.getByRole('button', { name: 'Hand over' }).click();

      await expect(dialog).toHaveCount(0);
      await expect(
        adminPage.getByText(
          'Only a handover whose incoming user was deactivated can be handed to someone else.',
        ),
      ).toBeVisible();
      await expect(row).toContainText(theirs.user.name);
      await expect(row.getByRole('button', { name: /^Hand to someone else/ })).toHaveCount(0);
    } finally {
      await cleanup.run();
    }
  });
});
