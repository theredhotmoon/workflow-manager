<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
        <div>
          <h1 class="text-2xl font-bold text-white">Procedures</h1>
          <p class="text-slate-400 text-sm mt-1">{{ totalProcedures }} procedure{{ totalProcedures !== 1 ? 's' : '' }}</p>
        </div>
        <div class="flex items-center gap-3">
          <div class="relative">
            <input v-model="searchQuery" type="text" placeholder="Search procedures..."
              class="w-64 pl-9 pr-4 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
            <svg class="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
          </div>
          <!-- View toggle -->
          <div class="flex bg-white/5 border border-white/10 rounded-xl p-0.5">
            <button @click="setViewMode('tiles')" :class="['p-2 rounded-lg transition-all', viewMode === 'tiles' ? 'bg-indigo-500/20 text-indigo-400' : 'text-slate-500 hover:text-slate-300']" title="Tiles view">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" /></svg>
            </button>
            <button @click="setViewMode('table')" :class="['p-2 rounded-lg transition-all', viewMode === 'table' ? 'bg-indigo-500/20 text-indigo-400' : 'text-slate-500 hover:text-slate-300']" title="Table view">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" /></svg>
            </button>
          </div>
          <button @click="showEditor = true"
            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl text-sm shadow-lg shadow-blue-500/25 hover:shadow-blue-500/40 transition-all whitespace-nowrap">
            + New Procedure
          </button>
        </div>
      </div>

      <!-- Two-column layout -->
      <div class="flex gap-6">
        <!-- Left: Sidebar (25%) -->
        <div class="w-1/4 shrink-0">
          <div class="sticky top-6 space-y-2">

            <!-- Location accordion -->
            <div class="bg-white/5 border border-white/10 rounded-xl overflow-hidden">
              <button @click="accordions.location = !accordions.location"
                class="w-full flex items-center justify-between px-4 py-3 text-xs font-semibold text-slate-300 uppercase tracking-wider hover:bg-white/5 transition-colors">
                <div class="flex items-center gap-2">
                  <span>Location</span>
                  <span v-if="selectedNodeId" class="px-1.5 py-0.5 text-[9px] bg-violet-500/20 text-violet-400 rounded-full">1</span>
                </div>
                <svg :class="['w-3.5 h-3.5 text-slate-500 transition-transform duration-200', accordions.location ? 'rotate-180' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
              </button>
              <div v-if="accordions.location" class="pb-3 overflow-hidden">
                <!-- Root "Procedures" node -->
                <div :class="['flex items-center gap-1 px-3 py-1.5 cursor-pointer rounded-lg mx-1 transition-all hover:bg-white/5',
                  selectedNodeId === 'root' ? 'bg-indigo-500/10' : '']"
                  @click="selectNode('root')">
                  <button @click.stop="rootExpanded = !rootExpanded"
                    class="w-5 h-5 flex items-center justify-center text-slate-500 hover:text-white transition-colors shrink-0">
                    <svg :class="['w-3 h-3 transition-transform duration-150', rootExpanded ? 'rotate-90' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                  </button>
                  <span class="text-[11px]">📂</span>
                  <span :class="['text-[11px] font-semibold', selectedNodeId === 'root' ? 'text-indigo-300' : 'text-slate-200']">Procedures</span>
                </div>

                <!-- Tree children -->
                <div v-if="rootExpanded" class="ml-1">
                  <ProcedureTreeNode v-for="node in treeData" :key="node.id"
                    :node="node" :depth="1" :selected-id="selectedNodeId === 'root' ? null : selectedNodeId"
                    @select="selectNode($event)" />
                </div>

                <div v-if="!treeData.length" class="px-3 py-2 text-slate-600 text-[10px]">
                  No tree structure yet.
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-3 mt-2 mx-3">
                  <button v-if="selectedNodeId" @click="selectedNodeId = null"
                    class="text-[10px] text-slate-500 hover:text-white transition-colors">
                    Show all
                  </button>
                  <router-link v-if="isAdmin" to="/procedures/tree-editor"
                    class="text-[10px] text-indigo-400 hover:text-indigo-300 transition-colors ml-auto">
                    Edit tree →
                  </router-link>
                </div>
              </div>
            </div>

            <!-- Filters accordion -->
            <div class="bg-white/5 border border-white/10 rounded-xl overflow-hidden">
              <button @click="accordions.filters = !accordions.filters"
                class="w-full flex items-center justify-between px-4 py-3 text-xs font-semibold text-slate-300 uppercase tracking-wider hover:bg-white/5 transition-colors">
                <div class="flex items-center gap-2">
                  <span>Filters</span>
                  <span v-if="activeFilterCount" class="px-1.5 py-0.5 text-[9px] bg-indigo-500/20 text-indigo-400 rounded-full">{{ activeFilterCount }}</span>
                </div>
                <svg :class="['w-3.5 h-3.5 text-slate-500 transition-transform duration-200', accordions.filters ? 'rotate-180' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
              </button>
              <div v-if="accordions.filters" class="px-4 pb-4 space-y-4 overflow-hidden">

                <!-- Tags -->
                <div>
                  <p class="text-[10px] text-slate-500 uppercase tracking-wider font-semibold mb-2">Tags</p>
                  <div v-if="allTags.length" class="flex flex-wrap gap-1.5">
                    <button v-for="tag in allTags" :key="tag.id" @click="toggleTag(tag.id)"
                      :class="['px-2.5 py-1 rounded-full text-[10px] font-medium transition-all border',
                        selectedTags.includes(tag.id)
                          ? 'text-white shadow-sm ring-1 ring-offset-1 ring-offset-slate-800'
                          : 'bg-white/5 text-slate-400 border-white/10 hover:border-white/20']"
                      :style="selectedTags.includes(tag.id)
                        ? { backgroundColor: tag.color + '25', borderColor: tag.color + '50', color: tag.color, ringColor: tag.color + '40' }
                        : { borderColor: tag.color + '30', color: tag.color + 'cc' }">
                      {{ tag.name }}
                    </button>
                  </div>
                  <p v-else class="text-slate-600 text-[11px]">No tags defined.</p>
                </div>

                <!-- Date filters -->
                <div>
                  <p class="text-[10px] text-slate-500 uppercase tracking-wider font-semibold mb-2">Created Date</p>
                  <div class="space-y-1.5">
                    <div class="flex items-center gap-2">
                      <span class="text-[10px] text-slate-500 w-10 shrink-0">After</span>
                      <input v-model="createdAfter" type="date"
                        class="flex-1 px-2 py-1 bg-white/5 border border-white/10 rounded-lg text-white text-[11px] focus:outline-none focus:ring-1 focus:ring-indigo-500/50" />
                    </div>
                    <div class="flex items-center gap-2">
                      <span class="text-[10px] text-slate-500 w-10 shrink-0">Before</span>
                      <input v-model="createdBefore" type="date"
                        class="flex-1 px-2 py-1 bg-white/5 border border-white/10 rounded-lg text-white text-[11px] focus:outline-none focus:ring-1 focus:ring-indigo-500/50" />
                    </div>
                  </div>
                </div>

                <div>
                  <p class="text-[10px] text-slate-500 uppercase tracking-wider font-semibold mb-2">Updated Date</p>
                  <div class="space-y-1.5">
                    <div class="flex items-center gap-2">
                      <span class="text-[10px] text-slate-500 w-10 shrink-0">After</span>
                      <input v-model="updatedAfter" type="date"
                        class="flex-1 px-2 py-1 bg-white/5 border border-white/10 rounded-lg text-white text-[11px] focus:outline-none focus:ring-1 focus:ring-indigo-500/50" />
                    </div>
                    <div class="flex items-center gap-2">
                      <span class="text-[10px] text-slate-500 w-10 shrink-0">Before</span>
                      <input v-model="updatedBefore" type="date"
                        class="flex-1 px-2 py-1 bg-white/5 border border-white/10 rounded-lg text-white text-[11px] focus:outline-none focus:ring-1 focus:ring-indigo-500/50" />
                    </div>
                  </div>
                </div>

                <!-- Clear all -->
                <button v-if="activeFilterCount" @click="clearAllFilters"
                  class="w-full text-center py-1.5 text-[10px] text-slate-500 hover:text-white transition-colors border border-white/5 rounded-lg hover:border-white/15">
                  Clear all filters
                </button>
              </div>
            </div>

            <!-- Sorting accordion -->
            <div class="bg-white/5 border border-white/10 rounded-xl overflow-hidden">
              <button @click="accordions.sorting = !accordions.sorting"
                class="w-full flex items-center justify-between px-4 py-3 text-xs font-semibold text-slate-300 uppercase tracking-wider hover:bg-white/5 transition-colors">
                <div class="flex items-center gap-2">
                  <span>Sorting</span>
                  <span v-if="sortBy" class="px-1.5 py-0.5 text-[9px] bg-indigo-500/20 text-indigo-400 rounded-full">{{ sortLabels[sortBy] }}</span>
                </div>
                <svg :class="['w-3.5 h-3.5 text-slate-500 transition-transform duration-200', accordions.sorting ? 'rotate-180' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
              </button>
              <div v-if="accordions.sorting" class="px-4 pb-4 space-y-1 overflow-hidden">
                <button v-for="opt in sortOptions" :key="opt.key" @click="toggleSort(opt.key)"
                  :class="['w-full flex items-center justify-between px-3 py-2 rounded-lg text-xs font-medium transition-all border',
                    sortBy === opt.key
                      ? 'bg-indigo-500/10 border-indigo-500/20 text-indigo-300'
                      : 'bg-white/3 border-white/5 text-slate-400 hover:border-white/15 hover:text-slate-300']">
                  <span>{{ opt.label }}</span>
                  <svg v-if="sortBy === opt.key" :class="['w-3.5 h-3.5 transition-transform', sortDir === 'desc' ? 'rotate-180' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                  </svg>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Right: Procedure cards (75%) -->
        <div class="flex-1 min-w-0">
          <!-- Filter/sort in-flight indicator — shown when results already exist and are being refreshed -->
          <div class="flex items-center gap-2 mb-3 h-5 transition-opacity duration-200" :class="filtering ? 'opacity-100' : 'opacity-0'">
            <svg class="w-3.5 h-3.5 text-indigo-400 animate-spin shrink-0" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span class="text-xs text-slate-500">Updating results…</span>
          </div>

          <!-- Loading -->
          <div v-if="loading" class="text-center py-16"><p class="text-slate-400">Loading procedures...</p></div>

          <!-- Tiles view -->
          <div v-else-if="procedures.length && viewMode === 'tiles'" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <router-link v-for="proc in procedures" :key="proc.id" :to="`/procedures/${proc.id}`"
              class="group bg-white/5 border border-white/10 rounded-2xl p-5 hover:border-indigo-500/30 hover:bg-white/8 transition-all duration-200">
              <div class="flex items-start justify-between mb-3">
                <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider bg-indigo-500/15 text-indigo-400 border border-indigo-500/20 rounded-full">
                  {{ proc.identifier }}
                </span>
                <span class="text-[10px] text-slate-500 tabular-nums">v{{ proc.version }}</span>
              </div>
              <h3 class="text-white font-semibold text-sm mb-1.5 group-hover:text-indigo-300 transition-colors line-clamp-2">{{ proc.title }}</h3>
              <p v-if="proc.purpose" class="text-slate-400 text-xs leading-relaxed line-clamp-2 mb-3">{{ proc.purpose }}</p>
              <div v-if="proc.tags?.length" class="flex flex-wrap gap-1 mb-3">
                <span v-for="tag in proc.tags" :key="tag.id"
                  class="px-1.5 py-0.5 rounded text-[9px] font-medium"
                  :style="{ backgroundColor: tag.color + '20', color: tag.color, border: '1px solid ' + tag.color + '30' }">
                  {{ tag.name }}
                </span>
              </div>
              <div class="flex items-center justify-between text-[10px] text-slate-500 pt-2 border-t border-white/5">
                <span>{{ proc.owner?.name || '—' }}</span>
                <span>{{ formatDate(proc.updated_at) }}</span>
              </div>
            </router-link>
          </div>

          <!-- Table view -->
          <div v-else-if="procedures.length && viewMode === 'table'" class="bg-white/5 border border-white/10 rounded-2xl overflow-hidden">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-white/10 text-[10px] text-slate-500 uppercase tracking-wider">
                  <th class="text-left px-4 py-3 font-semibold">ID</th>
                  <th class="text-left px-4 py-3 font-semibold">Title</th>
                  <th class="text-left px-4 py-3 font-semibold">Tags</th>
                  <th class="text-left px-4 py-3 font-semibold">Owner</th>
                  <th class="text-center px-4 py-3 font-semibold">Ver</th>
                  <th class="text-left px-4 py-3 font-semibold">Created</th>
                  <th class="text-left px-4 py-3 font-semibold">Updated</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="proc in procedures" :key="proc.id"
                  @click="$router.push(`/procedures/${proc.id}`)"
                  class="border-b border-white/5 hover:bg-white/5 transition-colors cursor-pointer">
                  <td class="px-4 py-3">
                    <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider bg-indigo-500/15 text-indigo-400 border border-indigo-500/20 rounded-full">
                      {{ proc.identifier }}
                    </span>
                  </td>
                  <td class="px-4 py-3">
                    <p class="text-white font-medium truncate max-w-xs">{{ proc.title }}</p>
                    <p v-if="proc.purpose" class="text-slate-500 text-xs truncate max-w-xs mt-0.5">{{ proc.purpose }}</p>
                  </td>
                  <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-1">
                      <span v-for="tag in proc.tags" :key="tag.id"
                        class="px-1.5 py-0.5 rounded text-[9px] font-medium whitespace-nowrap"
                        :style="{ backgroundColor: tag.color + '20', color: tag.color, border: '1px solid ' + tag.color + '30' }">
                        {{ tag.name }}
                      </span>
                      <span v-if="!proc.tags?.length" class="text-slate-600 text-xs">—</span>
                    </div>
                  </td>
                  <td class="px-4 py-3 text-slate-300">{{ proc.owner?.name || '—' }}</td>
                  <td class="px-4 py-3 text-center">
                    <span class="text-[10px] text-slate-500 tabular-nums">v{{ proc.version }}</span>
                  </td>
                  <td class="px-4 py-3 text-slate-400 text-xs tabular-nums">{{ formatDate(proc.created_at) }}</td>
                  <td class="px-4 py-3 text-slate-400 text-xs tabular-nums">{{ formatDate(proc.updated_at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Empty state -->
          <div v-else-if="!loading" class="text-center py-16">
            <p class="text-slate-500 text-sm mb-3">No procedures found.</p>
            <button @click="showEditor = true" class="text-indigo-400 hover:text-indigo-300 text-sm font-medium">Create your first procedure →</button>
          </div>

          <!-- Pagination -->
          <div v-if="lastPage > 1" class="flex items-center justify-between mt-6">
            <p class="text-xs text-slate-500">Showing {{ (currentPage - 1) * perPage + 1 }}–{{ Math.min(currentPage * perPage, totalProcedures) }} of {{ totalProcedures }}</p>
            <div class="flex items-center gap-1">
              <button @click="goToPage(1)" :disabled="currentPage <= 1"
                :class="['px-2.5 py-1.5 rounded-lg text-xs font-medium transition-all border',
                  currentPage <= 1 ? 'text-slate-700 border-white/5 cursor-not-allowed' : 'text-slate-400 border-white/10 hover:bg-white/10 hover:text-white']">
                «
              </button>
              <button @click="goToPage(currentPage - 1)" :disabled="currentPage <= 1"
                :class="['px-2.5 py-1.5 rounded-lg text-xs font-medium transition-all border',
                  currentPage <= 1 ? 'text-slate-700 border-white/5 cursor-not-allowed' : 'text-slate-400 border-white/10 hover:bg-white/10 hover:text-white']">
                ‹
              </button>
              <template v-for="p in visiblePages" :key="p">
                <button v-if="p === '...'" disabled class="px-2.5 py-1.5 text-xs text-slate-600">…</button>
                <button v-else @click="goToPage(p)"
                  :class="['px-2.5 py-1.5 rounded-lg text-xs font-medium transition-all border',
                    p === currentPage
                      ? 'bg-indigo-500/20 text-indigo-400 border-indigo-500/30'
                      : 'text-slate-400 border-white/10 hover:bg-white/10 hover:text-white']">
                  {{ p }}
                </button>
              </template>
              <button @click="goToPage(currentPage + 1)" :disabled="currentPage >= lastPage"
                :class="['px-2.5 py-1.5 rounded-lg text-xs font-medium transition-all border',
                  currentPage >= lastPage ? 'text-slate-700 border-white/5 cursor-not-allowed' : 'text-slate-400 border-white/10 hover:bg-white/10 hover:text-white']">
                ›
              </button>
              <button @click="goToPage(lastPage)" :disabled="currentPage >= lastPage"
                :class="['px-2.5 py-1.5 rounded-lg text-xs font-medium transition-all border',
                  currentPage >= lastPage ? 'text-slate-700 border-white/5 cursor-not-allowed' : 'text-slate-400 border-white/10 hover:bg-white/10 hover:text-white']">
                »
              </button>
            </div>
          </div>
        </div>
      </div>
    </main>

    <!-- Editor Modal -->
    <ProcedureEditorModal
      :show="showEditor"
      :procedure-id="null"
      @close="showEditor = false"
      @saved="onProcedureCreated" />
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import ProcedureTreeNode from '../components/procedure/ProcedureTreeNode.vue';
import ProcedureEditorModal from '../components/procedure/ProcedureEditorModal.vue';
import { currentUser as authUser } from '../router/index.js';

const router = useRouter();

const procedures = ref([]);
const totalProcedures = ref(0);
const currentPage = ref(1);
const lastPage = ref(1);
const perPage = ref(30);

const viewMode = ref(localStorage.getItem('procedures_view_mode') || 'tiles');
function setViewMode(mode) { viewMode.value = mode; localStorage.setItem('procedures_view_mode', mode); }
const allTags = ref([]);
const treeData = ref([]);
const loading = ref(true);
const filtering = ref(false); // true when a filter/sort change is in flight (results already shown)
const searchQuery = ref('');
const selectedTags = ref([]);
const selectedNodeId = ref(null);
const rootExpanded = ref(true);
const isAdmin = computed(() => authUser.value?.is_admin);
const showEditor = ref(false);

function onProcedureCreated(id) {
  showEditor.value = false;
  if (id) {
    router.push(`/procedures/${id}`);
  }
}

// Accordion state
const accordions = reactive({ location: true, filters: false, sorting: false });

// Date filters
const createdAfter = ref('');
const createdBefore = ref('');
const updatedAfter = ref('');
const updatedBefore = ref('');

// Sorting
const sortBy = ref(''); // 'title', 'identifier', 'created_at', 'updated_at'
const sortDir = ref('asc');
const sortOptions = [
  { key: 'title', label: 'Title' },
  { key: 'identifier', label: 'Procedure ID' },
  { key: 'created_at', label: 'Creation Date' },
  { key: 'updated_at', label: 'Update Date' },
];
const sortLabels = { title: 'Title', identifier: 'ID', created_at: 'Created', updated_at: 'Updated' };

function toggleSort(key) {
  if (sortBy.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
  } else {
    sortBy.value = key;
    sortDir.value = 'asc';
  }
}

const activeFilterCount = computed(() => {
  let c = selectedTags.value.length;
  if (createdAfter.value) c++;
  if (createdBefore.value) c++;
  if (updatedAfter.value) c++;
  if (updatedBefore.value) c++;
  return c;
});

// Pagination helpers
const visiblePages = computed(() => {
  const pages = [];
  const total = lastPage.value;
  const current = currentPage.value;
  if (total <= 7) {
    for (let i = 1; i <= total; i++) pages.push(i);
  } else {
    pages.push(1);
    if (current > 3) pages.push('...');
    const start = Math.max(2, current - 1);
    const end = Math.min(total - 1, current + 1);
    for (let i = start; i <= end; i++) pages.push(i);
    if (current < total - 2) pages.push('...');
    pages.push(total);
  }
  return pages;
});

function goToPage(page) {
  if (page < 1 || page > lastPage.value || page === currentPage.value) return;
  currentPage.value = page;
  fetchProcedures();
}

// For tree sidebar — still need all procedure IDs (lightweight fetch)
function collectIds(nodes, targetId) {
  for (const n of nodes) {
    if (n.id === targetId) {
      const ids = [];
      const gather = (node) => { ids.push(node.id); (node.children || []).forEach(gather); };
      gather(n);
      return ids;
    }
    const result = collectIds(n.children || [], targetId);
    if (result) return result;
  }
  return null;
}

function toggleTag(id) {
  const idx = selectedTags.value.indexOf(id);
  idx >= 0 ? selectedTags.value.splice(idx, 1) : selectedTags.value.push(id);
}

function clearAllFilters() {
  selectedTags.value = [];
  createdAfter.value = '';
  createdBefore.value = '';
  updatedAfter.value = '';
  updatedBefore.value = '';
}

function selectNode(id) {
  selectedNodeId.value = selectedNodeId.value === id ? null : id;
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

async function fetchTree() {
  try { treeData.value = (await api.get('/api/procedures-tree')).data; } catch {}
}

let fetchTimeout = null;
async function fetchProcedures() {
  // Only show the full-page loading skeleton on the initial load.
  // Subsequent filter/sort/page fetches use the inline `filtering` indicator
  // so existing results stay visible instead of blinking to a blank state.
  if (!loading.value) {
    filtering.value = true;
  }
  try {
    const params = {
      page: currentPage.value,
      per_page: perPage.value,
    };
    if (searchQuery.value) params.search = searchQuery.value;
    if (selectedTags.value.length) params.tags = selectedTags.value.join(',');
    if (createdAfter.value) params.created_after = createdAfter.value;
    if (createdBefore.value) params.created_before = createdBefore.value;
    if (updatedAfter.value) params.updated_after = updatedAfter.value;
    if (updatedBefore.value) params.updated_before = updatedBefore.value;
    if (sortBy.value) {
      params.sort_by = sortBy.value;
      params.sort_dir = sortDir.value;
    }
    if (selectedNodeId.value) {
      params.node_id = selectedNodeId.value;
    }

    const res = await api.get('/api/procedures', { params });
    procedures.value = res.data.data;
    totalProcedures.value = res.data.total;
    currentPage.value = res.data.current_page;
    lastPage.value = res.data.last_page;
  } catch {} finally {
    loading.value = false;
    filtering.value = false;
  }
}

async function fetchInitial() {
  loading.value = true;
  try {
    const tagRes = await api.get('/api/procedure-tags');
    allTags.value = tagRes.data;
    await fetchTree();
    await fetchProcedures();
  } catch {} finally { loading.value = false; }
}

// Watch reactive filters — reset to page 1 and refetch with debounce for search
watch(searchQuery, () => {
  clearTimeout(fetchTimeout);
  fetchTimeout = setTimeout(() => {
    currentPage.value = 1;
    fetchProcedures();
  }, 300);
});

watch([selectedTags, createdAfter, createdBefore, updatedAfter, updatedBefore, sortBy, sortDir, selectedNodeId], () => {
  currentPage.value = 1;
  fetchProcedures();
}, { deep: true });

onMounted(fetchInitial);
</script>
