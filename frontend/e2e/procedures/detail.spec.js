import {
  createProcedure,
  getEmployeeIdByEmail,
  publishProcedureVersion,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

// A procedure titled `${base} v1`, `${base} v2`, `${base} v3` across three published versions.
async function createVersionedProcedure(context, base) {
  const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
  const procedure = await createProcedure(context, { title: `${base} v1`, ownerId: adminId });
  await publishProcedureVersion(context, procedure.id, { title: `${base} v2`, changeReason: 'v2' });
  await publishProcedureVersion(context, procedure.id, { title: `${base} v3`, changeReason: 'v3' });
  return procedure;
}

const title = (page, name) => page.getByRole('heading', { level: 1, name, exact: true });
const isVersion = (procedureId, version) => (url) =>
  url.pathname === `/api/procedures/${procedureId}/versions/${version}`;

test.describe('Procedure detail', () => {
  test('stepping back two versions quickly shows the version the user stopped on', async ({
    adminPage,
  }) => {
    const procedure = await createVersionedProcedure(adminPage.context(), 'Versions Race');
    const v2 = await holdRequests(adminPage, isVersion(procedure.id, 2));

    await adminPage.goto(`/procedures/${procedure.id}`);
    await expect(title(adminPage, 'Versions Race v3')).toBeVisible();
    await adminPage.getByTitle('Previous version').click();
    await v2.seen;
    await adminPage.getByTitle('Previous version').click();
    await expect(title(adminPage, 'Versions Race v1')).toBeVisible();

    await v2.releaseAndSettle();

    await expect(title(adminPage, 'Versions Race v1')).toBeVisible();
    await expect(title(adminPage, 'Versions Race v2')).toHaveCount(0);
  });

  test('going back to the latest version while an older one loads stays on the latest', async ({
    adminPage,
  }) => {
    const procedure = await createVersionedProcedure(adminPage.context(), 'Latest Race');
    const v2 = await holdRequests(adminPage, isVersion(procedure.id, 2));

    await adminPage.goto(`/procedures/${procedure.id}`);
    await expect(title(adminPage, 'Latest Race v3')).toBeVisible();
    await adminPage.getByTitle('Previous version').click();
    await v2.seen;
    await adminPage.getByTitle('Next version').click();
    await expect(adminPage.getByTitle('Next version')).toBeDisabled(); // back on the latest

    await v2.releaseAndSettle();

    await expect(title(adminPage, 'Latest Race v3')).toBeVisible();
    await expect(title(adminPage, 'Latest Race v2')).toHaveCount(0);
  });

  test('a cached version shown while an older one loads stays on screen', async ({ adminPage }) => {
    const procedure = await createVersionedProcedure(adminPage.context(), 'Cache Race');

    await adminPage.goto(`/procedures/${procedure.id}`);
    await expect(title(adminPage, 'Cache Race v3')).toBeVisible();
    await adminPage.getByTitle('Previous version').click();
    await expect(title(adminPage, 'Cache Race v2')).toBeVisible(); // v2 is now cached

    const v1 = await holdRequests(adminPage, isVersion(procedure.id, 1));
    await adminPage.getByTitle('Previous version').click();
    await v1.seen;
    await adminPage.getByTitle('Next version').click(); // back to v2, served from the cache
    await expect(adminPage.getByText('Viewing v2')).toBeVisible();

    await v1.releaseAndSettle();

    await expect(title(adminPage, 'Cache Race v2')).toBeVisible();
    await expect(title(adminPage, 'Cache Race v1')).toHaveCount(0);
  });

  test('a compare dialog still loading does not open after the user switched versions', async ({
    adminPage,
  }) => {
    const procedure = await createVersionedProcedure(adminPage.context(), 'Diff Race');
    const firstV2 = await holdRequests(adminPage, isVersion(procedure.id, 2), { times: 1 });

    await adminPage.goto(`/procedures/${procedure.id}`);
    await expect(title(adminPage, 'Diff Race v3')).toBeVisible();
    await adminPage.getByTitle('Compare v2 → v3').click();
    await firstV2.seen;
    await adminPage.getByTitle('Previous version').click();
    await expect(title(adminPage, 'Diff Race v2')).toBeVisible();
    await expect(adminPage.getByText('Viewing v2')).toBeVisible();

    await firstV2.releaseAndSettle();

    await expect(title(adminPage, 'Diff Race v2')).toBeVisible();
    await expect(adminPage.getByRole('dialog', { name: /Changes: v2 → v3/ })).toHaveCount(0);
  });
});
