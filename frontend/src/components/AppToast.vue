<template>
  <Transition
    enter-active-class="transition-all duration-300 ease-out"
    enter-from-class="translate-y-4 opacity-0 scale-95"
    enter-to-class="translate-y-0 opacity-100 scale-100"
    leave-active-class="transition-all duration-200 ease-in"
    leave-from-class="translate-y-0 opacity-100 scale-100"
    leave-to-class="translate-y-4 opacity-0 scale-95"
  >
    <div v-if="toast.show" class="fixed bottom-6 right-6 z-[70] max-w-sm">
      <div :class="[
        'backdrop-blur-xl border rounded-xl px-5 py-4 shadow-2xl shadow-black/40 flex items-start gap-3',
        colorMap[toast.type]?.card ?? colorMap.success.card,
      ]">
        <!-- Icon -->
        <div :class="[
          'w-8 h-8 rounded-lg flex items-center justify-center shrink-0 mt-0.5',
          colorMap[toast.type]?.icon ?? colorMap.success.icon,
        ]">
          <!-- success -->
          <svg v-if="toast.type === 'success'" class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          <!-- error -->
          <svg v-else-if="toast.type === 'error'" class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
          <!-- warning -->
          <svg v-else class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
          </svg>
        </div>

        <!-- Message -->
        <p :class="[
          'flex-1 min-w-0 text-sm font-medium leading-snug pt-1',
          colorMap[toast.type]?.text ?? colorMap.success.text,
        ]">{{ toast.message }}</p>

        <!-- Dismiss button -->
        <button
          @click="dismissToast"
          title="Dismiss"
          class="text-slate-500 hover:text-slate-300 transition-colors duration-150 shrink-0 mt-0.5 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 rounded"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { useToast } from '@/composables/useToast';

const { toast, dismissToast } = useToast();

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
