<!-- frontend/src/components/ShiftTypeBulkEditor.vue -->
<template>
  <div class="fixed inset-0 z-[90] flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="emit('close')">
    <div role="dialog" aria-modal="true" aria-labelledby="shift-type-bulk-title"
      class="w-full max-w-md bg-slate-900 border border-white/10 rounded-2xl p-6 shadow-2xl">
      <h2 id="shift-type-bulk-title" class="text-lg font-semibold text-white mb-1">Set shift types</h2>
      <p class="text-xs text-slate-400 mb-4">Applies to every shift in the slot for this month. Streak counters of assigned employees are adjusted.</p>

      <p v-if="rows.length === 0" class="text-sm text-slate-400">No shifts in this month yet.</p>
      <div v-for="row in rows" :key="row.shiftNumber" class="flex items-center gap-3 mb-2">
        <label :for="`bulk-shift-type-${row.shiftNumber}`" class="w-24 shrink-0 text-sm text-slate-300">Slot {{ row.shiftNumber }} type</label>
        <input :id="`bulk-shift-type-${row.shiftNumber}`" v-model="row.value" type="text" maxlength="50"
          list="shift-type-suggestions" :placeholder="row.mixed ? 'mixed' : 'none'" @input="row.dirty = true"
          class="flex-1 px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
      </div>

      <div class="flex justify-end gap-3 mt-5">
        <button type="button" class="px-4 py-2 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl" @click="emit('close')">Cancel</button>
        <button type="button" :disabled="saving" class="px-5 py-2 text-sm font-semibold text-white bg-violet-600 hover:bg-violet-700 rounded-xl disabled:opacity-50" @click="save">Save</button>
      </div>
      <p v-if="error" class="mt-3 text-sm text-red-400">{{ error }}</p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import api from '../axios.js';

const props = defineProps({
  groupId: { type: Number, required: true },
  monthKey: { type: String, required: true },
  schedule: { type: Object, required: true },
});
const emit = defineEmits(['close', 'saved']);

// One row per slot number present this month; a slot whose days disagree starts empty as "mixed".
function buildRows() {
  const typesBySlot = new Map();
  for (const shifts of Object.values(props.schedule)) {
    for (const shift of shifts) {
      if (!typesBySlot.has(shift.shift_number)) typesBySlot.set(shift.shift_number, new Set());
      typesBySlot.get(shift.shift_number).add(shift.shift_type ?? null);
    }
  }
  return [...typesBySlot.entries()]
    .sort(([a], [b]) => a - b)
    .map(([shiftNumber, types]) => {
      const mixed = types.size > 1;
      return { shiftNumber, mixed, value: mixed ? '' : ([...types][0] ?? ''), dirty: false };
    });
}

const rows = ref(buildRows());
const saving = ref(false);
const error = ref('');

async function save() {
  saving.value = true;
  error.value = '';
  let updated = 0;
  let rebooked = 0;
  try {
    for (const row of rows.value.filter((r) => r.dirty)) {
      const res = await api.put('/api/schedule/shift-types', {
        shift_group_id: props.groupId,
        month: props.monthKey,
        shift_number: row.shiftNumber,
        shift_type: row.value.trim() || null,
      });
      updated += res.data.updated;
      rebooked += res.data.rebooked;
    }
    emit('saved', { updated, rebooked });
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to update shift types.';
  } finally {
    saving.value = false;
  }
}
</script>
