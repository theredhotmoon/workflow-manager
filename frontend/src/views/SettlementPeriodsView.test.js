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
});
