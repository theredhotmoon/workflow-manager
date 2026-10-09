import { expect, test } from '@playwright/test';
import { localDateStr, todayStr } from './dates.js';

// Node reads TZ when a Date needs it, so setting it here moves this worker east of UTC, where the
// old toISOString() formatting showed the previous day for local midnight.
const originalTz = process.env.TZ;

test.describe('local calendar dates', () => {
  test.beforeAll(() => {
    process.env.TZ = 'Europe/Warsaw';
  });

  test.afterAll(() => {
    if (originalTz === undefined) delete process.env.TZ;
    else process.env.TZ = originalTz;
  });

  test('runs east of UTC', () => {
    expect(new Date(2026, 9, 15).getTimezoneOffset()).toBeLessThan(0);
  });

  test('formats local midnight as that same day (regression: toISOString gave the 14th)', () => {
    const midnight = new Date(2026, 9, 15);
    expect(localDateStr(midnight)).toBe('2026-10-15');
    expect(midnight.toISOString().split('T')[0]).toBe('2026-10-14'); // the bug this replaces
  });

  test('pads months and days and keeps the year at the turn of the year', () => {
    expect(localDateStr(new Date(2027, 0, 1))).toBe('2027-01-01');
    expect(localDateStr(new Date(2026, 11, 31, 23, 59))).toBe('2026-12-31');
  });

  test('today is the local day', () => {
    expect(todayStr()).toBe(localDateStr(new Date()));
  });
});
