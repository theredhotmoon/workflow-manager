import { nextTick, onUnmounted, watch } from 'vue'

const FOCUSABLE_SELECTORS = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ')

/**
 * Traps keyboard focus inside a modal element while it is open.
 *
 * @param {import('vue').Ref<HTMLElement|null>} containerRef - template ref pointing at the modal root element
 * @param {import('vue').Ref<boolean>}          isOpen       - reactive flag that controls modal visibility
 * @param {object}                               options
 * @param {() => void}                           [options.onEscape] - callback invoked when the user presses Escape
 */
export function useFocusTrap(containerRef, isOpen, options = {}) {
  const { onEscape } = options
  let triggerElement = null

  function getFocusableElements() {
    if (!containerRef.value) return []
    return Array.from(containerRef.value.querySelectorAll(FOCUSABLE_SELECTORS))
  }

  function handleKeydown(event) {
    if (event.key === 'Escape') {
      event.preventDefault()
      onEscape?.()
      return
    }

    if (event.key !== 'Tab') return

    const focusable = getFocusableElements()
    if (!focusable.length) return

    const first = focusable[0]
    const last = focusable[focusable.length - 1]

    if (event.shiftKey) {
      // Shift+Tab: wrap backwards from first → last
      if (document.activeElement === first) {
        event.preventDefault()
        last.focus()
      }
    } else {
      // Tab: wrap forwards from last → first
      if (document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }
  }

  watch(isOpen, async (opened) => {
    if (opened) {
      // Remember where focus was before the modal opened so we can restore it
      triggerElement = document.activeElement
      document.addEventListener('keydown', handleKeydown)
      // Move focus inside the modal after Vue renders it
      await nextTick()
      const focusable = getFocusableElements()
      if (focusable.length) focusable[0].focus()
    } else {
      document.removeEventListener('keydown', handleKeydown)
      // Restore focus to the element that triggered the modal
      if (triggerElement instanceof HTMLElement) {
        triggerElement.focus()
        triggerElement = null
      }
    }
  })

  onUnmounted(() => {
    document.removeEventListener('keydown', handleKeydown)
  })
}
