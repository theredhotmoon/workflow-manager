import { effectScope } from 'vue';
import api from '../axios.js';
import {
  createPendingChangeRequestsStore,
  resetPendingOnUserChange,
} from '../pendingChangeRequestsStore.js';
import { currentUser } from '../router/index.js';

let store = null;

// One pending-count store for the whole app: the nav badge and the Change Requests screen share
// it. It is emptied whenever the signed-in user changes or signs out.
export function usePendingChangeRequests() {
  if (!store) {
    store = createPendingChangeRequestsStore({ api });
    // Detached scope: first called from a component's setup, but the watch must outlive it.
    effectScope(true).run(() => resetPendingOnUserChange(store, currentUser));
  }
  return store;
}
