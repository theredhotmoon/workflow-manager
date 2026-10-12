<!--
  Shift Checklists dialog of the Schedule screen (moved out of ScheduleView.vue unchanged).
  The rows edit `checklistForm` in place; `save` / `close` are the screen's.
-->
<template>
  <BaseModal
    overlay-class="fixed inset-0 z-50 flex items-center justify-center px-4"
    backdrop-class="fixed inset-0 bg-black/50 backdrop-blur-sm"
    panel-class="relative w-full max-w-lg bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 p-6"
    :busy="savingChecklist"
    @close="emit('close')"
    v-slot="{ titleId }">
    <h3 :id="titleId" class="text-white font-bold text-lg mb-1">Shift Checklists</h3>
    <p class="text-slate-400 text-sm mb-5">{{ checklistConfigDate }} · Shift {{ checklistConfigShift?.shift_number }}</p>

    <!-- Checklist rows -->
    <div class="space-y-3 mb-4 max-h-[50vh] overflow-y-auto">
      <div v-for="(item, idx) in checklistForm" :key="idx"
        class="bg-white/[0.03] border border-white/10 rounded-xl p-3 space-y-2">
        <div class="flex items-center gap-2">
          <span class="text-[10px] text-slate-500 font-bold w-5 shrink-0">#{{ idx + 1 }}</span>
          <select v-model="item.checklist_template_id"
            class="flex-1 px-3 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 cursor-pointer">
            <option :value="null" class="bg-slate-800 text-slate-400">Select template...</option>
            <option v-for="t in checklistTemplates" :key="t.id" :value="t.id" class="bg-slate-800">{{ t.name }}</option>
          </select>
          <button @click="checklistForm.splice(idx, 1)" class="p-1 text-slate-500 hover:text-red-400 transition-colors" title="Remove">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
          </button>
        </div>
        <div v-if="item.checklist_template_id" class="flex items-center gap-3 flex-wrap">
          <!-- Required toggle -->
          <label class="flex items-center gap-1.5 cursor-pointer">
            <input type="checkbox" v-model="item.is_required"
              class="w-3.5 h-3.5 rounded border-white/20 bg-white/5 text-emerald-500 focus:ring-emerald-500/30" />
            <span class="text-[10px] text-slate-400 uppercase tracking-wide">Required</span>
          </label>
          <!-- Deadline -->
          <div class="flex items-center gap-1.5">
            <span class="text-[10px] text-slate-500 uppercase">Deadline:</span>
            <input v-model.number="item.deadline_minutes" type="number" min="1" placeholder="min"
              class="w-16 px-2 py-1 bg-white/5 border border-white/10 rounded-lg text-white text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
            <select v-model="item.deadline_reference"
              class="px-2 py-1 bg-white/5 border border-white/10 rounded-lg text-white text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500/50 cursor-pointer">
              <option value="shift_start" class="bg-slate-800">after start</option>
              <option value="shift_end" class="bg-slate-800">before end</option>
            </select>
          </div>
        </div>
      </div>
      <div v-if="!checklistForm.length" class="text-center text-slate-500 text-sm py-4">
        No checklists assigned. Click "Add Checklist" below.
      </div>
    </div>

    <!-- Add button -->
    <button @click="checklistForm.push({ checklist_template_id: null, is_required: false, deadline_minutes: null, deadline_reference: 'shift_start' })"
      class="w-full mb-5 px-3 py-2 text-sm text-indigo-400 bg-indigo-500/10 hover:bg-indigo-500/20 border border-indigo-500/20 rounded-xl transition-all">
      + Add Checklist
    </button>

    <!-- Actions -->
    <div class="flex items-center justify-end gap-3">
      <button @click="emit('close')"
        class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">
        Cancel
      </button>
      <button @click="emit('save')" :disabled="savingChecklist"
        class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
        <svg v-if="savingChecklist" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        Save
      </button>
    </div>
  </BaseModal>
</template>

<script setup>
import BaseModal from '../BaseModal.vue';

defineProps({
  checklistConfigShift: { type: Object, default: null },
  checklistConfigDate: { type: String, default: '' },
  checklistForm: { type: Array, required: true },
  checklistTemplates: { type: Array, required: true },
  savingChecklist: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'save']);
</script>
