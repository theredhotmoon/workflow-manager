import { expect, test } from '@playwright/test';
import { useToast } from './useToast.js';

test.describe('useToast', () => {
  test('shows a toast with the given message and type', () => {
    const { toast, showToast } = useToast();

    showToast('Saved successfully', 'success');

    expect(toast.value).toEqual({ show: true, message: 'Saved successfully', type: 'success' });
  });

  test('defaults to type "success" and auto-dismisses after its duration', async () => {
    const { toast, showToast } = useToast();

    showToast('Done', undefined, 150);
    expect(toast.value.type).toBe('success');
    expect(toast.value.show).toBe(true);

    await new Promise((r) => setTimeout(r, 250));
    expect(toast.value.show).toBe(false);
  });

  test('auto-dismisses after a custom duration', async () => {
    const { toast, showToast } = useToast();

    showToast('Quick', 'warning', 100);

    await new Promise((r) => setTimeout(r, 200));
    expect(toast.value.show).toBe(false);
  });

  test('dismissToast clears immediately and cancels a pending auto-dismiss', async () => {
    const { toast, showToast, dismissToast } = useToast();

    showToast('Will be dismissed early', 'success', 5000);
    dismissToast();

    expect(toast.value.show).toBe(false);

    await new Promise((r) => setTimeout(r, 150));
    expect(toast.value.show).toBe(false);
  });
});
