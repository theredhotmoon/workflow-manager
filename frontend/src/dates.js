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
