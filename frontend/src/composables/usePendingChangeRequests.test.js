// frontend/src/composables/usePendingChangeRequests.test.js
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { currentUser } from '../router/index.js';
import { api } from '../testing/fakeApi.js';
import { usePendingChangeRequests } from './usePendingChangeRequests.js';

vi.mock('../axios.js', async () => ({ default: (await import('../testing/fakeApi.js')).api }));
vi.mock('../router/index.js', async () => {
  const { ref } = await import('vue');
  return { currentUser: ref({ id: 1, role: 'admin' }) };
});

beforeEach(() => {
  api.reset();
  currentUser.value = { id: 1, role: 'admin' };
});

describe('usePendingChangeRequests', () => {
  test('is one store for the whole app, reading the count through the API client', async () => {
    const store = usePendingChangeRequests();
    expect(usePendingChangeRequests()).toBe(store);

    api.on('get', '/api/shift-change-requests/pending-count', { count: '4' });
    await store.refresh();
    expect(store.count.value).toBe(4);
    expect(api.calls).toEqual([{ method: 'get', url: '/api/shift-change-requests/pending-count' }]);
  });

  test('signing out, or another user signing in, resets the count at once', async () => {
    const store = usePendingChangeRequests();
    api.on('get', '/api/shift-change-requests/pending-count', { count: 3 });
    await store.refresh();
    expect(store.count.value).toBe(3);

    currentUser.value = null;
    expect(store.count.value).toBe(0);

    await store.refresh();
    currentUser.value = { id: 2, role: 'employee' };
    expect(store.count.value).toBe(0);

    await store.refresh();
    currentUser.value = { id: 2, role: 'schedule_maintainer' }; // same person, new role: no reset
    expect(store.count.value).toBe(3);
  });
});
