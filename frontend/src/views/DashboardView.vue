<template>
  <AppLayout>
    <!-- Main Content -->
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <!-- Welcome Banner -->
      <div class="bg-gradient-to-r from-indigo-500/10 via-violet-500/10 to-purple-500/10 backdrop-blur-xl border border-white/10 rounded-2xl p-8 mb-8 relative overflow-hidden">
        <div class="absolute top-0 right-0 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl -mr-20 -mt-20"></div>
        <div class="relative">
          <h1 class="text-3xl font-bold text-white mb-2">
            Welcome back, <span class="bg-gradient-to-r from-indigo-400 to-violet-400 bg-clip-text text-transparent">{{ user?.name }}!</span>
          </h1>
          <p class="text-slate-400 text-lg">Here's your shift management overview for today.</p>
        </div>
      </div>

      <!-- Checklist Alerts — Unified Table -->
      <div v-if="allChecklistAlerts.length > 0" class="mb-8">
        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
          <div class="px-5 py-3 border-b border-white/10 flex items-center justify-between">
            <h3 class="text-sm font-bold text-white flex items-center gap-2">
              <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
              </svg>
              Unfulfilled Checklists
              <span class="ml-1 px-2 py-0.5 text-[10px] bg-amber-500/15 text-amber-400 rounded-full font-bold">{{ allChecklistAlerts.length }}</span>
            </h3>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead>
                <tr class="border-b border-white/10">
                  <th class="px-5 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-slate-400">Date</th>
                  <th class="px-5 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-slate-400">Shift</th>
                  <th class="px-5 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-slate-400">Checklist</th>
                  <th class="px-5 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-slate-400">Type</th>
                  <th class="px-5 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-slate-400">Status</th>
                  <th class="px-5 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-slate-400">Action</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="alert in allChecklistAlerts" :key="alert.shift_id + '-' + alert.checklist_template_id"
                  class="border-b border-white/5 hover:bg-white/[0.03] transition-colors">
                  <td class="px-5 py-3">
                    <span class="text-sm text-white font-medium">{{ formatAlertDate(alert.date) }}</span>
                  </td>
                  <td class="px-5 py-3">
                    <span class="text-sm text-slate-300">
                      #{{ alert.shift_number }}
                      <span v-if="alert.start_time" class="text-slate-500 text-xs ml-1">{{ alert.start_time }}–{{ alert.end_time }}</span>
                    </span>
                  </td>
                  <td class="px-5 py-3">
                    <span class="text-sm text-white font-medium">{{ alert.checklist_template_name }}</span>
                  </td>
                  <td class="px-5 py-3">
                    <span v-if="alert.is_required"
                      class="px-2 py-0.5 text-[9px] font-bold uppercase bg-amber-500/15 text-amber-400 border border-amber-500/20 rounded-full">Required</span>
                    <span v-else
                      class="px-2 py-0.5 text-[9px] font-bold uppercase bg-sky-500/15 text-sky-400 border border-sky-500/20 rounded-full">Optional</span>
                  </td>
                  <td class="px-5 py-3">
                    <span v-if="alert.minutes_remaining != null && alert.minutes_remaining > 0"
                      class="text-xs text-emerald-400 font-medium">{{ alert.minutes_remaining }} min left</span>
                    <span v-else-if="alert.minutes_remaining != null && alert.minutes_remaining <= 0"
                      class="text-xs text-red-400 font-bold">OVERDUE {{ Math.abs(alert.minutes_remaining) }} min</span>
                    <span v-else-if="alert._isOverdue"
                      class="text-xs text-red-400 font-bold">OVERDUE</span>
                    <span v-else class="text-xs text-slate-500">—</span>
                  </td>
                  <td class="px-5 py-3 text-right">
                    <router-link
                      :to="`/checklist/fill?shift_id=${alert.shift_id}&template_id=${alert.checklist_template_id}&date=${alert.date}&auto_start=1`"
                      class="px-3.5 py-1.5 text-xs font-semibold bg-indigo-500/15 hover:bg-indigo-500/25 text-indigo-300 border border-indigo-500/20 rounded-lg transition-all whitespace-nowrap">
                      Fill Now →
                    </router-link>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Crew + Today's Schedule Row -->
      <!-- Two columns only when there is a live crew to show next to the schedule. -->
      <div :class="['grid grid-cols-1 gap-6 mb-8 items-start', activeShift ? 'lg:grid-cols-2' : '']">
      <!-- Current Crew on Shift -->
      <div v-if="activeShift" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-emerald-500/20 flex items-center justify-center">
            <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
          </div>
          <div>
            <h2 class="text-lg font-semibold text-white flex items-center gap-2">
              Current Crew on Shift
              <span class="px-2 py-0.5 text-[10px] font-bold uppercase rounded-full bg-emerald-500/25 text-emerald-300 border border-emerald-500/30 animate-pulse">● LIVE</span>
            </h2>
            <p class="text-sm text-slate-400">
              Shift {{ activeShift.shift_number }} — {{ activeShift.start_time }}–{{ activeShift.end_time }}
              <span v-if="activeShift.is_overnight" class="text-xs">🌙 overnight</span>
            </p>
          </div>
        </div>
        <div v-if="activeShift.crew.length > 0" class="flex flex-wrap gap-3">
          <div v-for="member in activeShift.crew" :key="member.id"
            class="flex items-center gap-2.5 px-4 py-2.5 bg-emerald-500/10 border border-emerald-500/20 rounded-xl">
            <div class="w-8 h-8 rounded-full bg-emerald-500/25 flex items-center justify-center text-emerald-300 text-xs font-bold">
              {{ getInitials(member.name) }}
            </div>
            <div>
              <p class="text-sm font-medium text-white">{{ member.name }}</p>
              <p v-if="member.email" class="text-xs text-slate-400">{{ member.email }}</p>
            </div>
          </div>
        </div>
        <p v-else class="text-sm text-slate-500 italic">No one is assigned to this shift.</p>
      </div>

      <!-- Today's Schedule Preview -->
      <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
        <div class="flex items-center justify-between mb-4">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-indigo-500/20 flex items-center justify-center">
              <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
            </div>
            <div>
              <h2 class="text-lg font-semibold text-white">Today's Schedule</h2>
              <p class="text-slate-400 text-sm">{{ todayFormatted }}</p>
            </div>
          </div>
          <router-link to="/schedule"
            class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors flex items-center gap-1">
            Full Calendar
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
          </router-link>
        </div>

        <!-- Loading state -->
        <div v-if="todayShiftsLoading" class="flex items-center justify-center py-8">
          <svg class="animate-spin w-6 h-6 text-indigo-400" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
          </svg>
        </div>

        <!-- No shifts -->
        <div v-else-if="todayShifts.length === 0" class="text-center py-8">
          <p class="text-slate-500 text-sm italic">No shifts scheduled for today.</p>
        </div>

        <!-- Shifts Grid -->
        <div v-else class="space-y-3">
          <div v-for="shift in todayShifts" :key="shift.id"
            :class="['rounded-xl p-4 border transition-all', activeShift && activeShift.id === shift.id ? 'border-emerald-500/40' : 'border-white/10 bg-white/[0.02]']"
            :style="activeShift && activeShift.id === shift.id ? {
              backgroundImage: 'repeating-linear-gradient(135deg, transparent, transparent 4px, rgba(16, 185, 129, 0.08) 4px, rgba(16, 185, 129, 0.08) 8px)',
              boxShadow: 'inset 0 0 0 1px rgba(16, 185, 129, 0.15)'
            } : {}">
            <!-- Shift header -->
            <div class="flex items-center gap-2 mb-3 flex-wrap">
              <span :class="['text-sm font-bold uppercase tracking-wide', activeShift && activeShift.id === shift.id ? 'text-emerald-400' : 'text-slate-400']">
                Shift {{ shift.shift_number }}
              </span>
              <span v-if="activeShift && activeShift.id === shift.id"
                class="px-2 py-0.5 text-[9px] font-bold uppercase rounded bg-emerald-500/25 text-emerald-300 border border-emerald-500/30 animate-pulse tracking-wider">
                ● LIVE
              </span>
              <span v-if="shift.start_time"
                class="px-2 py-0.5 text-xs font-mono text-slate-400 bg-white/[0.05] border border-white/10 rounded">
                {{ shift.start_time }}–{{ shift.end_time }}
                <span v-if="shift.start_time && shift.end_time && shift.end_time < shift.start_time" class="text-[10px]">🌙</span>
              </span>
              <span v-for="sc in (shift.shift_checklists || [])" :key="sc.id"
                :class="[
                  'px-2 py-0.5 text-[9px] font-bold uppercase rounded tracking-wide',
                  sc.is_required
                    ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/25'
                    : 'bg-sky-500/15 text-sky-400 border border-sky-500/20'
                ]">
                {{ sc.is_required ? '☑' : '☐' }} {{ sc.checklist_template_name }}
              </span>
            </div>
            <!-- Assigned users -->
            <div v-if="shift.assignments.length > 0" class="flex flex-wrap gap-2">
              <div v-for="a in shift.assignments" :key="a.user_id"
                class="flex items-center gap-2 px-3 py-1.5 bg-indigo-500/15 border border-indigo-500/25 rounded-lg">
                <span class="w-6 h-6 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 flex items-center justify-center text-white font-bold text-[9px] shrink-0">
                  {{ getInitials(a.user_name) }}
                </span>
                <span class="text-sm text-indigo-200 font-medium">{{ a.user_name }}</span>
              </div>
            </div>
            <p v-else class="text-sm text-slate-500 italic">No one assigned</p>
          </div>
        </div>
      </div>
      </div>

      <!-- Quick Actions (admin only: there is nothing here for other roles) -->
      <div v-if="isAdmin(currentUser)" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
        <h2 class="text-lg font-semibold text-white mb-4">Quick Actions</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <router-link to="/schedule" class="flex items-center gap-3 px-5 py-4 bg-indigo-500/10 hover:bg-indigo-500/20 border border-indigo-500/20 rounded-xl transition-all duration-200 text-left group">
            <div class="w-10 h-10 rounded-xl bg-indigo-500/20 group-hover:bg-indigo-500/30 flex items-center justify-center transition-colors">
              <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
            </div>
            <div>
              <p class="text-white font-medium text-sm">Shift Schedule</p>
              <p class="text-slate-400 text-xs">Plan monthly work shifts</p>
            </div>
          </router-link>
          <router-link to="/users" class="flex items-center gap-3 px-5 py-4 bg-violet-500/10 hover:bg-violet-500/20 border border-violet-500/20 rounded-xl transition-all duration-200 text-left group">
            <div class="w-10 h-10 rounded-xl bg-violet-500/20 group-hover:bg-violet-500/30 flex items-center justify-center transition-colors">
              <svg class="w-5 h-5 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
            </div>
            <div>
              <p class="text-white font-medium text-sm">Manage Users</p>
              <p class="text-slate-400 text-xs">Add, edit, or remove users</p>
            </div>
          </router-link>
        </div>
      </div>
    </main>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import { localDateStr } from '../dates.js';
import { fetchCurrentUser, isLoggedOut } from '../fetchCurrentUser.js';
import { isAdmin } from '../roles.js';
import { currentUser } from '../router/index.js';

const router = useRouter();
const user = ref(null);
const currentShiftAlerts = ref([]);
const overdueAlerts = ref([]);
const activeShift = ref(null);
const todayShifts = ref([]);
const todayShiftsLoading = ref(true);

const todayFormatted = computed(() => {
  const d = new Date();
  return d.toLocaleDateString('en-US', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  });
});

const todayDateStr = computed(() => localDateStr(new Date()));

const todayMonthKey = computed(() => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
});

function getInitials(name) {
  if (!name) return '';
  return name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);
}

// Merge current + overdue alerts into one sorted list (newest date first)
const allChecklistAlerts = computed(() => {
  const current = (currentShiftAlerts.value || []).map((a) => ({ ...a, _isOverdue: false }));
  const overdue = (overdueAlerts.value || []).map((a) => ({ ...a, _isOverdue: true }));
  return [...current, ...overdue].sort((a, b) => b.date.localeCompare(a.date));
});

function formatAlertDate(dateStr) {
  const d = new Date(dateStr + 'T12:00:00');
  return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
}

onMounted(async () => {
  try {
    user.value = await fetchCurrentUser();
  } catch (error) {
    if (isLoggedOut(error)) {
      router.push({ name: 'login' });
      return;
    }
    // Throttled or failing API: not a logout. Fall back to the user the router guard resolved.
    user.value = currentUser.value;
  }

  // Fetch checklist alerts
  try {
    const alertsRes = await api.get('/api/my-checklist-alerts');
    currentShiftAlerts.value = alertsRes.data.current_shift_alerts || [];
    overdueAlerts.value = alertsRes.data.overdue_alerts || [];
  } catch {
    // silently fail
  }

  // Fetch current active shift crew
  try {
    const shiftRes = await api.get('/api/current-shift');
    activeShift.value = shiftRes.data.active_shift;
  } catch {
    // silently fail
  }

  // Fetch today's schedule
  try {
    const schedRes = await api.get('/api/schedule', { params: { month: todayMonthKey.value } });
    const dayData = schedRes.data.schedule[todayDateStr.value] || [];
    todayShifts.value = dayData;
  } catch {
    // silently fail
  } finally {
    todayShiftsLoading.value = false;
  }
});
</script>
