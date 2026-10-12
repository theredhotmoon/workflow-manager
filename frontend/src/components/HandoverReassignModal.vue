<!--
  "Hand to someone else": an admin picks the new incoming user of a pending handover whose incoming
  user was deactivated (PUT /api/checklist-submissions/{id}/incoming). The picker lists active
  users only, without the outgoing user and the admin themselves (the API refuses both).
  Emits `reassigned` with the updated handover, `stale` (with the server's message) when the
  handover changed meanwhile (confirmed, or handed over by another admin), and `close`.
-->
<template>
  <BaseModal
    overlay-class="fixed inset-0 z-[60] flex items-center justify-center px-4"
    backdrop-class="fixed inset-0 bg-black/60 backdrop-blur-sm"
    panel-class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl"
    :busy="saving"
    initial-focus="select"
    described
    @close="emit('close')"
    v-slot="{ titleId, descriptionId }">
    <form @submit.prevent="submit" class="p-6 space-y-4">
      <div>
        <h2 :id="titleId" class="text-lg font-bold text-white">Hand to someone else</h2>
        <p :id="descriptionId" class="text-sm text-slate-400 mt-1">
          {{ submission.template?.name ?? 'Handover' }} from {{ submission.outgoing_user?.name ?? 'a colleague' }}
          is waiting for {{ submission.incoming_user?.name ?? 'its incoming user' }}, who has been deactivated.
          Pick who confirms it instead; they get a notification.
        </p>
      </div>

      <div>
        <label for="reassign-incoming" class="block text-sm font-medium text-slate-300 mb-1.5">New incoming employee</label>
        <select id="reassign-incoming" v-model="selectedId" required :disabled="loadingUsers"
          class="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
          <option value="" class="bg-slate-800">{{ loadingUsers ? 'Loading…' : 'Choose an employee' }}</option>
          <option v-for="u in candidates" :key="u.id" :value="String(u.id)" class="bg-slate-800">{{ u.name }}</option>
        </select>
        <p v-if="usersError" role="alert" class="text-xs text-red-400 mt-1.5">
          {{ usersError }}
          <button type="button" @click="loadUsers" class="underline ml-1">Retry</button>
        </p>
        <p v-else-if="error" role="alert" class="text-xs text-red-400 mt-1.5">{{ error }}</p>
      </div>

      <div class="flex items-center justify-end gap-3 pt-2">
        <button type="button" @click="emit('close')"
          class="px-5 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">Cancel</button>
        <button type="submit" :disabled="saving || !selectedId"
          class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-xl transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed">
          {{ saving ? 'Handing over…' : 'Hand over' }}
        </button>
      </div>
    </form>
  </BaseModal>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { describeApiError } from '../apiErrors.js';
import api from '../axios.js';
import { currentUser } from '../router/index.js';
import BaseModal from './BaseModal.vue';

const props = defineProps({
  /** The pending handover (a row of the archive list). */
  submission: { type: Object, required: true },
});
const emit = defineEmits(['close', 'reassigned', 'stale']);

const users = ref([]);
const loadingUsers = ref(true);
const usersError = ref('');
const selectedId = ref('');
const saving = ref(false);
const error = ref('');

const candidates = computed(() =>
  users.value.filter(
    (u) =>
      u.id !== props.submission.outgoing_user_id &&
      u.id !== props.submission.outgoing_user?.id &&
      u.id !== props.submission.incoming_user_id &&
      u.id !== currentUser.value?.id,
  ),
);

async function loadUsers() {
  loadingUsers.value = true;
  usersError.value = '';
  try {
    const res = await api.get('/api/employees'); // active users only
    users.value = res.data ?? [];
  } catch (err) {
    usersError.value = describeApiError(err, 'Could not load the employees').message;
  } finally {
    loadingUsers.value = false;
  }
}

async function submit() {
  if (saving.value || !selectedId.value) return;
  saving.value = true;
  error.value = '';
  try {
    const res = await api.put(`/api/checklist-submissions/${props.submission.id}/incoming`, {
      incoming_user_id: Number(selectedId.value),
    });
    emit('reassigned', res.data);
  } catch (err) {
    const e = describeApiError(err, 'Could not hand the handover over');
    if (e.silent) return;
    if (e.fieldErrors.incoming_user_id) {
      // Someone just deactivated the picked user: offer the fresh list.
      error.value = e.fieldErrors.incoming_user_id;
      selectedId.value = '';
      loadUsers();
    } else if (e.status === 422 || e.status === 404) {
      // The handover itself changed (no longer waiting, already handed over, gone): retrying here
      // can only fail again, so the list re-reads it.
      emit('stale', e.message);
    } else {
      error.value = e.message;
    }
  } finally {
    saving.value = false;
  }
}

onMounted(loadUsers);
</script>
