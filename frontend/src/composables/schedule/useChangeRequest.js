// frontend/src/composables/schedule/useChangeRequest.js
//
// An employee's "Request Day Off" for one of their own future shifts on the Schedule screen: the
// dialog state, opening it and filing the request (the chip is marked locally, no reload). Moved
// out of ScheduleView.vue unchanged.
import { ref } from 'vue';
import api from '../../axios.js';

export function useChangeRequest({ schedule, showToast }) {
  // Shift change requests
  const changeRequestModal = ref({
    show: false,
    assignmentId: null,
    shiftNumber: null,
    dateLabel: '',
    reason: '',
    error: '',
    submitting: false,
  });

  function isShiftFuture(_shift, dateStr) {
    if (!dateStr) return false;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const shiftDate = new Date(dateStr + 'T00:00:00');
    return shiftDate >= today;
  }

  function openChangeRequestModal(assignment, shift, dateStr) {
    const d = new Date(dateStr + 'T12:00:00');
    changeRequestModal.value = {
      show: true,
      assignmentId: assignment.id,
      shiftNumber: shift.shift_number,
      dateLabel: d.toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
      }),
      reason: '',
      error: '',
      submitting: false,
    };
  }

  async function submitChangeRequest() {
    const modal = changeRequestModal.value;
    if (!modal.reason.trim()) {
      modal.error = 'Please provide a reason.';
      return;
    }
    modal.submitting = true;
    modal.error = '';
    try {
      const response = await api.post('/api/shift-change-requests', {
        shift_assignment_id: modal.assignmentId,
        reason: modal.reason.trim(),
      });
      modal.show = false;
      showToast('Day-off request submitted successfully.', 'success');
      // Update locally instead of refreshing entire schedule
      for (const dateStr in schedule.value) {
        for (const shift of schedule.value[dateStr]) {
          const a = shift.assignments.find((a) => a.id === modal.assignmentId);
          if (a) {
            a.has_change_request = true;
            a.change_request_reason = modal.reason.trim();
            a.change_request_id = response.data?.id || null;
            break;
          }
        }
      }
    } catch (err) {
      modal.error = err.response?.data?.message || 'Failed to submit request.';
    } finally {
      modal.submitting = false;
    }
  }

  return {
    changeRequestModal,
    isShiftFuture,
    openChangeRequestModal,
    submitChangeRequest,
  };
}
