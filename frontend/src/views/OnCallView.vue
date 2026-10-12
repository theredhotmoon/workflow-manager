<template>
  <AppLayout>
    <main class="max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">On-Call Duty</h1>
        <p class="text-slate-400 mt-1">Manage on-call assignments and log callbacks</p>
      </div>

      <div class="flex flex-wrap items-center gap-3 mb-6">
        <select v-model.number="selectedGroupId" @change="fetchAssignments" aria-label="Shift group"
          class="px-4 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
          <option v-for="group in groups" :key="group.id" :value="group.id">{{ group.name }}{{ group.archived_at ? ' (archived)' : '' }}</option>
        </select>

        <!-- Archived groups keep their on-call history: listed on request so past duties and
             callbacks can still be corrected. -->
        <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer select-none">
          <input v-model="includeArchived" type="checkbox" @change="onIncludeArchivedChange"
            class="w-4 h-4 rounded border-white/20 bg-white/5 text-indigo-500 focus:ring-indigo-500/50" />
          Include archived groups
        </label>

        <button aria-label="Previous month" @click="stepMonth(-1)"
          class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
        </button>
        <span class="text-white text-sm font-medium min-w-[140px] text-center">{{ monthLabel }}</span>
        <button aria-label="Next month" @click="stepMonth(1)"
          class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
        </button>

        <button v-if="!selectedGroupArchived" @click="openAddModal" class="ml-auto px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white text-sm font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all">
          + Add On-Call Period
        </button>
      </div>

      <p v-if="selectedGroupArchived" role="status" data-testid="archived-group-note"
        class="mb-4 p-3 text-sm text-slate-300 bg-slate-500/10 border border-slate-400/30 rounded-xl">
        <strong>Archived group.</strong> Its past on-call periods and callbacks can be corrected here; new on-call periods can't be added until the group is restored.
      </p>

      <div v-if="loading" class="p-10 text-center text-slate-400">Loading…</div>

      <div v-else class="space-y-3">
        <div v-for="assignment in assignments" :key="assignment.id"
          class="bg-white/5 border border-white/10 rounded-2xl p-4">
          <div class="flex items-center justify-between mb-3">
            <div>
              <p class="text-white font-semibold">{{ assignment.user.name }}</p>
              <p class="text-slate-400 text-sm">{{ formatRange(assignment.starts_at, assignment.ends_at) }}</p>
            </div>
            <div class="flex items-center gap-2">
              <button @click="openCallbackModal(assignment)"
                class="px-3 py-1.5 text-xs font-medium rounded-lg bg-amber-500/15 text-amber-300 border border-amber-500/20 hover:bg-amber-500/25 transition-all">
                + Log Callback
              </button>
              <button @click="removeAssignment(assignment)"
                class="w-7 h-7 flex items-center justify-center text-slate-500 hover:text-red-400 transition-colors" title="Remove">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
              </button>
            </div>
          </div>

          <div v-if="assignment.callbacks.length" class="space-y-1.5 pl-3 border-l-2 border-amber-500/20">
            <div v-for="callback in assignment.callbacks" :key="callback.id"
              class="flex items-center justify-between text-sm">
              <span class="text-amber-300">📞 {{ formatRange(callback.starts_at, callback.ends_at) }}</span>
              <button @click="removeCallback(callback)"
                class="text-slate-500 hover:text-red-400 text-xs transition-colors">Remove</button>
            </div>
          </div>
          <p v-else class="text-slate-600 text-sm pl-3">No callbacks logged.</p>
        </div>

        <div v-if="assignments.length === 0" class="p-10 text-center text-slate-500">
          No on-call periods for this group in {{ monthLabel }}.
        </div>
      </div>

      <!-- Add On-Call Period Modal -->
      <Transition
        enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
        enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <BaseModal v-if="showAddModal"
          overlay-class="fixed inset-0 z-50 flex items-center justify-center px-4"
          backdrop-class="fixed inset-0 bg-black/50 backdrop-blur-sm"
          panel-class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 p-6"
          :busy="savingAssignment"
          @close="showAddModal = false"
          v-slot="{ titleId }">
          <h3 :id="titleId" class="text-white font-bold text-lg mb-5">Add On-Call Period</h3>

          <div class="mb-4">
            <label for="oncall-add-employee" class="block text-sm font-medium text-slate-300 mb-1.5">Employee</label>
            <select id="oncall-add-employee" v-model.number="addForm.user_id"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
              <option v-for="u in employees" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </div>

          <div class="mb-4">
            <label for="oncall-add-starts" class="block text-sm font-medium text-slate-300 mb-1.5">Starts</label>
            <input id="oncall-add-starts" v-model="addForm.starts_at" type="datetime-local"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
          </div>

          <div class="mb-5">
            <label for="oncall-add-ends" class="block text-sm font-medium text-slate-300 mb-1.5">Ends</label>
            <input id="oncall-add-ends" v-model="addForm.ends_at" type="datetime-local"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
          </div>

          <div class="flex items-center justify-end gap-3">
            <button @click="showAddModal = false" class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">Cancel</button>
            <button @click="saveAssignment" :disabled="savingAssignment"
              class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed">
              Save
            </button>
          </div>
        </BaseModal>
      </Transition>

      <!-- Log Callback Modal -->
      <Transition
        enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
        enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <BaseModal v-if="showCallbackModal"
          overlay-class="fixed inset-0 z-50 flex items-center justify-center px-4"
          backdrop-class="fixed inset-0 bg-black/50 backdrop-blur-sm"
          panel-class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 p-6"
          :busy="savingCallback"
          @close="showCallbackModal = false"
          v-slot="{ titleId }">
          <h3 :id="titleId" class="text-white font-bold text-lg mb-1">Log Callback</h3>
          <p class="text-slate-400 text-sm mb-5">{{ callbackTarget?.user?.name }}</p>

          <div class="mb-4">
            <label for="oncall-callback-starts" class="block text-sm font-medium text-slate-300 mb-1.5">Starts</label>
            <input id="oncall-callback-starts" v-model="callbackForm.starts_at" type="datetime-local"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
          </div>

          <div class="mb-5">
            <label for="oncall-callback-ends" class="block text-sm font-medium text-slate-300 mb-1.5">Ends</label>
            <input id="oncall-callback-ends" v-model="callbackForm.ends_at" type="datetime-local"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
          </div>

          <div class="flex items-center justify-end gap-3">
            <button @click="showCallbackModal = false" class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">Cancel</button>
            <button @click="saveCallback" :disabled="savingCallback"
              class="px-5 py-2 bg-amber-600 hover:bg-amber-700 text-white font-semibold rounded-xl shadow-lg shadow-amber-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed">
              Save
            </button>
          </div>
        </BaseModal>
      </Transition>

      <AppToast />
    </main>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import AppToast from '../components/AppToast.vue';
import BaseModal from '../components/BaseModal.vue';
import { confirm } from '../composables/useConfirm.js';
import { useLatestRequest } from '../composables/useLatestRequest.js';
import { useToast } from '../composables/useToast.js';

const { showToast } = useToast();

const groups = ref([]);
const employees = ref([]);
const selectedGroupId = ref(null);
const currentYear = ref(new Date().getFullYear());
const currentMonth = ref(new Date().getMonth());
const assignments = ref([]);
const loading = ref(true);
const includeArchived = ref(false);
const selectedGroupArchived = computed(
  () => !!groups.value.find((g) => g.id === selectedGroupId.value)?.archived_at,
);

const monthKey = computed(
  () => `${currentYear.value}-${String(currentMonth.value + 1).padStart(2, '0')}`,
);
const monthLabel = computed(() => {
  const d = new Date(currentYear.value, currentMonth.value, 1);
  return d.toLocaleString('en-US', { month: 'long', year: 'numeric' });
});

function stepMonth(direction) {
  currentMonth.value += direction;
  if (currentMonth.value < 0) {
    currentMonth.value = 11;
    currentYear.value--;
  } else if (currentMonth.value > 11) {
    currentMonth.value = 0;
    currentYear.value++;
  }
  fetchAssignments();
}

function formatRange(startsAt, endsAt) {
  const fmt = (s) =>
    new Date(s).toLocaleString('en-US', {
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      timeZone: 'UTC',
    });
  return `${fmt(startsAt)} → ${fmt(endsAt)}`;
}

async function fetchGroups() {
  try {
    const res = await api.get(
      '/api/shift-groups',
      includeArchived.value ? { params: { include_archived: 1 } } : {},
    );
    groups.value = res.data;
    // Keep the chosen group while it's still listed; otherwise the default (or first) one.
    if (!groups.value.some((g) => g.id === selectedGroupId.value)) {
      const active = groups.value.filter((g) => !g.archived_at);
      selectedGroupId.value =
        active.find((g) => g.is_default)?.id ?? active[0]?.id ?? groups.value[0]?.id ?? null;
    }
  } catch {
    groups.value = [];
  }
}

async function onIncludeArchivedChange() {
  await fetchGroups();
  await fetchAssignments();
}

async function fetchEmployees() {
  try {
    const res = await api.get('/api/employees');
    employees.value = res.data;
  } catch {
    employees.value = [];
  }
}

// Group changes and month steps overlap fetches. Only the latest may apply its list or clear
// `loading` — otherwise an older response shows another month's or group's on-call periods under
// the current label. The early return also takes a token so it supersedes older requests.
const assignmentRequests = useLatestRequest();

async function fetchAssignments() {
  const token = assignmentRequests.begin();
  if (!selectedGroupId.value) {
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const res = await api.get('/api/on-call-assignments', {
      params: { shift_group_id: selectedGroupId.value, month: monthKey.value },
    });
    if (!assignmentRequests.isCurrent(token)) return;
    assignments.value = res.data;
  } catch {
    if (assignmentRequests.isCurrent(token)) assignments.value = [];
  } finally {
    if (assignmentRequests.isCurrent(token)) loading.value = false;
  }
}

const showAddModal = ref(false);
const savingAssignment = ref(false);
const addForm = ref({ user_id: null, starts_at: '', ends_at: '' });

function openAddModal() {
  addForm.value = { user_id: employees.value[0]?.id ?? null, starts_at: '', ends_at: '' };
  showAddModal.value = true;
}

async function saveAssignment() {
  if (savingAssignment.value) return;
  savingAssignment.value = true;
  try {
    await api.post('/api/on-call-assignments', {
      shift_group_id: selectedGroupId.value,
      user_id: addForm.value.user_id,
      starts_at: addForm.value.starts_at,
      ends_at: addForm.value.ends_at,
    });
    showAddModal.value = false;
    showToast('On-call period added.');
    await fetchAssignments();
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to add on-call period.', 'error');
  } finally {
    savingAssignment.value = false;
  }
}

async function removeAssignment(assignment) {
  if (
    !(await confirm({
      title: 'Remove On-Call Period',
      message: `Remove ${assignment.user.name}'s on-call period?`,
    }))
  )
    return;
  try {
    await api.delete(`/api/on-call-assignments/${assignment.id}`);
    showToast('On-call period removed.');
    await fetchAssignments();
  } catch {
    showToast('Failed to remove on-call period.', 'error');
  }
}

const showCallbackModal = ref(false);
const savingCallback = ref(false);
const callbackTarget = ref(null);
const callbackForm = ref({ starts_at: '', ends_at: '' });

function openCallbackModal(assignment) {
  callbackTarget.value = assignment;
  callbackForm.value = { starts_at: '', ends_at: '' };
  showCallbackModal.value = true;
}

async function saveCallback() {
  if (!callbackTarget.value || savingCallback.value) return;
  savingCallback.value = true;
  try {
    await api.post(`/api/on-call-assignments/${callbackTarget.value.id}/callbacks`, {
      starts_at: callbackForm.value.starts_at,
      ends_at: callbackForm.value.ends_at,
    });
    showCallbackModal.value = false;
    showToast('Callback logged.');
    await fetchAssignments();
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to log callback.', 'error');
  } finally {
    savingCallback.value = false;
  }
}

async function removeCallback(callback) {
  if (!(await confirm({ title: 'Remove Callback', message: 'Remove this callback?' }))) return;
  try {
    await api.delete(`/api/on-call-callbacks/${callback.id}`);
    showToast('Callback removed.');
    await fetchAssignments();
  } catch {
    showToast('Failed to remove callback.', 'error');
  }
}

onMounted(async () => {
  await fetchGroups();
  await fetchEmployees();
  await fetchAssignments();
});
</script>
