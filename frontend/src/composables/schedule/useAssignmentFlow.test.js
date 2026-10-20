// frontend/src/composables/schedule/useAssignmentFlow.test.js
//
// The drop-to-assign flow without a browser: the fake API records every request, so the order of
// the checks (availability → delegation → back-to-back → assign) and the exact request bodies
// (move_from_assignment_id, rest policies, confirm_short_rest) are asserted directly.
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { ref } from 'vue';
import { api, httpError } from '../../testing/fakeApi.js';
import { confirm } from '../useConfirm.js';
import { useAssignmentFlow } from './useAssignmentFlow.js';

vi.mock('../../axios.js', async () => ({
  default: (await import('../../testing/fakeApi.js')).api,
}));
vi.mock('../useConfirm.js', () => ({ confirm: vi.fn() }));

const MONTH = '2027-03';
const DAY = '2027-03-10';
const PREV_DAY = '2027-03-09';
const NEXT_DAY = '2027-03-11';
const EMPLOYEE = { user_id: 7, user_name: 'Regular Employee' };
const AVAILABILITY_URL = /^\/api\/user-availabilities\/\d+\/month$/;

function shift(
  id,
  { shift_number = 1, start_time = '08:00', end_time = '16:00', assignments = [] } = {},
) {
  return { id, shift_number, start_time, end_time, assignments };
}

function setup({ schedule = {}, delegations = [], delegationsFailed = false } = {}) {
  const scheduleRef = ref(schedule);
  const restPrompt = ref(null);
  const showToast = vi.fn();
  const fetchSchedule = vi.fn(async () => true);
  const flow = useAssignmentFlow({
    schedule: scheduleRef,
    monthKey: ref(MONTH),
    monthDelegations: ref(delegations),
    delegationsFailed: ref(delegationsFailed),
    restPrompt,
    fetchSchedule,
    showToast,
  });
  return { flow, schedule: scheduleRef, restPrompt, showToast, fetchSchedule };
}

// The server allows everything: no unavailability, every assign succeeds.
function allowEverything() {
  api.on('get', AVAILABILITY_URL, []);
  api.on('post', '/api/schedule/assign', (call) => ({
    id: 500 + call.body.shift_id,
    user_name: EMPLOYEE.user_name,
    rest_policy: call.body.rest_policy ?? null,
    updated_assignment_ids: [],
  }));
}

/** First answer per call, in order; the last one repeats. */
function answers(...responses) {
  let n = 0;
  return (call) => {
    const r = responses[Math.min(n++, responses.length - 1)];
    return typeof r === 'function' ? r(call) : r;
  };
}

const ROSTER = {}; // Sortable's `from` for a roster card: no data-shift-id
const list = (s) => ({ dataset: { shiftId: String(s.id) } });

// Sortable has already put the chip into the target list when onDrop runs.
function drop(flow, target, dateStr, item = { ...EMPLOYEE }, from = ROSTER) {
  target.assignments.push(item);
  const evt = { newIndex: target.assignments.length - 1, from, to: list(target) };
  return flow.onDrop(evt, target, dateStr);
}

beforeEach(() => {
  api.reset();
  vi.clearAllMocks();
});

describe('a plain drop', () => {
  test('checks availability, posts exactly the shift and user, fills in the chip and reloads quietly', async () => {
    allowEverything();
    const { flow, schedule, showToast, fetchSchedule } = setup({ schedule: { [DAY]: [shift(1)] } });
    const target = schedule.value[DAY][0];

    await drop(flow, target, DAY);

    expect(api.calls).toEqual([
      { method: 'get', url: '/api/user-availabilities/7/month', params: { month: MONTH } },
      { method: 'post', url: '/api/schedule/assign', body: { shift_id: 1, user_id: 7 } },
    ]);
    expect(target.assignments).toEqual([
      { user_id: 7, user_name: 'Regular Employee', id: 501, rest_policy: null },
    ]);
    expect(fetchSchedule).toHaveBeenCalledWith({ quiet: true });
    expect(showToast).not.toHaveBeenCalled();
  });

  test('a chip without a user (nothing dropped) is ignored', async () => {
    const { flow, schedule } = setup({ schedule: { [DAY]: [shift(1)] } });
    const target = schedule.value[DAY][0];
    target.assignments.push({});
    await flow.onDrop({ newIndex: 0, from: ROSTER, to: list(target) }, target, DAY);
    expect(api.calls).toEqual([]);
  });
});

describe('one chip per person and shift', () => {
  test('a second drop of someone already on the shift is taken back off with a warning and no request', async () => {
    const pending = { ...EMPLOYEE }; // first drop, still being saved (no id yet)
    const { flow, schedule, showToast, fetchSchedule } = setup({
      schedule: { [DAY]: [shift(1, { assignments: [pending] })] },
    });
    const target = schedule.value[DAY][0];

    await drop(flow, target, DAY);

    expect(target.assignments).toHaveLength(1);
    expect(showToast).toHaveBeenCalledWith('Regular Employee is already on this shift.', 'warning');
    expect(api.calls).toEqual([]);
    expect(fetchSchedule).not.toHaveBeenCalled();
  });

  test('when the duplicate came from another shift, that shift is reloaded so its chip comes back', async () => {
    const { flow, schedule, fetchSchedule } = setup({
      schedule: { [DAY]: [shift(1, { assignments: [{ ...EMPLOYEE, id: 40 }] }), shift(2)] },
    });
    const [target, source] = schedule.value[DAY];

    await drop(flow, target, DAY, { ...EMPLOYEE, id: 41 }, list(source));

    expect(target.assignments).toHaveLength(1);
    expect(fetchSchedule).toHaveBeenCalledWith({ quiet: true });
    expect(api.calls).toEqual([]);
  });
});

describe('unavailable days', () => {
  const unavailable = (date) => ({
    type: 'unavailable',
    start_date: date,
    end_date: date,
    start_time: null,
    end_time: null,
    note: 'away',
  });

  test('opens the Unavailable dialog instead of assigning; Cancel takes the chip back, Assign Anyway assigns', async () => {
    api.on('get', AVAILABILITY_URL, [
      unavailable(DAY),
      { type: 'preferred', start_date: DAY, end_date: DAY },
      unavailable('2027-03-20'),
    ]);
    api.on('post', '/api/schedule/assign', { id: 9, user_name: 'Regular Employee' });
    const { flow, schedule } = setup({ schedule: { [DAY]: [shift(1)] } });
    const target = schedule.value[DAY][0];

    await drop(flow, target, DAY);
    expect(flow.availConfirm.value).toMatchObject({
      show: true,
      userName: 'Regular Employee',
      dateStr: DAY,
      entries: [unavailable(DAY)],
    });
    expect(api.sent('post')).toEqual([]);

    flow.cancelAvailConfirm();
    expect(flow.availConfirm.value.show).toBe(false);
    expect(target.assignments).toEqual([]);
    expect(api.sent('post')).toEqual([]);

    await drop(flow, target, DAY);
    await flow.confirmAvailAssign();
    expect(api.sent('post')).toHaveLength(1);
    expect(target.assignments[0].id).toBe(9);
    expect(flow.availConfirm.value).toMatchObject({ show: false, shift: null, item: null });
  });

  test('an overnight shift also checks the next day; a day shift does not', async () => {
    api.on('get', AVAILABILITY_URL, [unavailable(NEXT_DAY)]);
    api.on('post', '/api/schedule/assign', { id: 9 });
    const { flow, schedule } = setup({
      schedule: {
        [DAY]: [
          shift(1, { start_time: '22:00', end_time: '06:00' }),
          shift(2, { shift_number: 2, start_time: '08:00', end_time: '16:00' }),
        ],
      },
    });
    const [overnight, day] = schedule.value[DAY];

    await drop(flow, overnight, DAY);
    expect(flow.availConfirm.value.show).toBe(true);
    flow.cancelAvailConfirm();

    await drop(flow, day, DAY);
    expect(flow.availConfirm.value.show).toBe(false);
    expect(api.sent('post')).toHaveLength(1);
  });

  test('when the availability check fails, the drop goes on with a warning', async () => {
    api.on('get', AVAILABILITY_URL, () => {
      throw httpError(500);
    });
    api.on('post', '/api/schedule/assign', { id: 9 });
    const { flow, schedule, showToast } = setup({ schedule: { [DAY]: [shift(1)] } });

    await drop(flow, schedule.value[DAY][0], DAY);

    expect(showToast).toHaveBeenCalledWith(
      "Couldn't check this employee's availability.",
      'warning',
    );
    expect(api.sent('post')).toHaveLength(1);
  });
});

describe('delegations', () => {
  const delegation = (userId, start = PREV_DAY, end = NEXT_DAY) => ({
    id: 3,
    name: 'Audit',
    start_datetime: `${start} 08:00:00`,
    end_datetime: `${end} 18:00:00`,
    users: [{ id: userId }],
  });

  test('a delegation covering the day asks first; Cancel takes the chip back, confirming assigns', async () => {
    allowEverything();
    const { flow, schedule } = setup({
      schedule: { [DAY]: [shift(1)] },
      delegations: [delegation(7), delegation(8)],
    });
    const target = schedule.value[DAY][0];

    await drop(flow, target, DAY);
    expect(flow.delegConfirm.value).toMatchObject({ show: true, dateStr: DAY });
    expect(flow.delegConfirm.value.delegations).toEqual([delegation(7)]);
    expect(api.sent('post')).toEqual([]);

    flow.cancelDelegConfirm();
    expect(target.assignments).toEqual([]);

    await drop(flow, target, DAY);
    await flow.confirmDelegAssign();
    expect(api.sent('post')).toHaveLength(1);
    expect(flow.delegConfirm.value.show).toBe(false);
  });

  test('a delegation on other days, or of someone else, does not ask', async () => {
    allowEverything();
    const { flow, schedule } = setup({
      schedule: { [DAY]: [shift(1)] },
      delegations: [delegation(7, '2027-03-11', '2027-03-12'), delegation(8)],
    });
    await drop(flow, schedule.value[DAY][0], DAY);
    expect(flow.delegConfirm.value.show).toBe(false);
    expect(api.sent('post')).toHaveLength(1);
  });

  test("when the month's delegations failed to load, it warns and still assigns", async () => {
    allowEverything();
    const { flow, schedule, showToast } = setup({
      schedule: { [DAY]: [shift(1)] },
      delegationsFailed: true,
    });
    await drop(flow, schedule.value[DAY][0], DAY);
    expect(showToast).toHaveBeenCalledWith(
      "Couldn't check delegations: the month's delegations failed to load.",
      'warning',
    );
    expect(api.sent('post')).toHaveLength(1);
  });
});

describe('back-to-back shifts', () => {
  // Three days, two slots each: S1 08:00–16:00, S2 16:00–23:00 (22:00–06:00, overnight, for one case).
  function threeDays({ overnightLast = false } = {}) {
    const s2 = (id) =>
      shift(id, {
        shift_number: 2,
        start_time: overnightLast ? '22:00' : '16:00',
        end_time: overnightLast ? '06:00' : '23:00',
      });
    return {
      [PREV_DAY]: [shift(11), s2(12)],
      [DAY]: [shift(21), s2(22)],
      [NEXT_DAY]: [shift(31), s2(32)],
    };
  }
  const worked = { ...EMPLOYEE, id: 77 };

  test.each([
    ['shift before', DAY, 1, DAY, 21, 'shift before'],
    ['shift after', DAY, 0, DAY, 22, 'shift after'],
    ['last shift previous day', DAY, 0, PREV_DAY, 12, 'last shift previous day'],
    ['first shift next day', DAY, 1, NEXT_DAY, 31, 'first shift next day'],
  ])(
    'dropping next to the %s asks about it',
    async (_label, date, slot, workedDate, workedId, position) => {
      allowEverything();
      const schedule = threeDays();
      schedule[workedDate].find((s) => s.id === workedId).assignments.push({ ...worked });
      const { flow, schedule: scheduleRef } = setup({ schedule });

      await drop(flow, scheduleRef.value[date][slot], date);

      expect(flow.adjacentConfirm.value.show).toBe(true);
      expect(flow.adjacentConfirm.value.conflicts).toEqual([
        expect.objectContaining({ date: workedDate, shiftId: workedId, position }),
      ]);
      expect(api.sent('post')).toEqual([]);
    },
  );

  test('an overnight shift the person works the day before is named as such, with its end time', async () => {
    allowEverything();
    const schedule = threeDays({ overnightLast: true });
    schedule[PREV_DAY][1].assignments.push({ ...worked });
    const { flow, schedule: scheduleRef } = setup({ schedule });

    await drop(flow, scheduleRef.value[DAY][0], DAY);

    expect(flow.adjacentConfirm.value.conflicts[0]).toMatchObject({
      shiftId: 12,
      startTime: '22:00',
      endTime: '06:00',
      position: 'overnight shift from previous day (ends 06:00)',
    });
  });

  test("an overnight target reaches into the next day's first shift", async () => {
    allowEverything();
    const schedule = threeDays({ overnightLast: true });
    schedule[NEXT_DAY][0].assignments.push({ ...worked });
    const { flow, schedule: scheduleRef } = setup({ schedule });

    await drop(flow, scheduleRef.value[DAY][1], DAY);

    expect(flow.adjacentConfirm.value.conflicts).toEqual([
      expect.objectContaining({
        shiftId: 31,
        position: 'first shift next day (your shift extends overnight)',
      }),
    ]);
  });

  test('Cancel takes the chip back; confirming assigns and says so', async () => {
    allowEverything();
    const schedule = threeDays();
    schedule[DAY][0].assignments.push({ ...worked });
    const { flow, schedule: scheduleRef, showToast } = setup({ schedule });
    const target = scheduleRef.value[DAY][1];

    await drop(flow, target, DAY);
    flow.cancelAdjacentConfirm();
    expect(target.assignments).toEqual([]);
    expect(api.sent('post')).toEqual([]);

    await drop(flow, target, DAY);
    await flow.confirmAdjacentAssign();
    expect(api.sent('post')).toHaveLength(1);
    expect(showToast).toHaveBeenCalledWith('Assigned to back-to-back shift.', 'success');
    expect(flow.adjacentConfirm.value.show).toBe(false);
  });

  test('the short-rest question is not asked again for the shifts already accepted here', async () => {
    api.on('get', AVAILABILITY_URL, []);
    api.on(
      'post',
      '/api/schedule/assign',
      answers(
        () => {
          throw httpError(409, {
            code: 'short_daily_rest',
            message: 'Only 0 h of rest.',
            conflicts: [{ type: 'shift', shift_id: 21, overlaps: false }],
          });
        },
        { id: 9, updated_assignment_ids: [] },
      ),
    );
    const schedule = threeDays();
    schedule[DAY][0].assignments.push({ ...worked });
    const { flow, schedule: scheduleRef } = setup({ schedule });

    await drop(flow, scheduleRef.value[DAY][1], DAY);
    await flow.confirmAdjacentAssign();

    expect(confirm).not.toHaveBeenCalled();
    expect(api.sent('post').map((c) => c.body)).toEqual([
      { shift_id: 22, user_id: 7 },
      { shift_id: 22, user_id: 7, confirm_short_rest: true },
    ]);
  });
});

describe('less than 11 hours of rest (409 short_daily_rest)', () => {
  const shortRest =
    (overrides = {}) =>
    () => {
      throw httpError(409, {
        code: 'short_daily_rest',
        message: 'Only 9 h of rest before the next shift.',
        conflicts: [{ type: 'shift', shift_id: 99, overlaps: false }],
        ...overrides,
      });
    };

  test('asks; declined, the chip is taken back and nothing more is sent', async () => {
    api.on('get', AVAILABILITY_URL, []);
    api.on('post', '/api/schedule/assign', shortRest());
    confirm.mockResolvedValueOnce(false);
    const { flow, schedule, showToast } = setup({ schedule: { [DAY]: [shift(1)] } });
    const target = schedule.value[DAY][0];

    await drop(flow, target, DAY);

    expect(confirm).toHaveBeenCalledWith({
      title: 'Less than 11 hours of rest',
      message: 'Only 9 h of rest before the next shift. Assign anyway?',
      confirmLabel: 'Assign anyway',
    });
    expect(target.assignments).toEqual([]);
    expect(api.sent('post')).toHaveLength(1);
    expect(showToast).not.toHaveBeenCalled();
  });

  test('accepted, the same request goes again with confirm_short_rest', async () => {
    api.on('get', AVAILABILITY_URL, []);
    api.on(
      'post',
      '/api/schedule/assign',
      answers(shortRest(), { id: 9, updated_assignment_ids: [] }),
    );
    confirm.mockResolvedValueOnce(true);
    const { flow, schedule } = setup({ schedule: { [DAY]: [shift(1)] } });
    const target = schedule.value[DAY][0];

    await drop(flow, target, DAY);

    expect(api.sent('post').map((c) => c.body)).toEqual([
      { shift_id: 1, user_id: 7 },
      { shift_id: 1, user_id: 7, confirm_short_rest: true },
    ]);
    expect(target.assignments[0].id).toBe(9);
  });

  test('an overlap is named as such in the title', async () => {
    api.on('get', AVAILABILITY_URL, []);
    api.on('post', '/api/schedule/assign', shortRest({ overlaps: true }));
    confirm.mockResolvedValueOnce(false);
    const { flow, schedule } = setup({ schedule: { [DAY]: [shift(1)] } });

    await drop(flow, schedule.value[DAY][0], DAY);

    expect(confirm).toHaveBeenCalledWith(expect.objectContaining({ title: 'Overlapping shift' }));
  });
});

describe('rest-day prompts (409 rest conflicts)', () => {
  const restWindow = () => {
    throw httpError(409, {
      code: 'rest_window_conflict',
      message: 'On a rest day.',
      resting_until: '2027-03-12',
      rule: { shift_type: 'night' },
    });
  };
  const laterConflict = () => {
    throw httpError(409, {
      code: 'creates_rest_conflict',
      affected: [{ assignment_id: 31 }, { assignment_id: 32 }],
    });
  };

  test('keeps the chip, opens the prompt without a toast, and resends with the chosen policy', async () => {
    api.on('get', AVAILABILITY_URL, []);
    api.on(
      'post',
      '/api/schedule/assign',
      answers(restWindow, (call) => ({
        id: 9,
        rest_policy: call.body.rest_policy,
        updated_assignment_ids: [],
      })),
    );
    const { flow, schedule, restPrompt, showToast } = setup({ schedule: { [DAY]: [shift(1)] } });
    const target = schedule.value[DAY][0];

    await drop(flow, target, DAY);
    expect(restPrompt.value).toMatchObject({
      shift: target,
      index: 0,
      restPolicy: null,
      conflict: { code: 'rest_window_conflict', resting_until: '2027-03-12' },
    });
    expect(target.assignments).toHaveLength(1);
    expect(showToast).not.toHaveBeenCalled();

    await flow.chooseRestPolicy('counts');
    expect(api.sent('post').map((c) => c.body)).toEqual([
      { shift_id: 1, user_id: 7 },
      { shift_id: 1, user_id: 7, rest_policy: 'counts' },
    ]);
    expect(target.assignments[0]).toMatchObject({ id: 9, rest_policy: 'counts' });
    expect(restPrompt.value).toBeNull();
    expect(showToast).toHaveBeenCalledWith('Assigned during rest.', 'success');
  });

  test('a later-shift conflict sends the policy with the ids that were shown, and reports the updated shifts', async () => {
    api.on('get', AVAILABILITY_URL, []);
    api.on(
      'post',
      '/api/schedule/assign',
      answers(laterConflict, { id: 9, updated_assignment_ids: [31, 32] }),
    );
    const { flow, schedule, showToast } = setup({ schedule: { [DAY]: [shift(1)] } });

    await drop(flow, schedule.value[DAY][0], DAY);
    await flow.chooseRestPolicy('extends_rest');

    expect(api.sent('post')[1].body).toEqual({
      shift_id: 1,
      user_id: 7,
      later_rest_policy: 'extends_rest',
      expected_affected_ids: [31, 32],
    });
    expect(showToast).toHaveBeenCalledWith('Assigned; later shifts updated.', 'success');
  });

  test('one updated later shift is reported in the singular; none at all as a plain assign', async () => {
    api.on('get', AVAILABILITY_URL, []);
    api.on(
      'post',
      '/api/schedule/assign',
      answers(laterConflict, { id: 9, updated_assignment_ids: [31] }),
    );
    const first = setup({ schedule: { [DAY]: [shift(1)] } });
    await drop(first.flow, first.schedule.value[DAY][0], DAY);
    await first.flow.chooseRestPolicy('counts');
    expect(first.showToast).toHaveBeenCalledWith('Assigned; later shift updated.', 'success');

    api.on(
      'post',
      '/api/schedule/assign',
      answers(laterConflict, { id: 9, updated_assignment_ids: [] }),
    );
    const second = setup({ schedule: { [DAY]: [shift(1)] } });
    await drop(second.flow, second.schedule.value[DAY][0], DAY);
    await second.flow.chooseRestPolicy('counts');
    expect(second.showToast).toHaveBeenCalledWith('Assigned.', 'success');
  });

  test('Cancel on the prompt takes the chip back', async () => {
    api.on('get', AVAILABILITY_URL, []);
    api.on('post', '/api/schedule/assign', restWindow);
    const { flow, schedule, restPrompt } = setup({ schedule: { [DAY]: [shift(1)] } });
    const target = schedule.value[DAY][0];

    await drop(flow, target, DAY);
    flow.cancelRestPolicy();

    expect(restPrompt.value).toBeNull();
    expect(target.assignments).toEqual([]);
    expect(api.sent('post')).toHaveLength(1);
  });
});

describe('moving a chip between shifts', () => {
  test('the request names the old assignment so the server moves it in one transaction', async () => {
    allowEverything();
    const { flow, schedule } = setup({
      schedule: { [DAY]: [shift(1, { assignments: [] }), shift(2, { shift_number: 2 })] },
    });
    const [source, target] = schedule.value[DAY];

    await drop(flow, target, DAY, { ...EMPLOYEE, id: 55 }, list(source));

    expect(api.sent('post')[0].body).toEqual({
      shift_id: 2,
      user_id: 7,
      move_from_assignment_id: 55,
    });
    expect(target.assignments[0].id).toBe(502);
  });

  test('a refused move takes the chip back and reloads, so the source chip returns', async () => {
    api.on('get', AVAILABILITY_URL, []);
    api.on('post', '/api/schedule/assign', () => {
      throw httpError(422, { message: 'That shift is full.' });
    });
    const { flow, schedule, showToast, fetchSchedule } = setup({
      schedule: { [DAY]: [shift(1), shift(2, { shift_number: 2 })] },
    });
    const [source, target] = schedule.value[DAY];

    await drop(flow, target, DAY, { ...EMPLOYEE, id: 55 }, list(source));

    expect(target.assignments).toEqual([]);
    expect(showToast).toHaveBeenCalledWith('That shift is full.', 'error');
    expect(fetchSchedule).toHaveBeenCalledWith({ quiet: true });
  });

  test('a refused roster drop only removes the chip (nothing to bring back)', async () => {
    api.on('get', AVAILABILITY_URL, []);
    api.on('post', '/api/schedule/assign', () => {
      throw httpError(500, {});
    });
    const { flow, schedule, showToast, fetchSchedule } = setup({ schedule: { [DAY]: [shift(1)] } });
    const target = schedule.value[DAY][0];

    await drop(flow, target, DAY);

    expect(target.assignments).toEqual([]);
    expect(showToast).toHaveBeenCalledWith('Failed to assign user.', 'error');
    expect(fetchSchedule).not.toHaveBeenCalled();
  });
});

describe('removeAssignment', () => {
  test('an unsaved chip is just taken off, without a request', async () => {
    const pending = { ...EMPLOYEE };
    const { flow, schedule, fetchSchedule } = setup({
      schedule: { [DAY]: [shift(1, { assignments: [pending] })] },
    });
    const target = schedule.value[DAY][0];

    await flow.removeAssignment(target.assignments[0], target);

    expect(target.assignments).toEqual([]);
    expect(api.calls).toEqual([]);
    expect(fetchSchedule).not.toHaveBeenCalled();
  });

  test('a saved chip is unassigned on the server, removed, and the month reloaded quietly', async () => {
    api.on('delete', '/api/schedule/unassign/77', {});
    const { flow, schedule, fetchSchedule } = setup({
      schedule: { [DAY]: [shift(1, { assignments: [{ ...EMPLOYEE, id: 77 }] })] },
    });
    const target = schedule.value[DAY][0];

    await flow.removeAssignment(target.assignments[0], target);

    expect(api.calls).toEqual([{ method: 'delete', url: '/api/schedule/unassign/77' }]);
    expect(target.assignments).toEqual([]);
    expect(fetchSchedule).toHaveBeenCalledWith({ quiet: true });
  });

  test('a failed unassign keeps the chip and says so', async () => {
    api.on('delete', '/api/schedule/unassign/77', () => {
      throw httpError(500);
    });
    const { flow, schedule, showToast, fetchSchedule } = setup({
      schedule: { [DAY]: [shift(1, { assignments: [{ ...EMPLOYEE, id: 77 }] })] },
    });
    const target = schedule.value[DAY][0];

    await flow.removeAssignment(target.assignments[0], target);

    expect(target.assignments).toHaveLength(1);
    expect(showToast).toHaveBeenCalledWith('Failed to remove assignment.', 'error');
    expect(fetchSchedule).not.toHaveBeenCalled();
  });
});
