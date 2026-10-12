// frontend/src/composables/schedule/useScheduleMonth.js
//
// The month on the Schedule screen: which month (prev/next, the calendar grid), loading it
// (fetchSchedule, guarded by useLatestRequest so only the latest load applies) and the overlays
// loaded with it — delegations, newbie periods, office closures and shift colours — with their
// per-day lookups. Moved out of ScheduleView.vue unchanged.
//
// From the screen: the active group (useShiftGroups), whether the user manages the schedule, the
// selected employee (the day lookups show only theirs) and the rest-day prompt, which a load
// closes (its shift/chip objects are about to be replaced).
import { computed, ref, watch } from 'vue';
import api from '../../axios.js';
import { localDateStr, todayStr } from '../../dates.js';
import { useLatestRequest } from '../useLatestRequest.js';

export function useScheduleMonth({
  activeGroupId,
  isAllGroupsMode,
  canManageSchedule,
  selectedEmployee,
  restPrompt,
  showToast,
}) {
  const loading = ref(true);
  const currentYear = ref(new Date().getFullYear());
  const currentMonth = ref(new Date().getMonth()); // 0-indexed
  const schedule = ref({});
  const hasScheduleShifts = computed(() =>
    Object.values(schedule.value).some((shifts) => shifts.length > 0),
  );
  const employees = ref([]);

  // Delegations overlay
  const showDelegations = ref(false);
  const monthDelegations = ref([]);

  // Office closures overlay
  const showClosures = ref(false);
  const monthClosures = ref([]);

  // Shift colors
  const showShiftColors = ref(true);
  const shiftColors = ref([]);
  const shiftColorById = computed(() => {
    const map = {};
    for (const c of shiftColors.value) {
      map[String(c.id)] = c;
    }
    return map;
  });

  async function fetchShiftColors() {
    try {
      const res = await api.get('/api/shift-colors');
      shiftColors.value = res.data;
    } catch {
      shiftColors.value = [];
    }
  }

  // Fetch delegations for the schedule overlay (also read by the drop-time delegation-conflict check).
  // It runs after every schedule load and when the overlay is switched on; only the latest call may
  // apply, so a slow previous month's list can't replace the month on screen.
  const delegationRequests = useLatestRequest();
  // A failed load is not "nobody is on delegation": the drop-time check warns instead of passing.
  const delegationsFailed = ref(false);

  async function fetchDelegations() {
    if (!canManageSchedule.value) return; // manager-only data (overlay and drop checks)
    const token = delegationRequests.begin();
    try {
      const res = await api.get('/api/delegations/month', { params: { month: monthKey.value } });
      if (!delegationRequests.isCurrent(token)) return;
      monthDelegations.value = res.data;
      delegationsFailed.value = false;
    } catch {
      if (!delegationRequests.isCurrent(token)) return;
      monthDelegations.value = [];
      delegationsFailed.value = true;
      showToast("Couldn't load delegations: delegation conflicts can't be checked.", 'warning');
    }
  }

  function getDayDelegations(dateStr) {
    if (!dateStr) return [];
    return monthDelegations.value.filter((d) => {
      const start = d.start_datetime.substring(0, 10);
      const end = d.end_datetime.substring(0, 10);
      if (dateStr < start || dateStr > end) return false;
      // If an employee is selected, show only their delegations
      if (selectedEmployee.value) {
        return d.users.some((u) => u.id === selectedEmployee.value.id);
      }
      return true;
    });
  }

  watch(showDelegations, (val) => {
    if (val) fetchDelegations();
  });

  // Newbies overlay
  const showNewbies = ref(false);
  const monthNewbies = ref([]);

  const newbieRequests = useLatestRequest();

  async function fetchNewbies() {
    if (!canManageSchedule.value) return; // manager-only data (overlay and roster stars)
    const token = newbieRequests.begin();
    try {
      const res = await api.get('/api/newbie-periods/month', { params: { month: monthKey.value } });
      if (!newbieRequests.isCurrent(token)) return;
      monthNewbies.value = res.data;
    } catch {
      if (!newbieRequests.isCurrent(token)) return;
      monthNewbies.value = [];
      showToast("Couldn't load newbie periods.", 'warning');
    }
  }

  function getDayNewbies(dateStr) {
    if (!dateStr) return [];
    return monthNewbies.value.filter((np) => {
      const start = np.start_date?.substring(0, 10);
      const end = np.end_date?.substring(0, 10);
      if (dateStr < start || dateStr > end) return false;
      if (selectedEmployee.value) {
        return np.user_id === selectedEmployee.value.id;
      }
      return true;
    });
  }

  watch(showNewbies, (val) => {
    if (val) fetchNewbies();
  });

  // Office closures fetch + helpers
  const closureRequests = useLatestRequest();

  async function fetchClosures() {
    const token = closureRequests.begin();
    try {
      const res = await api.get('/api/office-closures', { params: { month: monthKey.value } });
      if (!closureRequests.isCurrent(token)) return;
      monthClosures.value = res.data;
    } catch {
      if (!closureRequests.isCurrent(token)) return;
      monthClosures.value = [];
      showToast("Couldn't load office closures.", 'warning');
    }
  }

  function getClosureForDate(dateStr) {
    if (!dateStr) return null;
    return monthClosures.value.find((c) => (c.date?.substring?.(0, 10) || c.date) === dateStr);
  }

  watch(showClosures, (val) => {
    if (val) fetchClosures();
  });

  // Newbie helpers
  function isCurrentNewbie(userId) {
    const today = todayStr();
    return monthNewbies.value.some((np) => {
      const start = np.start_date?.substring(0, 10);
      const end = np.end_date?.substring(0, 10);
      return np.user_id === userId && today >= start && today <= end;
    });
  }

  const weekDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

  const monthKey = computed(() => {
    const m = (currentMonth.value + 1).toString().padStart(2, '0');
    return `${currentYear.value}-${m}`;
  });

  const monthLabel = computed(() => {
    const date = new Date(currentYear.value, currentMonth.value, 1);
    return date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
  });

  const employeePool = ref([]);

  // Get overnight shifts from previous day that spill into this day
  function getOvernightFromPrevDay(dateStr) {
    if (!dateStr) return [];
    const prevDate = new Date(dateStr + 'T12:00:00');
    prevDate.setDate(prevDate.getDate() - 1);
    const prevDateStr = localDateStr(prevDate);
    const prevDayShifts = schedule.value[prevDateStr] || [];
    return prevDayShifts.filter((s) => s.start_time && s.end_time && s.end_time < s.start_time);
  }

  const calendarWeeks = computed(() => {
    const year = currentYear.value;
    const month = currentMonth.value;
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const totalDays = lastDay.getDate();

    // Monday = 0, Sunday = 6 (ISO week)
    let startWeekday = firstDay.getDay() - 1;
    if (startWeekday < 0) startWeekday = 6;

    const today = todayStr();

    const weeks = [];
    let currentWeek = [];

    // Fill previous month days
    const prevMonthLast = new Date(year, month, 0);
    for (let i = startWeekday - 1; i >= 0; i--) {
      const d = prevMonthLast.getDate() - i;
      currentWeek.push({
        dayNumber: d,
        dateStr: null,
        isCurrentMonth: false,
        isToday: false,
        shifts: [],
      });
    }

    // Current month days
    for (let d = 1; d <= totalDays; d++) {
      const dateStr = `${year}-${(month + 1).toString().padStart(2, '0')}-${d.toString().padStart(2, '0')}`;
      currentWeek.push({
        dayNumber: d,
        dateStr,
        isCurrentMonth: true,
        isToday: dateStr === today,
        shifts: schedule.value[dateStr] || [],
      });
      if (currentWeek.length === 7) {
        weeks.push(currentWeek);
        currentWeek = [];
      }
    }

    // Fill next month days
    if (currentWeek.length > 0) {
      let nextD = 1;
      while (currentWeek.length < 7) {
        currentWeek.push({
          dayNumber: nextD++,
          dateStr: null,
          isCurrentMonth: false,
          isToday: false,
          shifts: [],
        });
      }
      weeks.push(currentWeek);
    }

    return weeks;
  });

  // Methods
  function prevMonth() {
    if (currentMonth.value === 0) {
      currentMonth.value = 11;
      currentYear.value--;
    } else {
      currentMonth.value--;
    }
  }

  function nextMonth() {
    if (currentMonth.value === 11) {
      currentMonth.value = 0;
      currentYear.value++;
    } else {
      currentMonth.value++;
    }
  }

  // Several fetches can overlap (e.g. a deep link fires one before the groups have loaded, then the
  // user switches tabs). Only the latest one may apply its response — otherwise a slower, older
  // response (which also reports a shift_group_id) snaps the view back to the wrong group.
  const scheduleRequests = useLatestRequest();

  // quiet: refresh in place (no spinner, the grid keeps its scroll position).
  /** Loads the displayed month; true when this load's result is the one now on screen. */
  async function fetchSchedule({ quiet = false } = {}) {
    const token = scheduleRequests.begin();
    restPrompt.value = null; // its shift/chip objects are about to be replaced
    if (!quiet) loading.value = true;
    try {
      const params = { month: monthKey.value };
      if (activeGroupId.value) params.shift_group_id = activeGroupId.value;
      const response = await api.get('/api/schedule', { params });
      if (!scheduleRequests.isCurrent(token)) return false;
      schedule.value = response.data.schedule;
      employees.value = response.data.employees;
      employeePool.value = response.data.employees.map((e) => ({
        ...e,
        user_id: e.id,
        user_name: e.name,
      }));
      // Update activeGroupId from server response if available (but not when in all-groups mode)
      if (response.data.shift_group_id && !isAllGroupsMode.value)
        activeGroupId.value = response.data.shift_group_id;
      fetchDelegations(); // Always fetch for conflict checks
      fetchNewbies(); // Always fetch for newbie star display
      fetchShiftColors(); // Load global color definitions
      if (showClosures.value) fetchClosures(); // Keep the closure overlay on the displayed month
      return true;
    } catch {
      if (scheduleRequests.isCurrent(token)) showToast('Failed to load schedule.', 'error');
      return false;
    } finally {
      if (scheduleRequests.isCurrent(token)) loading.value = false;
    }
  }

  return {
    loading,
    currentYear,
    currentMonth,
    schedule,
    hasScheduleShifts,
    employees,
    employeePool,
    weekDays,
    monthKey,
    monthLabel,
    calendarWeeks,
    prevMonth,
    nextMonth,
    getOvernightFromPrevDay,
    showDelegations,
    monthDelegations,
    delegationsFailed,
    fetchDelegations,
    getDayDelegations,
    showNewbies,
    monthNewbies,
    fetchNewbies,
    getDayNewbies,
    isCurrentNewbie,
    showClosures,
    monthClosures,
    fetchClosures,
    getClosureForDate,
    showShiftColors,
    shiftColors,
    shiftColorById,
    fetchShiftColors,
    fetchSchedule,
  };
}
