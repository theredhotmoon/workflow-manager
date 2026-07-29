<template>
  <div>
    <div v-if="!feedbacks.length" class="text-center text-slate-500 text-sm py-8">No feedback submitted yet.</div>
    <div v-else class="space-y-3">
      <div v-for="fb in feedbacks" :key="fb.id" class="bg-white/5 border border-white/10 rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
          <div class="flex items-center gap-2 flex-wrap">
            <span :class="['px-2 py-0.5 text-[9px] font-semibold uppercase rounded-full',
              fb.type === 'error' ? 'bg-red-500/15 text-red-400 border border-red-500/20' : 'bg-blue-500/15 text-blue-400 border border-blue-500/20']">
              {{ fb.type }}
            </span>
            <span v-if="fb.for_version" class="px-1.5 py-0.5 text-[9px] font-bold bg-indigo-500/15 text-indigo-400 border border-indigo-500/20 rounded-full tabular-nums">v{{ fb.for_version }}</span>
            <span class="text-[10px] text-slate-500">{{ fb.user?.name }} · {{ fmtDate(fb.created_at) }}</span>
          </div>
          <select v-if="isAdmin" v-model="fb.status" @change="updateStatus(fb)"
            class="px-2 py-1 bg-white/5 border border-white/10 rounded text-[10px] text-slate-300 focus:outline-none">
            <option value="open" class="bg-slate-800">Open</option>
            <option value="reviewed" class="bg-slate-800">Reviewed</option>
            <option value="resolved" class="bg-slate-800">Resolved</option>
          </select>
          <span v-else :class="['px-2 py-0.5 text-[9px] font-semibold uppercase rounded-full',
            fb.status === 'open' ? 'bg-yellow-500/15 text-yellow-400' : fb.status === 'reviewed' ? 'bg-blue-500/15 text-blue-400' : 'bg-emerald-500/15 text-emerald-400']">
            {{ fb.status }}
          </span>
        </div>
        <p class="text-sm text-slate-300">{{ fb.description }}</p>
        <p v-if="fb.proposed_changes" class="text-sm text-slate-400 mt-2 pl-3 border-l-2 border-white/10">{{ fb.proposed_changes }}</p>
        <!-- Resolution & owner comment -->
        <div v-if="fb.resolution || fb.owner_comment" class="mt-2 pt-2 border-t border-white/5">
          <div class="flex items-center gap-2 flex-wrap">
            <span v-if="fb.resolution" :class="['px-2 py-0.5 text-[9px] font-semibold uppercase rounded-full',
              fb.resolution === 'accepted' ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/20' :
              fb.resolution === 'rejected' ? 'bg-red-500/15 text-red-400 border border-red-500/20' :
              'bg-blue-500/15 text-blue-400 border border-blue-500/20']">
              {{ fb.resolution }}
            </span>
          </div>
          <p v-if="fb.owner_comment" class="text-xs text-slate-400 mt-1.5 italic">💬 {{ fb.owner_comment }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import api from '../../axios.js';

defineProps({
  feedbacks: { type: Array, required: true },
  isAdmin: { type: Boolean, default: false },
});

function fmtDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

async function updateStatus(fb) {
  try {
    await api.put(`/api/procedure-feedbacks/${fb.id}`, { status: fb.status });
  } catch {}
}
</script>
