import { fireEvent, render, screen, waitFor } from '@testing-library/vue';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';
import { server } from '../mocks/server.js';
import AvailabilityEditor from './AvailabilityEditor.vue';

describe('AvailabilityEditor', () => {
  it('loads entries scoped to the target user for the current month and shows a manager-set badge', async () => {
    server.use(
      http.get('/api/user-availabilities/42/month', () =>
        HttpResponse.json([
          {
            id: 1,
            user_id: 42,
            created_by_user_id: 7,
            created_by_name: 'Alex Manager',
            type: 'unavailable',
            shift_type: null,
            start_date: new Date().toISOString().slice(0, 10),
            end_date: new Date().toISOString().slice(0, 10),
            start_time: null,
            end_time: null,
            note: 'Doctor',
          },
        ]),
      ),
    );

    render(AvailabilityEditor, { props: { targetUserId: 42 } });

    await waitFor(() => expect(screen.getByText(/Alex Manager/)).toBeInTheDocument());
  });

  it('submits a shift-type preference for the target user', async () => {
    server.use(
      http.get('/api/user-availabilities/42/month', () => HttpResponse.json([])),
      http.post('/api/user-availabilities', async ({ request }) => {
        const body = await request.json();
        expect(body.user_id).toBe(42);
        expect(body.type).toBe('preferred');
        expect(body.shift_type).toBe('night');
        return HttpResponse.json(
          {
            id: 2,
            user_id: 42,
            created_by_user_id: 42,
            created_by_name: null,
            type: 'preferred',
            shift_type: 'night',
            start_date: body.start_date,
            end_date: body.end_date,
            start_time: null,
            end_time: null,
            note: null,
          },
          { status: 201 },
        );
      }),
    );

    render(AvailabilityEditor, { props: { targetUserId: 42 } });

    const todayStr = new Date().toISOString().slice(0, 10);
    const todayCell = await screen.findByTestId(`calendar-day-${todayStr}`);
    await fireEvent.click(todayCell);
    await fireEvent.click(screen.getByRole('button', { name: /preferred/i }));
    await fireEvent.update(screen.getByLabelText(/shift type/i), 'night');
    await fireEvent.click(screen.getByRole('button', { name: /^save$/i }));

    await waitFor(() => expect(screen.queryByText(/add availability/i)).not.toBeInTheDocument());
  });
});
