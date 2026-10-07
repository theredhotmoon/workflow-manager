// frontend/e2e/schedule/manual-assignment.spec.js
import { createShiftGroupWithStreakRule, generateMonthShifts } from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';

const MONTH = '2027-05';
const DAY = '2027-05-10';
const GROUP_NAME = 'Drag Team Manual';
const EMPLOYEE = 'Regular Employee';

async function dragToShiftDropzone(page, sourceLocator, targetLocator) {
  const source = await sourceLocator.boundingBox();
  const target = await targetLocator.boundingBox();
  if (!source || !target) throw new Error('drag source/target not visible');
  // A mouse drag can't scroll the page, so both ends must already be on screen — otherwise the drop
  // silently never happens. Fail loudly instead.
  const viewport = page.viewportSize();
  for (const [name, box] of [
    ['source', source],
    ['target', target],
  ]) {
    if (box.y < 0 || box.y + box.height > viewport.height) {
      throw new Error(
        `drag ${name} is outside the ${viewport.width}x${viewport.height} viewport (y=${Math.round(box.y)}); use a taller viewport`,
      );
    }
  }

  await page.mouse.move(source.x + source.width / 2, source.y + source.height / 2);
  await page.mouse.down();
  // Sortable.js needs several intermediate mousemove events to register a real drag.
  const steps = 8;
  for (let i = 1; i <= steps; i++) {
    const x = source.x + ((target.x - source.x) * i) / steps;
    const y = source.y + ((target.y - source.y) * i) / steps;
    await page.mouse.move(x, y, { steps: 3 });
  }
  await page.mouse.move(target.x + target.width / 2, target.y + target.height / 2, { steps: 3 });
  await page.mouse.up();
}

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
