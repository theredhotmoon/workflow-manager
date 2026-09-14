import { fireEvent, render, screen, waitFor } from '@testing-library/vue';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';
import { server } from '../mocks/server.js';
import SettlementPeriodsView from './SettlementPeriodsView.vue';

describe('SettlementPeriodsView', () => {
  it('loads groups and shows the report for the selected group', async () => {
    server.use(
      http.get('/api/shift-groups', () =>
        HttpResponse.json([{ id: 1, name: 'Main', is_default: true }]),
      ),
      http.get('/api/settlement-periods', ({ request }) => {
        const url = new URL(request.url);
        expect(url.searchParams.get('shift_group_id')).toBe('1');
        expect(url.searchParams.get('offset')).toBe('0');
        return HttpResponse.json({
          period: { period_number: 5, start: '2026-09-01', end: '2026-09-30' },
          weeks: [
            { start: '2026-09-01', end: '2026-09-07', cap_hours: 40 },
            { start: '2026-09-08', end: '2026-09-14', cap_hours: 40 },
          ],
          employees: [
            {
              id: 1,
              name: 'Jamie Employee',
              weekly_hours: [44, 20],
              total_hours: 64,
              over_cap_weeks: [0],
            },
          ],
        });
      }),
    );

    render(SettlementPeriodsView, {
      global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } },
    });

    await waitFor(() => expect(screen.getByText('Jamie Employee')).toBeInTheDocument());
    expect(screen.getByText('2026-09-01')).toBeInTheDocument();
    expect(screen.getByText('64')).toBeInTheDocument();
  });

  it('steps to the previous period on prev click', async () => {
    server.use(
      http.get('/api/shift-groups', () =>
        HttpResponse.json([{ id: 1, name: 'Main', is_default: true }]),
      ),
      http.get('/api/settlement-periods', ({ request }) => {
        const url = new URL(request.url);
        const offset = url.searchParams.get('offset');
        return HttpResponse.json({
          period: {
            period_number: offset === '-1' ? 4 : 5,
            start: offset === '-1' ? '2026-08-01' : '2026-09-01',
            end: offset === '-1' ? '2026-08-31' : '2026-09-30',
          },
          weeks: [],
          employees: [],
        });
      }),
    );

    render(SettlementPeriodsView, {
      global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } },
    });

    await waitFor(() => expect(screen.getByText(/2026-09-01/)).toBeInTheDocument());
    await fireEvent.click(screen.getByRole('button', { name: /prev/i }));
    await waitFor(() => expect(screen.getByText(/2026-08-01/)).toBeInTheDocument());
  });

  it('renders the streak status column for a mid-streak and a resting employee', async () => {
    server.use(
      http.get('/api/shift-groups', () =>
        HttpResponse.json([{ id: 1, name: 'Main', is_default: true }]),
      ),
      http.get('/api/settlement-periods', () =>
        HttpResponse.json({
          period: { period_number: 5, start: '2026-09-01', end: '2026-09-30' },
          weeks: [{ start: '2026-09-01', end: '2026-09-07', cap_hours: 40 }],
          employees: [
            {
              id: 1,
              name: 'Jamie Employee',
              weekly_hours: [16],
              total_hours: 16,
              over_cap_weeks: [],
              streak_status: [
                { shift_type: 'night', current_count: 2, streak_length: 3, resting_until: null },
              ],
            },
            {
              id: 2,
              name: 'Sam Resting',
              weekly_hours: [0],
              total_hours: 0,
              over_cap_weeks: [],
              streak_status: [
                {
                  shift_type: 'night',
                  current_count: 0,
                  streak_length: 3,
                  resting_until: '2026-09-05',
                },
              ],
            },
          ],
        }),
      ),
    );

    render(SettlementPeriodsView, {
      global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } },
    });

    await waitFor(() => expect(screen.getByText('Jamie Employee')).toBeInTheDocument());
    expect(screen.getByText('night: 2/3')).toBeInTheDocument();
    expect(screen.getByText('night: resting until 2026-09-05')).toBeInTheDocument();
  });
});
