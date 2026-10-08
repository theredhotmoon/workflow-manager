<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Settlement Periods</h1>
        <p class="text-slate-400 mt-1">Review hours worked against each group's settlement-period cap</p>
      </div>

      <div class="flex items-center gap-3 mb-6">
        <select v-model.number="selectedGroupId" @change="fetchReport"
          class="px-4 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
          <option v-for="group in groups" :key="group.id" :value="group.id">{{ group.name }}</option>
        </select>

        <button aria-label="Previous period" @click="stepPeriod(-1)"
          class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
        </button>
        <span v-if="report" class="text-white text-sm"><span>{{ report.period.start }}</span> – <span>{{ report.period.end }}</span></span>
        <button aria-label="Next period" @click="stepPeriod(1)"
          class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
        </button>
      </div>

      <div v-if="loading" class="p-10 text-center text-slate-400">Loading…</div>

      <div v-else-if="!report" class="p-10 text-center text-slate-500">Unable to load the report — check the selected group's settlement configuration, or that you have access to it.</div>

      <div v-else-if="report" class="bg-white/5 border border-white/10 rounded-2xl overflow-x-auto">
        <table class="w-full text-sm text-left">
          <thead class="text-slate-400 border-b border-white/10">
            <tr>
              <th class="px-4 py-3">Employee</th>
              <th v-for="(week, i) in report.weeks" :key="i" class="px-4 py-3">
                Week {{ i + 1 }}<br /><span class="text-[10px] text-slate-500">{{ week.start }} – {{ week.end }}</span><br /><span class="text-[10px] text-slate-500">cap {{ week.cap_hours }}h</span>
              </th>
              <th class="px-4 py-3">Total</th>
              <th class="px-4 py-3">Streak</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="emp in report.employees" :key="emp.id" class="border-b border-white/5">
              <td class="px-4 py-3 text-white">{{ emp.name }}</td>
              <td v-for="(hours, i) in emp.weekly_hours" :key="i" class="px-4 py-3"
                :class="emp.over_cap_weeks.includes(i) ? 'text-red-400 font-semibold' : 'text-slate-300'">
                {{ hours }}<span v-if="emp.over_cap_weeks.includes(i)"> ⚠️</span>
              </td>
              <td class="px-4 py-3 text-white font-semibold">{{ emp.total_hours }}</td>
              <td class="px-4 py-3 text-slate-300 text-xs">{{ formatStreakStatus(emp.streak_status) }}</td>
            </tr>
            <tr v-if="report.employees.length === 0">
              <td :colspan="report.weeks.length + 3" class="px-4 py-6 text-center text-slate-500">No assignments in this period yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </main>
  </AppLayout>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import { useLatestRequest } from '../composables/useLatestRequest.js';

const groups = ref([]);
const selectedGroupId = ref(null);
const offset = ref(0);
const report = ref(null);
const loading = ref(true);

async function fetchGroups() {
  try {
    const res = await api.get('/api/shift-groups');
    groups.value = res.data;
    if (groups.value.length > 0) {
      selectedGroupId.value = groups.value.find((g) => g.is_default)?.id ?? groups.value[0].id;
    }
  } catch {
    groups.value = [];
  }
}

// Fetches overlap (the on-load fetch for the default group, a group change, period stepping).
// Only the latest one may apply its response — otherwise a slower, older response replaces the
// report the user is looking at with another group's or period's.
const reportRequests = useLatestRequest();

async function fetchReport() {
  const token = reportRequests.begin();
  if (!selectedGroupId.value) {
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const res = await api.get('/api/settlement-periods', {
      params: { shift_group_id: selectedGroupId.value, offset: offset.value },
    });
    if (!reportRequests.isCurrent(token)) return;
    report.value = res.data;
  } catch {
    if (reportRequests.isCurrent(token)) report.value = null;
  } finally {
    if (reportRequests.isCurrent(token)) loading.value = false;
  }
}

function stepPeriod(direction) {
  offset.value += direction;
  fetchReport();
}

function formatStreakStatus(streakStatus) {
  if (!streakStatus || streakStatus.length === 0) return '';
  return streakStatus
    .map((s) => {
      if (s.resting_until) return `${s.shift_type}: resting until ${s.resting_until}`;
      if (s.current_count > 0) return `${s.shift_type}: ${s.current_count}/${s.streak_length}`;
      return null;
    })
    .filter(Boolean)
    .join(', ');
}

onMounted(async () => {
  await fetchGroups();
  await fetchReport();
});
</script>
