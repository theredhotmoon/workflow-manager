// frontend/src/composables/useAlarms.test.js
//
// The wiring around alarmStore.js (which has its own tests): one shared store, the real API
// client, errors shown through the toast, and the reset when the signed-in user changes.
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { currentUser } from '../router/index.js';
import { api, httpError } from '../testing/fakeApi.js';
import { useAlarms } from './useAlarms.js';
import { useToast } from './useToast.js';

vi.mock('../axios.js', async () => ({ default: (await import('../testing/fakeApi.js')).api }));
vi.mock('../router/index.js', async () => {
  const { ref } = await import('vue');
  return { currentUser: ref({ id: 1, name: 'Admin', role: 'admin' }) };
});

const unread = (id) => ({ id, read_at: null, title: `Alarm ${id}` });

function stubBell(count = 2) {
  api.on('get', '/api/alarms/latest', [unread('a'), unread('b')]);
  api.on('get', '/api/alarms/unread-count', { count });
}

beforeEach(() => {
  api.reset();
  useToast().dismissToast();
  currentUser.value = { id: 1, name: 'Admin', role: 'admin' };
});

describe('useAlarms', () => {
  test('is one store for the whole app, reading the bell through the API client', async () => {
    const store = useAlarms();
    expect(useAlarms()).toBe(store);

    stubBell(2);
    await store.refresh();
    expect(store.latest.value.map((a) => a.id)).toEqual(['a', 'b']);
    expect(store.unreadCount.value).toBe(2);
    expect(api.calls.map((c) => c.url)).toEqual(['/api/alarms/latest', '/api/alarms/unread-count']);
  });

  test('a refused mark-read is told through the toast, with the status in the fallback message', async () => {
    const store = useAlarms();
    stubBell(1);
    api.on('put', '/api/alarms/x/read', () => {
      throw httpError(500);
    });

    expect(await store.markRead(unread('x'))).toBe(false);
    expect(useToast().toast.value).toEqual({
      show: true,
      type: 'error',
      message: 'Could not mark the notification as read, try again (500).',
    });
  });

  test("a 4xx shows the server's message; a 401 (session gone) is silent", async () => {
    const store = useAlarms();
    stubBell(1);
    api.on('put', '/api/alarms/y/read', () => {
      throw httpError(403, { message: 'Not your notification.' });
    });
    await store.markRead(unread('y'));
    expect(useToast().toast.value).toMatchObject({ show: true, message: 'Not your notification.' });

    useToast().dismissToast();
    api.on('put', '/api/alarms/z/read', () => {
      throw httpError(401);
    });
    await store.markRead(unread('z'));
    expect(useToast().toast.value.show).toBe(false);
  });

  test('signing out, or another user signing in, empties the store at once', async () => {
    const store = useAlarms();
    stubBell(2);
    await store.refresh();
    expect(store.unreadCount.value).toBe(2);

    currentUser.value = null;
    expect(store.latest.value).toEqual([]);
    expect(store.unreadCount.value).toBe(0);

    await store.refresh();
    expect(store.unreadCount.value).toBe(2);
    currentUser.value = { id: 2, name: 'Someone else', role: 'employee' };
    expect(store.unreadCount.value).toBe(0);

    // The same user again (a re-fetch of /api/user) is not a change.
    await store.refresh();
    currentUser.value = { id: 2, name: 'Someone else', role: 'employee' };
    expect(store.unreadCount.value).toBe(2);
  });
});
