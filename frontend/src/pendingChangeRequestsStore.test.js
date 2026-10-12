import { expect, test } from '@playwright/test';
import { ref } from 'vue';
import {
  createPendingChangeRequestsStore,
  resetPendingOnUserChange,
} from './pendingChangeRequestsStore.js';

function deferredApi() {
  const pending = [];
  return {
    calls: 0,
    get(url) {
      this.calls++;
      expect(url).toBe('/api/shift-change-requests/pending-count');
      return new Promise((resolve, reject) => pending.push({ resolve, reject }));
    },
    answer(i, count) {
      pending[i].resolve({ data: { count } });
    },
  };
}

test.describe('pending change-request count', () => {
  test('refresh reads the count', async () => {
    const api = { get: async () => ({ data: { count: 3 } }) };
    const store = createPendingChangeRequestsStore({ api });
    await store.refresh();
    expect(store.count.value).toBe(3);
  });

  test('an older answer landing after a newer one is dropped', async () => {
    const api = deferredApi();
    const store = createPendingChangeRequestsStore({ api });
    const first = store.refresh();
    const second = store.refresh();
    api.answer(1, 1);
    await second;
    api.answer(0, 5);
    await first;
    expect(store.count.value).toBe(1);
  });

  test('a reset drops a refresh in flight', async () => {
    const api = deferredApi();
    const store = createPendingChangeRequestsStore({ api });
    const inFlight = store.refresh();
    store.reset();
    api.answer(0, 4);
    await inFlight;
    expect(store.count.value).toBe(0);
  });

  test('a failed refresh rejects and keeps the last count', async () => {
    let fail = false;
    const api = {
      get: async () => {
        if (fail) throw { response: { status: 500 } };
        return { data: { count: 2 } };
      },
    };
    const store = createPendingChangeRequestsStore({ api });
    await store.refresh();
    fail = true;
    await expect(store.refresh()).rejects.toBeTruthy();
    expect(store.count.value).toBe(2);
  });

  test('another user signing in empties it', async () => {
    const user = ref({ id: 1 });
    const store = createPendingChangeRequestsStore({
      api: { get: async () => ({ data: { count: 6 } }) },
    });
    resetPendingOnUserChange(store, user);
    await store.refresh();
    user.value = { id: 1 };
    expect(store.count.value).toBe(6);
    user.value = { id: 2 };
    expect(store.count.value).toBe(0);
  });
});
