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
    // A wide viewport keeps the assigned chip (name + x button) inside its narrow day cell;
    // at the default width it overflows and the next day's drop-zone covers the x button.
    await adminPage.setViewportSize({ width: 1920, height: 1080 });
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

    await dragToShiftDropzone(adminPage, employeeChip, dropzone);

    const assignedChip = dropzone.locator('div', { hasText: EMPLOYEE });
    await expect(assignedChip.first()).toBeVisible({ timeout: 5000 });

    // Persisted on the server
    await adminPage.reload();
    dropzone = await openGroup();
    await expect(dropzone.locator('div', { hasText: EMPLOYEE }).first()).toBeVisible();

    // Remove via the x button
    await dropzone
      .locator('div', { hasText: EMPLOYEE })
      .first()
      .getByText('×', { exact: true })
      .click();
    await expect(dropzone.locator('div', { hasText: EMPLOYEE })).toHaveCount(0);

    // Removal persisted
    await adminPage.reload();
    dropzone = await openGroup();
    await expect(dropzone.locator('div', { hasText: EMPLOYEE })).toHaveCount(0);
  });
});
