<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <!-- Header -->
      <div class="flex items-center justify-between mb-8">
        <div>
          <h1 class="text-2xl font-bold text-white">Notifications</h1>
          <p class="text-sm text-slate-400 mt-1">All your alerts and updates</p>
        </div>
        <button v-if="hasUnread" @click="markAllRead"
          class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition-all text-sm flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          Mark all as read
        </button>
      </div>

      <!-- Filters -->
      <div class="flex flex-wrap items-center gap-3 mb-6">
        <!-- Type filter -->
        <select v-model="filterType"
          class="px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-sm text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50 appearance-none cursor-pointer">
          <option value="">All Types</option>
          <option v-for="t in alarmTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>

        <!-- Status filter -->
        <select v-model="filterStatus"
          class="px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-sm text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50 appearance-none cursor-pointer">
          <option value="">All Status</option>
          <option value="unread">Unread</option>
          <option value="read">Read</option>
        </select>

        <!-- Date range -->
        <input type="date" v-model="filterDateFrom"
          class="px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-sm text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50 cursor-pointer"
          placeholder="From" />
        <span class="text-slate-600 text-xs">to</span>
        <input type="date" v-model="filterDateTo"
          class="px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-sm text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50 cursor-pointer"
          placeholder="To" />

        <button v-if="filterType || filterStatus || filterDateFrom || filterDateTo" @click="clearFilters"
          class="px-3 py-2 text-xs text-slate-400 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">
          Clear filters
        </button>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-20">
        <svg class="animate-spin w-8 h-8 text-indigo-400" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
      </div>

      <!-- Empty state -->
      <div v-else-if="alarms.length === 0" class="text-center py-20">
        <div class="w-16 h-16 rounded-2xl bg-slate-800/50 border border-white/10 flex items-center justify-center mx-auto mb-4">
          <svg class="w-8 h-8 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
          </svg>
        </div>
        <p class="text-slate-400 text-sm">No notifications to show.</p>
      </div>

      <!-- Alarm List -->
      <div v-else class="space-y-2">
        <div v-for="alarm in alarms" :key="alarm.id"
          @click="handleAlarmClick(alarm)"
          :class="['bg-white/5 backdrop-blur-xl border rounded-2xl p-4 cursor-pointer transition-all hover:bg-white/[0.07]',
            !alarm.read_at ? 'border-indigo-500/30 ring-1 ring-indigo-500/10' : 'border-white/10']">
          <div class="flex items-start gap-4">
            <span :class="['w-10 h-10 rounded-xl flex items-center justify-center text-lg shrink-0',
              getAlarmStyle(alarm.type)]">
              {{ getAlarmIcon(alarm.type) }}
            </span>
            <div class="flex-1 min-w-0">
              <div class="flex items-start justify-between gap-4">
                <p :class="['text-sm leading-snug', !alarm.read_at ? 'text-white font-semibold' : 'text-slate-300']">
                  {{ alarm.short_message }}
                </p>
                <div class="flex items-center gap-2 shrink-0">
                  <span v-if="!alarm.read_at" class="w-2.5 h-2.5 rounded-full bg-indigo-400"></span>
                  <span class="text-[10px] text-slate-600 whitespace-nowrap">{{ formatDate(alarm.created_at) }}</span>
                </div>
              </div>
              <p v-if="alarm.details" class="text-xs text-slate-500 mt-1 line-clamp-2">{{ alarm.details }}</p>
              <div class="flex items-center gap-3 mt-2">
                <span class="px-2 py-0.5 text-[9px] font-bold uppercase rounded-full border"
                  :class="getTypeBadgeClass(alarm.type)">
                  {{ getTypeLabel(alarm.type) }}
                </span>
                <router-link v-if="alarm.link_url" :to="alarm.link_url"
                  class="text-[10px] text-indigo-400 hover:text-indigo-300 font-medium" @click.stop>
                  {{ alarm.link_label || 'View' }} →
                </router-link>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="totalPages > 1" class="flex items-center justify-center gap-2 mt-8">
        <button @click="page = Math.max(1, page - 1)" :disabled="page <= 1"
          class="px-3 py-2 text-sm bg-white/5 border border-white/10 rounded-xl text-slate-300 hover:bg-white/10 disabled:opacity-30 transition-all">
          ← Previous
        </button>
        <span class="text-sm text-slate-500">Page {{ page }} of {{ totalPages }}</span>
        <button @click="page = Math.min(totalPages, page + 1)" :disabled="page >= totalPages"
          class="px-3 py-2 text-sm bg-white/5 border border-white/10 rounded-xl text-slate-300 hover:bg-white/10 disabled:opacity-30 transition-all">
          Next →
        </button>
      </div>
    </main>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import { useAlarms } from '../composables/useAlarms.js';
import { useLatestRequest } from '../composables/useLatestRequest.js';

const loading = ref(true);
const alarms = ref([]);
const page = ref(1);
const totalPages = ref(1);
const filterType = ref('');
const filterStatus = ref('');
const filterDateFrom = ref('');
const filterDateTo = ref('');

const alarmTypes = [
  { value: 'EMPLOYEE_CANNOT_ATTEND_SHIFT', label: '📅 Shift Attendance' },
  { value: 'PROCEDURE_NEW_ISSUE_REPORTED', label: '⚠️ Issue Reported' },
  { value: 'PROCEDURE_NEW_QUESTION_ADDED', label: '❓ New Question' },
  { value: 'PROCEDURE_QUESTION_REPLIED', label: '💬 Question Replied' },
  { value: 'PROCEDURE_ISSUE_SORTED', label: '✅ Issue Resolved' },
  { value: 'SHIFT_CHANGE_REQUEST_ACKNOWLEDGED', label: '👍 Change Request Seen' },
];

const hasUnread = computed(() => alarms.value.some((a) => !a.read_at));

// Filter and page changes overlap fetches (a filter change on page > 1 even fires two). Only the
// latest may apply its page, total or clear `loading`, so the list always matches the filter bar.
const alarmRequests = useLatestRequest();

async function fetchAlarms() {
  const token = alarmRequests.begin();
  loading.value = true;
  try {
    const params = { page: page.value };
    if (filterType.value) params.type = filterType.value;
    if (filterStatus.value) params.status = filterStatus.value;
    if (filterDateFrom.value) params.date_from = filterDateFrom.value;
    if (filterDateTo.value) params.date_to = filterDateTo.value;
    const res = await api.get('/api/alarms', { params });
    if (!alarmRequests.isCurrent(token)) return;
    alarms.value = res.data.data;
    totalPages.value = res.data.last_page;
  } catch {
    if (alarmRequests.isCurrent(token)) alarms.value = [];
  } finally {
    if (alarmRequests.isCurrent(token)) loading.value = false;
  }
}

// Shared with the header bell: its badge follows what's marked read here (rolled back, with a
// toast, when the server refuses).
const alarmStore = useAlarms();
// Registered with the store, so marking read from the header bell updates this list too.
const unregisterList = alarmStore.registerList(alarms);
onUnmounted(unregisterList);

function handleAlarmClick(alarm) {
  alarmStore.markRead(alarm);
}

function markAllRead() {
  alarmStore.markAllRead();
}

function clearFilters() {
  filterType.value = '';
  filterStatus.value = '';
  filterDateFrom.value = '';
  filterDateTo.value = '';
}

function getAlarmIcon(type) {
  const icons = {
    EMPLOYEE_CANNOT_ATTEND_SHIFT: '📅',
    PROCEDURE_NEW_ISSUE_REPORTED: '⚠️',
    PROCEDURE_NEW_QUESTION_ADDED: '❓',
    PROCEDURE_QUESTION_REPLIED: '💬',
    PROCEDURE_ISSUE_SORTED: '✅',
    SHIFT_CHANGE_REQUEST_ACKNOWLEDGED: '👍',
  };
  return icons[type] || '🔔';
}

function getAlarmStyle(type) {
  const styles = {
    EMPLOYEE_CANNOT_ATTEND_SHIFT: 'bg-orange-500/20',
    PROCEDURE_NEW_ISSUE_REPORTED: 'bg-red-500/20',
    PROCEDURE_NEW_QUESTION_ADDED: 'bg-blue-500/20',
    PROCEDURE_QUESTION_REPLIED: 'bg-emerald-500/20',
    PROCEDURE_ISSUE_SORTED: 'bg-green-500/20',
    SHIFT_CHANGE_REQUEST_ACKNOWLEDGED: 'bg-sky-500/20',
  };
  return styles[type] || 'bg-slate-500/20';
}

function getTypeBadgeClass(type) {
  const classes = {
    EMPLOYEE_CANNOT_ATTEND_SHIFT: 'bg-orange-500/15 text-orange-300 border-orange-500/20',
    PROCEDURE_NEW_ISSUE_REPORTED: 'bg-red-500/15 text-red-300 border-red-500/20',
    PROCEDURE_NEW_QUESTION_ADDED: 'bg-blue-500/15 text-blue-300 border-blue-500/20',
    PROCEDURE_QUESTION_REPLIED: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/20',
    PROCEDURE_ISSUE_SORTED: 'bg-green-500/15 text-green-300 border-green-500/20',
  };
  return classes[type] || 'bg-slate-500/15 text-slate-300 border-slate-500/20';
}

function getTypeLabel(type) {
  const labels = {
    EMPLOYEE_CANNOT_ATTEND_SHIFT: 'Shift',
    PROCEDURE_NEW_ISSUE_REPORTED: 'Issue',
    PROCEDURE_NEW_QUESTION_ADDED: 'Question',
    PROCEDURE_QUESTION_REPLIED: 'Reply',
    PROCEDURE_ISSUE_SORTED: 'Resolved',
  };
  return labels[type] || type;
}

function formatDate(isoStr) {
  if (!isoStr) return '';
  const d = new Date(isoStr);
  const now = new Date();
  const diffMs = now - d;
  const mins = Math.floor(diffMs / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

// Re-fetch when filters or page change
watch([filterType, filterStatus, filterDateFrom, filterDateTo], () => {
  page.value = 1;
  fetchAlarms();
});
watch(page, fetchAlarms);
onMounted(fetchAlarms);
</script>
