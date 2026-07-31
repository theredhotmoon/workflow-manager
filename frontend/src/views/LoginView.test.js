import { fireEvent, render, screen, waitFor } from '@testing-library/vue';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { server } from '../mocks/server.js';
import LoginView from './LoginView.vue';

function renderLoginView() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', redirect: '/login' },
      { path: '/login', name: 'login', component: LoginView },
      { path: '/dashboard', name: 'dashboard', component: { template: '<div>Dashboard</div>' } },
    ],
  });

  const utils = render(LoginView, { global: { plugins: [router] } });
  return { router, ...utils };
}

describe('LoginView', () => {
  it('logs in successfully and navigates to the dashboard', async () => {
    server.use(http.post('/api/login', () => HttpResponse.json({ message: 'Login successful' })));

    const { router } = renderLoginView();
    await router.isReady();

    await fireEvent.update(screen.getByLabelText(/email address/i), 'user@example.com');
    await fireEvent.update(screen.getByLabelText(/password/i), 'password');
    await fireEvent.click(screen.getByRole('button', { name: /sign in/i }));

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /signed in/i })).toBeInTheDocument(),
    );
    await waitFor(() => expect(router.currentRoute.value.name).toBe('dashboard'), {
      timeout: 2000,
    });
  });

  it('shows an error and stays on the login page when credentials are invalid', async () => {
    server.use(
      http.post('/api/login', () =>
        HttpResponse.json(
          { message: 'The provided credentials do not match our records.' },
          { status: 422 },
        ),
      ),
    );

    const { router } = renderLoginView();
    await router.isReady();

    await fireEvent.update(screen.getByLabelText(/email address/i), 'user@example.com');
    await fireEvent.update(screen.getByLabelText(/password/i), 'wrong-password');
    await fireEvent.click(screen.getByRole('button', { name: /sign in/i }));

    await waitFor(() =>
      expect(
        screen.getByText('The provided credentials do not match our records.'),
      ).toBeInTheDocument(),
    );
    expect(router.currentRoute.value.name).toBe('login');
  });
});
