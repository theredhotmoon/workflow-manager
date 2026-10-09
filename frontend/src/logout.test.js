import { expect, test } from '@playwright/test';
import { logoutFailureMessage, logoutWithRetry } from './logout.js';

function fakeApi(postResults) {
  const calls = [];
  const results = [...postResults];
  return {
    calls,
    post: async (url) => {
      calls.push(`POST ${url}`);
      const status = results.shift();
      if (status === 200) return { status };
      throw { response: { status, data: {} } };
    },
    get: async (url) => {
      calls.push(`GET ${url}`);
      return { status: 204 };
    },
  };
}

test('logs out with one call when it works', async () => {
  const api = fakeApi([200]);
  await logoutWithRetry(api);
  expect(api.calls).toEqual(['POST /api/logout']);
});

test('a 419 (stale CSRF token) refreshes the cookie and retries once', async () => {
  const api = fakeApi([419, 200]);
  await logoutWithRetry(api);
  expect(api.calls).toEqual(['POST /api/logout', 'GET /sanctum/csrf-cookie', 'POST /api/logout']);
});

test('fails when the retry fails too, without a third attempt', async () => {
  const api = fakeApi([419, 500]);
  await expect(logoutWithRetry(api)).rejects.toMatchObject({ response: { status: 500 } });
  expect(api.calls).toEqual(['POST /api/logout', 'GET /sanctum/csrf-cookie', 'POST /api/logout']);
});

test('a 401 means the session is already gone: that counts as logged out', async () => {
  const api = fakeApi([401]);
  await logoutWithRetry(api);
  expect(api.calls).toEqual(['POST /api/logout']);
});

test('says why logout failed, also when the reason is a stale token (419)', () => {
  const fail = (status, data = {}) => ({ response: { status, data } });
  expect(logoutFailureMessage(fail(419))).toBe(
    'Your session token expired; reload the page and try again.',
  );
  expect(logoutFailureMessage(fail(500))).toBe('Could not log out (500).');
  expect(logoutFailureMessage({})).toBe('Could not log out (no connection).');
});
