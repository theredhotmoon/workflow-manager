import { describeApiError } from './apiErrors.js';

// POST /api/logout, once more after refreshing the CSRF cookie if the first attempt fails (a 419
// when the XSRF token went stale, e.g. after a long idle or a session rotation in another tab).
// A 401 means the session is already gone, which is what logging out wants. Rejects when the
// retry fails too: the caller must then NOT behave as if the user were signed out.
function alreadyLoggedOut(error) {
  return error?.response?.status === 401;
}

export async function logoutWithRetry(api) {
  try {
    await api.post('/api/logout');
    return;
  } catch (error) {
    if (alreadyLoggedOut(error)) return;
  }
  await api.get('/sanctum/csrf-cookie');
  try {
    await api.post('/api/logout');
  } catch (error) {
    if (!alreadyLoggedOut(error)) throw error;
  }
}

/**
 * Why logging out failed, for the "You are still signed in" alert. describeApiError() leaves
 * 401/419 silent (sessionExpiry handles those elsewhere), but here a 419 that survived the retry
 * needs words of its own.
 */
export function logoutFailureMessage(error) {
  if (error?.response?.status === 419) {
    return 'Your session token expired; reload the page and try again.';
  }
  return describeApiError(error, 'Could not log out').message || 'Could not log out.';
}
