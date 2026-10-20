// @vitest-environment jsdom
// The navigation guard: who may open which route, and what a failed /api/user probe means.
// The real views are imported (the Vue plugin compiles them); only the user probe is faked.
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { isNavigationFailure, NavigationFailureType } from 'vue-router';
import { fetchCurrentUser } from '../fetchCurrentUser.js';
import router, { currentUser } from './index.js';

vi.mock('../fetchCurrentUser.js', async (importOriginal) => ({
  ...(await importOriginal()),
  fetchCurrentUser: vi.fn(),
}));

const ADMIN = { id: 1, name: 'Admin', role: 'admin' };
const MAINTAINER = { id: 2, name: 'Maintainer', role: 'schedule_maintainer' };
const EMPLOYEE = { id: 3, name: 'Employee', role: 'employee' };
const probe = vi.mocked(fetchCurrentUser);
const status = (code) => ({ response: { status: code } });

const signedInAs = (user) => probe.mockResolvedValue(user);
const signedOut = () => probe.mockRejectedValue(status(401));
const current = () => router.currentRoute.value;

beforeEach(() => {
  probe.mockReset();
});

describe('signed out', () => {
  test('the root address goes to the login page', async () => {
    signedOut();
    await router.push('/');
    expect(current().name).toBe('login');
    expect(currentUser.value).toBeNull();
  });

  test('a protected page sends to login and remembers where to come back to', async () => {
    signedOut();
    await router.push('/schedule/2027-03');
    expect(current().name).toBe('login');
    expect(current().query.redirect).toBe('/schedule/2027-03');
    expect(currentUser.value).toBeNull();
  });

  test('an unknown address is a protected "not found" page: login first, then back to it', async () => {
    signedOut();
    await router.push('/no-such-page');
    expect(current().name).toBe('login');
    expect(current().query.redirect).toBe('/no-such-page');

    signedInAs(EMPLOYEE);
    await router.push('/no-such-page');
    expect(current().name).toBe('not-found');
  });
});

describe('signed in', () => {
  test('a protected page loads the user and opens', async () => {
    signedInAs(EMPLOYEE);
    await router.push('/dashboard');
    expect(current().name).toBe('dashboard');
    expect(currentUser.value).toEqual(EMPLOYEE);
    expect(probe).toHaveBeenCalledTimes(1);
  });

  test('admin-only pages send anyone else to the dashboard', async () => {
    signedInAs(EMPLOYEE);
    await router.push('/alarms');
    for (const path of ['/users', '/checklists', '/shift-settings', '/procedures/tree-editor']) {
      await router.push(path);
      expect(current().name).toBe('dashboard');
    }

    signedInAs(MAINTAINER);
    await router.push('/users');
    expect(current().name).toBe('dashboard');

    signedInAs(ADMIN);
    await router.push('/users');
    expect(current().name).toBe('users');
  });

  test('schedule-manager pages open for admins and maintainers, not for employees', async () => {
    signedInAs(EMPLOYEE);
    for (const path of ['/on-call', '/settlement-periods', '/change-requests']) {
      await router.push(path);
      expect(current().name).toBe('dashboard');
    }

    signedInAs(MAINTAINER);
    await router.push('/on-call');
    expect(current().name).toBe('on-call');
    await router.push('/change-requests');
    expect(current().name).toBe('change-requests');

    signedInAs(ADMIN);
    await router.push('/settlement-periods');
    expect(current().name).toBe('settlement-periods');
  });

  test('the login page sends a signed-in user on: to a safe redirect, else the dashboard', async () => {
    signedInAs(ADMIN);
    await router.push({ path: '/login', query: { redirect: '/procedures/5' } });
    expect(current().name).toBe('procedure-detail');
    expect(current().params.id).toBe('5');

    await router.push({ path: '/login', query: { redirect: 'https://evil.example/' } });
    expect(current().name).toBe('dashboard');

    await router.push({ path: '/login', query: { redirect: '//evil.example' } });
    expect(current().name).toBe('dashboard');
  });

  test('the login page opens for a signed-out user', async () => {
    signedInAs(ADMIN);
    await router.push('/my-shifts');
    signedOut();
    await router.push('/login');
    expect(current().name).toBe('login');
    expect(currentUser.value).toBeNull();
  });
});

describe('when the user probe fails for another reason', () => {
  test('a known user keeps the session and the navigation goes through', async () => {
    signedInAs(ADMIN);
    await router.push('/dashboard');

    probe.mockRejectedValue(status(429));
    await router.push('/alarms');
    expect(current().name).toBe('alarms');
    expect(currentUser.value).toEqual(ADMIN);
  });

  test('with no user ever resolved, the navigation is aborted rather than guessed', async () => {
    signedInAs(ADMIN);
    await router.push('/dashboard');
    currentUser.value = null;
    probe.mockRejectedValue(status(500));

    const failure = await router.push('/my-shifts');
    expect(isNavigationFailure(failure, NavigationFailureType.aborted)).toBe(true);
    expect(current().name).toBe('dashboard');
    expect(currentUser.value).toBeNull();
  });

  test('a throttled probe on the login page still shows the login page', async () => {
    probe.mockRejectedValue(status(429));
    await router.push('/login');
    expect(current().name).toBe('login');
  });
});
