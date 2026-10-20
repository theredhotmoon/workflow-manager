import { expect, test } from 'vitest';
import { businessTodayStr, delegationStatus, isDelegationCompleted } from './delegationStatus.js';

// Delegations are whole days in Europe/Warsaw; the API serialises their dates as UTC midnights
// ("2026-10-09T00:00:00.000000Z"), and the last day is included.
const del = (start, end) => ({
  start_datetime: `${start}T00:00:00.000000Z`,
  end_datetime: `${end}T00:00:00.000000Z`,
});

test('a delegation is ongoing on its last day, and completed the day after', () => {
  const d = del('2026-10-07', '2026-10-09');
  expect(delegationStatus(d, new Date('2026-10-09T10:00:00Z'))).toBe('ongoing');
  expect(isDelegationCompleted(d, new Date('2026-10-09T10:00:00Z'))).toBe(false);
  expect(delegationStatus(d, new Date('2026-10-10T10:00:00Z'))).toBe('completed');
  expect(isDelegationCompleted(d, new Date('2026-10-10T10:00:00Z'))).toBe(true);
});

test('upcoming before the first day; a single-day delegation is ongoing all that day', () => {
  const d = del('2026-10-09', '2026-10-09');
  expect(delegationStatus(d, new Date('2026-10-08T12:00:00Z'))).toBe('upcoming');
  // 00:30 and 23:30 Warsaw time (CEST, UTC+2) on the 9th.
  expect(delegationStatus(d, new Date('2026-10-08T22:30:00Z'))).toBe('ongoing');
  expect(delegationStatus(d, new Date('2026-10-09T21:30:00Z'))).toBe('ongoing');
  expect(delegationStatus(d, new Date('2026-10-09T22:30:00Z'))).toBe('completed');
});

test('uses the Warsaw date, not the UTC one, late in the evening', () => {
  // 23:30 in Warsaw on the 9th is still the 9th there, but 21:30 UTC; 00:30 on the 10th in
  // Warsaw is still the 9th in UTC.
  expect(businessTodayStr(new Date('2026-10-09T21:30:00Z'))).toBe('2026-10-09');
  expect(businessTodayStr(new Date('2026-10-09T22:30:00Z'))).toBe('2026-10-10');
  // Winter time (CET, UTC+1).
  expect(businessTodayStr(new Date('2026-12-31T23:30:00Z'))).toBe('2027-01-01');

  const endsOn9th = del('2026-10-01', '2026-10-09');
  expect(delegationStatus(endsOn9th, new Date('2026-10-09T22:30:00Z'))).toBe('completed');
});

test('accepts plain Y-m-d dates', () => {
  const d = { start_datetime: '2026-10-01', end_datetime: '2026-10-09' };
  expect(delegationStatus(d, new Date('2026-10-09T12:00:00Z'))).toBe('ongoing');
});
