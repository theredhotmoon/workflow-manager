export const DEACTIVATED_MESSAGE = 'This account is deactivated.';

// The router guard already handles a 401/419 from the /api/user probe it runs on every
// navigation, login/logout handle their own failures, and the CSRF cookie call belongs to them.
const SELF_HANDLED = ['/api/user', '/api/login', '/api/logout', '/sanctum/csrf-cookie'];

function pathOf(url = '') {
  return url.replace(/^https?:\/\/[^/]+/, '').split('?')[0];
}

/**
 * Sends the user to the login page when any API call says the session is gone (401/419) while
 * they are logged in: e.g. an admin deactivated them, or the session expired after the page loaded.
 * The login page gets `redirect` (the page they were on), so signing in again takes them back.
 * Many calls failing together (the view, the alarm poll...) navigate once. When the server says
 * the account is deactivated, that message is shown. Other errors (429 throttling, 5xx, network)
 * pass through untouched; the error is always re-thrown so callers keep their own handling
 * (describeApiError() marks 401/419 silent, so they don't add a "could not save" on top).
 */
export function installSessionExpiry(api, { currentUser, router, showToast }) {
  return api.interceptors.response.use(
    (response) => response,
    (error) => {
      const status = error?.response?.status;
      // Same rule as fetchCurrentUser's isLoggedOut: only these mean the session is gone.
      if (status === 401 || status === 419) {
        const deactivated = error.response?.data?.message === DEACTIVATED_MESSAGE;
        const notify = () => deactivated && showToast(DEACTIVATED_MESSAGE, 'error', 8000);

        const route = router.currentRoute.value;
        const onLogin = route?.name === 'login';
        // The first failure clears currentUser synchronously, before any other response is
        // handled, so the calls failing with it (the view, the alarm poll...) don't navigate again.
        if (currentUser.value && !onLogin && !SELF_HANDLED.includes(pathOf(error.config?.url))) {
          currentUser.value = null;
          // Toast once on the login page, after the failed view's own error toast (if any).
          router.push({ name: 'login', query: { redirect: route?.fullPath } }).then(notify, notify);
        } else {
          notify();
        }
      }
      return Promise.reject(error);
    },
  );
}

/**
 * Where to go after signing in: the `redirect` the login page was given, when it is a path inside
 * this app (never another origin: "//evil.example" or "https://..." fall back to the dashboard).
 */
export function safeRedirect(redirect) {
  if (typeof redirect !== 'string' || !redirect.startsWith('/') || redirect.startsWith('//')) {
    return null;
  }
  if (redirect.includes('\\') || pathOf(redirect) === '/login') return null;
  return redirect;
}
