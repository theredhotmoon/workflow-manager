// frontend/e2e/procedures/detail-navigation.spec.js
import {
  createProcedure,
  getEmployeeIdByEmail,
  publishProcedureVersion,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

// A procedure titled `${base} v1`, `${base} v2` across two published versions.
async function createTwoVersions(context, base) {
  const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
  const procedure = await createProcedure(context, { title: `${base} v1`, ownerId: adminId });
  await publishProcedureVersion(context, procedure.id, { title: `${base} v2`, changeReason: 'v2' });
  return procedure;
}

const title = (page, name) => page.getByRole('heading', { level: 1, name, exact: true });

// In-app navigation, as a notification link or any router-link does: no page reload, so the
// router reuses the detail view when only :id changes.
async function navigateInApp(page, path) {
  await page.evaluate(
    (to) => document.querySelector('#app').__vue_app__.config.globalProperties.$router.push(to),
    path,
  );
}

test.describe('Procedure detail navigation', () => {
  test('moving to another procedure without a reload shows that procedure', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const first = await createTwoVersions(context, 'Nav First');
    const second = await createTwoVersions(context, 'Nav Second');

    await adminPage.goto(`/procedures/${first.id}`);
    await expect(title(adminPage, 'Nav First v2')).toBeVisible();

    await navigateInApp(adminPage, `/procedures/${second.id}`);

    await expect(title(adminPage, 'Nav Second v2')).toBeVisible();
    await expect(title(adminPage, 'Nav First v2')).toHaveCount(0);
  });

  test('an old version viewed on one procedure is not shown for the next one', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const first = await createTwoVersions(context, 'Nav Cache First');
    const second = await createTwoVersions(context, 'Nav Cache Second');

    // Viewing v1 of the first procedure caches it under version 1.
    await adminPage.goto(`/procedures/${first.id}/version/1`);
    await expect(title(adminPage, 'Nav Cache First v1')).toBeVisible();

    await navigateInApp(adminPage, `/procedures/${second.id}/version/1`);

    await expect(title(adminPage, 'Nav Cache Second v1')).toBeVisible();
    await expect(title(adminPage, 'Nav Cache First v1')).toHaveCount(0);
  });
});
