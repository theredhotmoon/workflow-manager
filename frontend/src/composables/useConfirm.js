/**
 * Singleton confirm-dialog composable.
 *
 * Usage (any component):
 *   import { confirm } from '@/composables/useConfirm.js'
 *   const ok = await confirm({ title: 'Delete', message: 'Are you sure?', danger: true })
 *   if (!ok) return
 *
 * Mount <ConfirmModal /> once in App.vue.
 */
import { ref } from 'vue';

// Module-level singleton state
const isOpen = ref(false);
const title = ref('Confirm');
const message = ref('');
const confirmLabel = ref('Confirm');
const danger = ref(true);
let resolveFn = null;

/**
 * Opens the confirm dialog and returns a Promise<boolean>.
 * Resolves true when the user clicks the confirm button, false on cancel/close.
 *
 * @param {Object} opts
 * @param {string} [opts.title]         - Dialog heading
 * @param {string} [opts.message]       - Body text
 * @param {string} [opts.confirmLabel]  - Label for the confirm button (default: 'Confirm')
 * @param {boolean} [opts.danger]       - Use red/destructive button style (default: true)
 */
export function confirm(opts = {}) {
  // Only one dialog at a time: a caller still waiting on an earlier one gets "not confirmed"
  // rather than a promise that never settles.
  if (resolveFn) {
    const previous = resolveFn;
    resolveFn = null;
    previous(false);
  }
  title.value = opts.title ?? 'Confirm';
  message.value = opts.message ?? '';
  confirmLabel.value = opts.confirmLabel ?? 'Confirm';
  danger.value = opts.danger ?? true;
  isOpen.value = true;
  return new Promise((resolve) => {
    resolveFn = resolve;
  });
}

/**
 * Used internally by ConfirmModal.vue to access state and respond to user actions.
 */
export function useConfirmModal() {
  function onConfirm() {
    isOpen.value = false;
    resolveFn?.(true);
    resolveFn = null;
  }

  function onCancel() {
    isOpen.value = false;
    resolveFn?.(false);
    resolveFn = null;
  }

  return { isOpen, title, message, confirmLabel, danger, onConfirm, onCancel };
}
