<!--
  Request Day Off dialog for an employee's own shift (moved out of ScheduleView.vue unchanged).
  The reason edits `changeRequestModal` in place; `submit` / `close` are the screen's.
-->
<template>
  <BaseModal
    overlay-class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm"
    panel-class="bg-slate-800/95 border border-white/10 rounded-2xl shadow-2xl w-full max-w-md mx-4"
    :busy="changeRequestModal.submitting"
    @close="emit('close')"
    v-slot="{ titleId }">
    <div class="p-6 pb-3 border-b border-white/10">
      <h3 :id="titleId" class="text-lg font-bold text-white flex items-center gap-2">
        🔄 Request Day Off
      </h3>
      <p class="text-slate-400 text-sm mt-1">
        Shift {{ changeRequestModal.shiftNumber }} on <strong class="text-white">{{ changeRequestModal.dateLabel }}</strong>
      </p>
    </div>
    <div class="p-6 pt-4">
      <label class="block text-sm font-medium text-slate-300 mb-2">Reason <span class="text-red-400">*</span></label>
      <textarea v-model="changeRequestModal.reason" rows="4" placeholder="Please describe why you can't work this shift..."
        class="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/50 placeholder-slate-500 resize-none"></textarea>
      <p v-if="changeRequestModal.error" class="text-red-400 text-xs mt-1">{{ changeRequestModal.error }}</p>
    </div>
    <div class="p-6 pt-0 flex gap-3">
      <button @click="emit('close')"
        class="flex-1 px-4 py-2.5 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">Cancel</button>
      <button @click="emit('submit')" :disabled="changeRequestModal.submitting || !changeRequestModal.reason.trim()"
        class="flex-1 px-4 py-2.5 text-sm text-white bg-orange-600 hover:bg-orange-700 rounded-xl transition-all font-semibold shadow-lg shadow-orange-500/25 disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2">
        <svg v-if="changeRequestModal.submitting" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
        Submit Request
      </button>
    </div>
  </BaseModal>
</template>

<script setup>
import BaseModal from '../BaseModal.vue';

defineProps({
  changeRequestModal: { type: Object, required: true },
});

const emit = defineEmits(['close', 'submit']);
</script>
