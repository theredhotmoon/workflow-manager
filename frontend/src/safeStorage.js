// Browser storage can be missing or throw (private mode, blocked site data, a full quota). It only
// ever holds conveniences such as a remembered view mode, so a failure falls back to the default
// instead of breaking the page.

function storage() {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    // Reading the property itself throws when site data is blocked.
    return null;
  }
}

export function readStorage(key, fallback = null) {
  try {
    const value = storage()?.getItem(key);
    return value ?? fallback;
  } catch {
    return fallback;
  }
}

/** Returns false when the value couldn't be stored. */
export function writeStorage(key, value) {
  try {
    const s = storage();
    if (!s) return false;
    s.setItem(key, value);
    return true;
  } catch {
    return false;
  }
}

export function removeStorage(key) {
  try {
    storage()?.removeItem(key);
  } catch {}
}
