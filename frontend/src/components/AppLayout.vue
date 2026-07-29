<template>
  <div class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-900 to-indigo-950">
    <!-- Top Navigation Bar -->
    <nav class="bg-white/5 backdrop-blur-xl border-b border-white/10 relative z-50">
      <div class="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
          <!-- Left: hamburger + logo -->
          <div class="flex items-center gap-3">
            <!-- Sidebar toggle (admin only) -->
            <button v-if="isAdmin" @click="sidebarOpen = !sidebarOpen"
              class="w-9 h-9 flex items-center justify-center rounded-xl hover:bg-white/10 transition-all text-slate-400 hover:text-white"
              title="Toggle sidebar">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
              </svg>
            </button>

            <router-link to="/dashboard" class="flex items-center gap-3 group">
              <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center shadow-lg shadow-indigo-500/20 group-hover:shadow-indigo-500/30 transition-all">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <span class="text-white font-bold text-lg tracking-tight">Shift Changer</span>
            </router-link>
          </div>

          <!-- Center: Top nav links (non-admin items) -->
          <div class="flex items-center gap-1">
            <router-link v-for="link in topLinks" :key="link.to" :to="link.to"
              :class="[
                'px-3 py-2 text-sm rounded-lg transition-all duration-200',
                $route.path === link.to
                  ? 'text-white bg-white/10 font-medium'
                  : 'text-slate-300 hover:text-white hover:bg-white/10'
              ]">
              {{ link.label }}
            </router-link>

            <!-- Alarm Bell -->
            <div class="relative ml-2">
              <button @click="alarmDropdownOpen = !alarmDropdownOpen"
                aria-label="Notifications"
                :aria-expanded="alarmDropdownOpen"
                aria-haspopup="true"
                class="relative w-9 h-9 flex items-center justify-center rounded-xl hover:bg-white/10 transition-all text-slate-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                </svg>
                <span v-if="unreadAlarmCount > 0"
                  class="absolute -top-0.5 -right-0.5 w-4.5 h-4.5 min-w-[18px] px-1 flex items-center justify-center text-[9px] font-bold text-white bg-red-500 rounded-full leading-none shadow-lg">
                  {{ unreadAlarmCount > 99 ? '99+' : unreadAlarmCount }}
                </span>
              </button>

              <!-- Alarm Dropdown -->
              <Transition
                enter-active-class="transition-all duration-200 ease-out"
                enter-from-class="opacity-0 scale-95 -translate-y-1"
                enter-to-class="opacity-100 scale-100 translate-y-0"
                leave-active-class="transition-all duration-150 ease-in"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 scale-95">
                <div v-if="alarmDropdownOpen" class="absolute right-0 top-12 w-96 bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl z-50 overflow-hidden">
                  <div class="px-4 py-3 border-b border-white/10 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-white">Notifications</h3>
                    <button v-if="unreadAlarmCount > 0" @click="markAllAlarmsRead"
                      class="text-[10px] text-indigo-400 hover:text-indigo-300 font-medium">Mark all read</button>
                  </div>
                  <div v-if="latestAlarms.length === 0" class="px-4 py-8 text-center">
                    <p class="text-slate-500 text-sm">No notifications yet</p>
                  </div>
                  <div v-else class="max-h-[400px] overflow-y-auto divide-y divide-white/5">
                    <router-link v-for="alarm in latestAlarms" :key="alarm.id"
                      :to="alarm.link_url || '/alarms'"
                      @click="markAlarmRead(alarm)"
                      :class="['block px-4 py-3 hover:bg-white/5 transition-all',
                        !alarm.read_at ? 'bg-indigo-500/5' : '']">
                      <div class="flex items-start gap-3">
                        <span :class="['w-8 h-8 rounded-lg flex items-center justify-center text-sm shrink-0',
                          getAlarmStyle(alarm.type)]">
                          {{ getAlarmIcon(alarm.type) }}
                        </span>
                        <div class="flex-1 min-w-0">
                          <p :class="['text-xs leading-snug', !alarm.read_at ? 'text-white font-medium' : 'text-slate-300']">
                            {{ alarm.short_message }}
                          </p>
                          <p v-if="alarm.details" class="text-[10px] text-slate-500 mt-0.5 line-clamp-1">{{ alarm.details }}</p>
                          <p class="text-[10px] text-slate-600 mt-1">{{ formatAlarmTime(alarm.created_at) }}</p>
                        </div>
                        <span v-if="!alarm.read_at" class="w-2 h-2 rounded-full bg-indigo-400 shrink-0 mt-1.5"></span>
                      </div>
                    </router-link>
                  </div>
                  <router-link to="/alarms" @click="alarmDropdownOpen = false"
                    class="block px-4 py-3 text-center text-xs font-medium text-indigo-400 hover:text-indigo-300 border-t border-white/10 hover:bg-white/5 transition-all">
                    See all alarms →
                  </router-link>
                </div>
              </Transition>
            </div>

            <!-- User Avatar Menu -->
            <div class="relative ml-3 pl-3 border-l border-white/10">
              <button @click="userMenuOpen = !userMenuOpen"
                :aria-label="`${currentUser?.name ?? 'User'} — account menu`"
                :aria-expanded="userMenuOpen"
                aria-haspopup="true"
                class="flex items-center gap-2 px-2 py-1.5 rounded-xl hover:bg-white/10 transition-all duration-200 group">
                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 flex items-center justify-center text-white font-bold text-[11px] shadow-lg shadow-indigo-500/20 ring-2 ring-white/10 group-hover:ring-indigo-500/40 transition-all">
                  {{ userInitials }}
                </div>
                <svg class="w-3.5 h-3.5 text-slate-400 transition-transform" :class="userMenuOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
              </button>

              <!-- Dropdown -->
              <Transition
                enter-active-class="transition-all duration-200 ease-out"
                enter-from-class="opacity-0 scale-95 -translate-y-1"
                enter-to-class="opacity-100 scale-100 translate-y-0"
                leave-active-class="transition-all duration-150 ease-in"
                leave-from-class="opacity-100 scale-100 translate-y-0"
                leave-to-class="opacity-0 scale-95 -translate-y-1">
                <div v-if="userMenuOpen" class="absolute right-0 top-full mt-2 w-56 bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-xl shadow-2xl shadow-black/40 py-1 z-50">
                  <!-- User header -->
                  <div class="px-4 py-3 border-b border-white/10">
                    <p class="text-sm font-semibold text-white truncate">{{ currentUser?.name }}</p>
                    <p class="text-xs text-slate-400 truncate">{{ currentUser?.email }}</p>
                  </div>

                  <!-- Menu Items -->
                  <div class="py-1">
                    <router-link to="/my-shifts" @click="userMenuOpen = false"
                      :class="['flex items-center gap-3 px-4 py-2.5 text-sm transition-all',
                        $route.path === '/my-shifts'
                          ? 'text-indigo-300 bg-indigo-500/10'
                          : 'text-slate-300 hover:text-white hover:bg-white/5']">
                      <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                      </svg>
                      My Shifts
                    </router-link>

                    <router-link to="/my-availability" @click="userMenuOpen = false"
                      :class="['flex items-center gap-3 px-4 py-2.5 text-sm transition-all',
                        $route.path === '/my-availability'
                          ? 'text-indigo-300 bg-indigo-500/10'
                          : 'text-slate-300 hover:text-white hover:bg-white/5']">
                      <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      Availability
                    </router-link>
                  </div>

                  <!-- Change Password -->
                  <div class="border-t border-white/10 py-1">
                    <button @click="userMenuOpen = false; showPasswordModal = true"
                      class="flex items-center gap-3 w-full px-4 py-2.5 text-sm text-slate-300 hover:text-white hover:bg-white/5 transition-all">
                      <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                      </svg>
                      Change Password
                    </button>
                  </div>

                  <!-- Logout -->
                  <div class="border-t border-white/10 py-1">
                    <button @click="logout"
                      class="flex items-center gap-3 w-full px-4 py-2.5 text-sm text-red-400 hover:text-red-300 hover:bg-red-500/5 transition-all">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                      </svg>
                      Logout
                    </button>
                  </div>
                </div>
              </Transition>
            </div>
          </div>
        </div>
      </div>
    </nav>

    <!-- Click-away overlay for user menu -->
    <div v-if="userMenuOpen" class="fixed inset-0 z-40" @click="userMenuOpen = false"></div>

    <!-- Sidebar overlay (mobile-friendly) -->
    <Transition enter-active-class="transition-opacity duration-200" leave-active-class="transition-opacity duration-150"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-black/30 lg:hidden" @click="sidebarOpen = false"></div>
    </Transition>

    <!-- Left Sidebar -->
    <Transition enter-active-class="transition-transform duration-200 ease-out" leave-active-class="transition-transform duration-150 ease-in"
      enter-from-class="-translate-x-full" enter-to-class="translate-x-0" leave-from-class="translate-x-0" leave-to-class="-translate-x-full">
      <aside v-if="sidebarOpen && isAdmin"
        class="fixed left-0 top-16 bottom-0 w-56 bg-slate-900/95 backdrop-blur-xl border-r border-white/10 z-40 flex flex-col overflow-y-auto">
        <div class="p-3 space-y-0.5">
          <p class="px-3 pt-2 pb-1 text-[9px] text-slate-600 uppercase tracking-wider font-semibold">Administration</p>

          <router-link v-for="item in sidebarLinks" :key="item.to" :to="item.to" @click="sidebarOpen = false"
            :class="['flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-all duration-200',
              $route.path === item.to
                ? 'text-white bg-indigo-500/15 border border-indigo-500/20 font-medium'
                : 'text-slate-400 hover:text-white hover:bg-white/5 border border-transparent']">
            <span class="w-5 h-5 flex items-center justify-center text-slate-500" v-html="item.icon"></span>
            {{ item.label }}
          </router-link>
        </div>
      </aside>
    </Transition>

    <div :class="['transition-all duration-200', sidebarOpen && (isAdmin || canManageSchedule) ? 'lg:ml-56' : '']">
      <slot />
    </div>

    <!-- Change Password Modal -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0">
      <div v-if="showPasswordModal" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="closePasswordModal">
        <div ref="passwordModalRef"
          role="dialog"
          aria-modal="true"
          aria-labelledby="password-modal-title"
          class="bg-slate-800/95 border border-white/10 rounded-2xl shadow-2xl w-full max-w-sm mx-4">
          <div class="p-6 pb-3 border-b border-white/10">
            <h3 id="password-modal-title" class="text-lg font-bold text-white flex items-center gap-2">
              <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
              </svg>
              Change Password
            </h3>
          </div>
          <form @submit.prevent="changePassword" class="p-6 space-y-4">
            <div>
              <label class="block text-sm font-medium text-slate-300 mb-1.5">Current password <span class="text-red-400 ml-0.5">*</span></label>
              <input v-model="pw.current" type="password" required
                class="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 placeholder-slate-500"
                placeholder="Enter current password" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-300 mb-1.5">New password <span class="text-red-400 ml-0.5">*</span></label>
              <input v-model="pw.password" type="password" required minlength="8"
                class="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 placeholder-slate-500"
                placeholder="Min 8 characters" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-300 mb-1.5">Confirm new password <span class="text-red-400 ml-0.5">*</span></label>
              <input v-model="pw.confirm" type="password" required
                class="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 placeholder-slate-500"
                placeholder="Repeat new password" />
            </div>
            <p v-if="pwError" class="text-xs text-red-400">{{ pwError }}</p>
            <p v-if="pwSuccess" class="text-xs text-emerald-400">{{ pwSuccess }}</p>
            <div class="flex items-center justify-end gap-3 pt-2">
              <button type="button" @click="closePasswordModal"
                class="px-5 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">Cancel</button>
              <button type="submit" :disabled="pwSaving"
                class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
                <svg v-if="pwSaving" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                Save
              </button>
            </div>
          </form>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import { currentUser } from '../router/index.js';
import api from '../axios.js';
import { useFocusTrap } from '../composables/useFocusTrap.js';

const router = useRouter();
const userMenuOpen = ref(false);
const sidebarOpen = ref(false);
const showPasswordModal = ref(false);
const pwSaving = ref(false);
const pwError = ref('');
const pwSuccess = ref('');
const pw = ref({ current: '', password: '', confirm: '' });

const isAdmin = computed(() => currentUser.value?.is_admin || currentUser.value?.role === 'admin');
const canManageSchedule = computed(
  () => isAdmin.value || currentUser.value?.role === 'schedule_maintainer',
);

// Focus trap for the Change Password modal
const passwordModalRef = ref(null);
useFocusTrap(passwordModalRef, showPasswordModal, { onEscape: closePasswordModal });

// Alarm state
const alarmDropdownOpen = ref(false);
const latestAlarms = ref([]);
const unreadAlarmCount = ref(0);
let alarmPollTimer = null;

async function fetchAlarms() {
  try {
    const [latestRes, countRes] = await Promise.all([
      api.get('/api/alarms/latest'),
      api.get('/api/alarms/unread-count'),
    ]);
    latestAlarms.value = latestRes.data;
    unreadAlarmCount.value = countRes.data.count;
  } catch {}
}

async function markAlarmRead(alarm) {
  if (!alarm.read_at) {
    try {
      await api.put(`/api/alarms/${alarm.id}/read`);
      alarm.read_at = new Date().toISOString();
      unreadAlarmCount.value = Math.max(0, unreadAlarmCount.value - 1);
    } catch {}
  }
  alarmDropdownOpen.value = false;
}

async function markAllAlarmsRead() {
  try {
    await api.put('/api/alarms/read-all');
    latestAlarms.value.forEach((a) => {
      a.read_at = a.read_at || new Date().toISOString();
    });
    unreadAlarmCount.value = 0;
  } catch {}
}

function getAlarmIcon(type) {
  const icons = {
    EMPLOYEE_CANNOT_ATTEND_SHIFT: '📅',
    PROCEDURE_NEW_ISSUE_REPORTED: '⚠️',
    PROCEDURE_NEW_QUESTION_ADDED: '❓',
    PROCEDURE_QUESTION_REPLIED: '💬',
    PROCEDURE_ISSUE_SORTED: '✅',
  };
  return icons[type] || '🔔';
}

function getAlarmStyle(type) {
  const styles = {
    EMPLOYEE_CANNOT_ATTEND_SHIFT: 'bg-orange-500/20 text-orange-300',
    PROCEDURE_NEW_ISSUE_REPORTED: 'bg-red-500/20 text-red-300',
    PROCEDURE_NEW_QUESTION_ADDED: 'bg-blue-500/20 text-blue-300',
    PROCEDURE_QUESTION_REPLIED: 'bg-emerald-500/20 text-emerald-300',
    PROCEDURE_ISSUE_SORTED: 'bg-green-500/20 text-green-300',
  };
  return styles[type] || 'bg-slate-500/20 text-slate-300';
}

function formatAlarmTime(isoStr) {
  if (!isoStr) return '';
  const d = new Date(isoStr);
  const now = new Date();
  const diffMs = now - d;
  const mins = Math.floor(diffMs / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

const userInitials = computed(() => {
  const name = currentUser.value?.name || '';
  return name
    .split(' ')
    .map((w) => w[0])
    .join('')
    .toUpperCase()
    .substring(0, 2);
});

// Top navbar links (visible to all users)
const topLinks = computed(() => {
  const links = [
    { to: '/dashboard', label: 'Dashboard' },
    { to: '/schedule', label: 'Schedule' },
    { to: '/checklist/fill', label: 'Fill Checklist' },
    { to: '/procedures', label: 'Procedures' },
    { to: '/procedures-to-learn', label: 'To Learn' },
    { to: '/delegations', label: 'Delegations' },
  ];
  return links.filter((l) => !l.adminOnly || isAdmin.value);
});

// Sidebar links (admin only, with SVG icons)
const sidebarLinks = [
  {
    to: '/users',
    label: 'Users',
    icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
  },
  {
    to: '/checklists',
    label: 'Templates',
    icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>',
  },
  {
    to: '/checklist/history',
    label: 'Checklists Archive',
    icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>',
  },
  {
    to: '/office-availability',
    label: 'Office',
    icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>',
  },
  {
    to: '/procedure-tags',
    label: 'Procedure Tags',
    icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>',
  },
  {
    to: '/delegations-manage',
    label: 'Delegations',
    icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
  },
  {
    to: '/delegation-archive',
    label: 'Delegation Archive',
    icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>',
  },
  {
    to: '/shift-settings',
    label: 'Shift Settings',
    icon: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
  },
];

async function logout() {
  userMenuOpen.value = false;
  try {
    await api.post('/api/logout');
  } catch {}
  router.push({ name: 'login' });
}

function closePasswordModal() {
  showPasswordModal.value = false;
  pw.value = { current: '', password: '', confirm: '' };
  pwError.value = '';
  pwSuccess.value = '';
}

async function changePassword() {
  pwError.value = '';
  pwSuccess.value = '';

  if (pw.value.password !== pw.value.confirm) {
    pwError.value = 'New passwords do not match.';
    return;
  }
  if (pw.value.password.length < 8) {
    pwError.value = 'Password must be at least 8 characters.';
    return;
  }

  pwSaving.value = true;
  try {
    const res = await api.put('/api/change-my-password', {
      current_password: pw.value.current,
      password: pw.value.password,
      password_confirmation: pw.value.confirm,
    });
    pwSuccess.value = res.data.message;
    pw.value = { current: '', password: '', confirm: '' };
    setTimeout(() => closePasswordModal(), 1500);
  } catch (err) {
    pwError.value = err.response?.data?.message || 'Failed to change password.';
  } finally {
    pwSaving.value = false;
  }
}

onMounted(() => {
  fetchAlarms();
  alarmPollTimer = setInterval(fetchAlarms, 60000);
});

onUnmounted(() => {
  if (alarmPollTimer) clearInterval(alarmPollTimer);
});
</script>
