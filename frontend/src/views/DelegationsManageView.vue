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
                <button @click.stop="toggleRegistration(del)"
                  :class="['p-2 rounded-lg transition-all', del.registration_open ? 'text-emerald-400 hover:bg-emerald-500/10' : 'text-red-400 hover:bg-red-500/10']"
                  :title="del.registration_open ? 'Close registration' : 'Open registration'">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </button>
                <button @click.stop="deleteDelegation(del)"
                  class="p-2 text-slate-500 hover:text-red-400 hover:bg-red-500/5 rounded-lg transition-all" title="Delete">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
                <button @click.stop="archiveDelegation(del)"
                  class="p-2 text-slate-500 hover:text-orange-400 hover:bg-orange-500/5 rounded-lg transition-all" title="Move to archive">
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
                <button @click="addPerson(del)" :disabled="!addUserForm[del.id]"
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
                        <input type="date" :value="user.pivot?.custom_start || ''"
                          @change="updateDates(del, user, 'custom_start', $event.target.value)"
                          class="px-2 py-1 bg-white/5 border border-white/10 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500/50 w-32" />
                      </td>
                      <td class="px-4 py-2.5">
                        <input type="date" :value="user.pivot?.custom_end || ''"
                          @change="updateDates(del, user, 'custom_end', $event.target.value)"
                          class="px-2 py-1 bg-white/5 border border-white/10 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500/50 w-32" />
                      </td>
                      <td class="px-4 py-2.5 text-center">
                        <div class="flex items-center justify-center gap-1">
                          <button v-if="user.pivot?.status === 'pending'" @click="approveUser(del, user)"
                            class="px-2 py-1 text-[10px] font-bold uppercase bg-emerald-500/15 text-emerald-400 border border-emerald-500/20 rounded-lg hover:bg-emerald-500/25 transition-all" title="Approve">
                            ✓ Approve
                          </button>
                          <button v-if="user.pivot?.status === 'pending'" @click="rejectUser(del, user)"
                            class="px-2 py-1 text-[10px] font-bold uppercase bg-red-500/15 text-red-400 border border-red-500/20 rounded-lg hover:bg-red-500/25 transition-all" title="Reject">
                            ✕ Reject
                          </button>
                          <button v-if="user.pivot?.status === 'rejected'" @click="approveUser(del, user)"
                            class="px-2 py-1 text-[10px] font-bold uppercase bg-emerald-500/15 text-emerald-400 border border-emerald-500/20 rounded-lg hover:bg-emerald-500/25 transition-all" title="Approve">
                            ✓ Approve
                          </button>
                          <button @click="removePerson(del, user)"
                            class="p-1 text-slate-500 hover:text-red-400 hover:bg-red-500/10 rounded transition-all" title="Remove">
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
        <div v-if="showModal" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showModal = false">
          <div class="bg-slate-800/95 border border-white/10 rounded-2xl shadow-2xl w-full max-w-lg mx-4">
            <div class="p-6 pb-3 border-b border-white/10">
              <h3 class="text-lg font-bold text-white">{{ editingId ? 'Edit Delegation' : 'New Delegation' }}</h3>
            </div>
            <form @submit.prevent="saveModal" class="p-6 space-y-4">
              <div>
                <label class="block text-sm font-medium text-slate-300 mb-1.5">Name <span class="text-red-400 ml-0.5">*</span></label>
                <input v-model="form.name" type="text" required
                  @blur="touched.name = true"
                  :class="['w-full px-3 py-2.5 bg-white/5 border rounded-xl text-white text-sm focus:outline-none focus:ring-2 placeholder-slate-500',
                    formErrors.name ? 'border-red-500/40 focus:ring-red-500/30' : 'border-white/10 focus:ring-indigo-500/50']"
                  placeholder="e.g. Warsaw Training" />
                <p v-if="formErrors.name" class="mt-1 text-xs text-red-400">{{ formErrors.name }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-300 mb-1.5">Destination <span class="text-red-400 ml-0.5">*</span></label>
                <input v-model="form.destination" type="text" required
                  @blur="touched.destination = true"
                  :class="['w-full px-3 py-2.5 bg-white/5 border rounded-xl text-white text-sm focus:outline-none focus:ring-2 placeholder-slate-500',
                    formErrors.destination ? 'border-red-500/40 focus:ring-red-500/30' : 'border-white/10 focus:ring-indigo-500/50']"
                  placeholder="e.g. Warsaw, Poland" />
                <p v-if="formErrors.destination" class="mt-1 text-xs text-red-400">{{ formErrors.destination }}</p>
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-sm font-medium text-slate-300 mb-1.5">Start Date <span class="text-red-400 ml-0.5">*</span></label>
                  <input v-model="form.start_datetime" type="date" required
                    @blur="touched.start_datetime = true"
                    :class="['w-full px-3 py-2.5 bg-white/5 border rounded-xl text-white text-sm focus:outline-none focus:ring-2',
                      formErrors.start_datetime ? 'border-red-500/40 focus:ring-red-500/30' : 'border-white/10 focus:ring-indigo-500/50']" />
                  <p v-if="formErrors.start_datetime" class="mt-1 text-xs text-red-400">{{ formErrors.start_datetime }}</p>
                </div>
                <div>
                  <label class="block text-sm font-medium text-slate-300 mb-1.5">End Date <span class="text-red-400 ml-0.5">*</span></label>
                  <input v-model="form.end_datetime" type="date" required
                    @blur="touched.end_datetime = true"
                    :class="['w-full px-3 py-2.5 bg-white/5 border rounded-xl text-white text-sm focus:outline-none focus:ring-2',
                      formErrors.end_datetime ? 'border-red-500/40 focus:ring-red-500/30' : 'border-white/10 focus:ring-indigo-500/50']" />
                  <p v-if="formErrors.end_datetime" class="mt-1 text-xs text-red-400">{{ formErrors.end_datetime }}</p>
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-300 mb-1.5">Description</label>
                <textarea v-model="form.description" rows="3"
                  class="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500 resize-none" placeholder="Optional details…"></textarea>
              </div>
              <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" v-model="form.registration_open" class="accent-emerald-500 w-4 h-4 rounded" />
                <span class="text-sm text-slate-300">Registration open (employees can request to join)</span>
              </label>
              <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" @click="showModal = false"
                  class="px-5 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">Cancel</button>
                <button type="submit" :disabled="saving"
                  class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50">
                  {{ saving ? 'Saving…' : (editingId ? 'Update' : 'Create') }}
                </button>
              </div>
            </form>
          </div>
        </div>
      </Transition>
    </main>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { confirm } from '../composables/useConfirm.js';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';

const delegations = ref([]);
const allUsers = ref([]);
const loading = ref(true);
const saving = ref(false);
const showModal = ref(false);
const editingId = ref(null);
const expandedIds = reactive(new Set());
const addUserForm = reactive({});
const subTabs = reactive({});
const logsData = reactive({});
const logsLoading = reactive({});

const form = ref({
  name: '', destination: '', description: '', start_datetime: '', end_datetime: '', registration_open: true,
});

const touched = reactive({ name: false, destination: false, start_datetime: false, end_datetime: false });

const formErrors = computed(() => ({
  name: touched.name && !form.value.name.trim()
    ? 'Name is required.' : '',
  destination: touched.destination && !form.value.destination.trim()
    ? 'Destination is required.' : '',
  start_datetime: touched.start_datetime && !form.value.start_datetime
    ? 'Start date is required.' : '',
  end_datetime: touched.end_datetime && !form.value.end_datetime
    ? 'End date is required.'
    : touched.end_datetime && form.value.start_datetime && form.value.end_datetime < form.value.start_datetime
      ? 'End date must be on or after start date.' : '',
}));

function getInitials(name) {
  return (name || '').split(' ').map(w => w[0]).join('').toUpperCase().substring(0, 2);
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatDateTime(d) {
  if (!d) return '';
  return new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function pendingCount(del) {
  return (del.users || []).filter(u => u.pivot?.status === 'pending').length;
}

function approvedCount(del) {
  return (del.users || []).filter(u => u.pivot?.status === 'approved').length;
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

function getSubTab(id) { return subTabs[id] || 'people'; }

function setSubTab(id, tab) {
  subTabs[id] = tab;
  if (tab === 'history' && !logsData[id]) fetchLogs(id);
}

function availableUsers(del) {
  const assignedIds = new Set((del.users || []).map(u => u.id));
  return allUsers.value.filter(u => !assignedIds.has(u.id));
}

function openCreateModal() {
  editingId.value = null;
  form.value = { name: '', destination: '', description: '', start_datetime: '', end_datetime: '', registration_open: true };
  Object.assign(touched, { name: false, destination: false, start_datetime: false, end_datetime: false });
  showModal.value = true;
}

function editDelegation(del) {
  editingId.value = del.id;
  form.value = {
    name: del.name,
    destination: del.destination,
    description: del.description || '',
    start_datetime: del.start_datetime?.substring(0, 10) || '',
    end_datetime: del.end_datetime?.substring(0, 10) || '',
    registration_open: del.registration_open,
  };
  Object.assign(touched, { name: false, destination: false, start_datetime: false, end_datetime: false });
  showModal.value = true;
}

async function saveModal() {
  saving.value = true;
  try {
    if (editingId.value) {
      await api.put(`/api/delegations/${editingId.value}`, form.value);
    } else {
      await api.post('/api/delegations', form.value);
    }
    showModal.value = false;
    await fetchData();
  } catch (e) { console.error(e); }
  finally { saving.value = false; }
}

async function toggleRegistration(del) {
  try {
    await api.put(`/api/delegations/${del.id}`, { registration_open: !del.registration_open });
    await fetchData();
  } catch (e) { console.error(e); }
}

async function deleteDelegation(del) {
  const ok = await confirm({ title: 'Delete Delegation', message: `Delete delegation "${del.name}"? This cannot be undone.`, confirmLabel: 'Delete', danger: true });
  if (!ok) return;
  try {
    await api.delete(`/api/delegations/${del.id}`);
    await fetchData();
  } catch (e) { console.error(e); }
}

async function archiveDelegation(del) {
  const now = new Date();
  const endDate = new Date(del.end_datetime);
  const isPast = endDate < now;

  const ok = await confirm({ title: 'Archive Delegation', message: `Move delegation "${del.name}" to archive?`, confirmLabel: 'Archive', danger: false });
  if (!ok) return;

  if (!isPast) {
    const ok2 = await confirm({ title: 'Delegation Still Active', message: 'This delegation has not ended yet. Archive it anyway?', confirmLabel: 'Archive Anyway', danger: true });
    if (!ok2) return;
  }

  try {
    await api.post(`/api/delegations/${del.id}/archive`);
    await fetchData();
  } catch (e) { console.error(e); }
}

async function addPerson(del) {
  const userId = addUserForm[del.id];
  if (!userId) return;
  try {
    await api.post(`/api/delegations/${del.id}/add-user`, { user_id: userId });
    addUserForm[del.id] = '';
    await fetchData();
    refreshLogs(del.id);
  } catch (e) { console.error(e); }
}

async function removePerson(del, user) {
  try {
    await api.post(`/api/delegations/${del.id}/remove-user`, { user_id: user.id });
    await fetchData();
    refreshLogs(del.id);
  } catch (e) { console.error(e); }
}

async function approveUser(del, user) {
  try {
    await api.post(`/api/delegations/${del.id}/approve-user`, { user_id: user.id });
    await fetchData();
    refreshLogs(del.id);
  } catch (e) { console.error(e); }
}

async function rejectUser(del, user) {
  try {
    await api.post(`/api/delegations/${del.id}/reject-user`, { user_id: user.id });
    await fetchData();
    refreshLogs(del.id);
  } catch (e) { console.error(e); }
}

async function updateDates(del, user, field, value) {
  try {
    await api.put(`/api/delegations/${del.id}/user-dates`, {
      user_id: user.id,
      custom_start: field === 'custom_start' ? (value || null) : (user.pivot?.custom_start || null),
      custom_end: field === 'custom_end' ? (value || null) : (user.pivot?.custom_end || null),
    });
    await fetchData();
    refreshLogs(del.id);
  } catch (e) { console.error(e); }
}

async function fetchLogs(delId) {
  logsLoading[delId] = true;
  try {
    const res = await api.get(`/api/delegations/${delId}/logs`);
    logsData[delId] = res.data;
  } catch (e) { console.error(e); }
  finally { logsLoading[delId] = false; }
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
  } catch (e) { console.error(e); }
  finally { loading.value = false; }
}

onMounted(fetchData);
</script>
