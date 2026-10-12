<!--
  "Unavailable Period" confirmation when a dropped employee marked the day unavailable (moved
  out of ScheduleView.vue unchanged). `confirm` assigns anyway, `cancel` takes the chip back off.
-->
<template>
  <BaseModal
    role="alertdialog"
    overlay-class="fixed inset-0 z-[60] flex items-center justify-center px-4"
    backdrop-class="fixed inset-0 bg-black/60 backdrop-blur-sm"
    panel-class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-red-500/30 rounded-2xl shadow-2xl shadow-red-500/10 p-6"
    :close-on-backdrop="false"
    described
    @close="emit('cancel')"
    v-slot="{ titleId, descriptionId }">
    <div class="flex items-start gap-4 mb-5">
      <div class="w-12 h-12 rounded-xl bg-red-500/20 flex items-center justify-center shrink-0">
        <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
        </svg>
      </div>
      <div class="flex-1">
        <h3 :id="titleId" class="text-red-300 font-bold text-lg mb-1">⚠️ Unavailable Period</h3>
        <p class="text-slate-300 text-sm mb-2">
          <strong class="text-white">{{ availConfirm.userName }}</strong> has marked
          <strong class="text-red-300">{{ availConfirm.dateStr }}</strong> as unavailable.
        </p>
        <div v-if="availConfirm.entries.length" class="space-y-1.5 mb-3">
          <div v-for="(entry, idx) in availConfirm.entries" :key="idx"
            class="flex items-center gap-2 px-3 py-2 bg-red-500/10 border border-red-500/20 rounded-lg">
            <span class="text-red-400 text-sm">🚫</span>
            <div class="text-sm">
              <span class="text-red-200 font-medium">{{ entry.start_time ? entry.start_time + '–' + entry.end_time : 'All day' }}</span>
              <span v-if="entry.note" class="text-slate-400 ml-2">— "{{ entry.note }}"</span>
            </div>
          </div>
        </div>
        <p :id="descriptionId" class="text-slate-400 text-sm">Do you still want to assign this person to the shift?</p>
      </div>
    </div>
    <div class="flex items-center justify-end gap-3">
      <button @click="emit('cancel')"
        class="px-5 py-2.5 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">
        Cancel
      </button>
      <button @click="emit('confirm')"
        class="px-5 py-2.5 bg-red-500 hover:bg-red-600 text-white font-semibold rounded-xl shadow-lg shadow-red-500/25 transition-all text-sm">
        Assign Anyway
      </button>
    </div>
  </BaseModal>
</template>

<script setup>
import BaseModal from '../BaseModal.vue';

defineProps({
  availConfirm: { type: Object, required: true },
});

const emit = defineEmits(['cancel', 'confirm']);
</script>
