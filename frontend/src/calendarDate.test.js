import { expect, test } from '@playwright/test';
import { formatCalendarDate } from './dates.js';

// F-a: delegation dates are calendar days. West of UTC, `new Date('2026-11-02')` (UTC midnight)
// is still the 1st locally, so the old formatter showed the day before.
const originalTz = process.env.TZ;

test.describe('formatCalendarDate west of UTC', () => {
  test.beforeAll(() => {
    process.env.TZ = 'America/Los_Angeles';
  });

  test.afterAll(() => {
    if (originalTz === undefined) delete process.env.TZ;
    else process.env.TZ = originalTz;
  });

  test('runs west of UTC, where the old formatting showed the previous day', () => {
    expect(new Date(2026, 10, 2).getTimezoneOffset()).toBeGreaterThan(0);
    const old = new Date('2026-11-02').toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });
    expect(old).toBe('Nov 1, 2026'); // the bug this replaces
  });

  test('a Y-m-d date is that day', () => {
    expect(formatCalendarDate('2026-11-02')).toBe('Nov 2, 2026');
    expect(formatCalendarDate('2027-01-01')).toBe('Jan 1, 2027');
  });

  test('a UTC midnight from a date column is that day', () => {
    expect(formatCalendarDate('2026-11-02T00:00:00.000000Z')).toBe('Nov 2, 2026');
    expect(formatCalendarDate('2026-12-31 00:00:00')).toBe('Dec 31, 2026');
  });

  test('empty or unreadable values give an empty string', () => {
    expect(formatCalendarDate(null)).toBe('');
    expect(formatCalendarDate('')).toBe('');
    expect(formatCalendarDate('soon')).toBe('');
  });
});

test.describe('formatCalendarDate east of UTC', () => {
  test.beforeAll(() => {
    process.env.TZ = 'Pacific/Kiritimati'; // UTC+14
  });

  test.afterAll(() => {
    if (originalTz === undefined) delete process.env.TZ;
    else process.env.TZ = originalTz;
  });

  test('is the same day far east of UTC', () => {
    expect(formatCalendarDate('2026-11-02T00:00:00.000000Z')).toBe('Nov 2, 2026');
  });
});
