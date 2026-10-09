<template>
  <div class="mt-4 bg-white/5 border border-white/10 rounded-xl p-3 relative" :class="{ 'opacity-50 pointer-events-none': disabled }">
    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wide mb-2 flex items-center gap-1.5">
      <svg class="w-3.5 h-3.5 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
      </svg>
      Auto-Fill Shifts
    </h4>

    <div class="flex items-center gap-2">
      <select v-model="selectedMode"
        class="flex-1 px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-xs focus:outline-none focus:ring-2 focus:ring-violet-500/50 appearance-none">
        <option value="random" class="bg-slate-800">Random Coverage</option>
        <option value="rotating" class="bg-slate-800">Rotating Subteam</option>
        <option value="polish-law" class="bg-slate-800">Polish Law</option>
      </select>
      <button @click="openModal"
        class="px-3 py-2 bg-gradient-to-r from-violet-500 to-purple-600 hover:from-violet-600 hover:to-purple-700 text-white font-semibold rounded-lg text-xs transition-all shadow-lg shadow-violet-500/25 flex items-center gap-1 shrink-0">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
        </svg>
        Run
      </button>
    </div>

    <!-- Disabled tooltip -->
    <div v-if="disabled" class="absolute inset-0 flex items-center justify-center cursor-not-allowed z-10" style="pointer-events: auto;"
      :title="'Generate a schedule first before auto-filling shifts'">
    </div>

    <!-- Random Coverage Modal -->
    <Teleport to="body">
      <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0">
      <div v-if="showRandomModal" class="fixed inset-0 z-[70] flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showRandomModal = false">
        <div ref="randomModalRef"
          role="dialog"
          aria-modal="true"
          aria-labelledby="random-modal-title"
          class="bg-slate-800/95 border border-white/10 rounded-2xl shadow-2xl w-full max-w-4xl mx-4">
          <div class="p-6 pb-3 border-b border-white/10">
            <h3 id="random-modal-title" class="text-lg font-bold text-white flex items-center gap-2">
              <svg class="w-5 h-5 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
              Random Coverage
            </h3>
            <p class="text-slate-400 text-sm mt-1">Assigns random available users to every shift</p>
          </div>

          <div class="grid grid-cols-2 divide-x divide-white/10">
            <!-- Left: Player selection -->
            <div class="p-6">
              <PlayerSelectionPanel v-model="playerSelection" :monthKey="monthKey" />
            </div>
            <!-- Right: Mode settings -->
            <div class="p-6 space-y-4">
              <div class="flex items-center gap-1.5 mb-1">
                <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                </svg>
                <span class="text-sm font-semibold text-slate-300">Settings</span>
              </div>
              <ShiftCountInputs v-model:weekday="config.users_per_shift_weekday" v-model:weekend="config.users_per_shift_weekend" />
            </div>
          </div>

          <div class="p-6 pt-3 border-t border-white/10 flex items-center justify-end gap-3">
            <button @click="showRandomModal = false"
              class="px-5 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">
              Cancel
            </button>
            <button @click="runRandom" :disabled="running"
              class="px-5 py-2 bg-gradient-to-r from-violet-500 to-purple-600 hover:from-violet-600 hover:to-purple-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 flex items-center gap-2">
              <svg v-if="running" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
              </svg>
              Auto-Fill
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Rotating Subteam Modal -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0">
      <div v-if="showRotatingModal" class="fixed inset-0 z-[70] flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showRotatingModal = false">
        <div ref="rotatingModalRef"
          role="dialog"
          aria-modal="true"
          aria-labelledby="rotating-modal-title"
          class="bg-slate-800/95 border border-white/10 rounded-2xl shadow-2xl w-full max-w-4xl mx-4">
          <div class="p-6 pb-3 border-b border-white/10">
            <h3 id="rotating-modal-title" class="text-lg font-bold text-white flex items-center gap-2">
              <svg class="w-5 h-5 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
              Rotating Subteam
            </h3>
            <p class="text-slate-400 text-sm mt-1">Builds subteams that rotate every N days</p>
          </div>

          <div class="grid grid-cols-2 divide-x divide-white/10">
            <!-- Left: Player selection -->
            <div class="p-6">
              <PlayerSelectionPanel v-model="playerSelection" :monthKey="monthKey" />
            </div>
            <!-- Right: Mode settings -->
            <div class="p-6 space-y-4">
              <div class="flex items-center gap-1.5 mb-1">
                <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                </svg>
                <span class="text-sm font-semibold text-slate-300">Settings</span>
              </div>
              <ShiftCountInputs v-model:weekday="config.users_per_shift_weekday" v-model:weekend="config.users_per_shift_weekend" />

              <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Rotation period (days)</label>
                <div class="flex items-center gap-3">
                  <input v-model.number="config.rotation_days" type="range" min="1" max="14" step="1"
                    class="flex-1 h-2 bg-white/10 rounded-lg appearance-none cursor-pointer accent-violet-500" />
                  <span class="w-10 text-center text-lg font-bold text-white tabular-nums">{{ config.rotation_days }}</span>
                </div>
                <p class="text-[10px] text-slate-500 mt-1">A new subteam will take over every {{ config.rotation_days }} day{{ config.rotation_days > 1 ? 's' : '' }}</p>
              </div>
            </div>
          </div>

          <div class="p-6 pt-3 border-t border-white/10 flex items-center justify-end gap-3">
            <button @click="showRotatingModal = false"
              class="px-5 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">
              Cancel
            </button>
            <button @click="runRotating" :disabled="running"
              class="px-5 py-2 bg-gradient-to-r from-violet-500 to-purple-600 hover:from-violet-600 hover:to-purple-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 flex items-center gap-2">
              <svg v-if="running" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
              </svg>
              Auto-Fill
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Polish Law Modal -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0">
      <div v-if="showPolishLawModal" class="fixed inset-0 z-[70] flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showPolishLawModal = false">
        <div ref="polishLawModalRef"
          role="dialog"
          aria-modal="true"
          aria-labelledby="polishlaw-modal-title"
          class="bg-slate-800/95 border border-white/10 rounded-2xl shadow-2xl w-full max-w-4xl mx-4">
          <div class="p-6 pb-3 border-b border-white/10">
            <h3 id="polishlaw-modal-title" class="text-lg font-bold text-white flex items-center gap-2">
              <svg class="w-5 h-5 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
              </svg>
              Polish Law
            </h3>
            <p class="text-slate-400 text-sm mt-1">Assigns shifts per Polish Labor Code — 11h daily rest, 35h weekly rest, max 48h/week</p>
          </div>

          <div class="grid grid-cols-2 divide-x divide-white/10">
            <!-- Left: Player selection -->
            <div class="p-6">
              <PlayerSelectionPanel v-model="playerSelection" :monthKey="monthKey" />
            </div>
            <!-- Right: Mode settings -->
            <div class="p-6 space-y-4">
              <div class="flex items-center gap-1.5 mb-1">
                <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                </svg>
                <span class="text-sm font-semibold text-slate-300">Settings</span>
              </div>
              <ShiftCountInputs v-model:weekday="config.users_per_shift_weekday" v-model:weekend="config.users_per_shift_weekend" />

              <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Rotation period (days)</label>
                <div class="flex items-center gap-3">
                  <input v-model.number="config.rotation_days" type="range" min="1" max="14" step="1"
                    class="flex-1 h-2 bg-white/10 rounded-lg appearance-none cursor-pointer accent-violet-500" />
                  <span class="w-10 text-center text-lg font-bold text-white tabular-nums">{{ config.rotation_days }}</span>
                </div>
                <p class="text-[10px] text-slate-500 mt-1">Fair rotation period for workload distribution</p>
              </div>
            </div>
          </div>

          <div class="p-6 pt-3 border-t border-white/10 flex items-center justify-end gap-3">
            <button @click="showPolishLawModal = false"
              class="px-5 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">
              Cancel
            </button>
            <button @click="runPolishLaw" :disabled="running"
              class="px-5 py-2 bg-gradient-to-r from-violet-500 to-purple-600 hover:from-violet-600 hover:to-purple-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 flex items-center gap-2">
              <svg v-if="running" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
              </svg>
              Auto-Fill
            </button>
          </div>
        </div>
      </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import api from '../axios.js';
import { useFocusTrap } from '../composables/useFocusTrap.js';
import PlayerSelectionPanel from './PlayerSelectionPanel.vue';
import ShiftCountInputs from './ShiftCountInputs.vue';

const props = defineProps({
  monthKey: { type: String, required: true },
  shiftGroupId: { type: Number, default: null },
  disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['filled', 'toast']);

const selectedMode = ref('random');
const showRandomModal = ref(false);
const showRotatingModal = ref(false);
const showPolishLawModal = ref(false);
const running = ref(false);

// Focus traps for the three Auto-Fill modals
const randomModalRef = ref(null);
const rotatingModalRef = ref(null);
const polishLawModalRef = ref(null);
useFocusTrap(randomModalRef, showRandomModal, {
  onEscape: () => {
    showRandomModal.value = false;
  },
});
useFocusTrap(rotatingModalRef, showRotatingModal, {
  onEscape: () => {
    showRotatingModal.value = false;
  },
});
useFocusTrap(polishLawModalRef, showPolishLawModal, {
  onEscape: () => {
    showPolishLawModal.value = false;
  },
});

const config = ref({
  users_per_shift_weekday: 1,
  users_per_shift_weekend: 1,
  rotation_days: 3,
});

const playerSelection = ref({ mode: 'all', userIds: [] });

function openModal() {
  if (props.disabled) return;
  if (selectedMode.value === 'random') {
    showRandomModal.value = true;
  } else if (selectedMode.value === 'rotating') {
    showRotatingModal.value = true;
  } else if (selectedMode.value === 'polish-law') {
    showPolishLawModal.value = true;
  }
}

async function runRandom() {
  running.value = true;
  showRandomModal.value = false;
  try {
    const res = await api.post('/api/schedule/auto-assign/random', {
      month: props.monthKey,
      shift_group_id: props.shiftGroupId,
      users_per_shift_weekday: config.value.users_per_shift_weekday,
      users_per_shift_weekend: config.value.users_per_shift_weekend,
      player_mode: playerSelection.value.mode,
      player_ids: playerSelection.value.userIds,
    });
    emit('toast', res.data.message, res.data.warnings?.length > 0 ? 'warning' : 'success');
    emit('filled');
  } catch (err) {
    const msg = err.response?.data?.message || 'Auto-assign failed.';
    emit('toast', msg, 'error');
  } finally {
    running.value = false;
  }
}

async function runRotating() {
  running.value = true;
  showRotatingModal.value = false;
  try {
    const res = await api.post('/api/schedule/auto-assign/rotating', {
      month: props.monthKey,
      shift_group_id: props.shiftGroupId,
      users_per_shift_weekday: config.value.users_per_shift_weekday,
      users_per_shift_weekend: config.value.users_per_shift_weekend,
      rotation_days: config.value.rotation_days,
      player_mode: playerSelection.value.mode,
      player_ids: playerSelection.value.userIds,
    });
    emit('toast', res.data.message, res.data.warnings?.length > 0 ? 'warning' : 'success');
    emit('filled');
  } catch (err) {
    const msg = err.response?.data?.message || 'Auto-assign failed.';
    emit('toast', msg, 'error');
  } finally {
    running.value = false;
  }
}

async function runPolishLaw() {
  running.value = true;
  showPolishLawModal.value = false;
  try {
    const res = await api.post('/api/schedule/auto-assign/polish-law', {
      month: props.monthKey,
      shift_group_id: props.shiftGroupId,
      users_per_shift_weekday: config.value.users_per_shift_weekday,
      users_per_shift_weekend: config.value.users_per_shift_weekend,
      rotation_days: config.value.rotation_days,
      player_mode: playerSelection.value.mode,
      player_ids: playerSelection.value.userIds,
    });
    const type = res.data.warnings?.length > 0 ? 'warning' : 'success';
    emit('toast', res.data.message, type);
    emit('filled');
  } catch (err) {
    const msg = err.response?.data?.message || 'Auto-assign failed.';
    emit('toast', msg, 'error');
  } finally {
    running.value = false;
  }
}
</script>
