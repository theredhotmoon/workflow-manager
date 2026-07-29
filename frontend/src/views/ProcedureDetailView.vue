<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div v-if="loading" class="text-center py-16"><p class="text-slate-400">Loading procedure...</p></div>
      <template v-else-if="procedure">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start gap-4 mb-6">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-3 mb-2">
              <router-link to="/procedures" class="text-slate-500 hover:text-white transition-colors text-sm">← Back</router-link>
              <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider bg-indigo-500/15 text-indigo-400 border border-indigo-500/20 rounded-full">
                {{ procedure.identifier }}
              </span>
              <span v-if="procedure.has_draft && procedure.can_edit" class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider bg-amber-500/15 text-amber-400 border border-amber-500/20 rounded-full">
                Draft in progress
              </span>
            </div>
            <h1 class="text-2xl font-bold text-white mb-1">{{ displayData.title }}</h1>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <button @click="copyLink"
              :class="['px-3 py-2 text-sm font-medium flex items-center gap-1.5 rounded-xl border transition-all',
                linkCopied
                  ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20'
                  : 'text-slate-400 bg-white/5 border-white/10 hover:bg-white/10 hover:text-white']"
              :title="linkCopied ? 'Link copied!' : 'Copy link to procedure'">
              <svg v-if="linkCopied" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
              <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>
              {{ linkCopied ? 'Copied!' : 'Copy Link' }}
            </button>
            <button @click="showFeedbackModal = true"
              class="px-3 py-2 text-sm text-orange-400 bg-orange-500/10 border border-orange-500/20 rounded-xl hover:bg-orange-500/20 transition-all font-medium">
              ⚠ Report Error
            </button>
            <button v-if="procedure.can_edit" @click="showEditor = true"
              class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl text-sm shadow-lg transition-all">
              {{ procedure.has_draft ? 'Continue Draft' : 'Edit' }}
            </button>
          </div>
        </div>

        <!-- Old version warning banner -->
        <div v-if="isViewingOldVersion" class="mb-4 p-3 bg-amber-500/10 border border-amber-500/20 rounded-xl flex items-center justify-between">
          <p class="text-sm text-amber-300">
            <strong>Viewing v{{ viewingVersion }}</strong> — this is not the latest version. <button @click="goToVersion(procedure.version)" class="underline hover:text-amber-200 transition-colors">Go to latest (v{{ procedure.version }})</button>
          </p>
        </div>

        <!-- Shared Procedure Content -->
        <ProcedureContent
          :procedure="displayProcedure"
          :user-id="currentUserId"
          :is-admin="isAdmin"
          :editable="!isViewingOldVersion"
          :users="users"
          :change-reason="displayData.change_reason"
          :publisher="displayData.publisher"
          :published-at="displayData.published_at"
          :viewing-version="viewingVersion"
          :max-version="procedure.version"
          @add-template="openTemplateModal(null)"
          @edit-template="openTemplateModal"
          @delete-template="deleteTemplate"
          @owner-changed="fetchProcedure"
          @open-diff="openDiffModal"
          @go-to-version="goToVersion" />

        <!-- Feedback Report Modal -->
        <Transition enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
          enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
          <div v-if="showFeedbackModal" class="fixed inset-0 z-50 flex items-center justify-center px-4">
            <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showFeedbackModal = false"></div>
            <div class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-orange-500/25 rounded-2xl shadow-2xl p-6">
              <h3 class="text-white font-bold text-lg mb-4">Report Procedure Issue</h3>
              <form @submit.prevent="submitFeedback" class="space-y-4">
                <div>
                  <label class="block text-sm font-medium text-slate-300 mb-1.5">Type</label>
                  <select v-model="feedbackForm.type" class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/50">
                    <option value="error" class="bg-slate-800">Error Report</option>
                    <option value="suggestion" class="bg-slate-800">Suggestion</option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm font-medium text-slate-300 mb-1.5">Description</label>
                  <textarea v-model="feedbackForm.description" rows="3" required placeholder="Describe the issue..."
                    class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/50 placeholder-slate-500 resize-none"></textarea>
                </div>
                <div>
                  <label class="block text-sm font-medium text-slate-300 mb-1.5">Proposed Changes (optional)</label>
                  <textarea v-model="feedbackForm.proposed_changes" rows="2" placeholder="Suggest a fix..."
                    class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/50 placeholder-slate-500 resize-none"></textarea>
                </div>
                <div class="flex justify-end gap-3">
                  <button type="button" @click="showFeedbackModal = false" class="px-4 py-2 text-sm text-slate-300 bg-white/5 border border-white/10 rounded-xl hover:bg-white/10 transition-all">Cancel</button>
                  <button type="submit" class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl text-sm shadow-lg disabled:opacity-50">Submit</button>
                </div>
              </form>
            </div>
          </div>
        </Transition>

        <!-- Template Modal -->
        <Transition enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
          enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
          <div v-if="showTemplateModal" class="fixed inset-0 z-50 flex items-center justify-center px-4">
            <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showTemplateModal = false"></div>
            <div class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-violet-500/25 rounded-2xl shadow-2xl p-6">
              <h3 class="text-white font-bold text-lg mb-4">{{ templateForm.id ? 'Edit Template' : 'New Template' }}</h3>
              <form @submit.prevent="saveTemplate" class="space-y-4">
                <div>
                  <label class="block text-sm font-medium text-slate-300 mb-1.5">Title</label>
                  <input v-model="templateForm.title" type="text" required placeholder="e.g. Greeting"
                    class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-violet-500/50 placeholder-slate-500" />
                </div>
                <div>
                  <label class="block text-sm font-medium text-slate-300 mb-1.5">Language</label>
                  <select v-model="templateForm.language" class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-violet-500/50">
                    <option value="en" class="bg-slate-800">English</option>
                    <option value="pl" class="bg-slate-800">Polish</option>
                    <option value="de" class="bg-slate-800">German</option>
                    <option value="fr" class="bg-slate-800">French</option>
                    <option value="es" class="bg-slate-800">Spanish</option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm font-medium text-slate-300 mb-1.5">Body</label>
                  <textarea v-model="templateForm.body" rows="5" required placeholder="Dear Customer..."
                    class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-violet-500/50 placeholder-slate-500 resize-none"></textarea>
                </div>
                <div class="flex justify-end gap-3">
                  <button type="button" @click="showTemplateModal = false" class="px-4 py-2 text-sm text-slate-300 bg-white/5 border border-white/10 rounded-xl hover:bg-white/10 transition-all">Cancel</button>
                  <button type="submit" class="px-5 py-2 bg-gradient-to-r from-violet-500 to-purple-600 text-white font-semibold rounded-xl text-sm shadow-lg disabled:opacity-50">{{ templateForm.id ? 'Update' : 'Create' }}</button>
                </div>
              </form>
            </div>
          </div>
        </Transition>

        <!-- Diff Modal -->
        <ProcedureDiffModal
          :show="showDiffModal"
          :old-version="diffOldVersion"
          :new-version="diffNewVersion"
          :old-data="diffOldData"
          :new-data="diffNewData"
          :change-reason="diffChangeReason"
          @close="showDiffModal = false" />

        <!-- Editor Modal -->
        <ProcedureEditorModal
          :show="showEditor"
          :procedure-id="procedure?.id || null"
          @close="showEditor = false"
          @saved="onEditorSaved" />
      </template>
    </main>
    <AppToast />
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import AppToast from '../components/AppToast.vue';
import ProcedureContent from '../components/procedure/ProcedureContent.vue';
import ProcedureDiffModal from '../components/procedure/ProcedureDiffModal.vue';
import ProcedureEditorModal from '../components/procedure/ProcedureEditorModal.vue';
import { currentUser as authUser } from '../router/index.js';

const route = useRoute();
const router = useRouter();
const procedure = ref(null);
const loading = ref(true);
const showFeedbackModal = ref(false);
const showTemplateModal = ref(false);
const showDiffModal = ref(false);
const showEditor = ref(false);
const linkCopied = ref(false);
let linkCopiedTimer = null;
const diffOldVersion = ref(0);
const diffNewVersion = ref(0);
const diffOldData = ref({});
const diffNewData = ref({});
const diffChangeReason = ref('');
const users = ref([]);

// Version browsing
const viewingVersion = ref(0);
const versionData = ref(null); // null = viewing latest, otherwise historical snapshot
const versionCache = {};

const currentUserId = computed(() => authUser.value?.id);
const isAdmin = computed(() => authUser.value?.is_admin);
const isViewingOldVersion = computed(
  () => procedure.value && viewingVersion.value < procedure.value.version,
);

// The data to display — either from versionData (historical) or procedure (latest)
const displayData = computed(() => {
  if (versionData.value) {
    return {
      title: versionData.value.title,
      purpose: versionData.value.purpose,
      requirements: versionData.value.requirements,
      content: versionData.value.content,
      change_reason: versionData.value.change_reason,
      publisher: versionData.value.publisher,
      published_at: versionData.value.published_at,
    };
  }
  return {
    title: procedure.value?.title,
    purpose: procedure.value?.purpose,
    requirements: procedure.value?.requirements,
    content: procedure.value?.content,
    change_reason: procedure.value?.change_reason,
    publisher: null,
    published_at: null,
  };
});

// Build a procedure-like object for ProcedureContent when viewing old versions
const displayProcedure = computed(() => {
  if (!procedure.value) return null;
  if (!versionData.value) return procedure.value;
  // Merge version data with procedure metadata
  return {
    ...procedure.value,
    title: versionData.value.title,
    purpose: versionData.value.purpose,
    requirements: versionData.value.requirements,
    content: versionData.value.content,
  };
});

const feedbackForm = reactive({ type: 'error', description: '', proposed_changes: '' });
const templateForm = reactive({ id: null, title: '', body: '', language: 'en' });

function fmtDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

async function fetchProcedure() {
  loading.value = true;
  try {
    const [procRes, usersRes] = await Promise.all([
      api.get(`/api/procedures/${route.params.id}`),
      isAdmin.value ? api.get('/api/users') : Promise.resolve({ data: [] }),
    ]);
    procedure.value = procRes.data;
    users.value = usersRes.data;
    // Determine which version to show
    const urlVersion = route.params.version ? parseInt(route.params.version) : null;
    if (urlVersion && urlVersion >= 1 && urlVersion <= procedure.value.version) {
      viewingVersion.value = urlVersion;
      if (urlVersion < procedure.value.version) {
        await loadVersionData(urlVersion);
      } else {
        versionData.value = null;
      }
    } else {
      viewingVersion.value = procedure.value.version;
      versionData.value = null;
    }
    // Cache the latest version
    if (procedure.value.change_reason) {
      versionCache[procedure.value.version] = {
        title: procedure.value.title,
        purpose: procedure.value.purpose,
        requirements: procedure.value.requirements,
        content: procedure.value.content,
        change_reason: procedure.value.change_reason,
        publisher: null,
        published_at: null,
      };
    }
  } catch {
    router.push('/procedures');
  } finally {
    loading.value = false;
  }
}

function goToVersion(v) {
  if (!procedure.value || v < 1 || v > procedure.value.version) return;
  if (v === procedure.value.version) {
    router.push(`/procedures/${procedure.value.id}`);
  } else {
    router.push(`/procedures/${procedure.value.id}/version/${v}`);
  }
}

async function loadVersionData(v) {
  // Check cache
  if (versionCache[v]) {
    versionData.value = versionCache[v];
    return;
  }
  // Fetch from API
  try {
    const res = await api.get(`/api/procedures/${procedure.value.id}/versions/${v}`);
    versionCache[v] = res.data;
    versionData.value = res.data;
  } catch {
    versionData.value = null;
  }
}

async function openDiffModal() {
  const newV = viewingVersion.value;
  const oldV = newV - 1;
  if (oldV < 1) return;

  // Get new version data
  let newData;
  if (newV === procedure.value.version) {
    newData = {
      title: procedure.value.title,
      purpose: procedure.value.purpose,
      requirements: procedure.value.requirements,
      content: procedure.value.content,
    };
  } else if (versionCache[newV]) {
    newData = versionCache[newV];
  } else {
    try {
      const r = await api.get(`/api/procedures/${procedure.value.id}/versions/${newV}`);
      versionCache[newV] = r.data;
      newData = r.data;
    } catch {
      newData = {
        title: procedure.value.title,
        purpose: procedure.value.purpose,
        requirements: procedure.value.requirements,
        content: procedure.value.content,
      };
    }
  }

  // Get old version data
  let oldData;
  if (versionCache[oldV]) {
    oldData = versionCache[oldV];
  } else {
    try {
      const r = await api.get(`/api/procedures/${procedure.value.id}/versions/${oldV}`);
      versionCache[oldV] = r.data;
      oldData = r.data;
    } catch {
      // Old version not in history — use empty data so diff shows everything as new
      oldData = { title: '', purpose: '', requirements: '', content: '' };
    }
  }

  diffOldVersion.value = oldV;
  diffNewVersion.value = newV;
  diffOldData.value = oldData;
  diffNewData.value = newData;
  diffChangeReason.value = newData.change_reason || displayData.value.change_reason || '';
  showDiffModal.value = true;
}

// Watch for route version changes (when navigating via URL)
watch(
  () => route.params.version,
  async (newVer) => {
    if (!procedure.value) return;
    const v = newVer ? parseInt(newVer) : procedure.value.version;
    if (v === viewingVersion.value) return;
    viewingVersion.value = v;
    if (v < procedure.value.version) {
      await loadVersionData(v);
    } else {
      versionData.value = null;
    }
  },
);

// Templates
function openTemplateModal(tmpl) {
  if (tmpl) {
    Object.assign(templateForm, {
      id: tmpl.id,
      title: tmpl.title,
      body: tmpl.body,
      language: tmpl.language,
    });
  } else {
    Object.assign(templateForm, { id: null, title: '', body: '', language: 'en' });
  }
  showTemplateModal.value = true;
}

async function saveTemplate() {
  try {
    if (templateForm.id) {
      const res = await api.put(`/api/procedure-templates/${templateForm.id}`, {
        title: templateForm.title,
        body: templateForm.body,
        language: templateForm.language,
      });
      const idx = procedure.value.response_templates.findIndex((t) => t.id === templateForm.id);
      if (idx >= 0) procedure.value.response_templates[idx] = res.data;
    } else {
      const res = await api.post(`/api/procedures/${procedure.value.id}/templates`, {
        title: templateForm.title,
        body: templateForm.body,
        language: templateForm.language,
      });
      procedure.value.response_templates.push(res.data);
    }
    showTemplateModal.value = false;
  } catch {}
}

async function deleteTemplate(tmpl) {
  try {
    await api.delete(`/api/procedure-templates/${tmpl.id}`);
    procedure.value.response_templates = procedure.value.response_templates.filter(
      (t) => t.id !== tmpl.id,
    );
  } catch {}
}

// Feedback
async function submitFeedback() {
  try {
    const res = await api.post(`/api/procedures/${procedure.value.id}/feedback`, {
      ...feedbackForm,
    });
    procedure.value.feedbacks.unshift(res.data);
    showFeedbackModal.value = false;
    Object.assign(feedbackForm, { type: 'error', description: '', proposed_changes: '' });
  } catch {}
}

function copyLink() {
  const url = `${window.location.origin}/procedures/${procedure.value.id}`;
  navigator.clipboard.writeText(url).then(() => {
    linkCopied.value = true;
    clearTimeout(linkCopiedTimer);
    linkCopiedTimer = setTimeout(() => {
      linkCopied.value = false;
    }, 2000);
  });
}

async function onEditorSaved(id) {
  showEditor.value = false;
  if (id === null) {
    // Procedure was deleted
    router.push('/procedures');
  } else {
    await fetchProcedure();
  }
}

onMounted(fetchProcedure);
onUnmounted(() => clearTimeout(linkCopiedTimer));
</script>
