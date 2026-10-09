<template>
  <Transition
    enter-active-class="transition-all duration-300 ease-out"
    enter-from-class="opacity-0"
    enter-to-class="opacity-100"
    leave-active-class="transition-all duration-200 ease-in"
    leave-from-class="opacity-100"
    leave-to-class="opacity-0">
    <div
      v-if="isOpen"
      class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm"
      @click.self="onCancel">
      <div
        ref="modalRef"
        role="dialog"
        aria-modal="true"
        aria-labelledby="confirm-modal-title"
        class="bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 transition-all">
        <!-- Icon + Title -->
        <div class="flex items-start gap-3 mb-3">
          <div :class="['w-9 h-9 rounded-xl flex items-center justify-center shrink-0',
            danger ? 'bg-red-500/15' : 'bg-indigo-500/15']">
            <svg v-if="danger" class="w-4.5 h-4.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <svg v-else class="w-4.5 h-4.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div>
            <h3 id="confirm-modal-title" class="text-white font-bold text-base leading-tight">{{ title }}</h3>
            <p v-if="message" class="text-slate-400 text-sm mt-1 leading-relaxed">{{ message }}</p>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-end gap-3 mt-5">
          <button
            @click="onCancel"
            class="px-5 py-2 text-sm font-medium text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
            Cancel
          </button>
          <button
            @click="onConfirm"
            :class="['px-5 py-2 text-sm font-semibold text-white rounded-xl shadow-lg transition-all focus:outline-none focus:ring-2',
              danger
                ? 'bg-red-600 hover:bg-red-700 shadow-red-500/25 focus:ring-red-500/50'
                : 'bg-violet-600 hover:bg-violet-700 shadow-violet-500/25 focus:ring-violet-500/50']">
            {{ confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref } from 'vue';
import { useConfirmModal } from '../composables/useConfirm.js';
import { useFocusTrap } from '../composables/useFocusTrap.js';

const { isOpen, title, message, confirmLabel, danger, onConfirm, onCancel } = useConfirmModal();

// The confirm usually opens over another dialog (an editor, a form): as the top layer of the
// shared modal stack it alone gets Escape (= Cancel) and Tab. Focus starts on Cancel, the safer
// default for destructive dialogs, and returns to where it was on close.
const modalRef = ref(null);
useFocusTrap(modalRef, isOpen, { onEscape: onCancel });
</script>
