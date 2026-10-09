import { nextTick, onUnmounted, watch } from 'vue';

const FOCUSABLE_SELECTORS = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ');

// Open modals form a stack (an editor, a dialog over it, a confirm over that). One document
// listener hands each key to the top layer only: Escape closes the dialog the user is looking at,
// not every layer below it, and Tab wraps inside the top dialog.
const layers = [];

/** Sends a keydown to the top layer. Exported for tests; the document listener calls it. */
export function handleModalKeydown(event) {
  layers[layers.length - 1]?.(event);
}

/** Puts a layer on top of the stack. Returns a function that removes it (idempotent). */
export function pushModalLayer(onKeydown) {
  const layer = (event) => onKeydown(event);
  layers.push(layer);
  if (layers.length === 1 && typeof document !== 'undefined') {
    document.addEventListener('keydown', handleModalKeydown);
  }
  return () => {
    const index = layers.indexOf(layer);
    if (index === -1) return;
    layers.splice(index, 1);
    if (!layers.length && typeof document !== 'undefined') {
      document.removeEventListener('keydown', handleModalKeydown);
    }
  };
}

export function modalLayerCount() {
  return layers.length;
}

/**
 * Traps keyboard focus inside a modal element while it is open.
 *
 * @param {import('vue').Ref<HTMLElement|null>} containerRef - template ref pointing at the modal root element
 * @param {import('vue').Ref<boolean>}          isOpen       - reactive flag that controls modal visibility
 * @param {object}                               options
 * @param {() => void}                           [options.onEscape] - callback invoked when the user presses Escape
 *                                                    while this modal is the top one
 */
export function useFocusTrap(containerRef, isOpen, options = {}) {
  const { onEscape } = options;
  let triggerElement = null;
  let releaseLayer = null;

  function getFocusableElements() {
    if (!containerRef.value) return [];
    return Array.from(containerRef.value.querySelectorAll(FOCUSABLE_SELECTORS));
  }

  function handleKeydown(event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      onEscape?.();
      return;
    }

    if (event.key !== 'Tab') return;

    const focusable = getFocusableElements();
    if (!focusable.length) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey) {
      // Shift+Tab: wrap backwards from first → last
      if (document.activeElement === first) {
        event.preventDefault();
        last.focus();
      }
    } else {
      // Tab: wrap forwards from last → first
      if (document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  }

  watch(isOpen, async (opened) => {
    if (opened) {
      // Remember where focus was before the modal opened so we can restore it
      triggerElement = document.activeElement;
      releaseLayer?.();
      releaseLayer = pushModalLayer(handleKeydown);
      // Move focus inside the modal after Vue renders it
      await nextTick();
      const focusable = getFocusableElements();
      if (focusable.length) focusable[0].focus();
    } else {
      releaseLayer?.();
      releaseLayer = null;
      // Restore focus to the element that triggered the modal
      if (triggerElement instanceof HTMLElement) {
        triggerElement.focus();
        triggerElement = null;
      }
    }
  });

  onUnmounted(() => {
    releaseLayer?.();
    releaseLayer = null;
  });
}
