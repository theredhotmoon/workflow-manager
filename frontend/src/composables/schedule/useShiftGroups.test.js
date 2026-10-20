import { beforeEach, describe, expect, test, vi } from 'vitest';
import { ref } from 'vue';
import { currentUser } from '../../router/index.js';
import { api, flush, httpError } from '../../testing/fakeApi.js';
import { confirm } from '../useConfirm.js';
import { useShiftGroups } from './useShiftGroups.js';

vi.mock('../../axios.js', async () => ({
  default: (await import('../../testing/fakeApi.js')).api,
}));
vi.mock('../useConfirm.js', () => ({ confirm: vi.fn() }));
// The router pulls in .vue files; only `currentUser` is needed here.
vi.mock('../../router/index.js', async () => {
  const { ref } = await import('vue');
  return { currentUser: ref({ id: 1, role: 'admin' }) };
});

const OPS = { id: 1, name: 'Ops', color: '#111111', is_default: true, archived_at: null };
const EVENTS = { id: 2, name: 'Events', color: '#222222', is_default: false, archived_at: null };
const OLD = { id: 3, name: 'Old', color: '#333333', is_default: false, archived_at: '2026-01-01' };

function setup({ activeGroupId: initial = null } = {}) {
  const activeGroupId = ref(initial);
  const fetchSchedule = vi.fn(async () => true);
  const showToast = vi.fn();
  const groups = useShiftGroups({ activeGroupId, fetchSchedule, showToast });
  return { ...groups, activeGroupId, fetchSchedule, showToast };
}

beforeEach(() => {
  api.reset();
  vi.clearAllMocks();
  confirm.mockReset();
  currentUser.value = { id: 1, role: 'admin' };
});

describe('fetchShiftGroups', () => {
  test('an admin asks for the archived groups too and they are split off the tabs', async () => {
    api.on('get', '/api/shift-groups', [OPS, EVENTS, OLD]);
    const g = setup();
    await g.fetchShiftGroups();
    expect(api.sent('get', '/api/shift-groups')[0].params).toEqual({ include_archived: 1 });
    expect(g.shiftGroups.value).toEqual([OPS, EVENTS]);
    expect(g.archivedGroups.value).toEqual([OLD]);
  });

  test('a non-admin sends no params', async () => {
    currentUser.value = { id: 5, role: 'schedule_maintainer' };
    api.on('get', '/api/shift-groups', [OPS, EVENTS]);
    const g = setup();
    await g.fetchShiftGroups();
    expect(api.sent('get', '/api/shift-groups')[0].params).toBeUndefined();
    expect(g.shiftGroups.value).toEqual([OPS, EVENTS]);
  });

  test('the active group becomes the default group, else the first, only when none is set', async () => {
    api.on('get', '/api/shift-groups', [EVENTS, OPS]);
    const g = setup();
    await g.fetchShiftGroups();
    expect(g.activeGroupId.value).toBe(OPS.id);

    api.on('get', '/api/shift-groups', [EVENTS, { ...OPS, is_default: false }]);
    const noDefault = setup();
    await noDefault.fetchShiftGroups();
    expect(noDefault.activeGroupId.value).toBe(EVENTS.id);

    const preset = setup({ activeGroupId: 'all' });
    await preset.fetchShiftGroups();
    expect(preset.activeGroupId.value).toBe('all');
  });

  test('a failed load leaves no tabs', async () => {
    api.on('get', '/api/shift-groups', () => {
      throw httpError(500);
    });
    const g = setup();
    g.shiftGroups.value = [OPS];
    await g.fetchShiftGroups();
    expect(g.shiftGroups.value).toEqual([]);
    expect(g.activeGroupId.value).toBeNull();
  });
});

describe('switchGroup', () => {
  test('switches the tab and reloads the month; the same tab does nothing', () => {
    const g = setup({ activeGroupId: 1 });
    g.switchGroup(2);
    expect(g.activeGroupId.value).toBe(2);
    expect(g.fetchSchedule).toHaveBeenCalledTimes(1);
    g.switchGroup(2);
    expect(g.fetchSchedule).toHaveBeenCalledTimes(1);
  });
});

describe('createShiftGroup', () => {
  test('an empty or whitespace name sends nothing', async () => {
    const g = setup();
    await g.createShiftGroup();
    g.newGroupName.value = '   ';
    await g.createShiftGroup();
    expect(api.calls).toEqual([]);
    expect(g.showToast).not.toHaveBeenCalled();
  });

  test('a second call while the first is in flight sends one POST', async () => {
    let resolve;
    api.on(
      'post',
      '/api/shift-groups',
      () =>
        new Promise((r) => {
          resolve = r;
        }),
    );
    const g = setup();
    g.newGroupName.value = 'Night';
    const first = g.createShiftGroup();
    expect(g.creatingGroup.value).toBe(true);
    const second = g.createShiftGroup();
    expect(api.sent('post', '/api/shift-groups')).toHaveLength(1);
    resolve({ id: 9, name: 'Night' });
    await Promise.all([first, second]);
    expect(g.creatingGroup.value).toBe(false);
    expect(g.shiftGroups.value).toEqual([{ id: 9, name: 'Night' }]);
  });

  test('posts the trimmed name and colour, adds the tab, resets the form, switches and toasts', async () => {
    api.on('post', '/api/shift-groups', (call) => ({ id: 9, ...call.body }));
    const g = setup({ activeGroupId: 1 });
    g.shiftGroups.value = [OPS];
    g.showNewGroupInput.value = true;
    g.newGroupName.value = '  Night  ';
    g.newGroupColor.value = '#abcdef';
    await g.createShiftGroup();

    expect(api.sent('post', '/api/shift-groups')[0].body).toEqual({
      name: 'Night',
      color: '#abcdef',
    });
    expect(g.shiftGroups.value).toEqual([OPS, { id: 9, name: 'Night', color: '#abcdef' }]);
    expect(g.newGroupName.value).toBe('');
    expect(g.newGroupColor.value).toBe('#6366f1');
    expect(g.showNewGroupInput.value).toBe(false);
    expect(g.activeGroupId.value).toBe(9);
    expect(g.fetchSchedule).toHaveBeenCalledTimes(1);
    expect(g.showToast).toHaveBeenCalledWith('Group "Night" created.');
  });

  test('a failure toasts the server message, else the fallback, and keeps the form', async () => {
    api.on('post', '/api/shift-groups', () => {
      throw httpError(422, { message: 'Name taken.' });
    });
    const g = setup();
    g.newGroupName.value = 'Ops';
    await g.createShiftGroup();
    expect(g.showToast).toHaveBeenCalledWith('Name taken.', 'error');
    expect(g.newGroupName.value).toBe('Ops');
    expect(g.creatingGroup.value).toBe(false);

    api.on('post', '/api/shift-groups', () => {
      throw httpError(500);
    });
    await g.createShiftGroup();
    expect(g.showToast).toHaveBeenLastCalledWith('Failed to create group.', 'error');
    expect(g.fetchSchedule).not.toHaveBeenCalled();
  });
});

describe('archiveShiftGroup', () => {
  test('a declined confirm sends nothing', async () => {
    confirm.mockResolvedValueOnce(false);
    const g = setup({ activeGroupId: 2 });
    g.shiftGroups.value = [OPS, EVENTS];
    await g.archiveShiftGroup(EVENTS);
    expect(confirm).toHaveBeenCalledTimes(1);
    expect(confirm.mock.calls[0][0]).toMatchObject({ title: 'Archive Shift Group', danger: true });
    expect(api.calls).toEqual([]);
    expect(g.shiftGroups.value).toEqual([OPS, EVENTS]);
  });

  test('archives with an empty body, moves the group, switches to the default tab and toasts', async () => {
    confirm.mockResolvedValueOnce(true);
    const archived = { ...EVENTS, archived_at: '2026-03-10T10:00:00Z' };
    api.on('delete', '/api/shift-groups/2', { group: archived, message: 'Events archived.' });
    const g = setup({ activeGroupId: 2 });
    g.shiftGroups.value = [EVENTS, OPS];
    g.archivedGroups.value = [OLD];
    await g.archiveShiftGroup(EVENTS);

    expect(api.calls).toEqual([{ method: 'delete', url: '/api/shift-groups/2', body: {} }]);
    expect(g.shiftGroups.value).toEqual([OPS]);
    expect(g.archivedGroups.value).toEqual([OLD, archived]);
    expect(g.activeGroupId.value).toBe(OPS.id);
    expect(g.fetchSchedule).toHaveBeenCalledTimes(1);
    expect(g.showToast).toHaveBeenCalledWith('Events archived.');
  });

  test('without a default group the first remaining tab becomes active; the toast has a fallback', async () => {
    confirm.mockResolvedValueOnce(true);
    const noDefault = { ...OPS, is_default: false };
    api.on('delete', '/api/shift-groups/2', { group: { ...EVENTS, archived_at: 'x' } });
    const g = setup({ activeGroupId: 2 });
    g.shiftGroups.value = [EVENTS, noDefault];
    await g.archiveShiftGroup(EVENTS);
    expect(g.activeGroupId.value).toBe(noDefault.id);
    expect(g.showToast).toHaveBeenCalledWith('Group "Events" archived.');
  });

  test('a future_work 409 asks a second confirm naming the work, then resends removing it', async () => {
    confirm.mockResolvedValueOnce(true).mockResolvedValueOnce(true);
    const archived = { ...EVENTS, archived_at: 'x' };
    api.on('delete', '/api/shift-groups/2', (call) => {
      if (call.body?.remove_future_assignments)
        return { group: archived, message: 'Removed and archived.' };
      throw httpError(409, {
        code: 'future_work',
        assignments: [{ id: 1 }],
        on_call: [{ id: 1 }, { id: 2 }],
      });
    });
    const g = setup({ activeGroupId: 2 });
    g.shiftGroups.value = [OPS, EVENTS];
    await g.archiveShiftGroup(EVENTS);

    expect(confirm).toHaveBeenCalledTimes(2);
    expect(confirm.mock.calls[1][0]).toMatchObject({
      title: 'Remove Future Work?',
      confirmLabel: 'Remove and archive',
      danger: true,
    });
    expect(confirm.mock.calls[1][0].message).toContain(
      '"Events" has 1 shift assignment and 2 on-call duties from tomorrow on.',
    );
    expect(api.sent('delete', '/api/shift-groups/2').map((c) => c.body)).toEqual([
      {},
      { remove_future_assignments: true },
    ]);
    expect(g.shiftGroups.value).toEqual([OPS]);
    expect(g.archivedGroups.value).toEqual([archived]);
    expect(g.activeGroupId.value).toBe(OPS.id);
    expect(g.showToast).toHaveBeenCalledWith('Removed and archived.');
  });

  test.each([
    [[1, 2], [1], '2 shift assignments and 1 on-call duty'],
    [[1, 2, 3], [], '3 shift assignments'],
    [[], [1], '1 on-call duty'],
    [[], [], ''],
  ])(
    'describes future work %j / %j as "%s" and sends nothing more when declined',
    async (assignments, onCall, text) => {
      confirm.mockResolvedValueOnce(true).mockResolvedValueOnce(false);
      api.on('delete', '/api/shift-groups/2', () => {
        throw httpError(409, {
          code: 'future_work',
          assignments: assignments.map((id) => ({ id })),
          on_call: onCall.map((id) => ({ id })),
        });
      });
      const g = setup({ activeGroupId: 2 });
      g.shiftGroups.value = [OPS, EVENTS];
      await g.archiveShiftGroup(EVENTS);
      expect(confirm.mock.calls[1][0].message).toContain(`"Events" has ${text} from tomorrow on.`);
      expect(api.sent('delete')).toHaveLength(1);
      expect(g.shiftGroups.value).toEqual([OPS, EVENTS]);
      expect(g.showToast).not.toHaveBeenCalled();
    },
  );

  test('a failure of the resend toasts', async () => {
    confirm.mockResolvedValueOnce(true).mockResolvedValueOnce(true);
    api.on('delete', '/api/shift-groups/2', (call) => {
      if (call.body?.remove_future_assignments) throw httpError(500);
      throw httpError(409, { code: 'future_work', assignments: [{ id: 1 }], on_call: [] });
    });
    const g = setup({ activeGroupId: 2 });
    g.shiftGroups.value = [OPS, EVENTS];
    await g.archiveShiftGroup(EVENTS);
    expect(api.sent('delete')).toHaveLength(2);
    expect(g.showToast).toHaveBeenCalledWith('Failed to archive group.', 'error');
    expect(g.shiftGroups.value).toEqual([OPS, EVENTS]);
  });

  test('any other error toasts the server message without a second confirm', async () => {
    confirm.mockResolvedValueOnce(true);
    api.on('delete', '/api/shift-groups/1', () => {
      throw httpError(422, { message: 'The default group cannot be archived.' });
    });
    const g = setup({ activeGroupId: 1 });
    g.shiftGroups.value = [OPS, EVENTS];
    await g.archiveShiftGroup(OPS);
    expect(confirm).toHaveBeenCalledTimes(1);
    expect(g.showToast).toHaveBeenCalledWith('The default group cannot be archived.', 'error');
    expect(g.shiftGroups.value).toEqual([OPS, EVENTS]);
    expect(g.fetchSchedule).not.toHaveBeenCalled();

    // A 409 that is not about future work is an ordinary failure too.
    confirm.mockResolvedValueOnce(true);
    api.on('delete', '/api/shift-groups/1', () => {
      throw httpError(409, { code: 'other' });
    });
    await g.archiveShiftGroup(OPS);
    expect(confirm).toHaveBeenCalledTimes(2);
    expect(g.showToast).toHaveBeenLastCalledWith('Failed to archive group.', 'error');
  });
});

describe('restoreShiftGroup', () => {
  test('marks the group as restoring, drops it from the archive, reloads the groups and toasts', async () => {
    let restoringDuring;
    api.on('post', '/api/shift-groups/3/restore', () => {
      restoringDuring = g.restoringGroupId.value;
      return { message: 'Old restored.' };
    });
    api.on('get', '/api/shift-groups', [OPS, EVENTS, { ...OLD, archived_at: null }]);
    const g = setup({ activeGroupId: 1 });
    g.shiftGroups.value = [OPS, EVENTS];
    g.archivedGroups.value = [OLD, { id: 4, name: 'Older', archived_at: 'x' }];
    g.showArchivedGroups.value = true;
    await g.restoreShiftGroup(OLD);

    expect(restoringDuring).toBe(3);
    expect(g.restoringGroupId.value).toBeNull();
    expect(api.sent('get', '/api/shift-groups')).toHaveLength(1);
    expect(g.shiftGroups.value.map((x) => x.id)).toEqual([1, 2, 3]);
    expect(g.archivedGroups.value).toEqual([]); // refreshed from the server
    expect(g.showArchivedGroups.value).toBe(false); // nothing archived after the refresh
    expect(g.showToast).toHaveBeenCalledWith('Old restored.');
  });

  test('hides the archived list when the restored group was the last one; fallback toast', async () => {
    api.on('post', '/api/shift-groups/3/restore', {});
    api.on('get', '/api/shift-groups', [OPS, { ...OLD, archived_at: null }]);
    const g = setup({ activeGroupId: 1 });
    g.archivedGroups.value = [OLD];
    g.showArchivedGroups.value = true;
    await g.restoreShiftGroup(OLD);
    expect(g.showArchivedGroups.value).toBe(false);
    expect(g.showToast).toHaveBeenCalledWith('Group "Old" restored.');
  });

  test('a failure toasts and clears the restoring flag', async () => {
    api.on('post', '/api/shift-groups/3/restore', () => {
      throw httpError(500, { message: 'Nope.' });
    });
    const g = setup({ activeGroupId: 1 });
    g.archivedGroups.value = [OLD];
    await g.restoreShiftGroup(OLD);
    expect(g.showToast).toHaveBeenCalledWith('Nope.', 'error');
    expect(g.restoringGroupId.value).toBeNull();
    expect(g.archivedGroups.value).toEqual([OLD]);
    expect(api.sent('get')).toHaveLength(0);
  });
});

describe('renaming a group', () => {
  test('startGroupRename opens the editor with the current name', () => {
    const g = setup();
    g.startGroupRename(EVENTS);
    expect(g.editingGroupId.value).toBe(2);
    expect(g.editingGroupName.value).toBe('Events');
  });

  test('a blank name cancels the rename without a request', async () => {
    const g = setup();
    g.shiftGroups.value = [{ ...EVENTS }];
    g.startGroupRename(EVENTS);
    g.editingGroupName.value = '   ';
    await g.saveGroupRename();
    expect(api.calls).toEqual([]);
    expect(g.editingGroupId.value).toBeNull();
    expect(g.shiftGroups.value[0].name).toBe('Events');
  });

  test('saveGroupRename without an open editor does nothing', async () => {
    const g = setup();
    await g.saveGroupRename();
    expect(api.calls).toEqual([]);
  });

  test('puts the trimmed name and renames the tab', async () => {
    api.on('put', '/api/shift-groups/2', {});
    const g = setup();
    g.shiftGroups.value = [{ ...OPS }, { ...EVENTS }];
    g.startGroupRename(EVENTS);
    g.editingGroupName.value = '  Parties ';
    await g.saveGroupRename();
    expect(api.calls).toEqual([
      { method: 'put', url: '/api/shift-groups/2', body: { name: 'Parties' } },
    ]);
    expect(g.shiftGroups.value[1].name).toBe('Parties');
    expect(g.shiftGroups.value[0].name).toBe('Ops');
    expect(g.editingGroupId.value).toBeNull();
  });

  test('a failed rename toasts and closes the editor, leaving the name', async () => {
    api.on('put', '/api/shift-groups/2', () => {
      throw httpError(422, { message: 'Name taken.' });
    });
    const g = setup();
    g.shiftGroups.value = [{ ...EVENTS }];
    g.startGroupRename(EVENTS);
    g.editingGroupName.value = 'Ops';
    await g.saveGroupRename();
    expect(g.showToast).toHaveBeenCalledWith('Name taken.', 'error');
    expect(g.editingGroupId.value).toBeNull();
    expect(g.shiftGroups.value[0].name).toBe('Events');
  });
});

describe('group settings', () => {
  test('openGroupSettings fills the form with defaults for a bare group', () => {
    const g = setup();
    g.openGroupSettings(EVENTS);
    expect(g.groupSettingsTarget.value).toEqual(EVENTS);
    expect(g.showGroupSettingsModal.value).toBe(true);
    expect(g.groupSettingsForm.value).toEqual({
      settlement_mode: 'monthly',
      settlement_anchor_date: '',
      settlement_custom_length_days: null,
      weekly_hour_cap: 48,
      streak_rules: [],
    });
  });

  test('openGroupSettings copies the group settings and maps each rule to three fields', () => {
    const g = setup();
    g.openGroupSettings({
      ...EVENTS,
      settlement_mode: 'custom',
      settlement_anchor_date: '2026-01-05',
      settlement_custom_length_days: 14,
      weekly_hour_cap: 0,
      streak_rules: [
        { id: 11, shift_group_id: 2, shift_type: 'night', streak_length: 3, rest_days: 2 },
      ],
    });
    expect(g.groupSettingsForm.value).toEqual({
      settlement_mode: 'custom',
      settlement_anchor_date: '2026-01-05',
      settlement_custom_length_days: 14,
      weekly_hour_cap: 0,
      streak_rules: [{ shift_type: 'night', streak_length: 3, rest_days: 2 }],
    });
    // The form is a copy: editing a rule leaves the group alone.
    g.groupSettingsForm.value.streak_rules[0].rest_days = 9;
    expect(g.groupSettingsTarget.value.streak_rules[0].rest_days).toBe(2);
  });

  test('saveGroupSettings sends the settings then the rules with a shift type, and replaces the tab', async () => {
    const updated = { ...EVENTS, settlement_mode: 'custom', settlement_custom_length_days: 14 };
    api.on('put', '/api/shift-groups/2', updated);
    api.on('put', '/api/shift-groups/2/streak-rules', {
      streak_rules: [{ id: 11, shift_type: 'night', streak_length: 3, rest_days: 2 }],
    });
    const g = setup();
    g.shiftGroups.value = [OPS, EVENTS];
    g.openGroupSettings(EVENTS);
    g.groupSettingsForm.value = {
      settlement_mode: 'custom',
      settlement_anchor_date: '2026-01-05',
      settlement_custom_length_days: 14,
      weekly_hour_cap: 40,
      streak_rules: [
        { shift_type: 'night', streak_length: 3, rest_days: 2 },
        { shift_type: '', streak_length: 5, rest_days: 1 },
      ],
    };
    const pending = g.saveGroupSettings();
    expect(g.savingGroupSettings.value).toBe(true);
    await pending;

    expect(api.calls).toEqual([
      {
        method: 'put',
        url: '/api/shift-groups/2',
        body: {
          settlement_mode: 'custom',
          settlement_anchor_date: '2026-01-05',
          settlement_custom_length_days: 14,
          weekly_hour_cap: 40,
        },
      },
      {
        method: 'put',
        url: '/api/shift-groups/2/streak-rules',
        body: { rules: [{ shift_type: 'night', streak_length: 3, rest_days: 2 }] },
      },
    ]);
    expect(g.shiftGroups.value[1]).toEqual({
      ...updated,
      streak_rules: [{ id: 11, shift_type: 'night', streak_length: 3, rest_days: 2 }],
    });
    expect(g.shiftGroups.value[0]).toEqual(OPS);
    expect(g.showGroupSettingsModal.value).toBe(false);
    expect(g.savingGroupSettings.value).toBe(false);
    expect(g.showToast).toHaveBeenCalledWith('Settlement settings saved.');
  });

  test('the custom length is sent as null unless the mode is custom', async () => {
    api.on('put', '/api/shift-groups/2', EVENTS);
    api.on('put', '/api/shift-groups/2/streak-rules', { streak_rules: [] });
    const g = setup();
    g.openGroupSettings({ ...EVENTS, settlement_custom_length_days: 14 });
    g.groupSettingsForm.value.settlement_mode = 'monthly';
    await g.saveGroupSettings();
    expect(api.sent('put', '/api/shift-groups/2')[0].body.settlement_custom_length_days).toBeNull();
  });

  test('a failed save toasts, keeps the dialog open and clears the saving flag', async () => {
    api.on('put', '/api/shift-groups/2', () => {
      throw httpError(422, { message: 'Anchor date required.' });
    });
    const g = setup();
    g.shiftGroups.value = [EVENTS];
    g.openGroupSettings(EVENTS);
    await g.saveGroupSettings();
    expect(g.showToast).toHaveBeenCalledWith('Anchor date required.', 'error');
    expect(g.showGroupSettingsModal.value).toBe(true);
    expect(g.savingGroupSettings.value).toBe(false);
    expect(api.sent('put', '/api/shift-groups/2/streak-rules')).toHaveLength(0);

    api.on('put', '/api/shift-groups/2', () => {
      throw httpError(500);
    });
    await g.saveGroupSettings();
    expect(g.showToast).toHaveBeenLastCalledWith('Failed to save settlement settings.', 'error');
  });

  test('saveGroupSettings without a target does nothing', async () => {
    const g = setup();
    await g.saveGroupSettings();
    await flush();
    expect(api.calls).toEqual([]);
  });
});
