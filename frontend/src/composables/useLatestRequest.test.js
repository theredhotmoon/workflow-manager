import { describe, expect, test } from 'vitest';
import { useLatestRequest } from './useLatestRequest.js';

function deferred() {
  let resolve;
  const promise = new Promise((r) => {
    resolve = r;
  });
  return { promise, resolve };
}

// The call-site pattern every view uses: apply, error handling and `loading` only for the latest.
function makeLoader(latest, state) {
  return async function load(response) {
    const token = latest.begin();
    state.loading = true;
    try {
      const data = await response;
      if (!latest.isCurrent(token)) return;
      state.data = data;
    } finally {
      if (latest.isCurrent(token)) state.loading = false;
    }
  };
}

describe('useLatestRequest', () => {
  test('a request that was just begun is current', () => {
    const latest = useLatestRequest();
    const token = latest.begin();
    expect(latest.isCurrent(token)).toBe(true);
  });

  test('a newer request supersedes an older one', () => {
    const latest = useLatestRequest();
    const older = latest.begin();
    const newer = latest.begin();
    expect(latest.isCurrent(older)).toBe(false);
    expect(latest.isCurrent(newer)).toBe(true);
  });

  test('invalidate() makes the in-flight request stale without starting a new one', () => {
    const latest = useLatestRequest();
    const token = latest.begin();
    latest.invalidate();
    expect(latest.isCurrent(token)).toBe(false);
    const next = latest.begin();
    expect(latest.isCurrent(next)).toBe(true);
  });

  test('channels are independent and never accept each other’s tokens', () => {
    const schedule = useLatestRequest();
    const overlay = useLatestRequest();
    const scheduleToken = schedule.begin();
    const overlayToken = overlay.begin();
    schedule.begin();
    expect(schedule.isCurrent(scheduleToken)).toBe(false);
    expect(overlay.isCurrent(overlayToken)).toBe(true);
    expect(schedule.isCurrent(overlayToken)).toBe(false);
  });

  test('an older response that resolves last is not applied and does not touch loading', async () => {
    const state = { data: null, loading: false };
    const load = makeLoader(useLatestRequest(), state);
    const slow = deferred();
    const fast = deferred();
    const first = load(slow.promise);
    const second = load(fast.promise);

    fast.resolve('newer');
    await second;
    expect(state).toEqual({ data: 'newer', loading: false });

    slow.resolve('older');
    await first;
    expect(state).toEqual({ data: 'newer', loading: false });
  });

  test('loading stays on while the latest request is still pending', async () => {
    const state = { data: null, loading: false };
    const load = makeLoader(useLatestRequest(), state);
    const slow = deferred();
    const fast = deferred();
    const first = load(fast.promise);
    load(slow.promise);

    fast.resolve('older');
    await first;
    expect(state).toEqual({ data: null, loading: true });
  });
});
