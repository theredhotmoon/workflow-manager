import { expect, test } from '@playwright/test';
import {
  removedState,
  requestGroup,
  requestShiftDate,
  scheduleLink,
  shiftTimeLabel,
  statusText,
} from './changeRequests.js';

const live = {
  id: 7,
  user_id: 12,
  status: 'pending',
  shift_date: '2026-10-20',
  shift_number: 2,
  shift_removed: false,
  assignment_removed: false,
  shift: {
    id: 40,
    date: '2026-10-20',
    shift_number: 2,
    start_time: '08:00:00',
    end_time: '16:00:00',
    shift_group: { id: 3, name: 'Main', archived_at: null },
  },
};

test.describe('change request helpers', () => {
  test('a live request: date, slot and times, group, schedule link on its group tab', () => {
    expect(requestShiftDate(live)).toBe('2026-10-20');
    expect(shiftTimeLabel(live)).toBe('Shift #2 · 08:00–16:00');
    expect(requestGroup(live)).toEqual({ id: 3, name: 'Main', archived: false });
    expect(removedState(live)).toBeNull();
    expect(scheduleLink(live)).toEqual({
      name: 'schedule',
      params: { month: '2026-10' },
      query: { scroll_date: '2026-10-20', highlight_user: '12', group: '3' },
    });
  });

  test('a removed shift keeps its date and slot, loses times and group, still links to the day', () => {
    const removed = {
      ...live,
      shift_id: null,
      shift: null,
      shift_removed: true,
      assignment_removed: true,
    };
    expect(removedState(removed)).toEqual({ kind: 'shift', label: 'Shift removed' });
    expect(shiftTimeLabel(removed)).toBe('Shift #2');
    expect(requestGroup(removed)).toBeNull();
    expect(scheduleLink(removed)).toEqual({
      name: 'schedule',
      params: { month: '2026-10' },
      query: { scroll_date: '2026-10-20', highlight_user: '12' },
    });
  });

  test('a removed assignment is flagged, the shift still shown', () => {
    const unassigned = { ...live, assignment_removed: true, status: 'acknowledged' };
    expect(removedState(unassigned)).toEqual({ kind: 'assignment', label: 'Assignment removed' });
    expect(statusText(unassigned)).toBe('Settled');
  });

  test('an archived group is flagged and gets no tab in the link', () => {
    const archived = {
      ...live,
      shift: { ...live.shift, shift_group: { id: 3, name: 'Old', archived_at: '2026-10-01' } },
    };
    expect(requestGroup(archived).archived).toBe(true);
    expect(scheduleLink(archived).query).not.toHaveProperty('group');
  });

  test('status text names the acknowledger when known', () => {
    expect(statusText(live)).toBe('Pending');
    expect(
      statusText({ ...live, status: 'acknowledged', acknowledged_by: { id: 1, name: 'Ada' } }),
    ).toBe('Acknowledged by Ada');
    expect(statusText({ ...live, status: 'acknowledged', acknowledged_by: null })).toBe(
      'Acknowledged',
    );
  });

  test('no date, no link', () => {
    expect(scheduleLink({ id: 1 })).toBeNull();
    expect(requestShiftDate({ shift_date: '2026-10-20T00:00:00.000000Z' })).toBe('2026-10-20');
  });
});
