import { render, screen, waitFor } from '@testing-library/vue';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';
import { server } from '../mocks/server.js';
import TeamAvailabilityView from './TeamAvailabilityView.vue';

describe('TeamAvailabilityView', () => {
  it('lists managed team members and shows an empty state with no teams', async () => {
    server.use(http.get('/api/my-managed-teams', () => HttpResponse.json([])));

    render(TeamAvailabilityView, {
      global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } },
    });

    await waitFor(() =>
      expect(screen.getByText(/you don.t manage any teams/i)).toBeInTheDocument(),
    );
  });

  it('shows team members and selecting one renders the availability editor', async () => {
    server.use(
      http.get('/api/my-managed-teams', () =>
        HttpResponse.json([
          {
            id: 1,
            name: 'Team A',
            enum: 'team_a',
            users: [{ id: 42, name: 'Jamie Employee', email: 'jamie@example.com' }],
          },
        ]),
      ),
      http.get('/api/user-availabilities/42/month', () => HttpResponse.json([])),
    );

    render(TeamAvailabilityView, {
      global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } },
    });

    await waitFor(() => expect(screen.getByText('Jamie Employee')).toBeInTheDocument());
    await screen.getByText('Jamie Employee').click();

    await waitFor(() => expect(screen.getByText(/click a day to add/i)).toBeInTheDocument());
  });
});
