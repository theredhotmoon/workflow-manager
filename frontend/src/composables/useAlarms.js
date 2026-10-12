import { effectScope } from 'vue';
import { createAlarmStore, resetAlarmsOnUserChange } from '../alarmStore.js';
import { describeApiError } from '../apiErrors.js';
import api from '../axios.js';
import { currentUser } from '../router/index.js';
import { useToast } from './useToast.js';

let store = null;

// One alarm store for the whole app: the header bell and the Notifications page share it. It is
// emptied whenever the signed-in user changes or signs out.
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
    // Detached scope: first called from a component's setup, but the watch must outlive it.
    effectScope(true).run(() => resetAlarmsOnUserChange(store, currentUser));
  }
  return store;
}
