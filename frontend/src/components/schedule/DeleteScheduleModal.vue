<!--
  The two-step Delete Schedule confirmation (moved out of ScheduleView.vue unchanged). The screen
  mounts it keyed by `deleteStep`, so step 2 is a new alertdialog; `continue` goes to step 2,
  `confirm` deletes, `close` cancels.
-->
<template>
  <BaseModal
    role="alertdialog"
    overlay-class="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 backdrop-blur-sm"
    panel-class="bg-slate-800/95 border border-red-500/20 rounded-2xl shadow-2xl w-full max-w-sm mx-4"
    described
    :busy="deleting"
    @close="emit('close')"
    v-slot="{ titleId, descriptionId }">
    <!-- Step 1 -->
    <template v-if="deleteStep === 1">
      <div class="p-6 text-center">
        <div class="w-14 h-14 rounded-2xl bg-red-500/15 flex items-center justify-center mx-auto mb-4">
          <svg class="w-7 h-7 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
          </svg>
        </div>
        <h3 :id="titleId" class="text-lg font-bold text-white mb-2">Delete {{ monthLabel }} Schedule?</h3>
        <p :id="descriptionId" class="text-sm text-slate-400">This will remove all shifts and assigned users for this month.</p>
      </div>
      <div class="p-4 pt-0 flex gap-3">
        <button @click="emit('close')" class="flex-1 px-4 py-2.5 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">Cancel</button>
        <button @click="emit('continue')" class="flex-1 px-4 py-2.5 text-sm text-red-300 bg-red-500/15 hover:bg-red-500/25 border border-red-500/25 rounded-xl transition-all font-medium">Yes, continue</button>
      </div>
    </template>
    <!-- Step 2 (final confirm) -->
    <template v-if="deleteStep === 2">
      <div class="p-6 text-center">
        <div class="w-14 h-14 rounded-2xl bg-red-500/20 flex items-center justify-center mx-auto mb-4 animate-pulse">
          <svg class="w-7 h-7 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
        </div>
        <h3 :id="titleId" class="text-lg font-bold text-red-300 mb-2">This cannot be undone!</h3>
        <p :id="descriptionId" class="text-sm text-slate-400">All shifts, assignments, and checklist data for <strong class="text-white">{{ monthLabel }}</strong> will be permanently deleted.</p>
      </div>
      <div class="p-4 pt-0 flex gap-3">
        <button @click="emit('close')" class="flex-1 px-4 py-2.5 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">Cancel</button>
        <button @click="emit('confirm')" :disabled="deleting" class="flex-1 px-4 py-2.5 text-sm text-white bg-red-600 hover:bg-red-700 rounded-xl transition-all font-semibold shadow-lg shadow-red-500/25 disabled:opacity-50 flex items-center justify-center gap-2">
          <svg v-if="deleting" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
          Delete Everything
        </button>
      </div>
    </template>
  </BaseModal>
</template>

<script setup>
import BaseModal from '../BaseModal.vue';

defineProps({
  deleteStep: { type: Number, required: true },
  monthLabel: { type: String, required: true },
  deleting: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'continue', 'confirm']);
</script>
