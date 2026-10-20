import { describe, expect, test } from 'vitest';
import { ref } from 'vue';
import { createAlarmStore, resetAlarmsOnUserChange } from './alarmStore.js';

function fakeApi({ failPut = false, count = 2 } = {}) {
  const calls = [];
  return {
    calls,
    count,
    failGet: false,
    async get(url) {
      calls.push(['get', url]);
      if (this.failGet) throw { response: { status: 500, data: {} } };
      if (url === '/api/alarms/latest') {
        return {
          data: [
            { id: 'a', read_at: null },
            { id: 'b', read_at: null },
            { id: 'c', read_at: '2026-10-01T10:00:00Z' },
          ],
        };
      }
      return { data: { count: this.count } };
    },
    async put(url) {
      calls.push(['put', url]);
      if (failPut) throw { response: { status: 500, data: {} } };
      return { data: {} };
    },
  };
}

const NOW = '2026-10-12T08:00:00Z';

describe('alarm store (L24)', () => {
  test('marking one alarm read lowers the badge at once and updates every copy', async () => {
    const api = fakeApi();
    const store = createAlarmStore({ api, now: () => NOW });
    await store.refresh();
    const pageList = [{ id: 'a', read_at: null }];

    const pending = store.markRead(pageList[0], [pageList]);
    // Before the server answers.
    expect(store.unreadCount.value).toBe(1);
    expect(pageList[0].read_at).toBe(NOW);
    expect(store.latest.value[0].read_at).toBe(NOW);

    await expect(pending).resolves.toBe(true);
    expect(api.calls).toContainEqual(['put', '/api/alarms/a/read']);
  });

  test('a failed mark-read rolls back and reports the error', async () => {
    const errors = [];
    const store = createAlarmStore({
      api: fakeApi({ failPut: true }),
      now: () => NOW,
      onError: (_e, fallback) => errors.push(fallback),
    });
    await store.refresh();

    await expect(store.markRead(store.latest.value[0])).resolves.toBe(false);
    expect(store.unreadCount.value).toBe(2);
    expect(store.latest.value[0].read_at).toBeNull();
    expect(errors).toEqual(['Could not mark the notification as read, try again']);
  });

  test('mark all read zeroes the badge, and a failure restores it', async () => {
    const ok = createAlarmStore({ api: fakeApi(), now: () => NOW });
    await ok.refresh();
    const pageList = [{ id: 'z', read_at: null }];
    await expect(ok.markAllRead([pageList])).resolves.toBe(true);
    expect(ok.unreadCount.value).toBe(0);
    expect(pageList[0].read_at).toBe(NOW);
    expect(ok.latest.value.every((a) => a.read_at)).toBe(true);

    const errors = [];
    const failing = createAlarmStore({
      api: fakeApi({ failPut: true }),
      now: () => NOW,
      onError: (_e, fallback) => errors.push(fallback),
    });
    await failing.refresh();
    const list = [{ id: 'z', read_at: null }];
    await expect(failing.markAllRead([list])).resolves.toBe(false);
    expect(failing.unreadCount.value).toBe(2);
    expect(list[0].read_at).toBeNull();
    expect(failing.latest.value.map((a) => a.read_at)).toEqual([
      null,
      null,
      '2026-10-01T10:00:00Z',
    ]);
    expect(errors).toHaveLength(1);
  });

  test('an already read alarm sends nothing', async () => {
    const api = fakeApi();
    const store = createAlarmStore({ api });
    await store.refresh();
    await expect(store.markRead(store.latest.value[2])).resolves.toBe(true);
    expect(api.calls.filter(([m]) => m === 'put')).toHaveLength(0);
    expect(store.unreadCount.value).toBe(2);
  });
});

describe('alarm store: review fixes', () => {
  test('a mark-read from the bell updates a registered page list (M1)', async () => {
    const store = createAlarmStore({ api: fakeApi(), now: () => NOW });
    await store.refresh();
    const page = ref([{ id: 'a', read_at: null }]);
    const unregister = store.registerList(page);

    await store.markRead(store.latest.value[0]);
    expect(page.value[0].read_at).toBe(NOW);
    // Clicking the page's copy afterwards sends nothing and doesn't lower the badge again.
    await store.markRead(page.value[0]);
    expect(store.unreadCount.value).toBe(1);

    unregister();
    const later = ref([{ id: 'b', read_at: null }]);
    store.registerList(later);
    await store.markAllRead();
    expect(later.value[0].read_at).toBe(NOW);
    expect(page.value.every((a) => a.read_at === NOW)).toBe(true);
  });

  test('a failed mark-read takes the count from the server, not local arithmetic (M2)', async () => {
    const api = fakeApi({ failPut: true });
    const store = createAlarmStore({ api, now: () => NOW });
    await store.refresh();
    // Meanwhile two more alarms arrived on the server.
    api.count = 4;
    await expect(store.markRead(store.latest.value[0])).resolves.toBe(false);
    expect(store.unreadCount.value).toBe(4);

    // If the server can't be asked either, the local correction is the fallback.
    api.failGet = true;
    await expect(store.markRead(store.latest.value[1])).resolves.toBe(false);
    expect(store.unreadCount.value).toBe(4);
  });

  test('reset empties the store and drops a refresh in flight (I3)', async () => {
    const api = fakeApi();
    const store = createAlarmStore({ api });
    await store.refresh();
    expect(store.latest.value).toHaveLength(3);

    const inFlight = store.refresh();
    store.reset();
    await inFlight;
    expect(store.latest.value).toEqual([]);
    expect(store.unreadCount.value).toBe(0);
  });

  test('the store is reset when the user signs out or another user signs in (I3)', async () => {
    const store = createAlarmStore({ api: fakeApi() });
    const user = ref({ id: 1 });
    const stop = resetAlarmsOnUserChange(store, user);
    await store.refresh();

    user.value = { id: 1, name: 'same user, fetched again' };
    expect(store.latest.value).toHaveLength(3);

    user.value = null; // signed out
    expect(store.latest.value).toEqual([]);
    expect(store.unreadCount.value).toBe(0);

    await store.refresh();
    user.value = { id: 2 }; // someone else
    expect(store.latest.value).toEqual([]);
    stop();
  });
});
