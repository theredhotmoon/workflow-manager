<!--
  The shell every hand-rolled modal shares: dialog semantics, a focus trap on the shared modal
  stack (useFocusTrap.js), Escape for the top layer only, and focus back to the opener on close.

  It is shown by mounting it (`<BaseModal v-if="open">`), so an enclosing <Transition> still
  animates it. The look stays with the caller: `overlay-class` is the fixed full-screen wrapper,
  `backdrop-class` an optional separate dimming layer, `panel-class` the dialog box. The slot
  gets `titleId` (and `descriptionId`) to put on its heading (and description).

  Closing is the caller's: `close` fires on Escape (unless `close-on-escape` is false) and on a
  backdrop click (unless `close-on-backdrop` is false), never while `busy`; the caller decides
  what that means.
-->
<template>
  <div :class="overlayClass" @pointerdown.capture="onPointerdown" @click.self="onBackdropClick">
    <div v-if="backdropClass" :class="backdropClass" aria-hidden="true" @click="onBackdropClick"></div>
    <div
      ref="panelRef"
      :role="role"
      aria-modal="true"
      :aria-labelledby="label ? undefined : titleId"
      :aria-label="label || undefined"
      :aria-describedby="described ? descriptionId : undefined"
      tabindex="-1"
      :class="[panelClass, 'outline-none']">
      <slot :titleId="titleId" :descriptionId="descriptionId" />
    </div>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { createFocusTrap } from '../composables/useFocusTrap.js';

const props = defineProps({
  /** Classes of the fixed full-screen wrapper (it also takes `close-on-backdrop` clicks). */
  overlayClass: {
    type: String,
    default: 'fixed inset-0 z-50 flex items-center justify-center px-4',
  },
  /** Classes of a separate backdrop layer behind the panel; none when empty. */
  backdropClass: { type: String, default: '' },
  /** Classes of the dialog box itself. */
  panelClass: { type: String, default: '' },
  /** `alertdialog` for confirmations that interrupt the user. */
  role: {
    type: String,
    default: 'dialog',
    validator: (value) => ['dialog', 'alertdialog'].includes(value),
  },
  /** Accessible name when there is no visible heading to point at. */
  label: { type: String, default: '' },
  /** The slot puts `descriptionId` on a description: point aria-describedby at it. */
  described: { type: Boolean, default: false },
  closeOnBackdrop: { type: Boolean, default: true },
  closeOnEscape: { type: Boolean, default: true },
  /** CSS selector (inside the panel) of the element to focus on open; else the first focusable. */
  initialFocus: { type: String, default: '' },
  /**
   * Where focus goes on close if the opener has left the page: a CSS selector (document-wide) or
   * a function returning the element. Default: the dialog the opener was in, else <main>.
   */
  returnFocus: { type: [String, Function], default: '' },
  /**
   * A request is in flight: Escape and backdrop clicks do not close the dialog, so a stray key or
   * click can't drop a form mid-save. The caller's own Cancel / close buttons stay enabled (an
   * explicit way out); its save handler must cope with the dialog closing before the response.
   */
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const titleId = useId();
const descriptionId = useId();
const panelRef = ref(null);

// A click counts as a backdrop click only if the press started on the backdrop too: dragging a
// text selection out of a field and releasing over the backdrop makes the browser fire `click`
// on the overlay (the common ancestor), and that must not close the dialog.
let pressedOn = null;

function onPointerdown(event) {
  pressedOn = event.target;
}

function onBackdropClick(event) {
  const startedHere = pressedOn === event.currentTarget;
  pressedOn = null;
  if (startedHere && props.closeOnBackdrop && !props.busy) emit('close');
}

function resolveReturnFocus() {
  if (typeof props.returnFocus === 'function') return props.returnFocus();
  return props.returnFocus ? document.querySelector(props.returnFocus) : null;
}

// Escape is swallowed even when it does not close: it must not reach a layer underneath.
const trap = createFocusTrap(panelRef, {
  onEscape: () => {
    if (props.closeOnEscape && !props.busy) emit('close');
  },
  returnFocus: resolveReturnFocus,
  initialFocus: () =>
    props.initialFocus ? panelRef.value?.querySelector(props.initialFocus) : null,
});

onMounted(() => trap.activate());
onBeforeUnmount(() => trap.deactivate());
</script>
