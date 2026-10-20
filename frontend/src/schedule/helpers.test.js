import { expect, test } from 'vitest';
import {
  cloneEmployee,
  describeArc,
  formatClosureDate,
  getInitials,
  groupShiftsByGroup,
  isOvernightShift,
  minutesToAngle,
  polarToCart,
} from './helpers.js';

test('minutesToAngle maps a day onto a full circle', () => {
  expect(minutesToAngle(0)).toBe(0);
  expect(minutesToAngle(6 * 60)).toBe(90);
  expect(minutesToAngle(12 * 60)).toBe(180);
  expect(minutesToAngle(24 * 60)).toBe(360);
});

test('polarToCart puts 0° at the top and turns clockwise', () => {
  const top = polarToCart(100, 100, 70, 0);
  expect(top.x).toBeCloseTo(100);
  expect(top.y).toBeCloseTo(30);
  const right = polarToCart(100, 100, 70, 90);
  expect(right.x).toBeCloseTo(170);
  expect(right.y).toBeCloseTo(100);
  const bottom = polarToCart(100, 100, 70, 180);
  expect(bottom.x).toBeCloseTo(100);
  expect(bottom.y).toBeCloseTo(170);
});

test('describeArc draws a small arc for up to 180° and a large one beyond', () => {
  const quarter = describeArc(100, 100, 70, 0, 90);
  expect(quarter).toMatch(/^M 100 30 A 70 70 0 0 1 170 /);
  const threeQuarters = describeArc(100, 100, 70, 0, 270);
  expect(threeQuarters).toContain('A 70 70 0 1 1');
});

test('describeArc stops a full circle just short of 360° so the path still draws', () => {
  const full = describeArc(100, 100, 70, 90, 450);
  const almost = describeArc(100, 100, 70, 90, 90 + 359.99);
  expect(full).toBe(almost);
  expect(full).toContain('A 70 70 0 1 1');
});

test('isOvernightShift: the end is before the start', () => {
  expect(isOvernightShift({ start_time: '22:00', end_time: '06:00' })).toBe(true);
  expect(isOvernightShift({ start_time: '06:00', end_time: '14:00' })).toBe(false);
  expect(isOvernightShift({ start_time: '06:00', end_time: '06:00' })).toBe(false);
  // Missing times are never overnight (falsy, as the template and the drop checks read it).
  expect(isOvernightShift({ start_time: null, end_time: '06:00' })).toBeFalsy();
  expect(isOvernightShift({ start_time: '22:00', end_time: '' })).toBeFalsy();
});

test('getInitials takes the first letters of up to two words, upper-cased', () => {
  expect(getInitials('Anna Kowalska')).toBe('AK');
  expect(getInitials('jan maria rokita')).toBe('JM');
  expect(getInitials('Ola')).toBe('O');
  expect(getInitials('')).toBe('');
  expect(getInitials(null)).toBe('');
  expect(getInitials(undefined)).toBe('');
});

test('formatClosureDate reads a date or a datetime as a short local day', () => {
  expect(formatClosureDate('2026-10-12')).toBe('Mon, Oct 12');
  expect(formatClosureDate('2026-10-12T00:00:00.000000Z')).toBe('Mon, Oct 12');
});

test('groupShiftsByGroup keeps the order groups first appear in, and their shift order', () => {
  const shifts = [
    { id: 1, shift_group_id: 7, shift_group_name: 'Ops', shift_group_color: '#ff0000' },
    { id: 2, shift_group_id: 9, shift_group_name: 'Events', shift_group_color: null },
    { id: 3, shift_group_id: 7, shift_group_name: 'Ops', shift_group_color: '#ff0000' },
  ];
  expect(groupShiftsByGroup(shifts)).toEqual([
    { groupId: 7, name: 'Ops', color: '#ff0000', shifts: [shifts[0], shifts[2]] },
    { groupId: 9, name: 'Events', color: null, shifts: [shifts[1]] },
  ]);
});

test('groupShiftsByGroup puts shifts without a group under "default" / "Unknown"', () => {
  const shifts = [{ id: 1 }, { id: 2, shift_group_id: null }];
  expect(groupShiftsByGroup(shifts)).toEqual([
    { groupId: 'default', name: 'Unknown', color: null, shifts },
  ]);
  expect(groupShiftsByGroup([])).toEqual([]);
});

test('cloneEmployee turns a roster card into an unsaved chip', () => {
  expect(cloneEmployee({ id: 5, name: 'Anna', user_id: 5, user_name: 'Anna' })).toEqual({
    user_id: 5,
    user_name: 'Anna',
    id: null,
  });
  // A chip (no roster id/name) keeps its user.
  expect(cloneEmployee({ user_id: 8, user_name: 'Jan' })).toEqual({
    user_id: 8,
    user_name: 'Jan',
    id: null,
  });
});
