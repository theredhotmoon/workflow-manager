<template>
  <div>
    <!-- Month Navigator -->
    <div class="flex items-center justify-center gap-3 mb-6">
      <button @click="prevMonth" class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
      </button>
      <span class="text-white font-semibold text-lg min-w-[180px] text-center">{{ monthLabel }}</span>
      <button @click="nextMonth" class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
      </button>
    </div>

    <!-- Calendar -->
    <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden mb-6">
      <div class="grid grid-cols-7 border-b border-white/10">
        <div v-for="d in ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']" :key="d"
          class="px-2 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider">{{ d }}</div>
      </div>
      <div v-for="(week, wi) in calendarWeeks" :key="wi" class="grid grid-cols-7 border-b border-white/5 last:border-b-0">
        <div v-for="(day, di) in week" :key="di" :data-testid="'calendar-day-' + day.dateStr"
          :class="[
            'border-r border-white/5 last:border-r-0 min-h-[80px] p-2 transition-colors cursor-pointer',
            day.isCurrentMonth ? 'hover:bg-white/5' : 'bg-white/[0.02] opacity-40',
            day.isToday ? 'ring-1 ring-inset ring-indigo-500/30' : '',
          ]"
          @click="day.isCurrentMonth && openAddModal(day.dateStr)">
          <div class="text-xs font-medium mb-1" :class="day.isToday ? 'text-indigo-400' : 'text-slate-500'">{{ day.dayNumber }}</div>
          <div class="space-y-0.5">
            <div v-for="entry in getEntriesForDate(day.dateStr)" :key="entry.id"
              @click.stop="deleteEntry(entry)"
              :title="entryTitle(entry)"
              :class="[
                'px-1.5 py-0.5 text-[9px] font-medium rounded cursor-pointer transition-all',
                entry.type === 'unavailable'
                  ? 'bg-red-500/20 text-red-300 border border-red-500/25 hover:bg-red-500/30'
                  : 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/25 hover:bg-emerald-500/30'
              ]">
              {{ entry.type === 'unavailable' ? '🔴' : '🟢' }}
              {{ entry.shift_type ? entry.shift_type : (entry.start_time ? entry.start_time + '–' + entry.end_time : 'All day') }}
              <span v-if="entry.note" class="opacity-70">· {{ entry.note }}</span>
              <span v-if="entry.created_by_user_id !== entry.user_id && entry.created_by_name" class="opacity-70">· set by {{ entry.created_by_name }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Legend -->
    <div class="flex items-center gap-6 mb-6 text-sm text-slate-400">
      <div class="flex items-center gap-2">
        <span class="w-3 h-3 rounded bg-red-500/30 border border-red-500/40"></span> Unavailable
      </div>
      <div class="flex items-center gap-2">
        <span class="w-3 h-3 rounded bg-emerald-500/30 border border-emerald-500/40"></span> Preferred
      </div>
      <span class="text-slate-600">Click a day to add · Click an entry to remove</span>
    </div>

    <!-- Add Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center px-4" @click.self="showModal = false">
        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showModal = false"></div>
        <div class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 p-6">
          <h3 class="text-white font-bold text-lg mb-1">Add Availability</h3>
          <p class="text-slate-400 text-sm mb-5">
            {{ targetUserName ? targetUserName + ' · ' : '' }}{{ form.start_date }}{{ form.end_date !== form.start_date ? ' → ' + form.end_date : '' }}
          </p>

          <div class="flex gap-2 mb-4">
            <button @click="form.type = 'unavailable'"
              :class="['flex-1 py-2.5 rounded-xl text-sm font-semibold border transition-all', form.type === 'unavailable' ? 'bg-red-500/20 text-red-300 border-red-500/40' : 'bg-white/5 text-slate-400 border-white/10 hover:bg-white/10']">
              🔴 Unavailable
            </button>
            <button @click="form.type = 'preferred'"
              :class="['flex-1 py-2.5 rounded-xl text-sm font-semibold border transition-all', form.type === 'preferred' ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40' : 'bg-white/5 text-slate-400 border-white/10 hover:bg-white/10']">
              🟢 Preferred
            </button>
          </div>

          <div v-if="form.type === 'preferred'" class="mb-4">
            <label for="shift-type-input" class="block text-sm font-medium text-slate-300 mb-1.5">Shift type <span class="text-slate-500 font-normal">(optional)</span></label>
            <input id="shift-type-input" v-model="form.shift_type" type="text" list="shift-type-options" placeholder="e.g. night"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-600" />
            <datalist id="shift-type-options">
              <option value="day" />
              <option value="evening" />
              <option value="night" />
            </datalist>
          </div>

          <div class="mb-4">
            <label class="block text-sm font-medium text-slate-300 mb-1.5">End Date <span class="text-slate-500 font-normal">(for multi-day range)</span></label>
            <input v-model="form.end_date" type="date" :min="form.start_date"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
          </div>

          <div class="mb-4">
            <label class="flex items-center gap-2 text-sm text-slate-300 mb-2 cursor-pointer">
              <input type="checkbox" v-model="useHours" class="rounded bg-white/10 border-white/20 text-indigo-500 focus:ring-indigo-500/50" />
              Specific hours only
            </label>
            <div v-if="useHours" class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-xs text-slate-400 mb-1">From</label>
                <input v-model="form.start_time" type="time"
                  class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
              </div>
              <div>
                <label class="block text-xs text-slate-400 mb-1">To</label>
                <input v-model="form.end_time" type="time"
                  class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
              </div>
            </div>
          </div>

          <div class="mb-5">
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Note <span class="text-slate-500 font-normal">(optional)</span></label>
            <input v-model="form.note" type="text" placeholder="e.g. Doctor appointment"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-600" />
          </div>

          <div class="flex items-center justify-end gap-3">
            <button @click="showModal = false" class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">Cancel</button>
            <button @click="saveEntry" :disabled="saving"
              class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
              <svg v-if="saving" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
              Save
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <AppToast />
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import api from '../axios.js';
import { confirm } from '../composables/useConfirm.js';
import { useToast } from '../composables/useToast.js';
import AppToast from './AppToast.vue';

const props = defineProps({
  targetUserId: { type: Number, required: true },
  targetUserName: { type: String, default: null },
});

const currentYear = ref(new Date().getFullYear());
const currentMonth = ref(new Date().getMonth());
const entries = ref([]);
const showModal = ref(false);
const saving = ref(false);
const useHours = ref(false);
const { showToast } = useToast();

const form = ref({
  type: 'unavailable',
  shift_type: '',
  start_date: '',
  end_date: '',
  start_time: '06:00',
  end_time: '14:00',
  note: '',
});

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
  const last = new Date(currentYear.value, currentMonth.value + 1, 0);
  const today = localDateStr(new Date());
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

function getEntriesForDate(dateStr) {
  return entries.value.filter((e) => dateStr >= e.start_date && dateStr <= e.end_date);
}

function entryTitle(entry) {
  const kind = entry.type === 'unavailable' ? '🔴 Unavailable' : '🟢 Preferred';
  const shiftType = entry.shift_type ? ` (${entry.shift_type})` : '';
  const hours = entry.start_time ? ` (${entry.start_time}–${entry.end_time})` : ' (all day)';
  const note = entry.note ? `: ${entry.note}` : '';
  const setBy =
    entry.created_by_user_id !== entry.user_id && entry.created_by_name
      ? ` — set by ${entry.created_by_name}`
      : '';
  return `${kind}${shiftType}${note}${hours}${setBy} — click to remove`;
}

function localDateStr(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function openAddModal(dateStr) {
  form.value = {
    type: 'unavailable',
    shift_type: '',
    start_date: dateStr,
    end_date: dateStr,
    start_time: '06:00',
    end_time: '14:00',
    note: '',
  };
  useHours.value = false;
  showModal.value = true;
}

async function fetchEntries() {
  try {
    const res = await api.get(`/api/user-availabilities/${props.targetUserId}/month`, {
      params: { month: monthKey.value },
    });
    entries.value = res.data;
  } catch {
    showToast('Failed to load availability.', 'error');
  }
}

async function saveEntry() {
  saving.value = true;
  try {
    const payload = {
      user_id: props.targetUserId,
      type: form.value.type,
      shift_type:
        form.value.type === 'preferred' && form.value.shift_type ? form.value.shift_type : null,
      start_date: form.value.start_date,
      end_date: form.value.end_date,
      start_time: useHours.value ? form.value.start_time : null,
      end_time: useHours.value ? form.value.end_time : null,
      note: form.value.note || null,
    };
    const res = await api.post('/api/user-availabilities', payload);
    entries.value.push(res.data);
    showModal.value = false;
    showToast('Availability saved.');
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to save.', 'error');
  } finally {
    saving.value = false;
  }
}

async function deleteEntry(entry) {
  const ok = await confirm({
    title: 'Remove Entry',
    message: 'Remove this availability entry?',
    confirmLabel: 'Remove',
    danger: true,
  });
  if (!ok) return;
  try {
    await api.delete(`/api/user-availabilities/${entry.id}`);
    entries.value = entries.value.filter((e) => e.id !== entry.id);
    showToast('Entry removed.');
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to remove.', 'error');
  }
}

onMounted(fetchEntries);
watch(monthKey, fetchEntries);
watch(() => props.targetUserId, fetchEntries);
</script>
