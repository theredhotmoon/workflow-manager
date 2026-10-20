import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { computed, ref } from 'vue';
import { api, flush, httpError } from '../../testing/fakeApi.js';
import { useScheduleMonth } from './useScheduleMonth.js';

vi.mock('../../axios.js', async () => ({
  default: (await import('../../testing/fakeApi.js')).api,
}));

// March 2026: starts on a Sunday, 31 days, ends on a Tuesday.
function setup({ activeGroupId: initialGroup = null, canManage = true } = {}) {
  const activeGroupId = ref(initialGroup);
  const isAllGroupsMode = computed(() => activeGroupId.value === 'all');
  const canManageSchedule = ref(canManage);
  const selectedEmployee = ref(null);
  const restPrompt = ref(null);
  const showToast = vi.fn();
  const month = useScheduleMonth({
    activeGroupId,
    isAllGroupsMode,
    canManageSchedule,
    selectedEmployee,
    restPrompt,
    showToast,
  });
  month.currentYear.value = 2026;
  month.currentMonth.value = 2;
  return { ...month, activeGroupId, canManageSchedule, selectedEmployee, restPrompt, showToast };
}

function stubMonthLoads({ schedule, delegations, newbies, colors, closures } = {}) {
  api.on('get', '/api/schedule', schedule ?? { schedule: {}, employees: [] });
  api.on('get', '/api/delegations/month', delegations ?? []);
  api.on('get', '/api/newbie-periods/month', newbies ?? []);
  api.on('get', '/api/shift-colors', colors ?? []);
  api.on('get', '/api/office-closures', closures ?? []);
}

/** Only Date is faked: setTimeout (flush) stays real. */
function fixToday(year, monthIndex, day) {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date(year, monthIndex, day, 12, 0, 0));
}

beforeEach(() => {
  api.reset();
  vi.clearAllMocks();
});

afterEach(() => {
  vi.useRealTimers();
});

describe('month navigation', () => {
  test('monthKey zero-pads the month and monthLabel names it', () => {
    const m = setup();
    expect(m.monthKey.value).toBe('2026-03');
    expect(m.monthLabel.value).toBe('March 2026');
    m.currentMonth.value = 10;
    expect(m.monthKey.value).toBe('2026-11');
    expect(m.monthLabel.value).toBe('November 2026');
  });

  test('prevMonth from January goes to December of the previous year', () => {
    const m = setup();
    m.currentMonth.value = 0;
    m.prevMonth();
    expect([m.currentYear.value, m.currentMonth.value]).toEqual([2025, 11]);
    m.prevMonth();
    expect([m.currentYear.value, m.currentMonth.value]).toEqual([2025, 10]);
  });

  test('nextMonth from December goes to January of the next year', () => {
    const m = setup();
    m.currentMonth.value = 11;
    m.nextMonth();
    expect([m.currentYear.value, m.currentMonth.value]).toEqual([2027, 0]);
    m.nextMonth();
    expect([m.currentYear.value, m.currentMonth.value]).toEqual([2027, 1]);
  });
});

describe('calendarWeeks', () => {
  test('a Monday-first grid: filler days carry no date, month days carry their shifts', () => {
    fixToday(2026, 2, 10);
    const m = setup();
    m.schedule.value = { '2026-03-10': [{ id: 1 }] };

    const weeks = m.calendarWeeks.value;
    expect(weeks).toHaveLength(6);
    expect(weeks.every((w) => w.length === 7)).toBe(true);

    // March 1st 2026 is a Sunday: six February days lead in, with no dateStr.
    expect(weeks[0].slice(0, 6).map((d) => d.dayNumber)).toEqual([23, 24, 25, 26, 27, 28]);
    expect(weeks[0][0]).toEqual({
      dayNumber: 23,
      dateStr: null,
      isCurrentMonth: false,
      isToday: false,
      shifts: [],
    });
    expect(weeks[0][6]).toMatchObject({
      dayNumber: 1,
      dateStr: '2026-03-01',
      isCurrentMonth: true,
      isToday: false,
      shifts: [],
    });

    // The 10th (a Tuesday) is today and carries the schedule's shifts.
    expect(weeks[2][1]).toEqual({
      dayNumber: 10,
      dateStr: '2026-03-10',
      isCurrentMonth: true,
      isToday: true,
      shifts: [{ id: 1 }],
    });

    // The 31st is a Tuesday: five April days fill the last week.
    expect(weeks[5].map((d) => d.dayNumber)).toEqual([30, 31, 1, 2, 3, 4, 5]);
    expect(weeks[5][1].dateStr).toBe('2026-03-31');
    expect(weeks[5].slice(2).every((d) => d.dateStr === null && !d.isCurrentMonth)).toBe(true);
  });

  test('a month starting on a Monday has no leading filler', () => {
    const m = setup();
    m.currentMonth.value = 5; // June 2026 starts on a Monday
    const weeks = m.calendarWeeks.value;
    expect(weeks[0][0]).toMatchObject({ dayNumber: 1, dateStr: '2026-06-01' });
    expect(weeks).toHaveLength(5);
  });
});

describe('getOvernightFromPrevDay', () => {
  test('returns the previous day shifts that end before they start, across a month boundary', () => {
    const m = setup();
    const overnight = { id: 1, start_time: '22:00', end_time: '06:00' };
    m.schedule.value = {
      '2026-03-09': [overnight, { id: 2, start_time: '06:00', end_time: '14:00' }],
      '2026-02-28': [{ id: 3, start_time: '20:00', end_time: '04:00' }],
      '2026-03-11': [{ id: 4, start_time: null, end_time: '06:00' }],
    };
    expect(m.getOvernightFromPrevDay('2026-03-10')).toEqual([overnight]);
    expect(m.getOvernightFromPrevDay('2026-03-01')).toEqual([
      { id: 3, start_time: '20:00', end_time: '04:00' },
    ]);
    expect(m.getOvernightFromPrevDay('2026-03-12')).toEqual([]); // missing start: not overnight
    expect(m.getOvernightFromPrevDay('2026-03-20')).toEqual([]); // no shifts the day before
    expect(m.getOvernightFromPrevDay(null)).toEqual([]);
  });
});

describe('fetchSchedule', () => {
  test('sends the month, and the group only when one is active', async () => {
    stubMonthLoads();
    const m = setup();
    await m.fetchSchedule();
    expect(api.sent('get', '/api/schedule')[0].params).toEqual({ month: '2026-03' });

    m.activeGroupId.value = 5;
    await m.fetchSchedule();
    expect(api.sent('get', '/api/schedule')[1].params).toEqual({
      month: '2026-03',
      shift_group_id: 5,
    });
  });

  test('applies the schedule and maps the employees onto roster cards', async () => {
    stubMonthLoads({
      schedule: {
        schedule: { '2026-03-02': [{ id: 1 }] },
        employees: [{ id: 7, name: 'Anna', role: 'employee' }],
      },
    });
    const m = setup();
    await expect(m.fetchSchedule()).resolves.toBe(true);
    expect(m.schedule.value).toEqual({ '2026-03-02': [{ id: 1 }] });
    expect(m.employeePool.value).toEqual([
      { id: 7, name: 'Anna', role: 'employee', user_id: 7, user_name: 'Anna' },
    ]);
  });

  test('takes the active group from the response, except in all-groups mode', async () => {
    stubMonthLoads({ schedule: { schedule: {}, employees: [], shift_group_id: 3 } });
    const m = setup();
    await m.fetchSchedule();
    expect(m.activeGroupId.value).toBe(3);

    const all = setup({ activeGroupId: 'all' });
    await all.fetchSchedule();
    expect(all.activeGroupId.value).toBe('all');
  });

  test('a manager also loads delegations, newbies and shift colours; closures only with the overlay on', async () => {
    stubMonthLoads({
      delegations: [{ id: 1 }],
      newbies: [{ id: 2 }],
      colors: [{ id: 3, hex: '#ff0000' }],
    });
    const m = setup();
    await m.fetchSchedule();
    await flush();
    expect(api.sent('get', '/api/delegations/month')[0].params).toEqual({ month: '2026-03' });
    expect(api.sent('get', '/api/newbie-periods/month')[0].params).toEqual({ month: '2026-03' });
    expect(api.sent('get', '/api/shift-colors')).toHaveLength(1);
    expect(api.sent('get', '/api/office-closures')).toHaveLength(0);
    expect(m.monthDelegations.value).toEqual([{ id: 1 }]);
    expect(m.shiftColors.value).toEqual([{ id: 3, hex: '#ff0000' }]);

    m.showClosures.value = true;
    await flush();
    api.reset();
    stubMonthLoads();
    await m.fetchSchedule();
    expect(api.sent('get', '/api/office-closures')[0].params).toEqual({ month: '2026-03' });
  });

  test('a non-manager gets no delegations or newbies (manager-only data)', async () => {
    stubMonthLoads();
    const m = setup({ canManage: false });
    await m.fetchSchedule();
    await flush();
    expect(api.sent('get', '/api/delegations/month')).toHaveLength(0);
    expect(api.sent('get', '/api/newbie-periods/month')).toHaveLength(0);
    expect(api.sent('get', '/api/shift-colors')).toHaveLength(1);

    m.showDelegations.value = true;
    m.showNewbies.value = true;
    await flush();
    expect(api.sent('get', '/api/delegations/month')).toHaveLength(0);
    expect(api.sent('get', '/api/newbie-periods/month')).toHaveLength(0);
  });

  test('a quiet refresh keeps loading false; a normal load shows the spinner', async () => {
    stubMonthLoads();
    const m = setup();
    expect(m.loading.value).toBe(true);
    await m.fetchSchedule();
    expect(m.loading.value).toBe(false);

    let resolve;
    api.on(
      'get',
      '/api/schedule',
      () =>
        new Promise((r) => {
          resolve = r;
        }),
    );
    const quiet = m.fetchSchedule({ quiet: true });
    expect(m.loading.value).toBe(false);
    resolve({ schedule: {}, employees: [] });
    await quiet;
    expect(m.loading.value).toBe(false);

    const loud = m.fetchSchedule();
    expect(m.loading.value).toBe(true);
    resolve({ schedule: {}, employees: [] });
    await loud;
    expect(m.loading.value).toBe(false);
  });

  test('closes the rest-day prompt as soon as a load starts', async () => {
    stubMonthLoads();
    const m = setup();
    m.restPrompt.value = { shift: { id: 1 } };
    const pending = m.fetchSchedule();
    expect(m.restPrompt.value).toBeNull();
    await pending;
  });

  test('a failed load toasts, resolves false and clears loading', async () => {
    stubMonthLoads();
    api.on('get', '/api/schedule', () => {
      throw httpError(500);
    });
    const m = setup();
    await expect(m.fetchSchedule()).resolves.toBe(false);
    expect(m.showToast).toHaveBeenCalledWith('Failed to load schedule.', 'error');
    expect(m.loading.value).toBe(false);
    expect(api.sent('get', '/api/shift-colors')).toHaveLength(0);
  });

  test('a stale response is ignored and neither clears loading nor moves the group', async () => {
    stubMonthLoads();
    const m = setup();
    let resolveFirst;
    let resolveSecond;
    api.on(
      'get',
      '/api/schedule',
      () =>
        new Promise((r) => {
          resolveFirst = r;
        }),
    );
    const first = m.fetchSchedule();
    api.on(
      'get',
      '/api/schedule',
      () =>
        new Promise((r) => {
          resolveSecond = r;
        }),
    );
    const second = m.fetchSchedule();

    // The first (now stale) answer lands while the second is still in flight.
    resolveFirst({
      schedule: { '2026-03-01': [{ id: 'stale' }] },
      employees: [],
      shift_group_id: 1,
    });
    await expect(first).resolves.toBe(false);
    expect(m.schedule.value).toEqual({});
    expect(m.activeGroupId.value).toBeNull();
    expect(m.loading.value).toBe(true);

    resolveSecond({
      schedule: { '2026-03-01': [{ id: 'fresh' }] },
      employees: [],
      shift_group_id: 2,
    });
    await expect(second).resolves.toBe(true);
    expect(m.schedule.value['2026-03-01'][0].id).toBe('fresh');
    expect(m.activeGroupId.value).toBe(2);
    expect(m.loading.value).toBe(false);
    expect(m.showToast).not.toHaveBeenCalled();
  });
});

describe('delegations overlay', () => {
  test('a failed delegations load flags delegationsFailed and warns; the next success clears it', async () => {
    stubMonthLoads();
    api.on('get', '/api/delegations/month', () => {
      throw httpError(500);
    });
    const m = setup();
    await m.fetchSchedule();
    await flush();
    expect(m.delegationsFailed.value).toBe(true);
    expect(m.monthDelegations.value).toEqual([]);
    expect(m.showToast).toHaveBeenCalledWith(
      "Couldn't load delegations: delegation conflicts can't be checked.",
      'warning',
    );

    api.on('get', '/api/delegations/month', [{ id: 1 }]);
    m.showDelegations.value = true;
    await flush();
    expect(m.delegationsFailed.value).toBe(false);
    expect(m.monthDelegations.value).toEqual([{ id: 1 }]);
  });

  test('getDayDelegations covers the start-to-end date range and narrows to the selected employee', async () => {
    const trip = {
      id: 1,
      start_datetime: '2026-03-05 08:00:00',
      end_datetime: '2026-03-07 16:00:00',
      users: [{ id: 1 }, { id: 2 }],
    };
    const oneDay = {
      id: 2,
      start_datetime: '2026-03-06T00:00:00.000000Z',
      end_datetime: '2026-03-06T23:59:59.000000Z',
      users: [{ id: 3 }],
    };
    stubMonthLoads({ delegations: [trip, oneDay] });
    const m = setup();
    await m.fetchSchedule();
    await flush();

    expect(m.getDayDelegations('2026-03-04')).toEqual([]);
    expect(m.getDayDelegations('2026-03-05')).toEqual([trip]);
    expect(m.getDayDelegations('2026-03-06')).toEqual([trip, oneDay]);
    expect(m.getDayDelegations('2026-03-07')).toEqual([trip]);
    expect(m.getDayDelegations('2026-03-08')).toEqual([]);
    expect(m.getDayDelegations(null)).toEqual([]);

    m.selectedEmployee.value = { id: 3 };
    expect(m.getDayDelegations('2026-03-06')).toEqual([oneDay]);
    expect(m.getDayDelegations('2026-03-05')).toEqual([]);
  });

  test('switching the overlay on loads the month; switching it off does not', async () => {
    stubMonthLoads();
    const m = setup();
    m.showDelegations.value = true;
    await flush();
    expect(api.sent('get', '/api/delegations/month')).toHaveLength(1);
    m.showDelegations.value = false;
    await flush();
    expect(api.sent('get', '/api/delegations/month')).toHaveLength(1);
  });
});

describe('newbies overlay', () => {
  const fresh = { id: 1, user_id: 1, start_date: '2026-03-01', end_date: '2026-03-15' };
  const later = {
    id: 2,
    user_id: 2,
    start_date: '2026-03-11T00:00:00.000000Z',
    end_date: '2026-03-20T00:00:00.000000Z',
  };

  test('getDayNewbies covers the date range (date or timestamp) and narrows to the selected employee', async () => {
    stubMonthLoads({ newbies: [fresh, later] });
    const m = setup();
    await m.fetchSchedule();
    await flush();

    expect(m.getDayNewbies('2026-02-28')).toEqual([]);
    expect(m.getDayNewbies('2026-03-01')).toEqual([fresh]);
    expect(m.getDayNewbies('2026-03-12')).toEqual([fresh, later]);
    expect(m.getDayNewbies('2026-03-20')).toEqual([later]);
    expect(m.getDayNewbies('2026-03-21')).toEqual([]);
    expect(m.getDayNewbies(null)).toEqual([]);

    m.selectedEmployee.value = { id: 2 };
    expect(m.getDayNewbies('2026-03-12')).toEqual([later]);
  });

  test('isCurrentNewbie: the user has a newbie period covering today', async () => {
    fixToday(2026, 2, 10);
    stubMonthLoads({ newbies: [fresh, later] });
    const m = setup();
    m.showNewbies.value = true;
    await flush();
    expect(m.isCurrentNewbie(1)).toBe(true); // 1st–15th covers the 10th
    expect(m.isCurrentNewbie(2)).toBe(false); // starts tomorrow
    expect(m.isCurrentNewbie(99)).toBe(false);
  });

  test('switching the overlay on loads the month; switching it off does not', async () => {
    stubMonthLoads();
    const m = setup();
    m.showNewbies.value = true;
    await flush();
    expect(api.sent('get', '/api/newbie-periods/month')[0].params).toEqual({ month: '2026-03' });
    m.showNewbies.value = false;
    await flush();
    expect(api.sent('get', '/api/newbie-periods/month')).toHaveLength(1);
  });
});

describe('newbies overlay: failures', () => {
  test('a failed newbie-periods load empties the overlay and warns', async () => {
    const m = setup({ activeGroupId: 3 });
    stubMonthLoads({
      newbies: [{ id: 1, user_id: 7, start_date: '2026-03-01', end_date: '2026-03-31' }],
    });
    m.showNewbies.value = true;
    await flush();
    expect(m.getDayNewbies('2026-03-10')).toHaveLength(1);

    api.on('get', '/api/newbie-periods/month', () => {
      throw httpError(500);
    });
    await m.fetchSchedule();
    await flush();
    expect(m.getDayNewbies('2026-03-10')).toEqual([]);
    expect(m.showToast).toHaveBeenCalledWith("Couldn't load newbie periods.", 'warning');
  });
});

describe('office closures overlay', () => {
  test('getClosureForDate matches a plain date and the date part of a timestamp', async () => {
    const plain = { id: 1, date: '2026-03-05', reason: 'Holiday' };
    const stamped = { id: 2, date: '2026-03-06T00:00:00.000000Z', reason: 'Bridge day' };
    stubMonthLoads({ closures: [plain, stamped] });
    const m = setup();
    m.showClosures.value = true;
    await flush();
    expect(api.sent('get', '/api/office-closures')[0].params).toEqual({ month: '2026-03' });
    expect(m.getClosureForDate('2026-03-05')).toEqual(plain);
    expect(m.getClosureForDate('2026-03-06')).toEqual(stamped);
    expect(m.getClosureForDate('2026-03-07')).toBeNull();
    expect(m.getClosureForDate(null)).toBeNull();
  });

  test('switching the overlay off sends nothing, and a non-manager may load closures', async () => {
    stubMonthLoads();
    const m = setup({ canManage: false });
    m.showClosures.value = true;
    await flush();
    expect(api.sent('get', '/api/office-closures')).toHaveLength(1);
    m.showClosures.value = false;
    await flush();
    expect(api.sent('get', '/api/office-closures')).toHaveLength(1);
  });

  test('a failed closures load empties the overlay and warns', async () => {
    stubMonthLoads();
    api.on('get', '/api/office-closures', () => {
      throw httpError(500);
    });
    const m = setup();
    m.showClosures.value = true;
    await flush();
    expect(m.getClosureForDate('2026-03-05')).toBeNull();
    expect(m.showToast).toHaveBeenCalledWith("Couldn't load office closures.", 'warning');
  });
});

describe('derived state', () => {
  test('hasScheduleShifts is true only when some day has a shift', () => {
    const m = setup();
    expect(m.hasScheduleShifts.value).toBe(false);
    m.schedule.value = { '2026-03-01': [], '2026-03-02': [] };
    expect(m.hasScheduleShifts.value).toBe(false);
    m.schedule.value = { '2026-03-01': [], '2026-03-02': [{ id: 1 }] };
    expect(m.hasScheduleShifts.value).toBe(true);
  });

  test('shiftColorById keys the colours by their id as a string', () => {
    const m = setup();
    m.shiftColors.value = [
      { id: 3, hex: '#ff0000' },
      { id: 12, hex: '#00ff00' },
    ];
    expect(Object.keys(m.shiftColorById.value)).toEqual(['3', '12']);
    expect(m.shiftColorById.value['3']).toEqual({ id: 3, hex: '#ff0000' });
    expect(m.shiftColorById.value[12].hex).toBe('#00ff00');
    expect(m.shiftColorById.value['7']).toBeUndefined();
  });

  test('a failed shift-colours load leaves no colours', async () => {
    stubMonthLoads();
    api.on('get', '/api/shift-colors', () => {
      throw httpError(500);
    });
    const m = setup();
    m.shiftColors.value = [{ id: 1, hex: '#000000' }];
    await m.fetchSchedule();
    await flush();
    expect(m.shiftColors.value).toEqual([]);
    expect(m.shiftColorById.value).toEqual({});
  });
});
