import api from './axios.js';

const MAX_RETRY_WAIT_MS = 3000;

// Only these statuses mean the session is gone. Anything else (429 throttling, 5xx, network
// errors) says nothing about whether the user is logged in.
export function isLoggedOut(error) {
  const status = error?.response?.status;
  return status === 401 || status === 419;
}

function retryDelayMs(error) {
  const seconds = Number(error.response.headers?.['retry-after']);
  const ms = Number.isFinite(seconds) && seconds > 0 ? seconds * 1000 : 1000;
  return Math.min(ms, MAX_RETRY_WAIT_MS);
}

// GET /api/user, retrying once after `Retry-After` (capped) when the API rate limiter answers 429.
// Rejects with the original error; callers use isLoggedOut() to tell a real logout from a failure.
export async function fetchCurrentUser() {
  try {
    return (await api.get('/api/user')).data;
  } catch (error) {
    if (error?.response?.status !== 429) throw error;
    await new Promise((resolve) => setTimeout(resolve, retryDelayMs(error)));
    return (await api.get('/api/user')).data;
  }
}
