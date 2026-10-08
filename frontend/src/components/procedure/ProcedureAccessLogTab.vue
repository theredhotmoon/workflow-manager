<template>
  <div>
    <!-- Filters -->
    <div class="flex flex-wrap gap-3 mb-4 items-end">
      <div class="min-w-[140px]">
        <label class="block text-[10px] text-slate-500 uppercase tracking-wider font-semibold mb-1">User</label>
        <select v-model="filters.user_id" @change="fetchLogs"
          class="w-full px-3 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
          <option value="" class="bg-slate-800">All users</option>
          <option v-for="u in uniqueUsers" :key="u.id" :value="u.id" class="bg-slate-800">{{ u.name }}</option>
        </select>
      </div>
      <div class="min-w-[100px]">
        <label class="block text-[10px] text-slate-500 uppercase tracking-wider font-semibold mb-1">Version</label>
        <select v-model="filters.version" @change="fetchLogs"
          class="w-full px-3 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
          <option value="" class="bg-slate-800">All</option>
          <option v-for="v in uniqueVersions" :key="v" :value="v" class="bg-slate-800">v{{ v }}</option>
        </select>
      </div>
      <div class="min-w-[130px]">
        <label class="block text-[10px] text-slate-500 uppercase tracking-wider font-semibold mb-1">From</label>
        <input v-model="filters.date_from" type="date" @change="fetchLogs"
          class="w-full px-3 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
      </div>
      <div class="min-w-[130px]">
        <label class="block text-[10px] text-slate-500 uppercase tracking-wider font-semibold mb-1">To</label>
        <input v-model="filters.date_to" type="date" @change="fetchLogs"
          class="w-full px-3 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
      </div>
      <button v-if="hasActiveFilters" @click="clearFilters"
        class="px-3 py-1.5 text-xs text-slate-400 hover:text-white bg-white/5 border border-white/10 rounded-lg hover:bg-white/10 transition-all">
        Clear
      </button>
    </div>

    <!-- Results -->
    <div v-if="loading" class="text-center py-8"><p class="text-slate-400 text-sm">Loading...</p></div>
    <div v-else-if="!logs.length" class="text-center text-slate-500 text-sm py-8">No access logs found.</div>
    <div v-else class="space-y-1">
      <p class="text-[10px] text-slate-500 mb-2">{{ logs.length }} entries</p>
      <div v-for="log in logs" :key="log.id"
        class="flex items-center justify-between px-3 py-2 bg-white/3 border border-white/5 rounded-lg text-sm">
        <div class="flex items-center gap-2">
          <span class="text-slate-300">{{ log.user?.name }}</span>
          <span v-if="log.version_read" class="px-1.5 py-0.5 text-[9px] font-bold bg-indigo-500/15 text-indigo-400 border border-indigo-500/20 rounded-full tabular-nums">v{{ log.version_read }}</span>
        </div>
        <span class="text-slate-500 text-xs">{{ fmtDateTime(log.accessed_at) }}</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import api from '../../axios.js';
import { useLatestRequest } from '../../composables/useLatestRequest.js';

const props = defineProps({
  procedureId: { type: Number, required: true },
});

const logs = ref([]);
const loading = ref(true);
const uniqueUsers = ref([]);
const uniqueVersions = ref([]);

const filters = reactive({
  user_id: '',
  version: '',
  date_from: '',
  date_to: '',
});

const hasActiveFilters = computed(
  () => filters.user_id || filters.version || filters.date_from || filters.date_to,
);

function fmtDateTime(d) {
  if (!d) return '';
  return new Date(d).toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

// The unfiltered mount load and every filter change write the same list, so they share one
// channel: a slow unfiltered load must not replace a filter the user already picked.
const logsRequests = useLatestRequest();

async function fetchLogs() {
  const token = logsRequests.begin();
  loading.value = true;
  try {
    const params = {};
    if (filters.user_id) params.user_id = filters.user_id;
    if (filters.version) params.version = filters.version;
    if (filters.date_from) params.date_from = filters.date_from;
    if (filters.date_to) params.date_to = filters.date_to;
    const res = await api.get(`/api/procedures/${props.procedureId}/access-log`, { params });
    if (!logsRequests.isCurrent(token)) return;
    logs.value = res.data;
  } catch {
  } finally {
    if (logsRequests.isCurrent(token)) loading.value = false;
  }
}

function clearFilters() {
  filters.user_id = '';
  filters.version = '';
  filters.date_from = '';
  filters.date_to = '';
  fetchLogs();
}

onMounted(async () => {
  // Fetch initial unfiltered data to populate filter dropdowns
  const token = logsRequests.begin();
  try {
    const data = (await api.get(`/api/procedures/${props.procedureId}/access-log`)).data;
    // The list only if no filter was picked meanwhile; the dropdown options always.
    if (logsRequests.isCurrent(token)) logs.value = data;

    // Extract unique users and versions for dropdowns
    const userMap = {};
    const versionSet = new Set();
    data.forEach((log) => {
      if (log.user) userMap[log.user.id] = log.user;
      if (log.version_read) versionSet.add(log.version_read);
    });
    uniqueUsers.value = Object.values(userMap).sort((a, b) => a.name.localeCompare(b.name));
    uniqueVersions.value = [...versionSet].sort((a, b) => b - a);
  } catch {
  } finally {
    if (logsRequests.isCurrent(token)) loading.value = false;
  }
});
</script>
