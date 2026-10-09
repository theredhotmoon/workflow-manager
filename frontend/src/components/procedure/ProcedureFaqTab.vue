<template>
  <div>
    <form @submit.prevent="submitQuestion" class="mb-4 flex gap-2">
      <input v-model="newQuestion" type="text" placeholder="Ask a question about this procedure..."
        class="flex-1 px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
      <button type="submit" :disabled="asking || !newQuestion.trim()"
        class="px-4 py-2 bg-indigo-500/20 text-indigo-400 border border-indigo-500/30 rounded-xl text-sm font-medium hover:bg-indigo-500/30 transition-all disabled:opacity-40">
        Ask
      </button>
    </form>
    <div v-if="!items.length" class="text-center text-slate-500 text-sm py-8">No FAQ items yet.</div>
    <div v-else class="space-y-4">
      <div v-for="item in items" :key="item.id"
        :class="['border rounded-xl p-4 transition-all', item.is_outdated ? 'bg-white/3 border-white/5 opacity-60' : 'bg-white/5 border-white/10']">
        <div class="flex items-start justify-between gap-2 mb-3">
          <div>
            <p :class="['text-sm font-medium', item.is_outdated ? 'text-slate-500 line-through' : 'text-white']">{{ item.question }}</p>
            <div class="flex items-center gap-2 mt-1 flex-wrap">
              <span class="text-[10px] text-slate-500">{{ item.author?.name }} · {{ fmtDate(item.created_at) }}</span>
              <span v-if="item.for_version" class="px-1.5 py-0.5 text-[9px] font-bold bg-indigo-500/15 text-indigo-400 border border-indigo-500/20 rounded-full tabular-nums">v{{ item.for_version }}</span>
              <span v-if="item.is_outdated" class="px-1.5 py-0.5 text-[9px] font-semibold uppercase bg-amber-500/15 text-amber-400 border border-amber-500/20 rounded-full">Outdated</span>
            </div>
          </div>
          <div class="flex items-center gap-1 shrink-0">
            <button v-if="canManage(item)" @click="toggleOutdatedItem(item)"
              :title="item.is_outdated ? 'Mark as current' : 'Mark as outdated'"
              :aria-label="item.is_outdated ? 'Mark question as current' : 'Mark question as outdated'"
              :class="['text-xs p-1 transition-colors rounded',
                item.is_outdated ? 'text-amber-400 hover:text-amber-300' : 'text-slate-500 hover:text-amber-400']">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            </button>
            <button v-if="item.created_by === userId || isAdmin" @click="deleteFaqItem(item)"
              aria-label="Delete question"
              class="text-slate-500 hover:text-red-400 text-xs p-1 transition-colors">✕</button>
          </div>
        </div>
        <div v-if="item.replies?.length" class="ml-4 space-y-2 border-l-2 border-indigo-500/20 pl-4 mb-3">
          <div v-for="reply in item.replies" :key="reply.id"
            :class="['relative', reply.is_outdated ? 'opacity-50' : '']">
            <p :class="['text-sm', reply.is_outdated ? 'text-slate-500 line-through' : 'text-slate-300']">{{ reply.body }}</p>
            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
              <p class="text-[10px] text-slate-500">{{ reply.author?.name }} · {{ fmtDate(reply.created_at) }}</p>
              <span v-if="reply.for_version" class="px-1.5 py-0.5 text-[9px] font-bold bg-indigo-500/15 text-indigo-400 border border-indigo-500/20 rounded-full tabular-nums">v{{ reply.for_version }}</span>
              <span v-if="reply.is_outdated" class="px-1.5 py-0.5 text-[9px] font-semibold uppercase bg-amber-500/15 text-amber-400 border border-amber-500/20 rounded-full">Outdated</span>
              <button v-if="canManage(item)" @click="toggleOutdatedReply(reply)"
                :title="reply.is_outdated ? 'Mark as current' : 'Mark as outdated'"
                :aria-label="reply.is_outdated ? 'Mark reply as current' : 'Mark reply as outdated'"
                :class="['text-[10px] p-0.5 transition-colors rounded',
                  reply.is_outdated ? 'text-amber-400 hover:text-amber-300' : 'text-slate-600 hover:text-amber-400']">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
              </button>
              <button v-if="reply.created_by === userId || isAdmin" @click="deleteFaqReply(reply, item)"
                aria-label="Delete reply"
                class="text-slate-600 hover:text-red-400 text-[10px] transition-colors">✕</button>
            </div>
          </div>
        </div>
        <div class="flex gap-2 ml-4">
          <input v-model="replyTexts[item.id]" type="text" placeholder="Add a reply..."
            @keyup.enter="submitReply(item)"
            class="flex-1 px-3 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
          <button @click="submitReply(item)" :disabled="replying[item.id] || !replyTexts[item.id]?.trim()"
            class="px-3 py-1.5 text-xs text-indigo-400 bg-indigo-500/15 border border-indigo-500/20 rounded-lg hover:bg-indigo-500/25 transition-all disabled:opacity-40">
            Reply
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { describeApiError } from '../../apiErrors.js';
import api from '../../axios.js';
import { confirm } from '../../composables/useConfirm.js';
import { useToast } from '../../composables/useToast.js';

const props = defineProps({
  procedureId: { type: Number, required: true },
  items: { type: Array, required: true },
  userId: { type: Number, default: null },
  isAdmin: { type: Boolean, default: false },
  ownerId: { type: Number, default: null },
});

const emit = defineEmits(['update:items']);
const { showToast } = useToast();

const newQuestion = ref('');
const replyTexts = reactive({});
// In-flight posts: a second Enter or click while one is on its way must not post it again.
const asking = ref(false);
const replying = reactive({});

function canManage(item) {
  return props.isAdmin || props.userId === props.ownerId;
}

function fmtDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

function toastError(err, fallback) {
  const e = describeApiError(err, fallback);
  if (!e.silent) showToast(e.message, 'error', 6000);
}

async function submitQuestion() {
  if (asking.value || !newQuestion.value.trim()) return;
  asking.value = true;
  try {
    const res = await api.post(`/api/procedures/${props.procedureId}/faq`, {
      question: newQuestion.value,
    });
    emit('update:items', [{ ...res.data, replies: [] }, ...props.items]);
    newQuestion.value = '';
  } catch (err) {
    toastError(err, 'Could not post the question, try again');
  } finally {
    asking.value = false;
  }
}

async function submitReply(item) {
  const body = replyTexts[item.id]?.trim();
  if (!body || replying[item.id]) return;
  replying[item.id] = true;
  try {
    const res = await api.post(`/api/procedure-faq/${item.id}/replies`, { body });
    item.replies.push(res.data);
    replyTexts[item.id] = '';
  } catch (err) {
    toastError(err, 'Could not post the reply, try again');
  } finally {
    replying[item.id] = false;
  }
}

async function toggleOutdatedItem(item) {
  try {
    const res = await api.put(`/api/procedure-faq/${item.id}/toggle-outdated`);
    item.is_outdated = res.data.is_outdated;
  } catch (err) {
    toastError(err, 'Could not update the question, try again');
  }
}

async function toggleOutdatedReply(reply) {
  try {
    const res = await api.put(`/api/procedure-faq-replies/${reply.id}/toggle-outdated`);
    reply.is_outdated = res.data.is_outdated;
  } catch (err) {
    toastError(err, 'Could not update the reply, try again');
  }
}

async function deleteFaqItem(item) {
  const replies = item.replies?.length ?? 0;
  const ok = await confirm({
    title: 'Delete question',
    message: replies
      ? `Delete this question and its ${replies} ${replies === 1 ? 'reply' : 'replies'}? This cannot be undone.`
      : 'Delete this question? This cannot be undone.',
    confirmLabel: 'Delete',
    danger: true,
  });
  if (!ok) return;
  try {
    await api.delete(`/api/procedure-faq/${item.id}`);
    emit(
      'update:items',
      props.items.filter((i) => i.id !== item.id),
    );
    showToast('Question deleted');
  } catch (err) {
    toastError(err, 'Could not delete the question, try again');
  }
}

async function deleteFaqReply(reply, item) {
  const ok = await confirm({
    title: 'Delete reply',
    message: 'Delete this reply? This cannot be undone.',
    confirmLabel: 'Delete',
    danger: true,
  });
  if (!ok) return;
  try {
    await api.delete(`/api/procedure-faq-replies/${reply.id}`);
    item.replies = item.replies.filter((r) => r.id !== reply.id);
    showToast('Reply deleted');
  } catch (err) {
    toastError(err, 'Could not delete the reply, try again');
  }
}
</script>
