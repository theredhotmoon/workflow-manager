import { createProcedure, getEmployeeIdByEmail } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

const tile = (page, title) => page.getByRole('heading', { level: 3, name: title, exact: true });

test.describe('Procedures list', () => {
  test('a slow initial load does not replace the search the user typed', async ({ adminPage }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    await createProcedure(context, { title: 'RaceList Alpha', ownerId: adminId });
    await createProcedure(context, { title: 'RaceList Beta', ownerId: adminId });

    const initial = await holdRequests(
      adminPage,
      (url) => url.pathname === '/api/procedures' && !url.searchParams.has('search'),
      { times: 1 },
    );

    await adminPage.goto('/procedures');
    await initial.seen;
    await adminPage.getByPlaceholder('Search procedures...').fill('RaceList Alpha');
    await expect(tile(adminPage, 'RaceList Alpha')).toBeVisible();

    await initial.releaseAndSettle();

    await expect(tile(adminPage, 'RaceList Alpha')).toBeVisible();
    await expect(tile(adminPage, 'RaceList Beta')).toHaveCount(0);
    await expect(adminPage.getByPlaceholder('Search procedures...')).toHaveValue('RaceList Alpha');
  });

  // Review Focus 5.
  test('refining the search before the broader search answers keeps the refined results', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    await createProcedure(context, { title: 'Refine Alpha', ownerId: adminId });
    await createProcedure(context, { title: 'Refine Beta', ownerId: adminId });

    await adminPage.goto('/procedures');
    await expect(tile(adminPage, 'Refine Beta')).toBeVisible();
    await adminPage.waitForLoadState('networkidle');

    const broad = await holdRequests(
      adminPage,
      (url) => url.pathname === '/api/procedures' && url.searchParams.get('search') === 'Refine',
    );
    const search = adminPage.getByPlaceholder('Search procedures...');
    await search.fill('Refine');
    await broad.seen;
    await search.fill('Refine Alpha');
    await expect(tile(adminPage, 'Refine Beta')).toHaveCount(0); // was visible above
    await expect(tile(adminPage, 'Refine Alpha')).toBeVisible();

    await broad.releaseAndSettle();

    await expect(tile(adminPage, 'Refine Alpha')).toBeVisible();
    await expect(tile(adminPage, 'Refine Beta')).toHaveCount(0);
  });

  test('the updating indicator stays on while the newest search is still in flight', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const adminId = await getEmployeeIdByEmail(context, 'admin@shiftchanger.com');
    await createProcedure(context, { title: 'Spinner Alpha', ownerId: adminId });
    await createProcedure(context, { title: 'Spinner Beta', ownerId: adminId });

    await adminPage.goto('/procedures');
    await expect(tile(adminPage, 'Spinner Beta')).toBeVisible();
    await adminPage.waitForLoadState('networkidle');

    const older = await holdRequests(
      adminPage,
      (url) => url.pathname === '/api/procedures' && url.searchParams.get('search') === 'Spinner',
    );
    const newer = await holdRequests(
      adminPage,
      (url) =>
        url.pathname === '/api/procedures' && url.searchParams.get('search') === 'Spinner Alpha',
    );
    const search = adminPage.getByPlaceholder('Search procedures...');
    await search.fill('Spinner');
    await older.seen;
    await search.fill('Spinner Alpha');
    await newer.seen;

    // The older search answers first; the newer one is still pending, so the view is still updating.
    await older.releaseAndSettle();
    await adminPage.waitForTimeout(400); // longer than the indicator's 200ms fade-out
    const indicator = adminPage.getByText('Updating results…');
    await expect(indicator).toBeVisible();
    await expect(indicator.locator('..')).toHaveCSS('opacity', '1');
    await expect(tile(adminPage, 'Spinner Beta')).toBeVisible(); // old results still shown

    await newer.releaseAndSettle();

    await expect(indicator.locator('..')).toHaveCSS('opacity', '0');
    await expect(tile(adminPage, 'Spinner Alpha')).toBeVisible();
    await expect(tile(adminPage, 'Spinner Beta')).toHaveCount(0);
  });
});
