// A user's `role` is the only source of admin / schedule-maintainer status (the API's `is_admin`
// is derived from it and kept only for old clients). Every permission check in the SPA goes here.

export const ROLE_OPTIONS = [
  { value: 'admin', label: 'Admin' },
  { value: 'schedule_maintainer', label: 'Schedule maintainer' },
  { value: 'employee', label: 'Employee' },
];

export function isAdmin(user) {
  return user?.role === 'admin';
}

export function canManageSchedule(user) {
  return isAdmin(user) || user?.role === 'schedule_maintainer';
}

export function roleLabel(role) {
  return ROLE_OPTIONS.find((o) => o.value === role)?.label ?? 'Employee';
}
