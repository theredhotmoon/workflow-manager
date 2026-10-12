// Calendar dates in the browser's own timezone. Never use toISOString() for these: it converts to
// UTC first, so east of UTC (e.g. Warsaw) local midnight becomes the previous day.

/** A Date's local calendar day as YYYY-MM-DD. */
export function localDateStr(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/** Today's local calendar day as YYYY-MM-DD. */
export function todayStr() {
  return localDateStr(new Date());
}

const calendarDateFormat = new Intl.DateTimeFormat('en-US', {
  timeZone: 'UTC',
  month: 'short',
  day: 'numeric',
  year: 'numeric',
});

/**
 * A calendar date — "2026-11-02", or the UTC midnight the API sends for a date column
 * ("2026-11-02T00:00:00.000000Z") — as "Nov 2, 2026", the same day in every timezone. Only the
 * date part is read: `new Date('2026-11-02')` is UTC midnight, which west of UTC is still the 1st.
 */
export function formatCalendarDate(value) {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value ?? ''));
  if (!match) return '';
  const [, y, m, d] = match.map(Number);
  return calendarDateFormat.format(new Date(Date.UTC(y, m - 1, d)));
}
