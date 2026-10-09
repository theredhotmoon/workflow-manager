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
