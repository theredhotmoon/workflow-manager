import { askFaqQuestion, createProcedure, getEmployeeIdByEmail } from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

async function adminProcedure(page, title) {
  const context = page.context();
  const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
  return createProcedure(context, { title, ownerId: adminId });
}

async function apiPost(context, url, data) {
  const res = await context.request.post(url, {
    data,
    headers: { Accept: 'application/json', ...(await getXsrfHeader(context)) },
  });
  if (!res.ok()) throw new Error(`POST ${url} failed: ${res.status()} ${await res.text()}`);
  return res.json();
}

// Counts the requests with `method` whose path is `path`.
function countRequests(page, method, path) {
  const seen = [];
  page.on('request', (req) => {
    if (req.method() === method && new URL(req.url()).pathname === path) seen.push(req);
  });
  return () => seen.length;
}

test.describe('Procedure feedback, FAQ and templates', () => {
  test('double-clicking Submit on a report sends it once', async ({ adminPage }) => {
    const procedure = await adminProcedure(adminPage, `Feedback Once ${Date.now()}`);
    const path = `/api/procedures/${procedure.id}/feedback`;
    const posts = countRequests(adminPage, 'POST', path);
    const held = await holdRequests(adminPage, (url) => url.pathname === path, { method: 'POST' });

    await adminPage.goto(`/procedures/${procedure.id}`);
    await adminPage.getByRole('button', { name: '⚠ Report Error' }).click();
    await adminPage.getByPlaceholder('Describe the issue...').fill('Step 3 is wrong');
    await adminPage.getByRole('button', { name: 'Submit' }).dblclick();
    await held.seen;
    await held.releaseAndSettle();

    await expect(adminPage.getByText('Thanks, your report was sent.')).toBeVisible();
    expect(posts()).toBe(1);
  });

  test('pressing Enter twice asks a FAQ question once', async ({ adminPage }) => {
    const procedure = await adminProcedure(adminPage, `FAQ Once ${Date.now()}`);
    const path = `/api/procedures/${procedure.id}/faq`;
    const posts = countRequests(adminPage, 'POST', path);
    const held = await holdRequests(adminPage, (url) => url.pathname === path, { method: 'POST' });

    await adminPage.goto(`/procedures/${procedure.id}`);
    await adminPage.getByRole('button', { name: /^FAQ/ }).click();
    const input = adminPage.getByPlaceholder('Ask a question about this procedure...');
    await input.fill('Who approves step 2?');
    await input.press('Enter');
    await held.seen;
    await input.press('Enter');
    await held.releaseAndSettle();

    await expect(adminPage.getByText('Who approves step 2?')).toHaveCount(1);
    expect(posts()).toBe(1);
  });

  test('double-clicking "mark as outdated" toggles once', async ({ adminPage }) => {
    const procedure = await adminProcedure(adminPage, `FAQ Toggle ${Date.now()}`);
    const item = await askFaqQuestion(adminPage.context(), procedure.id, 'Still current?');
    const path = `/api/procedure-faq/${item.id}/toggle-outdated`;
    const puts = countRequests(adminPage, 'PUT', path);
    const held = await holdRequests(adminPage, (url) => url.pathname === path, { method: 'PUT' });

    await adminPage.goto(`/procedures/${procedure.id}`);
    await adminPage.getByRole('button', { name: /^FAQ/ }).click();
    await adminPage.getByRole('button', { name: 'Mark question as outdated' }).dblclick();
    await held.seen;
    await held.releaseAndSettle();

    await expect(adminPage.getByText('Outdated', { exact: true })).toBeVisible();
    expect(puts()).toBe(1);
  });

  test('deleting a FAQ question asks first', async ({ adminPage }) => {
    const procedure = await adminProcedure(adminPage, `FAQ Delete ${Date.now()}`);
    await askFaqQuestion(adminPage.context(), procedure.id, 'Is this question deletable?');
    let deleteCalls = 0;
    adminPage.on('request', (req) => {
      if (
        req.method() === 'DELETE' &&
        new URL(req.url()).pathname.startsWith('/api/procedure-faq/')
      )
        deleteCalls += 1;
    });

    await adminPage.goto(`/procedures/${procedure.id}`);
    await adminPage.getByRole('button', { name: /^FAQ/ }).click();
    const question = adminPage.getByText('Is this question deletable?');
    await expect(question).toBeVisible();

    await adminPage.getByRole('button', { name: 'Delete question' }).click();
    const prompt = adminPage.getByRole('dialog', { name: 'Delete question' });
    await expect(prompt).toBeVisible();
    await prompt.getByRole('button', { name: 'Cancel' }).click();
    await expect(prompt).toHaveCount(0);
    await expect(question).toBeVisible();
    expect(deleteCalls).toBe(0);

    await adminPage.getByRole('button', { name: 'Delete question' }).click();
    await prompt.getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(question).toHaveCount(0);
    expect(deleteCalls).toBe(1);
  });

  test('deleting a response template asks first', async ({ adminPage }) => {
    const procedure = await adminProcedure(adminPage, `Template Delete ${Date.now()}`);
    await apiPost(adminPage.context(), `/api/procedures/${procedure.id}/templates`, {
      title: 'Greeting',
      body: 'Dear customer,',
      language: 'en',
    });

    await adminPage.goto(`/procedures/${procedure.id}`);
    await adminPage.getByRole('button', { name: /^Templates/ }).click();
    await adminPage.getByRole('button', { name: 'Delete template: Greeting' }).click();
    const prompt = adminPage.getByRole('dialog', { name: 'Delete template' });
    await expect(prompt).toBeVisible();
    await prompt.getByRole('button', { name: 'Cancel' }).click();
    await expect(adminPage.getByRole('heading', { name: 'Greeting' })).toBeVisible();

    await adminPage.getByRole('button', { name: 'Delete template: Greeting' }).click();
    await prompt.getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(adminPage.getByRole('heading', { name: 'Greeting' })).toHaveCount(0);
    await expect(adminPage.getByText('Template deleted')).toBeVisible();
  });
});

test.describe('Procedure tags', () => {
  test('a duplicate tag name shows the server error and keeps the dialog open', async ({
    adminPage,
  }) => {
    const name = `Dup ${Date.now()}`.slice(0, 40);
    await apiPost(adminPage.context(), '/api/procedure-tags', { name, color: '#6366f1' });

    await adminPage.goto('/procedure-tags');
    await adminPage.getByRole('button', { name: '+ New Tag' }).click();
    await adminPage.getByPlaceholder('e.g. Security').fill(name);
    await adminPage.getByRole('button', { name: 'Create', exact: true }).click();

    await expect(adminPage.getByTestId('tag-name-error')).toHaveText(
      'The name has already been taken.',
    );
    await expect(adminPage.getByRole('heading', { name: 'New Tag' })).toBeVisible();
    await expect(adminPage.getByPlaceholder('e.g. Security')).toHaveValue(name);
    // Once, under the field: no error toast repeating it.
    await expect(adminPage.getByText('The name has already been taken.')).toHaveCount(1);
  });
});
