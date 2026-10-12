// Alarm state shared by the header bell (AppLayout) and the Notifications page (AlarmsView): the
// unread count and the bell's latest alarms. Marking alarms read updates the badge at once; when
// the server refuses, the change is rolled back, the count is re-read from the server and the
// user is told.
//
// Kept free of api/toast/router imports so it can be unit tested: composables/useAlarms.js wires
// the real ones into one shared instance.
import { ref, watch } from 'vue';

export function createAlarmStore({
  api,
  onError = () => {},
  now = () => new Date().toISOString(),
}) {
  const latest = ref([]);
  const unreadCount = ref(0);
  // Lists a page shows (refs to arrays of alarms), e.g. the Notifications page's: a mark-read from
  // the bell updates them too.
  const pageLists = new Set();
  let refreshSeq = 0;

  function allLists(extra = []) {
    return [latest.value, ...[...pageLists].map((list) => list.value ?? []), ...extra];
  }

  // Every copy of an alarm on screen (the bell's list, the page's list) changes together.
  function copiesOf(alarm, lists) {
    const copies = new Set([alarm]);
    for (const list of allLists(lists)) {
      for (const a of list) if (a.id === alarm.id) copies.add(a);
    }
    return [...copies];
  }

  async function refresh() {
    const seq = ++refreshSeq;
    const [latestRes, countRes] = await Promise.all([
      api.get('/api/alarms/latest'),
      api.get('/api/alarms/unread-count'),
    ]);
    // A mark-read or a reset made while this was in flight is newer than its answer.
    if (seq !== refreshSeq) return;
    latest.value = latestRes.data;
    unreadCount.value = countRes.data.count;
  }

  // After a refused mark-read the local count is a guess (a poll may have landed in between):
  // ask the server; only if that fails too, fall back to the local correction.
  async function resync(fallbackCount) {
    try {
      await refresh();
    } catch {
      unreadCount.value = fallbackCount();
    }
  }

  /** Marks one alarm read; `lists` are other arrays on screen holding the same alarm. */
  async function markRead(alarm, lists = []) {
    if (alarm.read_at) return true;
    const copies = copiesOf(alarm, lists).filter((a) => !a.read_at);
    const readAt = now();
    for (const a of copies) a.read_at = readAt;
    unreadCount.value = Math.max(0, unreadCount.value - 1);
    refreshSeq++; // an in-flight refresh would bring the old state back
    try {
      await api.put(`/api/alarms/${alarm.id}/read`);
      return true;
    } catch (error) {
      for (const a of copies) a.read_at = null;
      onError(error, 'Could not mark the notification as read, try again');
      await resync(() => unreadCount.value + 1);
      return false;
    }
  }

  /** Marks every alarm read; `lists` are other arrays on screen to update with the bell's list. */
  async function markAllRead(lists = []) {
    const changed = [];
    const readAt = now();
    for (const list of allLists(lists)) {
      for (const a of list) {
        if (!a.read_at) {
          a.read_at = readAt;
          changed.push(a);
        }
      }
    }
    const previousCount = unreadCount.value;
    unreadCount.value = 0;
    refreshSeq++;
    try {
      await api.put('/api/alarms/read-all');
      return true;
    } catch (error) {
      for (const a of changed) a.read_at = null;
      onError(error, 'Could not mark notifications as read, try again');
      await resync(() => previousCount);
      return false;
    }
  }

  /** A page's list of alarms (a ref) that bell actions should update too; returns the undo. */
  function registerList(listRef) {
    pageLists.add(listRef);
    return () => pageLists.delete(listRef);
  }

  /** Forgets everything (another user, or nobody, is signed in); a refresh in flight is dropped. */
  function reset() {
    refreshSeq++;
    latest.value = [];
    unreadCount.value = 0;
  }

  return { latest, unreadCount, refresh, markRead, markAllRead, registerList, reset };
}

/**
 * Resets the store whenever the signed-in user changes or signs out, so one user's alarms are
 * never shown to the next one on the same tab (logout is an in-app navigation; the store
 * survives it). Synchronous, so nothing renders in between.
 */
export function resetAlarmsOnUserChange(store, userRef) {
  return watch(
    () => userRef.value?.id ?? null,
    (id, previous) => {
      if (id !== previous) store.reset();
    },
    { flush: 'sync' },
  );
}
