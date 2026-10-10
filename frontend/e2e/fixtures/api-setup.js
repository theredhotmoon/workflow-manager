// frontend/e2e/fixtures/api-setup.js
import { getXsrfHeader } from './auth.js';

// The API throttles per user (API_RATE_LIMIT, 60/min by default, 1000 in the dev stack); on a 429,
// wait out Retry-After and retry once.
async function send(context, method, url, options = {}) {
  // Ask for JSON: without it Laravel answers a failed validation with a redirect that the request
  // follows to a 200, so a rejected setup call (422) would look like success.
  options = { ...options, headers: { Accept: 'application/json', ...options.headers } };
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

export async function setDayShiftCount(context, { date, count, shiftGroupId }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'put', '/api/schedule/day-shifts', {
    data: { date, count, shift_group_id: shiftGroupId },
    headers,
  });
  if (!res.ok()) throw new Error(`set day shift count failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

/**
 * Needs an admin context: /api/employees only returns colleagues' emails to admins (others get
 * just their own), so a non-admin context can't look anyone else up by email.
 */
export async function getEmployeeIdByEmail(context, email) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'get', '/api/employees', { headers });
  if (!res.ok()) throw new Error(`list employees failed: ${res.status()} ${await res.text()}`);
  const employees = await res.json();
  const withEmail = employees.filter((e) => 'email' in e);
  if (withEmail.length <= 1 && employees.length > 1) {
    throw new Error(
      `getEmployeeIdByEmail(${email}) needs an admin context: this login only sees its own email.`,
    );
  }
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

export async function createDelegation(context, { name, destination, start, end, userIds = [] }) {
  const headers = await getXsrfHeader(context);
  const data = {
    name,
    destination,
    start_datetime: `${start} 08:00:00`,
    end_datetime: `${end} 18:00:00`,
  };
  if (userIds.length) data.user_ids = userIds;
  const res = await send(context, 'post', '/api/delegations', { data, headers });
  if (!res.ok()) throw new Error(`create delegation failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

export async function createNewbiePeriod(context, { userId, startDate, endDate }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', '/api/newbie-periods', {
    data: { user_id: userId, start_date: startDate, end_date: endDate },
    headers,
  });
  if (!res.ok())
    throw new Error(`create newbie period failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

export async function listOfficeClosures(context, monthKey) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'get', '/api/office-closures', {
    params: { month: monthKey },
    headers,
  });
  if (!res.ok())
    throw new Error(`list office closures failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

export async function createOfficeClosure(context, { startDate, endDate = startDate, title }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', '/api/office-closures', {
    data: { start_date: startDate, end_date: endDate, title },
    headers,
  });
  if (!res.ok())
    throw new Error(`create office closure failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

// Availability entries always belong to the logged-in user of `context`.
export async function createAvailability(context, { type, startDate, endDate = startDate }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', '/api/user-availabilities', {
    data: { type, start_date: startDate, end_date: endDate },
    headers,
  });
  if (!res.ok()) throw new Error(`create availability failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

export async function createOnCallAssignment(context, { shiftGroupId, userId, startsAt, endsAt }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', '/api/on-call-assignments', {
    data: { shift_group_id: shiftGroupId, user_id: userId, starts_at: startsAt, ends_at: endsAt },
    headers,
  });
  if (!res.ok()) throw new Error(`create on-call failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

export async function createProcedure(context, { title, ownerId, content = `<p>${title}</p>` }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', '/api/procedures', {
    data: { title, owner_id: ownerId, content },
    headers,
  });
  if (!res.ok()) throw new Error(`create procedure failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

// Notifies the procedure owner (alarm type PROCEDURE_NEW_QUESTION_ADDED) when asked by someone else.
export async function askFaqQuestion(context, procedureId, question) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', `/api/procedures/${procedureId}/faq`, {
    data: { question },
    headers,
  });
  if (!res.ok()) throw new Error(`ask FAQ question failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

// Notifies the procedure owner (alarm type PROCEDURE_NEW_ISSUE_REPORTED) when reported by someone else.
export async function reportProcedureIssue(context, procedureId, description) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', `/api/procedures/${procedureId}/feedback`, {
    data: { type: 'error', description },
    headers,
  });
  if (!res.ok()) throw new Error(`report issue failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

export async function createChecklistTemplate(
  context,
  { name, requiresConfirmation = false, procedureIds = [], itemLabels = [] },
) {
  const headers = await getXsrfHeader(context);
  let items = [
    { label: `${name} item`, type: 'checkbox', requires_confirmation: requiresConfirmation },
  ];
  if (procedureIds.length) {
    items = procedureIds.map((procedureId, i) => ({
      label: `${name} item ${i + 1}`,
      type: 'checkbox',
      requires_confirmation: requiresConfirmation,
      procedure_id: procedureId,
    }));
  } else if (itemLabels.length) {
    items = itemLabels.map((label) => ({
      label,
      type: 'checkbox',
      requires_confirmation: requiresConfirmation,
    }));
  }
  const res = await send(context, 'post', '/api/checklist-templates', {
    data: { name, is_active: true, groups: [{ name: `${name} group`, items }] },
    headers,
  });
  if (!res.ok()) throw new Error(`create template failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

// Files as whoever is logged in on `context`. A non-admin may only file as the outgoing user; an
// admin may file for someone else, but the incoming user must differ from the outgoing user and
// from the filing admin (the API answers 422 otherwise).
export async function createChecklistSubmission(
  context,
  { template, date, outgoingUserId, incomingUserId = null },
) {
  const headers = await getXsrfHeader(context);
  const responses = template.groups
    .flatMap((group) => group.items)
    .map((item) => ({ checklist_item_id: item.id, value: 'true' }));
  const res = await send(context, 'post', '/api/checklist-submissions', {
    data: {
      checklist_template_id: template.id,
      date,
      outgoing_user_id: outgoingUserId,
      incoming_user_id: incomingUserId,
      responses,
    },
    headers,
  });
  if (!res.ok()) throw new Error(`create submission failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

// A throwaway user for specs that change a user's state (never use the seeded logins for that).
export async function createUser(context, { name, email, password = 'password123' }) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', '/api/users', {
    data: { name, email, password, password_confirmation: password },
    headers,
  });
  if (!res.ok()) throw new Error(`create user failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

export async function archiveDelegation(context, delegationId) {
  const headers = await getXsrfHeader(context);
  const res = await send(context, 'post', `/api/delegations/${delegationId}/archive`, { headers });
  if (!res.ok()) throw new Error(`archive delegation failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}

// Publishes a new version through the draft flow, which also stores a version snapshot.
export async function publishProcedureVersion(
  context,
  procedureId,
  { title, content = `<p>${title}</p>`, changeReason },
) {
  const headers = await getXsrfHeader(context);
  const draft = await send(context, 'put', `/api/procedures/${procedureId}/draft`, {
    data: { title, content },
    headers,
  });
  if (!draft.ok()) throw new Error(`save draft failed: ${draft.status()} ${await draft.text()}`);
  const res = await send(context, 'post', `/api/procedures/${procedureId}/publish`, {
    data: { change_reason: changeReason },
    headers,
  });
  if (!res.ok()) throw new Error(`publish failed: ${res.status()} ${await res.text()}`);
  return await res.json();
}
