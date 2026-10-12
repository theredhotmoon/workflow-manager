// The pending change-request count behind the "Change Requests" nav badge (AppLayout) and the
// screen itself (ChangeRequestsView): polled with the alarm bell, and re-read after an acknowledge.
//
// Kept free of api/router imports so it can be unit tested: composables/usePendingChangeRequests.js
// wires the real ones into one shared instance.
import { ref, watch } from 'vue';

export function createPendingChangeRequestsStore({ api }) {
  const count = ref(0);
  let seq = 0;

  /** Re-reads the count; an older answer arriving after a newer one (or a reset) is dropped. */
  async function refresh() {
    const mine = ++seq;
    const res = await api.get('/api/shift-change-requests/pending-count');
    if (mine !== seq) return;
    count.value = Number(res.data?.count) || 0;
  }

  /** Forgets the count (another user, or nobody, is signed in); a refresh in flight is dropped. */
  function reset() {
    seq++;
    count.value = 0;
  }

  return { count, refresh, reset };
}

/** Empties the store whenever the signed-in user changes or signs out (synchronously). */
export function resetPendingOnUserChange(store, userRef) {
  return watch(
    () => userRef.value?.id ?? null,
    (id, previous) => {
      if (id !== previous) store.reset();
    },
    { flush: 'sync' },
  );
}
