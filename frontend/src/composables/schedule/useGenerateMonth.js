// frontend/src/composables/schedule/useGenerateMonth.js
//
// Generating and deleting the month on the Schedule screen: the Generate Month dialog (shift
// templates, default shifts, the 24 h clock chart), the office-closure conflict before generating
// (generate as office, or as remote: switch the closed dates in one all-or-nothing request, at
// most MAX_WORK_MODE_SWITCH shifts, and only when the reload shows the generated month), and the
// two-step Delete Schedule confirmation (clearMonth). Moved out of ScheduleView.vue unchanged.
import { computed, ref } from 'vue';
import { describeApiError } from '../../apiErrors.js';
import api from '../../axios.js';
import { describeArc, minutesToAngle } from '../../schedule/helpers.js';
import { formatDuration, timeToMinutes } from '../../shiftHours.js';
import { confirm } from '../useConfirm.js';

export function useGenerateMonth({
  monthKey,
  activeGroupId,
  schedule,
  shiftColorById,
  fetchSchedule,
  showToast,
}) {
  const generating = ref(false);
  const deleteStep = ref(0); // 0=hidden, 1=first confirm, 2=final confirm
  const deleting = ref(false);

  async function clearMonth() {
    deleting.value = true;
    try {
      const res = await api.delete('/api/schedule/clear-month', {
        data: { month: monthKey.value, shift_group_id: activeGroupId.value },
      });
      showToast(res.data.message, 'success');
      deleteStep.value = 0;
      await fetchSchedule();
    } catch (err) {
      showToast(err.response?.data?.message || 'Failed to delete schedule.', 'error');
    } finally {
      deleting.value = false;
    }
  }

  // Office closure conflict
  const closureConflict = ref({ show: false, closures: [] });

  // Generation config modal
  const showGenerateModal = ref(false);
  const genConfig = ref({
    shiftsPerDay: 3,
    shifts: [],
    workModeWeekday: 'office',
    workModeWeekend: 'office',
  });

  function buildDefaultShifts(count) {
    const presets = {
      1: [{ start_time: '00:00', end_time: '00:00', checklists: [] }],
      2: [
        { start_time: '06:00', end_time: '18:00', checklists: [] },
        { start_time: '18:00', end_time: '06:00', checklists: [] },
      ],
      3: [
        {
          start_time: '06:00',
          end_time: '14:00',
          checklists: [],
          color: null,
          color_label: '',
          shift_type: '',
        },
        {
          start_time: '14:00',
          end_time: '22:00',
          checklists: [],
          color: null,
          color_label: '',
          shift_type: '',
        },
        {
          start_time: '22:00',
          end_time: '06:00',
          checklists: [],
          color: null,
          color_label: '',
          shift_type: '',
        },
      ],
    };
    if (presets[count])
      return presets[count].map((p) => ({
        ...p,
        checklists: [],
        color: null,
        color_label: '',
        shift_type: '',
      }));
    const hoursPerShift = Math.floor(24 / count);
    const shifts = [];
    let startMin = 6 * 60;
    for (let i = 0; i < count; i++) {
      const endMin = startMin + hoursPerShift * 60;
      shifts.push({
        start_time: `${String(Math.floor(startMin / 60) % 24).padStart(2, '0')}:${String(startMin % 60).padStart(2, '0')}`,
        end_time: `${String(Math.floor(endMin / 60) % 24).padStart(2, '0')}:${String(endMin % 60).padStart(2, '0')}`,
        checklists: [],
        color: null,
        color_label: '',
        shift_type: '',
      });
      startMin = endMin;
    }
    return shifts;
  }

  function openGenerateModal() {
    genConfig.value.shiftsPerDay = 3;
    genConfig.value.shifts = buildDefaultShifts(3);
    showGenerateModal.value = true;
  }

  function onGenShiftCountChange() {
    const count = genConfig.value.shiftsPerDay;
    genConfig.value.shifts = buildDefaultShifts(count);
  }

  function syncNextShiftStart(currentIdx) {
    const shifts = genConfig.value.shifts;
    if (currentIdx < shifts.length - 1 && shifts[currentIdx].end_time) {
      shifts[currentIdx + 1].start_time = shifts[currentIdx].end_time;
    }
  }

  // Donut chart computed data
  const defaultDonutColors = [
    '#6366f1',
    '#8b5cf6',
    '#ec4899',
    '#f43f5e',
    '#f97316',
    '#eab308',
    '#22c55e',
    '#06b6d4',
    '#3b82f6',
    '#14b8a6',
  ];

  const donutSegments = computed(() => {
    return genConfig.value.shifts.map((shift, idx) => {
      const startMin = timeToMinutes(shift.start_time);
      let endMin = timeToMinutes(shift.end_time);
      if (endMin <= startMin) endMin += 24 * 60; // overnight
      const duration = endMin - startMin;
      const startAngle = minutesToAngle(startMin);
      const endAngle = minutesToAngle(endMin);

      // Determine color
      let color = defaultDonutColors[idx % defaultDonutColors.length];
      if (shift.color && shiftColorById.value[shift.color]) {
        color = shiftColorById.value[shift.color].hex;
      }

      return {
        path: describeArc(100, 100, 70, startAngle, endAngle),
        color,
        startTime: shift.start_time || '00:00',
        endTime: shift.end_time || '00:00',
        durationLabel: formatDuration(duration),
        durationMinutes: duration,
      };
    });
  });

  const donutGapMinutes = computed(() => {
    const total = donutSegments.value.reduce((sum, s) => sum + s.durationMinutes, 0);
    return Math.max(0, 24 * 60 - total);
  });

  const donutOverlapMinutes = computed(() => {
    const total = donutSegments.value.reduce((sum, s) => sum + s.durationMinutes, 0);
    return Math.max(0, total - 24 * 60);
  });

  async function generateMonth() {
    // First check for office closure conflicts
    const hasOfficeWeekday = genConfig.value.workModeWeekday === 'office';
    const hasOfficeWeekend = genConfig.value.workModeWeekend === 'office';

    if (hasOfficeWeekday || hasOfficeWeekend) {
      try {
        const res = await api.get('/api/office-closures/conflicts', {
          params: { month: monthKey.value },
        });
        const allClosures = res.data || [];
        // Filter: which closed dates conflict with office-mode days?
        const conflicts = allClosures.filter((c) => {
          const d = new Date((c.date?.substring?.(0, 10) || c.date) + 'T12:00:00');
          const dow = d.getDay(); // 0=Sun, 6=Sat
          const isWeekend = dow === 0 || dow === 6;
          return isWeekend ? hasOfficeWeekend : hasOfficeWeekday;
        });
        if (conflicts.length > 0) {
          closureConflict.value = { show: true, closures: conflicts };
          showGenerateModal.value = false;
          return; // Wait for confirmation
        }
      } catch {
        // Not "no closures": say the check didn't run and let the manager decide.
        showGenerateModal.value = false;
        const ok = await confirm({
          title: "Couldn't check office closures",
          message:
            'The closed dates for this month could not be loaded, so office shifts may land on days the office is closed. Generate anyway?',
          confirmLabel: 'Generate anyway',
        });
        if (!ok) return;
      }
    }

    await doGenerateMonth();
  }

  function cancelClosureConflict() {
    closureConflict.value = { show: false, closures: [] };
  }

  async function proceedGenerate() {
    closureConflict.value = { show: false, closures: [] };
    await doGenerateMonth();
  }

  async function proceedGenerateAsRemote() {
    const closedDates = closureConflict.value.closures.map(
      (c) => c.date?.substring?.(0, 10) || c.date,
    );
    closureConflict.value = { show: false, closures: [] };
    const month = monthKey.value;
    const groupId = activeGroupId.value;
    const { generated, reloaded } = await doGenerateMonth();
    // Nothing generated, nothing to switch (doGenerateMonth already showed why).
    if (!generated) return;
    // The switch is picked from the reloaded month: if that reload failed, or the screen moved to
    // another month or group meanwhile, what's on screen isn't the generated month.
    if (!reloaded || monthKey.value !== month || activeGroupId.value !== groupId) {
      showToast(
        "The month was generated, but the schedule couldn't be reloaded, so the closed dates were not switched to remote. Reload and try again, or switch them one by one.",
        'error',
        8000,
      );
      return;
    }
    // Every office shift on a closed date goes remote, in one all-or-nothing request.
    const office = closedDates.flatMap((dateStr) =>
      (schedule.value[dateStr] ?? []).filter((shift) => shift.work_mode === 'office'),
    );
    if (office.length === 0) return;
    if (office.length > MAX_WORK_MODE_SWITCH) {
      // The server takes at most this many in one request (no chunking yet): say so, send nothing.
      showToast(
        `The month was generated, but ${office.length} shifts on closed dates are more than can be switched at once (${MAX_WORK_MODE_SWITCH}); they are still office. Switch them one by one.`,
        'error',
        8000,
      );
      return;
    }
    try {
      await api.put('/api/schedule/work-mode', {
        shift_ids: office.map((shift) => shift.id),
        work_mode: 'remote',
      });
      for (const shift of office) shift.work_mode = 'remote';
      showToast(
        `Switched ${office.length} shift${office.length > 1 ? 's' : ''} to remote on closed dates.`,
      );
    } catch (err) {
      const e = describeApiError(err, 'Could not switch the closed dates to remote');
      if (!e.silent) {
        showToast(
          `The month was generated, but the shifts on closed dates are still office: ${e.message}`,
          'error',
          8000,
        );
      }
    }
  }

  // PUT /api/schedule/work-mode accepts at most this many shift ids (ScheduleController::MAX_WORK_MODE_SWITCH).
  const MAX_WORK_MODE_SWITCH = 2000;

  /**
   * Generates the month. `generated`: the server created it (on failure its reason is shown);
   * `reloaded`: the schedule on screen was then reloaded from the server.
   */
  async function doGenerateMonth() {
    generating.value = true;
    showGenerateModal.value = false;
    try {
      const shiftTimes = genConfig.value.shifts.map((s) => ({
        start_time: s.start_time,
        end_time: s.end_time,
        color: s.color || null,
        color_label: s.color_label || null,
        shift_type: s.shift_type?.trim() || null,
        checklists: (s.checklists || [])
          .filter((c) => c.checklist_template_id)
          .map((c) => ({
            checklist_template_id: c.checklist_template_id,
            is_required: c.is_required || false,
            deadline_minutes: c.deadline_minutes || null,
            deadline_reference: c.deadline_reference || 'shift_start',
          })),
      }));
      const response = await api.post('/api/schedule/generate', {
        month: monthKey.value,
        shift_group_id: activeGroupId.value,
        shifts_per_day: genConfig.value.shiftsPerDay,
        shift_times: shiftTimes,
        work_mode_weekday: genConfig.value.workModeWeekday,
        work_mode_weekend: genConfig.value.workModeWeekend,
      });
      showToast(response.data.message);
      return { generated: true, reloaded: await fetchSchedule() };
    } catch (err) {
      const e = describeApiError(err, 'Failed to generate month');
      if (!e.silent) showToast(e.message, 'error', 6000);
      return { generated: false, reloaded: false };
    } finally {
      generating.value = false;
    }
  }

  return {
    generating,
    deleteStep,
    deleting,
    clearMonth,
    closureConflict,
    showGenerateModal,
    genConfig,
    openGenerateModal,
    onGenShiftCountChange,
    syncNextShiftStart,
    donutSegments,
    donutGapMinutes,
    donutOverlapMinutes,
    generateMonth,
    cancelClosureConflict,
    proceedGenerate,
    proceedGenerateAsRemote,
  };
}
