<!--
  "Back-to-Back Shifts" confirmation when a dropped employee already works an adjacent shift
  (moved out of ScheduleView.vue unchanged). `confirm` assigns anyway (the screen passes the
  shifts shown here on, so the server's short-rest 409 about them isn't asked again), `cancel`
  takes the chip back off.
-->
<template>
  <BaseModal
    role="alertdialog"
    overlay-class="fixed inset-0 z-[60] flex items-center justify-center px-4"
    backdrop-class="fixed inset-0 bg-black/60 backdrop-blur-sm"
    panel-class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-amber-500/30 rounded-2xl shadow-2xl shadow-amber-500/10 p-6"
    :close-on-backdrop="false"
    described
    @close="emit('cancel')"
    v-slot="{ titleId, descriptionId }">
    <div class="flex items-start gap-4 mb-5">
      <div class="w-12 h-12 rounded-xl bg-amber-500/20 flex items-center justify-center shrink-0">
        <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <div class="flex-1">
        <h3 :id="titleId" class="text-amber-300 font-bold text-lg mb-1">⏰ Back-to-Back Shifts</h3>
        <p class="text-slate-300 text-sm mb-2">
          <strong class="text-white">{{ adjacentConfirm.userName }}</strong> is already assigned to an adjacent shift:
        </p>
        <div class="space-y-1.5 mb-3">
          <div v-for="(conflict, idx) in adjacentConfirm.conflicts" :key="idx"
            class="flex items-center gap-2 px-3 py-2 bg-amber-500/10 border border-amber-500/20 rounded-lg">
            <span class="text-amber-400 text-sm">🔄</span>
            <div class="text-sm">
              <span class="text-amber-200 font-medium">
                {{ conflict.date }} · S{{ conflict.shiftNumber }}
              </span>
              <span v-if="conflict.startTime" class="text-slate-400 ml-1">
                ({{ conflict.startTime }}–{{ conflict.endTime }})
              </span>
              <span class="text-slate-500 ml-1">— {{ conflict.position }}</span>
            </div>
          </div>
        </div>
        <p :id="descriptionId" class="text-slate-400 text-sm">This employee would work consecutive shifts without a break. Assign anyway?</p>
      </div>
    </div>
    <div class="flex items-center justify-end gap-3">
      <button @click="emit('cancel')"
        class="px-5 py-2.5 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">
        Cancel
      </button>
      <button @click="emit('confirm')"
        class="px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-xl shadow-lg shadow-amber-500/25 transition-all text-sm">
        Assign Anyway
      </button>
    </div>
  </BaseModal>
</template>

<script setup>
import BaseModal from '../BaseModal.vue';

defineProps({
  adjacentConfirm: { type: Object, required: true },
});

const emit = defineEmits(['cancel', 'confirm']);
</script>
