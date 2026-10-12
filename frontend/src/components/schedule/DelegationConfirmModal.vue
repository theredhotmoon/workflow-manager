<!--
  "On Delegation" confirmation when a dropped employee is on a delegation that day (moved out
  of ScheduleView.vue unchanged). `confirm` assigns anyway, `cancel` takes the chip back off.
-->
<template>
  <BaseModal
    role="alertdialog"
    overlay-class="fixed inset-0 z-[60] flex items-center justify-center px-4"
    backdrop-class="fixed inset-0 bg-black/60 backdrop-blur-sm"
    panel-class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-purple-500/30 rounded-2xl shadow-2xl shadow-purple-500/10 p-6"
    :close-on-backdrop="false"
    described
    @close="emit('cancel')"
    v-slot="{ titleId, descriptionId }">
    <div class="flex items-start gap-4 mb-5">
      <div class="w-12 h-12 rounded-xl bg-purple-500/20 flex items-center justify-center shrink-0">
        <svg class="w-6 h-6 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
      </div>
      <div class="flex-1">
        <h3 :id="titleId" class="text-purple-300 font-bold text-lg mb-1">📍 On Delegation</h3>
        <p class="text-slate-300 text-sm mb-2">
          <strong class="text-white">{{ delegConfirm.userName }}</strong> is on delegation during
          <strong class="text-purple-300">{{ delegConfirm.dateStr }}</strong>.
        </p>
        <div v-if="delegConfirm.delegations.length" class="space-y-1.5 mb-3">
          <div v-for="(del, idx) in delegConfirm.delegations" :key="idx"
            class="flex items-center gap-2 px-3 py-2 bg-purple-500/10 border border-purple-500/20 rounded-lg">
            <span class="text-purple-400 text-sm">📍</span>
            <div class="text-sm">
              <span class="text-purple-200 font-medium">{{ del.name }}</span>
              <span class="text-slate-400 ml-2">— {{ del.destination }}</span>
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
        class="px-5 py-2.5 bg-purple-500 hover:bg-purple-600 text-white font-semibold rounded-xl shadow-lg shadow-purple-500/25 transition-all text-sm">
        Assign Anyway
      </button>
    </div>
  </BaseModal>
</template>

<script setup>
import BaseModal from '../BaseModal.vue';

defineProps({
  delegConfirm: { type: Object, required: true },
});

const emit = defineEmits(['cancel', 'confirm']);
</script>
