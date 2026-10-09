/**
 * Changing a day's shift count on the Schedule screen. Removing staffed slots needs the manager's
 * go-ahead: the API answers 409 `staffed_shifts_removal` with the shifts, and the request is resent
 * with `confirm_remove_staffed` and the ids the manager saw. If those changed in the meantime
 * (someone staffed another slot), the API asks again and so does this, up to MAX_CONFIRM_ROUNDS
 * dialogs.
 */
export const MAX_CONFIRM_ROUNDS = 3;

function isStaffedRemoval(err) {
  return err?.response?.status === 409 && err.response.data?.code === 'staffed_shifts_removal';
}

export function staffedRemovalTitle(staffed) {
  return staffed.length === 1
    ? 'Remove a staffed shift?'
    : `Remove ${staffed.length} staffed shifts?`;
}

export function staffedRemovalMessage(staffed) {
  const lines = staffed.map((s) => {
    const times = s.start_time && s.end_time ? ` ${s.start_time}-${s.end_time}` : '';
    const requests = s.pending_change_requests
      ? `, ${s.pending_change_requests} pending day-off request${s.pending_change_requests === 1 ? '' : 's'}`
      : '';
    return `• ${s.date} shift ${s.shift_number}${times}: ${(s.assigned_users || []).join(', ')}${requests}`;
  });
  return [
    ...lines,
    'Their assignments and day-off requests will be deleted. Handover submissions are kept.',
    'The remaining slots are renumbered 1..n in their order (e.g. shift 3 becomes shift 2), which matters for the month-wide slot type editor.',
  ].join('\n');
}

/**
 * @param {{ put: (body: object) => Promise<any>, confirm: (opts: object) => Promise<boolean>,
 *   payload: object, maxRounds?: number }} args
 * @returns {Promise<{status: 'saved', response: any} | {status: 'cancelled'} | {status: 'gave_up', message: string}>}
 *   Errors other than the staffed-removal 409 are rethrown.
 */
export async function putDayShiftCount({ put, confirm, payload, maxRounds = MAX_CONFIRM_ROUNDS }) {
  let body = payload;
  for (let round = 0; ; round++) {
    try {
      return { status: 'saved', response: await put(body) };
    } catch (err) {
      if (!isStaffedRemoval(err)) throw err;
      if (round >= maxRounds) {
        return {
          status: 'gave_up',
          message:
            'The staffed shifts on this day kept changing. Reload the schedule and try again.',
        };
      }
      const staffed = err.response.data.shifts || [];
      const ok = await confirm({
        title: staffedRemovalTitle(staffed),
        message: staffedRemovalMessage(staffed),
        confirmLabel: 'Remove',
      });
      if (!ok) return { status: 'cancelled' };
      body = {
        ...payload,
        confirm_remove_staffed: true,
        expected_remove_ids: staffed.map((s) => s.id),
      };
    }
  }
}
