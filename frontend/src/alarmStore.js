// Alarm state shared by the header bell (AppLayout) and the Notifications page (AlarmsView): the
// unread count and the bell's latest alarms. Marking alarms read updates the badge at once; when
// the server refuses, the change is rolled back and the user is told.
//
// Kept free of api/toast imports so it can be unit tested: composables/useAlarms.js wires the real
// ones into one shared instance.
import { ref } from 'vue';

export function createAlarmStore({
  api,
  onError = () => {},
  now = () => new Date().toISOString(),
}) {
  const latest = ref([]);
  const unreadCount = ref(0);
  let refreshSeq = 0;

  // Every copy of an alarm on screen (the bell's list, the page's list) changes together.
  function copiesOf(alarm, lists) {
    const copies = new Set([alarm]);
    for (const list of [latest.value, ...lists]) {
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
    // A mark-read made while this was in flight is newer than its answer.
    if (seq !== refreshSeq) return;
    latest.value = latestRes.data;
    unreadCount.value = countRes.data.count;
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
      unreadCount.value += 1;
      onError(error, 'Could not mark the notification as read, try again');
      return false;
    }
  }

  /** Marks every alarm read; `lists` are other arrays on screen to update with the bell's list. */
  async function markAllRead(lists = []) {
    const changed = [];
    const readAt = now();
    for (const list of [latest.value, ...lists]) {
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
      unreadCount.value = previousCount;
      onError(error, 'Could not mark notifications as read, try again');
      return false;
    }
  }

  return { latest, unreadCount, refresh, markRead, markAllRead };
}
