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

function isFocusable(element) {
  // Skip what can't take focus right now: hidden by v-show / display:none, or not rendered.
  return element.getClientRects().length > 0;
}

/**
 * The trap itself, without deciding when it runs: `activate()` puts it on top of the modal
 * stack and moves focus in, `deactivate()` takes it off and gives focus back to the opener.
 * `useFocusTrap` drives it from an open flag; BaseModal drives it from mount / unmount.
 *
 * @param {import('vue').Ref<HTMLElement|null>} containerRef - the dialog element
 * @param {object} [options]
 * @param {() => void} [options.onEscape] - called on Escape while this is the top layer
 * @param {() => (HTMLElement|null|undefined)} [options.initialFocus] - element to focus on open;
 *        defaults to the first focusable element, then the container itself
 */
export function createFocusTrap(containerRef, options = {}) {
  const { onEscape, initialFocus } = options;
  let triggerElement = null;
  let releaseLayer = null;

  function getFocusableElements() {
    if (!containerRef.value) return [];
    return Array.from(containerRef.value.querySelectorAll(FOCUSABLE_SELECTORS)).filter(isFocusable);
  }

  function handleKeydown(event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      onEscape?.();
      return;
    }

    if (event.key !== 'Tab') return;

    const container = containerRef.value;
    const focusable = getFocusableElements();
    if (!container || !focusable.length) {
      // Nothing to tab to: keep focus on the dialog rather than let it leave.
      event.preventDefault();
      container?.focus();
      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;
    // Focus can sit outside the dialog (a click on the backdrop, a removed button) or on the
    // dialog element itself: the next Tab still lands inside.
    const outside = !container.contains(active);

    if (event.shiftKey) {
      // Shift+Tab: wrap backwards from first → last
      if (outside || active === first || active === container) {
        event.preventDefault();
        last.focus();
      }
    } else if (outside || active === last) {
      // Tab: wrap forwards from last → first
      event.preventDefault();
      first.focus();
    }
  }

  function focusInitial() {
    const container = containerRef.value;
    if (!container || container.contains(document.activeElement)) return;
    const target = initialFocus?.() || getFocusableElements()[0] || container;
    target.focus();
  }

  async function activate() {
    // Remember where focus was before the modal opened so we can restore it
    triggerElement = document.activeElement;
    releaseLayer?.();
    releaseLayer = pushModalLayer(handleKeydown);
    // Move focus inside the modal after Vue renders it
    await nextTick();
    if (releaseLayer) focusInitial();
  }

  function deactivate() {
    if (!releaseLayer) return;
    releaseLayer();
    releaseLayer = null;
    // Restore focus to the element that opened the modal, if it is still on the page
    if (triggerElement instanceof HTMLElement && triggerElement.isConnected) {
      triggerElement.focus();
    }
    triggerElement = null;
  }

  return { activate, deactivate, handleKeydown };
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
  const trap = createFocusTrap(containerRef, options);

  watch(isOpen, (opened) => {
    if (opened) trap.activate();
    else trap.deactivate();
  });

  onUnmounted(() => trap.deactivate());
}
