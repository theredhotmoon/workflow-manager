// Pure helpers for the managers' Change Requests screen (ChangeRequestsView): how a request's shift,
// state and Schedule link are shown. A request keeps its shift's date and slot (shift_date,
// shift_number) even after the assignment or the shift itself is removed, so these read those
// first and the live shift only for what only it has (times, group).

export const STATUS_FILTERS = [
  { value: 'pending', label: 'Pending' },
  { value: 'acknowledged', label: 'Acknowledged' },
  { value: 'all', label: 'All' },
];

/** "HH:MM" from a time column ("08:00:00" or "08:00"); '' when missing. */
function hhmm(time) {
  return typeof time === 'string' && /^\d{2}:\d{2}/.test(time) ? time.slice(0, 5) : '';
}

/** The request's shift date (YYYY-MM-DD), from the copy on the request or the live shift. */
export function requestShiftDate(request) {
  const raw = request?.shift_date ?? request?.shift?.date ?? '';
  const match = /^\d{4}-\d{2}-\d{2}/.exec(String(raw));
  return match ? match[0] : '';
}

/** "Shift #2 · 08:00–16:00" (times only while the shift exists). */
export function shiftTimeLabel(request) {
  const number = request?.shift_number ?? request?.shift?.shift_number;
  const start = hhmm(request?.shift?.start_time);
  const end = hhmm(request?.shift?.end_time);
  const slot = number ? `Shift #${number}` : 'Shift';
  return start && end ? `${slot} · ${start}–${end}` : slot;
}

/**
 * What became of the request's work, if anything: the shift (with the request's group) or just
 * the employee's assignment to it. Null while both are there.
 */
export function removedState(request) {
  if (request?.shift_removed) return { kind: 'shift', label: 'Shift removed' };
  if (request?.assignment_removed) return { kind: 'assignment', label: 'Assignment removed' };
  return null;
}

/** The shift's group as the list shows it; null once the shift is gone. */
export function requestGroup(request) {
  const group = request?.shift?.shift_group;
  if (!group) return null;
  return { id: group.id, name: group.name, archived: Boolean(group.archived_at) };
}

/**
 * The Schedule at the request's month, scrolled to its day with the requester highlighted, on the
 * shift's group tab (an archived group has no tab, so the default one opens). Null without a date.
 */
export function scheduleLink(request) {
  const date = requestShiftDate(request);
  if (!date) return null;
  const query = { scroll_date: date };
  if (request?.user_id) query.highlight_user = String(request.user_id);
  const group = requestGroup(request);
  if (group && !group.archived) query.group = String(group.id);
  return { name: 'schedule', params: { month: date.slice(0, 7) }, query };
}

/** Status badge text: who acknowledged it when known, "Settled" when its work was removed. */
export function statusText(request) {
  if (request?.status === 'pending') return 'Pending';
  if (request?.acknowledged_by?.name) return `Acknowledged by ${request.acknowledged_by.name}`;
  if (removedState(request)) return 'Settled';
  return 'Acknowledged';
}
