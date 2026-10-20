import { describe, expect, test } from 'vitest';
import { useToast } from './useToast.js';

describe('useToast', () => {
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

    showToast('Will be dismissed early', 'success', 100);
    dismissToast();

    expect(toast.value.show).toBe(false);

    // Write the ref directly (not via showToast) to verify the timer was actually cancelled.
    // If clearTimeout was not called in dismissToast, the timer would fire and call dismissToast again.
    toast.value = { show: true, message: 'Shown again', type: 'success' };

    await new Promise((r) => setTimeout(r, 250));
    expect(toast.value.show).toBe(true);

    dismissToast();
  });
});
