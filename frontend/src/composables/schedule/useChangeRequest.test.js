// frontend/src/composables/schedule/useChangeRequest.test.js
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { ref } from 'vue';
import { localDateStr } from '../../dates.js';
import { api, httpError } from '../../testing/fakeApi.js';
import { useChangeRequest } from './useChangeRequest.js';

vi.mock('../../axios.js', async () => ({
  default: (await import('../../testing/fakeApi.js')).api,
}));

const DAY = '2027-03-10';

function setup(schedule = {}) {
  const showToast = vi.fn();
  const scheduleRef = ref(schedule);
  const cr = useChangeRequest({ schedule: scheduleRef, showToast });
  return { cr, schedule: scheduleRef, showToast };
}

const assignment = { id: 77, user_id: 7, user_name: 'Regular Employee', has_change_request: false };
const shift = () => ({ id: 12, shift_number: 2, assignments: [{ ...assignment }] });

const plusDays = (n) => {
  const d = new Date();
  d.setDate(d.getDate() + n);
  return localDateStr(d);
};

beforeEach(() => api.reset());

describe('isShiftFuture', () => {
  test('today and later count as future; yesterday and a missing date do not', () => {
    const { cr } = setup();
    expect(cr.isShiftFuture(null, plusDays(0))).toBe(true);
    expect(cr.isShiftFuture(null, plusDays(1))).toBe(true);
    expect(cr.isShiftFuture(null, plusDays(-1))).toBe(false);
    expect(cr.isShiftFuture(null, '')).toBe(false);
    expect(cr.isShiftFuture(null, undefined)).toBe(false);
  });
});

describe('the Request Day Off dialog', () => {
  test('opens for the chip with a blank reason and the shift named by its full date', () => {
    const { cr } = setup();
    cr.openChangeRequestModal(assignment, shift(), DAY);
    expect(cr.changeRequestModal.value).toEqual({
      show: true,
      assignmentId: 77,
      shiftNumber: 2,
      dateLabel: 'Wednesday, March 10, 2027',
      reason: '',
      error: '',
      submitting: false,
    });
  });

  test('refuses an empty or blank reason without a request', async () => {
    const { cr } = setup();
    cr.openChangeRequestModal(assignment, shift(), DAY);
    cr.changeRequestModal.value.reason = '   ';
    await cr.submitChangeRequest();
    expect(cr.changeRequestModal.value.error).toBe('Please provide a reason.');
    expect(cr.changeRequestModal.value.show).toBe(true);
    expect(api.calls).toEqual([]);
  });

  test('files the trimmed reason, closes, and marks the chip in the month without a reload', async () => {
    api.on('post', '/api/shift-change-requests', { id: 900 });
    const { cr, schedule, showToast } = setup({ [DAY]: [shift()] });
    cr.openChangeRequestModal(assignment, shift(), DAY);
    cr.changeRequestModal.value.reason = '  Dentist appointment  ';

    await cr.submitChangeRequest();

    expect(api.calls).toEqual([
      {
        method: 'post',
        url: '/api/shift-change-requests',
        body: { shift_assignment_id: 77, reason: 'Dentist appointment' },
      },
    ]);
    expect(cr.changeRequestModal.value.show).toBe(false);
    expect(cr.changeRequestModal.value.submitting).toBe(false);
    expect(showToast).toHaveBeenCalledWith('Day-off request submitted successfully.', 'success');
    expect(schedule.value[DAY][0].assignments[0]).toMatchObject({
      has_change_request: true,
      change_request_reason: 'Dentist appointment',
      change_request_id: 900,
    });
  });

  test('a response without an id still marks the chip, with a null request id', async () => {
    api.on('post', '/api/shift-change-requests', {});
    const { cr, schedule } = setup({ [DAY]: [shift()] });
    cr.openChangeRequestModal(assignment, shift(), DAY);
    cr.changeRequestModal.value.reason = 'Sick';
    await cr.submitChangeRequest();
    expect(schedule.value[DAY][0].assignments[0].change_request_id).toBeNull();
  });

  test('a refused request keeps the dialog open and shows the server’s reason, or a fallback', async () => {
    api.on('post', '/api/shift-change-requests', () => {
      throw httpError(422, { message: 'This shift is in the past.' });
    });
    const { cr, schedule, showToast } = setup({ [DAY]: [shift()] });
    cr.openChangeRequestModal(assignment, shift(), DAY);
    cr.changeRequestModal.value.reason = 'Sick';

    await cr.submitChangeRequest();

    expect(cr.changeRequestModal.value).toMatchObject({
      show: true,
      submitting: false,
      error: 'This shift is in the past.',
    });
    expect(schedule.value[DAY][0].assignments[0].has_change_request).toBe(false);
    expect(showToast).not.toHaveBeenCalled();

    api.on('post', '/api/shift-change-requests', () => {
      throw httpError(500);
    });
    await cr.submitChangeRequest();
    expect(cr.changeRequestModal.value.error).toBe('Failed to submit request.');
  });
});
