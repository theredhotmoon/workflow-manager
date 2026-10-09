<!--
  The shell every hand-rolled modal shares: dialog semantics, a focus trap on the shared modal
  stack (useFocusTrap.js), Escape for the top layer only, and focus back to the opener on close.

  It is shown by mounting it (`<BaseModal v-if="open">`), so an enclosing <Transition> still
  animates it. The look stays with the caller: `overlay-class` is the fixed full-screen wrapper,
  `backdrop-class` an optional separate dimming layer, `panel-class` the dialog box. The slot
  gets `titleId` (and `descriptionId`) to put on its heading (and description).

  Closing is the caller's: `close` fires on Escape (unless `close-on-escape` is false) and on a
  backdrop click (unless `close-on-backdrop` is false); the caller decides what that means.
-->
<template>
  <div :class="overlayClass" @click.self="onBackdropClick">
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
});

const emit = defineEmits(['close']);

const titleId = useId();
const descriptionId = useId();
const panelRef = ref(null);

function onBackdropClick() {
  if (props.closeOnBackdrop) emit('close');
}

// Escape is swallowed even when it does not close: it must not reach a layer underneath.
const trap = createFocusTrap(panelRef, {
  onEscape: () => {
    if (props.closeOnEscape) emit('close');
  },
  initialFocus: () =>
    props.initialFocus ? panelRef.value?.querySelector(props.initialFocus) : null,
});

onMounted(() => trap.activate());
onBeforeUnmount(() => trap.deactivate());
</script>
