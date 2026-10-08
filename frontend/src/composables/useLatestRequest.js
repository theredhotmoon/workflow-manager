// frontend/src/composables/useLatestRequest.js
//
// Guards a piece of state against stale responses. When a view fires overlapping requests for the
// same state (month, group, filter or page changes), only the latest one may apply its result,
// clear `loading` or report an error — otherwise a slower, older response that arrives last
// overwrites what the user is looking at.
//
//   const scheduleRequests = useLatestRequest();
//   async function fetchSchedule() {
//     const token = scheduleRequests.begin();
//     loading.value = true;
//     try {
//       const res = await api.get('/api/schedule', { params });
//       if (!scheduleRequests.isCurrent(token)) return;
//       schedule.value = res.data;
//     } catch {
//       if (scheduleRequests.isCurrent(token)) showToast('Failed to load schedule.', 'error');
//     } finally {
//       if (scheduleRequests.isCurrent(token)) loading.value = false;
//     }
//   }
//
// Use one instance per piece of state: independent loads (the schedule and a delegation overlay)
// each get their own, so one never discards the other. Call invalidate() when the state is set
// without a request (deselect, close, a local save, a cache hit) to drop an in-flight response.
// No AbortController on purpose: the ordering guard is needed anyway, and an abort would make every
// catch block tell "cancelled" from "failed".
export function useLatestRequest() {
  let current = null;
  return {
    begin() {
      current = Symbol('request');
      return current;
    },
    isCurrent(token) {
      return token === current;
    },
    invalidate() {
      current = null;
    },
  };
}
