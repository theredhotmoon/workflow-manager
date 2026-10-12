<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 py-8">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
        <div>
          <h1 class="text-2xl font-bold text-white">Manage Delegations</h1>
          <p class="text-slate-400 text-sm mt-1">{{ delegations.length }} delegation{{ delegations.length !== 1 ? 's' : '' }}</p>
        </div>
        <button @click="openCreateModal"
          class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl text-sm shadow-lg shadow-blue-500/25 hover:shadow-blue-500/40 transition-all whitespace-nowrap">
          + New Delegation
        </button>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="text-center py-16"><p class="text-slate-400">Loading delegations…</p></div>

      <!-- Delegation List -->
      <div v-else class="space-y-4">
        <div v-for="del in delegations" :key="del.id"
          class="bg-white/5 border border-white/10 rounded-2xl overflow-hidden">
          <!-- Card Header -->
          <div class="p-5 cursor-pointer" @click="toggleExpand(del.id)">
            <div class="flex items-start justify-between gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-1 flex-wrap">
                  <h3 class="text-white font-semibold text-base truncate">{{ del.name }}</h3>
                  <span :class="['px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-full border',
                    del.registration_open
                      ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20'
                      : 'bg-red-500/15 text-red-400 border-red-500/20']">
                    {{ del.registration_open ? 'Open' : 'Closed' }}
                  </span>
                  <!-- Pending count badge -->
                  <span v-if="pendingCount(del) > 0"
                    class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-full bg-amber-500/15 text-amber-400 border border-amber-500/20">
                    {{ pendingCount(del) }} pending
                  </span>
                </div>
                <div class="flex items-center gap-4 text-xs text-slate-400">
                  <span class="flex items-center gap-1">📍 {{ del.destination }}</span>
                  <span>{{ formatDate(del.start_datetime) }} → {{ formatDate(del.end_datetime) }}</span>
                  <span class="text-slate-500">{{ approvedCount(del) }} approved · {{ del.users?.length || 0 }} total</span>
                </div>
                <p v-if="del.description" class="text-xs text-slate-500 mt-1.5 line-clamp-1">{{ del.description }}</p>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <button @click.stop="editDelegation(del)"
                  class="p-2 text-slate-500 hover:text-indigo-400 hover:bg-white/5 rounded-lg transition-all" title="Edit">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </button>
                <button @click.stop="toggleRegistration(del)" :disabled="busy.has(`toggle-${del.id}`)"
                  :class="['p-2 rounded-lg transition-all disabled:opacity-40', del.registration_open ? 'text-emerald-400 hover:bg-emerald-500/10' : 'text-red-400 hover:bg-red-500/10']"
                  :title="del.registration_open ? 'Close registration' : 'Open registration'">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </button>
                <!-- Only a delegation nobody has joined can be deleted; one with members is archived. -->
                <button @click.stop="deleteDelegation(del)" :disabled="busy.has(`delete-${del.id}`) || approvedCount(del) > 0"
                  class="p-2 disabled:opacity-40 disabled:cursor-not-allowed text-slate-500 hover:text-red-400 hover:bg-red-500/5 rounded-lg transition-all"
                  :title="approvedCount(del) > 0 ? HAS_MEMBERS_HINT : 'Delete'" :aria-label="approvedCount(del) > 0 ? HAS_MEMBERS_HINT : 'Delete'">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
                <button @click.stop="archiveDelegation(del)" :disabled="busy.has(`archive-${del.id}`)"
                  class="p-2 disabled:opacity-40 text-slate-500 hover:text-orange-400 hover:bg-orange-500/5 rounded-lg transition-all" title="Move to archive">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                </button>
                <svg :class="['w-4 h-4 text-slate-500 transition-transform', expandedIds.has(del.id) ? 'rotate-180' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
              </div>
            </div>
          </div>

          <!-- Expanded Content -->
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

              <!-- Add Person -->
              <div class="flex items-center gap-2 mb-4">
                <select v-model="addUserForm[del.id]"
                  class="flex-1 px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
                  <option value="" disabled>Select employee to add…</option>
                  <option v-for="u in availableUsers(del)" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <button @click="addPerson(del)" :disabled="!addUserForm[del.id] || busy.has(`add-${del.id}`)"
                  class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-xl text-sm transition-all disabled:opacity-40 disabled:cursor-not-allowed whitespace-nowrap">
                  + Add
                </button>
              </div>

              <!-- People Table -->
              <div v-if="del.users?.length" class="bg-white/5 border border-white/10 rounded-xl overflow-hidden">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="border-b border-white/10 text-[10px] text-slate-500 uppercase tracking-wider">
                      <th class="text-left px-4 py-2.5 font-semibold">Name</th>
                      <th class="text-left px-4 py-2.5 font-semibold">Status</th>
                      <th class="text-left px-4 py-2.5 font-semibold">Custom Start</th>
                      <th class="text-left px-4 py-2.5 font-semibold">Custom End</th>
                      <th class="text-center px-4 py-2.5 font-semibold w-40">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="user in del.users" :key="user.id"
                      :class="['border-b border-white/5 last:border-b-0',
                        user.pivot?.status === 'pending' ? 'bg-amber-500/5' : '',
                        user.pivot?.status === 'rejected' ? 'bg-red-500/5 opacity-60' : '']">
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
                      <td class="px-4 py-2.5">
                        <input type="date" :value="user.pivot?.custom_start || ''" :disabled="isUserBusy(del, user)" :aria-label="`Custom start for ${user.name}`"
                          :min="del.start_datetime?.substring(0, 10)" :max="user.pivot?.custom_end || del.end_datetime?.substring(0, 10)"
                          @change="updateDates(del, user, 'custom_start', $event.target.value)"
                          class="px-2 py-1 bg-white/5 border border-white/10 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500/50 w-32" />
                      </td>
                      <td class="px-4 py-2.5">
                        <input type="date" :value="user.pivot?.custom_end || ''" :disabled="isUserBusy(del, user)" :aria-label="`Custom end for ${user.name}`"
                          :min="user.pivot?.custom_start || del.start_datetime?.substring(0, 10)" :max="del.end_datetime?.substring(0, 10)"
                          @change="updateDates(del, user, 'custom_end', $event.target.value)"
                          class="px-2 py-1 bg-white/5 border border-white/10 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500/50 w-32" />
                      </td>
                      <td class="px-4 py-2.5 text-center">
                        <div class="flex items-center justify-center gap-1">
                          <button v-if="user.pivot?.status === 'pending'" @click="approveUser(del, user)" :disabled="isUserBusy(del, user)"
                            class="disabled:opacity-40 px-2 py-1 text-[10px] font-bold uppercase bg-emerald-500/15 text-emerald-400 border border-emerald-500/20 rounded-lg hover:bg-emerald-500/25 transition-all" title="Approve">
                            ✓ Approve
                          </button>
                          <button v-if="user.pivot?.status === 'pending'" @click="rejectUser(del, user)" :disabled="isUserBusy(del, user)"
                            class="disabled:opacity-40 px-2 py-1 text-[10px] font-bold uppercase bg-red-500/15 text-red-400 border border-red-500/20 rounded-lg hover:bg-red-500/25 transition-all" title="Reject">
                            ✕ Reject
                          </button>
                          <button v-if="user.pivot?.status === 'rejected'" @click="approveUser(del, user)" :disabled="isUserBusy(del, user)"
                            class="disabled:opacity-40 px-2 py-1 text-[10px] font-bold uppercase bg-emerald-500/15 text-emerald-400 border border-emerald-500/20 rounded-lg hover:bg-emerald-500/25 transition-all" title="Approve">
                            ✓ Approve
                          </button>
                          <button @click="removePerson(del, user)" :disabled="isUserBusy(del, user)"
                            class="disabled:opacity-40 p-1 text-slate-500 hover:text-red-400 hover:bg-red-500/10 rounded transition-all" title="Remove">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                          </button>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p v-else class="text-xs text-slate-500 text-center py-4">No people assigned yet.</p>
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

        <!-- Empty -->
        <div v-if="!delegations.length" class="text-center py-16">
          <p class="text-slate-500 text-sm mb-3">No delegations yet.</p>
          <button @click="openCreateModal" class="text-indigo-400 hover:text-indigo-300 text-sm font-medium">Create your first delegation →</button>
        </div>
      </div>

      <!-- Create / Edit Modal -->
      <Transition
        enter-active-class="transition-all duration-300 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-all duration-200 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0">
        <BaseModal v-if="showModal"
          overlay-class="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 backdrop-blur-sm"
          panel-class="bg-slate-800/95 border border-white/10 rounded-2xl shadow-2xl w-full max-w-lg mx-4"
          :busy="saving"
          @close="showModal = false"
          v-slot="{ titleId }">
          <div class="p-6 pb-3 border-b border-white/10">
            <h3 :id="titleId" class="text-lg font-bold text-white">{{ editingId ? 'Edit Delegation' : 'New Delegation' }}</h3>
          </div>
          <div class="p-6">
            <DelegationForm
              :initial="modalInitial"
              :saving="saving"
              :server-errors="modalFieldErrors"
              :submit-label="editingId ? 'Update' : 'Create'"
              @submit="saveModal"
              @cancel="showModal = false" />
          </div>
        </BaseModal>
      </Transition>
    </main>
  </AppLayout>
</template>


<script setup>
import { onMounted, reactive, ref } from 'vue';
import { describeApiError } from '../apiErrors.js';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import BaseModal from '../components/BaseModal.vue';
import DelegationForm from '../components/DelegationForm.vue';
import { confirm } from '../composables/useConfirm.js';
import { useToast } from '../composables/useToast.js';
import { formatCalendarDate } from '../dates.js';
import { isDelegationCompleted } from '../delegationStatus.js';

const { showToast } = useToast();

const delegations = ref([]);
const allUsers = ref([]);
const loading = ref(true);
const saving = ref(false);
const showModal = ref(false);
const editingId = ref(null);
const modalInitial = ref({});
const modalFieldErrors = ref({});
const expandedIds = reactive(new Set());
const addUserForm = reactive({});
const subTabs = reactive({});
const logsData = reactive({});
const logsLoading = reactive({});
// Keys of the actions in flight ("user-3-12", "toggle-3"...): their buttons are disabled, and a
// second click while one runs does nothing.
const busy = reactive(new Set());

function getInitials(name) {
  return (name || '')
    .split(' ')
    .map((w) => w[0])
    .join('')
    .toUpperCase()
    .substring(0, 2);
}

// Delegation days are calendar dates: the same day whatever the browser's timezone.
const formatDate = formatCalendarDate;

function formatDateTime(d) {
  if (!d) return '';
  return new Date(d).toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function pendingCount(del) {
  return (del.users || []).filter((u) => u.pivot?.status === 'pending').length;
}

const HAS_MEMBERS_HINT = 'Can’t delete: this delegation has members. Archive it instead.';

function approvedCount(del) {
  return (del.users || []).filter((u) => u.pivot?.status === 'approved').length;
}

function logColor(action) {
  if (action.includes('approved')) return 'bg-emerald-400';
  if (action.includes('rejected')) return 'bg-red-400';
  if (action.includes('created')) return 'bg-blue-400';
  if (action.includes('removed')) return 'bg-red-400';
  if (action.includes('registered')) return 'bg-amber-400';
  return 'bg-slate-400';
}

function toggleExpand(id) {
  if (expandedIds.has(id)) {
    expandedIds.delete(id);
  } else {
    expandedIds.add(id);
    if (!subTabs[id]) subTabs[id] = 'people';
  }
}

function getSubTab(id) {
  return subTabs[id] || 'people';
}

function setSubTab(id, tab) {
  subTabs[id] = tab;
  if (tab === 'history' && !logsData[id]) fetchLogs(id);
}

function availableUsers(del) {
  const assignedIds = new Set((del.users || []).map((u) => u.id));
  return allUsers.value.filter((u) => !assignedIds.has(u.id));
}

function isUserBusy(del, user) {
  return busy.has(`user-${del.id}-${user.id}`);
}

/** Shows what the server said went wrong (nothing for 401/419: sessionExpiry handles those). */
function showError(err, fallback) {
  const e = describeApiError(err, fallback);
  if (!e.silent) showToast(e.message, 'error', 6000);
  return e;
}

/**
 * Runs one action under `key` (ignored while the same key is in flight), then reloads the list.
 * Returns true on success; on failure the server's message is shown.
 */
async function runAction(key, request, fallback, { delegationId } = {}) {
  if (busy.has(key)) return false;
  busy.add(key);
  try {
    await request();
  } catch (err) {
    showError(err, fallback);
    busy.delete(key);
    return false;
  }
  // Still busy until the list is reloaded: until then the old form (e.g. the person just added,
  // still selected) is on screen, and a second click would send the same request again.
  try {
    await fetchData();
  } finally {
    busy.delete(key);
  }
  if (delegationId) refreshLogs(delegationId);
  return true;
}

function openCreateModal() {
  editingId.value = null;
  modalInitial.value = { registration_open: true };
  modalFieldErrors.value = {};
  showModal.value = true;
}

function editDelegation(del) {
  editingId.value = del.id;
  modalInitial.value = { ...del };
  modalFieldErrors.value = {};
  showModal.value = true;
}

async function saveModal(payload) {
  if (saving.value) return;
  saving.value = true;
  modalFieldErrors.value = {};
  try {
    if (editingId.value) {
      await api.put(`/api/delegations/${editingId.value}`, payload);
    } else {
      await api.post('/api/delegations', payload);
    }
    showModal.value = false;
  } catch (err) {
    modalFieldErrors.value = showError(err, 'Could not save the delegation').fieldErrors;
    return;
  } finally {
    saving.value = false;
  }
  await fetchData();
}

function toggleRegistration(del) {
  return runAction(
    `toggle-${del.id}`,
    () => api.put(`/api/delegations/${del.id}`, { registration_open: !del.registration_open }),
    'Could not change registration',
    { delegationId: del.id },
  );
}

async function deleteDelegation(del) {
  const ok = await confirm({
    title: 'Delete Delegation',
    // Nobody has joined it (else the server answers 409 "Archive it instead", shown as a toast).
    message: `Delete delegation "${del.name}"? Its pending requests and log go with it. This cannot be undone.`,
    confirmLabel: 'Delete',
    danger: true,
  });
  if (!ok) return;
  await runAction(
    `delete-${del.id}`,
    () => api.delete(`/api/delegations/${del.id}`),
    'Could not delete the delegation',
  );
}

async function archiveDelegation(del) {
  const ok = await confirm({
    title: 'Archive Delegation',
    message: `Move delegation "${del.name}" to archive?`,
    confirmLabel: 'Archive',
    danger: false,
  });
  if (!ok) return;

  // Its last day counts: a delegation ending today is still under way.
  if (!isDelegationCompleted(del)) {
    const ok2 = await confirm({
      title: 'Delegation Still Active',
      message: 'This delegation has not ended yet. Archive it anyway?',
      confirmLabel: 'Archive Anyway',
      danger: true,
    });
    if (!ok2) return;
  }

  await runAction(
    `archive-${del.id}`,
    () => api.post(`/api/delegations/${del.id}/archive`),
    'Could not archive the delegation',
  );
}

async function addPerson(del) {
  const userId = addUserForm[del.id];
  if (!userId) return;
  const added = await runAction(
    `add-${del.id}`,
    () => api.post(`/api/delegations/${del.id}/add-user`, { user_id: userId }),
    'Could not add the person',
    { delegationId: del.id },
  );
  if (added) addUserForm[del.id] = '';
}

function removePerson(del, user) {
  return runAction(
    `user-${del.id}-${user.id}`,
    () => api.post(`/api/delegations/${del.id}/remove-user`, { user_id: user.id }),
    `Could not remove ${user.name}`,
    { delegationId: del.id },
  );
}

function approveUser(del, user) {
  return runAction(
    `user-${del.id}-${user.id}`,
    () => api.post(`/api/delegations/${del.id}/approve-user`, { user_id: user.id }),
    `Could not approve ${user.name}`,
    { delegationId: del.id },
  );
}

function rejectUser(del, user) {
  return runAction(
    `user-${del.id}-${user.id}`,
    () => api.post(`/api/delegations/${del.id}/reject-user`, { user_id: user.id }),
    `Could not reject ${user.name}`,
    { delegationId: del.id },
  );
}

async function updateDates(del, user, field, value) {
  const saved = await runAction(
    `user-${del.id}-${user.id}`,
    () =>
      api.put(`/api/delegations/${del.id}/user-dates`, {
        user_id: user.id,
        custom_start: field === 'custom_start' ? value || null : user.pivot?.custom_start || null,
        custom_end: field === 'custom_end' ? value || null : user.pivot?.custom_end || null,
      }),
    `Could not save the dates for ${user.name}`,
    { delegationId: del.id },
  );
  // Put the inputs back to what is saved.
  if (!saved) await fetchData();
}

async function fetchLogs(delId) {
  logsLoading[delId] = true;
  try {
    const res = await api.get(`/api/delegations/${delId}/logs`);
    logsData[delId] = res.data;
  } catch (err) {
    showError(err, 'Could not load the history');
  } finally {
    logsLoading[delId] = false;
  }
}

function refreshLogs(delId) {
  if (logsData[delId]) fetchLogs(delId);
}

async function fetchData() {
  loading.value = true;
  try {
    const [delRes, usersRes] = await Promise.all([
      api.get('/api/delegations'),
      api.get('/api/users'),
    ]);
    delegations.value = delRes.data;
    allUsers.value = usersRes.data;
  } catch (err) {
    showError(err, 'Could not load delegations');
  } finally {
    loading.value = false;
  }
}

onMounted(fetchData);
</script>
