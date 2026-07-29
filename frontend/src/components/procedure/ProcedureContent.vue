<template>
  <div>
    <!-- Purpose (always at top, outside tabs) -->
    <p v-if="procedure.purpose" class="text-slate-400 text-sm mb-4">{{ procedure.purpose }}</p>

    <!-- Tags -->
    <div v-if="procedure.tags?.length" class="flex flex-wrap gap-1.5 mb-4">
      <span v-for="tag in procedure.tags" :key="tag.id"
        class="px-2 py-0.5 rounded-full text-[10px] font-medium"
        :style="{ backgroundColor: tag.color + '20', color: tag.color, border: '1px solid ' + tag.color + '30' }">
        {{ tag.name }}
      </span>
    </div>

    <!-- Metadata + Requirements + Changes row -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
      <!-- Left: Metadata -->
      <div class="bg-white/5 border border-white/10 rounded-xl p-4">
        <div class="grid grid-cols-2 gap-3">
          <div><p class="text-[10px] text-slate-500 uppercase tracking-wider">Owner</p><p class="text-sm text-white font-medium mt-0.5">{{ procedure.owner?.name || '—' }}</p></div>
          <div><p class="text-[10px] text-slate-500 uppercase tracking-wider">Created</p><p class="text-sm text-slate-300 mt-0.5">{{ fmtDate(procedure.created_at) }}</p></div>
          <div><p class="text-[10px] text-slate-500 uppercase tracking-wider">Last Modified</p><p class="text-sm text-slate-300 mt-0.5">{{ fmtDate(procedure.updated_at) }}</p></div>
          <div><p class="text-[10px] text-slate-500 uppercase tracking-wider">Your Last Access</p><p class="text-sm text-slate-300 mt-0.5">{{ procedure.previous_access ? fmtDate(procedure.previous_access) : 'First visit' }}</p></div>
        </div>
      </div>
      <!-- Middle: Requirements / Tools -->
      <div :class="['border rounded-xl p-4', procedure.requirements ? 'bg-amber-500/8 border-amber-500/15' : 'bg-white/5 border-white/10']">
        <p :class="['text-[10px] uppercase tracking-wider font-semibold mb-1', procedure.requirements ? 'text-amber-400' : 'text-slate-500']">Requirements / Tools</p>
        <p v-if="procedure.requirements" class="text-sm text-slate-300 whitespace-pre-wrap">{{ procedure.requirements }}</p>
        <p v-else class="text-sm text-slate-500 italic">No additional requirements were defined.</p>
      </div>
      <!-- Right: Versions -->
      <div class="bg-indigo-500/8 border border-indigo-500/15 rounded-xl p-4">
        <p class="text-[10px] text-indigo-400 uppercase tracking-wider font-semibold mb-2">Versions</p>
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <button @click="emit('go-to-version', viewingVersion - 1)" :disabled="viewingVersion <= 1"
              :class="['w-7 h-7 flex items-center justify-center rounded-lg text-sm font-bold transition-all',
                viewingVersion <= 1 ? 'text-slate-700 cursor-not-allowed' : 'text-slate-400 hover:text-white hover:bg-white/10']"
              title="Previous version">‹</button>
            <span :class="['text-3xl font-black tabular-nums', viewingVersion < maxVersion ? 'text-amber-400' : 'text-white']">v{{ viewingVersion }}</span>
            <button @click="emit('go-to-version', viewingVersion + 1)" :disabled="viewingVersion >= maxVersion"
              :class="['w-7 h-7 flex items-center justify-center rounded-lg text-sm font-bold transition-all',
                viewingVersion >= maxVersion ? 'text-slate-700 cursor-not-allowed' : 'text-slate-400 hover:text-white hover:bg-white/10']"
              title="Next version">›</button>
          </div>
          <button v-if="viewingVersion > 1" @click="emit('open-diff')"
            class="w-8 h-8 flex items-center justify-center rounded-lg text-indigo-400 bg-indigo-500/10 border border-indigo-500/20 hover:bg-indigo-500/20 transition-all"
            :title="`Compare v${viewingVersion - 1} → v${viewingVersion}`">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>
          </button>
        </div>
        <p v-if="publisher" class="text-[10px] text-slate-500 mt-2">Published by {{ publisher?.name }} · {{ fmtDate(publishedAt) }}</p>
      </div>
    </div>

    <!-- Tabs -->
    <div class="flex border-b border-white/10 mb-6 overflow-x-auto">
      <button v-for="tab in tabs" :key="tab.key" @click="activeTab = tab.key"
        :class="['px-4 py-2.5 text-sm font-medium transition-all whitespace-nowrap -mb-px',
          activeTab === tab.key
            ? 'text-indigo-400 border-b-2 border-indigo-400'
            : 'text-slate-500 hover:text-slate-300']">
        {{ tab.label }}
        <span v-if="tab.count != null" class="ml-1 px-1.5 py-0.5 text-[9px] bg-white/10 rounded-full">{{ tab.count }}</span>
      </button>
    </div>

    <div v-show="activeTab === 'content'">
      <div v-if="procedure.content" class="prose-content bg-white/5 border border-white/10 rounded-xl p-6" v-html="procedure.content"></div>
      <p v-else class="text-slate-500 text-sm text-center py-8">No content yet.</p>
    </div>

    <!-- FAQ Tab -->
    <div v-show="activeTab === 'faq'">
      <ProcedureFaqTab
        :procedure-id="procedure.id"
        :items="procedure.faq_items || []"
        :user-id="userId"
        :is-admin="isAdmin"
        :owner-id="procedure.owner_id"
        @update:items="v => procedure.faq_items = v" />
    </div>

    <!-- Templates Tab -->
    <div v-show="activeTab === 'templates'">
      <ProcedureTemplatesTab
        :templates="procedure.response_templates || []"
        :editable="editable"
        @add-template="emit('add-template')"
        @edit-template="t => emit('edit-template', t)"
        @delete-template="t => emit('delete-template', t)"
        @copied="msg => showToast(msg)" />
    </div>

    <!-- Feedback Tab -->
    <div v-show="activeTab === 'feedback'">
      <ProcedureFeedbackTab
        :feedbacks="procedure.feedbacks || []"
        :is-admin="isAdmin" />
    </div>

    <!-- Owners Tab -->
    <div v-show="activeTab === 'owners'">
      <ProcedureOwnersTab
        :procedure-id="procedure.id"
        :current-owner="procedure.owner?.name || '—'"
        :owner-id="procedure.owner_id"
        :changes="procedure.owner_changes || []"
        :users="users"
        :is-admin="isAdmin"
        @owner-changed="emit('owner-changed')" />
    </div>

    <!-- Access Log Tab -->
    <div v-show="activeTab === 'access-log'">
      <ProcedureAccessLogTab v-if="accessLogLoaded" :procedure-id="procedure.id" />
      <div v-else class="text-center text-slate-500 text-sm py-8">Click to load access logs.</div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useToast } from '../../composables/useToast.js';
import ProcedureFaqTab from './ProcedureFaqTab.vue';
import ProcedureTemplatesTab from './ProcedureTemplatesTab.vue';
import ProcedureFeedbackTab from './ProcedureFeedbackTab.vue';
import ProcedureOwnersTab from './ProcedureOwnersTab.vue';
import ProcedureAccessLogTab from './ProcedureAccessLogTab.vue';

const props = defineProps({
  procedure: { type: Object, required: true },
  userId: { type: Number, default: null },
  isAdmin: { type: Boolean, default: false },
  editable: { type: Boolean, default: false },
  users: { type: Array, default: () => [] },
  changeReason: { type: String, default: null },
  publisher: { type: Object, default: null },
  publishedAt: { type: String, default: null },
  viewingVersion: { type: Number, default: 1 },
  maxVersion: { type: Number, default: 1 },
});

const emit = defineEmits([
  'add-template',
  'edit-template',
  'delete-template',
  'owner-changed',
  'open-diff',
  'go-to-version',
]);
const { showToast } = useToast();

const activeTab = ref('content');
const accessLogLoaded = ref(false);

const tabs = computed(() => [
  { key: 'content', label: 'Content', count: null },
  { key: 'faq', label: 'FAQ', count: props.procedure?.faq_items?.length || 0 },
  { key: 'templates', label: 'Templates', count: props.procedure?.response_templates?.length || 0 },
  { key: 'feedback', label: 'Feedback', count: props.procedure?.feedbacks?.length || 0 },
  { key: 'owners', label: 'Owners', count: props.procedure?.owner_changes?.length || 0 },
  { key: 'access-log', label: 'Access Log', count: null },
]);

watch(activeTab, (val) => {
  if (val === 'access-log') accessLogLoaded.value = true;
});

function fmtDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

defineExpose({
  resetTab: () => {
    activeTab.value = 'content';
    accessLogLoaded.value = false;
  },
});
</script>

<style>
.prose-content {
  color: #cbd5e1;
  font-size: 0.875rem;
  line-height: 1.75;
}
.prose-content h1, .prose-content h2, .prose-content h3 { color: #f1f5f9; font-weight: 700; margin: 1.5em 0 0.5em; }
.prose-content h1 { font-size: 1.5rem; }
.prose-content h2 { font-size: 1.25rem; }
.prose-content h3 { font-size: 1.1rem; }
.prose-content p { margin: 0.75em 0; }
.prose-content ul, .prose-content ol { margin: 0.75em 0; padding-left: 1.5em; }
.prose-content li { margin: 0.25em 0; }
.prose-content code { background: rgba(255,255,255,0.08); padding: 0.15em 0.4em; border-radius: 0.25rem; font-size: 0.85em; }
.prose-content pre { background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1); border-radius: 0.5rem; padding: 1em; overflow-x: auto; margin: 1em 0; }
.prose-content pre code { background: none; padding: 0; }
.prose-content img { max-width: 100%; border-radius: 0.5rem; margin: 1em 0; }
.prose-content a { color: #818cf8; text-decoration: underline; }
.prose-content blockquote { border-left: 3px solid rgba(99,102,241,0.4); padding-left: 1em; margin: 1em 0; color: #94a3b8; }
.prose-content strong { color: #f1f5f9; }
</style>
