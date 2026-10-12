// frontend/src/composables/schedule/useAssignmentFlow.js
//
// Assigning by drag and drop on the Schedule screen, and unassigning. Moved out of
// ScheduleView.vue unchanged; the order of the checks and the request bodies are the same:
//
//   onDrop: one chip per person and shift (a second drop while the first is still being saved,
//   or onto a shift they're already on, is taken straight back off), then
//   availability (unavailable that day) → delegation → back-to-back (adjacent shifts) →
//   doAssign: POST /api/schedule/assign; a 409 short_daily_rest asks (unless it only lists the
//   back-to-back shifts already accepted) and resends with confirm_short_rest; a 409 rest-day
//   conflict opens the rest-day policy dialog (restPrompt) and resends with the chosen policy.
//
// restPrompt is created by the screen: a month load closes it (useScheduleMonth).
import { ref } from 'vue';
import api from '../../axios.js';
import { localDateStr } from '../../dates.js';
import { isOvernightShift } from '../../schedule/helpers.js';
import { confirm } from '../useConfirm.js';

export function useAssignmentFlow({
  schedule,
  monthKey,
  monthDelegations,
  delegationsFailed,
  restPrompt,
  fetchSchedule,
  showToast,
}) {
  // Unavailable assignment confirmation
  const availConfirm = ref({
    show: false,
    shift: null,
    item: null,
    itemIndex: null,
    userName: '',
    dateStr: '',
    entries: [],
  });

  // Adjacent shift confirmation
  const adjacentConfirm = ref({
    show: false,
    shift: null,
    item: null,
    itemIndex: null,
    userName: '',
    dateStr: '',
    conflicts: [],
  });

  // Delegation conflict confirmation
  const delegConfirm = ref({
    show: false,
    shift: null,
    item: null,
    itemIndex: null,
    userName: '',
    dateStr: '',
    delegations: [],
  });

  // Find adjacent shift conflicts for a user

  function getAdjacentConflicts(userId, targetShift, dateStr) {
    const conflicts = [];
    const dayShifts = schedule.value[dateStr] || [];
    const shiftIdx = dayShifts.findIndex((s) => s.id === targetShift.id);

    // Check previous shift on same day
    if (shiftIdx > 0) {
      const prevShift = dayShifts[shiftIdx - 1];
      const isAssigned = prevShift.assignments.some((a) => a.user_id === userId);
      if (isAssigned) {
        conflicts.push({
          date: dateStr,
          shiftId: prevShift.id,
          shiftNumber: prevShift.shift_number,
          startTime: prevShift.start_time ? prevShift.start_time.substring(0, 5) : null,
          endTime: prevShift.end_time ? prevShift.end_time.substring(0, 5) : null,
          position: isOvernightShift(prevShift)
            ? 'overnight shift before (spills into next day)'
            : 'shift before',
        });
      }
    }

    // Check next shift on same day
    if (shiftIdx >= 0 && shiftIdx < dayShifts.length - 1) {
      const nextShift = dayShifts[shiftIdx + 1];
      const isAssigned = nextShift.assignments.some((a) => a.user_id === userId);
      if (isAssigned) {
        conflicts.push({
          date: dateStr,
          shiftId: nextShift.id,
          shiftNumber: nextShift.shift_number,
          startTime: nextShift.start_time ? nextShift.start_time.substring(0, 5) : null,
          endTime: nextShift.end_time ? nextShift.end_time.substring(0, 5) : null,
          position: 'shift after',
        });
      }
    }

    // If first shift of the day, check previous day's last shift
    if (shiftIdx === 0) {
      const prevDate = new Date(dateStr + 'T12:00:00');
      prevDate.setDate(prevDate.getDate() - 1);
      const prevDateStr = localDateStr(prevDate);
      const prevDayShifts = schedule.value[prevDateStr] || [];
      if (prevDayShifts.length > 0) {
        const lastShift = prevDayShifts[prevDayShifts.length - 1];
        const isAssigned = lastShift.assignments.some((a) => a.user_id === userId);
        if (isAssigned) {
          conflicts.push({
            date: prevDateStr,
            shiftId: lastShift.id,
            shiftNumber: lastShift.shift_number,
            startTime: lastShift.start_time ? lastShift.start_time.substring(0, 5) : null,
            endTime: lastShift.end_time ? lastShift.end_time.substring(0, 5) : null,
            position: isOvernightShift(lastShift)
              ? 'overnight shift from previous day (ends ' +
                lastShift.end_time?.substring(0, 5) +
                ')'
              : 'last shift previous day',
          });
        }
      }
    }

    // If target shift is overnight, also check next day's FIRST shift
    // (because an overnight shift from today reaches into tomorrow)
    if (isOvernightShift(targetShift)) {
      const nextDate = new Date(dateStr + 'T12:00:00');
      nextDate.setDate(nextDate.getDate() + 1);
      const nextDateStr = localDateStr(nextDate);
      const nextDayShifts = schedule.value[nextDateStr] || [];
      if (nextDayShifts.length > 0) {
        const firstShift = nextDayShifts[0];
        const isAssigned = firstShift.assignments.some((a) => a.user_id === userId);
        if (isAssigned) {
          conflicts.push({
            date: nextDateStr,
            shiftId: firstShift.id,
            shiftNumber: firstShift.shift_number,
            startTime: firstShift.start_time ? firstShift.start_time.substring(0, 5) : null,
            endTime: firstShift.end_time ? firstShift.end_time.substring(0, 5) : null,
            position: 'first shift next day (your shift extends overnight)',
          });
        }
      }
    }

    // If last shift of the day (and NOT overnight), check next day's first shift
    if (shiftIdx === dayShifts.length - 1 && !isOvernightShift(targetShift)) {
      const nextDate = new Date(dateStr + 'T12:00:00');
      nextDate.setDate(nextDate.getDate() + 1);
      const nextDateStr = localDateStr(nextDate);
      const nextDayShifts = schedule.value[nextDateStr] || [];
      if (nextDayShifts.length > 0) {
        const firstShift = nextDayShifts[0];
        const isAssigned = firstShift.assignments.some((a) => a.user_id === userId);
        if (isAssigned) {
          conflicts.push({
            date: nextDateStr,
            shiftId: firstShift.id,
            shiftNumber: firstShift.shift_number,
            startTime: firstShift.start_time ? firstShift.start_time.substring(0, 5) : null,
            endTime: firstShift.end_time ? firstShift.end_time.substring(0, 5) : null,
            position: 'first shift next day',
          });
        }
      }
    }

    return conflicts;
  }

  // Proceed to adjacent check (called after availability is cleared)
  async function proceedWithAdjacentCheck(shift, item, index, dateStr) {
    const adjConflicts = getAdjacentConflicts(item.user_id, shift, dateStr);
    if (adjConflicts.length > 0) {
      adjacentConfirm.value = {
        show: true,
        shift,
        item,
        itemIndex: index,
        userName: item.user_name || item.name || 'User',
        dateStr,
        conflicts: adjConflicts,
      };
      return; // Wait for user confirmation
    }
    await doAssign(shift, item, index);
  }

  // A chip dragged from another shift is a move: the server removes the old assignment and creates the
  // new one in one transaction (move_from_assignment_id), so a cancelled or refused move leaves the
  // employee where they were. Keyed by the dropped chip; read by every assign attempt for that drop.
  // Taken from the drop event itself rather than the source list's `remove` event, which Sortable
  // fires after `add`.
  const pendingMoves = new WeakMap();

  async function onDrop(evt, shift, dateStr) {
    const item = shift.assignments[evt.newIndex];
    if (!item?.user_id) return;
    // One chip per person and shift: dropping someone again while their first drop is still being
    // saved (or onto a shift they're already on) takes the new chip straight back off.
    if (shift.assignments.some((a, i) => i !== evt.newIndex && a.user_id === item.user_id)) {
      shift.assignments.splice(evt.newIndex, 1);
      // A chip moved from another shift left its list on screen; the server never moved it.
      if (evt.from !== evt.to && evt.from?.dataset?.shiftId) fetchSchedule({ quiet: true });
      showToast(
        `${item.user_name || item.name || 'This employee'} is already on this shift.`,
        'warning',
      );
      return;
    }
    // Roster cards are clones without an id; a chip from another shift still carries its assignment id.
    if (evt.from !== evt.to && evt.from?.dataset?.shiftId && item.id) {
      pendingMoves.set(item, item.id);
    }

    // Check if user has unavailable entries for this date
    // For overnight shifts, also check the next day
    const userId = item.user_id;
    const datesToCheck = [dateStr];
    if (isOvernightShift(shift)) {
      const nextDate = new Date(dateStr + 'T12:00:00');
      nextDate.setDate(nextDate.getDate() + 1);
      datesToCheck.push(localDateStr(nextDate));
    }

    try {
      const res = await api.get(`/api/user-availabilities/${userId}/month`, {
        params: { month: monthKey.value },
      });
      const unavailEntries = (res.data || []).filter(
        (e) =>
          e.type === 'unavailable' &&
          datesToCheck.some((d) => d >= e.start_date && d <= e.end_date),
      );

      if (unavailEntries.length > 0) {
        // Show unavailability confirmation modal
        availConfirm.value = {
          show: true,
          shift,
          item,
          itemIndex: evt.newIndex,
          userName: item.user_name || item.name || 'User',
          dateStr,
          entries: unavailEntries,
        };
        return; // Wait for user confirmation
      }
    } catch {
      // Don't block the drop, but don't pretend the check passed either.
      showToast("Couldn't check this employee's availability.", 'warning');
    }

    // No unavailability conflict — check delegation conflict
    await proceedWithDelegationCheck(shift, item, evt.newIndex, dateStr);
  }

  // Takes a dropped chip back off its target. For a move, the source chip (already gone from its list
  // on screen) comes back with a quiet reload; the server never removed it.
  function abandonDrop(shift, item) {
    const i = shift.assignments.indexOf(item);
    if (i !== -1) shift.assignments.splice(i, 1);
    if (pendingMoves.delete(item)) fetchSchedule({ quiet: true });
  }

  // Resolves to { status: 'assigned' | 'prompted' (waiting on the rest-day dialog) | 'failed', updatedCount }.
  // laterRestPolicy goes with the ids of the later shifts the manager was shown; if the server's list
  // differs by now, it asks again. confirmShortRest is the manager's go-ahead for less than 11 h of
  // daily rest (the server asks with 409 short_daily_rest, listing the shifts too close).
  // seenShiftIds: shifts the manager already accepted next to this one in the Back-to-Back dialog. When
  // the server's short-rest list is only those shifts, that answer stands and it isn't asked twice;
  // anything else (another group's shift, an overlap, an on-call callback) is still asked.
  async function doAssign(
    shift,
    item,
    index,
    restPolicy = null,
    laterRestPolicy = null,
    expectedAffectedIds = null,
    confirmShortRest = false,
    seenShiftIds = [],
  ) {
    const moveFrom = pendingMoves.get(item);
    try {
      const response = await api.post('/api/schedule/assign', {
        shift_id: shift.id,
        user_id: item.user_id,
        ...(moveFrom ? { move_from_assignment_id: moveFrom } : {}),
        ...(restPolicy ? { rest_policy: restPolicy } : {}),
        ...(laterRestPolicy
          ? { later_rest_policy: laterRestPolicy, expected_affected_ids: expectedAffectedIds || [] }
          : {}),
        ...(confirmShortRest ? { confirm_short_rest: true } : {}),
      });
      pendingMoves.delete(item);
      item.id = response.data.id;
      item.user_name = response.data.user_name;
      item.rest_policy = response.data.rest_policy;
      const updatedIds = response.data.updated_assignment_ids || [];
      // Any assignment can start, move or end a rest window, so other chips' ⚠ / ⏸ / resting-until
      // can change: reload them from the server.
      await fetchSchedule({ quiet: true });
      return { status: 'assigned', updatedCount: updatedIds.length };
    } catch (err) {
      // A rest day needs the manager's decision; keep the chip while asking.
      const code = err.response?.data?.code;
      if (
        err.response?.status === 409 &&
        (code === 'rest_window_conflict' || code === 'creates_rest_conflict')
      ) {
        restPrompt.value = {
          shift,
          item,
          index,
          conflict: err.response.data,
          restPolicy,
          seenShiftIds,
        };
        return { status: 'prompted', updatedCount: 0 };
      }
      if (err.response?.status === 409 && code === 'short_daily_rest') {
        // An overlap (booked twice at the same time) is the harder case: say so in the title too.
        const overlaps = err.response.data.overlaps === true;
        const conflicts = err.response.data.conflicts ?? [];
        const alreadyAccepted =
          conflicts.length > 0 &&
          conflicts.every(
            (c) => c.type === 'shift' && !c.overlaps && seenShiftIds.includes(c.shift_id),
          );
        const ok =
          alreadyAccepted ||
          (await confirm({
            title: overlaps ? 'Overlapping shift' : 'Less than 11 hours of rest',
            message: `${err.response.data.message} Assign anyway?`,
            confirmLabel: 'Assign anyway',
          }));
        if (!ok) {
          abandonDrop(shift, item);
          return { status: 'failed', updatedCount: 0 };
        }
        // Same request with the manager's go-ahead; earlier answers (rest-day policies) go along.
        return doAssign(shift, item, index, restPolicy, laterRestPolicy, expectedAffectedIds, true);
      }
      abandonDrop(shift, item);
      const msg = err.response?.data?.message || 'Failed to assign user.';
      showToast(msg, 'error');
      return { status: 'failed', updatedCount: 0 };
    }
  }

  async function chooseRestPolicy(policy) {
    const { shift, item, index, conflict, restPolicy, seenShiftIds } = restPrompt.value;
    restPrompt.value = null;
    // A later-shift question carries the answer to the dropped day's own question, if it was asked.
    const { status, updatedCount } =
      conflict.code === 'creates_rest_conflict'
        ? await doAssign(
            shift,
            item,
            index,
            restPolicy,
            policy,
            (conflict.affected || []).map((a) => a.assignment_id),
            false,
            seenShiftIds,
          )
        : await doAssign(shift, item, index, policy, null, null, false, seenShiftIds);
    if (status === 'assigned') {
      showToast(
        updatedCount === 0
          ? conflict.code === 'rest_window_conflict' || restPolicy
            ? 'Assigned during rest.'
            : 'Assigned.'
          : updatedCount === 1
            ? 'Assigned; later shift updated.'
            : 'Assigned; later shifts updated.',
        'success',
      );
    }
  }

  function cancelRestPolicy() {
    const { shift, item } = restPrompt.value;
    restPrompt.value = null;
    abandonDrop(shift, item);
  }

  function cancelAvailConfirm() {
    const { shift, item } = availConfirm.value;
    if (shift && item) abandonDrop(shift, item);
    availConfirm.value = {
      show: false,
      shift: null,
      item: null,
      itemIndex: null,
      userName: '',
      dateStr: '',
      entries: [],
    };
  }

  async function confirmAvailAssign() {
    const { shift, item, itemIndex, dateStr } = availConfirm.value;
    availConfirm.value.show = false;
    if (shift && item) {
      // After confirming unavailability, check delegation conflict
      await proceedWithDelegationCheck(shift, item, itemIndex, dateStr);
    }
    availConfirm.value = {
      show: false,
      shift: null,
      item: null,
      itemIndex: null,
      userName: '',
      dateStr: '',
      entries: [],
    };
  }

  async function proceedWithDelegationCheck(shift, item, itemIndex, dateStr) {
    if (delegationsFailed.value) {
      showToast("Couldn't check delegations: the month's delegations failed to load.", 'warning');
    }
    // Check delegation conflict
    try {
      const matchingDelegations = monthDelegations.value.filter((d) => {
        const start = d.start_datetime.substring(0, 10);
        const end = d.end_datetime.substring(0, 10);
        if (dateStr < start || dateStr > end) return false;
        return d.users.some((u) => u.id === item.user_id);
      });
      if (matchingDelegations.length > 0) {
        delegConfirm.value = {
          show: true,
          shift,
          item,
          itemIndex,
          userName: item.user_name || item.name || 'User',
          dateStr,
          delegations: matchingDelegations,
        };
        return;
      }
    } catch {
      /* proceed */
    }

    await proceedWithAdjacentCheck(shift, item, itemIndex, dateStr);
  }

  function cancelDelegConfirm() {
    const { shift, item } = delegConfirm.value;
    if (shift && item) abandonDrop(shift, item);
    delegConfirm.value = {
      show: false,
      shift: null,
      item: null,
      itemIndex: null,
      userName: '',
      dateStr: '',
      delegations: [],
    };
  }

  async function confirmDelegAssign() {
    const { shift, item, itemIndex, dateStr } = delegConfirm.value;
    delegConfirm.value.show = false;
    if (shift && item) {
      await proceedWithAdjacentCheck(shift, item, itemIndex, dateStr);
    }
    delegConfirm.value = {
      show: false,
      shift: null,
      item: null,
      itemIndex: null,
      userName: '',
      dateStr: '',
      delegations: [],
    };
  }

  function cancelAdjacentConfirm() {
    const { shift, item } = adjacentConfirm.value;
    if (shift && item) abandonDrop(shift, item);
    adjacentConfirm.value = {
      show: false,
      shift: null,
      item: null,
      itemIndex: null,
      userName: '',
      dateStr: '',
      conflicts: [],
    };
  }

  async function confirmAdjacentAssign() {
    const { shift, item, itemIndex, conflicts } = adjacentConfirm.value;
    adjacentConfirm.value.show = false;
    if (shift && item) {
      const seen = conflicts.map((c) => c.shiftId);
      const { status } = await doAssign(shift, item, itemIndex, null, null, null, false, seen);
      if (status === 'assigned') showToast('Assigned to back-to-back shift.', 'success');
    }
    adjacentConfirm.value = {
      show: false,
      shift: null,
      item: null,
      itemIndex: null,
      userName: '',
      dateStr: '',
      conflicts: [],
    };
  }

  async function removeAssignment(assignment, shift) {
    if (!assignment.id) {
      // Not yet saved, just remove from UI
      const idx = shift.assignments.findIndex((a) => a === assignment);
      if (idx !== -1) shift.assignments.splice(idx, 1);
      return;
    }
    try {
      await api.delete(`/api/schedule/unassign/${assignment.id}`);
      const idx = shift.assignments.findIndex((a) => a.id === assignment.id);
      if (idx !== -1) shift.assignments.splice(idx, 1);
    } catch {
      showToast('Failed to remove assignment.', 'error');
      return;
    }
    // Removing a shift can end or move a rest window: refresh the other chips' ⚠ / ⏸.
    await fetchSchedule({ quiet: true });
  }

  return {
    availConfirm,
    adjacentConfirm,
    delegConfirm,
    onDrop,
    chooseRestPolicy,
    cancelRestPolicy,
    cancelAvailConfirm,
    confirmAvailAssign,
    cancelDelegConfirm,
    confirmDelegAssign,
    cancelAdjacentConfirm,
    confirmAdjacentAssign,
    removeAssignment,
  };
}
