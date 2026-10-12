// frontend/src/composables/schedule/useEmployeeSelection.js
//
// The Schedule screen's employee roster and selection: sorting with each employee's shift count
// and hours this month, clicking or dragging a card to select it, the selected employee's
// availability overlay (guarded by useLatestRequest: only the latest load paints, deselecting
// drops an in-flight one) and the day/shift cell styles. Moved out of ScheduleView.vue unchanged.
//
// selectedEmployee is created by the screen (the month's day lookups read it too).
import { computed, ref } from 'vue';
import api from '../../axios.js';
import { totalShiftHours } from '../../shiftHours.js';
import { useLatestRequest } from '../useLatestRequest.js';

export function useEmployeeSelection({
  selectedEmployee,
  monthKey,
  schedule,
  employeePool,
  showShiftColors,
  shiftColorById,
}) {
  // Employee availability selection
  const selectedAvailability = ref([]);
  const empSort = ref('name');

  // Count how many shifts a user is assigned to this month
  function getEmployeeShiftCount(userId) {
    let count = 0;
    for (const dateStr in schedule.value) {
      const dayShifts = schedule.value[dateStr];
      if (!dayShifts) continue;
      for (const shift of dayShifts) {
        if (shift.assignments?.some((a) => a.user_id === userId)) {
          count++;
        }
      }
    }
    return count;
  }

  // Calculate total hours for an employee across all assigned shifts this month
  function getEmployeeTotalHours(userId) {
    const assigned = Object.values(schedule.value)
      .flatMap((dayShifts) => dayShifts ?? [])
      .filter((shift) => shift.assignments?.some((a) => a.user_id === userId));
    return totalShiftHours(assigned);
  }

  // Sorted employee pool for display
  const sortedEmployeePool = computed(() => {
    const pool = [...employeePool.value];
    if (empSort.value === 'name') {
      pool.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
    } else if (empSort.value === 'shifts-desc') {
      pool.sort((a, b) => getEmployeeShiftCount(b.id) - getEmployeeShiftCount(a.id));
    } else if (empSort.value === 'shifts-asc') {
      pool.sort((a, b) => getEmployeeShiftCount(a.id) - getEmployeeShiftCount(b.id));
    } else if (empSort.value === 'hours-desc') {
      pool.sort((a, b) => getEmployeeTotalHours(b.id) - getEmployeeTotalHours(a.id));
    } else if (empSort.value === 'hours-asc') {
      pool.sort((a, b) => getEmployeeTotalHours(a.id) - getEmployeeTotalHours(b.id));
    }
    return pool;
  });

  const wasDragging = ref(false);

  function onDragStart(evt) {
    wasDragging.value = true;
    // Auto-highlight availability for the employee being dragged
    const emp = sortedEmployeePool.value[evt.oldIndex];
    if (emp && (!selectedEmployee.value || selectedEmployee.value.id !== emp.id)) {
      selectedEmployee.value = emp;
      fetchAvailability(emp.id);
    }
  }

  function onDragEnd() {
    // Clear the flag after a small delay so the click event (which fires after mouseup) sees it
    setTimeout(() => {
      wasDragging.value = false;
    }, 50);
  }

  function handleEmployeeClick(emp) {
    if (wasDragging.value) return; // Skip click after drag
    toggleEmployeeSelect(emp);
  }

  // Availability highlighting. Clicking employees (or changing month) faster than their availability
  // loads overlaps requests; only the latest may paint the calendar. Deselecting drops the in-flight
  // one so it can't repopulate the highlight for whoever is selected next.
  const availabilityRequests = useLatestRequest();

  function toggleEmployeeSelect(emp) {
    if (selectedEmployee.value?.id === emp.id) {
      deselectEmployee();
    } else {
      selectedEmployee.value = emp;
      fetchAvailability(emp.id);
    }
  }

  function deselectEmployee() {
    availabilityRequests.invalidate();
    selectedEmployee.value = null;
    selectedAvailability.value = [];
  }

  async function fetchAvailability(userId) {
    const token = availabilityRequests.begin();
    try {
      const res = await api.get(`/api/user-availabilities/${userId}/month`, {
        params: { month: monthKey.value },
      });
      if (!availabilityRequests.isCurrent(token)) return;
      selectedAvailability.value = res.data;
    } catch {
      if (availabilityRequests.isCurrent(token)) selectedAvailability.value = [];
    }
  }

  function getDayAvailStyle(dateStr) {
    if (!selectedEmployee.value || !dateStr) return {};
    const entries = selectedAvailability.value.filter(
      (e) => dateStr >= e.start_date && dateStr <= e.end_date,
    );
    if (entries.length === 0) return {};

    const hasUnavailableFullDay = entries.some((e) => e.type === 'unavailable' && !e.start_time);
    const hasUnavailableHours = entries.some((e) => e.type === 'unavailable' && e.start_time);
    const hasPreferredFullDay = entries.some((e) => e.type === 'preferred' && !e.start_time);
    const hasPreferredHours = entries.some((e) => e.type === 'preferred' && e.start_time);

    if (hasUnavailableFullDay)
      return {
        backgroundColor: 'rgba(239, 68, 68, 0.18)',
        boxShadow: 'inset 0 0 0 2px rgba(239, 68, 68, 0.3)',
      };
    if (hasPreferredFullDay)
      return {
        backgroundColor: 'rgba(16, 185, 129, 0.18)',
        boxShadow: 'inset 0 0 0 2px rgba(16, 185, 129, 0.3)',
      };
    if (hasUnavailableHours && hasPreferredHours)
      return {
        backgroundColor: 'rgba(245, 158, 11, 0.12)',
        boxShadow: 'inset 0 0 0 1px rgba(245, 158, 11, 0.25)',
      };
    if (hasUnavailableHours)
      return {
        backgroundColor: 'rgba(239, 68, 68, 0.1)',
        boxShadow: 'inset 0 0 0 1px rgba(239, 68, 68, 0.2)',
      };
    if (hasPreferredHours)
      return {
        backgroundColor: 'rgba(16, 185, 129, 0.1)',
        boxShadow: 'inset 0 0 0 1px rgba(16, 185, 129, 0.2)',
      };
    return {};
  }

  // Check if the selected employee is assigned to a specific shift
  function isSelectedEmployeeAssigned(shift) {
    if (!selectedEmployee.value || !shift.assignments) return false;
    return shift.assignments.some((a) => a.user_id === selectedEmployee.value.id);
  }

  // Get highlight style for shifts the selected employee is assigned to
  function getShiftAssignedStyle(shift) {
    if (!isSelectedEmployeeAssigned(shift)) return {};
    return {
      backgroundColor: 'rgba(14, 165, 233, 0.12)',
      boxShadow: 'inset 0 0 0 1px rgba(14, 165, 233, 0.25)',
    };
  }

  function getShiftCellStyle(shift) {
    const base = getShiftAssignedStyle(shift);
    if (showShiftColors.value && shift.color && shiftColorById.value[shift.color]) {
      const hex = shiftColorById.value[shift.color].hex;
      // Convert hex to rgba at 15% opacity for a subtle background
      const r = parseInt(hex.slice(1, 3), 16);
      const g = parseInt(hex.slice(3, 5), 16);
      const b = parseInt(hex.slice(5, 7), 16);
      return {
        ...base,
        backgroundColor: `rgba(${r}, ${g}, ${b}, 0.15)`,
        boxShadow: base.boxShadow || `inset 0 0 0 1px rgba(${r}, ${g}, ${b}, 0.25)`,
      };
    }
    return base;
  }

  return {
    empSort,
    getEmployeeShiftCount,
    getEmployeeTotalHours,
    sortedEmployeePool,
    onDragStart,
    onDragEnd,
    handleEmployeeClick,
    deselectEmployee,
    fetchAvailability,
    getDayAvailStyle,
    isSelectedEmployeeAssigned,
    getShiftCellStyle,
  };
}
