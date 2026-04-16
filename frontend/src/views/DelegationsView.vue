<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 py-8">
      <div class="flex items-center justify-between mb-8">
        <div>
          <h1 class="text-2xl font-bold text-white mb-1">Delegations</h1>
          <p class="text-slate-400 text-sm">Browse open delegations and view your history</p>
        </div>

        <!-- Tabs -->
        <div class="flex items-center gap-1 bg-white/5 border border-white/10 rounded-xl p-1">
          <button @click="tab = 'open'"
            :class="['px-4 py-2 text-sm font-medium rounded-lg transition-all',
              tab === 'open' ? 'bg-indigo-500/20 text-indigo-300' : 'text-slate-400 hover:text-white']">
            Open Delegations
          </button>
          <button @click="tab = 'my'"
            :class="['px-4 py-2 text-sm font-medium rounded-lg transition-all',
              tab === 'my' ? 'bg-indigo-500/20 text-indigo-300' : 'text-slate-400 hover:text-white']">
            My Delegations
            <span v-if="myDelegations.length" class="ml-1.5 px-1.5 py-0.5 text-[10px] bg-indigo-500/15 text-indigo-400 rounded-full">{{ myDelegations.length }}</span>
          </button>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="text-center py-16"><p class="text-slate-400">Loading…</p></div>

      <!-- Open Delegations Tab -->
      <div v-else-if="tab === 'open'">
        <div v-if="openDelegations.length" class="space-y-3">
          <div v-for="del in openDelegations" :key="del.id"
            class="bg-white/5 border border-white/10 rounded-2xl p-5 hover:border-indigo-500/20 transition-all">
            <div class="flex items-start justify-between gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-1">
                  <h3 class="text-white font-semibold text-base">{{ del.name }}</h3>
                  <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider bg-emerald-500/15 text-emerald-400 border border-emerald-500/20 rounded-full">
                    Open
                  </span>
                </div>
                <div class="flex items-center gap-4 text-xs text-slate-400 mb-2">
                  <span class="flex items-center gap-1">📍 {{ del.destination }}</span>
                  <span>{{ formatDate(del.start_datetime) }} → {{ formatDate(del.end_datetime) }}</span>
                  <span class="text-slate-500">{{ del.users?.length || 0 }} interested</span>
                </div>
                <p v-if="del.description" class="text-sm text-slate-400 leading-relaxed">{{ del.description }}</p>

                <!-- Who's going (approved only) -->
                <div v-if="approvedUsers(del).length" class="mt-3">
                  <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-1">Confirmed participants</p>
                  <div class="flex flex-wrap gap-1.5">
                    <span v-for="u in approvedUsers(del)" :key="u.id"
                      :class="['px-2 py-0.5 text-[10px] font-medium rounded-full border',
                        u.id === currentUserId
                          ? 'bg-indigo-500/20 text-indigo-300 border-indigo-500/30'
                          : 'bg-white/5 text-slate-400 border-white/10']">
                      {{ u.name }}
                    </span>
                  </div>
                </div>
              </div>
              <div class="shrink-0">
                <!-- My status for this delegation -->
                <div v-if="del.my_status === 'pending'" class="text-center">
                  <span class="block px-3 py-1.5 text-xs font-bold text-amber-400 bg-amber-500/15 border border-amber-500/20 rounded-xl mb-1.5">
                    ⏳ Awaiting approval
                  </span>
                  <button @click="withdraw(del)"
                    class="text-[10px] text-slate-500 hover:text-red-400 transition-all">
                    Withdraw request
                  </button>
                </div>
                <span v-else-if="del.my_status === 'approved'"
                  class="block px-3 py-1.5 text-xs font-bold text-emerald-400 bg-emerald-500/15 border border-emerald-500/20 rounded-xl">
                  ✓ Approved
                </span>
                <span v-else-if="del.my_status === 'rejected'"
                  class="block px-3 py-1.5 text-xs font-bold text-red-400 bg-red-500/15 border border-red-500/20 rounded-xl">
                  ✕ Rejected
                </span>
                <button v-else @click="requestJoin(del)"
                  class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-xl text-sm shadow-lg shadow-emerald-500/25 transition-all whitespace-nowrap">
                  🙋 I want to take part
                </button>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="text-center py-16">
          <p class="text-slate-500 text-sm">No open delegations at the moment.</p>
        </div>
      </div>

      <!-- My Delegations Tab -->
      <div v-else-if="tab === 'my'">
        <div v-if="myDelegations.length" class="space-y-3">
          <div v-for="del in myDelegations" :key="del.id"
            class="bg-white/5 border border-white/10 rounded-2xl p-5">
            <div class="flex items-start justify-between gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-1 flex-wrap">
                  <h3 class="text-white font-semibold">{{ del.name }}</h3>
                  <!-- Time status -->
                  <span :class="['px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-full border',
                    isPast(del) ? 'bg-slate-500/15 text-slate-400 border-slate-500/20'
                    : isActive(del) ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20'
                    : 'bg-indigo-500/15 text-indigo-400 border-indigo-500/20']">
                    {{ isPast(del) ? 'Completed' : isActive(del) ? 'Active' : 'Upcoming' }}
                  </span>
                  <!-- Approval status -->
                  <span :class="['px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-full border',
                    del.my_status === 'approved' ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20'
                    : del.my_status === 'pending' ? 'bg-amber-500/15 text-amber-400 border-amber-500/20'
                    : 'bg-red-500/15 text-red-400 border-red-500/20']">
                    {{ del.my_status === 'approved' ? '✓ Approved' : del.my_status === 'pending' ? '⏳ Pending' : '✕ Rejected' }}
                  </span>
                </div>
                <div class="flex items-center gap-4 text-xs text-slate-400">
                  <span class="flex items-center gap-1">📍 {{ del.destination }}</span>
                  <span>{{ formatDate(del.start_datetime) }} → {{ formatDate(del.end_datetime) }}</span>
                </div>
                <!-- Custom dates if different -->
                <div v-if="del.my_custom_start || del.my_custom_end" class="mt-1.5 text-xs text-indigo-300">
                  Your dates: {{ formatDate(del.my_custom_start || del.start_datetime) }} → {{ formatDate(del.my_custom_end || del.end_datetime) }}
                </div>
                <p v-if="del.description" class="text-sm text-slate-500 mt-2">{{ del.description }}</p>
              </div>
              <div class="text-right shrink-0">
                <p class="text-xs text-slate-500">Created by</p>
                <p class="text-sm text-slate-300">{{ del.creator?.name || '—' }}</p>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="text-center py-16">
          <p class="text-slate-500 text-sm mb-3">You haven't applied to any delegations yet.</p>
          <button @click="tab = 'open'" class="text-indigo-400 hover:text-indigo-300 text-sm font-medium">Browse open delegations →</button>
        </div>
      </div>
    </main>
  </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import { currentUser } from '../router/index.js';

const tab = ref('open');
const openDelegations = ref([]);
const myDelegations = ref([]);
const loading = ref(true);
const currentUserId = ref(null);

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function approvedUsers(del) {
  return (del.users || []).filter(u => u.pivot?.status === 'approved');
}

function isPast(del) {
  return new Date(del.end_datetime) < new Date();
}

function isActive(del) {
  const now = new Date();
  return new Date(del.start_datetime) <= now && new Date(del.end_datetime) >= now;
}

async function requestJoin(del) {
  try {
    await api.post(`/api/delegations/${del.id}/self-register`);
    await fetchData();
  } catch (e) { console.error(e); }
}

async function withdraw(del) {
  try {
    await api.delete(`/api/delegations/${del.id}/self-unregister`);
    await fetchData();
  } catch (e) { console.error(e); }
}

async function fetchData() {
  loading.value = true;
  try {
    const [openRes, myRes] = await Promise.all([
      api.get('/api/delegations/open'),
      api.get('/api/my-delegations'),
    ]);
    openDelegations.value = openRes.data;
    myDelegations.value = myRes.data;
    currentUserId.value = currentUser.value?.id;
  } catch (e) { console.error(e); }
  finally { loading.value = false; }
}

onMounted(fetchData);
</script>
