// frontend/e2e/fixtures/api-setup.js
import { getXsrfHeader } from './auth.js';

// The API throttles per user (API_RATE_LIMIT, 60/min by default, 1000 in the dev stack); on a 429,
// wait out Retry-After and retry once.
async function send(context, method, url, options) {
  let res = await context.request[method](url, options);
  if (res.status() === 429) {
    const wait = Math.min(Number(res.headers()['retry-after']) || 60, 65);
    await new Promise((resolve) => setTimeout(resolve, wait * 1000));
    res = await context.request[method](url, options);
  }
  return res;
}

export async function createShiftGroupWithStreakRule(context, overrides = {}) {
  const headers = await getXsrfHeader(context);
  const groupRes = await send(context, 'post', '/api/shift-groups', {
    data: { name: 'Night Crew', color: '#6366f1', ...overrides.group },
    headers,
  });
  if (!groupRes.ok())
    throw new Error(`create shift group failed: ${groupRes.status()} ${await groupRes.text()}`);
  const groupId = (await groupRes.json()).id;

  const ruleRes = await send(context, 'put', `/api/shift-groups/${groupId}/streak-rules`, {
    data: { rules: [{ shift_type: 'night', streak_length: 3, rest_days: 2, ...overrides.rule }] },
    headers,
  });
  if (!ruleRes.ok())
    throw new Error(`create streak rule failed: ${ruleRes.status()} ${await ruleRes.text()}`);

  return groupId;
}

export async function generateMonthShifts(context, { monthKey, shiftGroupId }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', '/api/schedule/generate', {
    data: {
      month: monthKey,
      shift_group_id: shiftGroupId,
      shifts_per_day: 1,
      shift_times: [{ start_time: '08:00', end_time: '16:00' }],
      work_mode_weekday: 'office',
      work_mode_weekend: 'office',
    },
    headers,
  });
  if (!res.ok()) throw new Error(`generate month failed: ${res.status()} ${await res.text()}`);
}

export async function assignShift(context, { shiftId, userId }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', '/api/schedule/assign', {
    data: { shift_id: shiftId, user_id: userId },
    headers,
  });
  if (!res.ok()) throw new Error(`assign shift failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

export async function getEmployeeIdByEmail(context, email) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'get', '/api/employees', { headers });
  if (!res.ok()) throw new Error(`list employees failed: ${res.status()} ${await res.text()}`);
  const employees = await res.json();
  const match = employees.find((e) => e.email === email);
  if (!match) throw new Error(`no employee found with email ${email}`);
  return match.id;
}

export async function getShiftGroupsAndShifts(context, monthKey, shiftGroupId) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'get', '/api/schedule', {
    params: { month: monthKey, shift_group_id: shiftGroupId },
    headers,
  });
  if (!res.ok()) throw new Error(`fetch schedule failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

export async function updateShiftGroup(context, groupId, fields) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'put', `/api/shift-groups/${groupId}`, {
    data: fields,
    headers,
  });
  if (!res.ok()) throw new Error(`update shift group failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

export async function listShiftGroups(context) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'get', '/api/shift-groups', { headers });
  if (!res.ok()) throw new Error(`list shift groups failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

export async function setShiftTypes(context, { shiftGroupId, month, shiftNumber, shiftType }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'put', '/api/schedule/shift-types', {
    data: { shift_group_id: shiftGroupId, month, shift_number: shiftNumber, shift_type: shiftType },
    headers,
  });
  if (!res.ok()) throw new Error(`set shift types failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

export async function setShiftType(context, { shiftId, shiftType }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'put', `/api/schedule/shifts/${shiftId}/type`, {
    data: { shift_type: shiftType },
    headers,
  });
  if (!res.ok()) throw new Error(`set shift type failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}
