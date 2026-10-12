import { createAlarmStore } from '../alarmStore.js';
import { describeApiError } from '../apiErrors.js';
import api from '../axios.js';
import { useToast } from './useToast.js';

let store = null;

// One alarm store for the whole app: the header bell and the Notifications page share it.
export function useAlarms() {
  if (!store) {
    const { showToast } = useToast();
    store = createAlarmStore({
      api,
      onError(error, fallback) {
        const e = describeApiError(error, fallback);
        if (!e.silent) showToast(e.message, 'error', 6000);
      },
    });
  }
  return store;
}
