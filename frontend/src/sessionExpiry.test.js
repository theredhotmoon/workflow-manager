import { expect, test } from 'vitest';
import { DEACTIVATED_MESSAGE, installSessionExpiry, safeRedirect } from './sessionExpiry.js';

function setup({
  user = { id: 1 },
  route = 'dashboard',
  fullPath = '/schedule/2026-10?group=2',
} = {}) {
  let onRejected;
  const api = {
    interceptors: {
      response: {
        use: (_ok, fail) => {
          onRejected = fail;
          return 0;
        },
      },
    },
  };
  const currentUser = { value: user };
  const pushes = [];
  const router = {
    currentRoute: { value: { name: route, fullPath } },
    push: (to) => {
      pushes.push(to);
      return Promise.resolve();
    },
  };
  const toasts = [];
  installSessionExpiry(api, {
    currentUser,
    router,
    showToast: (message, type) => toasts.push({ message, type }),
  });
  const fail = async (status, url, message = 'Unauthenticated.') => {
    const error = { response: { status, data: { message } }, config: { url } };
    await expect(onRejected(error)).rejects.toBe(error);
    await Promise.resolve();
  };
  return { currentUser, pushes, toasts, fail };
}

test('a 401 on an API call while logged in clears the user and goes to login', async () => {
  const s = setup();
  await s.fail(401, '/api/schedule');
  expect(s.currentUser.value).toBeNull();
  expect(s.pushes).toEqual([{ name: 'login', query: { redirect: '/schedule/2026-10?group=2' } }]);
  expect(s.toasts).toEqual([]);
});

test('a deactivated account is told so', async () => {
  const s = setup();
  await s.fail(401, '/api/schedule', DEACTIVATED_MESSAGE);
  expect(s.pushes).toEqual([{ name: 'login', query: { redirect: '/schedule/2026-10?group=2' } }]);
  expect(s.toasts).toEqual([{ message: DEACTIVATED_MESSAGE, type: 'error' }]);
});

test('only the first of several failing calls navigates', async () => {
  const s = setup();
  await s.fail(401, '/api/schedule');
  await s.fail(401, '/api/alarms/unread-count');
  expect(s.pushes).toHaveLength(1);
});

test('many calls failing at once (e.g. the alarm poll and the view) navigate only once', async () => {
  const s = setup();
  // Fired together: none waits for the previous one's navigation.
  await Promise.all([
    s.fail(401, '/api/schedule'),
    s.fail(419, '/api/alarms/latest'),
    s.fail(401, '/api/alarms/unread-count'),
  ]);
  expect(s.pushes).toHaveLength(1);
  expect(s.pushes[0].query.redirect).toBe('/schedule/2026-10?group=2');
});

test('a 419 (CSRF token gone with the session) is handled like a 401', async () => {
  const s = setup({ fullPath: '/delegations' });
  await s.fail(419, '/api/delegations/3/self-register');
  expect(s.currentUser.value).toBeNull();
  expect(s.pushes).toEqual([{ name: 'login', query: { redirect: '/delegations' } }]);
});

test('leaves the /api/user probe, login, logout and the CSRF cookie to their own handling', async () => {
  for (const url of ['/api/user', '/api/login', '/api/logout', '/sanctum/csrf-cookie']) {
    const s = setup();
    await s.fail(401, url);
    expect(s.pushes).toEqual([]);
    expect(s.currentUser.value).not.toBeNull();
  }
});

test('does nothing when not logged in or already on the login page', async () => {
  const guest = setup({ user: null });
  await guest.fail(401, '/api/schedule');
  expect(guest.pushes).toEqual([]);
  const onLogin = setup({ route: 'login' });
  await onLogin.fail(419, '/api/schedule');
  expect(onLogin.pushes).toEqual([]);
});

test('ignores errors that do not mean the session is gone (429, 403, 500)', async () => {
  const s = setup();
  for (const status of [429, 403, 500]) await s.fail(status, '/api/schedule');
  expect(s.pushes).toEqual([]);
  expect(s.currentUser.value).not.toBeNull();
});

test('after signing in, only goes back to a page of this app', () => {
  expect(safeRedirect('/schedule/2026-10?group=2')).toBe('/schedule/2026-10?group=2');
  expect(safeRedirect('/procedures/5#faq')).toBe('/procedures/5#faq');
  for (const bad of [
    undefined,
    '',
    'schedule',
    '//evil.example',
    'https://evil.example/',
    '/\\evil.example',
    '/login?redirect=/x',
  ]) {
    expect(safeRedirect(bad)).toBeNull();
  }
});
