import { beforeEach, describe, expect, test, vi } from 'vitest';
import { ref } from 'vue';
import { api, httpError } from '../../testing/fakeApi.js';
import { confirm } from '../useConfirm.js';
import { useGenerateMonth } from './useGenerateMonth.js';

vi.mock('../../axios.js', async () => ({
  default: (await import('../../testing/fakeApi.js')).api,
}));
vi.mock('../useConfirm.js', () => ({ confirm: vi.fn() }));

const BLANK = { checklists: [], color: null, color_label: '', shift_type: '' };

function setup({ schedule = {} } = {}) {
  const monthKey = ref('2026-03');
  const activeGroupId = ref(5);
  const scheduleRef = ref(schedule);
  const shiftColorById = ref({});
  const fetchSchedule = vi.fn(async () => true);
  const showToast = vi.fn();
  const gen = useGenerateMonth({
    monthKey,
    activeGroupId,
    schedule: scheduleRef,
    shiftColorById,
    fetchSchedule,
    showToast,
  });
  return {
    ...gen,
    monthKey,
    activeGroupId,
    schedule: scheduleRef,
    shiftColorById,
    fetchSchedule,
    showToast,
  };
}

function remoteEverywhere(g) {
  g.genConfig.value.workModeWeekday = 'remote';
  g.genConfig.value.workModeWeekend = 'remote';
}

beforeEach(() => {
  api.reset();
  vi.clearAllMocks();
  confirm.mockReset();
});

describe('the Generate Month dialog', () => {
  test('openGenerateModal presets three shifts with empty checklist, colour and type fields', () => {
    const g = setup();
    g.genConfig.value.shiftsPerDay = 1;
    g.openGenerateModal();
    expect(g.showGenerateModal.value).toBe(true);
    expect(g.genConfig.value.shiftsPerDay).toBe(3);
    expect(g.genConfig.value.shifts).toEqual([
      { start_time: '06:00', end_time: '14:00', ...BLANK },
      { start_time: '14:00', end_time: '22:00', ...BLANK },
      { start_time: '22:00', end_time: '06:00', ...BLANK },
    ]);
  });

  test('onGenShiftCountChange uses the presets for 1 and 2 shifts', () => {
    const g = setup();
    g.genConfig.value.shiftsPerDay = 1;
    g.onGenShiftCountChange();
    expect(g.genConfig.value.shifts).toEqual([
      { start_time: '00:00', end_time: '00:00', ...BLANK },
    ]);

    g.genConfig.value.shiftsPerDay = 2;
    g.onGenShiftCountChange();
    expect(g.genConfig.value.shifts).toEqual([
      { start_time: '06:00', end_time: '18:00', ...BLANK },
      { start_time: '18:00', end_time: '06:00', ...BLANK },
    ]);
  });

  test('onGenShiftCountChange splits the day evenly from 06:00 for other counts', () => {
    const g = setup();
    g.genConfig.value.shiftsPerDay = 4;
    g.onGenShiftCountChange();
    expect(g.genConfig.value.shifts).toEqual([
      { start_time: '06:00', end_time: '12:00', ...BLANK },
      { start_time: '12:00', end_time: '18:00', ...BLANK },
      { start_time: '18:00', end_time: '00:00', ...BLANK },
      { start_time: '00:00', end_time: '06:00', ...BLANK },
    ]);

    // 24 / 5 rounds down to 4 h shifts: the fifth ends at 02:00 and the day is not covered.
    g.genConfig.value.shiftsPerDay = 5;
    g.onGenShiftCountChange();
    expect(g.genConfig.value.shifts.map((s) => `${s.start_time}-${s.end_time}`)).toEqual([
      '06:00-10:00',
      '10:00-14:00',
      '14:00-18:00',
      '18:00-22:00',
      '22:00-02:00',
    ]);
    expect(g.donutGapMinutes.value).toBe(240);
  });

  test('syncNextShiftStart copies a shift end onto the next shift start', () => {
    const g = setup();
    g.openGenerateModal();
    g.genConfig.value.shifts[0].end_time = '15:00';
    g.syncNextShiftStart(0);
    expect(g.genConfig.value.shifts[1].start_time).toBe('15:00');
    expect(g.genConfig.value.shifts[2].start_time).toBe('22:00');

    g.genConfig.value.shifts[2].end_time = '07:00';
    g.syncNextShiftStart(2); // the last shift has no follower
    expect(g.genConfig.value.shifts[0].start_time).toBe('06:00');

    g.genConfig.value.shifts[1].end_time = '';
    g.syncNextShiftStart(1); // nothing to copy
    expect(g.genConfig.value.shifts[2].start_time).toBe('22:00');
  });
});

describe('the 24 h clock chart', () => {
  test('donutSegments: duration labels, overnight wrap, palette colour by index or the shift colour', () => {
    const g = setup();
    g.shiftColorById.value = { 7: { id: 7, hex: '#abcdef' } };
    g.genConfig.value.shifts = [
      { start_time: '06:00', end_time: '14:00', color: null },
      { start_time: '22:00', end_time: '06:00', color: 7 },
      { start_time: '09:00', end_time: '10:30', color: 99 },
    ];
    const [day, night, short] = g.donutSegments.value;
    expect(day).toMatchObject({
      color: '#6366f1',
      startTime: '06:00',
      endTime: '14:00',
      durationLabel: '8h',
      durationMinutes: 480,
    });
    expect(day.path).toMatch(/^M /);
    expect(night).toMatchObject({ color: '#abcdef', durationLabel: '8h', durationMinutes: 480 });
    expect(short).toMatchObject({ color: '#ec4899', durationLabel: '1h 30m', durationMinutes: 90 });
  });

  test('donutSegments: an end equal to the start is a full 24 h, missing times read as 00:00', () => {
    const g = setup();
    g.genConfig.value.shifts = [
      { start_time: '08:00', end_time: '08:00' },
      { start_time: '', end_time: '' },
    ];
    expect(g.donutSegments.value[0]).toMatchObject({ durationLabel: '24h', durationMinutes: 1440 });
    expect(g.donutSegments.value[1]).toMatchObject({
      startTime: '00:00',
      endTime: '00:00',
      durationMinutes: 1440,
    });
  });

  test('donutGapMinutes and donutOverlapMinutes measure the uncovered or doubled part of the day', () => {
    const g = setup();
    g.openGenerateModal(); // 3 × 8 h
    expect(g.donutGapMinutes.value).toBe(0);
    expect(g.donutOverlapMinutes.value).toBe(0);

    g.genConfig.value.shifts = [
      { start_time: '06:00', end_time: '14:00' },
      { start_time: '12:00', end_time: '22:00' },
    ];
    expect(g.donutGapMinutes.value).toBe(360);
    expect(g.donutOverlapMinutes.value).toBe(0);

    g.genConfig.value.shifts = [
      { start_time: '00:00', end_time: '00:00' },
      { start_time: '06:00', end_time: '14:00' },
    ];
    expect(g.donutGapMinutes.value).toBe(0);
    expect(g.donutOverlapMinutes.value).toBe(480);
  });
});

describe('generateMonth: the office-closure check', () => {
  test('with both work modes remote there is no closures check, the month is generated at once', async () => {
    api.on('post', '/api/schedule/generate', { message: 'Generated.' });
    const g = setup();
    g.openGenerateModal();
    remoteEverywhere(g);
    await g.generateMonth();
    expect(api.sent('get')).toHaveLength(0);
    expect(api.sent('post', '/api/schedule/generate')).toHaveLength(1);
    expect(g.showGenerateModal.value).toBe(false);
  });

  test('a closure on an office weekday opens the conflict dialog instead of generating', async () => {
    api.on('get', '/api/office-closures/conflicts', [{ id: 1, date: '2026-03-10' }]); // a Tuesday
    const g = setup();
    g.openGenerateModal();
    g.genConfig.value.workModeWeekend = 'remote';
    await g.generateMonth();
    expect(api.sent('get', '/api/office-closures/conflicts')[0].params).toEqual({
      month: '2026-03',
    });
    expect(g.closureConflict.value).toEqual({
      show: true,
      closures: [{ id: 1, date: '2026-03-10' }],
    });
    expect(g.showGenerateModal.value).toBe(false);
    expect(api.sent('post')).toHaveLength(0);
    expect(g.generating.value).toBe(false);
  });

  test('a weekend closure does not conflict when weekends are remote: no dialog, generated', async () => {
    api.on('get', '/api/office-closures/conflicts', [
      { id: 1, date: '2026-03-07T00:00:00.000000Z' }, // a Saturday
      { id: 2, date: '2026-03-08' }, // a Sunday
    ]);
    api.on('post', '/api/schedule/generate', { message: 'Generated.' });
    const g = setup();
    g.openGenerateModal();
    g.genConfig.value.workModeWeekend = 'remote';
    await g.generateMonth();
    expect(g.closureConflict.value.show).toBe(false);
    expect(api.sent('post', '/api/schedule/generate')).toHaveLength(1);
  });

  test('a weekend closure conflicts when only weekends are office', async () => {
    api.on('get', '/api/office-closures/conflicts', [{ id: 2, date: '2026-03-08' }]);
    const g = setup();
    g.openGenerateModal();
    g.genConfig.value.workModeWeekday = 'remote';
    await g.generateMonth();
    expect(g.closureConflict.value.show).toBe(true);
    expect(api.sent('post')).toHaveLength(0);
  });

  test('when the closures cannot be loaded the manager is asked; declined means no generation', async () => {
    api.on('get', '/api/office-closures/conflicts', () => {
      throw httpError(500);
    });
    confirm.mockResolvedValueOnce(false);
    const g = setup();
    g.openGenerateModal();
    await g.generateMonth();
    expect(confirm).toHaveBeenCalledTimes(1);
    expect(confirm.mock.calls[0][0]).toMatchObject({
      title: "Couldn't check office closures",
      confirmLabel: 'Generate anyway',
    });
    expect(g.showGenerateModal.value).toBe(false);
    expect(api.sent('post')).toHaveLength(0);
    expect(g.showToast).not.toHaveBeenCalled();
  });

  test('when the closures cannot be loaded and the manager confirms, the month is generated', async () => {
    api.on('get', '/api/office-closures/conflicts', () => {
      throw httpError(500);
    });
    api.on('post', '/api/schedule/generate', { message: 'Generated.' });
    confirm.mockResolvedValueOnce(true);
    const g = setup();
    g.openGenerateModal();
    await g.generateMonth();
    expect(api.sent('post', '/api/schedule/generate')).toHaveLength(1);
    expect(g.showToast).toHaveBeenCalledWith('Generated.');
  });
});

describe('doGenerateMonth (through generateMonth)', () => {
  test('posts the month, group and the shift templates, then toasts the server message and reloads', async () => {
    let generatingDuring;
    api.on('post', '/api/schedule/generate', () => {
      generatingDuring = g.generating.value;
      return { message: '31 days generated.' };
    });
    const g = setup();
    g.openGenerateModal();
    remoteEverywhere(g);
    g.genConfig.value.shifts = [
      {
        start_time: '06:00',
        end_time: '14:00',
        color: 7,
        color_label: 'Day',
        shift_type: '  day ',
        checklists: [
          {
            checklist_template_id: 3,
            is_required: true,
            deadline_minutes: 30,
            deadline_reference: 'shift_end',
          },
          { checklist_template_id: 4 },
          { checklist_template_id: null, is_required: true },
        ],
      },
      { start_time: '14:00', end_time: '22:00', color: null, color_label: '', shift_type: '' },
    ];
    g.genConfig.value.shiftsPerDay = 2;

    await g.generateMonth();

    expect(generatingDuring).toBe(true);
    expect(g.generating.value).toBe(false);
    expect(api.sent('post', '/api/schedule/generate')[0].body).toEqual({
      month: '2026-03',
      shift_group_id: 5,
      shifts_per_day: 2,
      work_mode_weekday: 'remote',
      work_mode_weekend: 'remote',
      shift_times: [
        {
          start_time: '06:00',
          end_time: '14:00',
          color: 7,
          color_label: 'Day',
          shift_type: 'day',
          checklists: [
            {
              checklist_template_id: 3,
              is_required: true,
              deadline_minutes: 30,
              deadline_reference: 'shift_end',
            },
            {
              checklist_template_id: 4,
              is_required: false,
              deadline_minutes: null,
              deadline_reference: 'shift_start',
            },
          ],
        },
        {
          start_time: '14:00',
          end_time: '22:00',
          color: null,
          color_label: null,
          shift_type: null,
          checklists: [],
        },
      ],
    });
    expect(g.showToast).toHaveBeenCalledWith('31 days generated.');
    expect(g.fetchSchedule).toHaveBeenCalledTimes(1);
  });

  test('a failed generate toasts the described API error and does not reload', async () => {
    api.on('post', '/api/schedule/generate', () => {
      throw httpError(500);
    });
    const g = setup();
    g.openGenerateModal();
    remoteEverywhere(g);
    await g.generateMonth();
    expect(g.showToast).toHaveBeenCalledWith('Failed to generate month (500).', 'error', 6000);
    expect(g.fetchSchedule).not.toHaveBeenCalled();
    expect(g.generating.value).toBe(false);

    api.on('post', '/api/schedule/generate', () => {
      throw httpError(422, { message: 'The month already has shifts.' });
    });
    await g.generateMonth();
    expect(g.showToast).toHaveBeenLastCalledWith('The month already has shifts.', 'error', 6000);

    // 401: the session-expiry handler takes over, nothing is shown.
    api.on('post', '/api/schedule/generate', () => {
      throw httpError(401);
    });
    await g.generateMonth();
    expect(g.showToast).toHaveBeenCalledTimes(2);
  });
});

describe('the closure conflict dialog', () => {
  const CLOSURES = [
    { id: 1, date: '2026-03-10' },
    { id: 2, date: '2026-03-11T00:00:00.000000Z' },
  ];
  const RELOADED = {
    '2026-03-10': [
      { id: 1, work_mode: 'office' },
      { id: 2, work_mode: 'remote' },
    ],
    '2026-03-11': [{ id: 3, work_mode: 'office' }],
    '2026-03-12': [{ id: 4, work_mode: 'office' }], // not closed
  };

  function conflicted(g) {
    g.closureConflict.value = { show: true, closures: CLOSURES };
  }

  test('cancelClosureConflict closes the dialog without generating', () => {
    const g = setup();
    conflicted(g);
    g.cancelClosureConflict();
    expect(g.closureConflict.value).toEqual({ show: false, closures: [] });
    expect(api.calls).toEqual([]);
  });

  test('proceedGenerate closes the dialog and generates as configured', async () => {
    api.on('post', '/api/schedule/generate', { message: 'Generated.' });
    const g = setup();
    g.openGenerateModal();
    conflicted(g);
    await g.proceedGenerate();
    expect(g.closureConflict.value).toEqual({ show: false, closures: [] });
    expect(api.sent('post', '/api/schedule/generate')[0].body.work_mode_weekday).toBe('office');
    expect(g.showToast).toHaveBeenCalledWith('Generated.');
    expect(api.sent('put')).toHaveLength(0);
  });

  test('proceedGenerateAsRemote switches exactly the office shifts on the closed dates after the reload', async () => {
    api.on('post', '/api/schedule/generate', { message: 'Generated.' });
    api.on('put', '/api/schedule/work-mode', {});
    const g = setup();
    g.openGenerateModal();
    conflicted(g);
    g.fetchSchedule.mockImplementation(async () => {
      g.schedule.value = structuredClone(RELOADED);
      return true;
    });
    await g.proceedGenerateAsRemote();

    expect(g.closureConflict.value).toEqual({ show: false, closures: [] });
    expect(api.sent('put', '/api/schedule/work-mode')).toEqual([
      {
        method: 'put',
        url: '/api/schedule/work-mode',
        body: { shift_ids: [1, 3], work_mode: 'remote' },
      },
    ]);
    expect(g.schedule.value['2026-03-10'].map((s) => s.work_mode)).toEqual(['remote', 'remote']);
    expect(g.schedule.value['2026-03-11'][0].work_mode).toBe('remote');
    expect(g.schedule.value['2026-03-12'][0].work_mode).toBe('office');
    expect(g.showToast).toHaveBeenLastCalledWith('Switched 2 shifts to remote on closed dates.');
  });

  test('proceedGenerateAsRemote: one shift reads in the singular; no office shifts sends nothing', async () => {
    api.on('post', '/api/schedule/generate', { message: 'Generated.' });
    api.on('put', '/api/schedule/work-mode', {});
    const g = setup();
    g.openGenerateModal();
    conflicted(g);
    g.fetchSchedule.mockImplementation(async () => {
      g.schedule.value = { '2026-03-10': [{ id: 1, work_mode: 'office' }] };
      return true;
    });
    await g.proceedGenerateAsRemote();
    expect(g.showToast).toHaveBeenLastCalledWith('Switched 1 shift to remote on closed dates.');

    conflicted(g);
    g.fetchSchedule.mockImplementation(async () => {
      g.schedule.value = { '2026-03-10': [{ id: 1, work_mode: 'remote' }] };
      return true;
    });
    await g.proceedGenerateAsRemote();
    expect(api.sent('put')).toHaveLength(1);
    expect(g.showToast).toHaveBeenLastCalledWith('Generated.');
  });

  test('proceedGenerateAsRemote sends nothing when the generate failed', async () => {
    api.on('post', '/api/schedule/generate', () => {
      throw httpError(500);
    });
    const g = setup();
    g.openGenerateModal();
    conflicted(g);
    await g.proceedGenerateAsRemote();
    expect(g.fetchSchedule).not.toHaveBeenCalled();
    expect(api.sent('put')).toHaveLength(0);
    expect(g.showToast).toHaveBeenCalledTimes(1);
    expect(g.showToast).toHaveBeenCalledWith('Failed to generate month (500).', 'error', 6000);
  });

  const RELOAD_ERROR =
    "The month was generated, but the schedule couldn't be reloaded, so the closed dates were not switched to remote. Reload and try again, or switch them one by one.";

  test.each([
    [
      'the reload failed',
      (g) => {
        g.schedule.value = structuredClone(RELOADED);
        return false;
      },
    ],
    [
      'the screen moved to another month meanwhile',
      (g) => {
        g.schedule.value = structuredClone(RELOADED);
        g.monthKey.value = '2026-04';
        return true;
      },
    ],
    [
      'the screen moved to another group meanwhile',
      (g) => {
        g.schedule.value = structuredClone(RELOADED);
        g.activeGroupId.value = 6;
        return true;
      },
    ],
  ])('proceedGenerateAsRemote: no switch when %s', async (_case, reload) => {
    api.on('post', '/api/schedule/generate', { message: 'Generated.' });
    api.on('put', '/api/schedule/work-mode', {});
    const g = setup();
    g.openGenerateModal();
    conflicted(g);
    g.fetchSchedule.mockImplementation(async () => reload(g));
    await g.proceedGenerateAsRemote();
    expect(api.sent('put')).toHaveLength(0);
    expect(g.showToast).toHaveBeenLastCalledWith(RELOAD_ERROR, 'error', 8000);
    expect(g.schedule.value['2026-03-10'][0].work_mode).toBe('office');
  });

  test('proceedGenerateAsRemote: more than 2000 office shifts on closed dates are not switched', async () => {
    api.on('post', '/api/schedule/generate', { message: 'Generated.' });
    api.on('put', '/api/schedule/work-mode', {});
    const g = setup();
    g.openGenerateModal();
    conflicted(g);
    g.fetchSchedule.mockImplementation(async () => {
      g.schedule.value = {
        '2026-03-10': Array.from({ length: 2001 }, (_, i) => ({ id: i + 1, work_mode: 'office' })),
      };
      return true;
    });
    await g.proceedGenerateAsRemote();
    expect(api.sent('put')).toHaveLength(0);
    expect(g.showToast).toHaveBeenLastCalledWith(
      'The month was generated, but 2001 shifts on closed dates are more than can be switched at once (2000); they are still office. Switch them one by one.',
      'error',
      8000,
    );
    expect(g.schedule.value['2026-03-10'][0].work_mode).toBe('office');
  });

  test('proceedGenerateAsRemote: a failed switch says the shifts are still office', async () => {
    api.on('post', '/api/schedule/generate', { message: 'Generated.' });
    api.on('put', '/api/schedule/work-mode', () => {
      throw httpError(500);
    });
    const g = setup();
    g.openGenerateModal();
    conflicted(g);
    g.fetchSchedule.mockImplementation(async () => {
      g.schedule.value = structuredClone(RELOADED);
      return true;
    });
    await g.proceedGenerateAsRemote();
    expect(api.sent('put')).toHaveLength(1);
    expect(g.showToast).toHaveBeenLastCalledWith(
      'The month was generated, but the shifts on closed dates are still office: Could not switch the closed dates to remote (500).',
      'error',
      8000,
    );
    expect(g.schedule.value['2026-03-10'][0].work_mode).toBe('office');
  });
});

describe('clearMonth', () => {
  test('deletes the month of the active group, toasts, closes the two-step confirm and reloads', async () => {
    let deletingDuring;
    api.on('delete', '/api/schedule/clear-month', () => {
      deletingDuring = g.deleting.value;
      return { message: '93 shifts deleted.' };
    });
    const g = setup();
    g.deleteStep.value = 2;
    await g.clearMonth();

    expect(deletingDuring).toBe(true);
    expect(g.deleting.value).toBe(false);
    expect(api.calls).toEqual([
      {
        method: 'delete',
        url: '/api/schedule/clear-month',
        body: { month: '2026-03', shift_group_id: 5 },
      },
    ]);
    expect(g.showToast).toHaveBeenCalledWith('93 shifts deleted.', 'success');
    expect(g.deleteStep.value).toBe(0);
    expect(g.fetchSchedule).toHaveBeenCalledTimes(1);
  });

  test('a failed delete toasts the server message, else the fallback, and keeps the confirm open', async () => {
    api.on('delete', '/api/schedule/clear-month', () => {
      throw httpError(422, { message: 'Past months cannot be deleted.' });
    });
    const g = setup();
    g.deleteStep.value = 2;
    await g.clearMonth();
    expect(g.showToast).toHaveBeenCalledWith('Past months cannot be deleted.', 'error');
    expect(g.deleteStep.value).toBe(2);
    expect(g.deleting.value).toBe(false);
    expect(g.fetchSchedule).not.toHaveBeenCalled();

    api.on('delete', '/api/schedule/clear-month', () => {
      throw httpError(500);
    });
    await g.clearMonth();
    expect(g.showToast).toHaveBeenLastCalledWith('Failed to delete schedule.', 'error');
  });
});
