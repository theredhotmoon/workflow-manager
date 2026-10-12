<!--
  "Office Closed on Some Dates" before generating office shifts (moved out of ScheduleView.vue
  unchanged). `generate-remote` switches the closed dates to remote after generating,
  `generate-office` generates as is, `cancel` generates nothing.
-->
<template>
  <BaseModal
    role="alertdialog"
    overlay-class="fixed inset-0 z-[60] flex items-center justify-center px-4"
    backdrop-class="fixed inset-0 bg-black/60 backdrop-blur-sm"
    panel-class="relative w-full max-w-lg bg-slate-800/95 backdrop-blur-xl border border-orange-500/30 rounded-2xl shadow-2xl shadow-orange-500/10 p-6"
    :close-on-backdrop="false"
    @close="emit('cancel')"
    v-slot="{ titleId }">
    <div class="flex items-start gap-4 mb-5">
      <div class="w-12 h-12 rounded-xl bg-orange-500/20 flex items-center justify-center shrink-0">
        <svg class="w-6 h-6 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
        </svg>
      </div>
      <div class="flex-1">
        <h3 :id="titleId" class="text-orange-300 font-bold text-lg mb-1">🏢❌ Office Closed on Some Dates</h3>
        <p class="text-slate-300 text-sm mb-3">
          You're generating <strong class="text-amber-300">office shifts</strong> but the office is
          <strong class="text-orange-300">closed</strong> on the following dates:
        </p>
        <div class="space-y-1.5 mb-3 max-h-[200px] overflow-y-auto">
          <div v-for="c in closureConflict.closures" :key="c.id"
            class="flex items-center gap-2 px-3 py-2 bg-orange-500/10 border border-orange-500/20 rounded-lg">
            <span class="text-orange-400 text-sm">📅</span>
            <div class="text-sm flex-1">
              <span class="text-orange-200 font-medium">{{ formatClosureDate(c.date) }}</span>
              <span class="text-white ml-2">— {{ c.title }}</span>
              <p v-if="c.description" class="text-slate-500 text-[10px] mt-0.5">{{ c.description }}</p>
            </div>
          </div>
        </div>
        <p class="text-slate-400 text-sm">These shifts will still be created as "office" type. You can change them individually later.</p>
      </div>
    </div>
    <div class="flex items-center justify-end gap-2 flex-wrap">
      <button @click="emit('cancel')"
        class="px-4 py-2.5 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">
        Cancel
      </button>
      <button @click="emit('generate-remote')"
        class="px-4 py-2.5 bg-slate-600 hover:bg-slate-500 text-white font-semibold rounded-xl transition-all text-sm flex items-center gap-1.5">
        🏠 Switch to Remote
      </button>
      <button @click="emit('generate-office')"
        class="px-4 py-2.5 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm">
        Generate as Office
      </button>
    </div>
  </BaseModal>
</template>

<script setup>
import { formatClosureDate } from '../../schedule/helpers.js';
import BaseModal from '../BaseModal.vue';

defineProps({
  closureConflict: { type: Object, required: true },
});

const emit = defineEmits(['cancel', 'generate-remote', 'generate-office']);
</script>
