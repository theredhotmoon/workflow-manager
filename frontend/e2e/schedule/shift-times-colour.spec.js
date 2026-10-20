// frontend/e2e/schedule/shift-times-colour.spec.js
import {
  archiveShiftGroup,
  createShiftGroupWithStreakRule,
  generateMonthShifts,
  getShiftGroupsAndShifts,
} from '../fixtures/api-setup.js';
import { expect, getXsrfHeader, test } from '../fixtures/auth.js';
import { reservationsFor } from '../fixtures/reserve.js';

const reserve = reservationsFor('schedule/shift-times-colour');

// Month reserved for this file: 2093-07.
const MONTH = reserve.month('2093-07');
const DAY = '2093-07-08';
const GROUP = reserve.name('Times Colour Team');
const COLOUR = { name: 'Times Spec Teal', hex: '#14b8a6' };
// The chip's day cell is tinted with the colour at 15 % opacity (getShiftCellStyle).
const TINT = 'rgba(20, 184, 166, 0.15)';

async function createShiftColour(context) {
  const res = await context.request.post('/api/shift-colors', {
    data: COLOUR,
    headers: { Accept: 'application/json', ...(await getXsrfHeader(context)) },
  });
  if (!res.ok()) throw new Error(`create shift colour failed: ${res.status()} ${await res.text()}`);
  return res.json();
}

async function deleteShiftColour(context, id) {
  await context.request.delete(`/api/shift-colors/${id}`, {
    headers: { Accept: 'application/json', ...(await getXsrfHeader(context)) },
  });
}

test.describe('Shift Times dialog', () => {
  test('sets overnight times and a labelled colour from the chip, keeps them across a reload, then clears the colour', async ({
    adminPage,
  }) => {
    const context = adminPage.context();
    // The colour first, the group inside the try: whatever fails, the finally below cleans up both.
    const colour = await createShiftColour(context);
    let groupId = null;
    try {
      groupId = await createShiftGroupWithStreakRule(context, { group: { name: GROUP } });
      await generateMonthShifts(context, { monthKey: MONTH, shiftGroupId: groupId });
      const shiftOnServer = async () =>
        (await getShiftGroupsAndShifts(context, MONTH, groupId)).schedule[DAY][0];
      const shiftId = (await shiftOnServer()).id;

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
      // The shift block (tinted by the colour) wraps the drop-zone.
      const shiftBlock = cell.locator('[data-shift-id]').first().locator('xpath=..');
      const dialog = adminPage.getByRole('dialog', { name: 'Shift Times' });
      const timeInputs = dialog.locator('input[type="time"]');
      const colourSaved = () =>
        adminPage.waitForResponse(
          (res) =>
            res.url().endsWith(`/api/schedule/shifts/${shiftId}/color`) &&
            res.request().method() === 'PUT',
        );

      // 1. Fixture shifts are 08:00–16:00; make this one an overnight shift in the spec colour.
      await expect(cell.getByTitle('Edit shift times')).toHaveText(/08:00–16:00/);
      await cell.getByTitle('Edit shift times').click();
      await expect(dialog).toBeVisible();
      await expect(dialog).toContainText(`${DAY} · Shift 1`);
      await expect(timeInputs.nth(0)).toHaveValue('08:00');
      await expect(timeInputs.nth(1)).toHaveValue('16:00');
      await expect(dialog.getByText(/Overnight shift/)).toHaveCount(0);

      await timeInputs.nth(0).fill('22:00');
      await timeInputs.nth(1).fill('06:00');
      await expect(dialog.getByText('Overnight shift — ends the next day')).toBeVisible();
      await dialog.getByTitle(COLOUR.name).click();
      const label = dialog.getByPlaceholder(`Label (default: ${COLOUR.name})`);
      await expect(label).toBeVisible();
      await label.fill('Late crew');
      let saved = colourSaved();
      await dialog.getByRole('button', { name: 'Save' }).click();
      expect((await saved).ok()).toBe(true);
      await expect(adminPage.getByText('Shift updated.')).toBeVisible();
      await expect(dialog).toBeHidden();

      // The chip shows the new times as an overnight shift, and its block is tinted.
      const overnight = cell.getByTitle('Overnight shift (ends next day)');
      await expect(overnight).toHaveText(/22:00–06:00/);
      await expect(overnight).toContainText('🌙');
      await expect(shiftBlock).toHaveCSS('background-color', TINT);
      expect(await shiftOnServer()).toMatchObject({
        start_time: '22:00',
        end_time: '06:00',
        color: String(colour.id),
        color_label: 'Late crew',
      });

      // 2. Persisted across a reload, and the dialog reopens with the saved values.
      await adminPage.reload();
      await openGroup();
      await expect(overnight).toHaveText(/22:00–06:00/);
      await expect(shiftBlock).toHaveCSS('background-color', TINT);
      await overnight.click();
      await expect(dialog).toBeVisible();
      await expect(timeInputs.nth(0)).toHaveValue('22:00');
      await expect(timeInputs.nth(1)).toHaveValue('06:00');
      await expect(label).toHaveValue('Late crew');

      // 3. "No color" clears the colour and its label; the times stay.
      await dialog.getByTitle('No color').click();
      await expect(label).toHaveCount(0);
      saved = colourSaved();
      await dialog.getByRole('button', { name: 'Save' }).click();
      expect((await saved).ok()).toBe(true);
      await expect(dialog).toBeHidden();
      await expect(shiftBlock).not.toHaveCSS('background-color', TINT);
      expect(await shiftOnServer()).toMatchObject({
        start_time: '22:00',
        end_time: '06:00',
        color: null,
        color_label: null,
      });
    } finally {
      if (groupId) await archiveShiftGroup(context, groupId).catch(() => {});
      await deleteShiftColour(context, colour.id).catch(() => {});
    }
  });
});
