<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <h1 class="text-2xl font-bold text-white mb-1">Procedures to Learn</h1>
      <p class="text-slate-400 text-sm mb-6">New or updated procedures you haven't read yet.</p>

      <div v-if="loading" class="text-center py-16"><p class="text-slate-400">Loading...</p></div>

      <div v-else-if="!procedures.length" class="text-center py-16">
        <p class="text-3xl mb-3">🎉</p>
        <p class="text-white font-semibold text-lg">You're all caught up!</p>
        <p class="text-slate-400 text-sm mt-1">No new procedures to learn right now.</p>
      </div>

      <div v-else class="space-y-3">
        <div v-for="p in procedures" :key="p.id"
          @click="openProcedure(p)"
          class="block bg-white/5 border border-white/10 rounded-2xl p-5 hover:border-indigo-500/30 hover:bg-white/8 transition-all group cursor-pointer">
          <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
              <div class="flex items-center gap-2 mb-1.5">
                <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider bg-indigo-500/15 text-indigo-400 border border-indigo-500/20 rounded-full shrink-0">
                  {{ p.identifier }}
                </span>
                <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider bg-emerald-500/15 text-emerald-400 border border-emerald-500/20 rounded-full shrink-0">
                  v{{ p.version }}
                </span>
              </div>
              <h2 class="text-white font-semibold text-lg group-hover:text-indigo-300 transition-colors">{{ p.title }}</h2>
              <p class="text-slate-500 text-xs mt-1">
                Owner: {{ p.owner?.name }} · Updated {{ fmtDate(p.updated_at) }}
              </p>
            </div>
            <div class="shrink-0 text-slate-500 group-hover:text-indigo-400 transition-colors mt-2">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
            </div>
          </div>
        </div>
      </div>

      <!-- Procedure Detail Modal -->
      <Transition enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
        enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-start justify-center pt-8 px-4 pb-4 overflow-y-auto">
          <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="closeModal"></div>
          <div class="relative w-full max-w-5xl bg-slate-900/98 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 my-4">
            <!-- Modal Header -->
            <div class="sticky top-0 z-10 bg-slate-900/98 backdrop-blur-xl border-b border-white/10 rounded-t-2xl px-6 py-4 flex items-center justify-between">
              <div class="flex items-center gap-3 min-w-0">
                <span v-if="modalProcedure" class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider bg-indigo-500/15 text-indigo-400 border border-indigo-500/20 rounded-full shrink-0">
                  {{ modalProcedure.identifier }}
                </span>
                <h2 class="text-lg font-bold text-white truncate">{{ modalProcedure?.title || 'Loading...' }}</h2>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <router-link v-if="modalProcedure" :to="`/procedures/${modalProcedure.id}`"
                  class="px-3 py-1.5 text-xs text-slate-400 bg-white/5 border border-white/10 rounded-lg hover:bg-white/10 hover:text-white transition-all">
                  Open full page →
                </router-link>
                <button @click="closeModal" class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
              </div>
            </div>

            <!-- Modal Body -->
            <div class="px-6 py-6">
              <div v-if="modalLoading" class="text-center py-16">
                <svg class="animate-spin w-8 h-8 text-indigo-400 mx-auto" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
              </div>
              <ProcedureContent v-else-if="modalProcedure"
                :procedure="modalProcedure"
                :user-id="currentUserId"
                :is-admin="false"
                :editable="false"
                :users="[]"
                :viewing-version="modalProcedure.version"
                :max-version="modalProcedure.version"  />
            </div>
          </div>
        </div>
      </Transition>
    </main>
  </AppLayout>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import ProcedureContent from '../components/procedure/ProcedureContent.vue';
import { currentUser } from '../router/index.js';
import { computed } from 'vue';

const route = useRoute();
const router = useRouter();
const procedures = ref([]);
const loading = ref(true);
const showModal = ref(false);
const modalLoading = ref(false);
const modalProcedure = ref(null);

const currentUserId = computed(() => currentUser.value?.id);

function fmtDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

async function openProcedure(p) {
  showModal.value = true;
  modalLoading.value = true;
  modalProcedure.value = null;
  // Update hash
  window.location.hash = `procedure-${p.id}`;
  try {
    const res = await api.get(`/api/procedures/${p.id}`);
    modalProcedure.value = res.data;
  } catch {
    showModal.value = false;
  } finally {
    modalLoading.value = false;
  }
}

function closeModal() {
  showModal.value = false;
  modalProcedure.value = null;
  window.location.hash = '';
}

// Open modal from hash URL on mount
async function checkHash() {
  const hash = window.location.hash;
  const match = hash.match(/^#procedure-(\d+)$/);
  if (match) {
    const id = parseInt(match[1]);
    showModal.value = true;
    modalLoading.value = true;
    try {
      const res = await api.get(`/api/procedures/${id}`);
      modalProcedure.value = res.data;
    } catch {
      showModal.value = false;
    } finally {
      modalLoading.value = false;
    }
  }
}

onMounted(async () => {
  try { procedures.value = (await api.get('/api/procedures-to-learn')).data; }
  catch {}
  finally { loading.value = false; }
  await checkHash();
});

// Watch for hash changes (back/forward)
window.addEventListener('hashchange', checkHash);
</script>
