// frontend/e2e/shift-settings/double-submit.spec.js
import { archiveShiftGroup, listShiftGroups } from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

// L28: a double click on a create button sends one request. The first POST is held so the second
// click lands while it is still in flight.

function countPosts(page, pathname) {
  const seen = [];
  page.on('request', (req) => {
    if (req.method() === 'POST' && new URL(req.url()).pathname === pathname) seen.push(req);
  });
  return seen;
}

test.describe('Create buttons ignore a second click while saving', () => {
  test('a shift colour is created once', async ({ adminPage }) => {
    const context = adminPage.context();
    const name = `Double Colour ${Date.now()}`;
    const posts = countPosts(adminPage, '/api/shift-colors');
    const held = await holdRequests(adminPage, (url) => url.pathname === '/api/shift-colors', {
      method: 'POST',
    });
    try {
      await adminPage.goto('/shift-settings');
      await adminPage.getByPlaceholder('Color name (e.g. Night, Day, Extra)').fill(name);
      await adminPage.getByRole('button', { name: '+ Add' }).dblclick();
      await held.seen;
      // Enter in the name field is another way in.
      await adminPage.getByPlaceholder('Color name (e.g. Night, Day, Extra)').press('Enter');
      await held.releaseAndSettle();

      await expect(adminPage.getByText('Color added.')).toBeVisible();
      expect(posts).toHaveLength(1);
      const colors = await (await context.request.get('/api/shift-colors')).json();
      expect(colors.filter((c) => c.name === name)).toHaveLength(1);
    } finally {
      const colors = await (await context.request.get('/api/shift-colors')).json();
      const headers = { Accept: 'application/json', ...(await getXsrfHeader(context)) };
      for (const c of colors.filter((c) => c.name === name)) {
        await context.request.delete(`/api/shift-colors/${c.id}`, { headers });
      }
    }
  });

  test('a shift group is created once', async ({ adminPage }) => {
    const context = adminPage.context();
    const name = `Double Group ${Date.now()}`;
    const posts = countPosts(adminPage, '/api/shift-groups');
    try {
      await adminPage.goto('/schedule');
      await adminPage.getByRole('button', { name: 'New Group' }).click();
      const held = await holdRequests(adminPage, (url) => url.pathname === '/api/shift-groups', {
        method: 'POST',
      });
      const nameInput = adminPage.getByPlaceholder('Group name (e.g. Event Coverage)');
      await nameInput.fill(name);
      await adminPage.getByRole('button', { name: 'Create', exact: true }).dblclick();
      await held.seen;
      await nameInput.press('Enter');
      await held.releaseAndSettle();

      await expect(adminPage.getByText(`Group "${name}" created.`)).toBeVisible();
      expect(posts).toHaveLength(1);
      const groups = await listShiftGroups(context);
      expect(groups.filter((g) => g.name === name)).toHaveLength(1);
    } finally {
      // Archived so its tab doesn't push later specs' calendars around.
      for (const g of (await listShiftGroups(context)).filter((g) => g.name === name)) {
        await archiveShiftGroup(context, g.id).catch(() => {});
      }
    }
  });
});
