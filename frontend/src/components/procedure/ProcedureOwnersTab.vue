<template>
  <div>
    <!-- Change owner form (admin only) -->
    <div v-if="isAdmin" class="bg-white/5 border border-white/10 rounded-xl p-4 mb-4">
      <h4 class="text-sm font-semibold text-white mb-3">Change Owner</h4>
      <div class="space-y-3">
        <div>
          <label class="block text-[10px] text-slate-500 uppercase tracking-wider font-semibold mb-1">Current Owner</label>
          <p class="text-sm text-white font-medium">{{ currentOwner }}</p>
        </div>
        <div>
          <label class="block text-[10px] text-slate-500 uppercase tracking-wider font-semibold mb-1">New Owner</label>
          <select v-model="newOwnerId"
            class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
            <option value="" disabled class="bg-slate-800">Select new owner...</option>
            <option v-for="u in availableUsers" :key="u.id" :value="u.id" class="bg-slate-800">{{ u.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-[10px] text-slate-500 uppercase tracking-wider font-semibold mb-1">Reason for change</label>
          <textarea v-model="reason" rows="2" placeholder="Explain why ownership is being transferred..."
            class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500 resize-none"></textarea>
        </div>
        <div class="flex items-center gap-3">
          <button @click="changeOwner" :disabled="!newOwnerId || !reason.trim() || saving"
            class="px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl text-sm shadow-lg shadow-violet-500/25 disabled:opacity-40 disabled:cursor-not-allowed transition-all flex items-center gap-2">
            <svg v-if="saving" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            Transfer Ownership
          </button>
          <p v-if="error" class="text-xs text-red-400">{{ error }}</p>
          <p v-if="success" class="text-xs text-emerald-400">{{ success }}</p>
        </div>
      </div>
    </div>

    <!-- Owner change history -->
    <h4 class="text-sm font-semibold text-white mb-3">Ownership History</h4>
    <div v-if="!changes.length" class="text-center text-slate-500 text-sm py-8">No ownership changes recorded.</div>
    <div v-else class="space-y-3">
      <div v-for="c in changes" :key="c.id" class="bg-white/5 border border-white/10 rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="px-2 py-0.5 text-[9px] font-semibold uppercase bg-violet-500/15 text-violet-400 border border-violet-500/20 rounded-full">Transfer</span>
            <span class="text-[10px] text-slate-500">by {{ c.changed_by?.name || 'Unknown' }} · {{ fmtDate(c.created_at) }}</span>
          </div>
        </div>
        <div class="flex items-center gap-2 text-sm mb-2">
          <span class="text-slate-400">{{ c.old_owner?.name || 'None' }}</span>
          <svg class="w-4 h-4 text-indigo-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
          </svg>
          <span class="text-white font-medium">{{ c.new_owner?.name || 'Unknown' }}</span>
        </div>
        <p class="text-xs text-slate-400 pl-3 border-l-2 border-white/10">{{ c.reason }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import api from '../../axios.js';

const props = defineProps({
  procedureId: { type: Number, required: true },
  currentOwner: { type: String, default: '—' },
  ownerId: { type: Number, default: null },
  changes: { type: Array, default: () => [] },
  users: { type: Array, default: () => [] },
  isAdmin: { type: Boolean, default: false },
});

const emit = defineEmits(['owner-changed']);

const newOwnerId = ref('');
const reason = ref('');
const saving = ref(false);
const error = ref('');
const success = ref('');

const availableUsers = ref(props.users.filter((u) => u.id !== props.ownerId));

async function changeOwner() {
  if (!newOwnerId.value || !reason.value.trim()) return;
  saving.value = true;
  error.value = '';
  success.value = '';
  try {
    await api.put(`/api/procedures/${props.procedureId}/change-owner`, {
      new_owner_id: newOwnerId.value,
      reason: reason.value.trim(),
    });
    success.value = 'Ownership transferred successfully.';
    emit('owner-changed');
    newOwnerId.value = '';
    reason.value = '';
    setTimeout(() => {
      success.value = '';
    }, 2000);
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to change owner.';
  } finally {
    saving.value = false;
  }
}

function fmtDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}
</script>
