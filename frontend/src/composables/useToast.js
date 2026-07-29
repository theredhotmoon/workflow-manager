import { ref } from 'vue';

// Module-level singleton — one toast state shared across the entire app.
const toast = ref({ show: false, message: '', type: 'success' });
let _timer = null;

export function useToast() {
  /**
   * @param {string} message
   * @param {'success'|'error'|'warning'} type
   * @param {number} duration  ms before auto-dismiss (default 4000)
   */
  function showToast(message, type = 'success', duration = 4000) {
    clearTimeout(_timer);
    toast.value = { show: true, message, type };
    _timer = setTimeout(dismissToast, duration);
  }

  function dismissToast() {
    clearTimeout(_timer);
    toast.value.show = false;
  }

  return { toast, showToast, dismissToast };
}
