import { expect, test } from '@playwright/test';
import { createAlarmStore } from './alarmStore.js';

function fakeApi({ failPut = false } = {}) {
  const calls = [];
  return {
    calls,
    async get(url) {
      calls.push(['get', url]);
      if (url === '/api/alarms/latest') {
        return {
          data: [
            { id: 'a', read_at: null },
            { id: 'b', read_at: null },
            { id: 'c', read_at: '2026-10-01T10:00:00Z' },
          ],
        };
      }
      return { data: { count: 2 } };
    },
    async put(url) {
      calls.push(['put', url]);
      if (failPut) throw { response: { status: 500, data: {} } };
      return { data: {} };
    },
  };
}

const NOW = '2026-10-12T08:00:00Z';

test.describe('alarm store (L24)', () => {
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
