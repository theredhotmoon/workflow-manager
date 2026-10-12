<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-white">Procedure Tags</h1>
          <p class="text-slate-400 text-sm mt-1">Manage keywords for linking related procedures</p>
        </div>
        <button @click="openCreate"
          class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl text-sm shadow-lg shadow-blue-500/25 transition-all hover:shadow-blue-500/40">
          + New Tag
        </button>
      </div>

      <div v-if="loading" class="text-center py-12"><p class="text-slate-400">Loading...</p></div>

      <!-- Table View -->
      <div v-else class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
        <table class="w-full">
          <thead>
            <tr class="border-b border-white/10">
              <th class="text-left px-5 py-3.5 text-xs font-semibold text-slate-400 uppercase tracking-wider">Color</th>
              <th class="text-left px-5 py-3.5 text-xs font-semibold text-slate-400 uppercase tracking-wider">Name</th>
              <th class="text-left px-5 py-3.5 text-xs font-semibold text-slate-400 uppercase tracking-wider">Hex</th>
              <th class="text-right px-5 py-3.5 text-xs font-semibold text-slate-400 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-white/5">
            <tr v-for="tag in tags" :key="tag.id" class="hover:bg-white/[0.03] transition-colors">
              <td class="px-5 py-3">
                <span class="w-5 h-5 rounded-full inline-block border border-white/10" :style="{ backgroundColor: tag.color }"></span>
              </td>
              <td class="px-5 py-3">
                <span class="text-white font-medium text-sm">{{ tag.name }}</span>
              </td>
              <td class="px-5 py-3">
                <span class="text-slate-500 text-xs font-mono">{{ tag.color }}</span>
              </td>
              <td class="px-5 py-3 text-right">
                <div class="flex items-center justify-end gap-1">
                  <button @click="openEdit(tag)" class="p-2 text-slate-400 hover:text-indigo-400 hover:bg-indigo-500/10 rounded-lg transition-all" title="Edit">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                  </button>
                  <button @click="deleteTag(tag)" class="p-2 text-slate-400 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition-all" title="Delete">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="!tags.length" class="text-center text-slate-500 py-8 text-sm">No tags yet. Create one to get started.</p>
      </div>

      <!-- Create/Edit Modal -->
      <Transition enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
        enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <BaseModal v-if="showModal"
          overlay-class="fixed inset-0 z-50 flex items-center justify-center px-4"
          backdrop-class="fixed inset-0 bg-black/50 backdrop-blur-sm"
          panel-class="relative w-full max-w-sm bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl p-6"
          :busy="saving"
          @close="showModal = false"
          v-slot="{ titleId }">
          <h3 :id="titleId" class="text-white font-bold text-lg mb-4">{{ editingId ? 'Edit Tag' : 'New Tag' }}</h3>
          <form @submit.prevent="saveTag" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-slate-300 mb-1.5">Name</label>
              <input v-model="form.name" type="text" required placeholder="e.g. Security"
                :aria-invalid="nameError ? 'true' : undefined"
                class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
              <p v-if="nameError" data-testid="tag-name-error" class="mt-1 text-xs text-red-400">{{ nameError }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-300 mb-1.5">Color</label>
              <div class="flex items-center gap-3">
                <input v-model="form.color" type="color"
                  class="w-10 h-10 rounded-lg border border-white/10 bg-transparent cursor-pointer" />
                <span class="text-slate-400 text-sm font-mono">{{ form.color }}</span>
              </div>
            </div>
            <div class="flex justify-end gap-3">
              <button type="button" @click="showModal = false" class="px-4 py-2 text-sm text-slate-300 bg-white/5 border border-white/10 rounded-xl hover:bg-white/10 transition-all">Cancel</button>
              <button type="submit" :disabled="saving" class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl text-sm shadow-lg disabled:opacity-50 disabled:cursor-not-allowed">{{ editingId ? 'Update' : 'Create' }}</button>
            </div>
          </form>
        </BaseModal>
      </Transition>
    </main>
  </AppLayout>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { describeApiError, fieldError } from '../apiErrors.js';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import BaseModal from '../components/BaseModal.vue';
import { confirm } from '../composables/useConfirm.js';
import { useToast } from '../composables/useToast.js';

const { showToast } = useToast();

const tags = ref([]);
const loading = ref(true);
const showModal = ref(false);
const saving = ref(false);
const editingId = ref(null);
const form = ref({ name: '', color: '#6366f1' });
const nameError = ref('');

function toastError(err, fallback) {
  const e = describeApiError(err, fallback);
  if (!e.silent) showToast(e.message, 'error', 6000);
  return e;
}

async function fetchTags() {
  loading.value = true;
  try {
    tags.value = (await api.get('/api/procedure-tags')).data;
  } catch (err) {
    toastError(err, 'Could not load the tags, try again');
  } finally {
    loading.value = false;
  }
}

function openCreate() {
  editingId.value = null;
  form.value = { name: '', color: '#6366f1' };
  nameError.value = '';
  showModal.value = true;
}

function openEdit(tag) {
  editingId.value = tag.id;
  form.value = { name: tag.name, color: tag.color };
  nameError.value = '';
  showModal.value = true;
}

// A failed save (e.g. a duplicate name, 422) keeps the dialog open with the server's message.
async function saveTag() {
  if (saving.value) return;
  saving.value = true;
  nameError.value = '';
  const editing = editingId.value;
  try {
    if (editing) {
      await api.put(`/api/procedure-tags/${editing}`, form.value);
    } else {
      await api.post('/api/procedure-tags', form.value);
    }
    showModal.value = false;
    showToast(editing ? 'Tag updated' : 'Tag created');
  } catch (err) {
    // A name error (e.g. a duplicate, 422) is shown under the field only; anything else as a toast.
    const e = describeApiError(err, 'Could not save the tag, try again');
    nameError.value = fieldError(e.fieldErrors, 'name');
    if (!e.silent && !nameError.value) showToast(e.message, 'error', 6000);
    return;
  } finally {
    saving.value = false;
  }
  await fetchTags();
}

async function deleteTag(tag) {
  const ok = await confirm({
    title: 'Delete Tag',
    message: `Delete tag "${tag.name}"? Procedures using this tag will be unaffected.`,
    confirmLabel: 'Delete',
    danger: true,
  });
  if (!ok) return;
  try {
    await api.delete(`/api/procedure-tags/${tag.id}`);
  } catch (err) {
    toastError(err, 'Could not delete the tag, try again');
    return;
  }
  showToast('Tag deleted');
  await fetchTags();
}

onMounted(fetchTags);
</script>
