// frontend/src/composables/schedule/useShiftEditors.test.js
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { ref } from 'vue';
import { api, httpError } from '../../testing/fakeApi.js';
import { useShiftEditors } from './useShiftEditors.js';

vi.mock('../../axios.js', async () => ({
  default: (await import('../../testing/fakeApi.js')).api,
}));

function setup({ canManage = true } = {}) {
  const showToast = vi.fn();
  const editors = useShiftEditors({ canManageSchedule: ref(canManage), showToast });
  return { editors, showToast };
}

const shift = (overrides = {}) => ({
  id: 12,
  shift_number: 2,
  start_time: '08:00',
  end_time: '16:00',
  color: null,
  color_label: '',
  shift_type: null,
  shift_checklists: [],
  ...overrides,
});

beforeEach(() => api.reset());

describe('checklist templates', () => {
  test('are loaded for a manager and left alone for anyone else', async () => {
    api.on('get', '/api/checklist-templates', [{ id: 1, name: 'Opening' }]);
    const manager = setup();
    await manager.editors.fetchTemplates();
    expect(manager.editors.checklistTemplates.value).toEqual([{ id: 1, name: 'Opening' }]);

    api.reset();
    const employee = setup({ canManage: false });
    await employee.editors.fetchTemplates();
    expect(api.calls).toEqual([]);
    expect(employee.editors.checklistTemplates.value).toEqual([]);
  });

  test('a failed load stays silent and empty', async () => {
    api.on('get', '/api/checklist-templates', () => {
      throw httpError(500);
    });
    const { editors, showToast } = setup();
    await editors.fetchTemplates();
    expect(editors.checklistTemplates.value).toEqual([]);
    expect(showToast).not.toHaveBeenCalled();
  });
});

describe('Shift Checklists dialog', () => {
  test('opens with the shift’s rows, filling in the defaults of older rows', () => {
    const { editors } = setup();
    editors.openChecklistConfig(
      shift({
        shift_checklists: [
          {
            checklist_template_id: 1,
            is_required: true,
            deadline_minutes: 30,
            deadline_reference: 'shift_end',
          },
          { checklist_template_id: 2 },
        ],
      }),
      '2027-03-10',
    );
    expect(editors.showChecklistModal.value).toBe(true);
    expect(editors.checklistConfigDate.value).toBe('2027-03-10');
    expect(editors.checklistConfigShift.value.id).toBe(12);
    expect(editors.checklistForm.value).toEqual([
      {
        checklist_template_id: 1,
        is_required: true,
        deadline_minutes: 30,
        deadline_reference: 'shift_end',
      },
      {
        checklist_template_id: 2,
        is_required: false,
        deadline_minutes: null,
        deadline_reference: 'shift_start',
      },
    ]);
  });

  test('saving drops rows without a template, sends the rest, and takes the server’s list back', async () => {
    const saved = [
      { id: 5, checklist_template_id: 1, checklist_template_name: 'Opening', is_required: true },
    ];
    api.on('put', '/api/schedule/shifts/12/checklist', { id: 12, shift_checklists: saved });
    const { editors, showToast } = setup();
    const target = shift();
    editors.openChecklistConfig(target, '2027-03-10');
    editors.checklistForm.value.push(
      {
        checklist_template_id: 1,
        is_required: true,
        deadline_minutes: 30,
        deadline_reference: 'shift_end',
      },
      {
        checklist_template_id: null,
        is_required: false,
        deadline_minutes: null,
        deadline_reference: 'shift_start',
      },
    );

    await editors.saveChecklistConfig();

    expect(api.calls).toEqual([
      {
        method: 'put',
        url: '/api/schedule/shifts/12/checklist',
        body: {
          checklists: [
            {
              checklist_template_id: 1,
              is_required: true,
              deadline_minutes: 30,
              deadline_reference: 'shift_end',
            },
          ],
        },
      },
    ]);
    expect(target.shift_checklists).toEqual(saved);
    expect(editors.showChecklistModal.value).toBe(false);
    expect(editors.savingChecklist.value).toBe(false);
    expect(showToast).toHaveBeenCalledWith('Checklist settings updated.');
  });

  test('a refused save keeps the dialog open and shows the server’s reason, or a fallback', async () => {
    api.on('put', '/api/schedule/shifts/12/checklist', () => {
      throw httpError(422, { message: 'That template is archived.' });
    });
    const { editors, showToast } = setup();
    editors.openChecklistConfig(shift(), '2027-03-10');
    await editors.saveChecklistConfig();
    expect(editors.showChecklistModal.value).toBe(true);
    expect(showToast).toHaveBeenCalledWith('That template is archived.', 'error');

    api.on('put', '/api/schedule/shifts/12/checklist', () => {
      throw httpError(500);
    });
    await editors.saveChecklistConfig();
    expect(showToast).toHaveBeenLastCalledWith('Failed to update checklist settings.', 'error');
  });

  test('saving with no shift open does nothing', async () => {
    const { editors } = setup();
    await editors.saveChecklistConfig();
    expect(api.calls).toEqual([]);
  });
});

describe('Shift Times dialog', () => {
  test('opens with the shift’s values, or 06:00–14:00 and no colour for a bare shift', () => {
    const { editors } = setup();
    editors.openTimeEditor(
      shift({ start_time: null, end_time: null, color_label: null }),
      '2027-03-10',
    );
    expect(editors.showTimeModal.value).toBe(true);
    expect(editors.timeConfigDate.value).toBe('2027-03-10');
    expect(editors.timeForm.value).toEqual({
      start_time: '06:00',
      end_time: '14:00',
      color: null,
      color_label: '',
      shift_type: '',
    });

    editors.openTimeEditor(
      shift({
        start_time: '22:00',
        end_time: '06:00',
        color: '3',
        color_label: 'Late',
        shift_type: 'night',
      }),
      '2027-03-11',
    );
    expect(editors.timeForm.value).toEqual({
      start_time: '22:00',
      end_time: '06:00',
      color: '3',
      color_label: 'Late',
      shift_type: 'night',
    });
  });

  test('saves the times, then the colour (an empty label as null); the type is left alone when only its spelling differs', async () => {
    api.on('put', '/api/schedule/shifts/12/times', (c) => ({ id: 12, ...c.body }));
    api.on('put', '/api/schedule/shifts/12/color', (c) => ({ id: 12, ...c.body }));
    const { editors, showToast } = setup();
    const target = shift({ shift_type: 'night' });
    editors.openTimeEditor(target, '2027-03-10');
    Object.assign(editors.timeForm.value, {
      start_time: '22:00',
      end_time: '06:00',
      color: '3',
      color_label: '',
      shift_type: ' Night ',
    });

    await editors.saveShiftTimes();

    expect(api.calls).toEqual([
      {
        method: 'put',
        url: '/api/schedule/shifts/12/times',
        body: { start_time: '22:00', end_time: '06:00' },
      },
      {
        method: 'put',
        url: '/api/schedule/shifts/12/color',
        body: { color: '3', color_label: null },
      },
    ]);
    expect(target).toMatchObject({
      start_time: '22:00',
      end_time: '06:00',
      color: '3',
      color_label: null,
    });
    expect(editors.showTimeModal.value).toBe(false);
    expect(editors.savingTimes.value).toBe(false);
    expect(showToast).toHaveBeenCalledWith('Shift updated.');
  });

  test('a changed type is sent normalised, and the re-booked streak counters are reported', async () => {
    api.on('put', '/api/schedule/shifts/12/times', (c) => ({ id: 12, ...c.body }));
    api.on('put', '/api/schedule/shifts/12/color', (c) => ({ id: 12, ...c.body }));
    api.on('put', '/api/schedule/shifts/12/type', (c) => ({
      id: 12,
      shift_type: c.body.shift_type,
      rebooked: 2,
    }));
    const { editors, showToast } = setup();
    const target = shift({ shift_type: null });
    editors.openTimeEditor(target, '2027-03-10');
    editors.timeForm.value.shift_type = ' Day ';

    await editors.saveShiftTimes();

    expect(api.sent('put', /\/type$/)).toEqual([
      { method: 'put', url: '/api/schedule/shifts/12/type', body: { shift_type: 'day' } },
    ]);
    expect(target.shift_type).toBe('day');
    expect(showToast).toHaveBeenCalledWith(
      'Shift updated; streak counters adjusted for 2 employees.',
    );

    // One employee: singular. Clearing the type sends null.
    api.on('put', '/api/schedule/shifts/12/type', (c) => ({
      id: 12,
      shift_type: c.body.shift_type,
      rebooked: 1,
    }));
    editors.openTimeEditor(target, '2027-03-10');
    editors.timeForm.value.shift_type = '';
    await editors.saveShiftTimes();
    expect(api.sent('put', /\/type$/)[1].body).toEqual({ shift_type: null });
    expect(target.shift_type).toBeNull();
    expect(showToast).toHaveBeenLastCalledWith(
      'Shift updated; streak counters adjusted for 1 employee.',
    );
  });

  test('a refused time change stops there: no colour or type request, dialog open, reason shown', async () => {
    api.on('put', '/api/schedule/shifts/12/times', () => {
      throw httpError(422, { message: 'The end time field must match H:i.' });
    });
    const { editors, showToast } = setup();
    editors.openTimeEditor(shift(), '2027-03-10');
    editors.timeForm.value.shift_type = 'day';

    await editors.saveShiftTimes();

    expect(api.calls).toHaveLength(1);
    expect(editors.showTimeModal.value).toBe(true);
    expect(editors.savingTimes.value).toBe(false);
    expect(showToast).toHaveBeenCalledWith('The end time field must match H:i.', 'error');

    api.on('put', '/api/schedule/shifts/12/times', () => {
      throw httpError(500);
    });
    await editors.saveShiftTimes();
    expect(showToast).toHaveBeenLastCalledWith('Failed to update shift.', 'error');
  });
});
