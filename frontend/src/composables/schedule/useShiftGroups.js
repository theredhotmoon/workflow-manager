// frontend/src/composables/schedule/useShiftGroups.js
//
// The Schedule screen's shift groups: the tabs (fetch, switch, create, rename), archive/restore and
// the group's settlement settings dialog. Moved out of ScheduleView.vue unchanged.
//
// activeGroupId: the active tab (a group id, or 'all'), created by the screen because the month
// (useScheduleMonth) loads it too. fetchSchedule: the screen's month loader (useScheduleMonth);
// called after a switch, a create or an archive, exactly as before.
import { ref } from 'vue';
import api from '../../axios.js';
import { isAdmin } from '../../roles.js';
import { currentUser } from '../../router/index.js';
import { confirm } from '../useConfirm.js';

export function useShiftGroups({ activeGroupId, fetchSchedule, showToast }) {
  // Shift groups
  const shiftGroups = ref([]);
  const archivedGroups = ref([]);
  const showArchivedGroups = ref(false);
  const restoringGroupId = ref(null);
  const showNewGroupInput = ref(false);
  const newGroupName = ref('');
  // A create in flight: a double click (or Enter + click) must not create the group twice.
  const creatingGroup = ref(false);
  const newGroupColor = ref('#6366f1');
  const editingGroupId = ref(null);
  const editingGroupName = ref('');

  async function fetchShiftGroups() {
    try {
      // Admins also get the archived groups (for the Archived list); they never become tabs.
      const admin = isAdmin(currentUser.value);
      const res = await api.get(
        '/api/shift-groups',
        admin ? { params: { include_archived: 1 } } : {},
      );
      shiftGroups.value = res.data.filter((g) => !g.archived_at);
      archivedGroups.value = res.data.filter((g) => g.archived_at);
      // Set active group to first (default) if not set
      if (!activeGroupId.value && shiftGroups.value.length > 0) {
        const defaultGroup = shiftGroups.value.find((g) => g.is_default);
        activeGroupId.value = defaultGroup?.id || shiftGroups.value[0].id;
      }
    } catch {
      shiftGroups.value = [];
    }
  }

  function switchGroup(groupId) {
    if (activeGroupId.value === groupId) return;
    activeGroupId.value = groupId;
    fetchSchedule();
  }

  async function createShiftGroup() {
    if (creatingGroup.value || !newGroupName.value.trim()) return;
    creatingGroup.value = true;
    try {
      const res = await api.post('/api/shift-groups', {
        name: newGroupName.value.trim(),
        color: newGroupColor.value,
      });
      shiftGroups.value.push(res.data);
      newGroupName.value = '';
      newGroupColor.value = '#6366f1';
      showNewGroupInput.value = false;
      // Switch to the new group
      activeGroupId.value = res.data.id;
      fetchSchedule();
      showToast(`Group "${res.data.name}" created.`);
    } catch (err) {
      showToast(err.response?.data?.message || 'Failed to create group.', 'error');
    } finally {
      creatingGroup.value = false;
    }
  }

  /** "1 shift assignment and 2 on-call duties" — the work a future_work 409 lists. */
  function describeFutureWork(data) {
    const parts = [];
    const shifts = data.assignments?.length ?? 0;
    const duties = data.on_call?.length ?? 0;
    if (shifts) parts.push(`${shifts} shift assignment${shifts === 1 ? '' : 's'}`);
    if (duties) parts.push(`${duties} on-call ${duties === 1 ? 'duty' : 'duties'}`);
    return parts.join(' and ');
  }

  async function archiveShiftGroup(group) {
    const ok = await confirm({
      title: 'Archive Shift Group',
      message:
        `Archive group "${group.name}"? It leaves the schedule tabs, pickers and auto-fill.\n\n` +
        'Kept: its past shifts, assignments, hours, change requests, checklists and on-call duties; settlement reports still count them. You can restore it later.',
      confirmLabel: 'Archive',
      danger: true,
    });
    if (!ok) return;
    try {
      await sendArchive(group, false);
    } catch (err) {
      const data = err.response?.data;
      if (err.response?.status !== 409 || data?.code !== 'future_work') {
        showToast(data?.message || 'Failed to archive group.', 'error');
        return;
      }
      const removeOk = await confirm({
        title: 'Remove Future Work?',
        message:
          `"${group.name}" has ${describeFutureWork(data)} from tomorrow on.\n\n` +
          "Archiving removes them (pending change requests are settled and kept). Today's and past work stays.",
        confirmLabel: 'Remove and archive',
        danger: true,
      });
      if (!removeOk) return;
      try {
        await sendArchive(group, true);
      } catch (err2) {
        showToast(err2.response?.data?.message || 'Failed to archive group.', 'error');
      }
    }
  }

  async function sendArchive(group, removeFutureAssignments) {
    const res = await api.delete(`/api/shift-groups/${group.id}`, {
      data: removeFutureAssignments ? { remove_future_assignments: true } : {},
    });
    shiftGroups.value = shiftGroups.value.filter((g) => g.id !== group.id);
    archivedGroups.value = [...archivedGroups.value, res.data.group];
    // Switch to default group
    const defaultGroup = shiftGroups.value.find((g) => g.is_default);
    activeGroupId.value = defaultGroup?.id || shiftGroups.value[0]?.id;
    fetchSchedule();
    showToast(res.data.message || `Group "${group.name}" archived.`);
  }

  async function restoreShiftGroup(group) {
    restoringGroupId.value = group.id;
    try {
      const res = await api.post(`/api/shift-groups/${group.id}/restore`);
      archivedGroups.value = archivedGroups.value.filter((g) => g.id !== group.id);
      if (archivedGroups.value.length === 0) showArchivedGroups.value = false;
      await fetchShiftGroups();
      showToast(res.data.message || `Group "${group.name}" restored.`);
    } catch (err) {
      showToast(err.response?.data?.message || 'Failed to restore group.', 'error');
    } finally {
      restoringGroupId.value = null;
    }
  }

  function startGroupRename(group) {
    editingGroupId.value = group.id;
    editingGroupName.value = group.name;
  }

  async function saveGroupRename() {
    if (!editingGroupId.value) return;
    const name = editingGroupName.value.trim();
    if (!name) {
      editingGroupId.value = null;
      return;
    }
    try {
      await api.put(`/api/shift-groups/${editingGroupId.value}`, { name });
      const group = shiftGroups.value.find((g) => g.id === editingGroupId.value);
      if (group) group.name = name;
      editingGroupId.value = null;
    } catch (err) {
      showToast(err.response?.data?.message || 'Failed to rename group.', 'error');
      editingGroupId.value = null;
    }
  }

  const showGroupSettingsModal = ref(false);
  const savingGroupSettings = ref(false);
  const groupSettingsTarget = ref(null);
  const groupSettingsForm = ref({
    settlement_mode: 'monthly',
    settlement_anchor_date: '',
    settlement_custom_length_days: null,
    weekly_hour_cap: 48,
    streak_rules: [],
  });

  function openGroupSettings(group) {
    groupSettingsTarget.value = group;
    groupSettingsForm.value = {
      settlement_mode: group.settlement_mode || 'monthly',
      settlement_anchor_date: group.settlement_anchor_date || '',
      settlement_custom_length_days: group.settlement_custom_length_days ?? null,
      weekly_hour_cap: group.weekly_hour_cap ?? 48,
      streak_rules: (group.streak_rules ?? []).map((r) => ({
        shift_type: r.shift_type,
        streak_length: r.streak_length,
        rest_days: r.rest_days,
      })),
    };
    showGroupSettingsModal.value = true;
  }

  async function saveGroupSettings() {
    if (!groupSettingsTarget.value) return;
    savingGroupSettings.value = true;
    try {
      const payload = {
        settlement_mode: groupSettingsForm.value.settlement_mode,
        settlement_anchor_date: groupSettingsForm.value.settlement_anchor_date,
        settlement_custom_length_days:
          groupSettingsForm.value.settlement_mode === 'custom'
            ? groupSettingsForm.value.settlement_custom_length_days
            : null,
        weekly_hour_cap: groupSettingsForm.value.weekly_hour_cap,
      };
      const res = await api.put(`/api/shift-groups/${groupSettingsTarget.value.id}`, payload);

      const streakRes = await api.put(
        `/api/shift-groups/${groupSettingsTarget.value.id}/streak-rules`,
        {
          rules: groupSettingsForm.value.streak_rules.filter((r) => r.shift_type),
        },
      );

      const idx = shiftGroups.value.findIndex((g) => g.id === res.data.id);
      if (idx !== -1)
        shiftGroups.value[idx] = { ...res.data, streak_rules: streakRes.data.streak_rules };
      showGroupSettingsModal.value = false;
      showToast('Settlement settings saved.');
    } catch (err) {
      showToast(err.response?.data?.message || 'Failed to save settlement settings.', 'error');
    } finally {
      savingGroupSettings.value = false;
    }
  }

  return {
    shiftGroups,
    archivedGroups,
    showArchivedGroups,
    restoringGroupId,
    showNewGroupInput,
    newGroupName,
    creatingGroup,
    newGroupColor,
    editingGroupId,
    editingGroupName,
    fetchShiftGroups,
    switchGroup,
    createShiftGroup,
    archiveShiftGroup,
    restoreShiftGroup,
    startGroupRename,
    saveGroupRename,
    showGroupSettingsModal,
    savingGroupSettings,
    groupSettingsTarget,
    groupSettingsForm,
    openGroupSettings,
    saveGroupSettings,
  };
}
