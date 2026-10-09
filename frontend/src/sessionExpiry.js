export const DEACTIVATED_MESSAGE = 'This account is deactivated.';

// The router guard already handles a 401/419 from the /api/user probe it runs on every
// navigation, and login/logout handle their own failures.
const SELF_HANDLED = ['/api/user', '/api/login', '/api/logout'];

function pathOf(url = '') {
  return url.replace(/^https?:\/\/[^/]+/, '').split('?')[0];
}

/**
 * Sends the user to the login page when any API call says the session is gone (401/419) while
 * they are logged in: e.g. an admin deactivated them, or the session expired. When the server says
 * the account is deactivated, that message is shown. Other errors (429 throttling, 5xx, network)
 * pass through untouched; the error is always re-thrown so callers keep their own handling.
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

        const onLogin = router.currentRoute.value?.name === 'login';
        if (currentUser.value && !onLogin && !SELF_HANDLED.includes(pathOf(error.config?.url))) {
          currentUser.value = null;
          // Toast once on the login page, after the failed view's own error toast (if any).
          router.push({ name: 'login' }).then(notify, notify);
        } else {
          notify();
        }
      }
      return Promise.reject(error);
    },
  );
}
