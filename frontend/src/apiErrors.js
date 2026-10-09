// Turns a failed API call into what a form or toast shows.
//
// - 401/419: `silent` — sessionExpiry.js already sends the user to the login page; showing a
//   "could not save" on top would be misleading.
// - 422: Laravel's `errors` object, first message per field, plus the server's summary.
// - 5xx: the generic message with the status (the server text is "Server Error" or, in debug mode,
//   an exception message — neither helps the user).
// - anything else: the server's `message`, else the generic message with the status.
export function describeApiError(err, fallback = 'Could not save, try again') {
  const status = err?.response?.status ?? null;
  const data = err?.response?.data ?? {};
  const result = { silent: false, status, message: '', fieldErrors: {} };

  if (status === 401 || status === 419) return { ...result, silent: true };
  if (status === null) return { ...result, message: `${fallback} (no connection).` };

  if (status === 422) {
    for (const [field, messages] of Object.entries(data.errors ?? {})) {
      result.fieldErrors[field] = Array.isArray(messages) ? messages[0] : String(messages);
    }
    result.message = data.message || 'Please fix the highlighted fields.';
    return result;
  }

  result.message =
    status < 500 && typeof data.message === 'string' && data.message
      ? data.message
      : `${fallback} (${status}).`;
  return result;
}

/** The error for `name`, or for any item of the array field `name` (e.g. `tag_ids.0`). */
export function fieldError(fieldErrors, name) {
  if (fieldErrors[name]) return fieldErrors[name];
  const item = Object.keys(fieldErrors).find((key) => key.startsWith(`${name}.`));
  return item ? fieldErrors[item] : '';
}
