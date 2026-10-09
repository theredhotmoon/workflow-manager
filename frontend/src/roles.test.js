import { expect, test } from '@playwright/test';
import { canManageSchedule, isAdmin, roleLabel } from './roles.js';

test.describe('roles', () => {
  test('only role decides admin status, not a stale is_admin flag', () => {
    expect(isAdmin({ role: 'admin' })).toBe(true);
    expect(isAdmin({ role: 'employee', is_admin: true })).toBe(false);
    expect(isAdmin({ role: 'schedule_maintainer' })).toBe(false);
    expect(isAdmin(null)).toBe(false);
  });

  test('admins and schedule maintainers manage the schedule', () => {
    expect(canManageSchedule({ role: 'admin' })).toBe(true);
    expect(canManageSchedule({ role: 'schedule_maintainer' })).toBe(true);
    expect(canManageSchedule({ role: 'employee' })).toBe(false);
    expect(canManageSchedule(undefined)).toBe(false);
  });

  test('labels each role', () => {
    expect(roleLabel('admin')).toBe('Admin');
    expect(roleLabel('schedule_maintainer')).toBe('Schedule maintainer');
    expect(roleLabel('employee')).toBe('Employee');
  });
});
