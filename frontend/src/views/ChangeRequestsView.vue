<template>
  <AppLayout>
    <main class="max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Change Requests</h1>
        <p class="text-slate-400 mt-1">
          Employees who can't attend a shift. Acknowledge a request once you have seen it, then
          change the schedule if needed.
        </p>
      </div>

      <!-- Filters -->
      <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-4 mb-4">
        <div class="flex flex-wrap gap-3 items-end">
          <div>
            <label for="cr-status" class="block text-[10px] text-slate-500 uppercase tracking-wider mb-1">Status</label>
            <select id="cr-status" v-model="filters.status"
              class="px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500/50 cursor-pointer min-w-[140px]">
              <option v-for="s in STATUS_FILTERS" :key="s.value" :value="s.value" class="bg-slate-800">{{ s.label }}</option>
            </select>
          </div>
          <div>
            <label for="cr-group" class="block text-[10px] text-slate-500 uppercase tracking-wider mb-1">Group</label>
            <select id="cr-group" v-model="filters.shift_group_id"
              class="px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500/50 cursor-pointer min-w-[140px] max-w-full">
              <option value="" class="bg-slate-800">All groups</option>
              <option v-for="g in activeGroups" :key="g.id" :value="String(g.id)" class="bg-slate-800">{{ g.name }}</option>
              <optgroup v-if="archivedGroups.length" label="Archived" class="bg-slate-800">
                <option v-for="g in archivedGroups" :key="g.id" :value="String(g.id)" class="bg-slate-800">{{ g.name }}</option>
              </optgroup>
            </select>
          </div>
          <div>
            <label for="cr-month" class="block text-[10px] text-slate-500 uppercase tracking-wider mb-1">Month</label>
            <input id="cr-month" v-model="filters.month" type="month"
              class="px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500/50 w-[170px] max-w-full" />
          </div>
          <button v-if="filtersChanged" type="button" @click="resetFilters"
            class="px-3 py-2 text-slate-400 hover:text-slate-200 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg text-sm transition-all">
            Reset
          </button>
        </div>
      </div>

      <p class="text-xs text-slate-500 mb-3" data-testid="cr-results-count">
        <template v-if="!loading && !loadError">
          <template v-if="total > 0">{{ total }} request{{ total === 1 ? '' : 's' }}</template>
          <template v-else>No results</template>
        </template>
      </p>

      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-20" aria-busy="true">
        <svg class="animate-spin w-8 h-8 text-indigo-400" fill="none" viewBox="0 0 24 24" aria-hidden="true">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        <span class="sr-only">Loading change requests</span>
      </div>

      <!-- Failed to load -->
      <div v-else-if="loadError" role="alert" class="text-center py-20">
        <p class="text-red-300 text-sm mb-4">{{ loadError }}</p>
        <button type="button" @click="fetchPage"
          class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all">Retry</button>
      </div>

      <!-- Empty -->
      <div v-else-if="requests.length === 0" class="text-center py-20" data-testid="cr-empty">
        <p class="text-slate-400 text-lg mb-1">
          {{ filters.status === 'pending' && !filtersNarrowed ? 'No pending change requests' : 'No change requests found' }}
        </p>
        <p class="text-slate-500 text-sm">
          {{ filters.status === 'pending' && !filtersNarrowed ? 'Requests employees file from My Shifts show up here.' : 'Try another status, group or month.' }}
        </p>
      </div>

      <!-- List: a table from md up, stacked cards on a phone. The explicit roles keep the table
           semantics that display:block drops in some browsers; below md the header row is hidden,
           so every cell carries its own visible caption. -->
      <div v-else class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
        <table role="table" class="w-full text-sm block md:table">
          <thead role="rowgroup" class="hidden md:table-header-group">
            <tr role="row" class="border-b border-white/10 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
              <th role="columnheader" scope="col" class="px-4 py-3">Employee</th>
              <th role="columnheader" scope="col" class="px-4 py-3">Shift</th>
              <th role="columnheader" scope="col" class="px-4 py-3">Group</th>
              <th role="columnheader" scope="col" class="px-4 py-3">Reason</th>
              <th role="columnheader" scope="col" class="px-4 py-3">Filed</th>
              <th role="columnheader" scope="col" class="px-4 py-3">Status</th>
              <th role="columnheader" scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody role="rowgroup" class="block md:table-row-group">
            <tr v-for="req in requests" :key="req.id" role="row"
              data-testid="change-request-row" :data-request-id="req.id"
              class="block md:table-row border-b border-white/5 last:border-b-0 px-4 py-3 md:p-0 align-top">
              <td role="cell" class="block md:table-cell md:px-4 md:py-3">
                <span class="md:hidden text-[10px] text-slate-500 uppercase tracking-wider block">Employee</span>
                <span class="text-white font-medium">{{ req.user?.name ?? 'Unknown employee' }}</span>
              </td>
              <td role="cell" class="block md:table-cell md:px-4 md:py-3 mt-2 md:mt-0">
                <span class="md:hidden text-[10px] text-slate-500 uppercase tracking-wider block">Shift</span>
                <span class="text-slate-200">{{ formatCalendarDate(requestShiftDate(req)) || '—' }}</span>
                <span class="block text-xs text-slate-500">{{ shiftTimeLabel(req) }}</span>
                <span v-if="removedState(req)" data-testid="cr-removed"
                  class="inline-block mt-1 px-2 py-0.5 text-[10px] font-semibold uppercase rounded-full border bg-slate-500/15 text-slate-300 border-slate-500/30">
                  {{ removedState(req).label }}
                </span>
              </td>
              <td role="cell" class="block md:table-cell md:px-4 md:py-3 mt-2 md:mt-0">
                <span class="md:hidden text-[10px] text-slate-500 uppercase tracking-wider block">Group</span>
                <template v-if="requestGroup(req)">
                  <span class="text-slate-300">{{ requestGroup(req).name }}</span>
                  <span v-if="requestGroup(req).archived"
                    class="ml-1 px-1.5 py-0.5 text-[9px] font-semibold uppercase rounded-full border bg-amber-500/10 text-amber-300 border-amber-500/20">Archived</span>
                </template>
                <span v-else class="text-slate-600">—</span>
              </td>
              <td role="cell" class="block md:table-cell md:px-4 md:py-3 mt-2 md:mt-0 md:max-w-xs">
                <span class="md:hidden text-[10px] text-slate-500 uppercase tracking-wider block">Reason</span>
                <p class="text-slate-300 whitespace-pre-line break-words">{{ req.reason }}</p>
              </td>
              <td role="cell" class="block md:table-cell md:px-4 md:py-3 mt-2 md:mt-0 text-xs text-slate-500 whitespace-nowrap">
                <span class="md:hidden text-[10px] text-slate-500 uppercase tracking-wider block">Filed</span>
                {{ formatDateTime(req.created_at) }}
              </td>
              <td role="cell" class="block md:table-cell md:px-4 md:py-3 mt-2 md:mt-0">
                <span class="md:hidden text-[10px] text-slate-500 uppercase tracking-wider block">Status</span>
                <span data-testid="cr-status"
                  :class="['inline-block px-2.5 py-1 text-[10px] font-semibold uppercase rounded-full border',
                    req.status === 'pending'
                      ? 'bg-amber-500/15 text-amber-400 border-amber-500/20'
                      : 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20']">
                  {{ statusText(req) }}
                </span>
                <span v-if="req.acknowledged_at" class="block text-[10px] text-slate-500 mt-1">{{ formatDateTime(req.acknowledged_at) }}</span>
              </td>
              <td role="cell" class="block md:table-cell md:px-4 md:py-3 mt-3 md:mt-0 md:text-right">
                <div class="flex flex-wrap gap-2 md:justify-end">
                  <router-link v-if="scheduleLink(req)" :to="scheduleLink(req)"
                    class="px-3 py-1.5 text-xs font-medium text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg transition-all whitespace-nowrap">
                    View in schedule
                  </router-link>
                  <button v-if="req.status === 'pending'" type="button"
                    @click="acknowledge(req)" :disabled="acknowledging.has(req.id)"
                    :aria-busy="acknowledging.has(req.id) ? 'true' : 'false'"
                    :aria-label="`Acknowledge the request from ${req.user?.name ?? 'an employee'} for ${requestShiftDate(req)}`"
                    class="px-3 py-1.5 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition-all disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap">
                    {{ acknowledging.has(req.id) ? 'Acknowledging…' : 'Acknowledge' }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <nav v-if="!loading && !loadError && lastPage > 1" aria-label="Pagination" class="flex items-center justify-between gap-3 mt-4">
        <p class="text-xs text-slate-500">Page {{ currentPage }} of {{ lastPage }}</p>
        <div class="flex items-center gap-2">
          <button type="button" @click="currentPage--" :disabled="currentPage === 1"
            class="px-3 py-1.5 rounded-lg text-xs text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 disabled:opacity-30 transition-all">
            Previous
          </button>
          <button type="button" @click="currentPage++" :disabled="currentPage === lastPage"
            class="px-3 py-1.5 rounded-lg text-xs text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 disabled:opacity-30 transition-all">
            Next
          </button>
        </div>
      </nav>
    </main>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { describeApiError } from '../apiErrors.js';
import api from '../axios.js';
import {
  removedState,
  requestGroup,
  requestShiftDate,
  STATUS_FILTERS,
  scheduleLink,
  shiftTimeLabel,
  statusText,
} from '../changeRequests.js';
import AppLayout from '../components/AppLayout.vue';
import { useLatestRequest } from '../composables/useLatestRequest.js';
import { usePendingChangeRequests } from '../composables/usePendingChangeRequests.js';
import { useToast } from '../composables/useToast.js';
import { formatCalendarDate } from '../dates.js';

const PER_PAGE = 20;

const { showToast } = useToast();
const pending = usePendingChangeRequests();
const route = useRoute();
const router = useRouter();

const loading = ref(true);
const loadError = ref('');
const requests = ref([]);
const total = ref(0);
const lastPage = ref(1);
const currentPage = ref(1);
const groups = ref([]);
const activeGroups = computed(() => groups.value.filter((g) => !g.archived_at));
const archivedGroups = computed(() => groups.value.filter((g) => g.archived_at));
// Ids being acknowledged: a second click on the same row does nothing until the first answers.
const acknowledging = ref(new Set());

const filters = reactive({ status: 'pending', shift_group_id: '', month: '' });

// ?group=<id>&month=<YYYY-MM>[&status=...] (the filing alarm's link) set the filters, then leave
// the address, so a reload or a later filter change doesn't fight them. Also when the screen is
// already open and another alarm is clicked (same route: the view is reused, not remounted).
function applyQueryFilters(query) {
  const known = ['group', 'month', 'status'].filter((key) => query[key] !== undefined);
  if (known.length === 0) return;
  const group = Number(query.group);
  if (Number.isInteger(group) && group > 0) filters.shift_group_id = String(group);
  if (typeof query.month === 'string' && /^\d{4}-(0[1-9]|1[0-2])$/.test(query.month)) {
    filters.month = query.month;
  }
  if (STATUS_FILTERS.some((s) => s.value === query.status)) filters.status = query.status;
  const rest = { ...query };
  for (const key of known) delete rest[key];
  router.replace({ query: rest });
}
applyQueryFilters(route.query);
const filtersNarrowed = computed(() => filters.shift_group_id !== '' || filters.month !== '');
const filtersChanged = computed(() => filters.status !== 'pending' || filtersNarrowed.value);

function params() {
  const p = { status: filters.status, page: currentPage.value, per_page: PER_PAGE };
  if (filters.shift_group_id) p.shift_group_id = filters.shift_group_id;
  if (filters.month) p.month = filters.month;
  return p;
}

// Filter and page changes overlap loads; only the latest may fill the list or clear `loading`.
const listRequests = useLatestRequest();

// quiet: refresh in place (no spinner), e.g. after an acknowledge.
async function fetchPage({ quiet = false } = {}) {
  const token = listRequests.begin();
  if (!quiet) loading.value = true;
  try {
    const res = await api.get('/api/shift-change-requests', { params: params() });
    if (!listRequests.isCurrent(token)) return;
    requests.value = res.data.data ?? [];
    total.value = res.data.total ?? 0;
    lastPage.value = Math.max(1, res.data.last_page ?? 1);
    loadError.value = '';
    // The list shrank under us (e.g. on the last page): show its new last page.
    if (currentPage.value > lastPage.value) currentPage.value = lastPage.value;
  } catch (err) {
    if (!listRequests.isCurrent(token)) return;
    if (quiet) {
      // The rows on screen are still right; only the refresh failed.
      const e = describeApiError(err, 'Could not refresh the change requests');
      if (!e.silent) showToast(e.message, 'error', 6000);
      return;
    }
    requests.value = [];
    loadError.value = describeApiError(err, 'Could not load the change requests').message;
  } finally {
    if (listRequests.isCurrent(token)) loading.value = false;
  }
}

function reloadFromFirstPage() {
  if (currentPage.value === 1) fetchPage();
  else currentPage.value = 1;
}

watch(currentPage, () => fetchPage());
watch(() => [filters.status, filters.shift_group_id, filters.month], reloadFromFirstPage);
// Only while this screen is the route: leaving for the Schedule (whose link also has ?group=)
// changes route.query before this view unmounts, and must not be rewritten here.
watch(
  () => (route.name === 'change-requests' ? route.query : null),
  (query) => {
    if (query) applyQueryFilters(query);
  },
);

function resetFilters() {
  filters.status = 'pending';
  filters.shift_group_id = '';
  filters.month = '';
}

function refreshBadge() {
  pending.refresh().catch(() => {});
}

// No confirm step: acknowledging only records that a manager has seen the request (and tells the
// employee); it changes no schedule, and acknowledging twice is harmless on the server.
// The row shows the result at once, then the page is re-read quietly: under the Pending filter the
// acknowledged row leaves the page and the next pending one moves up, so paging never skips a
// request (acknowledged rows kept on screen would shift every later page).
async function acknowledge(req) {
  if (acknowledging.value.has(req.id)) return;
  acknowledging.value = new Set(acknowledging.value).add(req.id);
  try {
    const res = await api.put(`/api/shift-change-requests/${req.id}/acknowledge`);
    const i = requests.value.findIndex((r) => r.id === req.id);
    if (i !== -1) requests.value[i] = res.data;
    showToast(`Acknowledged the request from ${req.user?.name ?? 'an employee'}.`);
    fetchPage({ quiet: true });
  } catch (err) {
    const e = describeApiError(err, 'Could not acknowledge the request');
    if (!e.silent) showToast(e.message, 'error', 6000);
  } finally {
    const next = new Set(acknowledging.value);
    next.delete(req.id);
    acknowledging.value = next;
    refreshBadge();
  }
}

async function fetchGroups() {
  try {
    // Archived groups too: their requests for today or earlier stay pending (archiving removes only
    // future work), and the filter must be able to narrow to them.
    const res = await api.get('/api/shift-groups', { params: { include_archived: 1 } });
    groups.value = res.data ?? [];
  } catch {
    groups.value = []; // the filter just offers "All groups"
  }
}

function formatDateTime(value) {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '—';
  return `${d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })} ${d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`;
}

// The badge itself is read by AppLayout's poll, which starts with every page.
onMounted(() => {
  fetchGroups();
  fetchPage();
});
</script>
