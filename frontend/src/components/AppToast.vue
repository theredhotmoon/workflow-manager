<template>
  <!--
    Two live regions, always in the DOM so a screen reader is already listening when a message
    lands in one: errors are announced at once (role="alert"), everything else politely
    (role="status"). The Dismiss button sits outside both (laid over the card's corner), so it is
    not read out with the message. Nothing here takes focus. z-[110] keeps the toast above every
    dialog (the highest is z-[100]).
  -->
  <div class="fixed bottom-6 right-6 z-[110] max-w-sm">
    <div class="relative">
      <div v-for="region in regions" :key="region.role" :role="region.role" :aria-live="region.live" aria-atomic="true">
        <Transition
          enter-active-class="transition-all duration-300 ease-out"
          enter-from-class="translate-y-4 opacity-0 scale-95"
          enter-to-class="translate-y-0 opacity-100 scale-100"
          leave-active-class="transition-all duration-200 ease-in"
          leave-from-class="translate-y-0 opacity-100 scale-100"
          leave-to-class="translate-y-4 opacity-0 scale-95"
        >
          <div v-if="toast.show && isError === region.errors" :class="[
            'backdrop-blur-xl border rounded-xl pl-5 pr-12 py-4 shadow-2xl shadow-black/40 flex items-start gap-3',
            colorMap[toast.type]?.card ?? colorMap.success.card,
          ]">
            <!-- Icon -->
            <div :class="[
              'w-8 h-8 rounded-lg flex items-center justify-center shrink-0 mt-0.5',
              colorMap[toast.type]?.icon ?? colorMap.success.icon,
            ]">
              <!-- success -->
              <svg aria-hidden="true" v-if="toast.type === 'success'" class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              <!-- error -->
              <svg aria-hidden="true" v-else-if="toast.type === 'error'" class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
              <!-- warning -->
              <svg aria-hidden="true" v-else class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
              </svg>
            </div>

            <!-- Message -->
            <p :class="[
              'flex-1 min-w-0 text-sm font-medium leading-snug pt-1',
              colorMap[toast.type]?.text ?? colorMap.success.text,
            ]">{{ toast.message }}</p>
          </div>
        </Transition>
      </div>

      <!-- Dismiss button -->
      <Transition
        enter-active-class="transition-all duration-300 ease-out"
        enter-from-class="translate-y-4 opacity-0 scale-95"
        enter-to-class="translate-y-0 opacity-100 scale-100"
        leave-active-class="transition-all duration-200 ease-in"
        leave-from-class="translate-y-0 opacity-100 scale-100"
        leave-to-class="translate-y-4 opacity-0 scale-95"
      >
        <button
          v-if="toast.show"
          @click="dismissToast"
          title="Dismiss"
          aria-label="Dismiss notification"
          class="absolute top-[18px] right-5 text-slate-500 hover:text-slate-300 transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 rounded"
        >
          <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </Transition>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useToast } from '@/composables/useToast';

const { toast, dismissToast } = useToast();

const regions = [
  { role: 'status', live: 'polite', errors: false },
  { role: 'alert', live: 'assertive', errors: true },
];
const isError = computed(() => toast.value.type === 'error');

const colorMap = {
  success: {
    card: 'bg-slate-800/95 border-emerald-500/20',
    icon: 'bg-emerald-500/15',
    text: 'text-emerald-300',
  },
  error: {
    card: 'bg-slate-800/95 border-red-500/20',
    icon: 'bg-red-500/15',
    text: 'text-red-300',
  },
  warning: {
    card: 'bg-slate-800/95 border-amber-500/20',
    icon: 'bg-amber-500/15',
    text: 'text-amber-300',
  },
};
</script>
