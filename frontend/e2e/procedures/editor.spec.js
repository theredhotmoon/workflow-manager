import { createProcedure, createUser, getEmployeeIdByEmail } from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, loginAs, STATEFUL_ORIGIN, test } from '../fixtures/auth.js';

const TITLE_PLACEHOLDER = 'e.g. Password Reset via Database';

async function adminProcedure(page, title) {
  const context = page.context();
  const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
  return createProcedure(context, { title, ownerId: adminId });
}

// Opens the editor for `procedure` and waits until it has loaded the procedure into the form.
async function openEditor(page, procedure) {
  await page.goto(`/procedures/${procedure.id}`);
  await page.getByRole('button', { name: 'Edit', exact: true }).click();
  const editor = page.getByRole('dialog', { name: 'Edit Procedure' });
  await expect(editor.getByPlaceholder(TITLE_PLACEHOLDER)).toHaveValue(procedure.title);
  return editor;
}

const draftUrl = (id) => (url) => url.pathname === `/api/procedures/${id}/draft`;

test.describe('Procedure editor: who may edit', () => {
  test('a non-admin owner sees no Edit on their own procedure; an admin does', async ({
    adminPage,
    browser,
  }) => {
    const admin = adminPage.context();
    const stamp = Date.now();
    const email = `owner-${stamp}@example.test`;
    const owner = await createUser(admin, { name: `Owner Throwaway ${stamp}`, email });
    try {
      const procedure = await createProcedure(admin, {
        title: `Owned Procedure ${stamp}`,
        ownerId: owner.id,
      });

      const ownerContext = await browser.newContext({ extraHTTPHeaders: STATEFUL_ORIGIN });
      try {
        await loginAs(ownerContext, email, 'password123');
        const page = await ownerContext.newPage();
        await page.goto(`/procedures/${procedure.id}`);
        await expect(
          page.getByRole('heading', { level: 1, name: procedure.title, exact: true }),
        ).toBeVisible();
        await expect(page.getByRole('button', { name: 'Edit', exact: true })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Continue Draft' })).toHaveCount(0);

        await page.goto('/procedures');
        await expect(page.getByRole('link', { name: new RegExp(procedure.title) })).toBeVisible();
        await expect(page.getByRole('button', { name: '+ New Procedure' })).toHaveCount(0);
      } finally {
        await ownerContext.close();
      }

      await adminPage.goto(`/procedures/${procedure.id}`);
      await expect(adminPage.getByRole('button', { name: 'Edit', exact: true })).toBeVisible();
    } finally {
      const res = await admin.request.post(`/api/users/${owner.id}/deactivate`, {
        headers: await getXsrfHeader(admin),
      });
      expect(res.ok()).toBe(true);
    }
  });
});

test.describe('Procedure editor: save errors', () => {
  test('a 422 on Save Draft shows the field error and keeps the editor and its content', async ({
    adminPage,
  }) => {
    const procedure = await adminProcedure(adminPage, `Draft 422 ${Date.now()}`);
    const editor = await openEditor(adminPage, procedure);
    await editor.getByPlaceholder(TITLE_PLACEHOLDER).fill('Kept title');

    await adminPage.route(draftUrl(procedure.id), (route) =>
      route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'The title has already been taken.',
          errors: { title: ['The title has already been taken.'] },
        }),
      }),
    );
    await editor.getByRole('button', { name: 'Save Draft' }).click();

    await expect(editor.getByTestId('title-error')).toHaveText('The title has already been taken.');
    await expect(editor.getByRole('alert')).toContainText('The title has already been taken.');
    await expect(editor).toBeVisible();
    await expect(editor.getByPlaceholder(TITLE_PLACEHOLDER)).toHaveValue('Kept title');
    await expect(adminPage.getByText('Draft saved')).toHaveCount(0);
  });

  test('a 500 on Save Draft shows a message; the retry clears it and says "Draft saved"', async ({
    adminPage,
  }) => {
    const procedure = await adminProcedure(adminPage, `Draft 500 ${Date.now()}`);
    const editor = await openEditor(adminPage, procedure);
    await editor.getByPlaceholder(TITLE_PLACEHOLDER).fill('Retried title');

    const isDraft = draftUrl(procedure.id); // unroute matches the same function
    await adminPage.route(isDraft, (route) =>
      route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Server Error' }),
      }),
    );
    await editor.getByRole('button', { name: 'Save Draft' }).click();
    await expect(editor.getByRole('alert')).toHaveText('Could not save, try again (500).');
    await expect(editor).toBeVisible();

    await adminPage.unroute(isDraft);
    await editor.getByRole('button', { name: 'Save Draft' }).click();
    await expect(adminPage.getByText('Draft saved')).toBeVisible();
    await expect(editor.getByRole('alert')).toHaveCount(0);
    await expect(editor.getByPlaceholder(TITLE_PLACEHOLDER)).toHaveValue('Retried title');
  });

  test('a rejected change reason is shown in the publish dialog', async ({ adminPage }) => {
    const procedure = await adminProcedure(adminPage, `Publish 422 ${Date.now()}`);
    const editor = await openEditor(adminPage, procedure);
    const isPublish = (url) => url.pathname === `/api/procedures/${procedure.id}/publish`;
    await adminPage.route(isPublish, (route) =>
      route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'The change reason must not be greater than 1000 characters.',
          errors: {
            change_reason: ['The change reason must not be greater than 1000 characters.'],
          },
        }),
      }),
    );

    await editor.getByRole('button', { name: 'Publish v2' }).click();
    const publish = adminPage.getByRole('dialog', { name: 'Publish v2' });
    await publish.getByRole('textbox').fill('Too long, says the server');
    await publish.getByRole('button', { name: 'Publish v2' }).click();

    await expect(publish.getByTestId('change-reason-error')).toHaveText(
      'The change reason must not be greater than 1000 characters.',
    );
    await expect(publish.getByRole('textbox')).toHaveValue('Too long, says the server');
    await expect(adminPage.getByText(/^Published/)).toHaveCount(0);
  });

  test('a failed create keeps the new-procedure editor open with its title', async ({
    adminPage,
  }) => {
    await adminPage.goto('/procedures');
    await adminPage.getByRole('button', { name: '+ New Procedure' }).click();
    const editor = adminPage.getByRole('dialog', { name: 'New Procedure' });
    await editor.getByPlaceholder(TITLE_PLACEHOLDER).fill('Never created');
    await adminPage.route(
      (url) => url.pathname === '/api/procedures',
      (route) =>
        route.request().method() === 'POST'
          ? route.fulfill({
              status: 403,
              contentType: 'application/json',
              body: JSON.stringify({ message: 'This action is unauthorized.' }),
            })
          : route.fallback(),
    );

    await editor.getByRole('button', { name: 'Create' }).click();

    await expect(editor.getByRole('alert')).toHaveText('This action is unauthorized.');
    await expect(editor.getByPlaceholder(TITLE_PLACEHOLDER)).toHaveValue('Never created');
  });
});

test.describe('Procedure editor: Escape and unsaved changes', () => {
  test('Escape on a confirm over the editor closes only the confirm', async ({ adminPage }) => {
    const procedure = await adminProcedure(adminPage, `Escape Stack ${Date.now()}`);
    const editor = await openEditor(adminPage, procedure);
    await editor.getByPlaceholder(TITLE_PLACEHOLDER).fill('Unsaved title');

    await editor.getByRole('button', { name: 'Delete Procedure' }).click();
    const confirmDialog = adminPage.getByRole('dialog', { name: 'Delete Procedure' });
    await expect(confirmDialog).toBeVisible();
    await adminPage.keyboard.press('Escape');

    await expect(confirmDialog).toHaveCount(0);
    // A closing editor stays in the DOM for its 200ms fade-out; look after that.
    await adminPage.waitForTimeout(400);
    await expect(editor).toBeVisible();
    await expect(editor.getByPlaceholder(TITLE_PLACEHOLDER)).toHaveValue('Unsaved title');
  });

  test('closing an editor with unsaved changes asks first; a clean one just closes', async ({
    adminPage,
  }) => {
    const procedure = await adminProcedure(adminPage, `Dirty Close ${Date.now()}`);
    const editor = await openEditor(adminPage, procedure);
    const discardPrompt = adminPage.getByRole('dialog', { name: 'Discard unsaved changes?' });

    // A clean editor closes on Escape without a prompt.
    await adminPage.keyboard.press('Escape');
    await expect(editor).toHaveCount(0);
    await expect(discardPrompt).toHaveCount(0);

    await adminPage.getByRole('button', { name: 'Edit', exact: true }).click();
    await expect(editor.getByPlaceholder(TITLE_PLACEHOLDER)).toHaveValue(procedure.title);
    await editor.getByPlaceholder(TITLE_PLACEHOLDER).fill('Typed but not saved');

    // Escape asks; Escape on the prompt keeps editing.
    await adminPage.keyboard.press('Escape');
    await expect(discardPrompt).toBeVisible();
    await adminPage.keyboard.press('Escape');
    await expect(discardPrompt).toHaveCount(0);
    await adminPage.waitForTimeout(400); // past the editor's 200ms fade-out, had it closed
    await expect(editor).toBeVisible();
    await expect(editor.getByPlaceholder(TITLE_PLACEHOLDER)).toHaveValue('Typed but not saved');

    // Cancel asks too; cancelling the prompt keeps the content.
    await editor.getByRole('button', { name: 'Cancel', exact: true }).click();
    await discardPrompt.getByRole('button', { name: 'Cancel' }).click();
    await expect(editor.getByPlaceholder(TITLE_PLACEHOLDER)).toHaveValue('Typed but not saved');

    // The ✕ asks as well; discarding closes the editor.
    await editor.getByRole('button', { name: 'Close editor' }).click();
    await discardPrompt.getByRole('button', { name: 'Discard changes' }).click();
    await expect(editor).toHaveCount(0);
  });
});
