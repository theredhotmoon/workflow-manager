<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <!-- Header -->
      <div class="flex items-center justify-between mb-8">
        <div>
          <h1 class="text-2xl font-bold text-white">My Shifts</h1>
          <p class="text-sm text-slate-400 mt-1">Your assigned shifts at a glance</p>
        </div>
        <!-- Month Nav -->
        <div class="flex items-center gap-3">
          <button @click="prevMonth" aria-label="Previous month" class="w-9 h-9 flex items-center justify-center bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl text-slate-300 hover:text-white transition-all">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
          </button>
          <span class="text-white font-semibold text-sm min-w-[130px] text-center">{{ monthLabel }}</span>
          <button @click="nextMonth" aria-label="Next month" class="w-9 h-9 flex items-center justify-center bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl text-slate-300 hover:text-white transition-all">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
          </button>
        </div>
      </div>

      <!-- Shifts loaded but delegations did not: a small, non-blocking notice -->
      <div v-if="!loading && !shiftsError && delegationsFailed" role="status"
        class="mb-4 flex items-center justify-between gap-3 px-4 py-2.5 bg-amber-500/10 border border-amber-500/20 rounded-xl text-xs text-amber-300">
        <span>Delegations couldn't be loaded, so they may be missing below.</span>
        <button @click="fetchMyShifts" class="font-semibold text-amber-200 hover:text-white">Retry</button>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-20">
        <svg class="animate-spin w-8 h-8 text-indigo-400" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
      </div>

      <!-- Shifts failed to load: say so (not "no shifts") and offer a retry -->
      <div v-else-if="shiftsError" role="alert" class="text-center py-20">
        <div class="w-16 h-16 rounded-2xl bg-red-500/10 border border-red-500/20 flex items-center justify-center mx-auto mb-4">
          <svg class="w-8 h-8 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
          </svg>
        </div>
        <p class="text-red-300 text-sm mb-4">{{ shiftsError }}</p>
        <button @click="fetchMyShifts"
          class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all">
          Retry
        </button>
      </div>

      <!-- No shifts -->
      <div v-else-if="sortedDates.length === 0 && delegationDates.length === 0" class="text-center py-20">
        <div class="w-16 h-16 rounded-2xl bg-slate-800/50 border border-white/10 flex items-center justify-center mx-auto mb-4">
          <svg class="w-8 h-8 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
          </svg>
        </div>
        <p class="text-slate-400 text-sm">No shifts or delegations for this month.</p>
      </div>

      <!-- Day-by-day list -->
      <div v-else class="space-y-4">
        <div v-for="dateStr in allDates" :key="dateStr"
          :class="['bg-white/5 backdrop-blur-xl border rounded-2xl overflow-hidden transition-all',
            isToday(dateStr) ? 'border-indigo-500/40 ring-1 ring-indigo-500/20' : 'border-white/10']">

          <!-- Date Header -->
          <div :class="['px-5 py-3 flex items-center justify-between border-b',
            isToday(dateStr) ? 'bg-indigo-500/10 border-indigo-500/20' : 'bg-white/[0.03] border-white/5']">
            <div class="flex items-center gap-3">
              <div :class="['w-10 h-10 rounded-xl flex items-center justify-center text-lg font-bold',
                isToday(dateStr) ? 'bg-indigo-500/20 text-indigo-300' : 'bg-white/5 text-slate-400']">
                {{ new Date(dateStr + 'T12:00:00').getDate() }}
              </div>
              <div>
                <p :class="['text-sm font-semibold', isToday(dateStr) ? 'text-indigo-300' : 'text-white']">
                  {{ formatDayName(dateStr) }}
                </p>
                <p class="text-[11px] text-slate-500">{{ formatFullDate(dateStr) }}</p>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <span v-if="isToday(dateStr)" class="px-2 py-0.5 text-[10px] font-bold uppercase rounded-full bg-indigo-500/25 text-indigo-300 border border-indigo-500/30 animate-pulse">Today</span>
              <span class="px-2 py-0.5 text-[10px] font-medium rounded-full bg-white/5 text-slate-400 border border-white/10">
                {{ (shifts[dateStr]?.length || 0) }} shift{{ (shifts[dateStr]?.length || 0) !== 1 ? 's' : '' }}
              </span>
            </div>
          </div>

          <!-- Delegations for this day -->
          <div v-if="getDayDelegations(dateStr).length" class="border-b border-white/5">
            <div v-for="del in getDayDelegations(dateStr)" :key="'del-' + del.id" class="px-5 py-3 flex items-start gap-3 bg-purple-500/5">
              <div class="w-8 h-8 rounded-lg bg-purple-500/15 flex items-center justify-center mt-0.5 shrink-0">
                <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-purple-300">{{ del.name }}</p>
                <p class="text-xs text-slate-400">📍 {{ del.destination }}</p>
                <p class="text-[10px] text-slate-500 mt-0.5">
                  {{ formatDelegationTime(del.start_datetime) }} → {{ formatDelegationTime(del.end_datetime) }}
                </p>
              </div>
              <span class="px-2 py-0.5 text-[9px] font-bold uppercase rounded-full bg-purple-500/15 text-purple-300 border border-purple-500/20 shrink-0">Delegation</span>
            </div>
          </div>

          <!-- Shifts -->
          <div v-if="shifts[dateStr]?.length" class="divide-y divide-white/5">
            <div v-for="shift in shifts[dateStr]" :key="shift.shift_id" class="px-5 py-3 flex items-start gap-4">
              <!-- Shift Info -->
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1 flex-wrap">
                  <span class="text-xs font-bold text-slate-300 uppercase">Shift {{ shift.shift_number }}</span>
                  <span v-if="shift.shift_group_name" class="px-1.5 py-0.5 text-[9px] font-medium rounded bg-indigo-500/15 text-indigo-300 border border-indigo-500/20">{{ shift.shift_group_name }}</span>
                  <span v-if="shift.start_time" class="px-1.5 py-0.5 text-[10px] font-mono text-slate-400 bg-white/5 border border-white/5 rounded">
                    {{ shift.start_time }}–{{ shift.end_time }}
                    <span v-if="shift.end_time < shift.start_time" class="text-[8px]">🌙</span>
                  </span>
                  <span :class="['px-1.5 py-0.5 text-[9px] font-medium rounded border',
                    shift.work_mode === 'remote'
                      ? 'bg-teal-500/15 text-teal-300 border-teal-500/20'
                      : 'bg-amber-500/10 text-amber-400 border-amber-500/15']"
                    :title="shift.work_mode === 'remote' ? 'Remote shift' : 'Office shift'">
                    {{ shift.work_mode === 'remote' ? '🏠 Remote' : '🏢 Office' }}
                  </span>
                  <!-- Change request button / pending badge -->
                  <button v-if="!shift.has_change_request && isShiftFuture(dateStr)"
                    @click="openChangeRequestModal(shift, dateStr)"
                    class="px-1.5 py-0.5 text-[9px] font-medium rounded border bg-orange-500/10 text-orange-300 border-orange-500/20 hover:bg-orange-500/20 transition-all cursor-pointer"
                    title="Request day off">
                    🔄 Request off
                  </button>
                  <span v-if="shift.has_change_request"
                    class="px-1.5 py-0.5 text-[9px] font-bold uppercase rounded bg-amber-500/15 text-amber-400 border border-amber-500/20"
                    :title="'Change request pending: ' + shift.change_request_reason">
                    Pending
                  </span>
                </div>

                <!-- Checklists Status -->
                <div v-if="shift.shift_checklists?.length" class="mt-2 space-y-1.5">
                  <div v-for="sc in shift.shift_checklists" :key="sc.id" class="flex items-center gap-2 flex-wrap">
                    <!-- Has submission -->
                    <template v-if="sc.submission">
                      <span class="flex items-center gap-1 px-2 py-1 text-[11px] font-medium rounded-lg bg-emerald-500/15 text-emerald-400 border border-emerald-500/20">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        ✓ {{ sc.checklist_template_name }}
                      </span>
                      <span v-if="sc.submission.submitted_on_time === false"
                        class="px-1.5 py-0.5 text-[9px] font-bold uppercase bg-red-500/15 text-red-400 border border-red-500/20 rounded-full">Late</span>
                      <span v-else-if="sc.submission.submitted_on_time === true"
                        class="px-1.5 py-0.5 text-[9px] font-bold uppercase bg-emerald-500/15 text-emerald-400 border border-emerald-500/20 rounded-full">On time</span>
                    </template>
                    <!-- Missing submission -->
                    <template v-else>
                      <span :class="['flex items-center gap-1 px-2 py-1 text-[11px] font-medium rounded-lg border',
                        sc.is_required
                          ? 'bg-red-500/15 text-red-400 border-red-500/20'
                          : 'bg-amber-500/10 text-amber-400 border-amber-500/20']">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        {{ sc.is_required ? '⚠' : '○' }} {{ sc.checklist_template_name }}
                      </span>
                      <span v-if="sc.deadline_minutes" class="text-[10px] text-slate-500">
                        Due: {{ sc.deadline_minutes }}min {{ sc.deadline_reference === 'shift_end' ? 'before end' : 'after start' }}
                      </span>
                    </template>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Stats footer -->
      <div v-if="sortedDates.length > 0" class="mt-6 flex items-center justify-center gap-6 text-sm text-slate-500">
        <span>{{ totalShifts }} shift{{ totalShifts !== 1 ? 's' : '' }} this month</span>
        <span>{{ totalHours }}h total</span>
      </div>
    </main>

    <!-- Change Request Modal -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="opacity-0" enter-to-class="opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="opacity-100" leave-to-class="opacity-0">
      <BaseModal v-if="changeRequestModal.show"
        overlay-class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm"
        panel-class="bg-slate-800/95 border border-white/10 rounded-2xl shadow-2xl w-full max-w-md mx-4"
        @close="changeRequestModal.show = false"
        v-slot="{ titleId }">
        <div class="p-6 pb-3 border-b border-white/10">
          <h3 :id="titleId" class="text-lg font-bold text-white">🔄 Request Day Off</h3>
          <p class="text-slate-400 text-sm mt-1">
            Shift {{ changeRequestModal.shiftNumber }} on <strong class="text-white">{{ changeRequestModal.dateLabel }}</strong>
          </p>
        </div>
        <div class="p-6 pt-4">
          <label class="block text-sm font-medium text-slate-300 mb-2">Reason <span class="text-red-400">*</span></label>
          <textarea v-model="changeRequestModal.reason" rows="4" placeholder="Please describe why you can't work this shift..."
            class="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/50 placeholder-slate-500 resize-none"></textarea>
          <p v-if="changeRequestModal.error" class="text-red-400 text-xs mt-1">{{ changeRequestModal.error }}</p>
        </div>
        <div class="p-6 pt-0 flex gap-3">
          <button @click="changeRequestModal.show = false"
            class="flex-1 px-4 py-2.5 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">Cancel</button>
          <button @click="submitChangeRequest" :disabled="changeRequestModal.submitting || !changeRequestModal.reason.trim()"
            class="flex-1 px-4 py-2.5 text-sm text-white bg-orange-600 hover:bg-orange-700 rounded-xl transition-all font-semibold shadow-lg shadow-orange-500/25 disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2">
            <svg v-if="changeRequestModal.submitting" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            Submit Request
          </button>
        </div>
      </BaseModal>
    </Transition>

    <AppToast />
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { describeApiError } from '../apiErrors.js';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import AppToast from '../components/AppToast.vue';
import BaseModal from '../components/BaseModal.vue';
import { useLatestRequest } from '../composables/useLatestRequest.js';
import { useToast } from '../composables/useToast';
import { localDateStr } from '../dates.js';

const loading = ref(true);
const shifts = ref({});
const delegations = ref([]);
// Why the shifts didn't load ('' when they did); delegations failing alone only adds a notice.
const shiftsError = ref('');
const delegationsFailed = ref(false);
const currentYear = ref(new Date().getFullYear());
const currentMonth = ref(new Date().getMonth());

const monthKey = computed(() => {
  return `${currentYear.value}-${String(currentMonth.value + 1).padStart(2, '0')}`;
});

const monthLabel = computed(() => {
  const d = new Date(currentYear.value, currentMonth.value, 1);
  return d.toLocaleString('en-US', { month: 'long', year: 'numeric' });
});

const sortedDates = computed(() => Object.keys(shifts.value).sort());

const delegationDates = computed(() => {
  const dates = new Set();
  for (const d of delegations.value) {
    const start = d.start_datetime.substring(0, 10);
    const end = d.end_datetime.substring(0, 10);
    let cur = new Date(start + 'T12:00:00');
    const last = new Date(end + 'T12:00:00');
    while (cur <= last) {
      const ds = localDateStr(cur);
      // Only include dates in current month
      if (ds.startsWith(monthKey.value)) dates.add(ds);
      cur.setDate(cur.getDate() + 1);
    }
  }
  return [...dates].sort();
});

const allDates = computed(() => {
  const set = new Set([...sortedDates.value, ...delegationDates.value]);
  return [...set].sort();
});

const totalShifts = computed(() => {
  let c = 0;
  for (const d of sortedDates.value) c += shifts.value[d].length;
  return c;
});

const totalHours = computed(() => {
  let mins = 0;
  for (const d of sortedDates.value) {
    for (const s of shifts.value[d]) {
      if (!s.start_time || !s.end_time) continue;
      const [sh, sm] = s.start_time.split(':').map(Number);
      const [eh, em] = s.end_time.split(':').map(Number);
      let dur = eh * 60 + em - (sh * 60 + sm);
      if (dur <= 0) dur += 24 * 60;
      mins += dur;
    }
  }
  return Math.round(mins / 60);
});

function prevMonth() {
  if (currentMonth.value === 0) {
    currentYear.value--;
    currentMonth.value = 11;
  } else currentMonth.value--;
}

function nextMonth() {
  if (currentMonth.value === 11) {
    currentYear.value++;
    currentMonth.value = 0;
  } else currentMonth.value++;
}

const todayStr = computed(() => localDateStr(new Date()));

function isToday(dateStr) {
  return dateStr === todayStr.value;
}

function formatDayName(dateStr) {
  return new Date(dateStr + 'T12:00:00').toLocaleDateString('en-US', { weekday: 'long' });
}

function formatFullDate(dateStr) {
  return new Date(dateStr + 'T12:00:00').toLocaleDateString('en-US', {
    month: 'long',
    day: 'numeric',
    year: 'numeric',
  });
}

// Switching months while a load is in flight overlaps fetches. Only the latest one may apply its
// result — otherwise a slower load of the previous month shows its shifts under the new month's
// heading. Both requests belong to one load, so one token covers the Promise.all.
const shiftsRequests = useLatestRequest();

async function fetchMyShifts() {
  const token = shiftsRequests.begin();
  loading.value = true;
  // allSettled: a failed delegations call must not hide the shifts, and a failed shifts call must
  // show an error, never the "no shifts" empty state.
  const [shiftRes, delRes] = await Promise.allSettled([
    api.get('/api/my-shifts', { params: { month: monthKey.value } }),
    api.get('/api/my-delegations', { params: { month: monthKey.value } }),
  ]);
  if (!shiftsRequests.isCurrent(token)) return;

  if (shiftRes.status === 'fulfilled') {
    shifts.value = shiftRes.value.data;
    shiftsError.value = '';
  } else {
    shifts.value = {};
    const { message } = describeApiError(shiftRes.reason, 'Could not load your shifts');
    shiftsError.value = message || 'Could not load your shifts.';
  }
  if (delRes.status === 'fulfilled') {
    delegations.value = delRes.value.data;
    delegationsFailed.value = false;
  } else {
    delegations.value = [];
    delegationsFailed.value = true;
  }
  loading.value = false;
}

function getDayDelegations(dateStr) {
  return delegations.value.filter((d) => {
    const start = d.start_datetime.substring(0, 10);
    const end = d.end_datetime.substring(0, 10);
    return dateStr >= start && dateStr <= end;
  });
}

function formatDelegationTime(dt) {
  if (!dt) return '';
  const d = new Date(dt);
  return (
    d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) +
    ' ' +
    d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
  );
}

watch(monthKey, fetchMyShifts);
onMounted(fetchMyShifts);

// Toast
const { showToast } = useToast();

// Change request
const changeRequestModal = ref({
  show: false,
  assignmentId: null,
  shiftNumber: null,
  dateLabel: '',
  reason: '',
  error: '',
  submitting: false,
});

function isShiftFuture(dateStr) {
  if (!dateStr) return false;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return new Date(dateStr + 'T00:00:00') >= today;
}

function openChangeRequestModal(shift, dateStr) {
  const d = new Date(dateStr + 'T12:00:00');
  changeRequestModal.value = {
    show: true,
    assignmentId: shift.assignment_id,
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
    await api.post('/api/shift-change-requests', {
      shift_assignment_id: modal.assignmentId,
      reason: modal.reason.trim(),
    });
    modal.show = false;
    showToast('Day-off request submitted successfully.');
    // Update locally
    for (const dateStr in shifts.value) {
      const s = shifts.value[dateStr].find((s) => s.assignment_id === modal.assignmentId);
      if (s) {
        s.has_change_request = true;
        s.change_request_reason = modal.reason.trim();
        break;
      }
    }
  } catch (err) {
    modal.error = err.response?.data?.message || 'Failed to submit request.';
  } finally {
    modal.submitting = false;
  }
}
</script>

