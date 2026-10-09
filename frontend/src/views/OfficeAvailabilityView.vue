<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-white">Office Availability</h1>
          <p class="text-slate-400 text-sm mt-1">Mark dates when the office is closed or unavailable</p>
        </div>
      </div>

      <!-- Month Navigator -->
      <div class="flex items-center justify-center gap-3 mb-6">
        <button @click="prevMonth" aria-label="Previous month" class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
        </button>
        <span class="text-white font-semibold text-lg min-w-[180px] text-center">{{ monthLabel }}</span>
        <button @click="nextMonth" aria-label="Next month" class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
        </button>
      </div>

      <!-- Two Column Layout -->
      <div class="flex gap-6">
        <!-- Left: Calendar (66%) -->
        <div class="w-2/3 min-w-0">
          <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden mb-4">
            <div class="grid grid-cols-7 border-b border-white/10">
              <div v-for="d in ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']" :key="d"
                class="px-2 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider">{{ d }}</div>
            </div>
            <div v-for="(week, wi) in calendarWeeks" :key="wi" class="grid grid-cols-7 border-b border-white/5 last:border-b-0">
              <div v-for="(day, di) in week" :key="di"
                :class="[
                  'border-r border-white/5 last:border-r-0 min-h-[80px] p-2 transition-all duration-150 cursor-pointer',
                  day.isCurrentMonth ? 'hover:bg-white/5' : 'bg-white/[0.02] opacity-40',
                  day.isToday ? 'ring-1 ring-inset ring-indigo-500/30' : '',
                  getClosureForDate(day.dateStr) ? 'bg-orange-500/8' : '',
                  highlightDate === day.dateStr && getClosureForDate(day.dateStr) ? 'ring-2 ring-inset ring-orange-400/60 bg-orange-500/15' : '',
                ]"
                @click="day.isCurrentMonth && openModal(day.dateStr)"
                @mouseenter="getClosureForDate(day.dateStr) && (highlightDate = day.dateStr)"
                @mouseleave="highlightDate = null">
                <div class="text-xs font-medium mb-1" :class="day.isToday ? 'text-indigo-400' : 'text-slate-500'">{{ day.dayNumber }}</div>
                <div v-if="getClosureForDate(day.dateStr)" class="space-y-0.5">
                  <div class="px-1.5 py-0.5 text-[9px] font-medium rounded bg-orange-500/20 text-orange-300 border border-orange-500/25"
                    @click.stop="editClosure(getClosureForDate(day.dateStr))"
                    :title="getClosureForDate(day.dateStr).description || 'Office closed'">
                    🏢❌ {{ getClosureForDate(day.dateStr).title }}
                  </div>
                </div>
              </div>
            </div>
          </div>
          <!-- Legend -->
          <div class="flex items-center gap-6 text-sm text-slate-400">
            <div class="flex items-center gap-2">
              <span class="w-3 h-3 rounded bg-orange-500/30 border border-orange-500/40"></span> Office Closed
            </div>
            <span class="text-slate-600">Click a day to add closure · Click a closure to edit/delete</span>
          </div>
        </div>

        <!-- Right: Closures this month (33%) -->
        <div class="w-1/3">
          <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-4 sticky top-4">
            <h3 class="text-sm font-semibold text-slate-300 mb-3 flex items-center gap-2">
              <svg class="w-4 h-4 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
              Closures this month
              <span v-if="closures.length" class="px-1.5 py-0.5 text-[10px] font-bold bg-orange-500/20 text-orange-300 border border-orange-500/25 rounded-full">{{ closures.length }}</span>
            </h3>
            <div v-if="closures.length" class="space-y-2">
              <div v-for="c in closures" :key="c.id"
                :class="['flex items-center justify-between p-3 border rounded-xl transition-all duration-150 cursor-pointer',
                  highlightDate === getDateStr(c) ? 'bg-orange-500/15 border-orange-500/40 ring-1 ring-orange-400/30' : 'bg-white/5 border-white/10 hover:border-orange-500/30']"
                @mouseenter="highlightDate = getDateStr(c)"
                @mouseleave="highlightDate = null"
                @click="editClosure(c)">
                <div class="flex items-center gap-3 min-w-0 flex-1">
                  <div class="w-9 h-9 rounded-lg bg-orange-500/15 flex items-center justify-center shrink-0 text-base">🏢</div>
                  <div class="min-w-0">
                    <p class="text-sm font-medium text-white truncate">{{ c.title }}</p>
                    <p class="text-xs text-slate-400">{{ formatDate(c.date) }}</p>
                    <p v-if="c.description" class="text-[10px] text-slate-500 mt-0.5 truncate">{{ c.description }}</p>
                  </div>
                </div>
                <div class="flex items-center gap-0.5 shrink-0 ml-2">
                  <button @click="editClosure(c)" title="Edit"
                    class="p-1.5 text-slate-400 hover:text-orange-400 hover:bg-orange-500/10 rounded-lg transition-all">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                  </button>
                  <button @click="deleteClosure(c)" title="Delete"
                    class="p-1.5 text-slate-400 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition-all">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              </div>
            </div>
            <p v-else class="text-center text-slate-500 text-sm py-6">No closures this month.</p>
          </div>
        </div>
      </div>

      <!-- Add/Edit Modal -->
      <Transition
        enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
        enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <BaseModal v-if="showModal"
          overlay-class="fixed inset-0 z-50 flex items-center justify-center px-4"
          backdrop-class="fixed inset-0 bg-black/50 backdrop-blur-sm"
          panel-class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 p-6"
          @close="showModal = false"
          v-slot="{ titleId }">
          <h3 :id="titleId" class="text-white font-bold text-lg mb-1">{{ editingId ? 'Edit Closure' : 'Add Office Closure' }}</h3>
          <p class="text-slate-400 text-sm mb-5">{{ form.date }}{{ form.end_date && form.end_date !== form.date ? ' → ' + form.end_date : '' }}</p>

          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-slate-300 mb-1.5">Title <span class="text-red-400 ml-0.5">*</span></label>
              <input v-model="form.title" type="text" required placeholder="e.g. Christmas Holiday"
                class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/50 placeholder-slate-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-300 mb-1.5">Description <span class="text-slate-500 font-normal">(optional)</span></label>
              <textarea v-model="form.description" rows="3" placeholder="e.g. Office closed for the holiday season"
                class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/50 placeholder-slate-500 resize-none"></textarea>
            </div>
            <div v-if="!editingId">
              <label class="block text-sm font-medium text-slate-300 mb-1.5">End Date <span class="text-slate-500 font-normal">(for multi-day closure)</span></label>
              <input v-model="form.end_date" type="date" :min="form.date"
                class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/50" />
            </div>
          </div>

          <div class="flex items-center justify-end gap-3 mt-5">
            <button @click="showModal = false" class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">Cancel</button>
            <button @click="saveClosure" :disabled="saving || !form.title.trim()"
              class="px-5 py-2 bg-gradient-to-r from-orange-500 to-amber-600 hover:from-orange-600 hover:to-amber-700 text-white font-semibold rounded-xl shadow-lg shadow-orange-500/25 transition-all text-sm disabled:opacity-50 flex items-center gap-2">
              <svg v-if="saving" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
              {{ editingId ? 'Update' : 'Save' }}
            </button>
          </div>
        </BaseModal>
      </Transition>

      <AppToast />
    </main>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import AppToast from '../components/AppToast.vue';
import BaseModal from '../components/BaseModal.vue';
import { confirm } from '../composables/useConfirm.js';
import { useLatestRequest } from '../composables/useLatestRequest.js';
import { useToast } from '../composables/useToast.js';
import { localDateStr, todayStr } from '../dates.js';

const currentYear = ref(new Date().getFullYear());
const currentMonth = ref(new Date().getMonth());
const closures = ref([]);
const showModal = ref(false);
const saving = ref(false);
const editingId = ref(null);
const highlightDate = ref(null);

const form = ref({ date: '', end_date: '', title: '', description: '' });

const monthKey = computed(
  () => `${currentYear.value}-${String(currentMonth.value + 1).padStart(2, '0')}`,
);
const monthLabel = computed(() => {
  const d = new Date(currentYear.value, currentMonth.value, 1);
  return d.toLocaleString('en-US', { month: 'long', year: 'numeric' });
});

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

const calendarWeeks = computed(() => {
  const first = new Date(currentYear.value, currentMonth.value, 1);
  const today = todayStr();
  let startDay = first.getDay() === 0 ? 6 : first.getDay() - 1;
  const weeks = [];
  let week = [];
  const startDate = new Date(first);
  startDate.setDate(startDate.getDate() - startDay);
  for (let i = 0; i < 42; i++) {
    const d = new Date(startDate);
    d.setDate(startDate.getDate() + i);
    const dateStr = localDateStr(d);
    week.push({
      dateStr,
      dayNumber: d.getDate(),
      isCurrentMonth: d.getMonth() === currentMonth.value && d.getFullYear() === currentYear.value,
      isToday: dateStr === today,
    });
    if (week.length === 7) {
      weeks.push(week);
      week = [];
    }
  }
  return weeks.filter((w) => w.some((d) => d.isCurrentMonth));
});

function getClosureForDate(dateStr) {
  return closures.value.find((c) => c.date === dateStr || c.date?.substring(0, 10) === dateStr);
}

function getDateStr(c) {
  return c.date?.substring?.(0, 10) || c.date;
}

function formatDate(dateStr) {
  const d = new Date((dateStr?.substring?.(0, 10) || dateStr) + 'T12:00:00');
  return d.toLocaleDateString('en-US', {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
    year: 'numeric',
  });
}

function openModal(dateStr) {
  const existing = getClosureForDate(dateStr);
  if (existing) {
    editClosure(existing);
  } else {
    editingId.value = null;
    form.value = { date: dateStr, end_date: dateStr, title: '', description: '' };
    showModal.value = true;
  }
}

function editClosure(c) {
  editingId.value = c.id;
  form.value = {
    date: c.date?.substring?.(0, 10) || c.date,
    end_date: c.date?.substring?.(0, 10) || c.date,
    title: c.title,
    description: c.description || '',
  };
  showModal.value = true;
}

// Month steps and the refetch after a save/delete overlap; only the latest may apply, so an older
// month's closures never show under the current month's label.
const closureRequests = useLatestRequest();

async function fetchClosures() {
  const token = closureRequests.begin();
  try {
    const res = await api.get('/api/office-closures', { params: { month: monthKey.value } });
    if (!closureRequests.isCurrent(token)) return;
    closures.value = res.data;
  } catch {
    if (closureRequests.isCurrent(token)) closures.value = [];
  }
}

async function saveClosure() {
  saving.value = true;
  const trimmedTitle = form.value.title.trim();
  if (!trimmedTitle) {
    saving.value = false;
    return;
  }
  try {
    if (editingId.value) {
      await api.put(`/api/office-closures/${editingId.value}`, {
        title: trimmedTitle,
        description: form.value.description?.trim() || null,
      });
      showToast('Closure updated.');
    } else {
      await api.post('/api/office-closures', {
        start_date: form.value.date,
        end_date: form.value.end_date || form.value.date,
        title: trimmedTitle,
        description: form.value.description?.trim() || null,
      });
      const days = daysBetween(form.value.date, form.value.end_date || form.value.date);
      showToast(`Office closure added for ${days} day${days > 1 ? 's' : ''}.`);
    }
    showModal.value = false;
    await fetchClosures();
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to save.', 'error');
  } finally {
    saving.value = false;
  }
}

async function deleteClosure(c) {
  const ok = await confirm({
    title: 'Remove Closure',
    message: `Remove the office closure "${c.title}"?`,
    confirmLabel: 'Remove',
    danger: true,
  });
  if (!ok) return;
  try {
    await api.delete(`/api/office-closures/${c.id}`);
    showToast('Closure removed.');
    await fetchClosures();
  } catch {
    showToast('Failed to remove.', 'error');
  }
}

const { showToast } = useToast();

function daysBetween(start, end) {
  const s = new Date(start + 'T12:00:00');
  const e = new Date(end + 'T12:00:00');
  return Math.round((e - s) / 86400000) + 1;
}

watch(monthKey, fetchClosures);
onMounted(fetchClosures);
</script>
