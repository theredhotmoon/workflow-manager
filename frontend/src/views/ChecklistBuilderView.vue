<template>
  <AppLayout>
    <!-- Main Content -->
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-white">Checklist Templates</h1>
          <p class="text-slate-400 mt-1">Build and manage shift handover checklists</p>
        </div>
        <button @click="openCreateModal"
          class="px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-500/25 transition-all duration-200 flex items-center gap-2 text-sm">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
          </svg>
          New Template
        </button>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-20">
        <svg class="animate-spin w-8 h-8 text-indigo-400" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
      </div>

      <!-- Templates List -->
      <div v-else-if="templates.length === 0" class="text-center py-20">
        <svg class="w-16 h-16 text-slate-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </svg>
        <p class="text-slate-400 text-lg mb-2">No templates yet</p>
        <p class="text-slate-500 text-sm">Create your first checklist template to get started.</p>
      </div>

      <div v-else class="space-y-3">
        <div v-for="tpl in templates" :key="tpl.id"
          class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5 hover:bg-white/[0.07] transition-all duration-200">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-indigo-500/15 flex items-center justify-center">
                <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                </svg>
              </div>
              <div>
                <h3 class="text-white font-semibold text-sm">{{ tpl.name }}</h3>
                <p class="text-slate-500 text-xs mt-0.5">{{ tpl.groups_count }} group{{ tpl.groups_count !== 1 ? 's' : '' }}</p>
              </div>
              <span :class="[
                'px-2 py-0.5 text-[10px] font-semibold uppercase rounded-full',
                tpl.is_active ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/20' : 'bg-slate-500/15 text-slate-400 border border-slate-500/20'
              ]">
                {{ tpl.is_active ? 'Active' : 'Inactive' }}
              </span>
            </div>
            <div class="flex items-center gap-2">
              <button @click="editTemplate(tpl)"
                class="px-3 py-1.5 text-xs font-medium text-indigo-400 hover:text-indigo-300 bg-indigo-500/10 hover:bg-indigo-500/20 border border-indigo-500/20 rounded-lg transition-all">
                Edit
              </button>
              <button @click="deleteTemplate(tpl)"
                class="px-3 py-1.5 text-xs font-medium text-red-400 hover:text-red-300 bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 rounded-lg transition-all">
                Delete
              </button>
            </div>
          </div>
          <p v-if="tpl.description" class="text-slate-400 text-xs mt-3 pl-[52px]">{{ tpl.description }}</p>
        </div>
      </div>
    </main>

    <!-- Create/Edit Modal -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      leave-active-class="transition-all duration-200 ease-in"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-start justify-center pt-10 px-4" @click.self="showModal = false">
        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="showModal = false"></div>
        <div ref="templateModalRef"
          role="dialog"
          aria-modal="true"
          aria-labelledby="template-modal-title"
          class="relative w-full max-w-7xl max-h-[85vh] overflow-y-auto bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50">
          <!-- Modal Header -->
          <div class="sticky top-0 z-10 bg-slate-800/95 backdrop-blur-xl border-b border-white/10 px-6 py-4 flex items-center justify-between">
            <h2 id="template-modal-title" class="text-lg font-bold text-white">{{ editingTemplate ? 'Edit Template' : 'Create Template' }}</h2>
            <button @click="showModal = false"
              aria-label="Close modal"
              class="text-slate-400 hover:text-white transition-colors">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div class="px-6 py-5 space-y-5">
            <!-- Template Name -->
            <div>
              <label class="block text-sm font-medium text-slate-300 mb-1.5">Template Name <span class="text-red-400 ml-0.5">*</span></label>
              <input v-model="form.name" type="text" placeholder="e.g. Daily Shift Handover"
                @blur="touchedName = true"
                :class="['w-full px-4 py-2.5 bg-white/5 border rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 text-sm',
                  nameError ? 'border-red-500/40 focus:ring-red-500/30' : 'border-white/10 focus:ring-indigo-500/50 focus:border-indigo-500/50']" />
              <p v-if="nameError" class="mt-1 text-xs text-red-400">{{ nameError }}</p>
            </div>

            <!-- Description -->
            <div>
              <label class="block text-sm font-medium text-slate-300 mb-1.5">Description <span class="text-slate-500 font-normal">(optional)</span></label>
              <textarea v-model="form.description" rows="2" placeholder="Brief description..."
                class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 text-sm resize-none"></textarea>
            </div>

            <!-- Active Toggle -->
            <div class="flex items-center gap-3">
              <button @click="form.is_active = !form.is_active"
                :class="[
                  'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                  form.is_active ? 'bg-indigo-500' : 'bg-slate-600'
                ]">
                <span :class="[
                  'inline-block h-4 w-4 rounded-full bg-white transition-transform',
                  form.is_active ? 'translate-x-6' : 'translate-x-1'
                ]"></span>
              </button>
              <span class="text-sm text-slate-300">Active</span>
            </div>

            <!-- Groups -->
            <div>
              <div class="flex items-center justify-between mb-3">
                <label class="text-sm font-medium text-slate-300">Groups & Items</label>
                <button @click="addGroup"
                  class="px-3 py-1.5 text-xs font-medium text-indigo-400 bg-indigo-500/10 hover:bg-indigo-500/20 border border-indigo-500/20 rounded-lg transition-all flex items-center gap-1">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12m6-6H6" />
                  </svg>
                  Add Group
                </button>
              </div>

              <div class="space-y-3">
                <div v-for="(group, gi) in form.groups" :key="gi"
                  class="bg-white/[0.03] border border-white/10 rounded-xl overflow-hidden">
                  <!-- Group Header (always visible — click chevron to expand/collapse) -->
                  <div class="flex items-center gap-2 px-4 py-3">
                    <!-- Accordion toggle -->
                    <button @click="collapsedGroups.has(gi) ? collapsedGroups.delete(gi) : collapsedGroups.add(gi)"
                      :title="collapsedGroups.has(gi) ? 'Expand group' : 'Collapse group'"
                      class="w-5 h-5 flex items-center justify-center text-slate-500 hover:text-white transition-colors shrink-0">
                      <svg :class="['w-3.5 h-3.5 transition-transform duration-150', collapsedGroups.has(gi) ? '' : 'rotate-90']"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                      </svg>
                    </button>
                    <span class="text-slate-500 text-xs font-medium min-w-[20px] shrink-0">{{ gi + 1 }}.</span>
                    <input v-model="group.name" type="text" :placeholder="'Group ' + (gi + 1) + ' name...'"
                      class="flex-1 px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-indigo-500/50 text-sm" />
                    <!-- Item count badge — only when collapsed -->
                    <span v-if="collapsedGroups.has(gi)"
                      class="px-2 py-0.5 text-[10px] font-medium bg-white/5 text-slate-400 border border-white/10 rounded-full shrink-0">
                      {{ group.items.length }} item{{ group.items.length !== 1 ? 's' : '' }}
                    </span>
                    <button @click="moveGroup(gi, -1)" :disabled="gi === 0"
                      class="p-1.5 text-slate-500 hover:text-slate-300 disabled:opacity-30 transition-colors" title="Move up">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" /></svg>
                    </button>
                    <button @click="moveGroup(gi, 1)" :disabled="gi === form.groups.length - 1"
                      class="p-1.5 text-slate-500 hover:text-slate-300 disabled:opacity-30 transition-colors" title="Move down">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                    </button>
                    <button @click="removeGroup(gi)" :disabled="form.groups.length <= 1"
                      class="p-1.5 text-red-400/60 hover:text-red-400 disabled:opacity-30 transition-colors" title="Remove group">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                    </button>
                  </div>

                  <!-- Items (collapsible) -->
                  <div v-if="!collapsedGroups.has(gi)" class="px-4 pb-4 border-t border-white/5 pt-3">
                  <div class="space-y-2 ml-7">
                    <div v-for="(item, ii) in group.items" :key="ii" class="flex items-center gap-2">
                      <input v-model="item.label" type="text" :placeholder="'Item ' + (ii + 1) + '...'"
                        class="flex-1 min-w-0 px-3 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-indigo-500/50 text-xs" />
                      <select v-model="item.type"
                        class="px-2 py-1.5 bg-white/5 border border-white/10 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500/50 cursor-pointer shrink-0">
                        <option value="checkbox">Checkbox</option>
                        <option value="text">Text</option>
                        <option value="number">Number</option>
                      </select>
                      <select v-model="item.procedure_id"
                        class="px-2 py-1.5 bg-white/5 border border-white/10 rounded-lg text-slate-300 text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500/50 cursor-pointer w-[140px] shrink-0" title="Link a procedure">
                        <option :value="null" class="bg-slate-800">No procedure</option>
                        <option v-for="proc in procedures" :key="proc.id" :value="proc.id" class="bg-slate-800">{{ proc.identifier }} — {{ proc.title }}</option>
                      </select>
                      <button @click="item.requires_confirmation = !item.requires_confirmation"
                        :class="[
                          'px-2 py-1 text-[10px] font-semibold uppercase rounded-md border transition-all whitespace-nowrap shrink-0',
                          item.requires_confirmation
                            ? 'bg-amber-500/15 text-amber-400 border-amber-500/20'
                            : 'bg-white/5 text-slate-500 border-white/10 hover:text-slate-300'
                        ]"
                        :title="item.requires_confirmation ? 'Requires confirmation by incoming user' : 'Single user only'">
                        {{ item.requires_confirmation ? '2-User' : '1-User' }}
                      </button>
                      <!-- Range toggle + inline min/max — only for number type -->
                      <template v-if="item.type === 'number'">
                        <button @click="toggleItemRange(item)"
                          :class="[
                            'px-2 py-1 text-[10px] font-semibold uppercase rounded-md border transition-all whitespace-nowrap shrink-0',
                            (item.min_value !== null || item.max_value !== null)
                              ? 'bg-violet-500/15 text-violet-400 border-violet-500/20'
                              : 'bg-white/5 text-slate-500 border-white/10 hover:text-slate-300'
                          ]"
                          title="Restrict to allowed value range">
                          Range
                        </button>
                        <template v-if="item.min_value !== null || item.max_value !== null">
                          <input v-model="item.min_value" type="number" placeholder="Min"
                            class="w-20 shrink-0 px-2 py-1.5 bg-white/5 border border-violet-500/20 rounded-lg text-white placeholder-slate-500 text-xs text-right focus:outline-none focus:ring-1 focus:ring-violet-500/40 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none" />
                          <span class="text-slate-500 text-xs shrink-0">–</span>
                          <input v-model="item.max_value" type="number" placeholder="Max"
                            class="w-20 shrink-0 px-2 py-1.5 bg-white/5 border border-violet-500/20 rounded-lg text-white placeholder-slate-500 text-xs text-right focus:outline-none focus:ring-1 focus:ring-violet-500/40 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none" />
                        </template>
                      </template>
                      <button @click="moveItem(group, ii, -1)" :disabled="ii === 0"
                        class="p-1 text-slate-500 hover:text-slate-300 disabled:opacity-30 transition-colors shrink-0">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" /></svg>
                      </button>
                      <button @click="moveItem(group, ii, 1)" :disabled="ii === group.items.length - 1"
                        class="p-1 text-slate-500 hover:text-slate-300 disabled:opacity-30 transition-colors shrink-0">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                      </button>
                      <button @click="removeItem(group, ii)" :disabled="group.items.length <= 1"
                        class="p-1 text-red-400/60 hover:text-red-400 disabled:opacity-30 transition-colors shrink-0">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                      </button>
                    </div>
                    <button @click="addItem(group)"
                      class="text-xs text-indigo-400/70 hover:text-indigo-400 transition-colors flex items-center gap-1 mt-1">
                      <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12m6-6H6" />
                      </svg>
                      Add item
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Modal Footer -->
          <div class="sticky bottom-0 bg-slate-800/95 backdrop-blur-xl border-t border-white/10 px-6 py-4 flex items-center justify-end gap-3">
            <button @click="showModal = false"
              class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">
              Cancel
            </button>
            <button @click="saveTemplate" :disabled="saving"
              class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
              <svg v-if="saving" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
              </svg>
              {{ editingTemplate ? 'Save Changes' : 'Create Template' }}
            </button>
          </div>
        </div>
      </div>
    </div>
    </Transition>

    <AppToast />
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { confirm } from '../composables/useConfirm.js';
import { useToast } from '../composables/useToast.js';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import AppToast from '../components/AppToast.vue';
import { useFocusTrap } from '../composables/useFocusTrap.js';

const router = useRouter();

// State
const loading = ref(true);
const saving = ref(false);
const templates = ref([]);
const showModal = ref(false);
const editingTemplate = ref(null);

// Focus trap for the Create/Edit Template modal
const templateModalRef = ref(null);
useFocusTrap(templateModalRef, showModal, {
  onEscape: () => {
    showModal.value = false;
  },
});

const defaultItem = () => ({
  label: '',
  type: 'checkbox',
  requires_confirmation: false,
  procedure_id: null,
  min_value: null,
  max_value: null,
});
const defaultGroup = () => ({ name: '', items: [defaultItem()] });
const defaultForm = () => ({
  name: '',
  description: '',
  is_active: true,
  groups: [defaultGroup()],
});

const form = ref(defaultForm());
const procedures = ref([]);
// Track which group indexes are collapsed in the accordion
const collapsedGroups = reactive(new Set());

// Validation
const touchedName = ref(false);
const nameError = computed(() =>
  touchedName.value && !form.value.name.trim() ? 'Template name is required.' : '',
);

// Toast
const { showToast } = useToast();

// Fetch templates
async function fetchTemplates() {
  loading.value = true;
  try {
    const response = await api.get('/api/checklist-templates');
    templates.value = response.data;
  } catch {
    showToast('Failed to load templates.', 'error');
  } finally {
    loading.value = false;
  }
}

// Modal helpers
function openCreateModal() {
  editingTemplate.value = null;
  form.value = defaultForm();
  touchedName.value = false;
  collapsedGroups.clear();
  showModal.value = true;
}

async function editTemplate(tpl) {
  editingTemplate.value = tpl;
  try {
    const response = await api.get(`/api/checklist-templates/${tpl.id}`);
    const data = response.data;
    touchedName.value = false;
    collapsedGroups.clear();
    form.value = {
      name: data.name,
      description: data.description || '',
      is_active: data.is_active,
      groups: data.groups.map((g) => ({
        name: g.name,
        items: g.items.map((i) => ({
          label: i.label,
          type: i.type,
          requires_confirmation: i.requires_confirmation,
          procedure_id: i.procedure_id || null,
          min_value: i.min_value ?? null,
          max_value: i.max_value ?? null,
        })),
      })),
    };
    showModal.value = true;
  } catch {
    showToast('Failed to load template.', 'error');
  }
}

// Group/Item management
function addGroup() {
  // Collapse all existing groups so the new one stands out
  for (let i = 0; i < form.value.groups.length; i++) collapsedGroups.add(i);
  form.value.groups.push(defaultGroup());
  // New group's index is not in the Set, so it renders expanded
}

function removeGroup(index) {
  if (form.value.groups.length > 1) {
    form.value.groups.splice(index, 1);
    // Rebuild collapsed state: shift indexes above the removed one down by 1
    const updated = new Set();
    for (const i of collapsedGroups) {
      if (i < index) updated.add(i);
      else if (i > index) updated.add(i - 1);
      // i === index is dropped (the group is gone)
    }
    collapsedGroups.clear();
    for (const i of updated) collapsedGroups.add(i);
  }
}

function moveGroup(index, direction) {
  const newIndex = index + direction;
  if (newIndex < 0 || newIndex >= form.value.groups.length) return;
  const groups = form.value.groups;
  [groups[index], groups[newIndex]] = [groups[newIndex], groups[index]];
  // Swap collapsed state to match the moved groups
  const aCollapsed = collapsedGroups.has(index);
  const bCollapsed = collapsedGroups.has(newIndex);
  aCollapsed ? collapsedGroups.add(newIndex) : collapsedGroups.delete(newIndex);
  bCollapsed ? collapsedGroups.add(index) : collapsedGroups.delete(index);
}

function addItem(group) {
  group.items.push(defaultItem());
}

function removeItem(group, index) {
  if (group.items.length > 1) {
    group.items.splice(index, 1);
  }
}

function toggleItemRange(item) {
  const isEnabled = item.min_value !== null || item.max_value !== null;
  item.min_value = isEnabled ? null : '';
  item.max_value = isEnabled ? null : '';
}

function moveItem(group, index, direction) {
  const newIndex = index + direction;
  if (newIndex < 0 || newIndex >= group.items.length) return;
  [group.items[index], group.items[newIndex]] = [group.items[newIndex], group.items[index]];
}

// Save
async function saveTemplate() {
  // Validate
  if (!form.value.name.trim()) {
    showToast('Template name is required.', 'error');
    return;
  }
  for (const group of form.value.groups) {
    if (!group.name.trim()) {
      showToast('All groups must have a name.', 'error');
      return;
    }
    for (const item of group.items) {
      if (!item.label.trim()) {
        showToast('All items must have a label.', 'error');
        return;
      }
    }
  }

  saving.value = true;
  try {
    const toNum = (v) => (v !== null && v !== '' ? Number(v) : null);
    const payload = {
      name: form.value.name,
      description: form.value.description,
      is_active: form.value.is_active,
      groups: form.value.groups.map((g) => ({
        name: g.name,
        items: g.items.map((i) => ({
          label: i.label,
          type: i.type,
          requires_confirmation: i.requires_confirmation,
          procedure_id: i.procedure_id,
          min_value: i.type === 'number' ? toNum(i.min_value) : null,
          max_value: i.type === 'number' ? toNum(i.max_value) : null,
        })),
      })),
    };
    if (editingTemplate.value) {
      await api.put(`/api/checklist-templates/${editingTemplate.value.id}`, payload);
      showToast('Template updated successfully.');
    } else {
      await api.post('/api/checklist-templates', payload);
      showToast('Template created successfully.');
    }
    showModal.value = false;
    await fetchTemplates();
  } catch (err) {
    const msg = err.response?.data?.message || 'Failed to save template.';
    showToast(msg, 'error');
  } finally {
    saving.value = false;
  }
}

// Delete
async function deleteTemplate(tpl) {
  const ok = await confirm({
    title: 'Delete Template',
    message: `Delete template "${tpl.name}"? This cannot be undone.`,
    confirmLabel: 'Delete',
    danger: true,
  });
  if (!ok) return;
  try {
    await api.delete(`/api/checklist-templates/${tpl.id}`);
    showToast('Template deleted.');
    await fetchTemplates();
  } catch {
    showToast('Failed to delete template.', 'error');
  }
}

onMounted(async () => {
  await fetchTemplates();
  try {
    const procResponse = await api.get('/api/procedures', { params: { per_page: 100 } });
    procedures.value = procResponse.data?.data ?? procResponse.data ?? [];
  } catch {}
});
</script>
