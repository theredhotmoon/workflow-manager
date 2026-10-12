import { expect, test } from '@playwright/test';
import { confirm, useConfirmModal } from './useConfirm.js';

test.describe('useConfirm', () => {
  test('resolves true when confirmed', async () => {
    const resultPromise = confirm({ title: 'Delete item', message: 'Are you sure?' });

    const { isOpen, title, message, onConfirm } = useConfirmModal();
    expect(isOpen.value).toBe(true);
    expect(title.value).toBe('Delete item');
    expect(message.value).toBe('Are you sure?');

    onConfirm();

    await expect(resultPromise).resolves.toBe(true);
    expect(isOpen.value).toBe(false);
  });

  test('resolves false when cancelled', async () => {
    const resultPromise = confirm({ title: 'Discard changes?' });

    const { onCancel } = useConfirmModal();
    onCancel();

    await expect(resultPromise).resolves.toBe(false);
  });

  test('applies defaults when no options are given', async () => {
    const resultPromise = confirm();

    const { title, message, confirmLabel, danger, onCancel } = useConfirmModal();
    expect(title.value).toBe('Confirm');
    expect(message.value).toBe('');
    expect(confirmLabel.value).toBe('Confirm');
    expect(danger.value).toBe(true);

    onCancel();
    await expect(resultPromise).resolves.toBe(false);
  });
});

test.describe('useConfirm — overlapping calls (L27)', () => {
  test('a second confirm() resolves the first one with false instead of leaving it hanging', async () => {
    const first = confirm({ title: 'First' });
    const second = confirm({ title: 'Second' });

    // The first caller gets its answer at once: it was never confirmed.
    await expect(first).resolves.toBe(false);

    const { isOpen, title, onConfirm } = useConfirmModal();
    expect(isOpen.value).toBe(true);
    expect(title.value).toBe('Second');

    onConfirm();
    await expect(second).resolves.toBe(true);
  });
});
