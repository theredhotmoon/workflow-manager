import { expect, test } from 'vitest';
import {
  formatDuration,
  shiftDurationMinutes,
  timeToMinutes,
  totalShiftHours,
} from './shiftHours.js';

test('reads HH:MM and HH:MM:SS as minutes after midnight', () => {
  expect(timeToMinutes('00:00')).toBe(0);
  expect(timeToMinutes('07:30')).toBe(450);
  expect(timeToMinutes('23:59:00')).toBe(1439);
  expect(timeToMinutes(null)).toBe(0);
});

test('a day shift is its end minus its start', () => {
  expect(shiftDurationMinutes('06:00', '14:00')).toBe(8 * 60);
  expect(shiftDurationMinutes('08:15:00', '16:45:00')).toBe(8 * 60 + 30);
});

test('an overnight shift wraps past midnight', () => {
  expect(shiftDurationMinutes('22:00', '06:00')).toBe(8 * 60);
  expect(shiftDurationMinutes('22:00', '12:00')).toBe(14 * 60);
  expect(shiftDurationMinutes('18:00', '00:00')).toBe(6 * 60);
});

test('the same start and end time is a full 24-hour shift', () => {
  expect(shiftDurationMinutes('08:00', '08:00')).toBe(24 * 60);
});

test('a shift without both times counts as zero', () => {
  expect(shiftDurationMinutes(null, '14:00')).toBe(0);
  expect(shiftDurationMinutes('06:00', '')).toBe(0);
});

test('totals round to the nearest hour across day and overnight shifts', () => {
  const shifts = [
    { start_time: '06:00', end_time: '14:00' }, // 8 h
    { start_time: '22:00', end_time: '06:00' }, // 8 h overnight
    { start_time: '09:00', end_time: '09:40' }, // 40 min
    { start_time: null, end_time: null }, // no times: ignored
  ];
  expect(totalShiftHours(shifts)).toBe(17); // 16 h 40 min
  expect(totalShiftHours([{ start_time: '09:00', end_time: '09:20' }])).toBe(0);
  expect(totalShiftHours([])).toBe(0);
});

test('formats minutes as hours, with minutes only when there are some', () => {
  expect(formatDuration(0)).toBe('0h');
  expect(formatDuration(8 * 60)).toBe('8h');
  expect(formatDuration(7 * 60 + 30)).toBe('7h 30m');
  expect(formatDuration(25 * 60 + 5)).toBe('25h 5m');
});
