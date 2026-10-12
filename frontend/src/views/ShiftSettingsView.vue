<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 py-8">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-white">Shift Settings</h1>
          <p class="text-slate-400 text-sm mt-1">Manage shift color definitions used across the schedule</p>
        </div>
      </div>

      <!-- Color List -->
      <div class="bg-white/5 border border-white/10 rounded-2xl overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-white/10 bg-white/[0.02]">
          <h3 class="text-sm font-semibold text-white flex items-center gap-2">
            🎨 Shift Colors
            <span class="text-[10px] text-slate-500 font-normal">({{ colors.length }} defined)</span>
          </h3>
        </div>

        <div v-if="loading" class="p-8 text-center text-slate-500 text-sm">Loading…</div>

        <div v-else class="divide-y divide-white/5">
          <div v-for="color in colors" :key="color.id"
            class="flex items-center gap-4 px-5 py-3 group hover:bg-white/[0.03] transition-colors">
            <!-- Color swatch -->
            <div class="w-8 h-8 rounded-lg border border-white/20 shrink-0 shadow-inner cursor-pointer relative overflow-hidden"
              :style="{ backgroundColor: editId === color.id ? editHex : color.hex }"
              @click="startEdit(color)">
              <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 bg-black/30 transition-opacity">
                <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
              </div>
            </div>

            <!-- Name & hex -->
            <template v-if="editId === color.id">
              <input v-model="editName" type="text" placeholder="Name"
                class="flex-1 px-3 py-1.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
              <div class="flex items-center gap-1">
                <input v-model="editHex" type="color"
                  class="w-8 h-8 rounded-lg border border-white/10 cursor-pointer bg-transparent" />
                <input v-model="editHex" type="text" placeholder="#hex"
                  class="w-20 px-2 py-1.5 bg-white/5 border border-white/10 rounded-xl text-white text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500/50 font-mono" />
              </div>
              <button @click="saveEdit" :disabled="savingEdit" class="disabled:opacity-40 px-3 py-1.5 text-xs font-medium text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 rounded-lg hover:bg-emerald-500/20 transition-all">Save</button>
              <button @click="editId = null" class="px-3 py-1.5 text-xs font-medium text-slate-400 bg-white/5 border border-white/10 rounded-lg hover:bg-white/10 transition-all">Cancel</button>
            </template>
            <template v-else>
              <div class="flex-1 min-w-0">
                <span class="text-sm text-white font-medium">{{ color.name }}</span>
                <span class="text-xs text-slate-500 font-mono ml-2">{{ color.hex }}</span>
              </div>
              <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                <button @click="startEdit(color)"
                  class="p-1.5 text-slate-500 hover:text-indigo-400 hover:bg-indigo-500/10 rounded-lg transition-all" title="Edit">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                </button>
                <button @click="deleteColor(color)"
                  class="p-1.5 text-slate-500 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition-all" title="Delete">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
              </div>
            </template>
          </div>

          <div v-if="!colors.length && !loading" class="p-8 text-center text-slate-500 text-sm">
            No colors defined. Add one below.
          </div>
        </div>
      </div>

      <!-- Add New Color -->
      <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
        <h3 class="text-sm font-semibold text-white mb-3">Add New Color</h3>
        <div class="flex items-center gap-3">
          <input v-model="newHex" type="color"
            class="w-10 h-10 rounded-lg border border-white/10 cursor-pointer bg-transparent shrink-0" />
          <input v-model="newName" type="text" placeholder="Color name (e.g. Night, Day, Extra)"
            class="flex-1 px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500"
            @keyup.enter="addColor" />
          <input v-model="newHex" type="text" placeholder="#hex"
            class="w-24 px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500/50 font-mono placeholder-slate-600" />
          <button @click="addColor" :disabled="adding || !newName.trim() || !newHex"
            class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition-all text-sm disabled:opacity-40 disabled:cursor-not-allowed whitespace-nowrap">
            + Add
          </button>
        </div>
      </div>

      <AppToast />
    </main>
  </AppLayout>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import AppToast from '../components/AppToast.vue';
import { confirm } from '../composables/useConfirm.js';
import { useToast } from '../composables/useToast.js';

const colors = ref([]);
const loading = ref(true);
const newName = ref('');
const newHex = ref('#3b82f6');
const editId = ref(null);
const editName = ref('');
const editHex = ref('');
// A save in flight: a second click (or Enter) must not create a duplicate.
const adding = ref(false);
const savingEdit = ref(false);
const { showToast } = useToast();

async function fetchColors() {
  loading.value = true;
  try {
    const res = await api.get('/api/shift-colors');
    colors.value = res.data;
  } catch {
    showToast('Failed to load colors.', 'error');
  } finally {
    loading.value = false;
  }
}

async function addColor() {
  if (adding.value || !newName.value.trim() || !newHex.value) return;
  adding.value = true;
  try {
    const res = await api.post('/api/shift-colors', {
      name: newName.value.trim(),
      hex: newHex.value,
    });
    colors.value.push(res.data);
    newName.value = '';
    newHex.value = '#3b82f6';
    showToast('Color added.');
  } catch (e) {
    showToast(e.response?.data?.message || 'Failed to add color.', 'error');
  } finally {
    adding.value = false;
  }
}

function startEdit(color) {
  editId.value = color.id;
  editName.value = color.name;
  editHex.value = color.hex;
}

async function saveEdit() {
  if (!editId.value || savingEdit.value) return;
  savingEdit.value = true;
  try {
    const res = await api.put(`/api/shift-colors/${editId.value}`, {
      name: editName.value.trim(),
      hex: editHex.value,
    });
    const idx = colors.value.findIndex((c) => c.id === editId.value);
    if (idx >= 0) colors.value[idx] = res.data;
    editId.value = null;
    showToast('Color updated.');
  } catch (e) {
    showToast(e.response?.data?.message || 'Failed to update.', 'error');
  } finally {
    savingEdit.value = false;
  }
}

async function deleteColor(color) {
  const ok = await confirm({
    title: 'Delete Color',
    message: `Delete color "${color.name}"?`,
    confirmLabel: 'Delete',
    danger: true,
  });
  if (!ok) return;
  try {
    await api.delete(`/api/shift-colors/${color.id}`);
    colors.value = colors.value.filter((c) => c.id !== color.id);
    showToast('Color deleted.');
  } catch (e) {
    showToast(e.response?.data?.message || 'Failed to delete.', 'error');
  }
}

onMounted(fetchColors);
</script>
