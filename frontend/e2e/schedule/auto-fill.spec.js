// frontend/e2e/schedule/auto-fill.spec.js
import {
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getShiftGroupsAndShifts,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('schedule/auto-fill');
reserve.months('2027-11', '2027-12', '2028-01');

const MONTH = reserve.month('2027-06');
const EMPLOYEE = 'Regular Employee';

// Same selector list as src/composables/useFocusTrap.js, so first/last match the trap's own.
const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ');

async function openAutoFill(page, groupName) {
  await page.getByRole('button', { name: new RegExp(groupName) }).click();
  // The sidebar's "Auto-Fill" accordion toggle (exact, to skip the "Auto-Fill Shifts" heading).
  await page.getByRole('button', { name: 'Auto-Fill', exact: true }).click();
}

test.describe('Auto-Fill', () => {
  test.beforeEach(async ({ adminPage }) => {
    // Wide viewport avoids the known calendar overlap that intercepts clicks at 1280x720.
    await adminPage.setViewportSize({ width: 1920, height: 1080 });
  });

  test('runs Random Coverage restricted to the selected player and fills shifts', async ({
    adminPage,
  }) => {
    const groupName = 'AutoFill Team';
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: groupName } });
    await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });

    await adminPage.goto(`/schedule/${MONTH}`);
    await openAutoFill(adminPage, groupName);

    await adminPage.getByRole('button', { name: 'Run' }).click();
    const dialog = adminPage.getByRole('dialog', { name: 'Random Coverage' });
    await expect(dialog).toBeVisible();

    await dialog.getByRole('button', { name: 'Only selected' }).click();
    // Every employee starts selected, so clear the list first; otherwise .check() is a no-op.
    await dialog.getByRole('button', { name: 'Clear', exact: true }).click();
    await dialog.getByPlaceholder('Search employees...').fill('Regular');
    await dialog.getByText(EMPLOYEE).locator('..').getByRole('checkbox').check();

    await dialog.getByRole('button', { name: 'Auto-Fill' }).click();
    await expect(adminPage.getByText(/assigned|filled/i)).toBeVisible({ timeout: 10000 });

    // Server side: shifts got assigned, and only to the selected player.
    const { schedule } = await getShiftGroupsAndShifts(context, MONTH, groupId);
    const assignments = Object.values(schedule)
      .flat()
      .flatMap((shift) => shift.assignments);
    expect(assignments.length).toBeGreaterThan(0);
    for (const a of assignments) expect(a.user_name).toBe(EMPLOYEE);
  });

  test('the Random Coverage modal traps focus: Tab wraps both ways, Escape closes, focus is restored', async ({
    adminPage,
  }) => {
    const groupName = 'FocusTrap Team';
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: groupName } });
    await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });

    await adminPage.goto(`/schedule/${MONTH}`);
    await openAutoFill(adminPage, groupName);

    const runButton = adminPage.getByRole('button', { name: 'Run' });
    await runButton.click();

    const dialog = adminPage.getByRole('dialog', { name: 'Random Coverage' });
    await expect(dialog).toBeVisible();

    const focusable = dialog.locator(FOCUSABLE);
    const first = focusable.first();
    const last = focusable.last();

    // Focus enters the trap on the first focusable element.
    await expect(first).toBeFocused();

    // Tab from the last wraps to the first.
    await last.focus();
    await expect(last).toBeFocused();
    await adminPage.keyboard.press('Tab');
    await expect(first).toBeFocused();

    // Shift+Tab from the first wraps to the last.
    await adminPage.keyboard.press('Shift+Tab');
    await expect(last).toBeFocused();

    // Escape closes it and restores focus to the trigger.
    await adminPage.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(runButton).toBeFocused();
  });
});

// ---------------------------------------------------------------------------------------------
// Caps and the remaining modes. Each test owns its group and month (2027-11, 2027-12, 2028-01).
// ---------------------------------------------------------------------------------------------

const isWeekend = (date) => [0, 6].includes(new Date(`${date}T12:00:00`).getDay());

// Per-shift assignment counts, split into weekday and weekend buckets.
function countsByDayType(schedule) {
  const weekday = [];
  const weekend = [];
  for (const [date, shifts] of Object.entries(schedule)) {
    for (const shift of shifts) {
      (isWeekend(date) ? weekend : weekday).push(shift.assignments.length);
    }
  }
  return { weekday, weekend };
}

// Sets a ShiftCountInputs range slider; fill() dispatches the input event the component listens to.
async function setCaps(dialog, { weekday, weekend }) {
  const sliders = dialog.locator('input[type="range"]');
  await sliders.nth(0).fill(String(weekday));
  await sliders.nth(1).fill(String(weekend));
  await expect(dialog.getByText(String(weekday), { exact: true }).first()).toBeVisible();
}

// Positive guards first (assignments exist and both caps are exercised), then the caps themselves.
function expectCaps(weekday, weekend) {
  expect(weekday.length).toBeGreaterThan(0);
  expect(weekend.length).toBeGreaterThan(0);
  expect(weekday.some((n) => n === 2)).toBe(true);
  expect(weekend.some((n) => n === 1)).toBe(true);
  for (const n of weekday) expect(n).toBeLessThanOrEqual(2);
  for (const n of weekend) expect(n).toBeLessThanOrEqual(1);
}

async function runMode(page, { groupName, month, mode, modalTitle, caps, rotationDays }) {
  await page.goto(`/schedule/${month}`);
  await openAutoFill(page, groupName);
  await page.locator('select').filter({ hasText: 'Random Coverage' }).selectOption(mode);
  await page.getByRole('button', { name: 'Run' }).click();
  const dialog = page.getByRole('dialog', { name: modalTitle });
  await expect(dialog).toBeVisible();
  await setCaps(dialog, caps);
  if (rotationDays) await dialog.locator('input[type="range"]').nth(2).fill(String(rotationDays));
  await dialog.getByRole('button', { name: 'Auto-Fill' }).click();
  await expect(dialog).toBeHidden();
}

test.describe('Auto-Fill caps and modes', () => {
  test.beforeEach(async ({ adminPage }) => {
    await adminPage.setViewportSize({ width: 1920, height: 1080 });
  });

  test('Random Coverage respects the Users-per-shift caps for weekdays and weekends', async ({
    adminPage,
  }) => {
    const groupName = 'Caps Random Team';
    const month = '2027-11';
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: groupName } });
    await generateMonthShifts(context, { monthKey: month, shiftGroupId: groupId });

    // The slider default is already 1, so weekdays are set to 2 to prove the input is wired
    // through to the request; weekends stay at 1.
    await runMode(adminPage, {
      groupName,
      month,
      mode: 'random',
      modalTitle: 'Random Coverage',
      caps: { weekday: 2, weekend: 1 },
    });
    await expect(adminPage.getByText(/assigned|filled/i)).toBeVisible({ timeout: 10000 });

    const { schedule } = await getShiftGroupsAndShifts(context, month, groupId);
    const { weekday, weekend } = countsByDayType(schedule);
    expectCaps(weekday, weekend);
  });

  test('Rotating Subteam assigns shifts and respects the Users-per-shift caps', async ({
    adminPage,
  }) => {
    const groupName = 'Caps Rotating Team';
    const month = '2027-12';
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: groupName } });
    await generateMonthShifts(context, { monthKey: month, shiftGroupId: groupId });

    await runMode(adminPage, {
      groupName,
      month,
      mode: 'rotating',
      modalTitle: 'Rotating Subteam',
      caps: { weekday: 2, weekend: 1 },
      rotationDays: 2,
    });
    await expect(adminPage.getByText(/assigned|filled/i)).toBeVisible({ timeout: 10000 });

    const { schedule } = await getShiftGroupsAndShifts(context, month, groupId);
    const { weekday, weekend } = countsByDayType(schedule);
    expectCaps(weekday, weekend);
  });

  test('Polish Law assigns shifts within the caps', async ({ adminPage }) => {
    const groupName = 'Caps Polish Team';
    const month = '2028-01';
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: groupName } });
    await generateMonthShifts(context, { monthKey: month, shiftGroupId: groupId });

    await runMode(adminPage, {
      groupName,
      month,
      mode: 'polish-law',
      modalTitle: 'Polish Law',
      caps: { weekday: 2, weekend: 1 },
      rotationDays: 2,
    });
    await expect(adminPage.getByText(/assigned|filled/i)).toBeVisible({ timeout: 10000 });

    const { schedule } = await getShiftGroupsAndShifts(context, month, groupId);
    const { weekday, weekend } = countsByDayType(schedule);
    expectCaps(weekday, weekend);
  });
});
