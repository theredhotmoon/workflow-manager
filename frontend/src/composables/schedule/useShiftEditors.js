// frontend/src/composables/schedule/useShiftEditors.js
//
// The Schedule screen's per-shift editors: the Shift Checklists dialog (with the checklist
// templates it and Generate Month pick from) and the Shift Times dialog (times, colour, type).
// Moved out of ScheduleView.vue unchanged.
import { ref } from 'vue';
import api from '../../axios.js';

export function useShiftEditors({ canManageSchedule, showToast }) {
  // Checklist config
  const checklistTemplates = ref([]);
  const showChecklistModal = ref(false);
  const checklistConfigShift = ref(null);
  const checklistConfigDate = ref('');
  const savingChecklist = ref(false);
  const checklistForm = ref([]);

  // Time editor
  const showTimeModal = ref(false);
  const timeConfigShift = ref(null);
  const timeConfigDate = ref('');
  const savingTimes = ref(false);
  const timeForm = ref({
    start_time: '',
    end_time: '',
    color: null,
    color_label: '',
    shift_type: '',
  });

  async function fetchTemplates() {
    if (!canManageSchedule.value) return; // only the checklist pickers use them
    try {
      const response = await api.get('/api/checklist-templates');
      checklistTemplates.value = response.data;
    } catch {
      // silently fail
    }
  }

  function openChecklistConfig(shift, dateStr) {
    checklistConfigShift.value = shift;
    checklistConfigDate.value = dateStr;
    checklistForm.value = (shift.shift_checklists || []).map((sc) => ({
      checklist_template_id: sc.checklist_template_id,
      is_required: sc.is_required || false,
      deadline_minutes: sc.deadline_minutes || null,
      deadline_reference: sc.deadline_reference || 'shift_start',
    }));
    showChecklistModal.value = true;
  }

  async function saveChecklistConfig() {
    const shift = checklistConfigShift.value;
    if (!shift) return;

    // Filter out items without a template selected
    const checklists = checklistForm.value.filter((i) => i.checklist_template_id);

    savingChecklist.value = true;
    try {
      const response = await api.put(`/api/schedule/shifts/${shift.id}/checklist`, {
        checklists,
      });
      // Update local state
      shift.shift_checklists = response.data.shift_checklists;
      showChecklistModal.value = false;
      showToast('Checklist settings updated.');
    } catch (err) {
      const msg = err.response?.data?.message || 'Failed to update checklist settings.';
      showToast(msg, 'error');
    } finally {
      savingChecklist.value = false;
    }
  }

  function openTimeEditor(shift, dateStr) {
    timeConfigShift.value = shift;
    timeConfigDate.value = dateStr;
    timeForm.value = {
      start_time: shift.start_time || '06:00',
      end_time: shift.end_time || '14:00',
      color: shift.color || null,
      color_label: shift.color_label || '',
      shift_type: shift.shift_type || '',
    };
    showTimeModal.value = true;
  }

  async function saveShiftTimes() {
    const shift = timeConfigShift.value;
    if (!shift) return;

    savingTimes.value = true;
    try {
      const response = await api.put(`/api/schedule/shifts/${shift.id}/times`, {
        start_time: timeForm.value.start_time,
        end_time: timeForm.value.end_time,
      });
      shift.start_time = response.data.start_time;
      shift.end_time = response.data.end_time;

      // Save color if changed
      const colorRes = await api.put(`/api/schedule/shifts/${shift.id}/color`, {
        color: timeForm.value.color,
        color_label: timeForm.value.color_label || null,
      });
      shift.color = colorRes.data.color;
      shift.color_label = colorRes.data.color_label;

      // Only call the type endpoint when the (normalised) value changed: it re-books streak counters.
      const newType = timeForm.value.shift_type.trim().toLowerCase() || null;
      let rebooked = 0;
      if (newType !== (shift.shift_type ?? null)) {
        const typeRes = await api.put(`/api/schedule/shifts/${shift.id}/type`, {
          shift_type: newType,
        });
        shift.shift_type = typeRes.data.shift_type;
        rebooked = typeRes.data.rebooked;
      }

      showTimeModal.value = false;
      showToast(
        rebooked > 0
          ? `Shift updated; streak counters adjusted for ${rebooked} employee${rebooked === 1 ? '' : 's'}.`
          : 'Shift updated.',
      );
    } catch (err) {
      const msg = err.response?.data?.message || 'Failed to update shift.';
      showToast(msg, 'error');
    } finally {
      savingTimes.value = false;
    }
  }

  return {
    checklistTemplates,
    showChecklistModal,
    checklistConfigShift,
    checklistConfigDate,
    savingChecklist,
    checklistForm,
    showTimeModal,
    timeConfigShift,
    timeConfigDate,
    savingTimes,
    timeForm,
    fetchTemplates,
    openChecklistConfig,
    saveChecklistConfig,
    openTimeEditor,
    saveShiftTimes,
  };
}
