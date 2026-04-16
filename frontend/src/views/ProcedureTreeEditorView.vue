<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-white">Procedure Tree Editor</h1>
          <p class="text-slate-400 text-sm mt-1">Drag procedures to organize them into a tree structure</p>
        </div>
        <router-link to="/procedures" class="px-4 py-2 text-sm text-slate-400 hover:text-white border border-white/10 rounded-xl hover:border-white/20 transition-all">
          ← Back to Procedures
        </router-link>
      </div>

      <div class="flex gap-6">
        <!-- Left: Tree (65%) -->
        <div class="flex-1 min-w-0">
          <div class="bg-white/5 border border-white/10 rounded-2xl p-4">
            <div v-if="loadingTree" class="text-center py-12"><p class="text-slate-400 text-sm">Loading tree...</p></div>
            <div v-else>
              <!-- Root node -->
              <div :class="['flex items-center gap-2 px-3 py-2 rounded-xl transition-all cursor-pointer mb-1',
                isDragOverRoot ? 'bg-indigo-500/20 border border-indigo-500/40 ring-1 ring-indigo-500/30' : 'hover:bg-white/5',
                ]"
                @dragover.prevent="isDragOverRoot = true"
                @dragleave="isDragOverRoot = false"
                @drop.prevent="onDropToRoot">
                <button @click="rootExpanded = !rootExpanded"
                  class="w-6 h-6 flex items-center justify-center text-slate-500 hover:text-white transition-colors">
                  <svg :class="['w-3.5 h-3.5 transition-transform duration-150', rootExpanded ? 'rotate-90' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                  </svg>
                </button>
                <span class="text-sm">📂</span>
                <span class="text-sm font-semibold text-slate-200">Procedures</span>
                <span class="text-[10px] text-slate-600 ml-auto">Drop here to move to root</span>
              </div>

              <!-- Tree children -->
              <div v-if="rootExpanded">
                <TreeEditNode v-for="node in treeData" :key="node.id"
                  :node="node" :depth="1"
                  @drop-procedure="onMoveProcedure" />
              </div>

              <div v-if="!treeData.length && !loadingTree" class="text-center py-8 text-slate-600 text-sm">
                No procedures assigned to the tree yet. Drag from the right panel to add.
              </div>
            </div>
          </div>
        </div>

        <!-- Right: Unassigned procedures (35%) -->
        <div class="w-[35%] shrink-0">
          <div class="sticky top-6">
            <div class="bg-white/5 border border-white/10 rounded-2xl p-4">
              <div class="flex items-center justify-between mb-3">
                <h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Unassigned Procedures</h2>
                <span class="text-[10px] text-slate-600">{{ unassigned.length }} item{{ unassigned.length !== 1 ? 's' : '' }}</span>
              </div>

              <!-- Search -->
              <div class="relative mb-3">
                <input v-model="searchQuery" type="text" placeholder="Search..."
                  class="w-full pl-8 pr-3 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white text-xs placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-indigo-500/50" />
                <svg class="w-3.5 h-3.5 text-slate-500 absolute left-2.5 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
              </div>

              <div v-if="filteredUnassigned.length" class="space-y-1 max-h-[60vh] overflow-y-auto">
                <div v-for="proc in filteredUnassigned" :key="proc.id"
                  draggable="true"
                  @dragstart="onDragStart($event, proc.id)"
                  class="flex items-center gap-2 px-3 py-2 rounded-xl bg-white/3 border border-white/5 cursor-grab active:cursor-grabbing hover:border-indigo-500/20 hover:bg-white/5 transition-all group">
                  <span class="text-slate-600 group-hover:text-slate-400 text-xs">⠿</span>
                  <span class="px-1.5 py-0.5 text-[9px] font-bold bg-indigo-500/15 text-indigo-400 border border-indigo-500/20 rounded-full shrink-0">{{ proc.identifier }}</span>
                  <span class="text-xs text-slate-300 truncate">{{ proc.title }}</span>
                </div>
              </div>
              <p v-else class="text-center text-slate-600 text-xs py-6">
                {{ searchQuery ? 'No results' : 'All procedures are assigned!' }}
              </p>
            </div>

            <!-- All assigned list (for re-arranging) -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-4 mt-2">
              <h2 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">All Assigned</h2>
              <div v-if="assignedList.length" class="space-y-1 max-h-[30vh] overflow-y-auto">
                <div v-for="proc in assignedList" :key="proc.id"
                  draggable="true"
                  @dragstart="onDragStart($event, proc.id)"
                  class="flex items-center gap-2 px-3 py-1.5 rounded-lg cursor-grab active:cursor-grabbing hover:bg-white/5 transition-all group">
                  <span class="text-slate-600 group-hover:text-slate-400 text-xs">⠿</span>
                  <span class="text-[9px] text-slate-500 font-mono shrink-0">{{ proc.identifier }}</span>
                  <span class="text-[11px] text-slate-400 truncate">{{ proc.title }}</span>
                </div>
              </div>
              <p v-else class="text-center text-slate-600 text-xs py-4">None assigned yet.</p>
            </div>
          </div>
        </div>
      </div>
    </main>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import TreeEditNode from '../components/procedure/TreeEditNode.vue';

const treeData = ref([]);
const allProcedures = ref([]);
const loadingTree = ref(true);
const rootExpanded = ref(true);
const isDragOverRoot = ref(false);
const searchQuery = ref('');

// Collect IDs in tree
function gatherIds(nodes) {
  const ids = new Set();
  for (const n of nodes) { ids.add(n.id); for (const id of gatherIds(n.children || [])) ids.add(id); }
  return ids;
}

const assignedIds = computed(() => gatherIds(treeData.value));

const unassigned = computed(() =>
  allProcedures.value
    .filter(p => !assignedIds.value.has(p.id))
    .sort((a, b) => a.title.localeCompare(b.title))
);

const filteredUnassigned = computed(() => {
  if (!searchQuery.value) return unassigned.value;
  const q = searchQuery.value.toLowerCase();
  return unassigned.value.filter(p =>
    p.title.toLowerCase().includes(q) || p.identifier.toLowerCase().includes(q)
  );
});

const assignedList = computed(() => {
  const flat = [];
  const walk = (nodes) => { for (const n of nodes) { flat.push(n); walk(n.children || []); } };
  walk(treeData.value);
  return flat.sort((a, b) => a.title.localeCompare(b.title));
});

function onDragStart(e, procId) {
  e.dataTransfer.setData('application/procedure-id', procId.toString());
  e.dataTransfer.effectAllowed = 'move';
}

async function onMoveProcedure({ procedureId, parentId }) {
  try {
    await api.put(`/api/procedures/${procedureId}/move`, { parent_id: parentId });
    await fetchTree();
  } catch {}
}

async function onDropToRoot(e) {
  isDragOverRoot.value = false;
  const procId = e.dataTransfer.getData('application/procedure-id');
  if (procId) {
    await onMoveProcedure({ procedureId: parseInt(procId), parentId: null });
  }
}

async function fetchTree() {
  try { treeData.value = (await api.get('/api/procedures-tree')).data; } catch {}
}

async function fetchData() {
  loadingTree.value = true;
  try {
    const [treeRes, procRes] = await Promise.all([
      api.get('/api/procedures-tree'),
      api.get('/api/procedures'),
    ]);
    treeData.value = treeRes.data;
    allProcedures.value = procRes.data;
  } catch {} finally { loadingTree.value = false; }
}

onMounted(fetchData);
</script>
