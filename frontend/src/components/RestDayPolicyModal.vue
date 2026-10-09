<template>
  <div class="fixed inset-0 z-[90] flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="emit('cancel')">
    <div ref="dialogRef" role="dialog" aria-modal="true" aria-labelledby="rest-day-title"
      class="w-full max-w-md bg-slate-900 border border-white/10 rounded-2xl p-6 shadow-2xl">
      <h2 id="rest-day-title" class="text-lg font-semibold text-white mb-1">{{ affected ? 'This makes a later shift a rest day' : 'Assign on a rest day?' }}</h2>
      <p v-if="affected" class="text-sm text-slate-300 mb-4">
        Assigning {{ userName }} here makes their {{ affectedType }} on {{ affectedDates }} {{ restDayNoun }}
        ({{ rule.shift_type }}: {{ rule.streak_length }} in a row, then {{ rule.rest_days }} days off).
        How should {{ affectedCount > 1 ? 'those shifts' : 'that shift' }} count? The choice also applies to
        any further shifts it pushes into rest.
      </p>
      <p v-else class="text-sm text-slate-300 mb-4">
        {{ userName }} is resting until {{ restingUntil }}
        ({{ rule.shift_type }}: {{ rule.streak_length }} in a row, then {{ rule.rest_days }} days off).
        How should this shift count?
      </p>
      <fieldset class="space-y-2 mb-5">
        <legend class="sr-only">Rest-day policy</legend>
        <label v-for="option in options" :key="option.value" class="flex items-start gap-2 text-sm text-slate-200 cursor-pointer">
          <input v-model="policy" type="radio" name="rest-policy" :value="option.value" class="mt-1" />
          <span>
            <span class="font-medium">{{ option.label }}</span>
            <span class="block text-xs text-slate-400">{{ option.hint }}</span>
          </span>
        </label>
      </fieldset>
      <div class="flex justify-end gap-3">
        <button type="button" class="px-4 py-2 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl" @click="emit('cancel')">Cancel</button>
        <button type="button" class="px-5 py-2 text-sm font-semibold text-white bg-violet-600 hover:bg-violet-700 rounded-xl" @click="emit('choose', policy)">Assign</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useFocusTrap } from '../composables/useFocusTrap.js';

const props = defineProps({
  userName: { type: String, required: true },
  restingUntil: { type: String, default: '' },
  affected: { type: Array, default: null },
  rule: { type: Object, required: true },
});
const emit = defineEmits(['choose', 'cancel']);

const options = computed(() => [
  {
    value: 'not_counted',
    label: "Doesn't count",
    hint: 'An exception; the streak and the rest window are unchanged.',
  },
  {
    value: 'counts',
    label: 'Counts toward the next streak',
    hint: 'Brings the next mandatory rest closer.',
  },
  {
    value: 'extends_rest',
    label: 'Extends the rest',
    hint: 'The rest window ends ' + props.rule.rest_days + ' days after this shift.',
  },
]);
const policy = ref('not_counted');

const uniqueDates = computed(() => [...new Set((props.affected || []).map((a) => a.date))]);
// "A", "A and B", "A, B and C" (as the server's message).
const affectedDates = computed(() => {
  const dates = uniqueDates.value;
  return dates.length < 2 ? dates.join('') : `${dates.slice(0, -1).join(', ')} and ${dates.at(-1)}`;
});
const restDayNoun = computed(() => (uniqueDates.value.length > 1 ? 'rest days' : 'a rest day'));
const affectedCount = computed(() => (props.affected || []).length);
const affectedType = computed(
  () => [...new Set((props.affected || []).map((a) => a.shift_type))].join('/') || 'shift',
);

// On the shared modal stack: Escape cancels only while this dialog is the top one (a confirm opened
// over it gets the key instead). The parent mounts it to show it, so it opens on mount.
const dialogRef = ref(null);
const open = ref(false);
useFocusTrap(dialogRef, open, { onEscape: () => emit('cancel') });
onMounted(() => {
  open.value = true;
});
</script>
