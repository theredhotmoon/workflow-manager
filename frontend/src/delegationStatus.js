// Where a delegation stands today. Delegations are whole days in the business calendar
// (Europe/Warsaw, like App\Support\BusinessDate on the server) and include their last day: one that
// ends today is ongoing until midnight in Warsaw, whatever the browser's timezone. The API sends the
// dates as "Y-m-d" or as UTC midnights ("2026-10-09T00:00:00.000000Z"); only the date part counts.

export const BUSINESS_TIMEZONE = 'Europe/Warsaw';

const businessDateFormat = new Intl.DateTimeFormat('en-CA', {
  timeZone: BUSINESS_TIMEZONE,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
});

/** Today's date in the business timezone as YYYY-MM-DD. */
export function businessTodayStr(now = new Date()) {
  const parts = Object.fromEntries(
    businessDateFormat.formatToParts(now).map((part) => [part.type, part.value]),
  );
  return `${parts.year}-${parts.month}-${parts.day}`;
}

function dayOf(value) {
  return String(value ?? '').slice(0, 10);
}

/** 'upcoming' | 'ongoing' | 'completed' */
export function delegationStatus(delegation, now = new Date()) {
  const today = businessTodayStr(now);
  if (today < dayOf(delegation.start_datetime)) return 'upcoming';
  if (today > dayOf(delegation.end_datetime)) return 'completed';
  return 'ongoing';
}

/** True only once the delegation's last day is over in the business timezone. */
export function isDelegationCompleted(delegation, now = new Date()) {
  return delegationStatus(delegation, now) === 'completed';
}

export const DELEGATION_STATUS_LABELS = {
  upcoming: 'Upcoming',
  ongoing: 'Ongoing',
  completed: 'Completed',
};
