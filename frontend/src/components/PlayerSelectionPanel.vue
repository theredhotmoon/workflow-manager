<template>
  <div class="space-y-3">
    <!-- Section title -->
    <div class="flex items-center gap-1.5">
      <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
      </svg>
      <span class="text-sm font-semibold text-slate-300">Players</span>
      <span class="text-[10px] text-slate-500 tabular-nums ml-auto">{{ summaryLabel }}</span>
    </div>

    <!-- Mode selector -->
    <div class="flex items-center gap-1.5">
      <button v-for="opt in modeOptions" :key="opt.value"
        @click="selectionMode = opt.value"
        :class="[
          'px-2.5 py-1.5 text-[11px] font-medium rounded-lg border transition-all',
          selectionMode === opt.value
            ? 'bg-violet-500/20 border-violet-500/40 text-violet-300 shadow-sm shadow-violet-500/10'
            : 'bg-white/5 border-white/10 text-slate-400 hover:bg-white/10 hover:text-slate-300'
        ]">
        {{ opt.label }}
      </button>
    </div>

    <!-- Group filter -->
    <div v-if="selectionMode !== 'all' && groups.length > 0" class="flex items-center gap-1.5 flex-wrap">
      <span class="text-[10px] text-slate-500 uppercase tracking-wide shrink-0">Group:</span>
      <button
        @click="selectedGroupId = null"
        :class="[
          'px-2 py-1 text-[10px] font-medium rounded-md border transition-all',
          selectedGroupId === null
            ? 'bg-sky-500/20 border-sky-500/30 text-sky-300'
            : 'bg-white/5 border-white/10 text-slate-400 hover:bg-white/10 hover:text-slate-300'
        ]">
        All
      </button>
      <button v-for="group in groups" :key="group.id"
        @click="selectedGroupId = selectedGroupId === group.id ? null : group.id"
        :class="[
          'px-2 py-1 text-[10px] font-mono rounded-md border transition-all',
          selectedGroupId === group.id
            ? 'bg-sky-500/20 border-sky-500/30 text-sky-300'
            : 'bg-white/5 border-white/10 text-slate-400 hover:bg-sky-500/10 hover:border-sky-500/20 hover:text-sky-300'
        ]">
        {{ group.enum }}
      </button>
    </div>

    <!-- Quick filters -->
    <div v-if="selectionMode !== 'all'" class="flex items-center gap-2 flex-wrap">
      <span class="text-[10px] text-slate-500 uppercase tracking-wide">Quick:</span>
      <button @click="toggleNewbies"
        :class="[
          'flex items-center gap-1 px-2 py-1 text-[10px] font-medium rounded-md border transition-all',
          newbieIds.length === 0
            ? 'opacity-40 cursor-not-allowed bg-white/5 border-white/10 text-slate-500'
            : allNewbiesSelected
              ? 'bg-yellow-500/20 border-yellow-500/30 text-yellow-300'
              : 'bg-white/5 border-white/10 text-slate-400 hover:bg-yellow-500/10 hover:border-yellow-500/20 hover:text-yellow-300'
        ]"
        :disabled="newbieIds.length === 0"
        :title="newbieIds.length === 0 ? 'No newbies this month' : ''">
        ⭐ Newbies ({{ newbieIds.length }})
      </button>
      <button @click="toggleDelegated"
        :class="[
          'flex items-center gap-1 px-2 py-1 text-[10px] font-medium rounded-md border transition-all',
          delegatedIds.length === 0
            ? 'opacity-40 cursor-not-allowed bg-white/5 border-white/10 text-slate-500'
            : allDelegatedSelected
              ? 'bg-purple-500/20 border-purple-500/30 text-purple-300'
              : 'bg-white/5 border-white/10 text-slate-400 hover:bg-purple-500/10 hover:border-purple-500/20 hover:text-purple-300'
        ]"
        :disabled="delegatedIds.length === 0"
        :title="delegatedIds.length === 0 ? 'No one on delegation this month' : ''">
        📍 On Delegation ({{ delegatedIds.length }})
      </button>
      <button @click="selectAll" class="px-2 py-1 text-[10px] font-medium rounded-md border bg-white/5 border-white/10 text-slate-400 hover:bg-white/10 hover:text-slate-300 transition-all">
        Select All
      </button>
      <button @click="selectNone" class="px-2 py-1 text-[10px] font-medium rounded-md border bg-white/5 border-white/10 text-slate-400 hover:bg-white/10 hover:text-slate-300 transition-all">
        Clear
      </button>
    </div>

    <!-- Search + list -->
    <div v-if="selectionMode !== 'all'">
      <div class="relative mb-2">
        <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <input v-model="search" type="text" placeholder="Search employees..."
          class="w-full pl-8 pr-3 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white text-xs focus:outline-none focus:ring-2 focus:ring-violet-500/50 placeholder-slate-500" />
      </div>

      <div class="max-h-[280px] overflow-y-auto space-y-0.5 rounded-lg border border-white/10 bg-white/[0.02] p-1 custom-scrollbar">
        <label v-for="emp in filteredEmployees" :key="emp.id"
          class="flex items-center gap-2 px-2 py-1.5 rounded-md hover:bg-white/5 cursor-pointer transition-colors group">
          <input type="checkbox"
            :checked="selectedIds.has(emp.id)"
            @change="toggleUser(emp.id)"
            class="w-3.5 h-3.5 rounded border-white/20 bg-white/5 text-violet-500 focus:ring-violet-500/30 cursor-pointer" />
          <span class="flex-1 text-xs text-slate-300 group-hover:text-white transition-colors truncate">{{ emp.name }}</span>
          <span v-if="isNewbie(emp.id)" class="text-yellow-400 text-[10px]" title="Newbie">⭐</span>
          <span v-if="isDelegated(emp.id)" class="text-purple-400 text-[10px]" title="On delegation">📍</span>
        </label>
        <div v-if="filteredEmployees.length === 0" class="text-center text-[10px] text-slate-500 py-3 italic">
          {{ search || selectedGroupId !== null ? 'No matches' : 'Loading...' }}
        </div>
      </div>

      <!-- Counter -->
      <div class="flex items-center justify-between mt-1.5">
        <span class="text-[10px] text-slate-500 tabular-nums">
          {{ selectedIds.size }} of {{ allEmployees.length }} selected
          <template v-if="selectedGroupId !== null">
            (showing {{ filteredEmployees.length }} in group)
          </template>
        </span>
        <span v-if="selectionMode === 'include' && selectedIds.size === 0"
          class="text-[10px] text-amber-400 flex items-center gap-1">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
          </svg>
          No one selected
        </span>
      </div>
    </div>

    <!-- "All" mode hint -->
    <div v-if="selectionMode === 'all'" class="px-3 py-2.5 bg-white/[0.03] border border-white/5 rounded-lg">
      <p class="text-[10px] text-slate-500 leading-snug">All non-admin employees will be included in auto-fill. Switch to "Only selected" or "Exclude" for fine-grained control.</p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import api from '../axios.js';

const props = defineProps({
  monthKey: { type: String, required: true },
  modelValue: {
    type: Object,
    default: () => ({ mode: 'all', userIds: [] }),
  },
});

const emit = defineEmits(['update:modelValue']);

// UI state
const search = ref('');
const selectionMode = ref('all');
const selectedIds = ref(new Set());
const selectedGroupId = ref(null);

// Data
const allEmployees = ref([]);
const newbieIds = ref([]);
const delegatedIds = ref([]);
const groups = ref([]);

const modeOptions = [
  { value: 'all', label: 'All players' },
  { value: 'include', label: 'Only selected' },
  { value: 'exclude', label: 'Exclude selected' },
];

// Computed
const activeGroupMemberIds = computed(() => {
  if (selectedGroupId.value === null) return null;
  const group = groups.value.find((g) => g.id === selectedGroupId.value);
  return new Set(group?.users?.map((u) => u.id) ?? []);
});

const filteredEmployees = computed(() => {
  let list = allEmployees.value;
  if (activeGroupMemberIds.value !== null) {
    list = list.filter((e) => activeGroupMemberIds.value.has(e.id));
  }
  const q = search.value.toLowerCase().trim();
  if (!q) return list;
  return list.filter((e) => e.name.toLowerCase().includes(q));
});

const allNewbiesSelected = computed(
  () => newbieIds.value.length > 0 && newbieIds.value.every((id) => selectedIds.value.has(id)),
);

const allDelegatedSelected = computed(
  () =>
    delegatedIds.value.length > 0 && delegatedIds.value.every((id) => selectedIds.value.has(id)),
);

const summaryLabel = computed(() => {
  if (selectionMode.value === 'all') return 'All players';
  const count = selectedIds.value.size;
  return selectionMode.value === 'include' ? `${count} included` : `${count} excluded`;
});

// Methods
function isNewbie(userId) {
  return newbieIds.value.includes(userId);
}

function isDelegated(userId) {
  return delegatedIds.value.includes(userId);
}

function toggleUser(userId) {
  const next = new Set(selectedIds.value);
  if (next.has(userId)) {
    next.delete(userId);
  } else {
    next.add(userId);
  }
  selectedIds.value = next;
}

function toggleNewbies() {
  if (newbieIds.value.length === 0) return;
  const next = new Set(selectedIds.value);
  if (allNewbiesSelected.value) {
    newbieIds.value.forEach((id) => next.delete(id));
  } else {
    newbieIds.value.forEach((id) => next.add(id));
  }
  selectedIds.value = next;
}

function toggleDelegated() {
  if (delegatedIds.value.length === 0) return;
  const next = new Set(selectedIds.value);
  if (allDelegatedSelected.value) {
    delegatedIds.value.forEach((id) => next.delete(id));
  } else {
    delegatedIds.value.forEach((id) => next.add(id));
  }
  selectedIds.value = next;
}

function selectAll() {
  const next = new Set(selectedIds.value);
  filteredEmployees.value.forEach((e) => next.add(e.id));
  selectedIds.value = next;
}

function selectNone() {
  const next = new Set(selectedIds.value);
  filteredEmployees.value.forEach((e) => next.delete(e.id));
  selectedIds.value = next;
}

// Emit model updates
watch(
  [selectionMode, selectedIds],
  () => {
    emit('update:modelValue', {
      mode: selectionMode.value,
      userIds: Array.from(selectedIds.value),
    });
  },
  { deep: true },
);

// Reset group filter when switching to 'all' mode
watch(selectionMode, (mode) => {
  if (mode === 'all') selectedGroupId.value = null;
});

// Fetch data
async function fetchData() {
  try {
    const [empRes, newbieRes, delegRes, groupRes] = await Promise.all([
      api.get('/api/employees'),
      api.get('/api/newbie-periods/month', { params: { month: props.monthKey } }),
      api.get('/api/delegations/month', { params: { month: props.monthKey } }),
      api.get('/api/user-groups').catch(() => ({ data: [] })),
    ]);

    allEmployees.value = empRes.data.filter((e) => !e.is_admin);
    selectedIds.value = new Set(allEmployees.value.map((e) => e.id));

    const nbIds = new Set();
    (newbieRes.data || []).forEach((np) => nbIds.add(np.user_id));
    newbieIds.value = Array.from(nbIds);

    const delIds = new Set();
    (delegRes.data || []).forEach((del) => {
      (del.users || []).forEach((u) => delIds.add(u.id));
    });
    delegatedIds.value = Array.from(delIds);

    groups.value = groupRes.data;
  } catch (err) {
    console.error('PlayerSelectionPanel: fetch error', err);
  }
}

watch(
  () => props.monthKey,
  () => {
    fetchData();
  },
);

onMounted(() => {
  fetchData();
});
</script>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 9999px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: rgba(255, 255, 255, 0.2);
}
</style>
