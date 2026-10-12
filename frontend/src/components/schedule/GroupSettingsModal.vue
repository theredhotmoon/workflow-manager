<!--
  Settlement Period Settings dialog of a shift group (moved out of ScheduleView.vue unchanged).
  The fields edit `groupSettingsForm` in place; `save` / `close` are the screen's.
-->
<template>
  <BaseModal
    overlay-class="fixed inset-0 z-50 flex items-center justify-center px-4"
    backdrop-class="fixed inset-0 bg-black/50 backdrop-blur-sm"
    panel-class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 p-6"
    :busy="savingGroupSettings"
    @close="emit('close')"
    v-slot="{ titleId }">
    <h3 :id="titleId" class="text-white font-bold text-lg mb-1">Settlement Period Settings</h3>
    <p class="text-slate-400 text-sm mb-5">{{ groupSettingsTarget?.name }}</p>

    <div class="mb-4">
      <label for="settlement-mode" class="block text-sm font-medium text-slate-300 mb-1.5">Mode</label>
      <select id="settlement-mode" v-model="groupSettingsForm.settlement_mode"
        class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
        <option value="weekly">Weekly</option>
        <option value="monthly">Monthly</option>
        <option value="quarterly">Quarterly</option>
        <option value="custom">Custom</option>
      </select>
    </div>

    <div class="mb-4">
      <label for="settlement-anchor-date" class="block text-sm font-medium text-slate-300 mb-1.5">Anchor Date</label>
      <input id="settlement-anchor-date" v-model="groupSettingsForm.settlement_anchor_date" type="date"
        class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
    </div>

    <div v-if="groupSettingsForm.settlement_mode === 'custom'" class="mb-4">
      <label for="settlement-custom-length" class="block text-sm font-medium text-slate-300 mb-1.5">Custom Length (days)</label>
      <input id="settlement-custom-length" v-model.number="groupSettingsForm.settlement_custom_length_days" type="number" min="1"
        class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
    </div>

    <div class="mb-5">
      <label for="settlement-weekly-hour-cap" class="block text-sm font-medium text-slate-300 mb-1.5">Weekly Hour Cap</label>
      <input id="settlement-weekly-hour-cap" v-model.number="groupSettingsForm.weekly_hour_cap" type="number" min="0" step="0.5"
        class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
    </div>

    <div class="mb-5">
      <label class="block text-sm font-medium text-slate-300 mb-1.5">Shift Streak Rules</label>
      <div v-for="(rule, idx) in groupSettingsForm.streak_rules" :key="idx" class="flex items-center gap-2 mb-2">
        <input v-model="rule.shift_type" type="text" placeholder="shift type (e.g. night)"
          class="flex-1 px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
        <input v-model.number="rule.streak_length" type="number" min="1" title="Streak length"
          class="w-16 px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
        <span class="text-slate-500 text-xs whitespace-nowrap">then rest</span>
        <input v-model.number="rule.rest_days" type="number" min="1" title="Rest days"
          class="w-16 px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
        <span class="text-slate-500 text-xs whitespace-nowrap">days</span>
        <button @click="groupSettingsForm.streak_rules.splice(idx, 1)"
          class="w-6 h-6 flex items-center justify-center text-slate-500 hover:text-red-400 transition-colors shrink-0" title="Remove rule">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <button @click="groupSettingsForm.streak_rules.push({ shift_type: '', streak_length: 3, rest_days: 2 })"
        class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">+ Add streak rule</button>
    </div>

    <div class="flex items-center justify-end gap-3">
      <button @click="emit('close')" class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">Cancel</button>
      <button @click="emit('save')" :disabled="savingGroupSettings"
        class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
        <svg v-if="savingGroupSettings" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
        Save
      </button>
    </div>
  </BaseModal>
</template>

<script setup>
import BaseModal from '../BaseModal.vue';

defineProps({
  groupSettingsTarget: { type: Object, default: null },
  groupSettingsForm: { type: Object, required: true },
  savingGroupSettings: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'save']);
</script>
