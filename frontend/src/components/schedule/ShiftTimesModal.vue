<!--
  Shift Times dialog of the Schedule screen: times, colour and type of one shift (moved out of
  ScheduleView.vue unchanged). The fields edit `timeForm` in place; `save` / `close` are the screen's.
-->
<template>
  <BaseModal
    overlay-class="fixed inset-0 z-50 flex items-center justify-center px-4"
    backdrop-class="fixed inset-0 bg-black/50 backdrop-blur-sm"
    panel-class="relative w-full max-w-sm bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 p-6"
    :busy="savingTimes"
    @close="emit('close')"
    v-slot="{ titleId }">
    <h3 :id="titleId" class="text-white font-bold text-lg mb-1">Shift Times</h3>
    <p class="text-slate-400 text-sm mb-5">{{ timeConfigDate }} · Shift {{ timeConfigShift?.shift_number }}</p>

    <div class="grid grid-cols-2 gap-4 mb-4">
      <div>
        <label class="block text-sm font-medium text-slate-300 mb-1.5">Start Time</label>
        <input v-model="timeForm.start_time" type="time"
          class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-300 mb-1.5">End Time</label>
        <input v-model="timeForm.end_time" type="time"
          class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
      </div>
    </div>

    <!-- Overnight indicator -->
    <div v-if="timeForm.start_time && timeForm.end_time && timeForm.end_time < timeForm.start_time"
      class="flex items-center gap-2 px-3 py-2 mb-4 bg-indigo-500/10 border border-indigo-500/20 rounded-xl">
      <span class="text-sm">🌙</span>
      <span class="text-xs text-indigo-300">Overnight shift — ends the next day</span>
    </div>

    <!-- Color Picker -->
    <div class="mb-4">
      <label class="block text-sm font-medium text-slate-300 mb-2">Shift Color</label>
      <div class="flex flex-wrap gap-2 mb-2">
        <button v-for="colorDef in shiftColors" :key="colorDef.id"
          @click="timeForm.color = timeForm.color == colorDef.id ? null : String(colorDef.id)"
          :class="['w-8 h-8 rounded-lg border-2 transition-all flex items-center justify-center text-[10px] font-bold',
            timeForm.color == colorDef.id ? 'border-white scale-110 shadow-lg' : 'border-transparent hover:border-white/30']"
          :style="{ backgroundColor: colorDef.hex }"
          :title="colorDef.name">
          <span v-if="timeForm.color == colorDef.id" class="text-white drop-shadow">✓</span>
        </button>
        <button @click="timeForm.color = null; timeForm.color_label = ''"
          :class="['w-8 h-8 rounded-lg border-2 transition-all flex items-center justify-center text-xs',
            !timeForm.color ? 'border-white/40 bg-white/10' : 'border-white/10 bg-white/5 hover:border-white/20']"
          title="No color">
          ✕
        </button>
      </div>
      <input v-if="timeForm.color" v-model="timeForm.color_label" type="text" :placeholder="'Label (default: ' + (shiftColorById[timeForm.color]?.name || '') + ')'"
        class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
    </div>

    <div class="mb-4">
      <label for="shift-edit-type" class="block text-sm font-medium text-slate-300 mb-1.5">Shift type</label>
      <input id="shift-edit-type" v-model="timeForm.shift_type" type="text" maxlength="50"
        list="shift-type-suggestions" placeholder="e.g. night (used by streak rules)"
        class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
    </div>

    <div class="flex items-center justify-end gap-3">
      <button @click="emit('close')"
        class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">
        Cancel
      </button>
      <button @click="emit('save')" :disabled="savingTimes"
        class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
        <svg v-if="savingTimes" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
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
  timeConfigShift: { type: Object, default: null },
  timeConfigDate: { type: String, default: '' },
  timeForm: { type: Object, required: true },
  shiftColors: { type: Array, required: true },
  shiftColorById: { type: Object, required: true },
  savingTimes: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'save']);
</script>
