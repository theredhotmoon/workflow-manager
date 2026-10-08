import { archiveDelegation, createDelegation } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { holdRequests } from '../fixtures/hold.js';

// Delegation dates in 2097-08 are reserved for this file.
test.describe('Delegation archive', () => {
  test('a slow result for the previous destination does not replace the chosen one', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const north = await createDelegation(context, {
      name: 'Arch Race North',
      destination: 'Archtown North',
      start: '2097-08-05',
      end: '2097-08-06',
    });
    const south = await createDelegation(context, {
      name: 'Arch Race South',
      destination: 'Archtown South',
      start: '2097-08-12',
      end: '2097-08-13',
    });
    await archiveDelegation(context, north.id);
    await archiveDelegation(context, south.id);

    await adminPage.goto('/delegation-archive');
    const northCard = adminPage.getByRole('heading', { name: 'Arch Race North' });
    const southCard = adminPage.getByRole('heading', { name: 'Arch Race South' });
    await expect(northCard).toBeVisible();
    await adminPage.waitForLoadState('networkidle');

    const northLoad = await holdRequests(
      adminPage,
      (url) =>
        url.pathname === '/api/delegation-archive' &&
        url.searchParams.get('destination') === 'Archtown North',
    );
    const destination = adminPage
      .locator('select')
      .filter({ has: adminPage.locator('option', { hasText: 'All destinations' }) });
    await destination.selectOption('Archtown North');
    await northLoad.seen;
    await destination.selectOption('Archtown South');
    await expect(northCard).toHaveCount(0); // was visible above; the South result has rendered
    await expect(southCard).toBeVisible();

    await northLoad.releaseAndSettle();

    await expect(southCard).toBeVisible();
    await expect(northCard).toHaveCount(0);
  });
});
