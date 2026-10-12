import { expect, test } from '../fixtures/auth.js';

// L18: with several shift groups the dashboard shows the crew of the shift on now in every
// group, each labelled with its group. The live answer is stubbed: making real shifts "now"
// would depend on the clock and leak into other specs' today.
const ACTIVE_SHIFTS = [
  {
    id: 900001,
    date: '2026-10-09',
    shift_number: 1,
    shift_group_id: 1,
    shift_group_name: 'L18 South',
    start_time: '22:00',
    end_time: '12:00',
    is_overnight: true,
    crew: [{ id: 990001, name: 'Crew Nightowl' }],
  },
  {
    id: 900002,
    date: '2026-10-09',
    shift_number: 2,
    shift_group_id: 2,
    shift_group_name: 'L18 North',
    start_time: '06:00',
    end_time: '14:00',
    is_overnight: false,
    crew: [],
  },
];

test.describe('Dashboard: current crew', () => {
  test('lists the shift on now in every group, with its crew', async ({ employeePage }) => {
    await employeePage.route(
      (url) => url.pathname === '/api/current-shift',
      (route) =>
        route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ active_shift: ACTIVE_SHIFTS[0], active_shifts: ACTIVE_SHIFTS }),
        }),
    );

    await employeePage.goto('/dashboard');

    const crew = employeePage.getByTestId('current-crew');
    await expect(crew.getByRole('heading', { name: /Current Crew on Shift/ })).toBeVisible();
    await expect(crew.getByText('2 shifts on now')).toBeVisible();

    const blocks = crew.getByTestId('current-crew-shift');
    await expect(blocks).toHaveCount(2);
    await expect(blocks.nth(0)).toContainText('L18 South');
    await expect(blocks.nth(0)).toContainText('Shift 1 — 22:00–12:00');
    await expect(blocks.nth(0)).toContainText('Crew Nightowl');
    await expect(blocks.nth(1)).toContainText('L18 North');
    await expect(blocks.nth(1)).toContainText('No one is assigned to this shift.');
  });

  test('shows no crew panel when nothing is on', async ({ employeePage }) => {
    await employeePage.route(
      (url) => url.pathname === '/api/current-shift',
      (route) =>
        route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ active_shift: null, active_shifts: [] }),
        }),
    );

    await employeePage.goto('/dashboard');
    await expect(employeePage.getByRole('heading', { name: "Today's Schedule" })).toBeVisible();
    await expect(employeePage.getByTestId('current-crew')).toHaveCount(0);
  });
});
