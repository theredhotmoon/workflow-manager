<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 py-8">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-white">Delegation Archive</h1>
          <p class="text-slate-400 text-sm mt-1">{{ archived.length }} archived delegation{{ archived.length !== 1 ? 's' : '' }}</p>
        </div>
      </div>

      <!-- Filters -->
      <div class="bg-white/5 border border-white/10 rounded-2xl p-5 mb-6">
        <div class="flex items-center gap-2 mb-3">
          <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
          <h3 class="text-sm font-semibold text-white">Filters</h3>
          <button v-if="hasFilters" @click="clearFilters" class="ml-auto text-[10px] text-indigo-400 hover:text-indigo-300">Clear all</button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
          <div>
            <label class="block text-[10px] text-slate-500 uppercase tracking-wider mb-1 font-semibold">Start From</label>
            <input v-model="filters.start_from" type="date" @change="fetchData"
              class="w-full px-2.5 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500/50" />
          </div>
          <div>
            <label class="block text-[10px] text-slate-500 uppercase tracking-wider mb-1 font-semibold">Start To</label>
            <input v-model="filters.start_to" type="date" @change="fetchData"
              class="w-full px-2.5 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500/50" />
          </div>
          <div>
            <label class="block text-[10px] text-slate-500 uppercase tracking-wider mb-1 font-semibold">Destination</label>
            <select v-model="filters.destination" @change="fetchData"
              class="w-full px-2.5 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500/50">
              <option value="">All destinations</option>
              <option v-for="d in destinations" :key="d" :value="d">{{ d }}</option>
            </select>
          </div>
          <div>
            <label class="block text-[10px] text-slate-500 uppercase tracking-wider mb-1 font-semibold">Employee</label>
            <select v-model="filters.employee_id" @change="fetchData"
              class="w-full px-2.5 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500/50">
              <option value="">All employees</option>
              <option v-for="u in allUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-[10px] text-slate-500 uppercase tracking-wider mb-1 font-semibold">End From</label>
            <input v-model="filters.end_from" type="date" @change="fetchData"
              class="w-full px-2.5 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500/50" />
          </div>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="text-center py-16"><p class="text-slate-400">Loading…</p></div>

      <!-- Archived List -->
      <div v-else class="space-y-4">
        <div v-for="del in archived" :key="del.id"
          class="bg-white/5 border border-white/10 rounded-2xl overflow-hidden">
          <!-- Card Header -->
          <div class="p-5 cursor-pointer" @click="toggleExpand(del.id)">
            <div class="flex items-start justify-between gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-1 flex-wrap">
                  <h3 class="text-white font-semibold text-base truncate">{{ del.name }}</h3>
                  <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-full bg-slate-500/15 text-slate-400 border border-slate-500/20">
                    Archived
                  </span>
                </div>
                <div class="flex items-center gap-4 text-xs text-slate-400">
                  <span class="flex items-center gap-1">📍 {{ del.destination }}</span>
                  <span>{{ formatDate(del.start_datetime) }} → {{ formatDate(del.end_datetime) }}</span>
                  <span class="text-slate-500">{{ del.users?.length || 0 }} people</span>
                </div>
                <p v-if="del.description" class="text-xs text-slate-500 mt-1.5 line-clamp-1">{{ del.description }}</p>
                <p class="text-[10px] text-slate-600 mt-1">
                  Archived {{ formatDateTime(del.archived_at) }} by {{ del.archived_by_user?.name || '—' }}
                </p>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <button @click.stop="unarchiveDelegation(del)"
                  class="px-3 py-1.5 text-xs font-medium text-indigo-400 bg-indigo-500/10 border border-indigo-500/20 rounded-lg hover:bg-indigo-500/20 transition-all whitespace-nowrap">
                  ↩ Unarchive
                </button>
                <svg :class="['w-4 h-4 text-slate-500 transition-transform', expandedIds.has(del.id) ? 'rotate-180' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
              </div>
            </div>
          </div>

          <!-- Expanded -->
          <div v-if="expandedIds.has(del.id)" class="border-t border-white/10">
            <!-- Tab Bar -->
            <div class="flex border-b border-white/10 bg-white/[0.02]">
              <button @click="setSubTab(del.id, 'people')"
                :class="['px-5 py-2.5 text-xs font-medium transition-all border-b-2',
                  getSubTab(del.id) === 'people' ? 'text-indigo-300 border-indigo-400' : 'text-slate-500 border-transparent hover:text-slate-300']">
                People ({{ del.users?.length || 0 }})
              </button>
              <button @click="setSubTab(del.id, 'history')"
                :class="['px-5 py-2.5 text-xs font-medium transition-all border-b-2',
                  getSubTab(del.id) === 'history' ? 'text-indigo-300 border-indigo-400' : 'text-slate-500 border-transparent hover:text-slate-300']">
                History
              </button>
            </div>

            <!-- People Tab -->
            <div v-if="getSubTab(del.id) === 'people'" class="p-5">
              <p v-if="del.description" class="text-sm text-slate-400 mb-4 leading-relaxed">{{ del.description }}</p>
              <div v-if="del.users?.length" class="bg-white/5 border border-white/10 rounded-xl overflow-hidden">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="border-b border-white/10 text-[10px] text-slate-500 uppercase tracking-wider">
                      <th class="text-left px-4 py-2.5 font-semibold">Name</th>
                      <th class="text-left px-4 py-2.5 font-semibold">Status</th>
                      <th class="text-left px-4 py-2.5 font-semibold">Custom Dates</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="user in del.users" :key="user.id" class="border-b border-white/5 last:border-b-0">
                      <td class="px-4 py-2.5">
                        <div class="flex items-center gap-2">
                          <div class="w-6 h-6 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 flex items-center justify-center text-white font-bold text-[8px] shrink-0">
                            {{ getInitials(user.name) }}
                          </div>
                          <span class="text-white font-medium">{{ user.name }}</span>
                        </div>
                      </td>
                      <td class="px-4 py-2.5">
                        <span :class="['px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-full border',
                          user.pivot?.status === 'approved' ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20'
                          : user.pivot?.status === 'pending' ? 'bg-amber-500/15 text-amber-400 border-amber-500/20'
                          : 'bg-red-500/15 text-red-400 border-red-500/20']">
                          {{ user.pivot?.status || 'unknown' }}
                        </span>
                      </td>
                      <td class="px-4 py-2.5 text-xs text-slate-400 tabular-nums">
                        <template v-if="user.pivot?.custom_start || user.pivot?.custom_end">
                          {{ formatDate(user.pivot?.custom_start || del.start_datetime) }} → {{ formatDate(user.pivot?.custom_end || del.end_datetime) }}
                        </template>
                        <span v-else class="text-slate-600">Default dates</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p v-else class="text-xs text-slate-500 text-center py-4">No people were assigned.</p>
            </div>

            <!-- History Tab -->
            <div v-else-if="getSubTab(del.id) === 'history'" class="p-5">
              <div v-if="logsLoading[del.id]" class="text-center py-4"><p class="text-slate-500 text-xs">Loading…</p></div>
              <div v-else-if="logsData[del.id]?.length" class="space-y-0">
                <div v-for="log in logsData[del.id]" :key="log.id"
                  class="flex items-start gap-3 py-2.5 border-b border-white/5 last:border-b-0">
                  <div class="w-1.5 h-1.5 rounded-full mt-1.5 shrink-0"
                    :class="logColor(log.action)"></div>
                  <div class="flex-1 min-w-0">
                    <p class="text-xs text-slate-300">{{ log.details || log.action }}</p>
                    <p class="text-[10px] text-slate-500 mt-0.5">
                      by {{ log.performer?.name || 'System' }} · {{ formatDateTime(log.created_at) }}
                    </p>
                  </div>
                </div>
              </div>
              <p v-else class="text-xs text-slate-500 text-center py-4">No history records.</p>
            </div>
          </div>
        </div>

        <div v-if="!archived.length && !loading" class="text-center py-16">
          <p class="text-slate-500 text-sm">No archived delegations match your filters.</p>
        </div>
      </div>
    </main>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import { confirm } from '../composables/useConfirm.js';
import { useLatestRequest } from '../composables/useLatestRequest.js';

const archived = ref([]);
const allUsers = ref([]);
const destinations = ref([]);
const loading = ref(true);
const expandedIds = reactive(new Set());
const subTabs = reactive({});
const logsData = reactive({});
const logsLoading = reactive({});

const filters = ref({
  start_from: '',
  start_to: '',
  end_from: '',
  destination: '',
  employee_id: '',
});

const hasFilters = computed(() => Object.values(filters.value).some((v) => v));

function getInitials(name) {
  return (name || '')
    .split(' ')
    .map((w) => w[0])
    .join('')
    .toUpperCase()
    .substring(0, 2);
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

function formatDateTime(d) {
  if (!d) return '';
  return new Date(d).toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function logColor(action) {
  if (action.includes('approved')) return 'bg-emerald-400';
  if (action.includes('rejected') || action.includes('removed')) return 'bg-red-400';
  if (action.includes('created')) return 'bg-blue-400';
  if (action.includes('archived') || action.includes('unarchived')) return 'bg-orange-400';
  if (action.includes('registered')) return 'bg-amber-400';
  return 'bg-slate-400';
}

function toggleExpand(id) {
  expandedIds.has(id) ? expandedIds.delete(id) : expandedIds.add(id);
}

function getSubTab(id) {
  return subTabs[id] || 'people';
}

function setSubTab(id, tab) {
  subTabs[id] = tab;
  if (tab === 'history' && !logsData[id]) fetchLogs(id);
}

function clearFilters() {
  filters.value = { start_from: '', start_to: '', end_from: '', destination: '', employee_id: '' };
  fetchData();
}

async function unarchiveDelegation(del) {
  const ok = await confirm({
    title: 'Unarchive Delegation',
    message: `Move "${del.name}" back to active delegations?`,
    confirmLabel: 'Unarchive',
    danger: false,
  });
  if (!ok) return;
  try {
    await api.post(`/api/delegation-archive/${del.id}/unarchive`);
    await fetchData();
  } catch (e) {
    console.error(e);
  }
}

async function fetchLogs(delId) {
  logsLoading[delId] = true;
  try {
    const res = await api.get(`/api/delegation-archive/${delId}/logs`);
    logsData[delId] = res.data;
  } catch (e) {
    console.error(e);
  } finally {
    logsLoading[delId] = false;
  }
}

// Every filter change refetches; only the latest may apply its rows or clear `loading`, so the list
// always matches the filters shown.
const archiveRequests = useLatestRequest();

async function fetchData() {
  const token = archiveRequests.begin();
  loading.value = true;
  try {
    const params = {};
    Object.entries(filters.value).forEach(([k, v]) => {
      if (v) params[k] = v;
    });

    const [archRes, destRes, usersRes] = await Promise.all([
      api.get('/api/delegation-archive', { params }),
      api.get('/api/delegation-archive/destinations'),
      // The archive filter also offers deactivated users (their past delegations stay).
      api.get('/api/users', { params: { include_deactivated: 1 } }),
    ]);
    if (!archiveRequests.isCurrent(token)) return;
    archived.value = archRes.data;
    destinations.value = destRes.data;
    allUsers.value = usersRes.data;
  } catch (e) {
    if (archiveRequests.isCurrent(token)) console.error(e);
  } finally {
    if (archiveRequests.isCurrent(token)) loading.value = false;
  }
}

onMounted(fetchData);
</script>
