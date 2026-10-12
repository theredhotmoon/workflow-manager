// Shift lengths and hour totals from the API's wall-clock times ("HH:MM" or "HH:MM:SS"). Shared by
// My Shifts (the month's total) and the Schedule (per-employee totals and the shift-pattern donut).

/** "HH:MM[:SS]" as minutes after midnight; 0 for a missing time. */
export function timeToMinutes(time) {
  if (!time) return 0;
  const [h, m] = time.split(':').map(Number);
  return h * 60 + m;
}

/**
 * Length of a shift in minutes. An end at or before the start runs past midnight (22:00–06:00 is
 * 8 h; 08:00–08:00 is a full 24 h). 0 when either time is missing.
 */
export function shiftDurationMinutes(startTime, endTime) {
  if (!startTime || !endTime) return 0;
  const minutes = timeToMinutes(endTime) - timeToMinutes(startTime);
  return minutes > 0 ? minutes : minutes + 24 * 60;
}

/** Total length of shifts ({ start_time, end_time }) in whole hours, rounded to the nearest. */
export function totalShiftHours(shifts) {
  let minutes = 0;
  for (const shift of shifts) minutes += shiftDurationMinutes(shift.start_time, shift.end_time);
  return Math.round(minutes / 60);
}

/** Minutes as "8h" or "7h 30m". */
export function formatDuration(minutes) {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m > 0 ? `${h}h ${m}m` : `${h}h`;
}
