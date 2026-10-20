// frontend/e2e/schedule/shift-checklists.spec.js
import {
  archiveShiftGroup,
  createChecklistTemplate,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getShiftGroupsAndShifts,
} from '../fixtures/api-setup.js';
import { expect, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('schedule/shift-checklists');

// Month reserved for this file: 2093-05.
const MONTH = reserve.month('2093-05');
const DAY = '2093-05-12';
const GROUP = reserve.name('Checklist Config Team');

test.describe('Shift checklists on the schedule', () => {
  test('attaches a required template with a deadline to one shift, keeps it across a reload, then detaches it', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    const groupId = await createShiftGroupWithStreakRule(context, { group: { name: GROUP } });
    try {
      await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });
      const template = await createChecklistTemplate(context, {
        name: 'Schedule Opening Checklist',
      });
      await createChecklistTemplate(context, { name: 'Schedule Closing Checklist' });
      const shiftId = (await getShiftGroupsAndShifts(context, MONTH, groupId)).schedule[DAY][0].id;
      const attached = async () =>
        (await getShiftGroupsAndShifts(context, MONTH, groupId)).schedule[DAY][0].shift_checklists;

      const openGroup = async () => {
        const loaded = adminPage.waitForResponse(
          (res) =>
            res.url().includes('/api/schedule?') && res.url().includes(`shift_group_id=${groupId}`),
        );
        await adminPage.getByRole('button', { name: new RegExp(GROUP) }).click();
        await loaded;
      };
      await adminPage.goto(`/schedule/${MONTH}`);
      await openGroup();
      const cell = adminPage.locator(`[data-date="${DAY}"]`);
      const dialog = adminPage.getByRole('dialog', { name: 'Shift Checklists' });
      const templateSelect = dialog.locator('select').first();
      const savedChecklist = () =>
        adminPage.waitForResponse(
          (res) =>
            res.url().endsWith(`/api/schedule/shifts/${shiftId}/checklist`) &&
            res.request().method() === 'PUT',
        );

      // 1. A shift without checklists offers "Configure checklists" (shown on hover) and an empty dialog.
      await cell.getByTitle('Configure checklists').click();
      await expect(dialog).toBeVisible();
      await expect(dialog).toContainText(`${DAY} · Shift 1`);
      await expect(dialog.getByText(/No checklists assigned/)).toBeVisible();

      // Cancel discards an added row: nothing is sent, nothing attached.
      await dialog.getByRole('button', { name: '+ Add Checklist' }).click();
      await templateSelect.selectOption({ label: 'Schedule Closing Checklist' });
      await dialog.getByRole('button', { name: 'Cancel' }).click();
      await expect(dialog).toBeHidden();
      expect(await attached()).toEqual([]);

      // 2. Attach the opening template: required, due 30 minutes before the shift ends.
      await cell.getByTitle('Configure checklists').click();
      await expect(dialog.getByText(/No checklists assigned/)).toBeVisible();
      await dialog.getByRole('button', { name: '+ Add Checklist' }).click();
      await templateSelect.selectOption({ label: 'Schedule Opening Checklist' });
      await dialog.getByLabel('Required').check();
      await dialog.getByPlaceholder('min').fill('30');
      await dialog.locator('select').nth(1).selectOption('shift_end');
      let saved = savedChecklist();
      await dialog.getByRole('button', { name: 'Save' }).click();
      expect((await saved).ok()).toBe(true);
      await expect(adminPage.getByText('Checklist settings updated.')).toBeVisible();
      await expect(dialog).toBeHidden();

      // The chip now carries the checklist badge, titled with the template (☑ = required).
      const badge = cell.getByTitle('☑ Schedule Opening Checklist');
      await expect(badge).toBeVisible();
      await expect(badge).toHaveText(/×1/);
      expect(await attached()).toMatchObject([
        {
          checklist_template_id: template.id,
          checklist_template_name: 'Schedule Opening Checklist',
          is_required: true,
          deadline_minutes: 30,
          deadline_reference: 'shift_end',
        },
      ]);

      // 3. Persisted: after a reload the badge is back and the dialog shows the saved row.
      await adminPage.reload();
      await openGroup();
      await expect(badge).toBeVisible();
      await badge.click();
      await expect(dialog).toBeVisible();
      await expect(templateSelect).toHaveValue(String(template.id));
      await expect(dialog.getByLabel('Required')).toBeChecked();
      await expect(dialog.getByPlaceholder('min')).toHaveValue('30');
      await expect(dialog.locator('select').nth(1)).toHaveValue('shift_end');

      // 4. Remove the row and save: the shift has no checklists again.
      await dialog.getByTitle('Remove').click();
      await expect(dialog.getByText(/No checklists assigned/)).toBeVisible();
      saved = savedChecklist();
      await dialog.getByRole('button', { name: 'Save' }).click();
      expect((await saved).ok()).toBe(true);
      await expect(dialog).toBeHidden();
      await expect(badge).toHaveCount(0);
      await expect(cell.getByTitle('Configure checklists')).toHaveCount(1);
      expect(await attached()).toEqual([]);
    } finally {
      await archiveShiftGroup(context, groupId).catch(() => {});
    }
  });
});
