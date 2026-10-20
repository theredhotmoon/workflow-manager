import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { fetchCurrentUser, isLoggedOut } from './fetchCurrentUser.js';
import { api } from './testing/fakeApi.js';

vi.mock('./axios.js', async () => ({ default: (await import('./testing/fakeApi.js')).api }));

const USER = { id: 1, name: 'Admin User', role: 'admin' };
const throttled = (retryAfter) => ({
  response: { status: 429, headers: retryAfter === undefined ? {} : { 'retry-after': retryAfter } },
});

/** Answers in order; the last one repeats. A function answer may throw. */
function answers(...responses) {
  let n = 0;
  return () => {
    const r = responses[Math.min(n++, responses.length - 1)];
    return typeof r === 'function' ? r() : r;
  };
}

beforeEach(() => {
  api.reset();
  vi.useFakeTimers();
});

afterEach(() => {
  vi.useRealTimers();
});

describe('isLoggedOut', () => {
  test('only 401 and 419 mean the session is gone', () => {
    expect(isLoggedOut({ response: { status: 401 } })).toBe(true);
    expect(isLoggedOut({ response: { status: 419 } })).toBe(true);
    expect(isLoggedOut({ response: { status: 429 } })).toBe(false);
    expect(isLoggedOut({ response: { status: 500 } })).toBe(false);
    expect(isLoggedOut({ response: { status: 403 } })).toBe(false);
    expect(isLoggedOut(new Error('network'))).toBe(false);
    expect(isLoggedOut(undefined)).toBe(false);
  });
});

describe('fetchCurrentUser', () => {
  test('returns the signed-in user from GET /api/user', async () => {
    api.on('get', '/api/user', USER);
    await expect(fetchCurrentUser()).resolves.toEqual(USER);
    expect(api.calls).toEqual([{ method: 'get', url: '/api/user' }]);
  });

  test('any failure but 429 is rethrown as is, after one request', async () => {
    const error = { response: { status: 401 } };
    api.on('get', '/api/user', () => {
      throw error;
    });
    await expect(fetchCurrentUser()).rejects.toBe(error);
    expect(api.calls).toHaveLength(1);
  });

  test('a 429 is retried once, after the Retry-After seconds', async () => {
    api.on(
      'get',
      '/api/user',
      answers(() => {
        throw throttled('2');
      }, USER),
    );
    const result = fetchCurrentUser();
    await vi.advanceTimersByTimeAsync(1999);
    expect(api.calls).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(api.calls).toHaveLength(2);
    await expect(result).resolves.toEqual(USER);
  });

  test('without a usable Retry-After it waits one second; a long one is capped at three', async () => {
    api.on(
      'get',
      '/api/user',
      answers(() => {
        throw throttled();
      }, USER),
    );
    let result = fetchCurrentUser();
    await vi.advanceTimersByTimeAsync(999);
    expect(api.calls).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(api.calls).toHaveLength(2);
    await expect(result).resolves.toEqual(USER);

    api.reset();
    api.on(
      'get',
      '/api/user',
      answers(() => {
        throw throttled('30');
      }, USER),
    );
    result = fetchCurrentUser();
    await vi.advanceTimersByTimeAsync(2999);
    expect(api.calls).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(api.calls).toHaveLength(2);
    await expect(result).resolves.toEqual(USER);

    api.reset();
    api.on(
      'get',
      '/api/user',
      answers(() => {
        throw throttled('nonsense');
      }, USER),
    );
    result = fetchCurrentUser();
    await vi.advanceTimersByTimeAsync(1000);
    expect(api.calls).toHaveLength(2);
    await expect(result).resolves.toEqual(USER);
  });

  test('a second failure after the retry is what the caller gets', async () => {
    const second = { response: { status: 500 } };
    api.on(
      'get',
      '/api/user',
      answers(
        () => {
          throw throttled('1');
        },
        () => {
          throw second;
        },
      ),
    );
    const result = fetchCurrentUser();
    // Attach the handler before the rejection lands, so the runtime never sees it unhandled.
    const outcome = result.then(
      () => 'resolved',
      (e) => e,
    );
    await vi.advanceTimersByTimeAsync(1000);
    await expect(outcome).resolves.toBe(second);
    expect(api.calls).toHaveLength(2);
  });
});
