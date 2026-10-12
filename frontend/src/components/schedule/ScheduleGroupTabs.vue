<!--
  The Schedule screen's shift group tabs: All Groups and one tab per active group, inline rename
  (double-click, admins), archive and settlement settings on the active tab, New Group, and the
  Archived list with Restore (moved out of ScheduleView.vue unchanged). The screen (useShiftGroups)
  does the work: this only shows the groups and emits what was asked for. The rename and new-group
  fields and the two toggles are v-models of the screen's state.
-->
<template>
  <div class="mb-4">
    <div class="flex items-center gap-2 flex-wrap">
      <!-- All Groups tab -->
      <button @click="emit('switch-group', 'all')"
        :class="['flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-xl border transition-all duration-200',
          isAllGroupsMode
            ? 'border-transparent text-white ring-1 ring-purple-500/40 shadow-lg shadow-purple-500/10'
            : 'bg-white/5 border-white/10 text-slate-400 hover:bg-white/10 hover:text-slate-300 hover:border-white/20']"
        :style="isAllGroupsMode ? { background: 'linear-gradient(135deg, rgba(99,102,241,0.25), rgba(168,85,247,0.25), rgba(236,72,153,0.15))' } : {}">
        <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background: linear-gradient(135deg, #6366f1, #a855f7, #ec4899)"></span>
        All Groups
      </button>

      <button v-for="group in shiftGroups" :key="group.id"
        @click="emit('switch-group', group.id)"
        :class="['flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-xl border transition-all duration-200',
          activeGroupId === group.id
            ? 'bg-indigo-500/20 border-indigo-500/40 text-indigo-300 ring-1 ring-indigo-500/30 shadow-lg shadow-indigo-500/10'
            : 'bg-white/5 border-white/10 text-slate-400 hover:bg-white/10 hover:text-slate-300 hover:border-white/20']">
        <span v-if="group.color" class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ backgroundColor: group.color }"></span>
        <span v-else class="w-2.5 h-2.5 rounded-full shrink-0 bg-slate-500/50"></span>

        <!-- Inline rename -->
        <template v-if="isAdmin(currentUser) && editingGroupId === group.id">
          <input v-model="editingGroupName" type="text" @keyup.enter="emit('save-rename')" @keyup.escape="editingGroupId = null" @blur="emit('save-rename')"
            class="w-24 px-1.5 py-0.5 bg-white/10 border border-indigo-500/30 rounded text-white text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500/50"
            ref="groupRenameInput" />
        </template>
        <template v-else>
          <span @dblclick.stop="isAdmin(currentUser) && emit('start-rename', group)">{{ group.name }}</span>
        </template>

        <span v-if="activeGroupId === group.id && group.is_default" class="text-[8px] text-indigo-400/60 uppercase tracking-wider">default</span>

        <!-- Archive group button (admin only, non-default) -->
        <button v-if="isAdmin(currentUser) && !group.is_default && activeGroupId === group.id"
          @click.stop="emit('archive', group)"
          class="w-4 h-4 flex items-center justify-center text-slate-500 hover:text-amber-400 transition-colors ml-1"
          title="Archive group">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
        </button>

        <!-- Group settlement settings (admin only: saving writes the group) -->
        <button v-if="isAdmin(currentUser) && activeGroupId === group.id"
          @click.stop="emit('open-settings', group)"
          class="w-4 h-4 flex items-center justify-center text-slate-500 hover:text-indigo-400 transition-colors ml-1"
          title="Settlement period settings">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        </button>
      </button>

      <!-- New Group button (admin only) -->
      <button v-if="isAdmin(currentUser)" @click="showNewGroupInput = !showNewGroupInput"
        class="flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-xl border border-dashed border-white/15 text-slate-500 hover:text-indigo-400 hover:border-indigo-500/30 hover:bg-indigo-500/5 transition-all">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
        New Group
      </button>

      <!-- Archived groups (admin only) -->
      <button v-if="isAdmin(currentUser) && archivedGroups.length > 0" @click="showArchivedGroups = !showArchivedGroups"
        :aria-expanded="showArchivedGroups ? 'true' : 'false'"
        class="flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-xl border border-white/10 text-slate-500 hover:text-slate-300 hover:bg-white/5 transition-all">
        Archived ({{ archivedGroups.length }})
      </button>
    </div>

    <!-- Archived groups list: their history stays; restoring brings the tab back -->
    <div v-if="showArchivedGroups && isAdmin(currentUser) && archivedGroups.length > 0"
      class="mt-3 p-3 bg-white/[0.03] border border-white/10 rounded-xl">
      <p class="text-xs text-slate-500 mb-2">Archived groups keep their past shifts, hours and on-call duties. Restore one to plan in it again.</p>
      <ul class="flex flex-col gap-1.5" aria-label="Archived shift groups">
        <li v-for="group in archivedGroups" :key="group.id" class="flex items-center gap-2 text-sm text-slate-300">
          <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ backgroundColor: group.color || '#64748b' }"></span>
          <span>{{ group.name }}</span>
          <button @click="emit('restore', group)" :disabled="restoringGroupId === group.id"
            class="ml-auto px-2.5 py-1 text-xs font-medium text-indigo-300 hover:text-white bg-indigo-500/10 hover:bg-indigo-500/20 border border-indigo-500/30 rounded-lg transition-all disabled:opacity-40">
            Restore {{ group.name }}
          </button>
        </li>
      </ul>
    </div>

    <!-- New group inline form -->
    <Transition enter-active-class="transition-all duration-200 ease-out" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition-all duration-150 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="showNewGroupInput && isAdmin(currentUser)" class="mt-3 flex items-center gap-2">
        <input v-model="newGroupColor" type="color" class="w-8 h-8 rounded-lg border border-white/10 cursor-pointer bg-transparent shrink-0" />
        <input v-model="newGroupName" type="text" placeholder="Group name (e.g. Event Coverage)"
          class="flex-1 px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500"
          @keyup.enter="emit('create')" />
        <button @click="emit('create')" :disabled="creatingGroup || !newGroupName.trim()"
          class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition-all text-sm disabled:opacity-40 disabled:cursor-not-allowed">Create</button>
        <button @click="showNewGroupInput = false" class="px-3 py-2 text-sm text-slate-400 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">Cancel</button>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { isAdmin } from '../../roles.js';
import { currentUser } from '../../router/index.js';

defineProps({
  shiftGroups: { type: Array, required: true },
  archivedGroups: { type: Array, required: true },
  activeGroupId: { type: [Number, String], default: null },
  isAllGroupsMode: { type: Boolean, default: false },
  restoringGroupId: { type: Number, default: null },
  creatingGroup: { type: Boolean, default: false },
});

const editingGroupId = defineModel('editingGroupId', { type: Number, default: null });
const editingGroupName = defineModel('editingGroupName', { type: String, default: '' });
const showNewGroupInput = defineModel('showNewGroupInput', { type: Boolean, default: false });
const showArchivedGroups = defineModel('showArchivedGroups', { type: Boolean, default: false });
const newGroupName = defineModel('newGroupName', { type: String, default: '' });
const newGroupColor = defineModel('newGroupColor', { type: String, default: '' });

const emit = defineEmits([
  'switch-group',
  'start-rename',
  'save-rename',
  'archive',
  'open-settings',
  'restore',
  'create',
]);
</script>
