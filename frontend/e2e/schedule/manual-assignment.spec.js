// frontend/e2e/schedule/manual-assignment.spec.js
import { createShiftGroupWithStreakRule, generateMonthShifts } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { dragToShiftDropzone } from '../fixtures/drag.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('schedule/manual-assignment');

const MONTH = reserve.month('2027-05');
const DAY = '2027-05-10';
const GROUP_NAME = reserve.name('Drag Team Manual');
const EMPLOYEE = 'Regular Employee';

test.describe('Manual shift assignment (drag and drop)', () => {
  test('assigns an employee by dragging from the roster, persists it, then unassigns via the remove button', async ({
    adminPage,
  }) => {
    // Keep the default 1280 px width (chip fit is covered there) but make the window tall enough for
    // the roster and the target day to be on screen together: on CI's fonts the day sits below 720 px.
    await adminPage.setViewportSize({ width: 1280, height: 1000 });
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: GROUP_NAME } });
    await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });

    const openGroup = async () => {
      await adminPage.getByRole('button', { name: new RegExp(GROUP_NAME) }).click();
      const cell = adminPage.locator(`[data-date="${DAY}"]`);
      await expect(cell).toBeVisible();
      const zone = cell.locator('[data-shift-id]').first();
      await expect(zone).toBeVisible();
      return zone;
    };

    await adminPage.goto(`/schedule/${MONTH}`);
    let dropzone = await openGroup();

    // The roster row carries `cursor-grab`; it is cloned (not moved) into the drop target.
    const employeeChip = adminPage.locator('div.cursor-grab', { hasText: EMPLOYEE });
    await expect(employeeChip).toBeVisible();

    // The chip appears as soon as Sortable drops the clone, but onDrop() first fetches the
    // employee's availability and only then POSTs the assignment. Wait for the save itself before
    // reloading, or the reload can abort it (seen in CI: no assign request ever reached the server).
    const assignSaved = adminPage.waitForResponse(
      (res) => res.url().includes('/api/schedule/assign') && res.request().method() === 'POST',
    );
    await dragToShiftDropzone(adminPage, employeeChip, dropzone);

    const assignedChip = dropzone.locator('div', { hasText: EMPLOYEE });
    await expect(assignedChip.first()).toBeVisible({ timeout: 5000 });
    expect((await assignSaved).ok()).toBe(true);

    // Persisted on the server
    await adminPage.reload();
    dropzone = await openGroup();
    await expect(dropzone.locator('div', { hasText: EMPLOYEE }).first()).toBeVisible();

    // Remove via the x button
    const unassignSaved = adminPage.waitForResponse(
      (res) => res.url().includes('/api/schedule/unassign/') && res.request().method() === 'DELETE',
    );
    await dropzone
      .locator('div', { hasText: EMPLOYEE })
      .first()
      .getByText('×', { exact: true })
      .click();
    await expect(dropzone.locator('div', { hasText: EMPLOYEE })).toHaveCount(0);
    expect((await unassignSaved).ok()).toBe(true);

    // Removal persisted
    await adminPage.reload();
    dropzone = await openGroup();
    await expect(dropzone.locator('div', { hasText: EMPLOYEE })).toHaveCount(0);
  });
});
