<template>
  <form @submit.prevent="submit" class="space-y-4" novalidate>
    <div>
      <label :for="`${idPrefix}-name`" class="block text-sm font-medium text-slate-300 mb-1.5">Name <span class="text-red-400 ml-0.5">*</span></label>
      <input :id="`${idPrefix}-name`" v-model="form.name" type="text" required
        @blur="touched.name = true"
        :class="inputClass(errors.name)"
        placeholder="e.g. Warsaw Training" />
      <p v-if="errors.name" class="mt-1 text-xs text-red-400">{{ errors.name }}</p>
    </div>
    <div>
      <label :for="`${idPrefix}-destination`" class="block text-sm font-medium text-slate-300 mb-1.5">Destination <span class="text-red-400 ml-0.5">*</span></label>
      <input :id="`${idPrefix}-destination`" v-model="form.destination" type="text" required
        @blur="touched.destination = true"
        :class="inputClass(errors.destination)"
        placeholder="e.g. Warsaw, Poland" />
      <p v-if="errors.destination" class="mt-1 text-xs text-red-400">{{ errors.destination }}</p>
    </div>
    <div class="grid grid-cols-2 gap-3">
      <div>
        <label :for="`${idPrefix}-start`" class="block text-sm font-medium text-slate-300 mb-1.5">Start Date <span class="text-red-400 ml-0.5">*</span></label>
        <input :id="`${idPrefix}-start`" v-model="form.start_datetime" type="date" required
          @blur="touched.start_datetime = true"
          :class="inputClass(errors.start_datetime)" />
        <p v-if="errors.start_datetime" class="mt-1 text-xs text-red-400">{{ errors.start_datetime }}</p>
      </div>
      <div>
        <label :for="`${idPrefix}-end`" class="block text-sm font-medium text-slate-300 mb-1.5">End Date <span class="text-red-400 ml-0.5">*</span></label>
        <input :id="`${idPrefix}-end`" v-model="form.end_datetime" type="date" required
          :min="form.start_datetime || undefined"
          @blur="touched.end_datetime = true"
          :class="inputClass(errors.end_datetime)" />
        <p v-if="errors.end_datetime" class="mt-1 text-xs text-red-400">{{ errors.end_datetime }}</p>
      </div>
    </div>
    <template v-if="showDetails">
      <div>
        <label :for="`${idPrefix}-description`" class="block text-sm font-medium text-slate-300 mb-1.5">Description</label>
        <textarea :id="`${idPrefix}-description`" v-model="form.description" rows="3"
          class="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500 resize-none" placeholder="Optional details…"></textarea>
      </div>
      <label class="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" v-model="form.registration_open" class="accent-emerald-500 w-4 h-4 rounded" />
        <span class="text-sm text-slate-300">Registration open (employees can request to join)</span>
      </label>
    </template>
    <div class="flex items-center justify-end gap-3 pt-2">
      <button type="button" @click="emit('cancel')"
        class="px-5 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">Cancel</button>
      <button type="submit" :disabled="saving"
        class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
        <svg v-if="saving" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
        {{ saving ? 'Saving…' : submitLabel }}
      </button>
    </div>
  </form>
</template>

<script setup>
// The one delegation create/edit form, shared by Manage Delegations and the Users screen so the two
// can't drift apart again. Dates are whole days and the end day is included, so start == end (a
// one-day delegation) is valid. The parent owns the request: it gets `submit` with the payload and
// passes back `saving` and the server's 422 field errors (`serverErrors`).
import { computed, reactive, useId, watch } from 'vue';

const props = defineProps({
  // Values to start from; a new object resets the form.
  initial: { type: Object, default: () => ({}) },
  saving: { type: Boolean, default: false },
  submitLabel: { type: String, default: 'Create' },
  serverErrors: { type: Object, default: () => ({}) },
  // Description and the registration toggle (Manage Delegations only).
  showDetails: { type: Boolean, default: true },
});
const emit = defineEmits(['submit', 'cancel']);

const idPrefix = `delegation-${useId()}`;

const form = reactive({
  name: '',
  destination: '',
  description: '',
  start_datetime: '',
  end_datetime: '',
  registration_open: true,
});
const touched = reactive({
  name: false,
  destination: false,
  start_datetime: false,
  end_datetime: false,
});

watch(
  () => props.initial,
  (initial) => {
    Object.assign(form, {
      name: initial.name ?? '',
      destination: initial.destination ?? '',
      description: initial.description ?? '',
      start_datetime: initial.start_datetime?.substring(0, 10) ?? '',
      end_datetime: initial.end_datetime?.substring(0, 10) ?? '',
      registration_open: initial.registration_open ?? true,
    });
    for (const key of Object.keys(touched)) touched[key] = false;
  },
  { immediate: true },
);

function clientError(field) {
  if (!touched[field]) return '';
  switch (field) {
    case 'name':
      return form.name.trim() ? '' : 'Name is required.';
    case 'destination':
      return form.destination.trim() ? '' : 'Destination is required.';
    case 'start_datetime':
      return form.start_datetime ? '' : 'Start date is required.';
    case 'end_datetime':
      if (!form.end_datetime) return 'End date is required.';
      return form.start_datetime && form.end_datetime < form.start_datetime
        ? 'End date must be on or after start date.'
        : '';
    default:
      return '';
  }
}

// A field the user changed after the server rejected it no longer shows that rejection (the
// next submit re-checks it). New server errors bring them all back.
const editedSinceServerErrors = reactive(new Set());
watch(
  () => props.serverErrors,
  () => editedSinceServerErrors.clear(),
);
for (const field of Object.keys(touched)) {
  watch(
    () => form[field],
    () => editedSinceServerErrors.add(field),
  );
}

const errors = computed(() =>
  Object.fromEntries(
    Object.keys(touched).map((field) => [
      field,
      clientError(field) ||
        (editedSinceServerErrors.has(field) ? '' : props.serverErrors[field]) ||
        '',
    ]),
  ),
);

function inputClass(error) {
  return [
    'w-full px-3 py-2.5 bg-white/5 border rounded-xl text-white text-sm focus:outline-none focus:ring-2 placeholder-slate-500',
    error ? 'border-red-500/40 focus:ring-red-500/30' : 'border-white/10 focus:ring-indigo-500/50',
  ];
}

function submit() {
  for (const key of Object.keys(touched)) touched[key] = true;
  if (Object.keys(touched).some((field) => clientError(field))) return;
  const payload = {
    name: form.name.trim(),
    destination: form.destination.trim(),
    start_datetime: form.start_datetime,
    end_datetime: form.end_datetime,
  };
  if (props.showDetails) {
    payload.description = form.description;
    payload.registration_open = form.registration_open;
  }
  emit('submit', payload);
}
</script>
