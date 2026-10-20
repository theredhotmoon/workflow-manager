import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { ref } from 'vue';
import { api, flush } from '../../testing/fakeApi.js';
import { useEmployeeSelection } from './useEmployeeSelection.js';

vi.mock('../../axios.js', async () => ({
  default: (await import('../../testing/fakeApi.js')).api,
}));

const AVAILABILITY_URL = /^\/api\/user-availabilities\/\d+\/month$/;

// Zoe: 2 shifts, 16 h (one overnight). Adam: 1 shift, 12.5 h → 13. Mia: 3 shifts, 3 h.
const ZOE = { id: 1, name: 'Zoe' };
const ADAM = { id: 2, name: 'Adam' };
const MIA = { id: 3, name: 'Mia' };
const SCHEDULE = {
  '2026-03-02': [
    { id: 1, start_time: '06:00', end_time: '14:00', assignments: [{ user_id: 1 }] },
    { id: 2, start_time: '22:00', end_time: '06:00', assignments: [{ user_id: 1 }] },
  ],
  '2026-03-03': [
    { id: 3, start_time: '08:00', end_time: '20:30', assignments: [{ user_id: 2 }] },
    { id: 4, start_time: '09:00', end_time: '10:00', assignments: [{ user_id: 3 }] },
    { id: 5, start_time: '09:00', end_time: '10:00', assignments: [{ user_id: 3 }] },
    { id: 6, start_time: '09:00', end_time: '10:00' }, // nobody assigned
    { id: 7, start_time: '09:00', end_time: '10:00', assignments: [{ user_id: 3 }] },
  ],
  '2026-03-04': null,
};

const AVAILABILITY = [
  { type: 'unavailable', start_date: '2026-03-01', end_date: '2026-03-01', start_time: null },
  { type: 'preferred', start_date: '2026-03-01', end_date: '2026-03-02', start_time: null },
  { type: 'unavailable', start_date: '2026-03-02', end_date: '2026-03-04', start_time: '08:00' },
  { type: 'preferred', start_date: '2026-03-03', end_date: '2026-03-03', start_time: '14:00' },
  { type: 'preferred', start_date: '2026-03-05', end_date: '2026-03-05', start_time: '14:00' },
];

function setup({
  schedule = SCHEDULE,
  pool = [ZOE, ADAM, MIA],
  colors = {},
  showColors = true,
} = {}) {
  const selectedEmployee = ref(null);
  const monthKey = ref('2026-03');
  const scheduleRef = ref(schedule);
  const employeePool = ref(pool);
  const showShiftColors = ref(showColors);
  const shiftColorById = ref(colors);
  const selection = useEmployeeSelection({
    selectedEmployee,
    monthKey,
    schedule: scheduleRef,
    employeePool,
    showShiftColors,
    shiftColorById,
  });
  return {
    ...selection,
    selectedEmployee,
    monthKey,
    schedule: scheduleRef,
    employeePool,
    showShiftColors,
    shiftColorById,
  };
}

beforeEach(() => {
  api.reset();
  vi.clearAllMocks();
});

afterEach(() => {
  vi.useRealTimers();
});

describe('shift counts and hours', () => {
  test('getEmployeeShiftCount counts the shifts the user is assigned to, skipping empty days', () => {
    const s = setup();
    expect(s.getEmployeeShiftCount(1)).toBe(2);
    expect(s.getEmployeeShiftCount(2)).toBe(1);
    expect(s.getEmployeeShiftCount(3)).toBe(3);
    expect(s.getEmployeeShiftCount(99)).toBe(0);
  });

  test('getEmployeeTotalHours sums the assigned shifts, overnight included, rounded to whole hours', () => {
    const s = setup();
    expect(s.getEmployeeTotalHours(1)).toBe(16); // 8 h + 22:00–06:00 overnight 8 h
    expect(s.getEmployeeTotalHours(2)).toBe(13); // 12 h 30 m rounds up
    expect(s.getEmployeeTotalHours(3)).toBe(3);
    expect(s.getEmployeeTotalHours(99)).toBe(0);
  });
});

describe('sortedEmployeePool', () => {
  test.each([
    ['name', ['Adam', 'Mia', 'Zoe']],
    ['shifts-desc', ['Mia', 'Zoe', 'Adam']],
    ['shifts-asc', ['Adam', 'Zoe', 'Mia']],
    ['hours-desc', ['Zoe', 'Adam', 'Mia']],
    ['hours-asc', ['Mia', 'Adam', 'Zoe']],
  ])('empSort %s orders the roster %j', (mode, expected) => {
    const s = setup();
    s.empSort.value = mode;
    expect(s.sortedEmployeePool.value.map((e) => e.name)).toEqual(expected);
    // The source pool is left alone.
    expect(s.employeePool.value.map((e) => e.name)).toEqual(['Zoe', 'Adam', 'Mia']);
  });

  test('an unknown sort mode keeps the pool order', () => {
    const s = setup();
    s.empSort.value = 'bogus';
    expect(s.sortedEmployeePool.value.map((e) => e.name)).toEqual(['Zoe', 'Adam', 'Mia']);
  });
});

describe('selecting an employee', () => {
  test('a click selects the employee and loads their availability for the month', async () => {
    api.on('get', AVAILABILITY_URL, AVAILABILITY);
    const s = setup();
    s.handleEmployeeClick(ADAM);
    expect(s.selectedEmployee.value).toEqual(ADAM);
    expect(api.calls).toEqual([
      { method: 'get', url: '/api/user-availabilities/2/month', params: { month: '2026-03' } },
    ]);
    await flush();
    expect(s.getDayAvailStyle('2026-03-01').backgroundColor).toBe('rgba(239, 68, 68, 0.18)');
  });

  test('clicking the selected employee again deselects and clears the overlay without a request', async () => {
    api.on('get', AVAILABILITY_URL, AVAILABILITY);
    const s = setup();
    s.handleEmployeeClick(ADAM);
    await flush();
    s.handleEmployeeClick(ADAM);
    expect(s.selectedEmployee.value).toBeNull();
    expect(api.calls).toHaveLength(1);
    s.selectedEmployee.value = ADAM; // the overlay itself is gone, not only hidden
    expect(s.getDayAvailStyle('2026-03-01')).toEqual({});
  });

  test('clicking another employee switches the selection and loads theirs', async () => {
    api.on('get', AVAILABILITY_URL, []);
    const s = setup();
    s.handleEmployeeClick(ADAM);
    s.handleEmployeeClick(MIA);
    expect(s.selectedEmployee.value).toEqual(MIA);
    expect(api.sent('get').map((c) => c.url)).toEqual([
      '/api/user-availabilities/2/month',
      '/api/user-availabilities/3/month',
    ]);
  });

  test('deselecting drops an in-flight availability load so it cannot paint later', async () => {
    let resolveFirst;
    api.on(
      'get',
      AVAILABILITY_URL,
      () =>
        new Promise((r) => {
          resolveFirst = r;
        }),
    );
    const s = setup();
    s.handleEmployeeClick(ADAM);
    s.deselectEmployee();
    expect(s.selectedEmployee.value).toBeNull();

    // Mia is selected next; her load is still pending when Adam's late answer arrives.
    api.on('get', AVAILABILITY_URL, () => new Promise(() => {}));
    s.handleEmployeeClick(MIA);
    resolveFirst(AVAILABILITY);
    await flush();
    expect(s.getDayAvailStyle('2026-03-01')).toEqual({});
  });

  test('a stale availability answer for a previously selected employee is ignored', async () => {
    let resolveAdam;
    let resolveMia;
    api.on(
      'get',
      '/api/user-availabilities/2/month',
      () =>
        new Promise((r) => {
          resolveAdam = r;
        }),
    );
    api.on(
      'get',
      '/api/user-availabilities/3/month',
      () =>
        new Promise((r) => {
          resolveMia = r;
        }),
    );
    const s = setup();
    s.handleEmployeeClick(ADAM);
    s.handleEmployeeClick(MIA);
    resolveMia([
      { type: 'preferred', start_date: '2026-03-01', end_date: '2026-03-01', start_time: null },
    ]);
    await flush();
    resolveAdam(AVAILABILITY);
    await flush();
    expect(s.selectedEmployee.value).toEqual(MIA);
    expect(s.getDayAvailStyle('2026-03-01').backgroundColor).toBe('rgba(16, 185, 129, 0.18)');
  });

  test('a failed availability load clears the overlay', async () => {
    api.on('get', AVAILABILITY_URL, AVAILABILITY);
    const s = setup();
    s.handleEmployeeClick(ADAM);
    await flush();
    expect(s.getDayAvailStyle('2026-03-01')).not.toEqual({});
    api.on('get', AVAILABILITY_URL, () => {
      throw new Error('boom');
    });
    await s.fetchAvailability(ADAM.id);
    expect(s.getDayAvailStyle('2026-03-01')).toEqual({});
  });
});

describe('dragging a roster card', () => {
  test('onDragStart selects the dragged card by its index in the sorted roster', () => {
    api.on('get', AVAILABILITY_URL, []);
    const s = setup(); // sorted by name: Adam, Mia, Zoe
    s.onDragStart({ oldIndex: 2 });
    expect(s.selectedEmployee.value).toEqual(ZOE);
    expect(api.calls[0].url).toBe('/api/user-availabilities/1/month');

    // Dragging the already selected employee does not reload.
    s.onDragStart({ oldIndex: 2 });
    expect(api.calls).toHaveLength(1);
    // An index past the roster selects nothing.
    s.onDragStart({ oldIndex: 7 });
    expect(s.selectedEmployee.value).toEqual(ZOE);
  });

  test('a click right after a drag is ignored until 50 ms after onDragEnd', () => {
    vi.useFakeTimers();
    api.on('get', AVAILABILITY_URL, []);
    const s = setup();
    s.onDragStart({ oldIndex: 0 });
    expect(s.selectedEmployee.value).toEqual(ADAM);

    s.handleEmployeeClick(ADAM); // would deselect, but the drag swallows it
    expect(s.selectedEmployee.value).toEqual(ADAM);

    s.onDragEnd();
    vi.advanceTimersByTime(49);
    s.handleEmployeeClick(ADAM);
    expect(s.selectedEmployee.value).toEqual(ADAM);

    vi.advanceTimersByTime(1);
    s.handleEmployeeClick(ADAM);
    expect(s.selectedEmployee.value).toBeNull();
  });
});

describe('getDayAvailStyle', () => {
  const RED_DAY = {
    backgroundColor: 'rgba(239, 68, 68, 0.18)',
    boxShadow: 'inset 0 0 0 2px rgba(239, 68, 68, 0.3)',
  };
  const GREEN_DAY = {
    backgroundColor: 'rgba(16, 185, 129, 0.18)',
    boxShadow: 'inset 0 0 0 2px rgba(16, 185, 129, 0.3)',
  };
  const AMBER_HOURS = {
    backgroundColor: 'rgba(245, 158, 11, 0.12)',
    boxShadow: 'inset 0 0 0 1px rgba(245, 158, 11, 0.25)',
  };
  const RED_HOURS = {
    backgroundColor: 'rgba(239, 68, 68, 0.1)',
    boxShadow: 'inset 0 0 0 1px rgba(239, 68, 68, 0.2)',
  };
  const GREEN_HOURS = {
    backgroundColor: 'rgba(16, 185, 129, 0.1)',
    boxShadow: 'inset 0 0 0 1px rgba(16, 185, 129, 0.2)',
  };

  async function selectedWithAvailability() {
    api.on('get', AVAILABILITY_URL, AVAILABILITY);
    const s = setup();
    s.handleEmployeeClick(ADAM);
    await flush();
    return s;
  }

  test.each([
    ['2026-03-01', 'unavailable all day beats preferred all day', RED_DAY],
    ['2026-03-02', 'preferred all day beats unavailable hours', GREEN_DAY],
    ['2026-03-03', 'unavailable and preferred hours together', AMBER_HOURS],
    ['2026-03-04', 'unavailable hours only (end of a range)', RED_HOURS],
    ['2026-03-05', 'preferred hours only', GREEN_HOURS],
    ['2026-03-06', 'no entries', {}],
  ])('%s: %s', async (dateStr, _rule, expected) => {
    const s = await selectedWithAvailability();
    expect(s.getDayAvailStyle(dateStr)).toEqual(expected);
  });

  test('nothing without a selected employee or a date', async () => {
    const s = await selectedWithAvailability();
    expect(s.getDayAvailStyle(null)).toEqual({});
    s.selectedEmployee.value = null;
    expect(s.getDayAvailStyle('2026-03-01')).toEqual({});
  });
});

describe('shift cell styles', () => {
  const ASSIGNED = {
    backgroundColor: 'rgba(14, 165, 233, 0.12)',
    boxShadow: 'inset 0 0 0 1px rgba(14, 165, 233, 0.25)',
  };
  const COLORS = { 7: { id: 7, hex: '#ff8800' } };

  test('isSelectedEmployeeAssigned reads the selected employee out of the shift assignments', () => {
    const s = setup();
    const shift = SCHEDULE['2026-03-02'][0];
    expect(s.isSelectedEmployeeAssigned(shift)).toBe(false);
    s.selectedEmployee.value = ZOE;
    expect(s.isSelectedEmployeeAssigned(shift)).toBe(true);
    expect(s.isSelectedEmployeeAssigned(SCHEDULE['2026-03-03'][0])).toBe(false);
    expect(s.isSelectedEmployeeAssigned({ id: 9 })).toBe(false);
  });

  test('a shift colour tints the cell at 15% with a matching 25% border', () => {
    const s = setup({ colors: COLORS });
    expect(s.getShiftCellStyle({ color: 7, assignments: [] })).toEqual({
      backgroundColor: 'rgba(255, 136, 0, 0.15)',
      boxShadow: 'inset 0 0 0 1px rgba(255, 136, 0, 0.25)',
    });
  });

  test('an assigned shift keeps the assigned highlight border under the colour tint', () => {
    const s = setup({ colors: COLORS });
    s.selectedEmployee.value = ZOE;
    const shift = { color: 7, assignments: [{ user_id: 1 }] };
    expect(s.getShiftCellStyle(shift)).toEqual({
      backgroundColor: 'rgba(255, 136, 0, 0.15)',
      boxShadow: ASSIGNED.boxShadow,
    });
    expect(s.getShiftCellStyle({ assignments: [{ user_id: 1 }] })).toEqual(ASSIGNED);
  });

  test('no tint when shift colours are off or the colour id is unknown', () => {
    const off = setup({ colors: COLORS, showColors: false });
    expect(off.getShiftCellStyle({ color: 7, assignments: [] })).toEqual({});
    off.selectedEmployee.value = ZOE;
    expect(off.getShiftCellStyle({ color: 7, assignments: [{ user_id: 1 }] })).toEqual(ASSIGNED);

    const on = setup({ colors: COLORS });
    expect(on.getShiftCellStyle({ color: 8, assignments: [] })).toEqual({});
    expect(on.getShiftCellStyle({ color: null, assignments: [] })).toEqual({});
  });
});
